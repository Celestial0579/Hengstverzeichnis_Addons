<?php
// datenmigration/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: Umzug einer Instanz auf eine andere
// (Framework -> Framework). Der Export bündelt in EIN Archiv:
//
//   manifest.json   Kern-Version, Seitenname, Plugin-Bestand, Zählstände,
//                   und seit #121 die AUSWAHL (welche Gruppen im Archiv sind)
//   database.sql    DB-Dump der ausgewählten Tabellen (App\Service\DatabaseDumper)
//   uploads/...     hochgeladene Dateien aus public/uploads (Logos, Galerie,
//                   Dokumente) - die im Kern-Backup bewusst fehlen
//                   (siehe BackupService: "Kann bei Bedarf als eigenständige
//                   Erweiterung nachgezogen werden")
//   storage-horses/...  seit 1.3.0 (Format 3, Audit M26) die Pferdefotos aus
//                   storage/horses - sie liegen seit Kern 0.8 außerhalb des
//                   Webroots (Framework#366) und fehlten nach jedem Umzug
//   geheimnisse.json  seit 1.3.0 (Audit M25), nur mit Exportpasswort: die mit
//                   dem APP_KEY verschlüsselten Zugangsdaten, umverschlüsselt
//                   mit dem Exportpasswort (siehe Geheimnisumschlag)
//
// AUSWAHL STATT ALLES-ODER-NICHTS (#121). Bis v0.7 nahm der Export
// zwangsläufig jede Tabelle mit - also auch `users` mit den Passwort-Hashes,
// den TOTP-Geheimnissen und den Backup-Codes, dazu `api_keys`. Wer nur seine
// Pferde und Kontakte weitergeben wollte, verschickte die Anmeldedaten seiner
// Instanz gleich mit. Seit #121 wählt der Betreiber Gruppen aus (siehe
// Exportauswahl weiter unten); der Kern beschränkt den Dump darauf
// (DatabaseDumper::dumpTo($write, $tabellen), Framework#342).
//
// Der Import auf der Zielinstanz ist zweistufig (Prüfen -> Anwenden):
// Vorschau mit Versions-/Plugin-Abgleich, dann ausdrückliche Bestätigung.
// Seit 1.2.0 (Audit M1) gilt dabei nicht mehr das Manifest, sondern der
// geprüfte Dump: database.sql läuft vorab durch den DumpPruefer (Positivliste
// gegen das Format des Kern-Dumpers, Tabellenregel), und nur was dort als
// "wird ersetzt" steht, wird ausgeführt - Anweisung für Anweisung über eine
// eigene Verbindung (N21). Vor dem Anwenden wird ein Sicherungs-Dump der
// Zielinstanz geschrieben und trocken geprüft (Rückweg). Ein VOLLARCHIV
// ersetzt alle Tabellen, die es mitbringt; ein TEILARCHIV wird
// zusammengeführt: Es ersetzt nur die enthaltenen Tabellen, alles andere
// bleibt stehen. Werden Benutzerkonten ersetzt, enden alle Sitzungen (M2);
// stehenbleibende abhängige Zeilen verlangen eine Entscheidung (N23). Siehe
// apply() und importiere().
//
// Archivformat ist tar (ustar, bei verfügbarem zlib als .tar.gz), bewusst
// OHNE ext-zip: Das mitgelieferte Dockerfile des Kerns installiert kein
// zip, und ein ustar-Schreiber/-Leser sind zusammen unter 150 Zeilen -
// konsistent mit der "keine externen Abhängigkeiten"-Philosophie
// (docs/plugin-development.md; vgl. die CSV-statt-xlsx-Entscheidung des
// HorseCsvImporter im Kern).
//
// Große Archive: PHP-Upload-Grenzen (upload_max_filesize/post_max_size)
// gelten nur für den Upload-Weg. Große Archive legt man stattdessen per
// SFTP/Konsole direkt in var/datenmigration/ der Zielinstanz - die
// Übersicht listet alles in diesem Verzeichnis zum Prüfen/Anwenden auf.
//
// Nicht Teil des Umzugs (bewusst): config/db_config.php, APP_KEY und
// TLS/Proxy-Konfiguration - das ist Instanz-Infrastruktur, keine Daten. Was
// mit dem APP_KEY verschlüsselt in der Datenbank liegt (SMTP- und
// Backup-Zugangsdaten, Addon-Secrets, TOTP-Geheimnisse), ist dagegen an ihn
// gebunden: Das Manifest trägt deshalb seit 1.3.0 einen Fingerabdruck des
// Schlüssels, und bei einem abweichenden Schlüssel verlangt der Import das
// Exportpasswort oder die ausdrückliche Zustimmung, nicht entschlüsselbare
// Zugangsdaten zu leeren (Audit M25, siehe MigrationController::schluesselLage()).
//
// Installation: Verzeichnis nach plugins/ kopieren, unter /admin/plugins
// aktivieren, Berechtigungen "Datenmigration -> Export/Import" zuweisen.

namespace Plugin\Datenmigration;

use App\Controllers\BaseController;
use App\Database;
use App\Helper\HorseImagePath;
use App\Plugin\HookManager;
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;
use App\Router;
use App\Security\Crypto;
use App\Service\AuditLogger;
use App\Service\DatabaseDumper;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/datenmigration/uebersicht',
            'label' => 'Datenmigration',
            'icon' => '📦',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            ['module' => 'datenmigration', 'action' => 'export',
             'label' => 'Export erstellen', 'module_label' => 'Datenmigration'],
            ['module' => 'datenmigration', 'action' => 'import',
             'label' => 'Import anwenden', 'module_label' => 'Datenmigration'],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET',  'path' => '/uebersicht',
             'callback' => [MigrationController::class, 'overview']],
            // Export ist seit #121 zweistufig: GET zeigt die Auswahl, POST
            // erstellt das Archiv. Ein GET, das nebenbei einen kompletten
            // Datenbank-Dump ausliefert, war ohnehin die falsche Methode -
            // es genügte ein <img src> auf einer fremden Seite, solange ein
            // Administrator angemeldet war.
            ['method' => 'GET',  'path' => '/export',
             'callback' => [MigrationController::class, 'exportForm']],
            ['method' => 'POST', 'path' => '/export',
             'callback' => [MigrationController::class, 'export']],
            ['method' => 'POST', 'path' => '/import/hochladen',
             'callback' => [MigrationController::class, 'upload']],
            ['method' => 'GET',  'path' => '/import/pruefen',
             'callback' => [MigrationController::class, 'preview']],
            ['method' => 'POST', 'path' => '/import/anwenden',
             'callback' => [MigrationController::class, 'apply']],
        ];
    }
}


// ---------------------------------------------------------------------------
// Auswahl: was geht mit? (#121)
// ---------------------------------------------------------------------------

/**
 * Die Gruppen, aus denen der Betreiber sein Archiv zusammenstellt.
 *
 * DAS PROBLEM WAR EIN SICHERHEITSPROBLEM. Der Export nahm zwangsläufig ALLES
 * mit - also `users` mit den Passwort-Hashes, den TOTP-Geheimnissen und den
 * Backup-Codes, dazu `api_keys`. Wer nur seine Pferde und Kontakte zu einer
 * anderen Instanz tragen wollte (Zuchtverband, Nachfolgesystem, Testinstanz),
 * verschickte die Anmeldedaten seines Vereins gleich mit, ohne es zu merken.
 *
 * WARUM GRUPPEN UND NICHT EINZELNE TABELLEN. Eine Tabellenliste ist keine
 * Frage, die ein Betreiber beantworten kann: `horse_persons`,
 * `contact_id_map` oder `group_permissions` sagen ihm nichts, und die eine
 * Tabelle, die er dann übersieht, ist die, an der es hinterher hängt. Die
 * Gruppen sind entlang der Frage geschnitten, die er tatsächlich hat -
 * "sollen die Benutzerkonten mit?" -, und jede Gruppe nennt in der Oberfläche
 * ihre Tabellen samt Zeilenzahl, damit die Abstraktion nichts verbirgt.
 *
 * DIE VORGABE IST "ALLES AUSSER BENUTZER, GRUPPEN, RECHTE". Beide bequemen
 * Enden sind falsch: "alles angehakt" macht die Änderung wirkungslos - der
 * Regelfall bliebe der Vollexport samt Zugangsdaten -, "nichts angehakt"
 * erzeugt ein leeres Archiv und erzieht zum gedankenlosen Alles-Anhaken.
 * Die Linie verläuft deshalb dort, wo sie sich in einem Satz begründen lässt:
 * ZUGANGSMATERIAL ist ab, DATEN sind an. Ein Passwort-Hash, ein TOTP-Secret,
 * ein API-Schlüssel verschafft dem Empfänger Zugang - unabhängig davon, was
 * er damit vorhat; das ist ein Vorfall, sobald es passiert. Kontaktdaten und
 * Protokolle sind Inhalte: heikel, aber genau das, was der Betreiber
 * absichtlich weitergibt, wenn er eine Instanz umzieht. Sie deshalb per
 * Vorgabe wegzulassen hieße, dem Regelfall (Umzug) still Daten zu entziehen -
 * dieselbe Klasse Fehler, nur andersherum.
 *
 * KEINE TABELLE FÄLLT STILL HERAUS. Die Zuordnung unten ist eine feste Liste,
 * und der Kern bekommt in der nächsten Version neue Tabellen. Eine Tabelle,
 * die in keiner Gruppe steht, wäre aus jedem Export verschwunden, ohne dass
 * es jemand merkt - deshalb landet alles Unbekannte in der Gruppe
 * `sonstiges`, die ihre Tabellen namentlich anzeigt und per Vorgabe an ist.
 * Lieber eine Gruppe, die "diese hier kenne ich nicht" sagt, als ein Archiv,
 * das schweigend unvollständig ist.
 */
final class Exportauswahl {

    /** Dateien (public/uploads, storage/horses) - die einzige Gruppe ohne Tabellen. */
    public const GRUPPE_DATEIEN = 'dateien';

    /** Auffangbecken für Tabellen, die keine Zuordnung haben (s. o.). */
    public const GRUPPE_SONSTIGES = 'sonstiges';

    /** Benutzerkonten und Zugangsmaterial - die Gruppe, die per Vorgabe AUS ist. */
    public const GRUPPE_BENUTZER = 'benutzer';

    /**
     * Reihenfolge = Anzeigereihenfolge. `vorgabe` ist die Voreinstellung des
     * Auswahlformulars, `hinweis` steht als Warnhinweis an der Gruppe.
     *
     * @var array<string, array{label:string, text:string, vorgabe:bool, hinweis:?string}>
     */
    public const GRUPPEN = [
        'pferde' => [
            'label' => 'Pferde, Abstammung & Zuordnungen',
            'text' => 'Pferdedatensätze samt Abstammung (Vater/Mutter stehen in horses selbst), '
                . 'Registriernummern, die Zuordnung Pferd↔Kontakt (Züchter, Besitzer, Betreuer, '
                . 'Deckstation) und die getroffenen Dubletten-Entscheidungen. '
                . 'match_labels enthält auch die Entscheidungen zu Kontakt-Dubletten - sie hängen '
                . 'an derselben Mechanik und ließen sich nicht sinnvoll auftrennen.',
            'vorgabe' => true,
            'hinweis' => null,
        ],
        'kontakte' => [
            'label' => 'Kontakte (Personen & Deckstationen)',
            'text' => 'Seit v0.8 eine Tabelle (Framework#336). contact_id_map bildet die alten '
                . 'Personen-/Stationskennungen ab und muss mit - ohne sie laufen die dauerhaften '
                . 'Weiterleitungen von /person und /station ins Leere.',
            'vorgabe' => true,
            'hinweis' => 'Personenbezogene Daten: Namen, Anschriften, E-Mail, Telefon.',
        ],
        'addons' => [
            'label' => 'Addon-Daten',
            'text' => 'Alle Tabellen mit dem Präfix plugin_ sowie der Aktivierungsstand der Addons. '
                . 'Ausgenommen sind Addon-Tabellen mit einem Fremdschlüssel auf users - sie hängen '
                . 'an Benutzerkonten und gehen nur mit „Benutzer, Gruppen, Rechte“ ins Archiv. '
                . 'Der Kern prüft nach dem Import den Verzeichnis-Fingerabdruck und deaktiviert, '
                . 'was lokal nicht identisch vorliegt (fail-closed).',
            'vorgabe' => true,
            'hinweis' => null,
        ],
        'einstellungen' => [
            'label' => 'Einstellungen & Branding',
            'text' => 'Seitenname, Anzeigeoptionen, Erscheinungsbild sowie die eingetragenen '
                . 'Addon-Store-Quellen.',
            'vorgabe' => true,
            'hinweis' => null,
        ],
        'protokoll' => [
            'label' => 'Protokolle & Auskunftsanfragen',
            'text' => 'Audit-Log und DSGVO-Anfragen. Das Audit-Log führt den Benutzernamen als '
                . 'Text mit und bleibt deshalb auch ohne die Benutzertabelle lesbar.',
            'vorgabe' => true,
            'hinweis' => 'Enthält Benutzernamen, IP-Adressen und die Namen/E-Mail-Adressen von '
                . 'Auskunftsersuchenden.',
        ],
        self::GRUPPE_BENUTZER => [
            'label' => 'Benutzer, Gruppen, Rechte',
            'text' => 'Konten, Gruppenzugehörigkeit, Berechtigungsmatrix, API-Schlüssel, Passkeys, '
                . 'E-Mail-Anmeldecodes, Passwort-Zurücksetzungen und die Fehlversuchszähler des '
                . 'Brute-Force-Schutzes - dazu jede Tabelle, die per Fremdschlüssel auf users '
                . 'verweist (auch die von Addons).',
            'vorgabe' => false,
            'hinweis' => 'ZUGANGSMATERIAL: Passwort-Hashes, TOTP-Geheimnisse, Backup-Codes, Passkeys und '
                . 'API-Schlüssel-Hashes. Wer dieses Archiv bekommt, bekommt die Zugänge Ihrer '
                . 'Instanz. Nur anhaken, wenn die Zielinstanz Ihre eigene ist.',
        ],
        self::GRUPPE_SONSTIGES => [
            'label' => 'Nicht zugeordnete Tabellen',
            'text' => 'Tabellen, für die dieses Addon keine Zuordnung kennt - typischerweise, '
                . 'weil der Kern neuer ist als das Addon. Sie stehen hier namentlich, statt '
                . 'stillschweigend aus jedem Export zu verschwinden.',
            'vorgabe' => true,
            'hinweis' => null,
        ],
        self::GRUPPE_DATEIEN => [
            'label' => 'Dateien (public/uploads und Pferdefotos)',
            'text' => 'Logos, Galerie-Medien und Dokumente aus public/uploads sowie die Pferdefotos, '
                . 'die seit Kern 0.8 außerhalb des Webroots unter storage/horses liegen. Die Dateien '
                . 'liegen im Dateisystem und lassen sich nicht nach Tabellen aufteilen - sie gehen '
                . 'vollständig mit oder gar nicht.',
            'vorgabe' => true,
            'hinweis' => null,
        ],
    ];

    /**
     * Feste Zuordnung Kern-Tabelle -> Gruppe. Tabellen mit einem Verweis auf
     * `users` gehen an `benutzer` (siehe gruppeFuer()), alles mit dem Präfix
     * `plugin_` ohne Eintrag an `addons`, alles Übrige an `sonstiges`.
     *
     * @var array<string, string>
     */
    private const ZUORDNUNG = [
        'horses' => 'pferde',
        'horse_registrations' => 'pferde',
        'horse_persons' => 'pferde',
        'match_labels' => 'pferde',

        'contacts' => 'kontakte',
        'contact_id_map' => 'kontakte',

        'plugins' => 'addons',

        'settings' => 'einstellungen',
        'addon_repos' => 'einstellungen',

        'audit_logs' => 'protokoll',
        'gdpr_requests' => 'protokoll',

        'users' => self::GRUPPE_BENUTZER,
        'groups' => self::GRUPPE_BENUTZER,
        'user_groups' => self::GRUPPE_BENUTZER,
        'group_permissions' => self::GRUPPE_BENUTZER,
        'api_keys' => self::GRUPPE_BENUTZER,
        'password_resets' => self::GRUPPE_BENUTZER,
        'login_attempts' => self::GRUPPE_BENUTZER,
    ];

    /**
     * Kern-Tabellen, die an Benutzerkonten hängen, ohne in ZUORDNUNG zu
     * stehen (Audit M3).
     *
     * Bis 1.1.0 fielen sie in `sonstiges` - und `sonstiges` ist per Vorgabe
     * AN. Die Passkeys und E-Mail-Anmeldecodes verließen die Instanz damit mit
     * jedem Standardexport, und beim Einspielen hängten sich ihre Zeilen an
     * die Konten der Zielinstanz mit derselben Kennung: fremde Passkeys an
     * eigenen Konten, die eigenen gelöscht. Beide Tabellen haben zwar einen
     * Fremdschlüssel auf users (und fielen damit auch über die Verweisziele in
     * gruppeFuer() richtig) - sie stehen trotzdem namentlich hier, damit die
     * Zuordnung nicht davon abhängt, dass die Fremdschlüssel-Karte gelesen
     * werden konnte.
     *
     * @var array<int, string>
     */
    public const BENUTZERBEZOGEN = ['user_passkeys', 'email_2fa_codes'];

    /**
     * Verweise ohne Fremdschlüssel im Schema, die die Abhängigkeitsprüfung
     * sonst übersähe.
     *
     * `match_labels` hat keinen Fremdschlüssel (der Verweis hängt an der
     * Spalte `kind`), und ein Label ohne sein Gegenstück ist nicht bloß leer,
     * sondern schädlich - ein 'different' aus einer fremden Instanz legt auf
     * dem Ziel den Vorschlag zu einem ganz anderen Paar still.
     *
     * Die beiden Kontaktanfrage-Tabellen verweisen über `contact_id` auf
     * `contacts`, ebenfalls ohne Fremdschlüssel (0 heißt dort "Datensatz
     * entfernt"). Ersetzt ein Import die Kontakte, ohne sie mitzubringen,
     * ginge eine gespeicherte Anfrage sonst an die Person, die im Archiv
     * dieselbe Kennung trägt (Audit N23). Addons tragen solche Verweise
     * künftig selbst in ihre plugin.json ein (`weiche_verweise`, siehe
     * weicheVerweiseAusManifest()); die Einträge hier bleiben als Rückfall für
     * ältere Stände des Addons stehen.
     *
     * `trennen` sagt, was "trennen" in der Import-Vorschau mit einer solchen
     * Zeile tut: `loeschen`, `null` (Spalte auf NULL) oder `null_wert`
     * (Spalte auf 0 - die Kontaktanfrage-Semantik für "Datensatz entfernt").
     *
     * Bewusst NICHT hier: `audit_logs.user_id` und `addon_repos.added_by`.
     * Beide führen den Namen zusätzlich als Text mit, ein fehlender Verweis
     * kostet dort nichts - sie stünden bei der Vorgabe-Auswahl in jeder
     * Warnung und würden die Warnungen entwerten, auf die es ankommt.
     *
     * @var array<string, array<int, array{ziel:string, spalte:string, wo:array<string, int|string>, trennen:string}>>
     */
    public const WEICHE_VERWEISE = [
        'match_labels' => [
            ['ziel' => 'horses', 'spalte' => 'left_id', 'wo' => ['kind' => 'horse'], 'trennen' => 'loeschen'],
            ['ziel' => 'horses', 'spalte' => 'right_id', 'wo' => ['kind' => 'horse'], 'trennen' => 'loeschen'],
            ['ziel' => 'contacts', 'spalte' => 'left_id', 'wo' => ['kind' => 'contact'], 'trennen' => 'loeschen'],
            ['ziel' => 'contacts', 'spalte' => 'right_id', 'wo' => ['kind' => 'contact'], 'trennen' => 'loeschen'],
        ],
        'plugin_kontaktanfrage_requests' => [
            ['ziel' => 'contacts', 'spalte' => 'contact_id', 'wo' => [], 'trennen' => 'null_wert'],
        ],
        'plugin_kontaktanfrage_optout' => [
            ['ziel' => 'contacts', 'spalte' => 'contact_id', 'wo' => [], 'trennen' => 'loeschen'],
        ],
    ];

    /** Zulässige Werte für `trennen` eines weichen Verweises. */
    public const TRENNARTEN = ['loeschen', 'null', 'null_wert'];

    private function __construct() {}

    /** @return array<int, string> Alle Gruppenschlüssel in Anzeigereihenfolge. */
    public static function schluessel(): array {
        return array_keys(self::GRUPPEN);
    }

    /** @return array<int, string> Die Voreinstellung des Auswahlformulars. */
    public static function vorgabe(): array {
        $aus = [];
        foreach (self::GRUPPEN as $key => $meta) {
            if ($meta['vorgabe']) {
                $aus[] = $key;
            }
        }
        return $aus;
    }

    /**
     * Macht aus beliebiger Eingabe (Formular, fremdes Manifest) eine
     * Auswahl: nur bekannte Schlüssel, doppelte entfernt, in
     * Anzeigereihenfolge. Unbekanntes wird verworfen, nicht durchgereicht -
     * die Auswahl steuert am Ende, welche Tabellen ersetzt werden.
     *
     * @param mixed $roh
     * @return array<int, string>
     */
    public static function bereinige(mixed $roh): array {
        if (!is_array($roh)) {
            return [];
        }
        $gewuenscht = [];
        foreach ($roh as $eintrag) {
            if (is_string($eintrag)) {
                $gewuenscht[$eintrag] = true;
            }
        }
        return array_values(array_filter(
            self::schluessel(),
            static fn(string $k): bool => isset($gewuenscht[$k])
        ));
    }

    /**
     * Die Gruppe, in die eine tatsächlich vorhandene Tabelle fällt.
     *
     * $verweisziele sind die Tabellen, auf die sie per Fremdschlüssel zeigt.
     * Zeigt sie auf `users`, gehört sie zu den Benutzerkonten (Audit M3) -
     * gleich, ob Kern- oder Addon-Tabelle: Ihre Zeilen hängen an einer
     * Kontokennung und ergeben auf einer anderen Instanz nur an dem Konto mit
     * derselben Kennung Sinn, also an einem fremden. Die feste ZUORDNUNG geht
     * trotzdem vor; die Regel greift erst für Tabellen, die dort fehlen.
     *
     * @param array<int, string> $verweisziele
     */
    public static function gruppeFuer(string $tabelle, array $verweisziele = []): string {
        if (isset(self::ZUORDNUNG[$tabelle])) {
            return self::ZUORDNUNG[$tabelle];
        }
        if (in_array('users', $verweisziele, true) || in_array($tabelle, self::BENUTZERBEZOGEN, true)) {
            return self::GRUPPE_BENUTZER;
        }
        if (str_starts_with($tabelle, 'plugin_')) {
            return 'addons';
        }
        return self::GRUPPE_SONSTIGES;
    }

    /**
     * Die Zuordnung, nach der Archive bis Version 1.1.0 dieses Addons
     * geschrieben wurden (ZUORDNUNG, plugin_, sonstiges).
     *
     * Dient AUSSCHLIESSLICH der Importregel (siehe Importregel::fuer()): Ein
     * älteres Archiv mit der Auswahl [pferde, sonstiges] enthält
     * `user_passkeys` völlig regulär - damals gehörte die Tabelle zu
     * "sonstiges". Mit der neuen Zuordnung allein sähe der Import darin eine
     * Tabelle, die nicht zur Auswahl passt, und müsste das Archiv abweisen.
     * Richtig ist, sie zu überspringen und den Rest einzuspielen.
     */
    public static function altGruppeFuer(string $tabelle): string {
        if (isset(self::ZUORDNUNG[$tabelle])) {
            return self::ZUORDNUNG[$tabelle];
        }
        if (str_starts_with($tabelle, 'plugin_')) {
            return 'addons';
        }
        return self::GRUPPE_SONSTIGES;
    }

    /**
     * Die Positivliste der zu exportierenden Tabellen: Schnittmenge aus
     * "gewählte Gruppen" und "tatsächlich vorhandene Tabellen". Die
     * Reihenfolge bleibt die von SHOW TABLES, damit Dumps stabil sind.
     *
     * @param array<int, string> $auswahl
     * @param array<int, string> $vorhandene Ergebnis von SHOW TABLES
     * @param array<string, array<int, string>> $fks Tabelle => Verweisziele (Fremdschlüssel)
     * @return array<int, string>
     */
    public static function tabellen(array $auswahl, array $vorhandene, array $fks = []): array {
        $gewaehlt = array_flip($auswahl);
        return array_values(array_filter(
            $vorhandene,
            static fn(string $t): bool => isset($gewaehlt[self::gruppeFuer($t, $fks[$t] ?? [])])
        ));
    }

    /**
     * Die `weiche_verweise` aus der plugin.json eines Addons - geprüft.
     *
     * Ein Addon kennt seine Verweise ohne Fremdschlüssel selbst am besten,
     * deshalb darf es sie angeben. Die Angabe steuert aber, was "trennen" in
     * der Import-Vorschau LÖSCHT. Ohne Grenzen könnte jedes installierte
     * Addon per Manifest `trennen: loeschen` auf `users` oder `horses`
     * erklären. Deshalb gilt hart:
     *
     *   - die Tabelle beginnt mit plugin_ und steht in `owns.tables`
     *     DESSELBEN Manifests - ein Addon beschreibt nur eigene Tabellen;
     *   - Tabelle, Ziel, Spalte und die Schlüssel von `wo` sind schlichte
     *     Bezeichner (^[a-z0-9_]+$), die Werte von `wo` Skalare - sie werden
     *     später als Parameter gebunden, nie eingesetzt;
     *   - `trennen` ist einer der Werte aus TRENNARTEN.
     *
     * Was davon abweicht, wird verworfen und mit Grund zurückgegeben (der
     * Aufrufer protokolliert es) - ein fehlerhafter Eintrag soll den Import
     * nicht blockieren, aber auch nicht still wirken.
     *
     * @return array{verweise: array<string, array<int, array{ziel:string, spalte:string, wo:array<string, int|string>, trennen:string}>>, verworfen: array<int, string>}
     */
    public static function weicheVerweiseAusManifest(mixed $manifest): array {
        $ergebnis = ['verweise' => [], 'verworfen' => []];
        if (!is_array($manifest) || !array_key_exists('weiche_verweise', $manifest)) {
            return $ergebnis;
        }
        $slug = is_string($manifest['slug'] ?? null) ? $manifest['slug'] : '?';
        $angabe = $manifest['weiche_verweise'];
        if (!is_array($angabe)) {
            $ergebnis['verworfen'][] = "{$slug}: weiche_verweise ist kein Objekt";
            return $ergebnis;
        }
        $eigene = is_array($manifest['owns']['tables'] ?? null) ? $manifest['owns']['tables'] : [];
        $bezeichner = static fn(mixed $n): bool => is_string($n) && preg_match('/^[a-z0-9_]{1,64}$/D', $n) === 1;

        foreach ($angabe as $tabelle => $eintraege) {
            $tabelle = (string) $tabelle;
            if (!$bezeichner($tabelle) || !str_starts_with($tabelle, 'plugin_')
                || !in_array($tabelle, $eigene, true)) {
                $ergebnis['verworfen'][] = "{$slug}: Tabelle '{$tabelle}' ist keine eigene plugin_-Tabelle aus owns.tables";
                continue;
            }
            if (!is_array($eintraege) || !array_is_list($eintraege)) {
                $ergebnis['verworfen'][] = "{$slug}: Eintrag für '{$tabelle}' ist keine Liste";
                continue;
            }
            foreach ($eintraege as $e) {
                if (!is_array($e)) {
                    $ergebnis['verworfen'][] = "{$slug}: ungültiger Verweis in '{$tabelle}'";
                    continue;
                }
                $ziel = $e['ziel'] ?? null;
                $spalte = $e['spalte'] ?? null;
                $trennen = $e['trennen'] ?? null;
                $wo = $e['wo'] ?? [];
                if (!$bezeichner($ziel) || !$bezeichner($spalte)
                    || !in_array($trennen, self::TRENNARTEN, true) || !is_array($wo)) {
                    $ergebnis['verworfen'][] = "{$slug}: ungültiger Verweis in '{$tabelle}'";
                    continue;
                }
                $woSauber = [];
                foreach ($wo as $k => $v) {
                    if (!$bezeichner((string) $k) || !(is_int($v) || is_string($v))) {
                        $woSauber = null;
                        break;
                    }
                    $woSauber[(string) $k] = $v;
                }
                if ($woSauber === null) {
                    $ergebnis['verworfen'][] = "{$slug}: ungültige Bedingung (wo) in '{$tabelle}'";
                    continue;
                }
                $ergebnis['verweise'][$tabelle][] = [
                    'ziel' => $ziel, 'spalte' => $spalte, 'wo' => $woSauber, 'trennen' => $trennen,
                ];
            }
        }
        return $ergebnis;
    }

    /**
     * Führt Verweislisten zusammen (Konstante + Manifeste). Doppelte
     * Einträge - dieselbe Tabelle, dasselbe Ziel, dieselbe Spalte, dieselbe
     * Bedingung - zählen einmal; der ZUERST genannte gewinnt, deshalb steht
     * die Konstante vorn.
     *
     * @param array<int, array<string, array<int, array{ziel:string, spalte:string, wo:array<string, int|string>, trennen:string}>>> $listen
     * @return array<string, array<int, array{ziel:string, spalte:string, wo:array<string, int|string>, trennen:string}>>
     */
    public static function verweiseZusammenfuehren(array ...$listen): array {
        $aus = [];
        $gesehen = [];
        foreach ($listen as $liste) {
            foreach ($liste as $tabelle => $eintraege) {
                foreach ($eintraege as $e) {
                    $schluessel = $tabelle . '|' . $e['ziel'] . '|' . $e['spalte'] . '|' . json_encode($e['wo']);
                    if (isset($gesehen[$schluessel])) {
                        continue;
                    }
                    $gesehen[$schluessel] = true;
                    $aus[$tabelle][] = $e;
                }
            }
        }
        ksort($aus);
        return $aus;
    }

    /** Deckt die Auswahl jede Gruppe ab? Dann ist es ein Vollarchiv. */
    public static function istVollstaendig(array $auswahl): bool {
        return array_diff(self::schluessel(), self::bereinige($auswahl)) === [];
    }

    /** Beschriftung eines Schlüssels; unbekannte Schlüssel bleiben sichtbar. */
    public static function label(string $key): string {
        return self::GRUPPEN[$key]['label'] ?? $key;
    }
}


/**
 * Welche Dateien ein Import-Archiv unter uploads/ mitbringen darf.
 *
 * Steht als eigene Klasse neben TarWriter/TarReader und nicht im Controller:
 * Es ist eine reine Regel ohne Framework-Bezug, und genau so lässt sie sich
 * ohne Datenbank und ohne Kern-Instanz prüfen (tests/Unit).
 */
final class UploadNamePolicy {

    /**
     * Positivliste, keine Sperrliste: Eine Liste verbotener Endungen ist immer
     * unvollständig (.php5, .phtml, .phar, .htaccess selbst), eine Liste
     * erlaubter nicht.
     *
     * Der Umfang orientiert sich daran, was tatsächlich in public/uploads
     * liegt: Bilder (Kern, galerie), PDFs (Dokumente) und schlichte
     * Textdaten. Bewusst NICHT enthalten sind Archive und Office-Formate -
     * sie gehören nicht in ein öffentlich ausgeliefertes Verzeichnis.
     *
     * Trifft ein Import auf eine Datei außerhalb dieser Liste, bricht er ab
     * und NENNT die Datei, statt sie stillschweigend zu überspringen. Bei
     * einem Umzug ist ein stiller Datenverlust die schlechtere Antwort: Der
     * Betreiber soll entscheiden, ob die Datei ins Archiv gehört oder die
     * Liste erweitert wird.
     *
     * @var array<int, string>
     */
    public const ERLAUBTE_ENDUNGEN = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg',
        'pdf', 'txt', 'csv',
    ];

    /**
     * Endungen, die AN JEDER STELLE des Namens unzulässig sind - nicht nur als
     * letzte.
     *
     * Grund ist der Apache-Klassiker: Steht dort
     * `AddHandler application/x-httpd-php .php`, wertet der Server ALLE
     * Endungen eines Namens aus und führt auch "bild.php.jpg" als PHP aus. Die
     * Positivliste oben allein würde diesen Namen durchlassen, weil die letzte
     * Endung sauber ist.
     *
     * Umgekehrt darf die Regel nicht jede Endung gegen die Positivliste
     * prüfen: "stute.2024.jpg" ist ein völlig gewöhnlicher Dateiname, und ein
     * Import, der daran scheitert, wird umgangen statt befolgt. (Genau daran
     * ist der erste Entwurf im Test hängengeblieben.)
     *
     * @var array<int, string>
     */
    public const NIE_ERLAUBTE_ENDUNGEN = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'pht', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'jsp', 'asp', 'aspx', 'exe', 'htaccess', 'htpasswd',
    ];

    private function __construct() {}

    /**
     * Webserver-Steuerdateien werden aus dem Archiv NICHT übernommen, aber
     * sie brechen den Import auch nicht ab.
     *
     * Ein Export enthält zwangsläufig die `.htaccess`, mit der der Kern PHP in
     * public/uploads abschaltet - sie liegt ja in genau diesem Verzeichnis.
     * Sie zu übernehmen hieße, den Ausführungsschutz vom Inhalt des Archivs
     * bestimmen zu lassen; den Import daran scheitern zu lassen hieße, dass
     * kein einziger echter Export mehr einspielbar wäre. Also: überspringen
     * und den Schutz nach dem Umschalten neu schreiben - aus dem Stand der
     * Zielinstanz vor dem Import, sonst aus der eingebauten Mindestfassung
     * (siehe MigrationController::restoreUploadsProtection()).
     */
    public static function istWebserverSteuerdatei(string $rel): bool {
        return in_array(strtolower(basename($rel)), ['.htaccess', '.htpasswd', 'web.config'], true);
    }

    /**
     * @throws \RuntimeException wenn der Name nicht zulässig ist
     */
    public static function assertAllowed(string $rel): void {
        $base = basename($rel);

        // Punktdateien: .htaccess wäre die wirkungsvollste davon - die wird
        // aber schon vorher aussortiert (siehe istWebserverSteuerdatei()).
        // Was hier ankommt, ist eine andere versteckte Datei, und für die
        // gibt es in einem Upload-Verzeichnis keinen Grund.
        if (str_starts_with($base, '.')) {
            throw new \RuntimeException("Punktdateien sind im Archiv nicht zulässig: {$rel}");
        }

        $teile = explode('.', $base);
        array_shift($teile); // der Name selbst
        if ($teile === []) {
            throw new \RuntimeException("Datei ohne Endung ist im Archiv nicht zulässig: {$rel}");
        }

        // Keine ausführbare Endung an irgendeiner Stelle.
        foreach ($teile as $endung) {
            if (in_array(strtolower($endung), self::NIE_ERLAUBTE_ENDUNGEN, true)) {
                throw new \RuntimeException("Ausführbare Dateiendung im Archiv: {$rel}");
            }
        }

        // Und die tatsächliche Endung muss auf der Positivliste stehen.
        $letzte = strtolower((string) end($teile));
        if (!in_array($letzte, self::ERLAUBTE_ENDUNGEN, true)) {
            throw new \RuntimeException("Nicht erlaubte Dateiendung im Archiv: {$rel}");
        }
    }
}


// ---------------------------------------------------------------------------
// Archiv: minimaler ustar-Schreiber/-Leser (pure PHP, streamend)
// ---------------------------------------------------------------------------

/**
 * Streamender tar-Schreiber (ustar). Schreibt wahlweise über gzopen (.tar.gz,
 * wenn zlib verfügbar) oder fopen (.tar) - Dateien werden blockweise
 * durchgereicht, es liegt nie mehr als ein 512-Byte-Block im Speicher plus
 * der gerade gelesene Chunk. Lange Pfade nutzen das ustar-prefix-Feld.
 */
final class TarWriter {

    /** @var resource */
    private $handle;
    private bool $gzip;

    private function __construct($handle, bool $gzip) {
        $this->handle = $handle;
        $this->gzip = $gzip;
    }

    public static function create(string $path): self {
        $gzip = str_ends_with($path, '.gz') && function_exists('gzopen');
        $handle = $gzip ? gzopen($path, 'wb6') : fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Archiv nicht schreibbar: {$path}");
        }
        return new self($handle, $gzip);
    }

    /**
     * Schreibt und prüft die geschriebene Menge, nicht nur auf false:
     * gzwrite() meldet einen Schreibfehler (volle Platte, Quota) mit 0, und
     * gzclose() danach trotzdem Erfolg. Ohne diesen Vergleich entstand ein
     * abgeschnittenes Archiv, das als erfolgreich galt (Audit M43, hier das
     * Duplikat im Addon).
     */
    private function write(string $data): void {
        $ok = $this->gzip ? gzwrite($this->handle, $data) : fwrite($this->handle, $data);
        if ($ok === false || $ok !== strlen($data)) {
            throw new \RuntimeException('Schreiben in das Archiv fehlgeschlagen (Datenträger voll?).');
        }
    }

    public function addString(string $name, string $content): void {
        $this->writeHeader($name, strlen($content));
        $this->write($content);
        $this->pad(strlen($content));
    }

    public function addFile(string $name, string $sourcePath): void {
        $size = filesize($sourcePath);
        if ($size === false) {
            throw new \RuntimeException("Datei nicht lesbar: {$sourcePath}");
        }
        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new \RuntimeException("Datei nicht lesbar: {$sourcePath}");
        }
        $this->writeHeader($name, $size);
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 512);
            if ($chunk === false) {
                fclose($in);
                throw new \RuntimeException("Lesefehler: {$sourcePath}");
            }
            $this->write($chunk);
            $written += strlen($chunk);
        }
        fclose($in);
        if ($written !== $size) {
            throw new \RuntimeException("Datei änderte sich während des Exports: {$sourcePath}");
        }
        $this->pad($size);
    }

    public function close(): void {
        $this->write(str_repeat("\0", 1024)); // Zwei Null-Blöcke = Archivende
        // Erst beim Schließen schreibt gzip den Rest samt Prüfsummen-Trailer.
        if (!($this->gzip ? gzclose($this->handle) : fclose($this->handle))) {
            throw new \RuntimeException('Archiv konnte nicht abgeschlossen werden.');
        }
    }

    private function pad(int $size): void {
        $rest = $size % 512;
        if ($rest !== 0) {
            $this->write(str_repeat("\0", 512 - $rest));
        }
    }

    private function writeHeader(string $name, int $size): void {
        $prefix = '';
        if (strlen($name) > 100) {
            // ustar: name (100) + prefix (155), getrennt an einem '/'
            $cut = strrpos(substr($name, 0, 156), '/');
            if ($cut === false || strlen($name) - $cut - 1 > 100) {
                throw new \RuntimeException("Pfad zu lang für ustar: {$name}");
            }
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }
        $header = str_pad($name, 100, "\0")
            . '0000644' . "\0"                       // mode
            . '0000000' . "\0" . '0000000' . "\0"    // uid/gid
            . sprintf('%011o', $size) . "\0"
            . sprintf('%011o', time()) . "\0"
            . '        '                             // Platzhalter Prüfsumme
            . '0'                                    // typeflag: regular file
            . str_repeat("\0", 100)                  // linkname
            . "ustar\0" . '00'
            . str_pad('', 32, "\0") . str_pad('', 32, "\0")  // uname/gname
            . '0000000' . "\0" . '0000000' . "\0"    // devmajor/minor
            . str_pad($prefix, 155, "\0");
        $header = str_pad($header, 512, "\0");
        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }
        $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
        $this->write($header);
    }
}

/**
 * Streamender tar-Leser. gzopen liest transparent auch unkomprimierte
 * Dateien, deshalb ein Lesepfad für .tar und .tar.gz. Es werden nur
 * reguläre Dateien geliefert; alles andere (Symlinks, Devices, ...) wird
 * übersprungen - ein Migrationsarchiv enthält nichts dergleichen, und so
 * kann ein manipuliertes Archiv darüber auch nichts einschleusen.
 *
 * OHNE ZLIB (Audit N22): Der Lesemodus wird beim Öffnen festgelegt und gilt
 * für read() und close() gleichermaßen. Bis 1.2.0 öffnete der Leser ohne
 * zlib per fopen(), las aber immer per gzread() - jedes Archiv, auch ein
 * unkomprimiertes .tar, endete mit einem Fatal Error. Jetzt liest er ein .tar
 * per fread() und weist ein .tar.gz (gzip-Magic 1f 8b) mit einer Meldung ab,
 * die sagt, was zu tun ist.
 */
final class TarReader {

    /** @var resource */
    private $handle;

    /** Lesemodus: gzread()/gzclose() oder fread()/fclose(). */
    private bool $gzip;

    /**
     * @param bool|null $gzip null = zlib nutzen, wenn vorhanden. true ohne
     *                        zlib wird zu false (es gibt dann kein gzread).
     *                        false erzwingt den fread-Pfad (Tests).
     */
    public function __construct(string $path, ?bool $gzip = null) {
        $this->gzip = ($gzip ?? true) && function_exists('gzopen');
        $handle = $this->gzip ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Archiv nicht lesbar: {$path}");
        }
        if (!$this->gzip) {
            if (fread($handle, 2) === "\x1f\x8b") {
                fclose($handle);
                throw new \RuntimeException('Das Archiv ist gzip-komprimiert (.tar.gz), auf diesem Server fehlt aber '
                    . 'die PHP-Erweiterung zlib. Entweder das Archiv vorher entpacken (gunzip) und als .tar '
                    . 'ablegen oder zlib nachinstallieren.');
            }
            rewind($handle);
        }
        $this->handle = $handle;
    }

    /**
     * Ruft $callback(name, size, readChunk) je regulärer Datei auf.
     * $readChunk() liefert den nächsten Datenblock oder '' am Dateiende -
     * der Callback MUSS bis '' lesen (er konsumiert den Stream).
     *
     * @param callable(string, int, callable():string):void $callback
     */
    public function each(callable $callback): void {
        while (true) {
            $header = $this->read(512);
            if ($header === '' || trim($header, "\0") === '') {
                break; // Archivende (Null-Block)
            }
            if (strlen($header) < 512) {
                throw new \RuntimeException('Archiv abgeschnitten (unvollständiger Header).');
            }
            $stored = (int) substr($header, 148, 8);
            $probe = substr_replace($header, '        ', 148, 8);
            $checksum = 0;
            for ($i = 0; $i < 512; $i++) {
                $checksum += ord($probe[$i]);
            }
            if ($checksum !== octdec(trim(substr($header, 148, 8), "\0 "))) {
                throw new \RuntimeException('Archiv beschädigt (Header-Prüfsumme falsch).');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '') {
                $name = $prefix . '/' . $name;
            }
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $type = $header[156];

            if ($type === '0' || $type === "\0") {
                $remaining = $size;
                $reader = function () use (&$remaining): string {
                    if ($remaining <= 0) {
                        return '';
                    }
                    $chunk = $this->read(min($remaining, 1024 * 512));
                    if ($chunk === '') {
                        throw new \RuntimeException('Archiv abgeschnitten (Dateiinhalt fehlt).');
                    }
                    $remaining -= strlen($chunk);
                    return $chunk;
                };
                $callback($name, $size, $reader);
                if ($remaining > 0) {
                    throw new \RuntimeException('Interner Fehler: Archiv-Eintrag nicht vollständig gelesen.');
                }
            } else {
                // Verzeichnisse & Sonderdateien: Inhalt überspringen
                $this->skip($size);
            }
            $rest = $size % 512;
            if ($rest !== 0) {
                $this->skip(512 - $rest);
            }
        }
    }

    public function close(): void {
        $this->gzip ? gzclose($this->handle) : fclose($this->handle);
    }

    private function read(int $bytes): string {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = $this->gzip
                ? gzread($this->handle, $bytes - strlen($data))
                : fread($this->handle, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function skip(int $bytes): void {
        while ($bytes > 0) {
            $chunk = $this->read(min($bytes, 1024 * 512));
            if ($chunk === '') {
                throw new \RuntimeException('Archiv abgeschnitten.');
            }
            $bytes -= strlen($chunk);
        }
    }
}


// ---------------------------------------------------------------------------
// Import-Dump: prüfen, planen, ausführen (Audit M1, N21)
// ---------------------------------------------------------------------------

/** Eine Anweisung aus database.sql, die der DumpPruefer zugelassen hat. */
final class Anweisung {

    /**
     * @param string      $art   set | drop | create | insert
     * @param string      $sql   die Anweisung ohne abschließendes Semikolon
     * @param string|null $kopf  nur INSERT: "INSERT INTO `t` (`a`, `b`) VALUES " in
     *                           einheitlicher Schreibweise - der Schlüssel für
     *                           Sammel-INSERTs (siehe DumpAusfuehrer)
     * @param string|null $werte nur INSERT: die Wertetupel "(…)" bzw. "(…), (…)"
     */
    public function __construct(
        public readonly int $nr,
        public readonly string $art,
        public readonly string $sql,
        public readonly ?string $tabelle = null,
        public readonly ?string $kopf = null,
        public readonly ?string $werte = null,
    ) {}
}

/** Abweisung eines Dumps - die Meldung nennt Anweisungsnummer und Anfang. */
final class DumpAbgelehnt extends \RuntimeException {}

/**
 * Ergebnis eines Durchlaufs durch den DumpPruefer.
 *
 * `tabellen` ist der PLAN: je Tabelle des Dumps die Aktion (ausfuehren /
 * ueberspringen), die gezählten Zeilen, die REFERENCES-Ziele aus dem CREATE
 * TABLE und der Grund eines Überspringens. Vorschau, Anwenden und Audit
 * richten sich nach diesem Plan, nicht nach dem Manifest.
 */
final class DumpBefund {

    /**
     * @param array<string, array{aktion:?string, zeilen:int, verweise:array<int, string>, grund:?string}> $tabellen
     */
    public function __construct(
        public readonly array $tabellen,
        public readonly ?string $problem = null,
    ) {}

    /** @return array<string, string> Tabelle => Aktion */
    public function plan(): array {
        $plan = [];
        foreach ($this->tabellen as $t => $info) {
            $plan[$t] = (string) $info['aktion'];
        }
        return $plan;
    }

    /** @return array<int, string> Tabellen, die der Import tatsächlich ersetzt */
    public function ersetzt(): array {
        return array_keys(array_filter(
            $this->tabellen,
            static fn(array $i): bool => $i['aktion'] === DumpPruefer::AUSFUEHREN
        ));
    }

    /** @return array<string, string> Tabelle => Grund */
    public function uebersprungen(): array {
        $aus = [];
        foreach ($this->tabellen as $t => $info) {
            if ($info['aktion'] === DumpPruefer::UEBERSPRINGEN) {
                $aus[$t] = (string) $info['grund'];
            }
        }
        return $aus;
    }
}

/**
 * Prüft database.sql eines Import-Archivs, bevor irgendetwas davon
 * ausgeführt wird (Audit M1).
 *
 * DAS PROBLEM. Ob ein Archiv ein Teilarchiv ist und welche Tabellen es
 * ersetzt, entnahm der Import bis 1.1.0 allein dem Manifest. Der Dump selbst
 * lief ungeprüft als ein einziges Multi-Statement durch PDO::exec(). Ein
 * präpariertes "Teilarchiv Pferde" konnte so nebenbei `INSERT INTO users …`
 * mitbringen - die Vorschau sagte "alle übrigen Tabellen bleiben
 * unverändert", und hinterher gab es ein Administratorkonto mehr.
 *
 * DIE ANTWORT IST EINE POSITIVLISTE gegen genau das Format, das der Kern
 * schreibt (App\Service\DatabaseDumper): die SET-Kopf- und -Fußzeilen,
 * DROP TABLE IF EXISTS, CREATE TABLE und INSERT … VALUES mit reinen
 * Literalen, je Tabelle genau ein Block DROP -> CREATE -> INSERT*. Alles
 * andere wird abgewiesen - mit Anweisungsnummer und den ersten 80 Zeichen,
 * damit der Betreiber sieht, woran es lag. Das ist bewusst streng: Ein Dump,
 * den der Kern nicht so schreibt, ist entweder nachbearbeitet oder
 * präpariert, und in beiden Fällen ist Abweisen VOR jeder Änderung die
 * richtige Antwort.
 *
 * ZERLEGEN, streamend und quote-bewusst: Es liegt nur die gerade
 * unvollständige Anweisung im Speicher. Ein ';' trennt nur außerhalb von
 * '…', "…" und `…`; in '…' und "…" maskiert ein Backslash das nächste
 * Zeichen, verdoppelte Anführungszeichen sind Literale. Kommentare gibt es
 * nur ZWISCHEN Anweisungen und nur in der Server-Semantik: '--' gefolgt von
 * Leerraum (oder dem Dateiende), bis zum Zeilenende. '--' innerhalb einer
 * Anweisung, '/* … * /', '#' und DELIMITER werden abgewiesen - an genau
 * solchen Stellen deuten Client und Server ein Zeichen verschieden, und die
 * Prüfung sähe etwas anderes als die Datenbank.
 *
 * TABELLENNAMEN NUR KLEIN. Auf Servern mit lower_case_table_names=1/2
 * (Windows, macOS, manche Hoster) träfe ein Block `USERS` die Tabelle users,
 * liefe in der Gruppenzuordnung aber als "sonstiges" durch - und diese
 * Gruppe ist per Vorgabe an. Alle Tabellen von Kern und Addons sind
 * kleingeschrieben.
 *
 * DREI BETRIEBSARTEN:
 *   - Validierung (Tabellenregel, kein Plan): erster Durchlauf. Liefert den
 *     Plan (befund()), gibt selbst keine Anweisungen zur Ausführung heraus.
 *     Die Aktion einer Tabelle steht erst nach ihrem CREATE fest, denn erst
 *     dort stehen die REFERENCES - deshalb überhaupt ein zweiter Durchlauf.
 *   - Ausführung (Tabellenregel + Plan): zweiter Durchlauf. Prüft erneut und
 *     hält sich an den Plan; eine Tabelle, die dort fehlt oder deren Aktion
 *     jetzt anders ausfiele, bricht ab. Liefert die auszuführenden
 *     Anweisungen - die einer übersprungenen Tabelle nicht.
 *   - vertrauenswürdig (weder Regel noch Plan): für den eigenen
 *     Sicherungs-Dump. Jede Tabelle wird ausgeführt, Positivliste und
 *     Größengrenze gelten trotzdem - sie sind es, die der Vorabprüfung der
 *     Sicherung ihren Sinn geben (siehe MigrationController::pruefeSicherung()).
 *
 * Ohne Framework-Bezug, damit tests/Unit sie ohne Datenbank prüfen kann.
 */
final class DumpPruefer {

    public const AUSFUEHREN = 'ausfuehren';
    public const UEBERSPRINGEN = 'ueberspringen';
    public const ABLEHNEN = 'ablehnen';

    /** Untergrenze für die Anweisungsgröße - der Aufrufer nimmt max_allowed_packet. */
    public const MIN_ANWEISUNG = 1048576;

    private const AUSSEN = 0;
    private const EINFACH = 1;
    private const DOPPELT = 2;
    private const BACKTICK = 3;

    /**
     * Die zulässigen SET-Anweisungen, vollständig. Kopf und Fuß des
     * DatabaseDumper, dazu die Zeitzonen-Zeilen aus Framework N66 (merken,
     * auf UTC setzen, zurücksetzen) und ein SQL_MODE, der NUR
     * NO_AUTO_VALUE_ON_ZERO sein darf - NO_BACKSLASH_ESCAPES oder ANSI_QUOTES
     * änderten die Bedeutung der Anführungszeichen, auf denen diese Prüfung
     * beruht.
     *
     * @var array<int, string>
     */
    private const SET_ZEILEN = [
        '/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]$/iD',
        '/^SET\s+NAMES\s+utf8mb4$/iD',
        "/^SET\s+time_zone\s*=\s*'[+-]\d{2}:\d{2}'$/iD",
        "/^SET\s+SQL_MODE\s*=\s*'NO_AUTO_VALUE_ON_ZERO'$/iD",
        '/^SET\s+@hv_dump_zeitzone\s*=\s*@@SESSION\.time_zone$/iD',
        '/^SET\s+time_zone\s*=\s*@hv_dump_zeitzone$/iD',
    ];

    /**
     * Wörter, die außerhalb von Anführungszeichen in einem CREATE TABLE
     * nichts verloren haben. SHOW CREATE TABLE schreibt keines davon; mit
     * ihnen würde aus dem Anlegen einer Tabelle ein Lesezugriff (SELECT,
     * LOAD_FILE), eine Verbindung nach außen (CONNECTION, Fremd-Engines),
     * eine Datei an beliebiger Stelle (DIRECTORY) oder eine Bremse (SLEEP,
     * BENCHMARK).
     *
     * @var array<int, string>
     */
    private const CREATE_VERBOTEN = [
        'SELECT', 'LIKE', 'DIRECTORY', 'CONNECTION', 'UNION', 'TRIGGER', 'PROCEDURE',
        'FUNCTION', 'DEFINER', 'LOAD_FILE', 'SLEEP', 'BENCHMARK', 'INTO',
    ];

    /** @var array<int, string> */
    private const ENGINES = ['INNODB', 'ARIA', 'MYISAM', 'MEMORY'];

    private string $puffer = '';
    private int $anfang = 0;
    private int $pos = 0;
    private int $zustand = self::AUSSEN;
    private bool $imKommentar = false;
    private int $nr = 0;

    /** kopf | drop | block | fuss */
    private string $phase = 'kopf';
    private ?string $tabelle = null;

    /** @var array<string, array{aktion:?string, zeilen:int, verweise:array<int, string>, grund:?string}> */
    private array $tabellen = [];

    /**
     * @param int $maxAnweisung größte zulässige Anweisung in Byte
     * @param (\Closure(string, array<int, string>): array{0:string, 1:?string})|null $entscheide
     *        Tabellenregel: Tabelle + REFERENCES-Ziele -> [Aktion, Grund]
     * @param array<string, string>|null $plan eingefrorener Plan des ersten Durchlaufs
     */
    public function __construct(
        private readonly int $maxAnweisung,
        private readonly ?\Closure $entscheide = null,
        private readonly ?array $plan = null,
    ) {}

    private function vertrauenswuerdig(): bool {
        return $this->entscheide === null && $this->plan === null;
    }

    /** Gibt dieser Durchlauf Anweisungen zur Ausführung heraus? */
    private function liefert(): bool {
        return $this->vertrauenswuerdig() || $this->plan !== null;
    }

    /**
     * Nimmt den nächsten Block des Dumps und liefert die darin
     * abgeschlossenen, zugelassenen Anweisungen.
     *
     * @return \Generator<int, Anweisung>
     * @throws DumpAbgelehnt
     */
    public function zufuehren(string $chunk): \Generator {
        $this->puffer .= $chunk;
        yield from $this->zerlegen(false);
    }

    /**
     * Dateiende: Was jetzt noch offen ist, ist ein Fehler - ein offener
     * Quote oder ein Rest ohne ';'. Leerraum und Kommentare sind erlaubt
     * (der Kern schreibt die letzte Zeile ohne Zeilenumbruch).
     *
     * @return \Generator<int, Anweisung>
     * @throws DumpAbgelehnt
     */
    public function abschliessen(): \Generator {
        yield from $this->zerlegen(true);
        if ($this->zustand !== self::AUSSEN) {
            $this->nr++;
            $this->ablehnen('Der Dump endet in einer offenen Zeichenkette oder einem offenen Bezeichner',
                substr($this->puffer, $this->anfang));
        }
        $rest = trim(substr($this->puffer, $this->anfang));
        if ($rest !== '') {
            $this->nr++;
            $this->ablehnen('Anweisung ohne abschließendes Semikolon', $rest);
        }
        if ($this->phase === 'drop') {
            $this->ablehnen("Auf DROP TABLE `{$this->tabelle}` folgt kein CREATE TABLE");
        }
        if ($this->plan !== null) {
            foreach (array_keys($this->plan) as $t) {
                if (!isset($this->tabellen[$t])) {
                    $this->ablehnen("Tabelle `{$t}` steht im Plan, fehlt aber im Dump");
                }
            }
        }
    }

    public function befund(?string $problem = null): DumpBefund {
        return new DumpBefund($this->tabellen, $problem);
    }

    /** @return \Generator<int, Anweisung> */
    private function zerlegen(bool $ende): \Generator {
        $len = strlen($this->puffer);
        while ($this->pos < $len) {
            if ($this->imKommentar) {
                $nl = strpos($this->puffer, "\n", $this->pos);
                if ($nl === false) {
                    $this->pos = $this->anfang = $len;
                    break;
                }
                $this->pos = $this->anfang = $nl + 1;
                $this->imKommentar = false;
                continue;
            }

            if ($this->zustand === self::AUSSEN) {
                if ($this->pos === $this->anfang) {
                    // Noch kein Zeichen der Anweisung gelesen: Leerraum und
                    // Kommentare zwischen den Anweisungen überspringen.
                    $leer = strspn($this->puffer, " \t\r\n", $this->pos);
                    if ($leer > 0) {
                        $this->pos += $leer;
                        $this->anfang = $this->pos;
                        continue;
                    }
                    if ($this->puffer[$this->pos] === '-') {
                        if ($len - $this->pos < 3 && !$ende) {
                            break; // Ob Kommentar, entscheidet das nächste Zeichen
                        }
                        $drittes = $this->puffer[$this->pos + 2] ?? '';
                        if (substr($this->puffer, $this->pos, 2) === '--'
                            && ($drittes === '' || str_contains(" \t\r\n", $drittes))) {
                            $this->imKommentar = true;
                            $this->pos += 2;
                            $this->anfang = $this->pos;
                            continue;
                        }
                    }
                }

                // Über alles springen, was außerhalb von Quotes nichts bedeutet.
                $this->pos += strcspn($this->puffer, ";'\"`-#/\\", $this->pos);
                if ($this->pos >= $len) {
                    break;
                }
                $z = $this->puffer[$this->pos];
                if ($z === ';') {
                    $sql = substr($this->puffer, $this->anfang, $this->pos - $this->anfang);
                    $this->pos++;
                    $this->anfang = $this->pos;
                    $anweisung = $this->anweisung($sql);
                    if ($anweisung !== null) {
                        yield $anweisung;
                    }
                    continue;
                }
                if ($z === "'" || $z === '"' || $z === '`') {
                    $this->zustand = $z === "'" ? self::EINFACH : ($z === '"' ? self::DOPPELT : self::BACKTICK);
                    $this->pos++;
                    continue;
                }
                if ($z === '#') {
                    $this->nr++;
                    $this->ablehnen('#-Kommentare sind nicht zulässig', substr($this->puffer, $this->anfang, 200));
                }
                if ($z === '\\') {
                    $this->nr++;
                    $this->ablehnen('Backslash außerhalb einer Zeichenkette', substr($this->puffer, $this->anfang, 200));
                }
                // '-' oder '/': erst das nächste Zeichen entscheidet.
                if ($this->pos + 1 >= $len && !$ende) {
                    break;
                }
                $naechstes = $this->puffer[$this->pos + 1] ?? '';
                if ($z === '-' && $naechstes === '-') {
                    $this->nr++;
                    $this->ablehnen('„--“ innerhalb einer Anweisung ist nicht zulässig', substr($this->puffer, $this->anfang, 200));
                }
                if ($z === '/' && $naechstes === '*') {
                    $this->nr++;
                    $this->ablehnen('/*…*/-Kommentare (auch /*!…*/) sind nicht zulässig', substr($this->puffer, $this->anfang, 200));
                }
                $this->pos++;
                continue;
            }

            // In einer Zeichenkette oder einem Bezeichner.
            $quote = $this->zustand === self::EINFACH ? "'" : ($this->zustand === self::DOPPELT ? '"' : '`');
            $this->pos += strcspn($this->puffer, $this->zustand === self::BACKTICK ? '`' : $quote . '\\', $this->pos);
            if ($this->pos >= $len) {
                break;
            }
            if ($this->puffer[$this->pos] === '\\') {
                if ($this->pos + 1 >= $len && !$ende) {
                    break;
                }
                $this->pos += 2;
                continue;
            }
            // Anführungszeichen: verdoppelt ist es ein Literal, sonst das Ende.
            if ($this->pos + 1 >= $len && !$ende) {
                break;
            }
            if (($this->puffer[$this->pos + 1] ?? '') === $quote) {
                $this->pos += 2;
                continue;
            }
            $this->zustand = self::AUSSEN;
            $this->pos++;
        }

        // Eine Anweisung, die schon jetzt über der Grenze liegt, kann ohnehin
        // nicht ausgeführt werden - und soll nicht erst den Speicher füllen.
        if ($this->pos - $this->anfang > $this->maxAnweisung) {
            $this->nr++;
            $this->ablehnen('Anweisung ist größer als die Paketgrenze des Servers (max_allowed_packet, '
                . $this->maxAnweisung . ' Byte)', substr($this->puffer, $this->anfang, 200));
        }
        if ($this->anfang > 0) {
            $this->puffer = substr($this->puffer, $this->anfang);
            $this->pos -= $this->anfang;
            $this->anfang = 0;
        }
    }

    private function anweisung(string $roh): ?Anweisung {
        $this->nr++;
        $sql = rtrim($roh);
        if ($sql === '') {
            // So sieht im Dump des Kerns eine View aus: SHOW CREATE TABLE
            // liefert dort kein "Create Table", geschrieben wird ein leeres ';'.
            $this->ablehnen('Leere Anweisung (entsteht z. B., wenn die Datenbank eine View enthält)');
        }
        if (strlen($sql) > $this->maxAnweisung) {
            $this->ablehnen('Anweisung ist größer als die Paketgrenze des Servers (max_allowed_packet, '
                . $this->maxAnweisung . ' Byte)', $sql);
        }
        if (preg_match('/^SET\s/i', $sql)) {
            return $this->setzen($sql);
        }
        if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`([^`]*)`$/iD', $sql, $m)) {
            return $this->drop($sql, $m[1]);
        }
        if (preg_match('/^CREATE\s+TABLE\s+`([^`]*)`\s*\(/i', $sql, $m)) {
            return $this->create($sql, $m[1]);
        }
        if (preg_match('/^INSERT\s+INTO\s+`([^`]*)`\s*\(/i', $sql, $m)) {
            return $this->insert($sql, $m[1]);
        }
        $this->ablehnen('Anweisung ist im Dump-Format des Kerns nicht vorgesehen', $sql);
    }

    private function setzen(string $sql): ?Anweisung {
        $erlaubt = false;
        foreach (self::SET_ZEILEN as $muster) {
            if (preg_match($muster, $sql)) {
                $erlaubt = true;
                break;
            }
        }
        if (!$erlaubt) {
            $this->ablehnen('Diese SET-Anweisung ist nicht zulässig', $sql);
        }
        if ($this->phase === 'drop') {
            $this->ablehnen("Auf DROP TABLE `{$this->tabelle}` folgt kein CREATE TABLE", $sql);
        }
        if ($this->phase === 'block') {
            // SET nach dem ersten Block: ab hier ist es der Fuß.
            $this->phase = 'fuss';
            $this->tabelle = null;
        }
        return $this->liefert() ? new Anweisung($this->nr, 'set', $sql) : null;
    }

    private function drop(string $sql, string $name): ?Anweisung {
        $this->pruefeName($name, $sql);
        if ($this->phase === 'fuss') {
            $this->ablehnen('Tabellenblock nach den abschließenden SET-Anweisungen', $sql);
        }
        if ($this->phase === 'drop') {
            $this->ablehnen("Auf DROP TABLE `{$this->tabelle}` folgt kein CREATE TABLE", $sql);
        }
        if (isset($this->tabellen[$name])) {
            $this->ablehnen("Tabelle `{$name}` steht mehrfach im Dump", $sql);
        }
        $aktion = null;
        if ($this->vertrauenswuerdig()) {
            $aktion = self::AUSFUEHREN;
        } elseif ($this->plan !== null) {
            if (!isset($this->plan[$name])) {
                $this->ablehnen("Tabelle `{$name}` steht nicht im geprüften Plan", $sql);
            }
            $aktion = $this->plan[$name];
        }
        $this->tabellen[$name] = ['aktion' => $aktion, 'zeilen' => 0, 'verweise' => [], 'grund' => null];
        $this->phase = 'drop';
        $this->tabelle = $name;
        return $aktion === self::AUSFUEHREN ? new Anweisung($this->nr, 'drop', $sql, $name) : null;
    }

    private function create(string $sql, string $name): ?Anweisung {
        $this->pruefeName($name, $sql);
        if ($this->phase !== 'drop' || $this->tabelle !== $name) {
            $this->ablehnen("CREATE TABLE `{$name}` ohne unmittelbar vorausgehendes DROP TABLE derselben Tabelle", $sql);
        }
        $verweise = $this->pruefeCreate($sql);
        $this->tabellen[$name]['verweise'] = $verweise;

        if ($this->entscheide !== null) {
            [$aktion, $grund] = ($this->entscheide)($name, $verweise);
            if ($aktion === self::ABLEHNEN) {
                $this->ablehnen("Tabelle `{$name}` darf dieses Archiv nicht ersetzen: {$grund}");
            }
            if ($this->plan !== null && $aktion !== $this->plan[$name]) {
                $this->ablehnen("Tabelle `{$name}` weicht vom geprüften Plan ab");
            }
            $this->tabellen[$name]['aktion'] = $aktion;
            $this->tabellen[$name]['grund'] = $grund;
        }
        $this->phase = 'block';
        return $this->tabellen[$name]['aktion'] === self::AUSFUEHREN && $this->liefert()
            ? new Anweisung($this->nr, 'create', $sql, $name)
            : null;
    }

    private function insert(string $sql, string $name): ?Anweisung {
        $this->pruefeName($name, $sql);
        if ($this->phase !== 'block') {
            $this->ablehnen("INSERT INTO `{$name}` außerhalb seines Tabellenblocks (vor CREATE TABLE oder nach dem Abschluss)", $sql);
        }
        if ($this->tabelle !== $name) {
            $this->ablehnen("INSERT INTO `{$name}` im Block der Tabelle `{$this->tabelle}`", $sql);
        }
        [$kopf, $werte, $zeilen] = $this->pruefeInsert($sql, $name);
        $this->tabellen[$name]['zeilen'] += $zeilen;
        return $this->tabellen[$name]['aktion'] === self::AUSFUEHREN && $this->liefert()
            ? new Anweisung($this->nr, 'insert', $sql, $name, $kopf, $werte)
            : null;
    }

    private function pruefeName(string $name, string $sql): void {
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $name) !== 1) {
            $this->ablehnen('Tabellennamen sind nur aus Kleinbuchstaben, Ziffern und _ zulässig', $sql);
        }
    }

    /**
     * @return array<int, string> die REFERENCES-Ziele
     */
    private function pruefeCreate(string $sql): array {
        $verweise = [];
        $token = self::tokens($sql);
        $n = count($token);
        for ($i = 0; $i < $n; $i++) {
            [$typ, $text] = $token[$i];
            if ($typ !== 'wort') {
                continue;
            }
            $wort = strtoupper($text);
            if (in_array($wort, self::CREATE_VERBOTEN, true)) {
                $this->ablehnen("Schlüsselwort {$wort} ist in CREATE TABLE nicht zulässig", $sql);
            }
            if ($wort === 'ENGINE') {
                $j = $i + 1;
                if (($token[$j][0] ?? '') === 'zeichen' && $token[$j][1] === '=') {
                    $j++;
                }
                if (($token[$j][0] ?? '') !== 'wort' || !in_array(strtoupper($token[$j][1]), self::ENGINES, true)) {
                    $this->ablehnen('Nur die Speicher-Engines InnoDB, Aria, MyISAM und MEMORY sind zulässig', $sql);
                }
            }
            if ($wort === 'REFERENCES') {
                if (($token[$i + 1][0] ?? '') !== 'bezeichner') {
                    $this->ablehnen('REFERENCES ohne Tabellenbezeichner in Backticks', $sql);
                }
                $verweise[] = $token[$i + 1][1];
            }
        }
        return array_values(array_unique($verweise));
    }

    /**
     * INSERT INTO `t` (`a`, …) VALUES (lit, …)[, (lit, …)]* - lit ist NULL
     * oder ein '…'-String, nichts sonst. Damit sind Unterabfragen,
     * Funktionen, Ausdrücke und ON DUPLICATE KEY UPDATE ausgeschlossen, ohne
     * dass sie einzeln aufgezählt werden müssten.
     *
     * @return array{0:string, 1:string, 2:int} Kopf, Werte, Zahl der Zeilen
     */
    private function pruefeInsert(string $sql, string $name): array {
        $token = self::tokens($sql);
        $i = 3; // INSERT INTO `name`
        $erwarte = function (string $typ, ?string $text = null) use (&$token, &$i, $sql): string {
            $t = $token[$i] ?? null;
            if ($t === null || $t[0] !== $typ || ($text !== null && strtoupper($t[1]) !== $text)) {
                $this->ablehnen('INSERT weicht vom Format INSERT INTO `t` (`spalten`) VALUES (\'werte\') ab', $sql);
            }
            $i++;
            return $t[1];
        };
        $erwarte('zeichen', '(');
        $spalten = [];
        do {
            $spalten[] = '`' . str_replace('`', '``', $erwarte('bezeichner')) . '`';
            $weiter = ($token[$i] ?? null) === ['zeichen', ','];
            if ($weiter) {
                $i++;
            }
        } while ($weiter);
        $erwarte('zeichen', ')');
        $erwarte('wort', 'VALUES');
        $werteAb = $token[$i - 1][2] ?? strlen($sql); // Ende des Worts VALUES

        $zeilen = 0;
        do {
            $erwarte('zeichen', '(');
            $anzahl = 0;
            do {
                $t = $token[$i] ?? null;
                $literal = $t !== null
                    && (($t[0] === 'text' && $t[1][0] === "'") || ($t[0] === 'wort' && strtoupper($t[1]) === 'NULL'));
                if (!$literal) {
                    $this->ablehnen('INSERT enthält einen Wert, der weder NULL noch eine Zeichenkette ist', $sql);
                }
                $i++;
                $anzahl++;
                $weiter = ($token[$i] ?? null) === ['zeichen', ','];
                if ($weiter) {
                    $i++;
                }
            } while ($weiter);
            $erwarte('zeichen', ')');
            if ($anzahl !== count($spalten)) {
                $this->ablehnen('INSERT: Zahl der Werte passt nicht zur Spaltenliste', $sql);
            }
            $zeilen++;
            $weiter = ($token[$i] ?? null) === ['zeichen', ','];
            if ($weiter) {
                $i++;
            }
        } while ($weiter);
        if ($i !== count($token)) {
            $this->ablehnen('INSERT enthält nach den Werten weitere Bestandteile', $sql);
        }

        $kopf = 'INSERT INTO `' . $name . '` (' . implode(', ', $spalten) . ') VALUES ';
        return [$kopf, trim(substr($sql, $werteAb)), $zeilen];
    }

    /**
     * Minimaler Lexer für CREATE und INSERT. Liefert [typ, text] bzw. für
     * Zeichen [typ, text, position]; Typen: bezeichner (`…`, entmaskiert),
     * text ('…' oder "…", roh), wort, zeichen. Zeichenketten folgen exakt
     * derselben Regel wie das Zerlegen oben.
     *
     * @return array<int, array{0:string, 1:string, 2?:int}>
     */
    private static function tokens(string $sql): array {
        $aus = [];
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $i += strspn($sql, " \t\r\n", $i);
            if ($i >= $len) {
                break;
            }
            $z = $sql[$i];
            if ($z === '`') {
                $j = $i + 1;
                $name = '';
                while (true) {
                    $k = strpos($sql, '`', $j);
                    if ($k === false) {
                        $k = $len;
                        $name .= substr($sql, $j);
                        $i = $len;
                        break;
                    }
                    $name .= substr($sql, $j, $k - $j);
                    if (($sql[$k + 1] ?? '') === '`') {
                        $name .= '`';
                        $j = $k + 2;
                        continue;
                    }
                    $i = $k + 1;
                    break;
                }
                $aus[] = ['bezeichner', $name];
                continue;
            }
            if ($z === "'" || $z === '"') {
                $j = $i + 1;
                while ($j < $len) {
                    $j += strcspn($sql, $z . '\\', $j);
                    if ($j >= $len) {
                        break;
                    }
                    if ($sql[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if (($sql[$j + 1] ?? '') === $z) {
                        $j += 2;
                        continue;
                    }
                    $j++;
                    break;
                }
                $aus[] = ['text', substr($sql, $i, $j - $i)];
                $i = $j;
                continue;
            }
            if (preg_match('/\G[A-Za-z0-9_$]+/', $sql, $m, 0, $i)) {
                $aus[] = ['wort', $m[0], $i + strlen($m[0])];
                $i += strlen($m[0]);
                continue;
            }
            $aus[] = ['zeichen', $z];
            $i++;
        }
        // Zeichen tragen bewusst keine Position - so bleiben Vergleiche wie
        // ($token[$i] ?? null) === ['zeichen', ','] einfach. Wo eine Position
        // gebraucht wird (Anfang der Werte), hängt sie am Wort davor.
        return $aus;
    }

    private function ablehnen(string $grund, string $sql = ''): never {
        $auszug = '';
        if ($sql !== '') {
            // Maskiert: Steuerzeichen raus, auf 80 Zeichen gekürzt, gültiges
            // UTF-8 - die Meldung landet in der Oberfläche.
            $auszug = mb_scrub(substr(ltrim($sql), 0, 80), 'UTF-8');
            $auszug = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $auszug);
        }
        throw new DumpAbgelehnt(
            'Anweisung ' . $this->nr . ': ' . $grund . ($auszug !== '' ? ' – „' . $auszug . '…“' : '') . '.'
        );
    }
}

/**
 * Führt geprüfte Anweisungen aus - einzeln oder als Sammel-INSERT (Audit
 * N21).
 *
 * Bis 1.1.0 ging der gesamte Dump als EIN Paket an den Server und
 * scheiterte ab 16 MiB an max_allowed_packet. Jetzt geht jede Anweisung für
 * sich, und aufeinanderfolgende INSERTs derselben Tabelle mit derselben
 * Spaltenliste werden bis zur Sammelgrenze zusammengefasst - Zeile für Zeile
 * wäre bei großen Beständen unnötig langsam.
 *
 * Das Callable heißt $senden, nicht $exec: security/plugin-security-scan.sh
 * wertet `$exec(` als Aufruf der Shell-Funktion exec().
 */
final class DumpAusfuehrer {

    private \Closure $senden;

    public function __construct(callable $senden, private readonly int $sammelGrenze) {
        $this->senden = \Closure::fromCallable($senden);
    }

    /**
     * @param iterable<Anweisung> $anweisungen
     * @return int Zahl der ausgeführten Anweisungen des Dumps
     */
    public function ausfuehren(iterable $anweisungen): int {
        $kopf = null;
        $werte = [];
        $groesse = 0;
        $anzahl = 0;
        foreach ($anweisungen as $a) {
            $anzahl++;
            if ($a->art === 'insert' && $a->kopf !== null && $a->werte !== null) {
                if ($kopf === $a->kopf && $groesse + 2 + strlen($a->werte) <= $this->sammelGrenze) {
                    $werte[] = $a->werte;
                    $groesse += 2 + strlen($a->werte);
                    continue;
                }
                $this->leeren($kopf, $werte);
                $kopf = $a->kopf;
                $werte = [$a->werte];
                $groesse = strlen($kopf) + strlen($a->werte);
                continue;
            }
            $this->leeren($kopf, $werte);
            $kopf = null;
            $werte = [];
            ($this->senden)($a->sql);
        }
        $this->leeren($kopf, $werte);
        return $anzahl;
    }

    /** @param array<int, string> $werte */
    private function leeren(?string $kopf, array $werte): void {
        if ($kopf !== null && $werte !== []) {
            ($this->senden)($kopf . implode(', ', $werte));
        }
    }
}

/**
 * Die Tabellenregel des Imports: Darf eine Tabelle aus dem Dump ersetzt
 * werden? (Audit M1/M3)
 *
 * Grundlage ist die Auswahl laut Manifest - aber gegen den TATSÄCHLICHEN
 * Inhalt des Dumps gehalten, nicht umgekehrt:
 *
 *   (a) Weder die alte (bis 1.1.0) noch die neue Gruppe der Tabelle liegt in
 *       der Auswahl -> ABLEHNEN. Das ist der präparierte Fall: ein
 *       "Teilarchiv Pferde" mit einem users-Block. `users` selbst und alle
 *       festen Benutzertabellen landen ohne gewählte Gruppe hier.
 *   (b) Die neue Gruppe ist "benutzer", die Auswahl enthält sie aber nicht
 *       -> UEBERSPRINGEN. Das sind ältere Archive, in denen `user_passkeys`
 *       noch unter "sonstiges" oder eine plugin_-Tabelle mit Verweis auf
 *       users unter "addons" lief. Ihre Zeilen hingen sonst an fremden
 *       Konten gleicher Kennung; der Rest des Archivs bleibt einspielbar.
 *   (c) Sonst -> AUSFUEHREN.
 *
 * Die Verweisziele einer Tabelle sind die REFERENCES aus dem Dump UND die
 * Fremdschlüssel der gleichnamigen Tabelle auf dem Ziel. Ein Dump, dessen
 * CREATE die REFERENCES einfach weglässt, hebelt die Regel damit nicht aus.
 */
final class Importregel {

    private function __construct() {}

    /**
     * @param array<int, string> $auswahl Gruppen laut Manifest (bereinigt)
     * @param array<string, array<int, string>> $fkKarte eingefrorene Fremdschlüssel des Ziels: Tabelle => Ziele
     * @return \Closure(string, array<int, string>): array{0:string, 1:?string}
     */
    public static function fuer(array $auswahl, array $fkKarte): \Closure {
        $gewaehlt = array_flip($auswahl);
        return static function (string $tabelle, array $verweiseDump) use ($gewaehlt, $fkKarte): array {
            $ziele = array_values(array_unique(array_merge($verweiseDump, $fkKarte[$tabelle] ?? [])));
            $neu = Exportauswahl::gruppeFuer($tabelle, $ziele);
            $alt = Exportauswahl::altGruppeFuer($tabelle);
            if (!isset($gewaehlt[$neu]) && !isset($gewaehlt[$alt])) {
                return [DumpPruefer::ABLEHNEN, 'sie gehört zur Gruppe „' . Exportauswahl::label($neu)
                    . '“, die laut Manifest nicht im Archiv ist'];
            }
            if ($neu === Exportauswahl::GRUPPE_BENUTZER && !isset($gewaehlt[Exportauswahl::GRUPPE_BENUTZER])) {
                return [DumpPruefer::UEBERSPRINGEN, 'verweist auf users, das nicht im Archiv ist; die Zeilen '
                    . 'hingen sonst an fremden Konten gleicher Kennung'];
            }
            return [DumpPruefer::AUSFUEHREN, null];
        };
    }
}

/**
 * Wartungsmodus nach einem gescheiterten Import (Audit N21).
 *
 * Gelingt der Rückweg, ist die Instanz wieder auf dem Stand vor dem Import,
 * und der Wartungsmodus fällt. Scheitert AUCH der Rückweg, ist die Datenbank
 * halb ersetzt - dann darf sie auf keinen Fall wieder online gehen. Der
 * Marker wird deshalb OHNE pid neu geschrieben: Ein solcher Marker gilt für
 * den Kern als von Hand gesetzt (Maintenance::isStale()) und verfällt nie,
 * auch nicht, wenn dieser Prozess längst beendet ist. Gelöst wird er erst
 * von dem, der die Sicherung eingespielt hat (rm var/wartung.lock).
 *
 * Bis der Kern dafür eine eigene API hat (Maintenance::enableDauerhaft(),
 * Framework-Folgeissue), schreibt das Addon den Marker selbst - im Format,
 * das Maintenance::info() liest.
 */
final class Wartung {

    private function __construct() {}

    public static function nachFehlschlag(bool $rueckwegOk, string $grund): void {
        if ($rueckwegOk) {
            \App\Service\Maintenance::disable();
            return;
        }
        $marker = json_encode(
            ['grund' => $grund, 'seit' => date('c')],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        if (@file_put_contents(\App\Service\Maintenance::lockFile(), (string) $marker, LOCK_EX) === false) {
            // Der alte Marker (mit pid) bleibt dann stehen - besser als
            // keiner, aber er verfällt nach dem Prozessende. Deshalb laut.
            error_log('Datenmigration: dauerhafter Wartungsmarker konnte nicht geschrieben werden - '
                . \App\Service\Maintenance::lockFile());
        }
    }
}


// ---------------------------------------------------------------------------
// Zugangsdaten über einen APP_KEY-Wechsel tragen (Audit M25)
// ---------------------------------------------------------------------------

/**
 * Verpackt die mit dem APP_KEY verschlüsselten Zugangsdaten einer Instanz
 * unter einem Exportpasswort - für den Umzug auf eine Instanz mit anderem
 * APP_KEY.
 *
 * DAS PROBLEM. App\Security\Crypto verschlüsselt mit einem aus dem APP_KEY
 * abgeleiteten Schlüssel: das SMTP-Passwort, die Zugangsdaten der
 * Backup-Ziele, die Secrets der Addons (captcha-*, mitglieder-konten) und die
 * TOTP-Geheimnisse der Konten. Der APP_KEY wandert bewusst nicht mit. Auf
 * einer Zielinstanz mit eigenem Schlüssel lag danach unlesbarer Chiffretext
 * in der Datenbank - ohne jede Meldung, bis die erste Mail nicht rausging.
 *
 * DIE ANTWORT ist ein optionales Exportpasswort. Die Werte werden auf der
 * Quelle entschlüsselt und als EIN Umschlag in `geheimnisse.json` gelegt:
 * PBKDF2-SHA256 (600 000 Runden, 16 Byte Salt) für den Schlüssel,
 * AES-256-GCM mit 12 Byte IV und fester AAD für den Inhalt - nur openssl,
 * das Crypto ohnehin verlangt. Der Import entpackt ihn und verschlüsselt die
 * Werte mit dem APP_KEY des Ziels neu.
 *
 * Das Passwort ist die einzige Hürde vor Klartext-Zugangsdaten - deshalb
 * mindestens 12 Zeichen, eine hohe Rundenzahl, und beim Entpacken eine
 * Obergrenze für `iter` (ein fremdes Archiv soll den Server nicht mit
 * 10^9 Runden beschäftigen). Das Passwort selbst erscheint nirgends: nicht
 * im Archiv, nicht im Audit-Log, nicht in einer Fehlermeldung, nicht als
 * verstecktes Formularfeld.
 *
 * Ohne Framework-Bezug, damit tests/Unit sie ohne Datenbank prüfen kann.
 */
final class Geheimnisumschlag {

    public const MIN_PASSWORT = 12;
    public const ITERATIONEN = 600000;
    public const MIN_ITERATIONEN = 100000;
    public const MAX_ITERATIONEN = 5000000;

    /** Zusätzliche authentifizierte Daten: bindet das Chiffrat an diesen Zweck. */
    private const AAD = 'datenmigration-geheimnisse-v1';

    /** Spaltenbreite von settings.setting_key. */
    private const MAX_SCHLUESSEL = 50;

    private function __construct() {}

    /**
     * @param array{settings: array<string, string>, users_totp: array<int, string>} $daten
     * @param int $iter Rundenzahl - nur Tests nehmen weniger (Untergrenze gilt trotzdem)
     * @throws \InvalidArgumentException bei zu kurzem Passwort oder ungültigen Daten
     */
    public static function verpacken(array $daten, string $passwort, int $iter = self::ITERATIONEN): string {
        if (mb_strlen($passwort, 'UTF-8') < self::MIN_PASSWORT) {
            throw new \InvalidArgumentException('Das Exportpasswort muss mindestens ' . self::MIN_PASSWORT
                . ' Zeichen lang sein.');
        }
        if ($iter < self::MIN_ITERATIONEN || $iter > self::MAX_ITERATIONEN) {
            throw new \InvalidArgumentException('Unzulässige Rundenzahl.');
        }
        $klar = json_encode(self::struktur($daten, \InvalidArgumentException::class), JSON_UNESCAPED_UNICODE);
        if ($klar === false) {
            throw new \InvalidArgumentException('Zugangsdaten lassen sich nicht als JSON schreiben.');
        }
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $schluessel = hash_pbkdf2('sha256', $passwort, $salt, $iter, 32, true);
        $tag = '';
        $chiffrat = openssl_encrypt($klar, 'aes-256-gcm', $schluessel, OPENSSL_RAW_DATA, $iv, $tag, self::AAD, 16);
        if ($chiffrat === false) {
            throw new \RuntimeException('Verschlüsselung der Zugangsdaten fehlgeschlagen.');
        }
        return (string) json_encode([
            'v' => 1,
            'kdf' => 'pbkdf2-sha256',
            'iter' => $iter,
            'salt' => base64_encode($salt),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'daten' => base64_encode($chiffrat),
        ]);
    }

    /**
     * @return array{settings: array<string, string>, users_totp: array<int, string>}
     * @throws \RuntimeException bei falschem Passwort, Manipulation oder ungültigem Aufbau
     */
    public static function entpacken(string $json, string $passwort): array {
        $u = json_decode($json, true);
        if (!is_array($u) || ($u['v'] ?? null) !== 1 || ($u['kdf'] ?? null) !== 'pbkdf2-sha256') {
            throw new \RuntimeException('geheimnisse.json hat ein unbekanntes Format.');
        }
        $iter = $u['iter'] ?? null;
        if (!is_int($iter) || $iter < self::MIN_ITERATIONEN || $iter > self::MAX_ITERATIONEN) {
            throw new \RuntimeException('geheimnisse.json nennt eine unzulässige Rundenzahl.');
        }
        $roh = [];
        foreach (['salt' => 16, 'iv' => 12, 'tag' => 16, 'daten' => null] as $feld => $laenge) {
            $wert = is_string($u[$feld] ?? null) ? base64_decode($u[$feld], true) : false;
            if ($wert === false || ($laenge !== null && strlen($wert) !== $laenge)) {
                throw new \RuntimeException("geheimnisse.json ist beschädigt ({$feld}).");
            }
            $roh[$feld] = $wert;
        }
        $schluessel = hash_pbkdf2('sha256', $passwort, $roh['salt'], $iter, 32, true);
        $klar = openssl_decrypt($roh['daten'], 'aes-256-gcm', $schluessel, OPENSSL_RAW_DATA, $roh['iv'], $roh['tag'], self::AAD);
        if ($klar === false) {
            throw new \RuntimeException('Exportpasswort falsch oder Geheimnis-Datei beschädigt.');
        }
        return self::struktur(json_decode($klar, true), \RuntimeException::class);
    }

    /**
     * Prüft den Aufbau: settings als Name => Wert (Name höchstens 50 Zeichen,
     * wie die Spalte), users_totp als Konto-ID => Geheimnis. Ein falscher
     * Aufbau wird hier abgewiesen und nicht erst mitten im Import zu einem
     * TypeError - dann schon unter Wartungsmodus.
     *
     * @param class-string<\Throwable> $fehler
     * @return array{settings: array<string, string>, users_totp: array<int, string>}
     */
    private static function struktur(mixed $daten, string $fehler): array {
        if (!is_array($daten) || !is_array($daten['settings'] ?? null) || !is_array($daten['users_totp'] ?? null)) {
            throw new $fehler('Die Zugangsdaten haben einen ungültigen Aufbau.');
        }
        $aus = ['settings' => [], 'users_totp' => []];
        foreach ($daten['settings'] as $name => $wert) {
            if (!is_string($name) || $name === '' || strlen($name) > self::MAX_SCHLUESSEL || !is_string($wert)) {
                throw new $fehler('Die Zugangsdaten haben einen ungültigen Aufbau (Einstellungen).');
            }
            $aus['settings'][$name] = $wert;
        }
        foreach ($daten['users_totp'] as $id => $wert) {
            if (!is_int($id) || $id <= 0 || !is_string($wert)) {
                throw new $fehler('Die Zugangsdaten haben einen ungültigen Aufbau (TOTP).');
            }
            $aus['users_totp'][$id] = $wert;
        }
        return $aus;
    }
}


// ---------------------------------------------------------------------------
// Controller
// ---------------------------------------------------------------------------

class MigrationController extends BaseController {

    /**
     * Archivformat, das dieses Addon SCHREIBT.
     *
     * 2 seit #121: Das Manifest führt jetzt zusätzlich `auswahl` (welche
     * Gruppen im Archiv sind) und `vollstaendig`.
     *
     * 3 seit 1.3.0: Pferdefotos unter `storage-horses/` (Audit M26), dazu im
     * Manifest `horses_count`, `app_key_fingerabdruck`, `verschluesselt` und
     * `geheimnisse` (Audit M25) und gegebenenfalls der Eintrag
     * `geheimnisse.json`. Ein älteres Addon soll ein solches Archiv
     * ABWEISEN, statt die Pferdefotos still zu übergehen - deshalb ein neues
     * Format statt optionaler Einträge.
     */
    public const FORMAT = 3;

    /**
     * Formate, die dieses Addon LIEST.
     *
     * Format 1 bleibt drin, obwohl es `auswahl` nicht kennt: Ein Archiv aus
     * v0.7 ist immer ein Vollarchiv, das lässt sich beim Lesen einsetzen
     * (siehe auswahlDesArchivs()). Es zurückzuweisen hieße, vorhandene
     * Archive über Nacht unbrauchbar zu machen, ohne dass es dafür einen
     * Grund gäbe.
     *
     * Umgekehrt gilt das NICHT: Eine v0.7-Instanz weist ein Format-2-Archiv
     * ab, und das ist richtig - sie würde ein Teilarchiv wie einen
     * vollständigen Stand einspielen und alles Nicht-Enthaltene wegwerfen.
     *
     * Formate 1 und 2 enthalten keine Pferdefotos aus storage/horses und
     * keinen Schlüssel-Fingerabdruck; die Vorschau sagt beides.
     *
     * @var array<int, int>
     */
    public const LESBARE_FORMATE = [1, 2, 3];

    /** Präfix der Pferdefotos im Archiv (Audit M26). */
    private const PRAEFIX_FOTOS = 'storage-horses/';

    /** Obergrenze für manifest.json und geheimnisse.json. */
    private const MAX_KOPFEINTRAG = 1048576;

    /**
     * Die Schutzdateien, die der Kern unter public/uploads mitliefert
     * (relativer Pfad => eingebaute Mindestfassung), Audit N1.
     *
     * Bis 1.2.0 stellte der Import nur die .htaccess im Wurzelverzeichnis
     * wieder her. Nach dem Verzeichnistausch eines Vollarchivs fehlte damit
     * `horses/.htaccess` - und Pferdefotos, die dort noch aus der Zeit vor
     * Kern 0.8 lagen, waren wieder statisch abrufbar, am Sichtbarkeitsschutz
     * von /media/horse-image vorbei (Framework#366).
     *
     * @var array<string, string>
     */
    private const SCHUTZDATEIEN = [
        '.htaccess' => self::HTACCESS_UPLOADS,
        'horses/.htaccess' => self::HTACCESS_HORSES,
    ];

    /** Mindestfassung von public/uploads/.htaccess, angeglichen an die Kern-Datei. */
    private const HTACCESS_UPLOADS = <<<'HTACCESS'
    # Wiederhergestellt nach einem Datenmigrations-Import.
    # Kein PHP in diesem Verzeichnis - hier liegen ausschliesslich Daten.
    Options -Indexes -ExecCGI

    <FilesMatch "\.(php|php\d*|phtml|pl|py|jsp|asp|sh|cgi|phar|inc)$">
        SetHandler default-handler
        Require all denied
    </FilesMatch>

    <IfModule mod_php7.c>
        php_flag engine off
    </IfModule>

    <IfModule mod_php.c>
        php_flag engine off
    </IfModule>

    <IfModule mod_headers.c>
        <FilesMatch "\.(jpe?g|png|gif|webp)$">
            Header set Cross-Origin-Resource-Policy "same-origin"
            Header set X-Content-Type-Options "nosniff"
        </FilesMatch>
    </IfModule>

    HTACCESS;

    /** Mindestfassung von public/uploads/horses/.htaccess (Framework#366). */
    private const HTACCESS_HORSES = <<<'HTACCESS'
    # Wiederhergestellt nach einem Datenmigrations-Import (#366).
    # Pferdefotos liegen unter storage/horses und werden ausschliesslich ueber
    # /media/horse-image ausgeliefert, nie statisch aus diesem Verzeichnis.
    # Gilt nur fuer Apache; nginx/Caddy: location ^~ /uploads/horses/ { deny all; }
    Require all denied

    HTACCESS;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    /**
     * Export und Import verlangen zusätzlich zur Modulberechtigung
     * Administratorrechte.
     *
     * Die Berechtigungen `datenmigration.export`/`.import` sehen aus wie jede
     * andere Modulberechtigung und lassen sich im Gruppen-Editor an jede
     * Gruppe vergeben - ihre Wirkung ist aber eine ganz andere:
     *
     *   Export liefert den VOLLSTÄNDIGEN Datenbank-Dump aus, inklusive
     *   `users` (Passwort-Hashes, TOTP-Secrets), `api_keys` und aller
     *   personenbezogenen Daten. Wer ihn auslösen darf, hat faktisch
     *   Lesezugriff auf alles.
     *
     *   Import ERSETZT die gesamte Datenbank - also auch die Benutzertabelle.
     *   Wer ihn auslösen darf, kann sich mit einem selbst gebauten Archiv zum
     *   Administrator machen.
     *
     * Beides sind im Kern bewusst admin-only-Fähigkeiten (Backup, Update,
     * Systemreset). Ein Addon darf sie nicht über eine gewöhnliche, an
     * "Redakteure" vergebbare Berechtigung öffnen. Die Berechtigung bleibt
     * erhalten - sie erlaubt dem Betreiber weiterhin, die Funktion für
     * einzelne Administratoren abzuschalten -, sie genügt nur nicht mehr für
     * sich allein.
     */
    private function requireAdminForFullAccess(string $aktion): void {
        if ($this->isAdmin()) {
            return;
        }

        // Hier bleibt es bewusst bei AuditLogger und der Kategorie 'security'
        // statt PluginAudit (Framework#352): Das ist kein Vorgang dieses
        // Addons, sondern ein abgewiesener Versuch, an den gesamten
        // Datenbestand zu kommen. Er gehört zu den Sicherheitsereignissen,
        // die man am Stück durchsieht, nicht in den Addon-Filter.
        AuditLogger::log(
            'Datenmigration abgelehnt: Administratorrechte erforderlich',
            'security',
            "Aktion '{$aktion}' ohne Admin-Rechte angefordert",
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? null
        );

        $this->renderForbidden(
            'Zugriff verweigert: Export und Import der Datenmigration betreffen den gesamten Datenbestand '
            . '(inklusive Benutzerkonten und Zugangsdaten) und stehen deshalb ausschließlich Administratoren offen.'
        );
    }

    private function rootDir(): string {
        return dirname(__DIR__, 2);
    }

    private function uploadsDir(): string {
        return $this->rootDir() . '/public/uploads';
    }

    /**
     * Ablage der Pferdefotos (Audit M26) - außerhalb des Webroots, seit Kern
     * 0.8.0 (daher core_compatibility >=0.8.0). Im Docker-Setup des Kerns ein
     * eigenes Volume (horses_data): Der Import tauscht deshalb nie das
     * Verzeichnis, sondern nur seinen Inhalt (siehe ersetzeInhalt()).
     */
    private function horsesDir(): string {
        return HorseImagePath::dir();
    }

    /** Ablage für Archive und Sicherungs-Dumps - außerhalb von public/. */
    private function stageDir(): string {
        $dir = $this->rootDir() . '/var/datenmigration';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /** @return array<int, string> Archivdateien in var/datenmigration */
    private function stagedArchives(): array {
        $out = [];
        foreach (scandir($this->stageDir()) ?: [] as $f) {
            if (preg_match('/\.tar(\.gz)?$/', $f) && !str_starts_with($f, 'sicherung-')) {
                $out[] = $f;
            }
        }
        sort($out);
        return $out;
    }

    /** Dateiname aus Request-Parameter — strikt auf die Ablage begrenzt. */
    private function stagedPath(string $name): ?string {
        $name = basename($name);
        if (!preg_match('/^[A-Za-z0-9._-]+\.tar(\.gz)?$/', $name) || str_starts_with($name, 'sicherung-')) {
            return null;
        }
        $path = $this->stageDir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** @return array{core_version:string, site_name:string, plugins:array<int,array{slug:string,version:string,enabled:bool}>, tables:array<string,int>} */
    private function localInventory(): array {
        $db = Database::getInstance();
        $tables = [];
        // Backtick als eigene Konstante: haelt Identifier-Quoting und Variablen
        // auf getrennten Zeilen — der statische Sicherheits-Scan wertet
        // Backtick gefolgt von $variable auf einer Zeile als Shell-Ausfuehrung.
        $bt = '`';
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $quoted = $bt . str_replace($bt, $bt . $bt, $t) . $bt;
            $tables[$t] = (int) $db->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        }
        $plugins = [];
        foreach ($db->query('SELECT slug, installed_version, enabled FROM plugins ORDER BY slug')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $plugins[] = ['slug' => $p['slug'], 'version' => $p['installed_version'], 'enabled' => (bool) $p['enabled']];
        }
        $site = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'site_name'")->fetchColumn();
        return [
            'core_version' => defined('CORE_VERSION') ? CORE_VERSION : '',
            'site_name' => is_string($site) ? $site : '',
            'plugins' => $plugins,
            'tables' => $tables,
        ];
    }

    /**
     * Die Fremdschlüssel dieser Datenbank, aus information_schema.
     *
     * Bewusst abgefragt statt im Addon gepflegt: Die Liste muss die Tabellen
     * der ADDONS mit umfassen (deckanfrage, galerie, verkaufsboerse &c.
     * verweisen alle auf `horses`), und die kennt dieses Addon nicht. Eine
     * handgepflegte Liste wäre am Tag nach dem nächsten neuen Addon falsch -
     * und zwar still.
     *
     * @return array<int, array{tabelle:string, spalte:string, ziel:string}>
     */
    private function fremdschluessel(): array {
        $sql = 'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
                  FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND REFERENCED_TABLE_NAME IS NOT NULL';
        $out = [];
        foreach (Database::getInstance()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // Selbstbezüge (horses.sire_id -> horses) können nie brechen: Eine
            // Tabelle ist entweder ganz im Archiv oder gar nicht.
            if ($r['TABLE_NAME'] === $r['REFERENCED_TABLE_NAME']) {
                continue;
            }
            $out[] = [
                'tabelle' => (string) $r['TABLE_NAME'],
                'spalte' => (string) $r['COLUMN_NAME'],
                'ziel' => (string) $r['REFERENCED_TABLE_NAME'],
            ];
        }
        return $out;
    }

    /**
     * Die Fremdschlüssel als Karte Tabelle => Verweisziele - die Form, in der
     * Exportauswahl::gruppeFuer() und die Importregel sie brauchen.
     *
     * @param array<int, array{tabelle:string, spalte:string, ziel:string}> $fks
     * @return array<string, array<int, string>>
     */
    private static function fkKarte(array $fks): array {
        $karte = [];
        foreach ($fks as $fk) {
            $karte[$fk['tabelle']][] = $fk['ziel'];
        }
        foreach ($karte as $t => $ziele) {
            $karte[$t] = array_values(array_unique($ziele));
        }
        return $karte;
    }

    /**
     * Welche Spalten dürfen NULL sein? Entscheidet beim "Trennen", ob eine
     * abhängige Zeile ihren Verweis verliert (NULL) oder gelöscht wird.
     *
     * Wie die Fremdschlüssel wird das VOR dem Import gelesen und
     * eingefroren: Während der Dump läuft, verschwinden und entstehen
     * Tabellen, und information_schema zeigt dann einen Zwischenstand.
     *
     * @return array<string, array<string, bool>> Tabelle => Spalte => nullbar
     */
    private function spaltenNullbar(): array {
        $sql = 'SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE
                  FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()';
        $aus = [];
        foreach (Database::getInstance()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $aus[(string) $r['TABLE_NAME']][(string) $r['COLUMN_NAME']] = $r['IS_NULLABLE'] === 'YES';
        }
        return $aus;
    }

    /**
     * Größte Anweisung, die der Server annimmt - mindestens 1 MiB. Was
     * größer ist, lässt sich ohnehin nicht ausführen und wird deshalb schon
     * in der Vorschau gemeldet statt erst mitten im Import.
     */
    private static function paketGrenze(PDO $db): int {
        return max(DumpPruefer::MIN_ANWEISUNG, (int) $db->query('SELECT @@max_allowed_packet')->fetchColumn());
    }

    /** Obergrenze eines Sammel-INSERTs: ein Viertel der Paketgrenze, höchstens 1 MiB. */
    private static function sammelGrenze(int $paketGrenze): int {
        return min(1048576, intdiv($paketGrenze, 4));
    }

    /**
     * Die weichen Verweise (ohne Fremdschlüssel): die Konstante des Addons
     * plus die `weiche_verweise` aus der plugin.json jedes Addons unter
     * plugins/ - auch deaktivierter, denn deren Tabellen liegen genauso in
     * der Datenbank. Jede Angabe ist auf eigene plugin_-Tabellen des Addons
     * beschränkt (siehe Exportauswahl::weicheVerweiseAusManifest());
     * Verworfenes landet im Serverprotokoll.
     *
     * pferd-des-tages steht bewusst nicht darin: Seine Pferdeverweise sind
     * echte Fremdschlüssel, und die Pferde-Kennungen in seinen
     * Auswahlkriterien stecken in Konfigurationswerten, nicht in einer
     * Spalte - das lässt sich als Verweis nicht beschreiben.
     *
     * @return array<string, array<int, array{ziel:string, spalte:string, wo:array<string, int|string>, trennen:string}>>
     */
    private function weicheVerweise(): array {
        $listen = [Exportauswahl::WEICHE_VERWEISE];
        foreach (glob($this->rootDir() . '/plugins/*/plugin.json') ?: [] as $datei) {
            $roh = @file_get_contents($datei);
            $manifest = $roh !== false ? json_decode($roh, true) : null;
            $geprueft = Exportauswahl::weicheVerweiseAusManifest($manifest);
            foreach ($geprueft['verworfen'] as $grund) {
                error_log('Datenmigration: weiche_verweise verworfen - ' . $grund);
            }
            $listen[] = $geprueft['verweise'];
        }
        return Exportauswahl::verweiseZusammenfuehren(...$listen);
    }

    /**
     * Bezeichner-Quoting. Backtick als eigene Konstante - siehe
     * localInventory().
     */
    private static function bezeichner(string $name): string {
        $bt = '`';
        return $bt . str_replace($bt, $bt . $bt, $name) . $bt;
    }

    /**
     * Die WHERE-Bedingung "diese Zeile verweist" für eine Gruppe von
     * Verweisen derselben Kindtabelle - mit gebundenen Parametern.
     *
     * Fremdschlüssel: Spalte IS NOT NULL. Weiche Verweise zusätzlich <> 0
     * (0 heißt dort "kein Datensatz") und die `wo`-Bedingung, deren Werte
     * gebunden werden. Alle Bezeichner stammen aus information_schema, der
     * Addon-Konstante oder einer auf ^[a-z0-9_]+$ geprüften plugin.json und
     * werden zusätzlich gequotet.
     *
     * @param array<int, string> $spalten
     * @param array<string, int|string> $wo
     * @return array{0:string, 1:array<int, int|string>}
     */
    private static function verweisBedingung(array $spalten, array $wo, bool $weich): array {
        $oder = [];
        foreach ($spalten as $spalte) {
            $q = self::bezeichner($spalte);
            $oder[] = $weich ? '(' . $q . ' IS NOT NULL AND ' . $q . ' <> 0)' : $q . ' IS NOT NULL';
        }
        $bedingung = '(' . implode(' OR ', $oder) . ')';
        $parameter = [];
        foreach ($wo as $spalte => $wert) {
            $bedingung .= ' AND ' . self::bezeichner($spalte) . ' = ?';
            $parameter[] = $wert;
        }
        return [$bedingung, $parameter];
    }

    /**
     * Zählt Zeilen unter einer Bedingung aus verweisBedingung(). Eine nicht
     * zählbare Tabelle (fehlt, andere Spalten) zählt 0 - sie darf die
     * Warnung der anderen nicht verhindern.
     *
     * @param array<int, int|string> $parameter
     */
    private static function zaehle(PDO $db, string $tabelle, string $bedingung, array $parameter): int {
        try {
            $tabelle = self::bezeichner($tabelle);
            $stmt = $db->prepare('SELECT COUNT(*) FROM ' . $tabelle . ' WHERE ' . $bedingung);
            $stmt->execute($parameter);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Weiche Verweise einer Kindtabelle, gruppiert nach Ziel, Bedingung und
     * Trennart: match_labels verweist über ZWEI Spalten auf dieselben Pferde
     * - gezählt werden Zeilen, nicht Spalten.
     *
     * @param array<int, array{ziel:string, spalte:string, wo:array<string, int|string>, trennen:string}> $eintraege
     * @return array<int, array{ziel:string, spalten:array<int, string>, wo:array<string, int|string>, trennen:string}>
     */
    private static function weicheGruppen(array $eintraege): array {
        $gruppen = [];
        foreach ($eintraege as $e) {
            $schluessel = $e['ziel'] . '|' . json_encode($e['wo']) . '|' . $e['trennen'];
            $gruppen[$schluessel] ??= ['ziel' => $e['ziel'], 'spalten' => [], 'wo' => $e['wo'], 'trennen' => $e['trennen']];
            $gruppen[$schluessel]['spalten'][] = $e['spalte'];
        }
        return array_values($gruppen);
    }

    /**
     * Was verliert bei DIESER Auswahl sein Gegenstück? Mit Zahlen.
     *
     * "Pferde ohne Kontakte" ist eine völlig plausible Auswahl - und auf der
     * Zielinstanz ist das Ergebnis schlimmer als ein leerer Verweis: Die
     * Zeilen zeigen dort auf die Datensätze MIT DERSELBEN KENNUNG, also in
     * der Regel auf fremde Kontakte, Pferde oder Konten; nur wo die Kennung
     * fehlt, zeigen sie ins Leere. Eine Fehlermeldung gibt es dabei nicht.
     * Deshalb wird die Zahl VOR dem Erstellen genannt.
     *
     * Die frühere Sonderwarnung für Tabellen mit Ziel `users` gibt es nicht
     * mehr: Solche Tabellen gehören seit Audit M3 selbst zur Gruppe
     * "Benutzer" und können ohne users gar nicht mehr ins Archiv.
     *
     * @param array<int, string> $tabellen Positivliste des Exports
     * @return array<int, string>
     */
    private function abhaengigkeitsWarnungen(array $tabellen): array {
        $imArchiv = array_flip($tabellen);
        $db = Database::getInstance();

        /** @var array<string, array<string, int>> $offen  ziel => [tabelle => zeilen] */
        $offen = [];
        foreach ($this->fremdschluessel() as $fk) {
            if (!isset($imArchiv[$fk['tabelle']]) || isset($imArchiv[$fk['ziel']])) {
                continue;
            }
            [$bedingung, $parameter] = self::verweisBedingung([$fk['spalte']], [], false);
            $n = self::zaehle($db, $fk['tabelle'], $bedingung, $parameter);
            if ($n > 0) {
                $offen[$fk['ziel']][$fk['tabelle']] = ($offen[$fk['ziel']][$fk['tabelle']] ?? 0) + $n;
            }
        }

        foreach ($this->weicheVerweise() as $tabelle => $eintraege) {
            if (!isset($imArchiv[$tabelle])) {
                continue;
            }
            foreach (self::weicheGruppen($eintraege) as $g) {
                if (isset($imArchiv[$g['ziel']])) {
                    continue;
                }
                [$bedingung, $parameter] = self::verweisBedingung($g['spalten'], $g['wo'], true);
                $n = self::zaehle($db, $tabelle, $bedingung, $parameter);
                if ($n > 0) {
                    $offen[$g['ziel']][$tabelle] = ($offen[$g['ziel']][$tabelle] ?? 0) + $n;
                }
            }
        }

        $warnungen = [];
        ksort($offen);
        foreach ($offen as $ziel => $quellen) {
            ksort($quellen);
            $teile = [];
            foreach ($quellen as $tabelle => $n) {
                $teile[] = number_format($n, 0, ',', '.') . ' Zeile(n) in ' . $tabelle;
            }
            $warnungen[] = implode(', ', $teile) . ' verweisen auf ' . $ziel
                . ' - diese Tabelle ist nicht im Archiv. Auf der Zielinstanz zeigen die Verweise auf deren '
                . 'Datensätze mit derselben Kennung, also in der Regel auf fremde Pferde, Kontakte oder Konten; '
                . 'nur wo die Kennung fehlt, zeigen sie ins Leere '
                . '(Gruppe „' . Exportauswahl::label(Exportauswahl::gruppeFuer($ziel)) . '").';
        }
        return $warnungen;
    }

    // -- Übersicht ----------------------------------------------------------

    /**
     * Zwischendateien, die ein abgebrochener Lauf liegen gelassen hat
     * (Export-Dump, geprüfter Import-Dump). Sie enthalten Datenbankinhalt,
     * im Fall des Imports womöglich Zugangsmaterial - normalerweise räumen
     * finally und eine Shutdown-Funktion sie weg, ein harter Abbruch
     * (getöteter Worker) kommt aber an beidem vorbei. Älter als eine Stunde
     * heißt: Zu diesem Lauf gehört niemand mehr.
     */
    private function raeumeZwischendateienAuf(): void {
        $dateien = array_merge(
            glob($this->stageDir() . '/.import-*.sql') ?: [],
            glob($this->stageDir() . '/.dump-*.sql') ?: []
        );
        foreach ($dateien as $datei) {
            $alter = @filemtime($datei);
            if ($alter !== false && $alter < time() - 3600) {
                @unlink($datei);
            }
        }
    }

    public function overview(): void {
        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $canExport = $this->hasPermission('datenmigration', 'export');
        $canImport = $this->hasPermission('datenmigration', 'import');

        $content = '<div class="card"><h1>📦 Datenmigration (Instanz-Umzug)</h1>';
        $content .= '<p>Zieht eine Instanz um: Datenbank und Uploads, gebündelt in einem Archiv. '
            . 'Was mitgeht, wird beim Export ausgewählt - Benutzerkonten und Zugangsdaten bleiben dabei '
            . 'per Vorgabe zurück.</p>';

        $this->raeumeZwischendateienAuf();

        $notice = $_GET['hinweis'] ?? '';
        if ($notice === 'hochgeladen') {
            $content .= '<p class="alert alert-success">Archiv hochgeladen - unten prüfen und anwenden.</p>';
        }
        if ($notice === 'importiert') {
            $content .= '<p class="alert alert-success">Archiv eingespielt. Ersetzt wurden nur die Tabellen, die '
                . 'die Vorschau nach Prüfung des Dumps mit „wird ersetzt“ geführt hat; alle übrigen blieben '
                . 'unverändert. Benutzerkonten waren nicht darunter - Ihre Sitzung gilt deshalb weiter.</p>';
            $unlesbar = (int) ($_GET['unlesbar'] ?? 0);
            if ($unlesbar > 0) {
                $content .= '<p class="alert alert-warning">⚠ ' . $unlesbar . ' eingespielte(r) verschlüsselte(r) '
                    . 'Wert(e) lassen sich mit dem APP_KEY dieser Instanz nicht entschlüsseln (Zugangsdaten wie SMTP, '
                    . 'Backup-Ziele oder Addon-Secrets). Bitte neu eintragen oder den APP_KEY der Quelle übernehmen; '
                    . 'Einzelheiten im Protokoll.</p>';
            }
            if (($_GET['schutz'] ?? '') === 'fehlt') {
                $content .= '<p class="alert alert-error">Mindestens eine Schutzdatei unter public/uploads '
                    . '(.htaccess) ließ sich nicht wiederherstellen - Einzelheiten im Protokoll. Bitte von Hand '
                    . 'aus dem Kern zurückkopieren.</p>';
            }
        }

        if ($canExport) {
            $content .= '<h2>Export</h2><p><a class="btn" href="/plugin/datenmigration/export">Export-Archiv zusammenstellen</a></p>'
                . '<p><small>Das Archiv wird zusätzlich in <code>var/datenmigration/</code> abgelegt.</small></p>';
        }

        if ($canImport) {
            $content .= '<h2>Import</h2>';
            $content .= '<form method="POST" action="/plugin/datenmigration/import/hochladen" enctype="multipart/form-data">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<div class="form-group"><label for="archiv">Archiv hochladen (.tar / .tar.gz)</label>'
                . '<input type="file" name="archiv" id="archiv" class="form-control" accept=".tar,.gz" required></div>'
                . '<button type="submit" class="btn">Hochladen</button></form>';
            $content .= '<p><small>Große Archive (über der PHP-Upload-Grenze) direkt nach '
                . '<code>var/datenmigration/</code> legen - sie erscheinen dann in dieser Liste.</small></p>';

            $archives = $this->stagedArchives();
            if ($archives) {
                $content .= '<h3>Bereitliegende Archive</h3><ul>';
                foreach ($archives as $a) {
                    $safe = htmlspecialchars($a, ENT_QUOTES, 'UTF-8');
                    $size = filesize($this->stageDir() . '/' . $a);
                    $content .= '<li><code>' . $safe . '</code> (' . number_format($size / 1048576, 1, ',', '.') . ' MB) '
                        . '- <a href="/plugin/datenmigration/import/pruefen?datei=' . urlencode($a) . '">Prüfen &amp; anwenden</a></li>';
                }
                $content .= '</ul>';
            } else {
                $content .= '<p>Keine Archive in <code>var/datenmigration/</code>.</p>';
            }
        }

        if (!$canExport && !$canImport) {
            $content .= '<p class="alert alert-error">Keine Berechtigung für dieses Modul.</p>';
        }
        $content .= '</div>';
        PluginPage::render('Datenmigration', $content);
    }

    // -- Export -------------------------------------------------------------

    /**
     * Das Auswahlformular (#121): welche Gruppen kommen ins Archiv?
     *
     * Jede Gruppe nennt ihre Tabellen und deren Zeilenzahl. Das ist kein
     * Beiwerk: Die Gruppennamen sind eine Abstraktion, und eine Abstraktion,
     * die verbirgt, was sie zusammenfasst, verwandelt eine bewusste
     * Entscheidung in einen Vertrauensvorschuss.
     */
    public function exportForm(): void {
        $this->requirePermission('datenmigration', 'export');
        $this->requireAdminForFullAccess('export');

        $inventory = $this->localInventory();
        $vorhandene = array_keys($inventory['tables']);
        $fkKarte = self::fkKarte($this->fremdschluessel());
        $auswahl = array_key_exists('gruppen', $_GET)
            ? Exportauswahl::bereinige((array) $_GET['gruppen'])
            : Exportauswahl::vorgabe();
        $fehler = (string) ($_GET['fehler'] ?? '');

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $csrf = $e(Router::generateCsrfToken());

        $content = '<div class="card"><h1>📦 Export-Archiv zusammenstellen</h1>';
        if ($fehler === 'leer') {
            $content .= '<p class="alert alert-error">Es war nichts ausgewählt - ein leeres Archiv hilft niemandem. '
                . 'Bitte mindestens eine Gruppe anhaken.</p>';
        }
        if ($fehler === 'passwort') {
            $content .= '<p class="alert alert-error">Das Exportpasswort ist zu kurz (mindestens '
                . Geheimnisumschlag::MIN_PASSWORT . ' Zeichen) oder die Wiederholung stimmt nicht überein. '
                . 'Es wurde kein Archiv erstellt.</p>';
        }
        $content .= '<p>Angehakt ist, was in das Archiv kommt. Die Voreinstellung lässt <strong>Benutzer, Gruppen, '
            . 'Rechte</strong> weg: Das ist die einzige Gruppe, deren Inhalt dem Empfänger Zugang zu Ihrer Instanz '
            . 'verschafft (Passwort-Hashes, TOTP-Geheimnisse, Backup-Codes, API-Schlüssel). Alles Übrige sind Daten, '
            . 'die bei einem Umzug mitsollen.</p>';

        $content .= '<form method="POST" action="/plugin/datenmigration/export">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">';

        foreach (Exportauswahl::GRUPPEN as $key => $meta) {
            $tabellen = $key === Exportauswahl::GRUPPE_DATEIEN
                ? []
                : Exportauswahl::tabellen([$key], $vorhandene, $fkKarte);
            // Eine leere Auffanggruppe ist die Regel und kein Thema - sie
            // erscheint nur, wenn sie tatsächlich etwas enthält.
            if ($key === Exportauswahl::GRUPPE_SONSTIGES && $tabellen === []) {
                continue;
            }

            if ($key === Exportauswahl::GRUPPE_DATEIEN) {
                $umfang = number_format(count($this->collectUploads()), 0, ',', '.') . ' Datei(en) in public/uploads, '
                    . number_format(count($this->collectHorseImages()), 0, ',', '.') . ' Pferdefoto(s)';
            } else {
                $zeilen = 0;
                foreach ($tabellen as $t) {
                    $zeilen += (int) ($inventory['tables'][$t] ?? 0);
                }
                $umfang = number_format($zeilen, 0, ',', '.') . ' Zeile(n) in '
                    . count($tabellen) . ' Tabelle(n)';
            }

            $checked = in_array($key, $auswahl, true) ? ' checked' : '';
            $content .= '<div class="form-group"><label>'
                . '<input type="checkbox" name="gruppen[]" value="' . $e($key) . '"' . $checked . '> '
                . '<strong>' . $e($meta['label']) . '</strong> (' . $e($umfang) . ')</label>'
                . '<p><small>' . $e($meta['text']) . '</small></p>';
            if ($meta['hinweis'] !== null) {
                $content .= '<p class="alert alert-warning">⚠ ' . $e($meta['hinweis']) . '</p>';
            }
            if ($tabellen !== []) {
                // Erst jede Tabelle einzeln maskieren, dann die Auszeichnung
                // dazwischensetzen - andersherum landete das Markup im
                // maskierten Text und stünde wörtlich auf der Seite.
                $content .= '<p><small><code>'
                    . implode('</code>, <code>', array_map($e, $tabellen))
                    . '</code></small></p>';
            }
            $content .= '</div>';
        }

        $content .= $this->passwortAbschnitt(false);
        $content .= '<button type="submit" class="btn">Archiv erstellen und herunterladen</button></form>';
        $content .= '<p><a href="/plugin/datenmigration/uebersicht">Zurück</a></p></div>';
        PluginPage::render('Datenmigration - Export', $content);
    }

    /**
     * Der optionale Abschnitt "Verschlüsselte Zugangsdaten mitnehmen"
     * (Audit M25). Die Felder werden immer leer ausgeliefert - das Passwort
     * geht nie als Wert oder verstecktes Feld zurück an den Browser.
     */
    private function passwortAbschnitt(bool $erneut): string {
        $min = Geheimnisumschlag::MIN_PASSWORT;
        $pflicht = $erneut ? ' required' : '';
        return '<fieldset class="form-group"><legend><strong>Verschlüsselte Zugangsdaten mitnehmen</strong> '
            . '(optional)</legend>'
            . '<p><small>SMTP- und Backup-Zugangsdaten, die Secrets von Addons und die TOTP-Geheimnisse der Konten '
            . 'sind mit dem APP_KEY dieser Instanz verschlüsselt. Der APP_KEY wandert nicht mit - auf einer '
            . 'Zielinstanz mit eigenem Schlüssel sind diese Werte ohne Exportpasswort unbrauchbar. Mit einem '
            . 'Exportpasswort werden sie zusätzlich unter diesem Passwort verschlüsselt ins Archiv gelegt und beim '
            . 'Import mit dem Schlüssel des Ziels neu verschlüsselt. Wer Archiv und Passwort hat, hat diese '
            . 'Zugangsdaten - das Passwort also getrennt vom Archiv weitergeben. Mindestens ' . $min
            . ' Zeichen; leer lassen, wenn nichts davon mitsoll.</small></p>'
            . ($erneut ? '<p class="alert alert-warning">⚠ Aus Sicherheitsgründen wird das Exportpasswort nicht '
                . 'zwischengespeichert - bitte hier erneut eingeben.</p>'
                . '<input type="hidden" name="mit_passwort" value="1">' : '')
            . '<div class="form-group"><label for="export_passwort">Exportpasswort</label>'
            . '<input type="password" name="export_passwort" id="export_passwort" class="form-control" '
            . 'autocomplete="new-password" minlength="' . $min . '"' . $pflicht . '></div>'
            . '<div class="form-group"><label for="export_passwort_wdh">Exportpasswort wiederholen</label>'
            . '<input type="password" name="export_passwort_wdh" id="export_passwort_wdh" class="form-control" '
            . 'autocomplete="new-password" minlength="' . $min . '"' . $pflicht . '></div>'
            . '</fieldset>';
    }

    public function export(): void {
        $this->requirePermission('datenmigration', 'export');
        $this->requireAdminForFullAccess('export');
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
            return;
        }

        $auswahl = Exportauswahl::bereinige($_POST['gruppen'] ?? []);
        if ($auswahl === []) {
            header('Location: /plugin/datenmigration/export?fehler=leer');
            exit;
        }

        // Exportpasswort zuerst (Audit M25): Ein Tippfehler in der
        // Wiederholung darf nicht erst nach der Warnseite auffallen. Das
        // Passwort selbst geht nie in eine URL, ein Formularfeld oder ein
        // Protokoll.
        $passwort = (string) ($_POST['export_passwort'] ?? '');
        if ($passwort !== (string) ($_POST['export_passwort_wdh'] ?? '')
            || ($passwort !== '' && mb_strlen($passwort, 'UTF-8') < Geheimnisumschlag::MIN_PASSWORT)
            || ($passwort === '' && ($_POST['mit_passwort'] ?? '') === '1')) {
            header('Location: /plugin/datenmigration/export?fehler=passwort&' . http_build_query(['gruppen' => $auswahl]));
            exit;
        }

        $inventory = $this->localInventory();
        // Mit den Fremdschlüsseln: Addon-Tabellen mit Verweis auf users
        // gehören zu "Benutzer" und gehen ohne diese Gruppe nicht mit (M3).
        $tabellen = Exportauswahl::tabellen(
            $auswahl,
            array_keys($inventory['tables']),
            self::fkKarte($this->fremdschluessel())
        );
        $vollstaendig = Exportauswahl::istVollstaendig($auswahl);

        // Fremdschlüssel: erst warnen, dann erstellen. Ohne den
        // Zwischenschritt liefe der Betreiber ins Messer - der Import auf der
        // Gegenseite meldet nichts, er spielt die verwaisten Verweise
        // klaglos ein (siehe abhaengigkeitsWarnungen()).
        $warnungen = $this->abhaengigkeitsWarnungen($tabellen);
        $mitDateien = in_array(Exportauswahl::GRUPPE_DATEIEN, $auswahl, true);
        $fotos = $mitDateien || in_array('pferde', $auswahl, true) ? $this->collectHorseImages() : [];
        if (!$mitDateien && in_array('pferde', $auswahl, true) && $fotos !== []) {
            // Die Pferdefotos hängen an der Gruppe "Dateien" (Audit M26):
            // Pferde ohne Dateien ist ein Archiv mit Pferden ohne Fotos.
            $warnungen[] = number_format(count($fotos), 0, ',', '.') . ' Pferdefoto(s) aus storage/horses gehen nur '
                . 'mit der Gruppe „' . Exportauswahl::label(Exportauswahl::GRUPPE_DATEIEN) . '“ mit. Ohne sie '
                . 'kommen die Pferde auf der Zielinstanz ohne ihre Fotos an.';
        }
        if ($warnungen !== [] && ($_POST['trotzdem'] ?? '') !== '1') {
            $this->renderExportWarnung($auswahl, $warnungen, $passwort !== '');
            return;
        }

        $uploadFiles = $mitDateien ? $this->collectUploads() : [];
        $horseFiles = $mitDateien ? $fotos : [];

        // Was mit dem APP_KEY verschlüsselt im Archiv liegt - im Manifest nur
        // Namen und Zahlen, die Werte selbst nur mit Exportpasswort.
        $geheim = $this->sammleVerschluesselteWerte($tabellen);
        $geheimnisse = $passwort !== '' && ($geheim['settings'] !== [] || $geheim['users_totp'] !== [])
            ? Geheimnisumschlag::verpacken($geheim, $passwort)
            : null;
        unset($passwort);

        $tabellenZaehler = [];
        foreach ($tabellen as $t) {
            $tabellenZaehler[$t] = (int) ($inventory['tables'][$t] ?? 0);
        }

        $manifest = [
            'format' => self::FORMAT,
            'created_at' => gmdate('c'),
            'core_version' => $inventory['core_version'],
            'site_name' => $inventory['site_name'],
            'plugins' => $inventory['plugins'],
            // `tables` zählt seit #121 nur noch, was TATSÄCHLICH im Archiv
            // liegt. Genau daran erkennt die Vorschau der Gegenseite, dass
            // eine fehlende Tabelle Absicht ist und kein Mangel.
            'tables' => $tabellenZaehler,
            'auswahl' => $auswahl,
            'vollstaendig' => $vollstaendig,
            'uploads_count' => count($uploadFiles),
            'horses_count' => count($horseFiles),
            // Audit M25: Woran die Zielinstanz erkennt, ob ihr APP_KEY der
            // der Quelle ist - ein HMAC, aus dem sich der Schlüssel nicht
            // zurückgewinnen lässt.
            'app_key_fingerabdruck' => self::appKeyFingerabdruck(),
            'verschluesselt' => [
                'settings' => array_keys($geheim['settings']),
                'users_totp' => count($geheim['users_totp']),
            ],
            'geheimnisse' => $geheimnisse !== null,
        ];

        $ext = function_exists('gzopen') ? '.tar.gz' : '.tar';
        $filename = ($vollstaendig ? 'datenmigration-' : 'datenmigration-teil-') . gmdate('Ymd-His') . $ext;
        $path = $this->stageDir() . '/' . $filename;

        // Der Dump geht über eine Zwischendatei statt über einen String im
        // Speicher: DatabaseDumper::dumpTo() ist streamend (Framework#231),
        // aber der tar-Header verlangt die Größe VOR dem Inhalt. Eine Datei
        // beantwortet beides - konstanter Speicherbedarf, bekannte Größe.
        $dumpDatei = $this->stageDir() . '/.dump-' . bin2hex(random_bytes(8)) . '.sql';
        try {
            $fh = fopen($dumpDatei, 'wb');
            if ($fh === false) {
                throw new \RuntimeException("Zwischendatei nicht schreibbar: {$dumpDatei}");
            }
            try {
                // null heißt "alles" und schreibt KEINEN Auswahl-Hinweis in
                // den Dump. Bei einem Vollarchiv ist das die richtige Aussage;
                // eine Liste, die zufällig alle Tabellen enthält, würde den
                // Dump fälschlich als Teilsicherung kennzeichnen.
                DatabaseDumper::dumpTo(
                    function (string $chunk) use ($fh): void {
                        // Geschriebene Menge prüfen, nicht nur false (M43).
                        if (fwrite($fh, $chunk) !== strlen($chunk)) {
                            throw new \RuntimeException('Dump konnte nicht geschrieben werden (Datenträger voll?).');
                        }
                    },
                    $vollstaendig ? null : $tabellen
                );
            } finally {
                $geschlossen = fclose($fh);
            }
            if (!$geschlossen) {
                throw new \RuntimeException('Dump konnte nicht abgeschlossen werden.');
            }

            $tar = TarWriter::create($path);
            $tar->addString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $tar->addFile('database.sql', $dumpDatei);
            if ($geheimnisse !== null) {
                $tar->addString('geheimnisse.json', $geheimnisse);
            }
            foreach ($uploadFiles as $rel => $abs) {
                $tar->addFile('uploads/' . $rel, $abs);
            }
            foreach ($horseFiles as $rel => $abs) {
                $tar->addFile(self::PRAEFIX_FOTOS . $rel, $abs);
            }
            $tar->close();
        } finally {
            @unlink($dumpDatei);
        }

        PluginAudit::log(
            'datenmigration',
            'Export-Archiv erstellt',
            $filename,
            ($vollstaendig ? 'Vollarchiv' : 'Teilarchiv') . ': ' . implode(', ', $auswahl)
                . ' - ' . count($tabellen) . ' Tabelle(n), ' . count($uploadFiles) . ' Upload-Datei(en), '
                . count($horseFiles) . ' Pferdefoto(s)'
                . ($geheimnisse !== null
                    ? ', Zugangsdaten mit Exportpasswort mitgenommen (' . count($geheim['settings'])
                        . ' Einstellung(en), ' . count($geheim['users_totp']) . ' TOTP-Geheimnis(se))'
                    : '')
        );

        header('Content-Type: ' . ($ext === '.tar.gz' ? 'application/gzip' : 'application/x-tar'));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        $out = fopen($path, 'rb');
        while (!feof($out)) {
            echo fread($out, 1024 * 512);
        }
        fclose($out);
        exit;
    }

    /**
     * Zwischenseite mit den Zahlen: "142 Zeile(n) in horse_persons verweisen
     * auf contacts". Erst danach lässt sich das Archiv erstellen.
     *
     * @param array<int, string> $auswahl
     * @param array<int, string> $warnungen
     */
    private function renderExportWarnung(array $auswahl, array $warnungen, bool $mitPasswort): void {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $csrf = $e(Router::generateCsrfToken());

        $content = '<div class="card"><h1>📦 Export: fehlende Gegenstücke</h1>';
        $content .= '<p>Die Auswahl enthält Tabellen, deren Verweise auf nicht ausgewählte Tabellen zeigen. '
            . 'Auf der Zielinstanz zeigen diese Verweise auf deren Datensätze mit derselben Kennung, also in der '
            . 'Regel auf fremde Pferde, Kontakte oder Konten: eine Zuordnung zum falschen Kontakt, ein '
            . 'Addon-Datensatz am falschen Pferd. Nur wo die Kennung fehlt, zeigen sie ins Leere. Eine '
            . 'Fehlermeldung gibt es dabei nicht; die Vorschau des Imports weist aber darauf hin.</p>';
        foreach ($warnungen as $w) {
            $content .= '<p class="alert alert-warning">⚠ ' . $e($w) . '</p>';
        }
        $content .= '<p>Entweder die fehlende Gruppe mit anhaken - oder das Archiv bewusst so erstellen.</p>';

        $content .= '<form method="POST" action="/plugin/datenmigration/export">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="trotzdem" value="1">';
        foreach ($auswahl as $key) {
            $content .= '<input type="hidden" name="gruppen[]" value="' . $e($key) . '">';
        }
        // Das Passwort wird NICHT als verstecktes Feld weitergereicht
        // (Audit M25) - es stünde sonst im Quelltext dieser Seite.
        $content .= $this->passwortAbschnitt($mitPasswort);
        $content .= '<button type="submit" class="btn">Archiv trotzdem so erstellen</button></form>';

        // http_build_query trennt mit "&"; in einem HTML-Attribut gehört
        // "&amp;" hin, sonst deutet der Parser "&gruppen" als Entität.
        $content .= '<p><a href="/plugin/datenmigration/export?'
            . $e(http_build_query(['gruppen' => $auswahl])) . '">Auswahl ändern</a></p></div>';
        PluginPage::render('Datenmigration - Export', $content);
    }

    /** @return array<string, string> relativer Pfad => absoluter Pfad */
    private function collectUploads(): array {
        return $this->collectFiles($this->uploadsDir());
    }

    /**
     * Die Pferdefotos aus storage/horses (Audit M26) - OHNE Punktdateien.
     *
     * Der Kern liefert `storage/horses/.gitkeep` aus. Im Archiv lehnte der
     * Import sie als Punktdatei ab (UploadNamePolicy::assertAllowed()), und
     * jeder Vollimport mit Dateien bräche daran ab.
     *
     * @return array<string, string>
     */
    private function collectHorseImages(): array {
        return $this->collectFiles($this->horsesDir(), true);
    }

    /**
     * Alle regulären Dateien unter $base (keine Symlinks), sortiert.
     *
     * @return array<string, string> relativer Pfad => absoluter Pfad
     */
    private function collectFiles(string $base, bool $ohnePunktdateien = false): array {
        if (!is_dir($base)) {
            return [];
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            if ($ohnePunktdateien && str_starts_with($file->getFilename(), '.')) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($base) + 1);
            $files[$rel] = $file->getPathname();
        }
        ksort($files);
        return $files;
    }

    // -- Zugangsdaten und APP_KEY (Audit M25) --------------------------------

    /**
     * Fingerabdruck des APP_KEY: ein HMAC über einen festen Text, aus dem sich
     * der Schlüssel nicht zurückgewinnen lässt. null ohne APP_KEY.
     */
    public static function appKeyFingerabdruck(): ?string {
        return defined('APP_KEY') && (string) \constant('APP_KEY') !== ''
            ? hash_hmac('sha256', 'datenmigration:app-key-fingerabdruck:v1', (string) \constant('APP_KEY'))
            : null;
    }

    /**
     * Die mit dem APP_KEY verschlüsselten Werte der exportierten Tabellen, im
     * Klartext.
     *
     * settings: jeder Wert, den Crypto::decrypt() öffnet - die Erkennung ist
     * generisch und erfasst damit auch die Secrets von Addons, die dieses
     * Addon nicht kennt; das GCM-Tag schließt Fehltreffer aus.
     * users.totp_secret: nur entschlüsselbare Werte. Klartext-Altwerte aus
     * der Zeit vor der Verschlüsselung funktionieren ohnehin mit jedem
     * Schlüssel.
     *
     * Ohne APP_KEY wirft Crypto - dann wird nichts gesammelt.
     *
     * @param array<int, string> $tabellen
     * @return array{settings: array<string, string>, users_totp: array<int, string>}
     */
    private function sammleVerschluesselteWerte(array $tabellen): array {
        $aus = ['settings' => [], 'users_totp' => []];
        $db = Database::getInstance();
        try {
            if (in_array('settings', $tabellen, true)) {
                foreach ($db->query('SELECT setting_key, setting_value FROM settings ORDER BY setting_key')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $wert = (string) ($r['setting_value'] ?? '');
                    $klar = $wert !== '' ? Crypto::decrypt($wert) : null;
                    if ($klar !== null) {
                        $aus['settings'][(string) $r['setting_key']] = $klar;
                    }
                }
            }
            if (in_array('users', $tabellen, true)) {
                $sql = "SELECT id, totp_secret FROM users WHERE totp_secret IS NOT NULL AND totp_secret <> '' ORDER BY id";
                foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $klar = Crypto::decrypt((string) $r['totp_secret']);
                    if ($klar !== null) {
                        $aus['users_totp'][(int) $r['id']] = $klar;
                    }
                }
            }
        } catch (\Throwable $e) {
            return ['settings' => [], 'users_totp' => []];
        }
        return $aus;
    }

    /**
     * Passt der APP_KEY dieser Instanz zu dem der Quelle?
     *
     *   gleich     - Fingerabdrücke stimmen überein, nichts zu tun;
     *   unbekannt  - das Archiv hat keinen Fingerabdruck (Format 1/2) oder
     *                diese Instanz keinen APP_KEY: nur ein Hinweis;
     *   abweichend - verschlüsselte Werte der Quelle sind hier unlesbar.
     */
    private static function schluesselLage(array $manifest): string {
        $quelle = $manifest['app_key_fingerabdruck'] ?? null;
        $ziel = self::appKeyFingerabdruck();
        if (!is_string($quelle) || preg_match('/^[0-9a-f]{64}$/D', $quelle) !== 1 || $ziel === null) {
            return 'unbekannt';
        }
        return hash_equals($ziel, $quelle) ? 'gleich' : 'abweichend';
    }

    /**
     * Was ein abweichender APP_KEY bei DIESEM Archiv betrifft - für Vorschau
     * und Anwenden aus derselben Rechnung. Maßgeblich ist, welche Tabellen der
     * geprüfte Dump tatsächlich ersetzt; die Namen und Zahlen stammen aus dem
     * Manifest und werden nur angezeigt bzw. beim Leeren gegen den
     * tatsächlichen Wert geprüft (siehe schluesselAngleichen()).
     *
     * @return array{lage:string, settings:array<int, string>, totp:int, passkeys:int, geheimnisse:bool, betroffen:bool}
     */
    private static function schluesselBedarf(array $manifest, DumpBefund $befund): array {
        $ersetzt = array_flip($befund->ersetzt());
        $v = is_array($manifest['verschluesselt'] ?? null) ? $manifest['verschluesselt'] : [];
        $settings = [];
        if (isset($ersetzt['settings']) && is_array($v['settings'] ?? null)) {
            foreach ($v['settings'] as $name) {
                if (is_string($name) && $name !== '' && strlen($name) <= 50) {
                    $settings[] = $name;
                }
            }
            $settings = array_values(array_unique($settings));
        }
        $totp = isset($ersetzt['users']) ? max(0, (int) ($v['users_totp'] ?? 0)) : 0;
        $passkeys = isset($ersetzt['user_passkeys']) ? (int) ($befund->tabellen['user_passkeys']['zeilen'] ?? 0) : 0;
        return [
            'lage' => self::schluesselLage($manifest),
            'settings' => $settings,
            'totp' => $totp,
            'passkeys' => $passkeys,
            'geheimnisse' => ($manifest['geheimnisse'] ?? false) === true,
            'betroffen' => $settings !== [] || $totp > 0 || $passkeys > 0,
        ];
    }

    // -- Import: Hochladen --------------------------------------------------

    public function upload(): void {
        $this->requirePermission('datenmigration', 'import');
        $this->requireAdminForFullAccess('import');
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
            return;
        }
        $file = $_FILES['archiv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail('Kein Archiv hochgeladen (oder Upload-Grenze überschritten - große Archive per SFTP nach var/datenmigration/ legen).');
            return;
        }
        $name = basename((string) $file['name']);
        if (!preg_match('/^[A-Za-z0-9._ -]+\.tar(\.gz)?$/', $name)) {
            $this->fail('Nur .tar/.tar.gz-Archive mit einfachem Dateinamen.');
            return;
        }
        $target = $this->stageDir() . '/' . str_replace(' ', '_', $name);
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $this->fail('Archiv konnte nicht gespeichert werden.');
            return;
        }
        header('Location: /plugin/datenmigration/uebersicht?hinweis=hochgeladen');
        exit;
    }

    // -- Import: Prüfen (Vorschau) -----------------------------------------

    public function preview(): void {
        $this->requirePermission('datenmigration', 'import');
        $this->requireAdminForFullAccess('import');
        $path = $this->stagedPath((string) ($_GET['datei'] ?? ''));
        if ($path === null) {
            $this->fail('Archiv nicht gefunden.');
            return;
        }
        try {
            $manifest = $this->readManifest($path);
        } catch (\Throwable $e) {
            $this->fail('Archiv unlesbar: ' . $e->getMessage());
            return;
        }

        $local = $this->localInventory();
        $problems = $this->compatibilityProblems($manifest, $local);
        $warnings = $this->pluginWarnings($manifest, $local);
        $auswahl = $this->auswahlDesArchivs($manifest);
        $vollstaendig = Exportauswahl::istVollstaendig($auswahl);
        $fks = $this->fremdschluessel();
        $fkKarte = self::fkKarte($fks);

        // Der Dump wird trocken durch dieselbe Prüfung geschickt wie beim
        // Anwenden. Was die Vorschau ab hier über Tabellen sagt, stammt aus
        // database.sql, nicht aus dem Manifest (Audit M1).
        $pruefung = $this->pruefeArchiv($path, $manifest, $auswahl, $fkKarte, self::paketGrenze(Database::getInstance()));
        $befund = $pruefung['befund'];
        if ($pruefung['problem'] !== null) {
            $problems[] = $pruefung['problem'];
        }
        if ($pruefung['hinweis'] !== null) {
            $warnings[] = $pruefung['hinweis'];
        }
        $risiken = $pruefung['problem'] === null
            ? $this->importRisiken($befund, $fks, $local['tables'], $this->spaltenNullbar())
            : ['trennbar' => [], 'hinweise' => []];
        $benutzerErsetzt = $pruefung['problem'] === null && self::benutzerbezogenErsetzt($befund, $fkKarte);
        $bedarf = self::schluesselBedarf($manifest, $befund);
        $format = (int) ($manifest['format'] ?? 0);
        if ($format > 0 && $format < 3 && in_array(Exportauswahl::GRUPPE_DATEIEN, $auswahl, true)) {
            $warnings[] = 'Archiv im älteren Format ' . $format . ': Es enthält keine Pferdefotos aus storage/horses '
                . '(die liegen dort seit Kern 0.8). Die Pferdefotos dieser Instanz bleiben unverändert; fehlende '
                . 'Fotos der Quelle sind von Hand nach storage/horses zu kopieren oder mit einem neuen Export zu holen.';
        }

        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars(basename($path), ENT_QUOTES, 'UTF-8');
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $content = '<div class="card"><h1>📦 Import prüfen: <code>' . $file . '</code></h1>';

        // Das Wichtigste zuerst und in eigenen Worten: Ein Teilarchiv tut
        // etwas grundsätzlich anderes als ein Vollarchiv. Wer das erst aus
        // der Tabellenliste weiter unten erschließen muss, erschließt es
        // nicht.
        if ($vollstaendig) {
            $content .= '<p class="alert alert-warning">⚠ <strong>Vollarchiv.</strong> Jede Tabelle, die das '
                . 'Archiv mitbringt, wird ersetzt - einschließlich der Benutzerkonten. Tabellen, die nur diese '
                . 'Instanz hat (etwa die eines Addons, das auf der Quelle fehlt), bleiben stehen.</p>';
        } else {
            $content .= '<p class="alert alert-warning">⚠ <strong>Teilarchiv.</strong> Es wird '
                . '<em>zusammengeführt</em>, nicht ersetzt: Nur die unten mit „wird ersetzt“ geführten Tabellen '
                . 'werden durch den Stand des Archivs überschrieben, alle übrigen bleiben unverändert stehen. '
                . 'Enthaltene Gruppen laut Manifest: '
                . $e(implode(', ', array_map([Exportauswahl::class, 'label'], $auswahl))) . '.</p>';
        }
        if ($benutzerErsetzt) {
            // Audit M2: Nicht nur die eigene Sitzung endet.
            $content .= '<p class="alert alert-warning">⚠ <strong>Benutzerkonten werden ersetzt.</strong> Alle '
                . 'angemeldeten Sitzungen und API-Schlüssel dieser Instanz werden ungültig - auch Ihre eigene '
                . 'Sitzung. Danach meldet sich jeder mit den Konten des Archivs neu an; API-Schlüssel sind neu '
                . 'auszustellen.</p>';
        }

        $content .= '<div class="tabelle-scroll"><table class="table"><tr><th></th><th>Archiv (Quelle)</th><th>Diese Instanz (Ziel)</th></tr>'
            . '<tr><td>Seite</td><td>' . $e($manifest['site_name'] ?? '?') . '</td><td>' . $e($local['site_name']) . '</td></tr>'
            . '<tr><td>Kern-Version</td><td>' . $e($manifest['core_version'] ?? '?') . '</td><td>' . $e($local['core_version']) . '</td></tr>'
            . '<tr><td>Erstellt</td><td>' . $e($manifest['created_at'] ?? '?') . '</td><td>-</td></tr>'
            . '<tr><td>Umfang</td><td>' . ($vollstaendig ? 'Vollarchiv' : 'Teilarchiv') . '</td><td>-</td></tr>'
            . '<tr><td>Upload-Dateien</td><td>' . $e($manifest['uploads_count'] ?? '?') . '</td><td>-</td></tr>'
            . '<tr><td>Pferdefotos (storage/horses)</td><td>' . $e($manifest['horses_count'] ?? 'nicht enthalten')
            . '</td><td>' . $e(count($this->collectHorseImages())) . '</td></tr></table></div>';

        $content .= $this->schluesselHinweis($bedarf, $e);

        // "Quelle" sind die im Dump gezählten Zeilen, nicht die Angabe des
        // Manifests - die Vorschau soll zeigen, was tatsächlich eingespielt
        // würde.
        $content .= '<h2>Datenbestand (Zeilen je Tabelle)</h2><div class="tabelle-scroll"><table class="table">'
            . '<tr><th>Tabelle</th><th>Quelle (im Dump)</th><th>Ziel</th><th>Was geschieht</th></tr>';
        $tables = array_unique(array_merge(array_keys($befund->tabellen), array_keys($local['tables'])));
        sort($tables);
        foreach ($tables as $t) {
            $info = $befund->tabellen[$t] ?? null;
            if ($info === null) {
                $wirkung = 'bleibt unverändert';
            } elseif ($info['aktion'] === DumpPruefer::AUSFUEHREN) {
                $wirkung = 'wird ersetzt';
            } elseif ($info['aktion'] === DumpPruefer::UEBERSPRINGEN) {
                $wirkung = 'wird übersprungen – ' . $e($info['grund']);
            } else {
                $wirkung = 'ungeprüft';
            }
            $content .= '<tr><td><code>' . $e($t) . '</code></td><td>' . $e($info !== null ? $info['zeilen'] : '-')
                . '</td><td>' . $e($local['tables'][$t] ?? '-') . '</td><td>' . $wirkung . '</td></tr>';
        }
        $content .= '</table></div>';

        foreach ($risiken['trennbar'] as $r) {
            $content .= '<p class="alert alert-warning">⚠ ' . $e($r['text']) . '</p>';
        }
        foreach ($risiken['hinweise'] as $h) {
            $content .= '<p class="alert alert-warning">⚠ ' . $e($h) . '</p>';
        }

        foreach ($problems as $p) {
            $content .= '<p class="alert alert-error">' . $e($p) . '</p>';
        }
        foreach ($warnings as $w) {
            $content .= '<p class="alert alert-warning">⚠ ' . $e($w) . '</p>';
        }

        if (!$problems) {
            $frage = $vollstaendig
                ? 'Wirklich die Daten dieser Instanz durch das Archiv ersetzen?'
                : 'Wirklich die im Archiv enthaltenen Tabellen dieser Instanz überschreiben?';
            $zusage = 'Mir ist klar: Die oben mit „wird ersetzt" gekennzeichneten Tabellen werden vollständig durch '
                . 'den Stand des Archivs überschrieben'
                . ($vollstaendig ? ' (bei diesem Vollarchiv einschließlich der Benutzerkonten)' : '')
                . '; die übrigen bleiben stehen. Wo sie auf ersetzte Tabellen verweisen, gilt die Entscheidung '
                . 'unten.';
            $content .= '<form method="POST" action="/plugin/datenmigration/import/anwenden" '
                . 'onsubmit="return confirm(\'' . $frage . '\');">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="datei" value="' . $file . '">';
            if ($risiken['trennbar'] !== []) {
                // Pflichtwahl OHNE Vorgabe (Audit N23): Ob die stehenden Zeilen
                // zu den Datensätzen des Archivs passen, weiß nur der
                // Betreiber - haben Quelle und Ziel dieselbe Abstammung, sind
                // sie richtig, sonst hängen sie an Fremden.
                $content .= '<fieldset class="form-group"><legend><strong>Abhängige Zeilen, die stehen bleiben</strong> '
                    . '(siehe Hinweise oben) - bitte entscheiden:</legend>'
                    . '<label><input type="radio" name="abhaengige" value="trennen" required> <strong>trennen</strong> - '
                    . 'die Verweise werden nach dem Einspielen gelöst: auf NULL bzw. 0 gesetzt, wo die Spalte das '
                    . 'zulässt, sonst wird die Zeile gelöscht. Richtig, wenn Quelle und Ziel verschiedene Bestände '
                    . 'sind.</label><br>'
                    . '<label><input type="radio" name="abhaengige" value="stehen_lassen" required> <strong>stehen '
                    . 'lassen</strong> - die Zeilen bleiben unverändert und gehören danach zu den Datensätzen des '
                    . 'Archivs mit derselben Kennung. Richtig nur, wenn das Archiv von dieser Instanz stammt.</label>'
                    . '</fieldset>';
            }
            $content .= $this->schluesselFelder($bedarf);
            $content .= '<div class="form-group"><label><input type="checkbox" name="bestaetigt" value="1" required> '
                . $zusage . ' '
                . 'Ein Sicherungs-Dump wird vorher nach <code>var/datenmigration/</code> geschrieben.</label></div>'
                . '<button type="submit" class="btn btn-danger">Import anwenden</button></form>';
        }
        $content .= '<p><a href="/plugin/datenmigration/uebersicht">Zurück</a></p></div>';
        PluginPage::render('Datenmigration - Import prüfen', $content);
    }

    /**
     * Die Warnbox zum APP_KEY (Audit M25). Nennt, was betroffen ist, und
     * immer auch die Alternative: den APP_KEY der Quelle übernehmen.
     *
     * @param array{lage:string, settings:array<int, string>, totp:int, passkeys:int, geheimnisse:bool, betroffen:bool} $bedarf
     */
    private function schluesselHinweis(array $bedarf, \Closure $e): string {
        if ($bedarf['lage'] === 'unbekannt') {
            return '<p class="alert alert-warning">⚠ Ob diese Instanz denselben APP_KEY hat wie die Quelle, lässt sich '
                . 'nicht feststellen (älteres Archivformat oder kein APP_KEY). Mit dem APP_KEY verschlüsselte '
                . 'Zugangsdaten der Quelle - SMTP, Backup-Ziele, Addon-Secrets, TOTP - sind bei einem anderen '
                . 'Schlüssel nach dem Import unbrauchbar; der Abschlussbericht nennt die Zahl nicht entschlüsselbarer '
                . 'Werte.</p>';
        }
        if ($bedarf['lage'] !== 'abweichend' || !$bedarf['betroffen']) {
            return '';
        }
        $teile = [];
        if ($bedarf['settings'] !== []) {
            $teile[] = '<li>Einstellungen: <code>' . implode('</code>, <code>', array_map($e, $bedarf['settings']))
                . '</code>' . ($bedarf['geheimnisse']
                    ? ' - mit dem Exportpasswort werden sie neu verschlüsselt, ohne werden sie geleert und sind neu '
                        . 'einzutragen.'
                    : ' - sie werden geleert und sind neu einzutragen.') . '</li>';
        }
        if ($bedarf['totp'] > 0) {
            $teile[] = '<li>' . $e($bedarf['totp']) . ' Konto/Konten mit TOTP-Zwei-Faktor'
                . ($bedarf['geheimnisse']
                    ? ' - mit dem Exportpasswort werden die Geheimnisse neu verschlüsselt; ohne bleiben sie '
                    : ' - die Geheimnisse bleiben ')
                . 'unlesbar stehen: Die Betroffenen melden sich mit einem Backup-Code an und richten TOTP neu ein, '
                . 'oder ein Administrator setzt ihren zweiten Faktor zurück.</li>';
        }
        if ($bedarf['passkeys'] > 0) {
            $teile[] = '<li>' . $e($bedarf['passkeys']) . ' Passkey(s) - sie sind an den APP_KEY gebunden und lassen '
                . 'sich nicht umschlüsseln. Sie bleiben stehen, funktionieren aber nicht mehr und müssen neu '
                . 'registriert werden; bis dahin hilft ein anderer Faktor oder der Admin-Reset.</li>';
        }
        return '<div class="alert alert-warning"><p>⚠ <strong>Diese Instanz hat einen anderen APP_KEY als die '
            . 'Quelle.</strong> Mit dem APP_KEY verschlüsselte Werte des Archivs sind hier unlesbar:</p><ul>'
            . implode('', $teile) . '</ul><p>Alternative: den APP_KEY der Quelle auf dieser Instanz übernehmen '
            . '(Umgebungsvariable bzw. config) - dann bleiben alle Werte gültig und hier ist nichts zu tun.</p></div>';
    }

    /**
     * Die Eingaben zum APP_KEY im Import-Formular: Exportpasswort und/oder die
     * Zustimmung, ohne Passwort fortzufahren. Das Passwort wird nie
     * vorbelegt.
     *
     * @param array{lage:string, settings:array<int, string>, totp:int, passkeys:int, geheimnisse:bool, betroffen:bool} $bedarf
     */
    private function schluesselFelder(array $bedarf): string {
        if ($bedarf['lage'] !== 'abweichend' || !$bedarf['betroffen']) {
            return '';
        }
        $html = '<fieldset class="form-group"><legend><strong>Anderer APP_KEY</strong> - bitte entscheiden:</legend>';
        if ($bedarf['geheimnisse']) {
            $html .= '<div class="form-group"><label for="export_passwort">Exportpasswort des Archivs</label>'
                . '<input type="password" name="export_passwort" id="export_passwort" class="form-control" '
                . 'autocomplete="off"></div><p><small>oder:</small></p>';
        }
        $html .= '<label><input type="checkbox" name="ohne_geheimnisse" value="1"'
            . ($bedarf['geheimnisse'] ? '' : ' required') . '> Ohne Exportpasswort fortfahren – die genannten '
            . 'Zugangsdaten werden geleert</label></fieldset>';
        return $html;
    }

    /**
     * Welche Gruppen stecken in diesem Archiv?
     *
     * Format 1 kannte das Feld nicht und war immer ein Vollarchiv - dieser
     * Fall wird hier eingesetzt, statt ihn abzuweisen. Ein Format-2-Archiv
     * ohne brauchbares `auswahl` (von Hand gebaut, beschädigt) fällt auf die
     * leere Auswahl zurück: Dann wird nichts ersetzt, was nicht ausdrücklich
     * benannt ist - die harmlosere Richtung.
     *
     * @return array<int, string>
     */
    private function auswahlDesArchivs(array $manifest): array {
        if ((int) ($manifest['format'] ?? 0) === 1) {
            return Exportauswahl::schluessel();
        }
        return Exportauswahl::bereinige($manifest['auswahl'] ?? []);
    }

    /**
     * Liest ein Archiv in EINEM Durchlauf: database.sql durch den Prüfer
     * (und, beim Anwenden, zugleich in die Zwischendatei), Uploads und
     * Pferdefotos - beim Anwenden - in ihre Nebenverzeichnisse. Mehrfache
     * Einträge database.sql oder manifest.json werden abgewiesen: tar erlaubt
     * sie, und dann sähen Vorschau und Anwenden womöglich verschiedene
     * Einträge.
     *
     * @param resource|null $zwischen
     * @return array{uebersprungen:int, dateien:int, fotos:int}
     */
    private function leseArchiv(string $path, DumpPruefer $pruefer, $zwischen = null, ?string $uploadsNew = null,
                                ?string $horsesNew = null): array {
        $stand = ['sql' => 0, 'manifest' => 0, 'uebersprungen' => 0, 'dateien' => 0, 'fotos' => 0];
        $reader = new TarReader($path);
        try {
            $reader->each(function (string $name, int $size, callable $read) use (&$stand, $pruefer, $zwischen, $uploadsNew, $horsesNew) {
                if ($name === 'database.sql') {
                    if (++$stand['sql'] > 1) {
                        throw new \RuntimeException('database.sql steht mehrfach im Archiv - so schreibt dieses Addon kein Archiv.');
                    }
                    while (($chunk = $read()) !== '') {
                        if ($zwischen !== null && fwrite($zwischen, $chunk) !== strlen($chunk)) {
                            throw new \RuntimeException('Zwischendatei nicht schreibbar (Datenträger voll?).');
                        }
                        foreach ($pruefer->zufuehren($chunk) as $_) {
                            // Erster Durchlauf: nur prüfen, nichts ausführen.
                        }
                    }
                    foreach ($pruefer->abschliessen() as $_) {
                    }
                    return;
                }
                if ($name === 'manifest.json' && ++$stand['manifest'] > 1) {
                    throw new \RuntimeException('manifest.json steht mehrfach im Archiv - so schreibt dieses Addon kein Archiv.');
                }
                foreach ([['uploads/', $uploadsNew, 'dateien'], [self::PRAEFIX_FOTOS, $horsesNew, 'fotos']] as [$praefix, $basis, $zaehler]) {
                    if ($basis === null || !str_starts_with($name, $praefix)) {
                        continue;
                    }
                    $rel = self::pruefeArchivPfad($name, $praefix);
                    if ($rel === null) {
                        $stand['uebersprungen']++;
                        while ($read() !== '') { // Datenstrom verwerfen
                        }
                        return;
                    }
                    self::schreibeEintrag($basis, $rel, $read);
                    $stand[$zaehler]++;
                    return;
                }
                while ($read() !== '') { // manifest.json, geheimnisse.json u. ä.: konsumieren
                }
            });
        } finally {
            $reader->close();
        }
        if ($stand['sql'] === 0) {
            throw new \RuntimeException('database.sql fehlt im Archiv.');
        }
        return ['uebersprungen' => $stand['uebersprungen'], 'dateien' => $stand['dateien'], 'fotos' => $stand['fotos']];
    }

    /**
     * Der relative Pfad eines Archiveintrags unter $praefix - geprüft, bevor
     * irgendetwas geschrieben wird. null heißt: still verwerfen.
     *
     * Pfadhärtung: keine Traversal, keine absoluten Pfade, kein NUL. Dann der
     * Name (UploadNamePolicy): Die Pfadhärtung prüft, WOHIN geschrieben wird,
     * aber nicht WAS - und das Ziel von uploads/ ist public/uploads. Der
     * Inhalt stammt aus einer hochgeladenen Datei; ohne diese Prüfung genügte
     * ein Archiv mit einer .php darin für Codeausführung.
     *
     * Verworfen (nicht abgebrochen) werden Webserver-Steuerdateien - der
     * Ausführungsschutz des Ziels darf nicht aus dem Archiv stammen, er wird
     * nach dem Umschalten neu geschrieben - und unter storage-horses/ jede
     * Punktdatei: Der Kern liefert storage/horses/.gitkeep aus, ein fremd
     * gebautes Archiv darf daran nicht scheitern, und das Ziel schreibt sie
     * ohnehin nicht (Audit M26).
     *
     * @throws \RuntimeException bei unzulässigem Pfad oder Namen
     */
    private static function pruefeArchivPfad(string $name, string $praefix): ?string {
        $rel = substr($name, strlen($praefix));
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || str_contains($rel, "\0")) {
            throw new \RuntimeException("Unzulässiger Pfad im Archiv: {$name}");
        }
        if (UploadNamePolicy::istWebserverSteuerdatei($rel)) {
            return null;
        }
        if ($praefix === self::PRAEFIX_FOTOS && str_starts_with(basename($rel), '.')) {
            return null;
        }
        UploadNamePolicy::assertAllowed($rel);
        return $rel;
    }

    /**
     * Schreibt einen Archiveintrag nach $basis/$rel - mit geprüften
     * Rückgaben: Ein fwrite(), das auf voller Platte weniger schreibt, ergäbe
     * sonst ein abgeschnittenes Bild, das als übernommen gälte.
     *
     * @param callable():string $read
     */
    private static function schreibeEintrag(string $basis, string $rel, callable $read): void {
        $ziel = $basis . '/' . $rel;
        $verzeichnis = dirname($ziel);
        if (!is_dir($verzeichnis) && !mkdir($verzeichnis, 0755, true) && !is_dir($verzeichnis)) {
            throw new \RuntimeException("Verzeichnis nicht anlegbar: {$rel}");
        }
        $out = fopen($ziel, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Datei nicht schreibbar: {$rel}");
        }
        try {
            while (($chunk = $read()) !== '') {
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException("Datei nicht vollständig geschrieben (Datenträger voll?): {$rel}");
                }
            }
        } finally {
            $geschlossen = fclose($out);
        }
        if (!$geschlossen) {
            throw new \RuntimeException("Datei nicht abschließbar: {$rel}");
        }
    }

    /**
     * Erster Durchlauf durch database.sql: Positivliste, Blockreihenfolge,
     * Tabellenregel - und bei Format 2 der Abgleich mit der Tabellenliste
     * des Manifests. Das Ergebnis ist der PLAN, nach dem Vorschau und
     * Anwenden sich richten.
     *
     * @param array<int, string> $auswahl
     * @param array<string, array<int, string>> $fkKarte eingefroren
     * @param resource|null $zwischen
     * @return array{befund:DumpBefund, problem:?string, hinweis:?string, stand:array{uebersprungen:int, dateien:int, fotos:int}}
     */
    private function pruefeArchiv(string $path, array $manifest, array $auswahl, array $fkKarte, int $paketGrenze,
                                  $zwischen = null, ?string $uploadsNew = null, ?string $horsesNew = null): array {
        $pruefer = new DumpPruefer($paketGrenze, Importregel::fuer($auswahl, $fkKarte));
        $stand = ['uebersprungen' => 0, 'dateien' => 0, 'fotos' => 0];
        try {
            $stand = $this->leseArchiv($path, $pruefer, $zwischen, $uploadsNew, $horsesNew);
        } catch (\Throwable $e) {
            return [
                'befund' => $pruefer->befund(),
                'problem' => 'Der Datenbank-Dump des Archivs ist nicht einspielbar: ' . $e->getMessage(),
                'hinweis' => null,
                'stand' => $stand,
            ];
        }
        $befund = $pruefer->befund();

        // Tabellenliste gegen das Manifest. Format 2 schreibt beide aus
        // derselben Liste; weichen sie ab, ist das Archiv nachbearbeitet.
        // Format 1 (v0.7) ist immer ein Vollarchiv - dort nur ein Hinweis.
        $imDump = array_keys($befund->tabellen);
        $imManifest = array_map('strval', array_keys((array) ($manifest['tables'] ?? [])));
        $nurDump = array_diff($imDump, $imManifest);
        $nurManifest = array_diff($imManifest, $imDump);
        $problem = null;
        $hinweis = null;
        if ($nurDump !== [] || $nurManifest !== []) {
            $text = 'Die Tabellen in database.sql stimmen nicht mit dem Manifest überein'
                . ($nurDump !== [] ? '; nur im Dump: ' . implode(', ', $nurDump) : '')
                . ($nurManifest !== [] ? '; nur im Manifest: ' . implode(', ', $nurManifest) : '') . '.';
            if ((int) ($manifest['format'] ?? 0) === 1) {
                $hinweis = $text . ' Bei einem Archiv im alten Format 1 wird der Dump trotzdem eingespielt.';
            } else {
                $problem = $text . ' Das Archiv wurde nach dem Export verändert und wird nicht eingespielt.';
            }
        }
        return ['befund' => $befund, 'problem' => $problem, 'hinweis' => $hinweis, 'stand' => $stand];
    }

    /**
     * Ersetzt der Plan eine Tabelle, die zu den Benutzerkonten gehört (neue
     * Zuordnung, Audit M3)? Dann sind nach dem Import alle Sitzungen zu
     * beenden (M2). Übersprungen heißt dabei nicht ersetzt.
     *
     * @param array<string, array<int, string>> $fkKarte
     */
    private static function benutzerbezogenErsetzt(DumpBefund $befund, array $fkKarte): bool {
        foreach ($befund->ersetzt() as $t) {
            $ziele = array_merge($befund->tabellen[$t]['verweise'], $fkKarte[$t] ?? []);
            if (Exportauswahl::gruppeFuer($t, $ziele) === Exportauswahl::GRUPPE_BENUTZER) {
                return true;
            }
        }
        return false;
    }

    /**
     * Was ein Import auf DIESER Instanz anrichten kann, mit Zahlen - für
     * Teil- UND Vollarchive (Audit N23).
     *
     * Nachgemessen (MariaDB 11.8): Der Dump setzt FOREIGN_KEY_CHECKS=0, wirft
     * die enthaltenen Tabellen weg und legt sie neu an. Zeilen in Tabellen,
     * die der Import NICHT ersetzt, bleiben stehen. Ihre Verweise zeigen
     * danach nicht "ins Leere", wie es hier bis 1.1.0 hieß, sondern auf die
     * Datensätze des ARCHIVS mit derselben Kennung - bei zwei verschiedenen
     * Beständen also auf fremde: das Inserat am falschen Pferd, die
     * Kontaktanfrage an die falsche Person. Das abschließende
     * FOREIGN_KEY_CHECKS=1 prüft den Bestand nicht nach.
     *
     * Auch ein Vollarchiv lässt Tabellen stehen: die eines Addons, das die
     * Quelle nicht hat - darunter solche mit Verweis auf users. Deshalb läuft
     * die Prüfung seit 1.2.0 für jedes Archiv.
     *
     * `trennbar` sind die Fälle "Eltern ersetzt, Kind bleibt" - für sie
     * verlangt die Vorschau die Entscheidung trennen/stehen lassen.
     * `hinweise` ist die umgekehrte Richtung (Kind ersetzt, Eltern bleiben):
     * Dort bringt das Archiv die Zeilen mit, trennen hieße sie gleich wieder
     * wegzuwerfen - also nur ein Hinweis.
     *
     * @param array<int, array{tabelle:string, spalte:string, ziel:string}> $fks eingefroren
     * @param array<string, int> $lokal Zeilen je Tabelle auf dem Ziel
     * @param array<string, array<string, bool>> $nullbar eingefroren
     * @return array{trennbar: array<int, array{kind:string, ziel:string, spalten:array<int, string>, wo:array<string, int|string>, weich:bool, trennen:string, zeilen:int, text:string}>, hinweise: array<int, string>}
     */
    private function importRisiken(DumpBefund $befund, array $fks, array $lokal, array $nullbar): array {
        $plan = $befund->plan();
        $ersetzt = static fn(string $t): bool => ($plan[$t] ?? null) === DumpPruefer::AUSFUEHREN;
        $db = Database::getInstance();
        $trennbar = [];
        $hinweise = [];

        $kandidaten = [];
        foreach ($fks as $fk) {
            $kandidaten[] = [
                'kind' => $fk['tabelle'], 'ziel' => $fk['ziel'], 'spalten' => [$fk['spalte']], 'wo' => [],
                'weich' => false,
                'trennen' => ($nullbar[$fk['tabelle']][$fk['spalte']] ?? false) ? 'null' : 'loeschen',
            ];
        }
        foreach ($this->weicheVerweise() as $kind => $eintraege) {
            foreach (self::weicheGruppen($eintraege) as $g) {
                $kandidaten[] = [
                    'kind' => $kind, 'ziel' => $g['ziel'], 'spalten' => $g['spalten'], 'wo' => $g['wo'],
                    'weich' => true, 'trennen' => $g['trennen'],
                ];
            }
        }

        foreach ($kandidaten as $k) {
            // Eltern ersetzt, Kind bleibt stehen (und existiert hier).
            if (!$ersetzt($k['ziel']) || $ersetzt($k['kind']) || !isset($lokal[$k['kind']])) {
                continue;
            }
            [$bedingung, $parameter] = self::verweisBedingung($k['spalten'], $k['wo'], $k['weich']);
            $n = self::zaehle($db, $k['kind'], $bedingung, $parameter);
            if ($n === 0) {
                continue;
            }
            $folge = match ($k['trennen']) {
                'null' => 'Beim Trennen wird der Verweis auf NULL gesetzt.',
                'null_wert' => 'Beim Trennen wird der Verweis auf 0 („Datensatz entfernt“) gesetzt.',
                default => 'Beim Trennen werden diese Zeilen gelöscht.',
            };
            $k['zeilen'] = $n;
            $k['text'] = number_format($n, 0, ',', '.') . ' Zeile(n) in ' . $k['kind']
                . ' (' . implode(', ', $k['spalten']) . ') verweisen auf ' . $k['ziel'] . '. ' . $k['ziel']
                . ' wird durch das Archiv ersetzt, ' . $k['kind'] . ' nicht: Die Zeilen hängen danach an den '
                . 'Datensätzen des Archivs mit derselben Kennung - bei verschiedenen Beständen also an fremden; '
                . 'nur wo die Kennung im Archiv fehlt, zeigen sie ins Leere. ' . $folge;
            $trennbar[] = $k;
        }

        // Umgekehrte Richtung: Kind ersetzt, Eltern bleiben. Verweisziele aus
        // dem Dump (REFERENCES) und den Fremdschlüsseln dieser Instanz.
        $paare = [];
        foreach ($befund->tabellen as $kind => $info) {
            if ($info['aktion'] !== DumpPruefer::AUSFUEHREN || $info['zeilen'] === 0) {
                continue;
            }
            foreach ($info['verweise'] as $ziel) {
                $paare[$kind . '|' . $ziel] = [$kind, $ziel];
            }
        }
        foreach ($fks as $fk) {
            if ($ersetzt($fk['tabelle']) && ($befund->tabellen[$fk['tabelle']]['zeilen'] ?? 0) > 0) {
                $paare[$fk['tabelle'] . '|' . $fk['ziel']] = [$fk['tabelle'], $fk['ziel']];
            }
        }
        foreach ($this->weicheVerweise() as $kind => $eintraege) {
            if ($ersetzt($kind) && ($befund->tabellen[$kind]['zeilen'] ?? 0) > 0) {
                foreach ($eintraege as $e) {
                    $paare[$kind . '|' . $e['ziel']] = [$kind, $e['ziel']];
                }
            }
        }
        ksort($paare);
        foreach ($paare as [$kind, $ziel]) {
            // users: Solche Tabellen werden ohne users gar nicht ersetzt (M3).
            if ($kind === $ziel || $ziel === 'users' || $ersetzt($ziel) || !isset($lokal[$ziel])) {
                continue;
            }
            $hinweise[] = $kind . ' wird ersetzt, ' . $ziel . ' nicht - die Zeilen des Archivs hängen danach an '
                . 'den Datensätzen dieser Instanz mit gleicher Kennung.';
        }

        return ['trennbar' => $trennbar, 'hinweise' => $hinweise];
    }

    /** @return array<int, string> Harte Hindernisse (Import wird verweigert) */
    private function compatibilityProblems(array $manifest, array $local): array {
        $problems = [];
        if (!in_array((int) ($manifest['format'] ?? 0), self::LESBARE_FORMATE, true)) {
            $problems[] = 'Unbekanntes Archivformat (Version ' . (string) ($manifest['format'] ?? '?')
                . ', lesbar sind ' . implode('/', self::LESBARE_FORMATE) . ').';
        }
        $src = (string) ($manifest['core_version'] ?? '');
        if ($src === '' || $src !== $local['core_version']) {
            $problems[] = 'Kern-Version passt nicht (Archiv: ' . ($src ?: 'unbekannt') . ', Ziel: ' . $local['core_version']
                . '). Erst beide Instanzen auf denselben Stand bringen - ein versionsübergreifender Import braucht einen Schema-Migrationslauf.';
        }
        return $problems;
    }

    /** @return array<int, string> Weiche Hinweise (Import bleibt möglich) */
    private function pluginWarnings(array $manifest, array $local): array {
        $warnings = [];
        $localPlugins = [];
        foreach ($local['plugins'] as $p) {
            $localPlugins[$p['slug']] = $p['version'];
        }
        foreach (($manifest['plugins'] ?? []) as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            if (!array_key_exists($slug, $localPlugins)) {
                $warnings[] = "Quell-Addon '{$slug}' fehlt auf dieser Instanz - seine Tabellen werden mit importiert, "
                    . 'bleiben aber ohne das Addon unsichtbar. Addon nachinstallieren und danach unter /admin/plugins aktivieren.';
            } elseif (($p['version'] ?? '') !== $localPlugins[$slug]) {
                $warnings[] = "Addon '{$slug}': Quellversion " . ($p['version'] ?? '?') . ', hier ' . $localPlugins[$slug]
                    . ' - Datenformat der Plugin-Tabellen im Zweifel prüfen.';
            }
        }
        // Aktivierungszustand kommt aus der importierten plugins-Tabelle; der
        // Kern prüft danach den Verzeichnis-Fingerabdruck und deaktiviert
        // alles, was lokal nicht (identisch) vorliegt - fail-closed.
        return $warnings;
    }

    private function readManifest(string $path): array {
        return $this->leseKopf($path)['manifest'];
    }

    /**
     * Liest in einem Durchlauf die kleinen Einträge des Archivs:
     * manifest.json (Pflicht) und geheimnisse.json (optional, Audit M25),
     * beide höchstens 1 MiB.
     *
     * Doppelte Einträge werden abgewiesen - auch database.sql: tar erlaubt
     * sie, beim Lesen gewönne je nach Stelle der erste oder der letzte, und
     * Vorschau und Anwenden sähen dann verschiedene Inhalte.
     *
     * @return array{manifest: array<string, mixed>, geheimnisse: ?string}
     */
    private function leseKopf(string $path): array {
        $gelesen = ['manifest.json' => null, 'geheimnisse.json' => null];
        $anzahl = ['manifest.json' => 0, 'database.sql' => 0, 'geheimnisse.json' => 0];
        $reader = new TarReader($path);
        try {
            $reader->each(function (string $name, int $size, callable $read) use (&$gelesen, &$anzahl) {
                if (isset($anzahl[$name])) {
                    $anzahl[$name]++;
                }
                $merken = array_key_exists($name, $gelesen) && $gelesen[$name] === null;
                if ($merken && $size > self::MAX_KOPFEINTRAG) {
                    throw new \RuntimeException("{$name} unplausibel groß.");
                }
                $data = '';
                while (($chunk = $read()) !== '') {
                    if ($merken) {
                        $data .= $chunk;
                    }
                }
                if ($merken) {
                    $gelesen[$name] = $data;
                }
            });
        } finally {
            $reader->close();
        }
        foreach ($anzahl as $eintrag => $n) {
            if ($n > 1) {
                throw new \RuntimeException("{$eintrag} steht mehrfach im Archiv - so schreibt dieses Addon kein Archiv.");
            }
        }
        if ($gelesen['manifest.json'] === null) {
            throw new \RuntimeException('manifest.json fehlt im Archiv.');
        }
        $manifest = json_decode($gelesen['manifest.json'], true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('manifest.json ist kein gültiges JSON.');
        }
        return ['manifest' => $manifest, 'geheimnisse' => $gelesen['geheimnisse.json']];
    }

    // -- Import: Anwenden ---------------------------------------------------

    public function apply(): void {
        $this->requirePermission('datenmigration', 'import');
        $this->requireAdminForFullAccess('import');
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
            return;
        }
        if (($_POST['bestaetigt'] ?? '') !== '1') {
            $this->fail('Import nicht bestätigt.');
            return;
        }
        $path = $this->stagedPath((string) ($_POST['datei'] ?? ''));
        if ($path === null) {
            $this->fail('Archiv nicht gefunden.');
            return;
        }
        // Unlesbare Archive (auch ein .tar.gz ohne zlib, Audit N22) enden
        // hier mit einer Meldung, nicht mit einer Fehlerseite.
        try {
            $kopf = $this->leseKopf($path);
        } catch (\Throwable $e) {
            $this->fail('Archiv unlesbar, Import abgebrochen, nichts verändert: ' . $e->getMessage());
            return;
        }
        $manifest = $kopf['manifest'];
        $local = $this->localInventory();
        $problems = $this->compatibilityProblems($manifest, $local);
        if ($problems) {
            $this->fail(implode(' ', $problems));
            return;
        }
        $auswahl = $this->auswahlDesArchivs($manifest);
        $vollstaendig = Exportauswahl::istVollstaendig($auswahl);

        // Einmal lesen und einfrieren: Fremdschlüssel, Nullbarkeit und die
        // Paketgrenze. DROP/CREATE ändern information_schema, während der
        // Dump läuft - Plan und Trennen dürfen sich nicht nach einem
        // Zwischenstand richten.
        $fks = $this->fremdschluessel();
        $fkKarte = self::fkKarte($fks);
        $nullbar = $this->spaltenNullbar();
        $paketGrenze = self::paketGrenze(Database::getInstance());

        // 1. Archiv prüfen und entpacken - VOR der Sicherung, dem
        //    Wartungsmodus und jedem Schreibzugriff auf die Datenbank.
        //
        //    database.sql läuft durch den Prüfer und zugleich in eine
        //    Zwischendatei (0600, außerhalb des Webroots): Sie ist die Quelle
        //    des zweiten Durchlaufs, der tatsächlich ausführt. Sie enthält
        //    womöglich Zugangsmaterial und verschwindet deshalb in jedem Fall -
        //    im finally, und für die Wege, die mit exit enden oder hart
        //    abbrechen, zusätzlich per Shutdown-Funktion (und in der Übersicht
        //    nach einer Stunde, siehe raeumeZwischendateienAuf()).
        //
        //    Das Nebenverzeichnis für die Uploads liegt AUSSERHALB von
        //    public/. Vorher hieß es "public/uploads.import-neu" und lag damit
        //    im Webroot: Zwischen dem ersten geschriebenen Eintrag und dem
        //    Umschalten war jede Datei des Archivs unter ihrem eigenen Namen
        //    über den Webserver erreichbar - inklusive einer .php.
        //
        //    Die Pferdefotos (storage-horses/, Audit M26) gehen ebenso in ein
        //    eigenes Nebenverzeichnis unter var/datenmigration.
        $uploadsNew = $this->stageDir() . '/uploads-neu';
        $horsesNew = $this->stageDir() . '/horses-neu';
        $this->removeDir($uploadsNew);
        $this->removeDir($horsesNew);
        mkdir($uploadsNew, 0755, true);
        mkdir($horsesNew, 0750, true);
        $zwischenPfad = $this->stageDir() . '/.import-' . bin2hex(random_bytes(8)) . '.sql';
        register_shutdown_function(static function () use ($zwischenPfad): void {
            if (is_file($zwischenPfad)) {
                @unlink($zwischenPfad);
            }
        });
        try {
            $this->importiere($path, $manifest, $kopf['geheimnisse'], $auswahl, $vollstaendig, $local, $fks, $fkKarte,
                $nullbar, $paketGrenze, $zwischenPfad, $uploadsNew, $horsesNew);
        } finally {
            if (is_file($zwischenPfad)) {
                @unlink($zwischenPfad);
            }
            // Auf jedem Weg - auch nach fail() - verschwinden die Nebenverzeichnisse.
            $this->removeDir($uploadsNew);
            $this->removeDir($horsesNew);
        }
    }

    /**
     * Der eigentliche Ablauf, in dieser Reihenfolge:
     * Archiv prüfen -> Vollsicherung -> Sicherung trocken prüfen ->
     * Wartungsmodus -> Dump -> Trennen -> Sitzungen beenden -> bei Fehler
     * Rückweg -> Wartungsmodus aufheben (außer der Rückweg ist gescheitert).
     *
     * @param array<int, string> $auswahl
     * @param array{tables:array<string,int>} $local
     * @param array<int, array{tabelle:string, spalte:string, ziel:string}> $fks
     * @param array<string, array<int, string>> $fkKarte
     * @param array<string, array<string, bool>> $nullbar
     */
    private function importiere(string $path, array $manifest, ?string $geheimnisDatei, array $auswahl,
                                bool $vollstaendig, array $local, array $fks, array $fkKarte, array $nullbar,
                                int $paketGrenze, string $zwischenPfad, string $uploadsNew, string $horsesNew): void {
        $zh = @fopen($zwischenPfad, 'xb');
        if ($zh === false) {
            $this->removeDir($uploadsNew);
            $this->fail('Zwischendatei nicht anlegbar - nichts verändert.');
            return;
        }
        @chmod($zwischenPfad, 0600);
        try {
            $pruefung = $this->pruefeArchiv($path, $manifest, $auswahl, $fkKarte, $paketGrenze, $zh, $uploadsNew, $horsesNew);
        } finally {
            $geschlossen = fclose($zh);
        }
        if ($pruefung['problem'] === null && !$geschlossen) {
            $pruefung['problem'] = 'Zwischendatei konnte nicht abgeschlossen werden.';
        }
        if ($pruefung['problem'] !== null) {
            $this->removeDir($uploadsNew);
            $this->fail('Import abgebrochen, nichts verändert: ' . $pruefung['problem']);
            return;
        }
        $befund = $pruefung['befund'];
        $plan = $befund->plan();

        // 2. Pflichtwahl für stehenbleibende abhängige Zeilen - serverseitig
        //    neu berechnet, nicht dem Formular geglaubt.
        $risiken = $this->importRisiken($befund, $fks, $local['tables'], $nullbar);
        $abhaengige = (string) ($_POST['abhaengige'] ?? '');
        if ($risiken['trennbar'] !== [] && !in_array($abhaengige, ['trennen', 'stehen_lassen'], true)) {
            $this->removeDir($uploadsNew);
            $this->fail('Import abgebrochen, nichts verändert: Abhängige Zeilen dieser Instanz verweisen auf Tabellen, '
                . 'die das Archiv ersetzt. Bitte in der Vorschau „trennen“ oder „stehen lassen“ wählen.');
            return;
        }

        // 2b. Anderer APP_KEY (Audit M25): Exportpasswort oder ausdrückliche
        //     Zustimmung - geprüft, bevor die Sicherung entsteht. Ein falsches
        //     Passwort ändert nichts.
        $bedarf = self::schluesselBedarf($manifest, $befund);
        $geheimnisse = null;
        if ($bedarf['lage'] === 'abweichend' && $bedarf['betroffen']) {
            $passwort = (string) ($_POST['export_passwort'] ?? '');
            $ohne = ($_POST['ohne_geheimnisse'] ?? '') === '1';
            if ($bedarf['geheimnisse'] && $geheimnisDatei === null) {
                $this->fail('Import abgebrochen, nichts verändert: Laut Manifest enthält das Archiv verschlüsselte '
                    . 'Zugangsdaten (geheimnisse.json), die Datei fehlt aber.');
                return;
            }
            if ($bedarf['geheimnisse'] && $passwort !== '') {
                try {
                    $geheimnisse = Geheimnisumschlag::entpacken((string) $geheimnisDatei, $passwort);
                } catch (\Throwable $e) {
                    $this->fail('Import abgebrochen, nichts verändert: ' . $e->getMessage());
                    return;
                } finally {
                    unset($passwort);
                }
            } elseif (!$ohne) {
                $this->fail('Import abgebrochen, nichts verändert: Diese Instanz hat einen anderen APP_KEY als die '
                    . 'Quelle. Bitte in der Vorschau das Exportpasswort angeben oder bestätigen, dass die nicht '
                    . 'entschlüsselbaren Zugangsdaten geleert werden.');
                return;
            }
        }

        // 3. Rückweg sichern: Dump der Zielinstanz VOR dem Import.
        //
        // Immer VOLLSTÄNDIG, auch wenn nur ein Teilarchiv eingespielt wird -
        // die Sicherung ist der Rückweg, und ein Rückweg, der nur die Hälfte
        // kennt, ist keiner. Über dumpTo() in die Datei statt über dump() in
        // einen String: Der Speicherbedarf bleibt damit unabhängig von der
        // Instanzgröße (Framework#231). Erst jetzt, nach der Prüfung des
        // Archivs: Ein abgewiesenes Archiv hinterlässt keine Sicherung.
        $backupName = 'sicherung-vor-import-' . gmdate('Ymd-His') . '.sql' . (function_exists('gzencode') ? '.gz' : '');
        $backupPfad = $this->stageDir() . '/' . $backupName;
        $gz = function_exists('gzopen') && str_ends_with($backupName, '.gz');
        $bh = $gz ? gzopen($backupPfad, 'wb6') : fopen($backupPfad, 'wb');
        if ($bh === false) {
            $this->removeDir($uploadsNew);
            $this->fail('Sicherungs-Dump nicht schreibbar - nichts verändert.');
            return;
        }
        try {
            DatabaseDumper::dumpTo(function (string $chunk) use ($bh, $gz): void {
                // gzwrite() meldet einen Schreibfehler mit 0, nicht false (M43).
                $ok = $gz ? gzwrite($bh, $chunk) : fwrite($bh, $chunk);
                if ($ok !== strlen($chunk)) {
                    throw new \RuntimeException('Sicherungs-Dump konnte nicht geschrieben werden (Datenträger voll?).');
                }
            });
        } catch (\Throwable $e) {
            $gz ? gzclose($bh) : fclose($bh);
            @unlink($backupPfad);
            $this->removeDir($uploadsNew);
            $this->fail('Sicherungs-Dump fehlgeschlagen, Import abgebrochen: ' . $e->getMessage());
            return;
        }
        if (!($gz ? gzclose($bh) : fclose($bh))) {
            @unlink($backupPfad);
            $this->removeDir($uploadsNew);
            $this->fail('Sicherungs-Dump konnte nicht abgeschlossen werden, Import abgebrochen - nichts verändert.');
            return;
        }

        // 4. Die Sicherung einmal trocken prüfen, BEVOR sich etwas ändert.
        //    Sonst fiele der Rückweg genau dann aus, wenn er gebraucht wird -
        //    etwa an einer View (für die der Kern eine leere Anweisung
        //    schreibt) oder an einer Zeile über max_allowed_packet.
        try {
            $this->pruefeSicherung($backupPfad, $paketGrenze);
        } catch (\Throwable $e) {
            @unlink($backupPfad);
            $this->removeDir($uploadsNew);
            $this->fail('Die Sicherung dieser Instanz ließe sich nicht zurückspielen - Import abgebrochen, nichts '
                . 'verändert. Grund: ' . $e->getMessage());
            return;
        }

        // 5. Datenbank ersetzen - unter Wartungsmodus, und das ist keine
        //    Kosmetik: Der Dump wirft jede Tabelle einzeln weg und legt sie
        //    neu an. Zwischen dem DROP der ersten und dem letzten INSERT gibt
        //    es ein Zeitfenster, in dem parallele Anfragen auf eine halb
        //    ersetzte Datenbank träfen. DDL in MariaDB ist zudem
        //    transaktions-autocommittend: Ein "einfach in eine Transaktion
        //    packen" gibt es hier nicht, der Wartungsmodus ist die vorhandene
        //    und richtige Antwort (App\Service\Maintenance, vom Kern in
        //    public/index.php vor jedem DB-Zugriff geprüft).
        //
        //    Ein abgebrochener Browser oder ein Zeitlimit darf den Lauf
        //    zwischen DROP und Rückweg nicht beenden.
        ignore_user_abort(true);
        @set_time_limit(0);
        \App\Service\Maintenance::enable('Datenmigrations-Import läuft');

        $benutzerErsetzt = self::benutzerbezogenErsetzt($befund, $fkKarte);
        $getrennt = [];
        $schluesselBericht = [];
        $unlesbar = 0;
        try {
            $imp = $this->importVerbindung();

            // Höchste session_version VOR dem ersten DROP (M2) - danach steht
            // in users schon der Stand des Archivs.
            $maxVorher = 0;
            try {
                $maxVorher = (int) $imp->query('SELECT COALESCE(MAX(session_version), 0) FROM users')->fetchColumn();
            } catch (\Throwable $e) {
                // Keine users-Tabelle: dann gibt es auch keine Sitzung.
            }

            // Zweiter Durchlauf über die Zwischendatei: erneut geprüft, an den
            // Plan gebunden, ausgeführt.
            $this->spieleDumpEin(
                $zwischenPfad,
                $imp,
                new DumpPruefer($paketGrenze, Importregel::fuer($auswahl, $fkKarte), $plan),
                self::sammelGrenze($paketGrenze)
            );

            if ($abhaengige === 'trennen') {
                $getrennt = $this->trenneAbhaengige($imp, $risiken['trennbar']);
            }

            // Zugangsdaten an den APP_KEY dieser Instanz angleichen (M25) -
            // noch unter Wartungsmodus und im Rückweg-try.
            $schluesselBericht = $this->schluesselAngleichen($imp, $bedarf, $geheimnisse);
            $unlesbar = $this->zaehleUnlesbare($imp, $befund);

            if ($benutzerErsetzt) {
                $this->beendeAlleSitzungen($imp, $maxVorher);
            }
        } catch (\Throwable $e) {
            $imp = null;
            $this->removeDir($uploadsNew);
            $this->removeDir($horsesNew);
            @unlink($zwischenPfad);
            $rueckwegOk = $this->rueckwegEinspielen($backupName, $e);
            Wartung::nachFehlschlag(
                $rueckwegOk,
                'Datenmigrations-Import und Rückweg gescheitert - Sicherung von Hand einspielen: '
                    . $backupPfad . ', danach diese Datei löschen'
            );
            if (!$rueckwegOk) {
                $this->renderNotfallseite($backupPfad, $e);
                return;
            }
            $this->fail('Import fehlgeschlagen, der Sicherungsstand wurde zurückgespielt: ' . $e->getMessage());
            return;
        }
        $imp = null;
        \App\Service\Maintenance::disable();
        @unlink($zwischenPfad);

        // 6. Dateien: public/uploads, storage/horses, Schutzdateien.
        //
        // Für public/uploads drei Fälle, und der erste ist der, wegen dem
        // dieser Block seit #121 überhaupt eine Fallunterscheidung hat:
        //
        //   (a) Das Archiv bringt keine Dateien mit (Gruppe "Dateien" war beim
        //       Export nicht angehakt). Dann wird public/uploads NICHT
        //       angefasst. Der frühere Code tauschte das Verzeichnis
        //       bedingungslos aus - ein Teilarchiv "nur Kontakte" hätte damit
        //       sämtliche Pferdebilder der Zielinstanz gelöscht.
        //   (b) Vollarchiv mit Dateien: Verzeichnistausch wie bisher, der alte
        //       Stand bleibt als .import-alt als zweiter Rückweg liegen.
        //   (c) Teilarchiv mit Dateien: zusammenführen. Die Zielinstanz behält
        //       ihre übrigen Dateien; überschriebene Originale wandern vorher
        //       nach var/datenmigration/ersetzte-dateien-…, damit auch dieser
        //       Weg einen Rückweg hat.
        //
        // storage/horses (Audit M26) wird nie als Verzeichnis getauscht -
        // im Docker-Setup ist es ein eigenes Volume, und rename() eines
        // Mountpoints scheitert. Stattdessen dateiweise (ersetzeInhalt()).
        //
        // Ob Dateien angefasst werden, entscheidet der TATSÄCHLICHE Inhalt des
        // Archivs, nicht das Manifest. Ein Manifest ist eine Behauptung; das
        // Verzeichnis public/uploads zu leeren, weil in einer JSON-Datei
        // "dateien" stand, wäre die teuerste Art, ihr zu glauben.
        //
        // Die Datenbank ist hier schon eingespielt. Ein Fehler in dieser
        // Phase wird deshalb gemeldet und protokolliert (gemischter Stand,
        // Rückweg über die Sicherungen), statt als Fehlerseite ohne Hinweis
        // zu enden.
        $dateienImArchiv = $pruefung['stand']['dateien'];
        $fotosImArchiv = $pruefung['stand']['fotos'];
        $uebersprungen = $pruefung['stand']['uebersprungen'];
        $dateiBericht = 'Uploads unverändert';
        $fotoBericht = 'Pferdefotos unverändert';
        $schutzProbleme = [];
        $ersetztDir = $this->stageDir() . '/ersetzte-dateien-' . gmdate('Ymd-His');
        try {
            if ($dateienImArchiv === 0) {
                $this->removeDir($uploadsNew);
            } elseif ($vollstaendig) {
                $uploadsOld = $this->uploadsDir() . '.import-alt';
                $this->removeDir($uploadsOld);
                if (is_dir($this->uploadsDir())) {
                    rename($this->uploadsDir(), $uploadsOld);
                }
                // rename() über Verzeichnisgrenzen hinweg schlägt fehl, wenn
                // Staging und Ziel auf verschiedenen Dateisystemen liegen -
                // seit das Staging in var/ liegt, ist das kein theoretischer
                // Fall mehr (eigenes Volume für uploads, siehe
                // docker-compose.yml des Kerns). Deshalb mit Kopier-Rückfall
                // statt eines stillen false.
                if (!@rename($uploadsNew, $this->uploadsDir())) {
                    $this->copyDir($uploadsNew, $this->uploadsDir());
                    $this->removeDir($uploadsNew);
                }
                $dateiBericht = $dateienImArchiv . ' Datei(en) ersetzt (alter Stand: public/uploads.import-alt)';
            } else {
                $zusammen = $this->mergeUploads($uploadsNew, $this->uploadsDir(), $ersetztDir);
                $this->removeDir($uploadsNew);
                $dateiBericht = $zusammen['neu'] . ' Datei(en) neu, ' . $zusammen['ersetzt'] . ' überschrieben'
                    . ($zusammen['ersetzt'] > 0 ? ' (Originale: ' . basename($ersetztDir) . ')' : '');
            }

            if ($fotosImArchiv > 0) {
                $fotos = $this->ersetzeInhalt($horsesNew, $this->horsesDir(), $ersetztDir . '/storage-horses', $vollstaendig);
                $fotoBericht = $fotosImArchiv . ' Pferdefoto(s): ' . $fotos['neu'] . ' neu, ' . $fotos['ersetzt']
                    . ' überschrieben' . ($vollstaendig ? ', ' . $fotos['entfernt'] . ' nur hier vorhandene entfernt' : '')
                    . ($fotos['ersetzt'] + $fotos['entfernt'] > 0
                        ? ' (gesichert: ' . basename($ersetztDir) . '/storage-horses)' : '');
            }
            $this->removeDir($horsesNew);
        } catch (\Throwable $e) {
            $this->removeDir($uploadsNew);
            $this->removeDir($horsesNew);
            $schutzProbleme = $this->restoreUploadsProtection();
            error_log('Datenmigration: Dateiphase fehlgeschlagen - ' . $e->getMessage());
            PluginAudit::log(
                'datenmigration',
                'Import: Dateien unvollständig',
                basename($path),
                'Datenbank eingespielt (Sicherung: ' . $backupName . '), Dateiphase fehlgeschlagen: ' . $e->getMessage()
                    . ($schutzProbleme !== [] ? ', Schutzdateien: ' . implode('; ', $schutzProbleme) : '')
            );
            if ($benutzerErsetzt) {
                session_destroy();
            }
            $this->fail('Datenbank importiert, Dateien unvollständig – ' . $e->getMessage() . '. Sicherungen unter '
                . 'var/datenmigration/ (' . $backupName . ', ersetzte-dateien-…) und public/uploads.import-alt.');
            return;
        }

        // Ausführungsschutz wiederherstellen - der Verzeichnistausch hat die
        // Schutzdateien des Kerns mitgenommen (Audit N1: alle, nicht nur die
        // im Wurzelverzeichnis). Was noch da ist, bleibt unberührt.
        $schutzProbleme = $this->restoreUploadsProtection();

        // Die Audit-Zeile nennt, was TATSÄCHLICH ersetzt wurde - die geprüfte
        // Liste, nicht die Behauptung des Manifests.
        $uebersprungeneTabellen = $befund->uebersprungen();
        $abhaengigBericht = '';
        if ($risiken['trennbar'] !== []) {
            $abhaengigBericht = $abhaengige === 'trennen'
                ? ', abhängige Zeilen getrennt: ' . implode(', ', $getrennt)
                : ', abhängige Zeilen stehen gelassen';
        }
        PluginAudit::log(
            'datenmigration',
            'Import angewendet',
            basename($path),
            ($vollstaendig ? 'Vollarchiv' : 'Teilarchiv: ' . implode(', ', $auswahl))
                . ' - Quelle: ' . (string) ($manifest['site_name'] ?? '?')
                . ', ersetzt: ' . (implode(', ', $befund->ersetzt()) ?: 'keine Tabelle')
                . ($uebersprungeneTabellen !== [] ? ', übersprungen: ' . implode(', ', array_keys($uebersprungeneTabellen)) : '')
                . $abhaengigBericht
                . ($benutzerErsetzt ? ', alle Sitzungen und API-Schlüssel beendet' : '')
                . ', Sicherung: ' . $backupName
                . ', ' . $dateiBericht
                . ', ' . $fotoBericht
                . ($uebersprungen > 0 ? ', ' . $uebersprungen . ' Steuer-/Punktdatei(en) verworfen' : '')
                . ', APP_KEY: ' . $bedarf['lage']
                . ($schluesselBericht !== [] ? ' (' . implode(', ', $schluesselBericht) . ')' : '')
                . ($unlesbar > 0 ? ', ' . $unlesbar . ' verschlüsselte(r) Wert(e) hier nicht entschlüsselbar' : '')
                . ($schutzProbleme !== [] ? ', Schutzdateien NICHT wiederhergestellt: ' . implode('; ', $schutzProbleme) : '')
        );

        // 7. Eigene Sitzung beenden - aber nur, wenn tatsächlich eine
        //    benutzerbezogene Tabelle ersetzt wurde. Bei einem Teilarchiv
        //    ohne Benutzerkonten ist das angemeldete Konto dasselbe wie
        //    vorher; die Sitzung zu zerstören wäre dann nur eine
        //    unerklärliche Abmeldung mitten in der Arbeit. Die übrigen
        //    Sitzungen hat beendeAlleSitzungen() schon ungültig gemacht.
        if ($benutzerErsetzt) {
            session_destroy();
            header('Location: /login?import=fertig');
            exit;
        }
        $zusatz = ($unlesbar > 0 ? '&unlesbar=' . $unlesbar : '') . ($schutzProbleme !== [] ? '&schutz=fehlt' : '');
        header('Location: /plugin/datenmigration/uebersicht?hinweis=importiert' . $zusatz);
        exit;
    }

    /**
     * Frische Datenbankverbindung nur für den Import (Audit N21).
     *
     * Warum nicht die Verbindung der App (Database::getInstance())?
     *   - Der Dump setzt Sitzungsvariablen (FOREIGN_KEY_CHECKS, NAMES,
     *     time_zone). An der App-Verbindung blieben sie hängen, und über sie
     *     laufen danach Audit und Seitenaufbau.
     *   - Bricht eine Anweisung die Verbindung ab (1153 "Paket zu groß", 2006
     *     "Server gone away"), ist die App-Verbindung für den Rest der Anfrage
     *     tot - und der gepinnte Kern hat keine API, sie neu aufzubauen. Der
     *     Rückweg braucht aber eine funktionierende Verbindung.
     *
     * Aufbau wie Database::getInstance() (DSN mit Unix-Socket bei führendem
     * '/', SSL-Optionen, echte Prepared Statements), dazu:
     *   - ATTR_MULTI_STATEMENTS = false: Tiefenverteidigung. Auch wenn
     *     DumpPruefer und Server ein Zeichen einmal verschieden deuten, kann
     *     kein zweites Statement mitlaufen.
     *   - sql_mode ohne NO_BACKSLASH_ESCAPES und ANSI_QUOTES - der Prüfer setzt
     *     Backslash-Escapes und "…" als Zeichenkette voraus.
     *   - time_zone = PHP-Versatz, nachgebaut nach dem privaten
     *     Database::alignSessionTimeZone(). Ältere Dumps ohne eigenes
     *     `SET time_zone` und die Sicherung wurden in diesem Versatz
     *     geschrieben; ohne die Zeile verschöbe der Import jeden TIMESTAMP.
     *     Ein `SET time_zone` im Dump (Framework N66) gilt danach.
     *   - KEIN ensureSchemaUpToDate - mitten im Import wäre eine Migration
     *     das Letzte, was man will.
     *
     * Folgeissue im Framework: Database::neueVerbindung() im Kern, dann
     * hierauf umstellen - der Nachbau hier kann vom Kern abweichen.
     */
    private function importVerbindung(): PDO {
        $host = (string) \DB_HOST;
        $port = defined('DB_PORT') ? (string) \constant('DB_PORT') : '3306';
        $dsn = str_starts_with($host, '/')
            ? 'mysql:unix_socket=' . $host . ';dbname=' . \DB_NAME . ';charset=utf8mb4'
            : 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . \DB_NAME . ';charset=utf8mb4';
        $optionen = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
        ];
        if (defined('DB_SSL') && \constant('DB_SSL')) {
            if (defined('DB_SSL_CA') && !empty(\constant('DB_SSL_CA'))) {
                $optionen[\Pdo\Mysql::ATTR_SSL_CA] = \constant('DB_SSL_CA');
            }
            $optionen[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = defined('DB_SSL_VERIFY') && \constant('DB_SSL_VERIFY');
        }
        $db = new PDO($dsn, (string) \DB_USER, (string) \DB_PASS, $optionen);

        $modus = (string) $db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $bereinigt = array_filter(
            array_map('trim', explode(',', $modus)),
            static fn(string $m): bool => $m !== '' && !in_array(strtoupper($m), ['NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES', 'ANSI'], true)
        );
        $db->prepare('SET SESSION sql_mode = ?')->execute([implode(',', $bereinigt)]);
        $db->prepare('SET time_zone = ?')->execute([(new \DateTimeImmutable('now'))->format('P')]);
        return $db;
    }

    /**
     * Spielt einen Dump streamend ein: Blöcke zu 512 KiB durch den Prüfer,
     * dessen Anweisungen durch den DumpAusfuehrer auf $db. Der
     * Speicherbedarf bleibt unabhängig von der Größe des Dumps.
     */
    private function spieleDumpEin(string $pfad, PDO $db, DumpPruefer $pruefer, int $sammelGrenze): void {
        $fh = function_exists('gzopen') ? gzopen($pfad, 'rb') : fopen($pfad, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Dump nicht lesbar: ' . basename($pfad));
        }
        try {
            $strom = (static function () use ($fh, $pruefer): \Generator {
                while (true) {
                    $chunk = function_exists('gzread') ? gzread($fh, 524288) : fread($fh, 524288);
                    if ($chunk === false) {
                        throw new \RuntimeException('Lesefehler im Dump.');
                    }
                    if ($chunk === '') {
                        break;
                    }
                    yield from $pruefer->zufuehren($chunk);
                }
                yield from $pruefer->abschliessen();
            })();
            $ausfuehrer = new DumpAusfuehrer(static function (string $sql) use ($db): void {
                $db->exec($sql);
            }, $sammelGrenze);
            $ausfuehrer->ausfuehren($strom);
        } finally {
            function_exists('gzclose') ? gzclose($fh) : fclose($fh);
        }
    }

    /**
     * Prüft die eben geschriebene Sicherung, ohne sie auszuführen: Sie muss
     * vollständig durch den DumpPruefer laufen (vertrauenswürdig - jede
     * Tabelle zählt, aber Positivliste und Paketgrenze gelten), und bei gzip
     * muss der Trailer (CRC32 und Länge) zum gelesenen Inhalt passen. gzread()
     * bemerkt ein abgeschnittenes Ende sonst nicht (Framework D26).
     */
    private function pruefeSicherung(string $pfad, int $paketGrenze): void {
        $pruefer = new DumpPruefer($paketGrenze);
        $gz = str_ends_with($pfad, '.gz');
        $fh = $gz ? gzopen($pfad, 'rb') : fopen($pfad, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Sicherung nicht lesbar.');
        }
        $crc = hash_init('crc32b');
        $laenge = 0;
        try {
            while (true) {
                $chunk = $gz ? gzread($fh, 524288) : fread($fh, 524288);
                if ($chunk === false) {
                    throw new \RuntimeException('Lesefehler in der Sicherung.');
                }
                if ($chunk === '') {
                    break;
                }
                hash_update($crc, $chunk);
                $laenge += strlen($chunk);
                foreach ($pruefer->zufuehren($chunk) as $_) {
                }
            }
            foreach ($pruefer->abschliessen() as $_) {
            }
        } finally {
            $gz ? gzclose($fh) : fclose($fh);
        }
        if (!$gz) {
            return;
        }
        $roh = fopen($pfad, 'rb');
        $kopf = $roh !== false ? fread($roh, 2) : false;
        $trailer = ($roh !== false && fseek($roh, -8, SEEK_END) === 0) ? fread($roh, 8) : false;
        if ($roh !== false) {
            fclose($roh);
        }
        if ($kopf !== "\x1f\x8b" || !is_string($trailer) || strlen($trailer) !== 8) {
            throw new \RuntimeException('Sicherung ist keine vollständige gzip-Datei.');
        }
        $werte = unpack('Vcrc/Vlaenge', $trailer);
        if (sprintf('%08x', $werte['crc']) !== hash_final($crc) || $werte['laenge'] !== ($laenge & 0xFFFFFFFF)) {
            throw new \RuntimeException('Prüfsumme der Sicherung stimmt nicht (Datei abgeschnitten oder beschädigt).');
        }
    }

    /**
     * Löst die Verweise stehenbleibender Zeilen auf ersetzte Tabellen
     * ("trennen", Audit N23) - auf der Importverbindung, unter
     * Wartungsmodus, nach dem Dump. Fremdschlüssel auf eine nullbare Spalte
     * werden NULL, sonst wird die Zeile gelöscht; weiche Verweise nach ihrer
     * Angabe. Ein Fehler führt wie jeder Importfehler zum Rückweg.
     *
     * Beim Löschen verschwinden auch Dateiverweise in Addon-Zeilen; die
     * Dateien selbst bleiben liegen (README).
     *
     * @param array<int, array{kind:string, ziel:string, spalten:array<int, string>, wo:array<string, int|string>, weich:bool, trennen:string}> $risiken
     * @return array<int, string> "tabelle: n" je Verweis
     */
    private function trenneAbhaengige(PDO $db, array $risiken): array {
        $bericht = [];
        foreach ($risiken as $r) {
            [$bedingung, $parameter] = self::verweisBedingung($r['spalten'], $r['wo'], $r['weich']);
            $tabelle = self::bezeichner($r['kind']);
            if ($r['trennen'] === 'loeschen') {
                $stmt = $db->prepare('DELETE FROM ' . $tabelle . ' WHERE ' . $bedingung);
            } else {
                $wert = $r['trennen'] === 'null' ? 'NULL' : '0';
                $setzen = implode(', ', array_map(
                    static fn(string $s): string => self::bezeichner($s) . ' = ' . $wert,
                    $r['spalten']
                ));
                $stmt = $db->prepare('UPDATE ' . $tabelle . ' SET ' . $setzen . ' WHERE ' . $bedingung);
            }
            $stmt->execute($parameter);
            $bericht[] = $r['kind'] . '.' . implode('/', $r['spalten']) . ' ('
                . ($r['trennen'] === 'loeschen' ? 'gelöscht' : 'gelöst') . ': ' . $stmt->rowCount() . ')';
        }
        return $bericht;
    }

    /**
     * Beendet ALLE Sitzungen und entwertet alle API-Schlüssel, nachdem
     * Benutzerkonten ersetzt wurden (Audit M2).
     *
     * Der Kern prüft je Anfrage $_SESSION['session_version'] gegen
     * users.session_version (BaseController::checkAuth) und je API-Aufruf
     * api_keys.issued_session_version gegen denselben Wert
     * (ApiKey::authenticate). Nach dem Import steht in users aber der Stand
     * des ARCHIVS: Eine laufende Sitzung auf Konto 7 lief weiter - jetzt als
     * das Konto 7 der Quelle, bis hin zu dessen Administratorrechten.
     *
     * GREATEST statt eines schlichten +1: Ein Konto des Archivs könnte sonst
     * zufällig genau auf den Wert einer lebenden Sitzung dieser Instanz
     * springen. Über dem bisherigen Höchstwert liegt keine. Der zufällige
     * Zuschlag (einer für alle Zeilen) deckt Sitzungen hart gelöschter Konten
     * ab, deren Werte in MAX() nicht mehr auftauchen. Ein INT-Überlauf ist
     * erst nach weit über 2.000 Importen denkbar.
     *
     * Halb angemeldete Sitzungen (pending_2fa_user_id) sind kein Umweg: Sie
     * brauchen den zweiten Faktor des nun importierten Kontos und übernehmen
     * beim Abschluss die dann aktuelle session_version (AuthController).
     *
     * Die Platzhalter heißen verschieden - mit echten Prepared Statements
     * (EMULATE_PREPARES=false) darf ein Name nicht zweimal vorkommen.
     */
    private function beendeAlleSitzungen(PDO $db, int $maxVorher): void {
        $stmt = $db->prepare('UPDATE users SET session_version = GREATEST(session_version, :max) + 1 + :zufall');
        $stmt->execute(['max' => $maxVorher, 'zufall' => random_int(1, 1000000)]);
    }

    /**
     * Fehlerseite nach gescheitertem Import UND gescheitertem Rückweg.
     *
     * Bewusst schlichtes HTML ohne PluginPage::render(): Das Layout liest
     * Einstellungen und Navigation aus der Datenbank, und die ist gerade halb
     * ersetzt. Die Seite nennt, was jetzt zu tun ist - der Wartungsmodus
     * bleibt aktiv und sperrt auch Administratoren aus.
     */
    private function renderNotfallseite(string $backupPfad, \Throwable $ursache): void {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        // theming-ausnahme: Notfallseite bei halb ersetzter Datenbank - das Layout liest Settings/Navigation aus der DB
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
            . '<title>Datenmigration: Wartungsmodus bleibt aktiv</title></head><body>'
            . '<h1>Import gescheitert - und das Zurückspielen der Sicherung ebenfalls</h1>'
            . '<p>Fehler beim Import: ' . $e($ursache->getMessage()) . '</p>'
            . '<p><strong>Die Datenbank ist in einem unvollständigen Zustand.</strong> Die Instanz bleibt deshalb im '
            . 'Wartungsmodus - für alle, auch für Administratoren -, bis sie von Hand wiederhergestellt ist:</p>'
            . '<ol><li>Die Sicherung einspielen: <code>' . $e($backupPfad) . '</code><br>'
            . 'z. B. <code>gunzip -c ' . $e(basename($backupPfad)) . ' | mysql -u BENUTZER -p DATENBANK</code> '
            . '(bei einer Datei ohne .gz: <code>mysql -u BENUTZER -p DATENBANK &lt; DATEI</code>) oder über '
            . 'phpMyAdmin („Importieren“).</li>'
            . '<li>Danach die Datei <code>' . $e(\App\Service\Maintenance::lockFile()) . '</code> löschen - erst '
            . 'dann ist die Instanz wieder erreichbar.</li></ol>'
            . '<p>Einzelheiten stehen im Audit-Log (Kategorie „security“) und im Serverprotokoll.</p>'
            . '</body></html>';
    }

    /**
     * Führt die Dateien eines Teilarchivs in public/uploads ein, statt das
     * Verzeichnis auszutauschen.
     *
     * Überschriebene Originale werden vorher nach $sicherung verschoben (mit
     * ihrem relativen Pfad). Ein Teilimport soll keine Datei vernichten, die
     * nur die Zielinstanz kannte - und für die überschriebenen gilt dasselbe
     * wie für die Datenbank: Es muss einen Rückweg geben.
     *
     * @return array{neu:int, ersetzt:int}
     */
    private function mergeUploads(string $quelle, string $ziel, string $sicherung): array {
        $bilanz = ['neu' => 0, 'ersetzt' => 0];
        if (!is_dir($quelle)) {
            return $bilanz;
        }
        if (!is_dir($ziel) && !mkdir($ziel, 0755, true) && !is_dir($ziel)) {
            throw new \RuntimeException("Upload-Verzeichnis konnte nicht angelegt werden: {$ziel}");
        }

        $lauf = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($quelle, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($lauf as $eintrag) {
            $rel = $lauf->getSubPathname();
            $zielPfad = $ziel . '/' . $rel;
            if ($eintrag->isDir()) {
                if (!is_dir($zielPfad) && !mkdir($zielPfad, 0755, true) && !is_dir($zielPfad)) {
                    throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$zielPfad}");
                }
                continue;
            }
            if (is_file($zielPfad)) {
                $sicherungsPfad = $sicherung . '/' . $rel;
                if (!is_dir(dirname($sicherungsPfad)) && !mkdir(dirname($sicherungsPfad), 0750, true)
                    && !is_dir(dirname($sicherungsPfad))) {
                    throw new \RuntimeException("Sicherungsverzeichnis nicht anlegbar: {$sicherungsPfad}");
                }
                if (!@rename($zielPfad, $sicherungsPfad) && !copy($zielPfad, $sicherungsPfad)) {
                    throw new \RuntimeException("Original nicht sicherbar: {$zielPfad}");
                }
                $bilanz['ersetzt']++;
            } else {
                if (!is_dir(dirname($zielPfad)) && !mkdir(dirname($zielPfad), 0755, true) && !is_dir(dirname($zielPfad))) {
                    throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$zielPfad}");
                }
                $bilanz['neu']++;
            }
            if (!copy($eintrag->getPathname(), $zielPfad)) {
                throw new \RuntimeException("Datei konnte nicht übernommen werden: {$zielPfad}");
            }
        }
        return $bilanz;
    }

    /**
     * Schreibt die Schutzdateien unter public/uploads neu, wo sie fehlen -
     * unabhängig davon, was im Archiv stand (Audit N1).
     *
     * Der Verzeichnistausch ersetzt public/uploads VOLLSTÄNDIG, also auch die
     * dort mitgelieferten .htaccess-Dateien des Kerns: die im Wurzelverzeichnis
     * (kein PHP) und seit Kern 0.8 die unter horses/ (keine statische
     * Auslieferung von Pferdefotos, Framework#366). Aus dem Archiv kommen sie
     * nie - Steuerdateien werden beim Einlesen verworfen.
     *
     * Vorlage ist, wo vorhanden, die Datei aus public/uploads.import-alt -
     * also der Stand DIESER Instanz vor dem Import (reguläre Datei, kein
     * Symlink) -, sonst die eingebaute Mindestfassung. Vorhandene Dateien
     * werden nie überschrieben. Ein Fehlschlag bricht nichts mehr ab (der
     * Import ist durch), wird aber gemeldet.
     *
     * @return array<int, string> Probleme (leer = alles steht)
     */
    private function restoreUploadsProtection(): array {
        $probleme = [];
        foreach (self::SCHUTZDATEIEN as $rel => $fassung) {
            $ziel = $this->uploadsDir() . '/' . $rel;
            if (is_file($ziel)) {
                continue;
            }
            $verzeichnis = dirname($ziel);
            if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0755, true) && !is_dir($verzeichnis)) {
                $probleme[] = "public/uploads/{$rel}: Verzeichnis nicht anlegbar";
                error_log("Datenmigration: Schutzdatei public/uploads/{$rel} nicht wiederhergestellt (Verzeichnis)");
                continue;
            }
            $vorlage = $this->uploadsDir() . '.import-alt/' . $rel;
            $inhalt = is_file($vorlage) && !is_link($vorlage) ? @file_get_contents($vorlage) : false;
            if ($inhalt === false) {
                $inhalt = $fassung;
            }
            if (@file_put_contents($ziel, $inhalt) !== strlen($inhalt)) {
                $probleme[] = "public/uploads/{$rel}: nicht schreibbar";
                error_log("Datenmigration: Schutzdatei public/uploads/{$rel} nicht wiederhergestellt");
            }
        }
        return $probleme;
    }

    /**
     * Ersetzt den INHALT von $ziel durch den von $quelle - dateiweise, das
     * Verzeichnis selbst bleibt stehen (Audit M26).
     *
     * storage/horses ist im Docker-Setup des Kerns ein eigenes Volume
     * (horses_data): rename() des Verzeichnisses scheiterte am Mountpoint,
     * und eine Kopie daneben läge im Container-Dateisystem und wäre nach
     * einem Neustart weg. Deshalb Datei für Datei (rename, sonst copy +
     * unlink), und jedes überschriebene oder entfernte Original wandert
     * vorher nach $sicherung - das ist der Rückweg.
     *
     * $vollstaendig: Zusätzlich wandern Dateien, die nur das Ziel hat, in die
     * Sicherung - der Inhalt entspricht danach dem Archiv. Punktdateien
     * (.gitkeep) bleiben stehen; das Archiv bringt keine mit.
     *
     * @return array{neu:int, ersetzt:int, entfernt:int}
     */
    private function ersetzeInhalt(string $quelle, string $ziel, string $sicherung, bool $vollstaendig): array {
        $bilanz = ['neu' => 0, 'ersetzt' => 0, 'entfernt' => 0];
        if (!is_dir($ziel) && !mkdir($ziel, 0755, true) && !is_dir($ziel)) {
            throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$ziel}");
        }
        $neu = $this->collectFiles($quelle);

        if ($vollstaendig) {
            foreach ($this->collectFiles($ziel, true) as $rel => $abs) {
                if (!isset($neu[$rel])) {
                    self::verschiebe($abs, $sicherung . '/' . $rel);
                    $bilanz['entfernt']++;
                }
            }
        }
        foreach ($neu as $rel => $abs) {
            $zielPfad = $ziel . '/' . $rel;
            if (is_file($zielPfad)) {
                self::verschiebe($zielPfad, $sicherung . '/' . $rel);
                $bilanz['ersetzt']++;
            } else {
                $bilanz['neu']++;
            }
            self::verschiebe($abs, $zielPfad);
        }
        return $bilanz;
    }

    /** rename(), über Dateisystemgrenzen hinweg copy + unlink. */
    private static function verschiebe(string $von, string $nach): void {
        $verzeichnis = dirname($nach);
        if (!is_dir($verzeichnis) && !mkdir($verzeichnis, 0750, true) && !is_dir($verzeichnis)) {
            throw new \RuntimeException("Verzeichnis nicht anlegbar: {$verzeichnis}");
        }
        if (@rename($von, $nach)) {
            return;
        }
        if (!copy($von, $nach) || !unlink($von)) {
            throw new \RuntimeException("Datei nicht verschiebbar: {$von}");
        }
    }

    /**
     * Gleicht die Zugangsdaten an den APP_KEY dieser Instanz an (Audit M25) -
     * auf der Importverbindung, unter Wartungsmodus, nach dem Dump. Nur bei
     * abweichendem Schlüssel.
     *
     * Angefasst wird ein Wert nur, wenn er wie ein Crypto-Chiffrat aussieht
     * und sich hier tatsächlich NICHT entschlüsseln lässt
     * (istFremdesChiffrat()). Damit kann weder ein manipuliertes Manifest
     * eine Klartext-Einstellung wie site_name leeren - "nicht
     * entschlüsselbar" allein träfe jeden Klartext -, noch eine
     * geheimnisse.json einen lesbaren Wert überschreiben oder einem Konto
     * ohne TOTP eines unterschieben.
     *
     *   settings mit Exportpasswort: mit dem APP_KEY des Ziels neu verschlüsselt.
     *   settings ohne:               geleert (nach ausdrücklicher Zustimmung).
     *   users.totp_secret mit:       neu verschlüsselt.
     *   users.totp_secret ohne:      bleibt stehen - Leeren schaltete den
     *                                zweiten Faktor still ab (fail-open);
     *                                unlesbar sperrt er dagegen, bis
     *                                Backup-Code oder Admin-Reset helfen.
     *   user_passkeys:               bleiben stehen (Handle = HMAC(APP_KEY)),
     *                                werden nur gezählt - aus demselben Grund.
     *
     * @param array{lage:string, settings:array<int, string>, totp:int, passkeys:int, geheimnisse:bool, betroffen:bool} $bedarf
     * @param array{settings: array<string, string>, users_totp: array<int, string>}|null $geheimnisse
     * @return array<int, string> Bericht fürs Audit
     */
    private function schluesselAngleichen(PDO $db, array $bedarf, ?array $geheimnisse): array {
        if ($bedarf['lage'] !== 'abweichend' || !$bedarf['betroffen']) {
            return [];
        }
        $lesen = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $setzen = $db->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $unlesbar = static fn(mixed $wert): bool => self::istFremdesChiffrat($wert);

        $neu = 0;
        $geleert = 0;
        $namen = $bedarf['settings'];
        if ($geheimnisse !== null) {
            foreach ($geheimnisse['settings'] as $name => $klar) {
                if (!in_array($name, $namen, true)) {
                    continue; // nur, was der Dump tatsächlich ersetzt hat
                }
                $lesen->execute([$name]);
                if ($unlesbar($lesen->fetchColumn())) {
                    $setzen->execute([Crypto::encrypt($klar), $name]);
                    $neu++;
                }
            }
        } else {
            foreach ($namen as $name) {
                $lesen->execute([$name]);
                if ($unlesbar($lesen->fetchColumn())) {
                    $setzen->execute(['', $name]);
                    $geleert++;
                }
            }
        }

        $totpNeu = 0;
        if ($geheimnisse !== null && $bedarf['totp'] > 0) {
            $totpLesen = $db->prepare('SELECT totp_secret FROM users WHERE id = ?');
            $totpSetzen = $db->prepare('UPDATE users SET totp_secret = ? WHERE id = ?');
            foreach ($geheimnisse['users_totp'] as $id => $klar) {
                $totpLesen->execute([$id]);
                if ($unlesbar($totpLesen->fetchColumn())) {
                    $totpSetzen->execute([Crypto::encrypt($klar), $id]);
                    $totpNeu++;
                }
            }
        }

        $bericht = [];
        if ($neu > 0 || $totpNeu > 0) {
            $bericht[] = 'neu verschlüsselt: ' . $neu . ' Einstellung(en), ' . $totpNeu . ' TOTP-Geheimnis(se)';
        }
        if ($geleert > 0) {
            $bericht[] = 'geleert: ' . $geleert . ' Einstellung(en)';
        }
        if ($geheimnisse === null && $bedarf['totp'] > 0) {
            $bericht[] = $bedarf['totp'] . ' TOTP-Geheimnis(se) unlesbar stehen gelassen';
        }
        if ($bedarf['passkeys'] > 0) {
            $bericht[] = $bedarf['passkeys'] . ' Passkey(s) betroffen (neu zu registrieren)';
        }
        return $bericht;
    }

    /**
     * Ein Wert, der wie ein Crypto-Chiffrat aussieht - striktes base64 und
     * mindestens IV + Tag lang (12 + 16 Byte) -, sich mit dem APP_KEY dieser
     * Instanz aber nicht entschlüsseln lässt. Klartext (Seitenname, Farben,
     * ein TOTP-Altwert in base32 von üblicher Länge) fällt nicht darunter.
     */
    private static function istFremdesChiffrat(mixed $wert): bool {
        if (!is_string($wert) || $wert === '') {
            return false;
        }
        $roh = base64_decode($wert, true);
        return $roh !== false && strlen($roh) >= 28 && Crypto::decrypt($wert) === null;
    }

    /**
     * Wie viele eingespielte Werte sind fremde Chiffrate (siehe
     * istFremdesChiffrat())? Nur ersetzte Tabellen (settings, users).
     * Gezählt wird nur - geleert wird hier nichts; die Zahl steht im Audit
     * und in der Abschlussmeldung.
     */
    private function zaehleUnlesbare(PDO $db, DumpBefund $befund): int {
        $ersetzt = array_flip($befund->ersetzt());
        $werte = [];
        try {
            if (isset($ersetzt['settings'])) {
                $werte = array_merge($werte, $db->query('SELECT setting_value FROM settings')->fetchAll(PDO::FETCH_COLUMN));
            }
            if (isset($ersetzt['users'])) {
                $werte = array_merge($werte, $db->query('SELECT totp_secret FROM users WHERE totp_secret IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
            }
            return count(array_filter($werte, static fn(mixed $w): bool => self::istFremdesChiffrat($w)));
        } catch (\Throwable $e) {
            // Ohne APP_KEY (Crypto wirft) oder ohne Tabelle: nichts zu zählen.
            return 0;
        }
    }

    /**
     * Spielt den unmittelbar vor dem Import geschriebenen Sicherungs-Dump
     * zurück - streamend, geprüft und über eine NEUE Importverbindung (die
     * des gescheiterten Imports kann tot sein). Meldet Erfolg oder
     * Fehlschlag; bis 1.1.0 lief das als ein einziges Paket über die
     * App-Verbindung, scheiterte bei großen Instanzen an max_allowed_packet,
     * und die Meldung behauptete trotzdem "zurückgespielt".
     *
     * Wirft NICHT weiter: Der Aufrufer meldet dem Benutzer den ursprünglichen
     * Fehler, und ein zweiter Fehler beim Zurückrollen darf die Meldung
     * nicht verdrängen - er gehört ins Protokoll, weil dann Handarbeit nötig
     * ist (siehe Wartung::nachFehlschlag()).
     */
    private function rueckwegEinspielen(string $backupName, \Throwable $ursache): bool {
        $pfad = $this->stageDir() . '/' . $backupName;

        try {
            if (!is_file($pfad)) {
                throw new \RuntimeException("Sicherungs-Dump nicht gefunden: {$backupName}");
            }
            $db = $this->importVerbindung();
            $grenze = self::paketGrenze($db);
            $this->spieleDumpEin($pfad, $db, new DumpPruefer($grenze), self::sammelGrenze($grenze));
        } catch (\Throwable $e) {
            error_log('Datenmigration: Rollback fehlgeschlagen - ' . $e->getMessage());
            try {
                AuditLogger::log(
                    'Datenmigration: Zurückrollen FEHLGESCHLAGEN',
                    'security',
                    'Import scheiterte (' . $ursache->getMessage() . '), das Zurückspielen von '
                    . $backupName . ' ebenfalls (' . $e->getMessage() . ') - die Datenbank ist in einem '
                    . 'unvollständigen Zustand und muss von Hand aus var/datenmigration/' . $backupName
                    . ' wiederhergestellt werden. Der Wartungsmodus bleibt bis dahin aktiv.'
                );
            } catch (\Throwable $auchDas) {
                // Die Datenbank ist halb ersetzt - das Serverprotokoll oben
                // ist dann der einzige Weg, der sicher ankommt.
            }
            return false;
        }

        try {
            PluginAudit::log(
                'datenmigration',
                'Import zurückgerollt',
                $backupName,
                'Grund: ' . $ursache->getMessage() . ' - Sicherung eingespielt'
            );
        } catch (\Throwable $e) {
            error_log('Datenmigration: Rückweg gelungen, Audit-Eintrag nicht - ' . $e->getMessage());
        }
        return true;
    }

    /** Rückfall für rename() über Dateisystemgrenzen hinweg. */
    private function copyDir(string $from, string $to): void {
        if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
            throw new \RuntimeException("Zielverzeichnis konnte nicht angelegt werden: {$to}");
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $ziel = $to . '/' . $it->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($ziel) && !mkdir($ziel, 0755, true) && !is_dir($ziel)) {
                    throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$ziel}");
                }
                continue;
            }
            if (!copy($item->getPathname(), $ziel)) {
                throw new \RuntimeException("Datei konnte nicht kopiert werden: {$ziel}");
            }
        }
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function fail(string $message): void {
        $content = '<div class="card"><h1>📦 Datenmigration</h1><p class="alert alert-error">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><p><a href="/plugin/datenmigration/uebersicht">Zurück</a></p></div>';
        PluginPage::render('Datenmigration', $content);
    }
}
