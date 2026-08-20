<?php
// kontaktanfrage/Plugin.php
//
// Addon für Hengstverzeichnis_Framework, löst Addons#106: Ein Besucher kann
// einen Kontakt des Verzeichnisses anschreiben, OHNE dessen Adresse zu sehen.
// Das Formular fragt genau drei Angaben ab - E-Mail, Name und einen Grund aus
// fester Auswahl.
//
// Die drei tragenden Entscheidungen, die man dem Code sonst nicht ansieht:
//
//  1. Zugestellt wird IMMER an eine im Addon hinterlegte Team-Adresse, nie
//     direkt an den angefragten Kontakt. Das Team prüft und fragt nach, ob
//     Kontakt überhaupt gewünscht ist. Ein Formular, das ungeprüft an eine
//     fremde Adresse zustellt, ist ein Spam-Relais mit dem Verzeichnis als
//     Adressbuch.
//  2. Der Grund kommt aus einer festen Liste, es gibt KEIN Freitextfeld. Nur
//     so lässt sich die Nachricht serverseitig zusammensetzen; vom Absender
//     kommt ausschließlich, was geprüft werden kann. Wer später "nur ein
//     kleines Bemerkungsfeld" ergänzt, gibt genau diesen Schutz auf.
//  3. Opt-out statt Opt-in: Kontaktanfragen sind erlaubt und lassen sich je
//     Datensatz abschalten. Das Kennzeichen gehört dem ADDON (eigene
//     Tabelle) - der Kern bekommt dafür keine Spalte, gepflegt wird es über
//     contact.edit_sections.
//
// UMSTELLUNG AUF DIE KONTAKTLISTE (Addons#136, Kern-#336). Bis v0.7 führte
// dieses Addon mit `target_type = 'person'|'station'` einen EIGENEN
// Diskriminator - also genau die Unterscheidung, die der Kern mit der
// Zusammenführung von `persons` und `breeding_stations` zu `contacts`
// abgeschafft hat. Er ist ersatzlos entfallen; gespeichert wird nur noch eine
// `contact_id`.
//
// Das war nicht nur eine Umbenennung: Person 5 und Station 5 gab es BEIDE,
// und die beiden Tabellen dieses Addons speichern ihr Ziel ohne
// Fremdschlüssel (siehe den Kommentar an install()). Ohne Umrechnung über
// `contact_id_map` zeigte nach der Zusammenführung JEDE gespeicherte Zeile
// auf einen falschen Kontakt. Beim Opt-out wäre das eine Datenschutzpanne im
// Wortsinn - wer Kontaktanfragen abbestellt hat, wäre wieder erreichbar, und
// jemand anderes wäre ohne sein Zutun stumm geschaltet. Die Umrechnung steht
// deshalb in Uebernahme und läuft genau einmal.
//
// Installation (lokal im Framework-Repo):
//   cp -r kontaktanfrage plugins/kontaktanfrage
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// unter Admin -> Kontaktanfragen die Team-Adresse hinterlegen.

namespace Plugin\Kontaktanfrage;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;
use App\Router;
use App\Security\Captcha;
use App\Security\CaptchaContext;
use App\Security\ClientIp;
use App\Security\RateLimiter;
use App\Service\Mailer;
use App\Service\Scheduler;
use PDO;

class Plugin {

    /** Der eigene Slug - Kategorie jedes Protokolleintrags (Kern-#352). */
    public const SLUG = 'kontaktanfrage';

    public function register(HookManager $hooks): void {
        // NUR die contact.*-Hooks, und das ist wichtig (Addons#136): Der Kern
        // löst person.* und station.* bis v0.9.0 zusätzlich als Alias aus,
        // KASKADIEREND auf dem Ergebnis des vorherigen. Dieses Addon hatte
        // beide alten Paare registriert - seit Personen und Deckstationen
        // EINE Tabelle sind, ist das derselbe Datensatz, und das Formular
        // erschiene zweimal auf derselben Seite. Wer hier einen Alias
        // "sicherheitshalber" wieder hinzufügt, baut genau diese Doppelung.
        $hooks->addFilter('contact.detail_sections', [$this, 'kontaktAbschnitt']);
        $hooks->addFilter('contact.edit_sections', [$this, 'kontaktOptOut']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'dashboardKachel']);

        // Aufbewahrungsfrist (DSGVO): Die eingegebene E-Mail-Adresse ist ein
        // personenbezogenes Datum, sie darf nicht unbegrenzt liegenbleiben.
        // Registriert wird nur die Aufgabe - ausgeführt wird sie erst, wenn
        // der Cron-Lauf des Kerns (/admin/cron bzw. CronController) sie als
        // fällig erkennt.
        Scheduler::register('kontaktanfrage.aufraeumen', 86400, static function (): void {
            Aufraeumen::faellige();
        });
    }

    /**
     * Meldet das öffentliche Formular beim Spam-Schutz des Kerns an
     * (Kern-#351). Erst damit kann der Betreiber unter den Systemeinstellungen
     * je Formular einen Anbieter wählen - vorher war der vorhandene
     * CAPTCHA-Unterbau ausgerechnet für die Formulare unerreichbar, die Spam
     * bekommen, weil die öffentlichen Formulare dieses Systems in Addons
     * liegen.
     *
     * @return array<string, string>
     */
    public function captchaContexts(): array {
        return [Formular::CAPTCHA_KONTEXT => 'Kontaktanfrage an einen Kontakt'];
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei jeder
     * Aktivierung und nach jedem Addon-Update auf - deshalb idempotent, und
     * deshalb steht das DDL hier und nicht in register().
     *
     * Genau diese Wiederholung ist der Grund, warum die Datenübernahme aus
     * Addons#136 einen Marker braucht: Sie rechnet gespeicherte Kennungen um,
     * und ein zweiter Lauf würde bereits umgerechnete Kennungen ein zweites
     * Mal umrechnen. Siehe Uebernahme.
     */
    public function install(): void {
        $db = Database::getInstance();

        // Bewusst kein Fremdschlüssel auf `contacts`: Eine Anfrage ist ein
        // Vorgang und soll den Datensatz überleben, auf den sie sich bezog -
        // ein FK mit CASCADE löschte sie mit, ein FK ohne CASCADE verhinderte
        // das Löschen des Kontakts. Verwaiste Zeilen räumt
        // Aufraeumen::faellige() weg, die Verwaltung zeigt sie bis dahin als
        // "Datensatz entfernt".
        //
        // contact_id trägt DEFAULT 0 statt NULL, weil die Übernahme aus
        // Addons#136 genau diesen Wert für Anfragen an einen inzwischen
        // endgültig gelöschten Datensatz vergibt - "kein Kontakt mehr" ist
        // hier eine Aussage, kein fehlender Wert.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_kontaktanfrage_requests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `contact_id` INT NOT NULL DEFAULT 0,
                `reason_key` VARCHAR(64) NOT NULL,
                `reason_label` VARCHAR(100) NOT NULL,
                `requester_name` VARCHAR(150) NOT NULL,
                `requester_email` VARCHAR(150) NOT NULL,
                `team_notified` TINYINT(1) NOT NULL DEFAULT 0,
                `forwarded_at` DATETIME NULL DEFAULT NULL,
                `forwarded_by` VARCHAR(100) NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_ka_kontakt` (`contact_id`),
                INDEX `idx_ka_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Das Opt-out ist die ANWESENHEIT einer Zeile. Kein Wahrheitswert, den
        // jemand auf 0 setzt: Der Normalfall (Anfragen erlaubt) erzeugt damit
        // gar keine Daten, und ein "wieder erlauben" hinterlässt keine Zeile,
        // die später jemand für ein Opt-out hält.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_kontaktanfrage_optout` (
                `contact_id` INT NOT NULL PRIMARY KEY,
                `disabled_by` VARCHAR(100) NULL DEFAULT NULL,
                `disabled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Eigene Konfigurationstabelle statt der Kern-Tabelle `settings`:
        // deren Schlüsselspalte ist VARCHAR(50), und die Systemeinstellungen
        // des Kerns sind eine redaktionell gepflegte Oberfläche, in die ein
        // Addon keine Fremdschlüssel streuen sollte.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_kontaktanfrage_config` (
                `config_key` VARCHAR(64) NOT NULL PRIMARY KEY,
                `config_value` TEXT NULL,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Erst NACH dem DDL: Auf einer Neuinstallation legt der Block oben
        // bereits die neue Gestalt an, dann findet die Übernahme nichts vor
        // und setzt nur ihren Marker.
        Uebernahme::einmalig($db);
    }

    /**
     * Kern-#338: Was sich nicht im Manifest aufzählen lässt. Die drei eigenen
     * Tabellen stehen unter "owns" in der plugin.json; hier bleiben die Zeilen
     * übrig, die dieses Addon in KERN-Tabellen hinterlassen hat.
     *
     * Die Protokolleinträge unter der Kategorie `kontaktanfrage` werden
     * ausdrücklich NICHT gelöscht. Sie sind der Nachweis darüber, was mit
     * personenbezogenen Anfragen geschehen ist - wer wann was weitergeleitet
     * und gelöscht hat. Ein Nachweis, den das Deinstallieren des Addons
     * mitnimmt, ist keiner.
     */
    public function uninstall(): void {
        $db = Database::getInstance();

        // Die beiden Mengenzähler des öffentlichen Endpunkts.
        $db->prepare("DELETE FROM login_attempts WHERE type IN ('kontaktanfrage-ip', 'kontaktanfrage-ziel')")
            ->execute();

        // Der Zeitstempel der eigenen Cron-Aufgabe. Der Schlüssel gehört dem
        // Scheduler des Kerns (`cron_last_run__…`) und kann deshalb nicht
        // unter "owns" stehen - dort sind nur Schlüssel mit dem Präfix
        // `plugin_` zugelassen, und das aus gutem Grund.
        $db->prepare('DELETE FROM settings WHERE setting_key = ?')
            ->execute(['cron_last_run__kontaktanfrage.aufraeumen']);

        PluginAudit::log(
            self::SLUG,
            'Addon-Reste aus Kern-Tabellen entfernt',
            'Deinstallation',
            'Mengenzähler und Cron-Zeitstempel gelöscht; Protokolleinträge bleiben bewusst stehen'
        );
    }

    /**
     * Öffentliche Kontaktseite (`/kontakt?id=`). `$kontakt` trägt nur die
     * öffentlichen Spalten - die Adresse des Empfängers steht hier gerade
     * NICHT drin, und genau darum geht es bei diesem Addon.
     *
     * @param array<int, string> $sections
     * @param array<string, mixed> $kontakt
     * @param array<string, mixed> $horsesByRole
     * @param array<int, array<string, mixed>> $stationHorses
     * @return array<int, string>
     */
    public function kontaktAbschnitt(array $sections, array $kontakt, array $horsesByRole = [], array $stationHorses = []): array {
        $html = Formular::bauen($kontakt);
        if ($html !== '') {
            $sections[] = $html;
        }
        return $sections;
    }

    /**
     * @param array<int, string> $sections
     * @param array<string, mixed> $kontakt
     * @return array<int, string>
     */
    public function kontaktOptOut(array $sections, array $kontakt): array {
        $sections[] = Formular::optOutAbschnitt($kontakt);
        return $sections;
    }

    /**
     * @param array<int, array{url:string,label:string,icon:string}> $tiles
     * @return array<int, array{url:string,label:string,icon:string}>
     */
    public function dashboardKachel(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/kontaktanfrage/verwaltung',
            'label' => 'Kontaktanfragen',
            'icon' => '✉️',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'kontaktanfrage',
                'action' => 'manage',
                'label' => 'Anfragen einsehen, weiterleiten und löschen',
                'module_label' => 'Kontaktanfragen',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'POST', 'path' => '/senden', 'callback' => [AnfrageController::class, 'senden']],
            ['method' => 'GET', 'path' => '/verwaltung', 'callback' => [VerwaltungController::class, 'index']],
            ['method' => 'POST', 'path' => '/verwaltung/einstellungen', 'callback' => [VerwaltungController::class, 'einstellungen']],
            ['method' => 'POST', 'path' => '/verwaltung/weiterleiten', 'callback' => [VerwaltungController::class, 'weiterleiten']],
            ['method' => 'POST', 'path' => '/verwaltung/loeschen', 'callback' => [VerwaltungController::class, 'loeschen']],
            ['method' => 'POST', 'path' => '/verwaltung/aufraeumen', 'callback' => [VerwaltungController::class, 'aufraeumen']],
            ['method' => 'POST', 'path' => '/opt-out', 'callback' => [OptOutController::class, 'speichern']],
        ];
    }
}

/**
 * Eingabeprüfung ohne Datenbank und ohne Framework - reine Funktionen, die
 * sich als Unit-Test festnageln lassen (tests/Unit/KontaktanfrageEingabeTest.php).
 */
final class Eingabe {

    /** Passt in requester_email VARCHAR(150). */
    public const EMAIL_MAX = 150;

    private function __construct() {}

    /**
     * Liefert die geprüfte Adresse oder null. Kein bool-Rückgabewert: Der
     * Aufrufer soll mit der GEPRÜFTEN Fassung weiterarbeiten, nicht mit der
     * Eingabe, die er gerade hat prüfen lassen.
     */
    public static function email(string $roh): ?string {
        // CR/LF wird VOR dem Trimmen geprüft, und das ist der ganze Punkt:
        // trim() entfernt einen angehängten Zeilenumbruch und macht die
        // Adresse damit gültig. Eine Adresse, die mit Zeilenumbruch ankommt,
        // ist aber keine Eingabe eines Menschen, sondern der Anlauf, eine
        // zweite Kopfzeile in die Mail zu bekommen (Header-Injection). Der
        // Mailer des Kerns lehnt CR/LF ebenfalls ab - das ist die zweite
        // Verteidigungslinie, nicht die erste.
        if (preg_match('/[\r\n]/', $roh)) {
            return null;
        }

        $wert = trim($roh);
        if ($wert === '' || strlen($wert) > self::EMAIL_MAX) {
            return null;
        }

        $geprueft = filter_var($wert, FILTER_VALIDATE_EMAIL);
        return is_string($geprueft) ? $geprueft : null;
    }

    /**
     * Macht aus beliebiger Eingabe eine einzeilige, gekürzte Zeichenkette:
     * Steuerzeichen raus, Whitespace-Folgen zu einem Leerzeichen, getrimmt.
     *
     * Gebraucht für alles, was in eine Betreffzeile oder eine VARCHAR-Spalte
     * geht. Bei ungültigem UTF-8 liefert preg_replace null - daraus wird hier
     * die leere Zeichenkette, und die weisen die Aufrufer als fehlende Angabe
     * ab. Lieber eine abgelehnte Anfrage als ein halb dekodierter Name in
     * einer Mail.
     */
    public static function einzeilig(string $roh, int $maxLaenge): string {
        $ohneSteuerzeichen = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $roh);
        $zusammengefasst = (string) preg_replace('/\s+/u', ' ', $ohneSteuerzeichen);
        return mb_substr(trim($zusammengefasst), 0, $maxLaenge);
    }
}

/**
 * Die Gründe-Auswahl. Vier Standardgründe im Code, beliebig viele weitere
 * aus den Addon-Einstellungen - aber immer eine ABGESCHLOSSENE Liste, gegen
 * die serverseitig geprüft wird.
 */
final class Gruende {

    /** @var array<string, string> */
    public const STANDARD = [
        'deckanfrage' => 'Deckanfrage',
        'kaufinteresse' => 'Kaufinteresse',
        'abstammung' => 'Frage zur Abstammung',
        'sonstiges' => 'Sonstiges',
    ];

    /** Deckel gegen eine Einstellungsseite, die zur Textdatei wird. */
    public const MAX_ZUSATZ = 30;

    /** Passt in reason_label VARCHAR(100). */
    public const LABEL_MAX = 100;

    private function __construct() {}

    /**
     * Leitet den gespeicherten Schlüssel aus dem Anzeigetext ab. Bewusst
     * deterministisch statt fortlaufend nummeriert: Eine Nummer verschöbe
     * sich, sobald der Admin eine Zeile einfügt oder umsortiert, und alte
     * Anfragen zeigten plötzlich einen anderen Grund. Der Anzeigetext wird
     * zusätzlich in der Anfrage mitgespeichert, damit auch ein später
     * umbenannter Grund lesbar bleibt.
     */
    public static function schluessel(string $label): string {
        $wert = mb_strtolower(Eingabe::einzeilig($label, self::LABEL_MAX));
        $wert = strtr($wert, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'å' => 'aa', 'æ' => 'ae', 'ø' => 'oe',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a',
        ]);
        $wert = (string) preg_replace('/[^a-z0-9]+/', '-', $wert);
        return mb_substr(trim($wert, '-'), 0, 64);
    }

    /**
     * Zerlegt die Admin-Eingabe (ein Grund je Zeile) in Schlüssel => Text.
     *
     * @return array<string, string>
     */
    public static function ausText(string $text): array {
        $gruende = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $zeile) {
            $label = Eingabe::einzeilig($zeile, self::LABEL_MAX);
            if ($label === '') {
                continue;
            }

            $schluessel = self::schluessel($label);
            // Eine Zeile aus reiner Interpunktion ("---") ergibt keinen
            // Schlüssel und wäre sonst ein Grund ohne Bezeichner.
            if ($schluessel === '' || isset($gruende[$schluessel]) || isset(self::STANDARD[$schluessel])) {
                continue;
            }

            $gruende[$schluessel] = $label;
            if (count($gruende) >= self::MAX_ZUSATZ) {
                break;
            }
        }

        return $gruende;
    }

    /**
     * Die vollständige gültige Liste: Standardgründe zuerst, danach die
     * ergänzten. Genau diese Liste ist der Maßstab der serverseitigen Prüfung.
     *
     * @return array<string, string>
     */
    public static function alle(string $zusatzText): array {
        return self::STANDARD + self::ausText($zusatzText);
    }

    /**
     * @param array<string, string> $liste
     */
    public static function istGueltig(string $schluessel, array $liste): bool {
        return $schluessel !== '' && array_key_exists($schluessel, $liste);
    }
}

/**
 * Ziel einer Anfrage: ein Kontakt aus `contacts`.
 *
 * Bis v0.7 stand hier ein Diskriminator `person|station`, aus dem Tabelle,
 * Route und Berechtigungsmodul abgeleitet wurden. Mit Kern-#336 gibt es die
 * Unterscheidung nicht mehr: eine Tabelle, eine öffentliche Route, ein
 * Rechte-Modul. Übrig bleibt eine Kennung - und damit fällt die ganze
 * Zweigleisigkeit weg, samt der vier Abfragevarianten, die sie brauchte.
 *
 * Die Rolle eines Kontakts (Züchter, Besitzer, Deckstation) steht seither an
 * der Zuordnung zum Pferd, nicht am Kontakt selbst. Für dieses Addon ist sie
 * ohne Belang: Anfragen richten sich an den Kontakt, nicht an eine Rolle.
 */
final class Ziel {

    private function __construct() {}

    /** Kern-Modul, dessen Bearbeiten-Recht das Opt-out schützt. */
    public const MODUL = 'contacts';

    /**
     * Die Kontaktkennung aus einer Formulareingabe. Ein Formular, das den
     * Umbau in einem offenen Tab überdauert hat, trägt noch `ziel_typ` und
     * `ziel_id` - es findet hier keine Kennung und landet auf dem Katalog,
     * statt eine alte Personen-Kennung als Kontakt-Kennung zu verwenden. Das
     * wäre ein fremder Datensatz.
     */
    public static function idAusAnfrage(mixed $roh): ?int {
        $id = filter_var($roh, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) ? $id : null;
    }

    public static function oeffentlicherLink(int $id): string {
        return '/kontakt?id=' . $id;
    }

    public static function bearbeitenLink(int $id): string {
        return '/admin/contacts/edit?id=' . $id;
    }

    /**
     * Der Zieldatensatz unter derselben Sichtbarkeitsregel wie die
     * öffentliche Seite (veröffentlicht, nicht im Papierkorb). Anzeige und
     * Verarbeitung müssen dieselbe Regel anwenden - sonst liefe ein direkter
     * POST mit gültigem CSRF-Token an einem bewusst unveröffentlichten
     * Datensatz vorbei.
     *
     * @return array{id:int, name:string, email:?string}|null
     */
    public static function oeffentlich(int $id): ?array {
        return self::laden($id, true);
    }

    /**
     * Wie oben, ohne is_published-Filter - für das Backend und die
     * Weiterleitung: Eine Anfrage bleibt bearbeitbar, auch wenn der
     * Datensatz zwischenzeitlich aus der Veröffentlichung genommen wurde.
     * Ein Datensatz im Papierkorb ist dagegen auch hier keiner mehr.
     *
     * @return array{id:int, name:string, email:?string}|null
     */
    public static function intern(int $id): ?array {
        return self::laden($id, false);
    }

    /**
     * @return array{id:int, name:string, email:?string}|null
     */
    private static function laden(int $id, bool $nurVeroeffentlicht): ?array {
        if ($id < 1) {
            return null;
        }

        // Namentliche Spaltenliste, kein SELECT * - so verlangt es
        // docs/kontaktliste-umstellung.md für jeden Pfad, der `contacts`
        // liest. Seit Personen und Stationen in einer Tabelle liegen, ist die
        // Trennung von öffentlich und intern nur noch ein Feld; was gar nicht
        // erst geladen wird, kann auch niemand versehentlich ausgeben.
        //
        // `email` wird hier bewusst UNABHÄNGIG von contact_public gelesen: Sie
        // wird nirgends angezeigt, sondern ausschließlich als Empfängeradresse
        // der Weiterleitung verwendet - das ist der Zweck dieses Addons. Wer
        // sie ausgibt, hebt genau den Schutz auf, den es herstellt.
        $sql = $nurVeroeffentlicht
            ? 'SELECT id, name, email FROM contacts WHERE id = ? AND deleted_at IS NULL AND is_published = 1'
            : 'SELECT id, name, email FROM contacts WHERE id = ? AND deleted_at IS NULL';

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$zeile) {
            return null;
        }

        $email = Eingabe::email((string) ($zeile['email'] ?? ''));
        return [
            'id' => (int) $zeile['id'],
            'name' => (string) $zeile['name'],
            'email' => $email,
        ];
    }

    /** True, wenn für diesen Kontakt Kontaktanfragen abgeschaltet wurden. */
    public static function abgeschaltet(int $id): bool {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT 1 FROM `plugin_kontaktanfrage_optout` WHERE contact_id = ?'
            );
            $stmt->execute([$id]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            // Tabelle fehlt (Aktivierung läuft gerade): fail-closed, lieber
            // kein Formular als eines, das ein Opt-out übergeht.
            return true;
        }
    }
}

/**
 * Die einmalige Umrechnung der gespeicherten Ziele auf Kontakt-Kennungen
 * (Addons#136, Kern-#336).
 *
 * WARUM DAS NICHT OPTIONAL IST. `plugin_kontaktanfrage_requests` und
 * `plugin_kontaktanfrage_optout` hielten ihr Ziel als (target_type,
 * target_id) - ohne Fremdschlüssel, weil ein Fremdschlüssel nur auf genau
 * eine Tabelle zeigen kann. Person 5 und Station 5 gab es beide. Nach der
 * Zusammenführung behalten Personen ihre Kennung, Deckstationen bekommen neue
 * oberhalb des Personenbestands. Eine nicht umgerechnete Stationszeile zeigt
 * damit nicht ins Leere, sondern auf eine PERSON - also auf einen fremden
 * Menschen. Beim Opt-out heißt das: Der Abbestellende ist wieder erreichbar,
 * und ein Unbeteiligter ist stumm geschaltet.
 *
 * WARUM EIN MARKER. install() läuft bei JEDER Aktivierung und nach jedem
 * Addon-Update erneut. Die Umrechnung ist für sich NICHT wiederholbar: Sie
 * schreibt eine neue Kennung in dieselbe Zeile, und ein zweiter Lauf läse die
 * bereits umgerechnete Kennung wieder als alte Stationskennung und rechnete
 * sie ein zweites Mal um - auf einen dritten, wiederum falschen Kontakt.
 * Deshalb steht der Marker in derselben Transaktion wie die Umrechnung: Ein
 * Abbruch nimmt beides zurück, ein Erfolg hält beides fest. Es gibt keinen
 * Zwischenstand, in dem die Daten umgerechnet sind, der Marker aber fehlt.
 *
 * Das Entfernen der Altspalten läuft danach als eigener Schritt, weil es DDL
 * ist und eine Transaktion in MySQL implizit beendet. Es hängt am Marker,
 * nicht an einem eigenen Zustand - stirbt der Prozess dazwischen, holt der
 * nächste Lauf es nach.
 */
final class Uebernahme {

    /** Schlüssel des Markers in `plugin_kontaktanfrage_config`. */
    public const MARKER = 'uebernahme_336_kontakte';

    private function __construct() {}

    public static function einmalig(PDO $db): void {
        self::umrechnen($db);
        self::altspaltenEntfernen($db);
    }

    private static function umrechnen(PDO $db): void {
        if (self::markerGesetzt($db)) {
            return;
        }

        // Kern noch auf der 0.7-Linie: Es gibt nichts umzurechnen, und der
        // Marker darf NICHT gesetzt werden - sonst gälte die Übernahme als
        // erledigt, bevor sie möglich war. "Konnte nicht" und "war nichts zu
        // tun" sind verschiedene Aussagen.
        if (!self::tabelleExistiert($db, 'contact_id_map')) {
            return;
        }

        $requestsAlt = self::spalteExistiert($db, 'plugin_kontaktanfrage_requests', 'target_type');
        $optoutAlt = self::spalteExistiert($db, 'plugin_kontaktanfrage_optout', 'target_type');

        if (!$requestsAlt && !$optoutAlt) {
            // Neuinstallation oder bereits umgestellt: nichts zu tun, aber der
            // Marker gehört gesetzt, damit später niemand danach sucht.
            self::markerSetzen($db);
            return;
        }

        // Die Zuordnungstabelle ist leer, es gibt aber Altzeilen: Dann lief die
        // Kern-Migration noch nicht durch. Jetzt umzurechnen hieße, jede Zeile
        // als Waise abzuräumen. Also nichts tun und beim nächsten Lauf erneut
        // versuchen.
        $abbildungen = (int) $db->query('SELECT COUNT(*) FROM `contact_id_map`')->fetchColumn();
        if ($abbildungen === 0 && self::altzeilenVorhanden($db, $requestsAlt, $optoutAlt)) {
            return;
        }

        // Die neue Spalte gibt es auf einem Bestand aus v0.7 noch nicht -
        // `CREATE TABLE IF NOT EXISTS` oben hat eine vorhandene Tabelle
        // unangetastet gelassen. DDL vor der Transaktion, weil MySQL sie
        // implizit beendet.
        if ($requestsAlt && !self::spalteExistiert($db, 'plugin_kontaktanfrage_requests', 'contact_id')) {
            $db->exec(
                'ALTER TABLE `plugin_kontaktanfrage_requests`
                 ADD COLUMN `contact_id` INT NOT NULL DEFAULT 0 AFTER `id`,
                 ADD INDEX `idx_ka_kontakt` (`contact_id`)'
            );
        }
        if ($optoutAlt && !self::spalteExistiert($db, 'plugin_kontaktanfrage_optout', 'contact_id')) {
            $db->exec(
                'ALTER TABLE `plugin_kontaktanfrage_optout`
                 ADD COLUMN `contact_id` INT NOT NULL DEFAULT 0 FIRST'
            );
        }

        $db->beginTransaction();
        try {
            $bericht = [];

            if ($requestsAlt) {
                $db->exec(
                    'UPDATE `plugin_kontaktanfrage_requests` r
                     JOIN `contact_id_map` m ON m.old_type = r.target_type AND m.old_id = r.target_id
                     SET r.contact_id = m.contact_id'
                );
                // Anfragen ohne Abbildung bleiben stehen. Ihr Ziel gab es zum
                // Zeitpunkt der Kern-Migration nicht mehr (endgültig gelöscht);
                // contact_id 0 zeigt sie in der Verwaltung weiterhin als
                // "Datensatz entfernt". Sie zu löschen wäre ein stiller
                // Datenverlust an einem Vorgang, der protokolliert gehört.
                $waisen = (int) $db->query(
                    'SELECT COUNT(*) FROM `plugin_kontaktanfrage_requests` WHERE contact_id = 0'
                )->fetchColumn();
                $umgerechnet = (int) $db->query(
                    'SELECT COUNT(*) FROM `plugin_kontaktanfrage_requests` WHERE contact_id > 0'
                )->fetchColumn();
                $bericht[] = "{$umgerechnet} Anfrage(n) auf Kontakt-Kennungen umgerechnet, {$waisen} ohne Zielkontakt";
            }

            if ($optoutAlt) {
                $db->exec(
                    'UPDATE `plugin_kontaktanfrage_optout` o
                     JOIN `contact_id_map` m ON m.old_type = o.target_type AND m.old_id = o.target_id
                     SET o.contact_id = m.contact_id'
                );

                // Opt-outs ohne Abbildung werden entfernt. Anders als bei einer
                // Anfrage gibt es hier nichts aufzubewahren: Ein Opt-out ist
                // eine Aussage ÜBER einen Datensatz, und den gibt es nicht
                // mehr. Aufraeumen::faellige() räumt genau solche Zeilen
                // ohnehin bei jedem Lauf ab.
                $stmt = $db->prepare('DELETE FROM `plugin_kontaktanfrage_optout` WHERE contact_id = 0');
                $stmt->execute();
                $verwaist = $stmt->rowCount();

                // Sicherheitsnetz vor dem neuen Primärschlüssel: Zum Zeitpunkt
                // der Migration ist die Abbildung eineindeutig, zwei Altzeilen
                // können also nicht auf denselben Kontakt fallen. Verlassen
                // wird sich darauf nicht - ein von Hand nachgetragener Eintrag
                // in contact_id_map ließe das ALTER sonst scheitern. Behalten
                // wird die ÄLTESTE Abschaltung: Sie ist die zuerst erklärte,
                // und beim Opt-out ist die Anwesenheit einer Zeile die ganze
                // Aussage.
                $stmt = $db->prepare(
                    'DELETE o FROM `plugin_kontaktanfrage_optout` o
                     JOIN `plugin_kontaktanfrage_optout` k ON k.contact_id = o.contact_id
                        AND (k.disabled_at, k.target_type, k.target_id)
                          < (o.disabled_at, o.target_type, o.target_id)'
                );
                $stmt->execute();
                $doppelt = $stmt->rowCount();

                $verbleibend = (int) $db->query(
                    'SELECT COUNT(*) FROM `plugin_kontaktanfrage_optout`'
                )->fetchColumn();
                $bericht[] = "{$verbleibend} Opt-out(s) übernommen, {$verwaist} ohne Zielkontakt entfernt, {$doppelt} zusammengefallen";
            }

            self::markerSetzen($db);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        // Erst nach dem Commit protokollieren: Ein Eintrag über eine
        // zurückgerollte Umrechnung wäre eine Falschaussage.
        PluginAudit::log(
            Plugin::SLUG,
            'Gespeicherte Ziele auf die Kontaktliste umgerechnet',
            'Addons#136',
            implode('; ', $bericht)
        );
    }

    /**
     * Entfernt die Altspalten, sobald die Umrechnung nachweislich gelaufen
     * ist. Solange sie stehen, liest sie kein Code mehr - sie wegzulassen
     * wäre trotzdem falsch: Ein Diskriminator, den es noch gibt, wird eines
     * Tages wieder benutzt.
     */
    private static function altspaltenEntfernen(PDO $db): void {
        if (!self::markerGesetzt($db)) {
            return;
        }

        if (self::spalteExistiert($db, 'plugin_kontaktanfrage_requests', 'target_type')) {
            $db->exec(
                'ALTER TABLE `plugin_kontaktanfrage_requests`
                 DROP COLUMN `target_type`,
                 DROP COLUMN `target_id`'
            );
        }

        if (self::spalteExistiert($db, 'plugin_kontaktanfrage_optout', 'target_type')) {
            // Der Primärschlüssel hing an den Altspalten und muss deshalb im
            // selben Zug auf contact_id wechseln - eine Tabelle ohne
            // Primärschlüssel zwischendurch gäbe es sonst.
            $db->exec(
                'ALTER TABLE `plugin_kontaktanfrage_optout`
                 DROP PRIMARY KEY,
                 DROP COLUMN `target_type`,
                 DROP COLUMN `target_id`,
                 ADD PRIMARY KEY (`contact_id`)'
            );
        }
    }

    private static function altzeilenVorhanden(PDO $db, bool $requestsAlt, bool $optoutAlt): bool {
        if ($requestsAlt && (int) $db->query('SELECT COUNT(*) FROM `plugin_kontaktanfrage_requests`')->fetchColumn() > 0) {
            return true;
        }
        if ($optoutAlt && (int) $db->query('SELECT COUNT(*) FROM `plugin_kontaktanfrage_optout`')->fetchColumn() > 0) {
            return true;
        }
        return false;
    }

    private static function markerGesetzt(PDO $db): bool {
        $stmt = $db->prepare('SELECT 1 FROM `plugin_kontaktanfrage_config` WHERE config_key = ?');
        $stmt->execute([self::MARKER]);
        return $stmt->fetchColumn() !== false;
    }

    private static function markerSetzen(PDO $db): void {
        $stmt = $db->prepare(
            'INSERT INTO `plugin_kontaktanfrage_config` (config_key, config_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE config_value = config_value'
        );
        $stmt->execute(['k' => self::MARKER, 'v' => date('c')]);
    }

    private static function tabelleExistiert(PDO $db, string $name): bool {
        $stmt = $db->query('SHOW TABLES LIKE ' . $db->quote($name));
        return $stmt !== false && $stmt->rowCount() > 0;
    }

    private static function spalteExistiert(PDO $db, string $tabelle, string $spalte): bool {
        try {
            $stmt = $db->query(
                'SHOW COLUMNS FROM `' . str_replace('`', '``', $tabelle) . '` LIKE ' . $db->quote($spalte)
            );
            return $stmt !== false && $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

/**
 * Addon-Einstellungen in eigener Tabelle. Der Cache lebt genau einen
 * Request - der Formular-Hook fragt sie auf jeder Personen- und
 * Stationsseite ab.
 */
final class Konfiguration {

    public const STANDARD_AUFBEWAHRUNG_TAGE = 180;
    public const MAX_AUFBEWAHRUNG_TAGE = 3650;

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @var array<string, string>|null */
    private static ?array $kern = null;

    private function __construct() {}

    /** @return array<string, string> */
    public static function alle(): array {
        if (self::$cache === null) {
            try {
                $rows = Database::getInstance()
                    ->query('SELECT config_key, config_value FROM `plugin_kontaktanfrage_config`')
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
                self::$cache = is_array($rows) ? $rows : [];
            } catch (\Throwable $e) {
                self::$cache = [];
            }
        }
        return self::$cache;
    }

    /**
     * Die Adresse, an die JEDE Anfrage geht. Ohne sie erscheint kein
     * Formular - eine Anfrage, die nirgends ankommt, ist schlimmer als kein
     * Formular, weil der Besucher sie für zugestellt hält.
     */
    public static function teamAdresse(): ?string {
        return Eingabe::email((string) (self::alle()['team_email'] ?? ''));
    }

    public static function zusatzGruendeText(): string {
        return (string) (self::alle()['zusatz_gruende'] ?? '');
    }

    /** @return array<string, string> */
    public static function gruende(): array {
        return Gruende::alle(self::zusatzGruendeText());
    }

    /** 0 bedeutet: keine automatische Löschung (der Admin räumt von Hand auf). */
    public static function aufbewahrungstage(): int {
        $wert = self::alle()['aufbewahrung_tage'] ?? null;
        if ($wert === null || $wert === '') {
            return self::STANDARD_AUFBEWAHRUNG_TAGE;
        }
        $tage = filter_var($wert, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_AUFBEWAHRUNG_TAGE]]);
        return is_int($tage) ? $tage : self::STANDARD_AUFBEWAHRUNG_TAGE;
    }

    /**
     * Die Kern-Einstellungen, die der Spam-Schutz braucht: die globale
     * Anbieterwahl und die für dieses Formular (Kern-#351,
     * CaptchaContext::settingKey()).
     *
     * Der Hook läuft ohne Controller, es gibt hier also kein
     * BaseController::$settings. Geladen werden deshalb genau diese beiden
     * Schlüssel statt der ganzen Tabelle - der Abschnitt wird auf jeder
     * Kontaktseite gebaut.
     *
     * @return array<string, string>
     */
    public static function kernEinstellungen(): array {
        if (self::$kern === null) {
            try {
                $stmt = Database::getInstance()->prepare(
                    'SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, ?)'
                );
                $stmt->execute(['captcha_provider', CaptchaContext::settingKey(Formular::CAPTCHA_KONTEXT)]);
                $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                self::$kern = is_array($rows) ? $rows : [];
            } catch (\Throwable $e) {
                // Ohne Einstellungen gilt der eingebaute Anbieter - das ist der
                // Rückfallweg, den Captcha::activeProvider() ohnehin nimmt.
                self::$kern = [];
            }
        }
        return self::$kern;
    }

    public static function setzen(string $schluessel, string $wert): void {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO `plugin_kontaktanfrage_config` (config_key, config_value)
             VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE config_value = :v2'
        );
        $stmt->execute(['k' => $schluessel, 'v' => $wert, 'v2' => $wert]);
        self::$cache = null;
    }
}

/**
 * Baut die HTML-Fragmente der beiden Abschnitts-Hooks. Farben ausschließlich
 * über Theme-Variablen, Fremddaten ausschließlich escaped - beide
 * Abschnitte werden vom Kern unescaped ausgegeben.
 */
final class Formular {

    /**
     * Kennung dieses Formulars im Spam-Schutz-Katalog des Kerns (#351). Sie
     * landet in einem Einstellungsschlüssel und in der Oberfläche und ist
     * deshalb bewusst der Slug des Addons - der Betreiber soll die
     * Einstellung dort wiederfinden, wo er das Addon kennt.
     */
    public const CAPTCHA_KONTEXT = 'kontaktanfrage';

    private function __construct() {}

    /**
     * Öffentliches Kontaktformular. Leere Zeichenkette bedeutet "kein
     * Abschnitt" - der Hook fügt dann nichts hinzu.
     *
     * @param array<string, mixed> $ziel Payload des contact.detail_sections-Hooks
     */
    public static function bauen(array $ziel): string {
        $id = isset($ziel['id']) ? (int) $ziel['id'] : 0;
        if ($id < 1 || Konfiguration::teamAdresse() === null || Ziel::abgeschaltet($id)) {
            return '';
        }

        $gruende = Konfiguration::gruende();
        if ($gruende === []) {
            return '';
        }

        $html = '<h3 style="margin-top:0;">✉️ Kontakt aufnehmen</h3>';
        $html .= self::rueckmeldung();
        $html .= '<p style="color:var(--text-muted);font-size:0.9em;">Ihre Anfrage geht an das Team des Verzeichnisses, '
            . 'nicht direkt an diesen Kontakt. Das Team prüft sie und leitet sie weiter, wenn Kontakt gewünscht ist.</p>';

        $html .= '<form method="POST" action="/plugin/kontaktanfrage/senden">';
        $html .= '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="kontakt_id" value="' . $id . '">';

        // Honeypot: für Menschen unsichtbar. Füllt ein Bot es aus, meldet der
        // Controller scheinbar Erfolg und verwirft die Anfrage - ohne dem Bot
        // zu verraten, woran es lag.
        $html .= '<div style="position:absolute;left:-9999px;" aria-hidden="true">'
            . '<label for="kontaktanfrage-webseite">Webseite (bitte leer lassen)</label>'
            . '<input type="text" id="kontaktanfrage-webseite" name="webseite" tabindex="-1" autocomplete="off">'
            . '</div>';

        $html .= '<div class="form-group"><label for="kontaktanfrage-grund">Grund der Anfrage</label>'
            . '<select class="form-control" id="kontaktanfrage-grund" name="grund" required>';
        foreach ($gruende as $schluessel => $label) {
            $html .= '<option value="' . htmlspecialchars($schluessel, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="form-group"><label for="kontaktanfrage-name">Ihr Name</label>'
            . '<input class="form-control" type="text" id="kontaktanfrage-name" name="name" maxlength="150" required></div>';
        $html .= '<div class="form-group"><label for="kontaktanfrage-email">Ihre E-Mail-Adresse</label>'
            . '<input class="form-control" type="email" id="kontaktanfrage-email" name="email" maxlength="150" required></div>';

        // Spam-Schutz des Kerns (#351). Der Honeypot oben fängt den
        // gedankenlosen Formularausfüller, das Rate-Limit die Menge - die
        // Aufgabe hier den Bot, der beides kennt. Welcher Anbieter greift,
        // entscheidet der Betreiber je Formular; ohne Wahl ist es die
        // eingebaute Rechenaufgabe.
        $html .= Captcha::renderField(Konfiguration::kernEinstellungen(), self::CAPTCHA_KONTEXT);

        $html .= '<p style="color:var(--text-muted);font-size:0.85em;">Es gibt bewusst kein Nachrichtenfeld - '
            . 'der Grund aus der Auswahl genügt für die erste Kontaktaufnahme. Name und E-Mail-Adresse werden '
            . 'gespeichert, damit das Team die Anfrage bearbeiten kann.</p>';
        $html .= '<button type="submit" class="btn">Anfrage absenden</button>';
        $html .= '</form>';

        return $html;
    }

    /**
     * Opt-out-Abschnitt im Admin-Bearbeitungsformular. Eigenes Formular mit
     * eigener Route und eigener Berechtigungsprüfung - der Speichern-Knopf
     * des Kerns speichert Plugin-Felder nicht mit (siehe
     * docs/plugin-development.md, Abschnitt zu edit_sections).
     *
     * @param array<string, mixed> $datensatz
     */
    public static function optOutAbschnitt(array $datensatz): string {
        $id = isset($datensatz['id']) ? (int) $datensatz['id'] : 0;
        if ($id < 1) {
            return '';
        }

        $abgeschaltet = Ziel::abgeschaltet($id);

        $html = '<h2 style="margin-top:0;font-size:1.1rem;">✉️ Kontaktanfragen</h2>';
        $html .= '<p style="color:var(--text-muted);font-size:0.9em;">Besucher können über die öffentliche Seite '
            . 'eine Anfrage an das Team stellen, ohne die Adresse dieses Kontakts zu sehen. Das ist die Vorgabe '
            . 'und lässt sich hier für diesen Datensatz abschalten.</p>';

        $html .= '<form method="POST" action="/plugin/kontaktanfrage/opt-out">';
        $html .= '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="kontakt_id" value="' . $id . '">';
        // Verstecktes Feld vor der Checkbox: Eine nicht angehakte Checkbox
        // wird gar nicht übertragen, ohne den Vorgabewert wäre "abschalten"
        // von "Formular ohne dieses Feld" nicht zu unterscheiden.
        $html .= '<input type="hidden" name="erlaubt" value="0">';
        $html .= '<label style="display:flex;gap:0.5rem;align-items:center;">'
            . '<input type="checkbox" name="erlaubt" value="1"' . ($abgeschaltet ? '' : ' checked') . '>'
            . '<span>Kontaktanfragen über das Formular zulassen</span></label>';
        $html .= '<p style="color:var(--text-muted);font-size:0.85em;margin-top:0.5rem;">Änderungen an den Stammdaten oben '
            . 'bitte zuerst speichern - dieser Knopf speichert ausschließlich diese Einstellung.</p>';
        $html .= '<button type="submit" class="btn">Kontaktanfragen-Einstellung übernehmen</button>';
        $html .= '</form>';

        return $html;
    }

    /** Rückmeldung nach dem Absenden, gesteuert über einen festen Satz Werte. */
    private static function rueckmeldung(): string {
        $status = is_string($_GET['kontaktanfrage'] ?? null) ? $_GET['kontaktanfrage'] : '';

        $texte = [
            'erfolg' => ['Ihre Anfrage wurde an das Team übermittelt. Es meldet sich, sobald geklärt ist, ob Kontakt gewünscht ist.', 'success'],
            'fehler' => ['Ihre Anfrage konnte nicht übermittelt werden. Bitte prüfen Sie Ihre Angaben und versuchen Sie es später erneut.', 'danger'],
            'zuviele' => ['Es sind zu viele Anfragen in kurzer Zeit eingegangen. Bitte versuchen Sie es später erneut.', 'danger'],
            // Eigener Status statt "fehler": Wer die Rechenaufgabe falsch
            // beantwortet hat, soll das erfahren und es sofort noch einmal
            // versuchen können. Ein pauschales "hat nicht geklappt" schickt
            // ihn stattdessen auf die Suche nach einem Fehler in seinen
            // Angaben - und der Status verrät nichts über den Empfänger.
            'captcha' => ['Die Rechenaufgabe wurde nicht richtig beantwortet. Bitte lösen Sie die neue Aufgabe und senden Sie noch einmal.', 'danger'],
        ];

        if (!isset($texte[$status])) {
            return '';
        }

        [$text, $art] = $texte[$status];
        return '<p style="color:var(--' . $art . '-fg);background:var(--' . $art . '-soft-bg);padding:0.6rem;'
            . 'border-radius:var(--border-radius, 4px);">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

/**
 * Baut die beiden Nachrichten. Getrennt vom Versand, damit der Text an einer
 * Stelle steht und nicht in zwei Controllern auseinanderdriftet.
 */
final class Nachricht {

    private function __construct() {}

    /** Betreffzeilen sind Kopfzeilen - hier endet jede Mehrzeiligkeit. */
    public static function betreff(string $rohtext): string {
        return Eingabe::einzeilig($rohtext, 150);
    }

    /**
     * @param array{id:int, name:string, email:?string} $ziel
     */
    public static function anTeam(array $ziel, string $grundLabel, string $name, string $email, string $seitenname, string $basisUrl): string {
        $e = static fn(string $wert): string => htmlspecialchars($wert, ENT_QUOTES, 'UTF-8');

        return '<p>Über ' . $e($seitenname) . ' ist eine Kontaktanfrage eingegangen.</p>'
            . '<p><strong>Ziel:</strong> ' . $e($ziel['name']) . '<br>'
            . '<strong>Grund:</strong> ' . $e($grundLabel) . '<br>'
            . '<strong>Name des Anfragenden:</strong> ' . $e($name) . '<br>'
            . '<strong>E-Mail des Anfragenden:</strong> ' . $e($email) . '</p>'
            . '<p>Bitte prüfen und - wenn Kontakt gewünscht ist - weiterleiten: '
            . $e(rtrim($basisUrl, '/') . '/plugin/kontaktanfrage/verwaltung') . '</p>';
    }

    /**
     * @param array{id:int, name:string, email:?string} $ziel
     */
    public static function anEmpfaenger(array $ziel, string $grundLabel, string $name, string $email, string $seitenname): string {
        $e = static fn(string $wert): string => htmlspecialchars($wert, ENT_QUOTES, 'UTF-8');

        return '<p>Hallo ' . $e($ziel['name']) . ',</p>'
            . '<p>über ' . $e($seitenname) . ' ist eine Kontaktanfrage für Sie eingegangen. '
            . 'Das Team hat sie geprüft und leitet sie hiermit weiter.</p>'
            . '<p><strong>Grund:</strong> ' . $e($grundLabel) . '<br>'
            . '<strong>Name:</strong> ' . $e($name) . '<br>'
            . '<strong>E-Mail:</strong> ' . $e($email) . '</p>'
            . '<p>Sie können direkt an die genannte Adresse antworten. Ihre eigene Adresse wurde dem Anfragenden '
            . 'nicht angezeigt. Wenn Sie künftig keine Kontaktanfragen mehr erhalten möchten, sagen Sie dem Team '
            . 'Bescheid - die Anfragen lassen sich für Ihren Datensatz abschalten.</p>';
    }
}

/**
 * Löschroutine der Aufbewahrungsfrist. Läuft über den Scheduler des Kerns
 * und zusätzlich per Knopf in der Verwaltung - dieselbe Methode, damit sich
 * "automatisch" und "von Hand" nicht unterschiedlich verhalten.
 */
final class Aufraeumen {

    private function __construct() {}

    /** @return int Anzahl gelöschter Anfragen */
    public static function faellige(): int {
        $tage = Konfiguration::aufbewahrungstage();
        $db = Database::getInstance();
        $geloescht = 0;

        if ($tage > 0) {
            $stmt = $db->prepare(
                'DELETE FROM `plugin_kontaktanfrage_requests`
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
            );
            $stmt->execute([$tage]);
            $geloescht = $stmt->rowCount();
        }

        // Opt-outs endgültig gelöschter Kontakte. Weich gelöschte bleiben
        // erhalten (die Zeile existiert weiter, deleted_at ist gesetzt) -
        // eine Wiederherstellung soll das Opt-out nicht verlieren.
        //
        // Ein LEFT JOIN auf `contacts` statt zweier auf persons und
        // breeding_stations: Seit #336 ist das eine Tabelle, und der
        // Diskriminator, der die beiden Zweige auseinanderhielt, ist weg.
        $db->exec(
            'DELETE o FROM plugin_kontaktanfrage_optout o
             LEFT JOIN contacts c ON c.id = o.contact_id
             WHERE c.id IS NULL'
        );

        if ($geloescht > 0) {
            PluginAudit::log(
                Plugin::SLUG,
                'Kontaktanfragen nach Aufbewahrungsfrist gelöscht',
                'Aufbewahrungsfrist',
                "{$geloescht} Anfrage(n) älter als {$tage} Tage entfernt"
            );
        }

        return $geloescht;
    }
}

/**
 * Öffentlicher POST-Endpunkt. Bewusst ohne checkAuth() - er ist für anonyme
 * Besucher gedacht, wie das DSGVO-Kontaktformular des Kerns. Der Schutz
 * besteht deshalb aus fünf Hürden: CSRF-Token, Honeypot, Rate-Limit (je IP
 * UND je Empfänger), Spam-Aufgabe (#351) und der abgeschlossenen
 * Gründe-Liste.
 */
class AnfrageController extends BaseController {

    private const MAX_JE_IP = 5;
    private const FENSTER_IP = 3600;
    private const MAX_JE_ZIEL = 10;
    private const FENSTER_ZIEL = 86400;

    public function senden(): void {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = Ziel::idAusAnfrage($_POST['kontakt_id'] ?? null);
        if ($id === null) {
            header('Location: /katalog');
            exit;
        }

        // Honeypot ausgefüllt: verwerfen und Erfolg melden.
        if (!empty($_POST['webseite'])) {
            $this->zurueck($id, 'erfolg');
        }

        $ip = ClientIp::resolve();
        // Seit #336 gibt es nur noch eine Kontaktliste, der Bezeichner braucht
        // also keinen Typ mehr. Das Präfix bleibt trotzdem stehen: Der Zähler
        // liegt in derselben Tabelle wie alle anderen, und eine nackte Zahl
        // als Bezeichner wäre eine Einladung zur Kollision.
        $zielBezeichner = 'kontakt:' . $id;
        // Zwei Zähler, weil sie zwei verschiedene Missbräuche treffen: Der
        // IP-Zähler bremst den einzelnen Absender, der Empfänger-Zähler
        // verhindert, dass ein Kontakt über wechselnde Anschlüsse zugemüllt
        // wird. Nur einer von beiden wäre jeweils leicht zu umgehen.
        if (RateLimiter::tooManyAttempts($ip, 'kontaktanfrage-ip', self::MAX_JE_IP, self::FENSTER_IP)
            || RateLimiter::tooManyAttempts($zielBezeichner, 'kontaktanfrage-ziel', self::MAX_JE_ZIEL, self::FENSTER_ZIEL)) {
            PluginAudit::log(
                Plugin::SLUG,
                'Kontaktanfrage abgewiesen (Rate-Limit)',
                "Kontakt #{$id}",
                'über das öffentliche Formular'
            );
            $this->zurueck($id, 'zuviele');
        }
        RateLimiter::recordAttempt($ip, 'kontaktanfrage-ip');
        RateLimiter::recordAttempt($zielBezeichner, 'kontaktanfrage-ziel');

        // Die Spam-Aufgabe wird NACH der Buchung geprüft, und das ist keine
        // Nachlässigkeit: Der eingebaute Anbieter stellt eine Rechenaufgabe
        // mit rund zwanzig möglichen Antworten. Zählte ein falscher Versuch
        // nicht, könnte ein Bot beliebig oft raten und käme im Schnitt nach
        // wenigen Anläufen durch - die Aufgabe wäre dann Zierde. Genau die
        // Begrenzung der Rateversuche macht sie wirksam. Der Preis ist, dass
        // ein Vertipper einen der fünf Versuche je Stunde kostet; die
        // Rückmeldung sagt deshalb ausdrücklich, woran es lag.
        if (Captcha::verify($this->settings, Formular::CAPTCHA_KONTEXT, $_POST) !== Captcha::OK) {
            $this->zurueck($id, 'captcha');
        }

        $name = Eingabe::einzeilig(is_string($_POST['name'] ?? null) ? $_POST['name'] : '', 150);
        $email = Eingabe::email(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $grundSchluessel = Eingabe::einzeilig(is_string($_POST['grund'] ?? null) ? $_POST['grund'] : '', 64);
        $gruende = Konfiguration::gruende();

        if ($name === '' || $email === null || !Gruende::istGueltig($grundSchluessel, $gruende)) {
            $this->zurueck($id, 'fehler');
        }

        $teamAdresse = Konfiguration::teamAdresse();
        $ziel = Ziel::oeffentlich($id);

        // Fehlender/unveröffentlichter Datensatz, Opt-out oder fehlende
        // Team-Adresse: verwerfen und "erfolg" melden. Der Rückgabestatus darf
        // kein Orakel dafür sein, welche IDs es gibt und wer Anfragen
        // abgeschaltet hat.
        if ($teamAdresse === null || $ziel === null || Ziel::abgeschaltet($id)) {
            $this->zurueck($id, 'erfolg');
        }

        $grundLabel = $gruende[$grundSchluessel];

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO `plugin_kontaktanfrage_requests`
                (contact_id, reason_key, reason_label, requester_name, requester_email)
             VALUES (:id, :grund, :label, :name, :email)'
        );
        $stmt->execute([
            'id' => $id,
            'grund' => $grundSchluessel,
            'label' => $grundLabel,
            'name' => $name,
            'email' => $email,
        ]);
        $anfrageId = (int) $db->lastInsertId();

        $seitenname = (string) ($this->settings['site_name'] ?? 'Hengstverzeichnis');
        $mailer = new Mailer();
        $versendet = $mailer->send(
            $teamAdresse,
            Nachricht::betreff("Kontaktanfrage ({$grundLabel}) - {$ziel['name']}"),
            Nachricht::anTeam($ziel, $grundLabel, $name, $email, $seitenname, $mailer->getBaseUrl())
        );

        if ($versendet) {
            $db->prepare('UPDATE `plugin_kontaktanfrage_requests` SET team_notified = 1 WHERE id = ?')
                ->execute([$anfrageId]);
        }

        // Name und Adresse des Anfragenden gehören NICHT ins Protokoll: Es
        // wird dauerhaft aufbewahrt und von keiner Löschfrist erfasst, die
        // Anfrage selbst dagegen schon (Konfiguration::aufbewahrungstage()).
        // Was hier landete, überlebte die eigene Löschfrist des Addons.
        PluginAudit::log(
            Plugin::SLUG,
            'Kontaktanfrage eingegangen',
            "Kontakt #{$id}",
            "Anfrage #{$anfrageId}, Grund: {$grundSchluessel}, "
                . ($versendet ? 'an Team zugestellt' : 'Zustellung an Team fehlgeschlagen')
        );

        // Fehlgeschlagener Versand ist kein Datenverlust - die Anfrage steht in
        // der Verwaltung und ist dort als unzugestellt erkennbar. Dem Besucher
        // wird das trotzdem als Fehler gemeldet: Er soll nicht auf eine
        // Antwort warten, die vielleicht niemand gesehen hat.
        $this->zurueck($id, $versendet ? 'erfolg' : 'fehler');
    }

    private function zurueck(int $id, string $status): never {
        header('Location: ' . Ziel::oeffentlicherLink($id) . '&kontaktanfrage=' . $status);
        exit;
    }
}

/**
 * Backend: eingegangene Anfragen einsehen, weiterleiten, löschen und die
 * Addon-Einstellungen pflegen. Zugriff über die selbst registrierte
 * Berechtigung kontaktanfrage.manage.
 *
 * Die E-Mail-Adresse des EMPFÄNGERS wird hier bewusst nicht angezeigt -
 * gezeigt wird nur, ob eine hinterlegt ist. Wer Anfragen bearbeiten darf,
 * braucht dafür keinen Blick in die Kontaktdaten; das Recht dazu ist
 * contacts.view und hängt nicht an diesem Addon.
 */
class VerwaltungController extends BaseController {

    private const JE_SEITE = 50;

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('kontaktanfrage', 'manage');
    }

    public function index(): void {
        $db = Database::getInstance();

        $gesamt = (int) $db->query('SELECT COUNT(*) FROM `plugin_kontaktanfrage_requests`')->fetchColumn();
        $seitenzahl = max(1, (int) ceil($gesamt / self::JE_SEITE));
        $seite = min($seitenzahl, self::seiteAusAnfrage());

        // Ein LEFT JOIN statt der beiden von früher: Der Diskriminator, der
        // entschied, welcher der zwei Treffer gilt, ist mit #336 entfallen.
        // LEFT, nicht INNER - eine Anfrage an einen inzwischen gelöschten
        // Kontakt bleibt sichtbar und löschbar, sonst verschwände sie
        // unbemerkt aus der Verwaltung, ohne aus der Tabelle zu sein.
        $stmt = $db->prepare(
            'SELECT r.*, c.name AS kontakt_name, c.email AS kontakt_email, c.deleted_at AS kontakt_deleted
             FROM `plugin_kontaktanfrage_requests` r
             LEFT JOIN contacts c ON c.id = r.contact_id
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', self::JE_SEITE, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($seite - 1) * self::JE_SEITE, PDO::PARAM_INT);
        $stmt->execute();
        $anfragen = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        $inhalt = self::meldung();
        $inhalt .= $this->einstellungsKarte($csrf);
        $inhalt .= $this->anfragenKarte($anfragen, $csrf, $gesamt, $seite, $seitenzahl);

        PluginPage::render('Kontaktanfragen', $inhalt);
    }

    public function einstellungen(): void {
        $this->pruefeCsrf();

        $teamRoh = is_string($_POST['team_email'] ?? null) ? $_POST['team_email'] : '';
        $team = Eingabe::email($teamRoh);
        if ($team === null && trim($teamRoh) !== '') {
            $this->zurueck('adresse-ungueltig');
        }

        $zusatz = is_string($_POST['zusatz_gruende'] ?? null) ? $_POST['zusatz_gruende'] : '';
        // Gespeichert wird die aufbereitete Liste, nicht die Rohreingabe: Was
        // in den Einstellungen steht, ist danach exakt das, was das Formular
        // anbietet - keine stillen Unterschiede zwischen Anzeige und Prüfung.
        $zusatzListe = Gruende::ausText($zusatz);

        $tage = filter_var(
            $_POST['aufbewahrung_tage'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => Konfiguration::MAX_AUFBEWAHRUNG_TAGE]]
        );
        if (!is_int($tage)) {
            $this->zurueck('frist-ungueltig');
        }

        Konfiguration::setzen('team_email', $team ?? '');
        Konfiguration::setzen('zusatz_gruende', implode("\n", array_values($zusatzListe)));
        Konfiguration::setzen('aufbewahrung_tage', (string) $tage);

        PluginAudit::log(
            Plugin::SLUG,
            'Kontaktanfrage-Einstellungen geändert',
            'Addon-Einstellungen',
            'Team-Adresse ' . ($team === null ? 'geleert' : 'gesetzt')
                . ', Zusatzgründe: ' . count($zusatzListe)
                . ', Aufbewahrung: ' . ($tage === 0 ? 'unbegrenzt' : $tage . ' Tage')
        );

        $this->zurueck('gespeichert');
    }

    public function weiterleiten(): void {
        $this->pruefeCsrf();

        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            $this->zurueck('fehler');
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM `plugin_kontaktanfrage_requests` WHERE id = ?');
        $stmt->execute([$id]);
        $anfrage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$anfrage) {
            $this->zurueck('fehler');
        }

        $zielId = (int) $anfrage['contact_id'];

        // Ein nach der Anfrage gesetztes Opt-out gilt auch rückwirkend: Der
        // Kontakt hat erklärt, keine Kontaktanfragen zu wollen, und eine
        // gespeicherte Anfrage ist kein Freibrief, das zu übergehen.
        if (Ziel::abgeschaltet($zielId)) {
            $this->zurueck('opt-out');
        }

        $ziel = Ziel::intern($zielId);
        if ($ziel === null) {
            $this->zurueck('kein-datensatz');
        }
        if ($ziel['email'] === null) {
            $this->zurueck('kein-empfaenger');
        }

        $seitenname = (string) ($this->settings['site_name'] ?? 'Hengstverzeichnis');
        $grundLabel = (string) $anfrage['reason_label'];
        $name = (string) $anfrage['requester_name'];
        $email = (string) $anfrage['requester_email'];

        $versendet = (new Mailer())->send(
            $ziel['email'],
            Nachricht::betreff("Kontaktanfrage über {$seitenname}: {$grundLabel}"),
            Nachricht::anEmpfaenger($ziel, $grundLabel, $name, $email, $seitenname)
        );

        if (!$versendet) {
            PluginAudit::log(
                Plugin::SLUG,
                'Weiterleitung einer Kontaktanfrage fehlgeschlagen',
                "Kontakt #{$zielId}",
                "Anfrage #{$id}"
            );
            $this->zurueck('versand-fehler');
        }

        $db->prepare(
            'UPDATE `plugin_kontaktanfrage_requests`
             SET forwarded_at = NOW(), forwarded_by = :benutzer
             WHERE id = :id'
        )->execute([
            'benutzer' => Eingabe::einzeilig((string) ($_SESSION['username'] ?? 'unbekannt'), 100),
            'id' => $id,
        ]);

        PluginAudit::log(
            Plugin::SLUG,
            'Kontaktanfrage weitergeleitet',
            "Kontakt #{$zielId}",
            "Anfrage #{$id}, Grund: {$anfrage['reason_key']}"
        );

        $this->zurueck('weitergeleitet');
    }

    public function loeschen(): void {
        $this->pruefeCsrf();

        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            $this->zurueck('fehler');
        }

        $stmt = Database::getInstance()->prepare('DELETE FROM `plugin_kontaktanfrage_requests` WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            PluginAudit::log(Plugin::SLUG, 'Kontaktanfrage gelöscht', "Anfrage #{$id}");
        }

        $this->zurueck('geloescht');
    }

    public function aufraeumen(): void {
        $this->pruefeCsrf();

        $anzahl = Aufraeumen::faellige();
        PluginAudit::log(
            Plugin::SLUG,
            'Kontaktanfragen von Hand aufgeräumt',
            'Aufbewahrungsfrist',
            "{$anzahl} Anfrage(n) gelöscht"
        );

        $this->zurueck('aufgeraeumt');
    }

    private function einstellungsKarte(string $csrf): string {
        $team = (string) (Konfiguration::alle()['team_email'] ?? '');
        $zusatz = Konfiguration::zusatzGruendeText();
        $tage = Konfiguration::aufbewahrungstage();

        $html = '<div class="card"><h1>✉️ Kontaktanfragen</h1>';
        $html .= '<p style="color:var(--text-muted);">Anfragen gehen immer an die Team-Adresse, nie direkt an den '
            . 'angefragten Kontakt. Von hier aus leitet das Team sie weiter, wenn Kontakt gewünscht ist.</p>';

        if (Konfiguration::teamAdresse() === null) {
            $html .= '<p style="color:var(--warning-fg);background:var(--warning-soft-bg);padding:0.6rem;'
                . 'border-radius:var(--border-radius, 4px);">Ohne gültige Team-Adresse erscheint auf den '
                . 'öffentlichen Seiten kein Formular.</p>';
        }

        $html .= '<form method="POST" action="/plugin/kontaktanfrage/verwaltung/einstellungen">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrf . '">';

        $html .= '<div class="form-group"><label for="ka-team">Team-Adresse (Empfänger aller Anfragen)</label>'
            . '<input class="form-control" type="email" id="ka-team" name="team_email" maxlength="150" value="'
            . htmlspecialchars($team, ENT_QUOTES, 'UTF-8') . '"></div>';

        $html .= '<div class="form-group"><label for="ka-gruende">Zusätzliche Gründe (einer je Zeile)</label>'
            . '<textarea class="form-control" id="ka-gruende" name="zusatz_gruende" rows="5">'
            . htmlspecialchars($zusatz, ENT_QUOTES, 'UTF-8') . '</textarea>'
            . '<span class="form-hint">Fest eingebaut sind: '
            . htmlspecialchars(implode(', ', Gruende::STANDARD), ENT_QUOTES, 'UTF-8')
            . '. Höchstens ' . Gruende::MAX_ZUSATZ . ' weitere.</span></div>';

        $html .= '<div class="form-group"><label for="ka-frist">Aufbewahrung in Tagen (0 = keine automatische Löschung)</label>'
            . '<input class="form-control" type="number" id="ka-frist" name="aufbewahrung_tage" min="0" max="'
            . Konfiguration::MAX_AUFBEWAHRUNG_TAGE . '" value="' . $tage . '">'
            . '<span class="form-hint">Ältere Anfragen entfernt die Cron-Aufgabe kontaktanfrage.aufraeumen '
            . '(einmal täglich, siehe Admin -> Cron).</span></div>';

        $html .= '<button type="submit" class="btn">Einstellungen speichern</button>';
        $html .= '</form></div>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $anfragen
     */
    private function anfragenKarte(array $anfragen, string $csrf, int $gesamt, int $seite, int $seitenzahl): string {
        $html = '<div class="card" style="margin-top:1.5rem;">';
        $html .= '<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">';
        $html .= '<h2 style="margin:0;">Eingegangene Anfragen (' . $gesamt . ')</h2>';
        $html .= '<form method="POST" action="/plugin/kontaktanfrage/verwaltung/aufraeumen">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<button type="submit" class="btn btn-secondary">Abgelaufene jetzt löschen</button></form>';
        $html .= '</div>';

        if ($anfragen === []) {
            $html .= '<p style="color:var(--text-muted);">Bisher sind keine Anfragen eingegangen.</p></div>';
            return $html;
        }

        $html .= '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;margin-top:1rem;">';
        $html .= '<thead><tr>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Eingegangen</th>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Ziel</th>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Grund</th>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Anfragender</th>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Status</th>'
            . '<th style="text-align:left;padding:0.5rem;border-bottom:1px solid var(--border-color);">Aktion</th>'
            . '</tr></thead><tbody>';

        foreach ($anfragen as $anfrage) {
            $html .= $this->anfrageZeile($anfrage, $csrf);
        }

        $html .= '</tbody></table></div>';

        if ($seitenzahl > 1) {
            $html .= '<p style="margin-top:1rem;">';
            if ($seite > 1) {
                $html .= '<a class="btn btn-secondary" href="/plugin/kontaktanfrage/verwaltung?seite=' . ($seite - 1) . '">&laquo; Zurück</a> ';
            }
            $html .= 'Seite ' . $seite . ' von ' . $seitenzahl;
            if ($seite < $seitenzahl) {
                $html .= ' <a class="btn btn-secondary" href="/plugin/kontaktanfrage/verwaltung?seite=' . ($seite + 1) . '">Weiter &raquo;</a>';
            }
            $html .= '</p>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * @param array<string, mixed> $anfrage
     */
    private function anfrageZeile(array $anfrage, string $csrf): string {
        $e = static fn(mixed $wert): string => htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8');

        $zielId = (int) $anfrage['contact_id'];
        $zielName = $anfrage['kontakt_name'] ?? null;
        $zielEmail = $anfrage['kontakt_email'] ?? null;
        $imPapierkorb = $anfrage['kontakt_deleted'] ?? null;

        // contact_id 0 tragen die Anfragen, deren Ziel schon vor der
        // Umstellung auf die Kontaktliste endgültig gelöscht war
        // (Uebernahme). Sie sehen genauso aus wie eine Anfrage, deren Kontakt
        // seither verschwunden ist - was sie fachlich auch sind.
        $zielZelle = $zielName === null
            ? '<em>Datensatz entfernt</em>'
            : '<a href="' . $e(Ziel::bearbeitenLink($zielId)) . '">' . $e($zielName) . '</a>';
        if ($imPapierkorb !== null) {
            $zielZelle .= ' <span style="color:var(--warning-fg);">(Papierkorb)</span>';
        }

        $status = [];
        $status[] = empty($anfrage['team_notified'])
            ? '<span style="color:var(--danger-fg);">nicht an Team zugestellt</span>'
            : 'an Team zugestellt';
        if (!empty($anfrage['forwarded_at'])) {
            $status[] = '<span style="color:var(--success-fg);">weitergeleitet am '
                . $e(self::zeitpunkt((string) $anfrage['forwarded_at'])) . '</span>';
        }

        $zelle = 'padding:0.5rem;border-bottom:1px solid var(--border-color);vertical-align:top;';

        $html = '<tr>';
        $html .= '<td style="' . $zelle . '">' . $e(self::zeitpunkt((string) $anfrage['created_at'])) . '</td>';
        $html .= '<td style="' . $zelle . '">' . $zielZelle . '</td>';
        $html .= '<td style="' . $zelle . '">' . $e($anfrage['reason_label']) . '</td>';
        $html .= '<td style="' . $zelle . '">' . $e($anfrage['requester_name']) . '<br>'
            . '<a href="mailto:' . $e($anfrage['requester_email']) . '">' . $e($anfrage['requester_email']) . '</a></td>';
        $html .= '<td style="' . $zelle . '">' . implode('<br>', $status) . '</td>';

        $html .= '<td style="' . $zelle . '">';
        if ($zielEmail !== null && $zielName !== null) {
            $beschriftung = empty($anfrage['forwarded_at']) ? 'Weiterleiten' : 'Erneut weiterleiten';
            $html .= '<form method="POST" action="/plugin/kontaktanfrage/verwaltung/weiterleiten" style="display:inline;">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="id" value="' . (int) $anfrage['id'] . '">'
                . '<button type="submit" class="btn">' . $beschriftung . '</button></form> ';
        } else {
            $html .= '<span style="color:var(--text-muted);font-size:0.85em;">keine Adresse hinterlegt</span><br>';
        }
        $html .= '<form method="POST" action="/plugin/kontaktanfrage/verwaltung/loeschen" style="display:inline;">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="id" value="' . (int) $anfrage['id'] . '">'
            . '<button type="submit" class="btn btn-secondary">Löschen</button></form>';
        $html .= '</td></tr>';

        return $html;
    }

    private static function meldung(): string {
        $status = is_string($_GET['ka'] ?? null) ? $_GET['ka'] : '';

        $texte = [
            'gespeichert' => ['Einstellungen gespeichert.', 'success'],
            'weitergeleitet' => ['Anfrage weitergeleitet.', 'success'],
            'geloescht' => ['Anfrage gelöscht.', 'success'],
            'aufgeraeumt' => ['Abgelaufene Anfragen wurden entfernt.', 'success'],
            'adresse-ungueltig' => ['Die Team-Adresse ist keine gültige E-Mail-Adresse.', 'danger'],
            'frist-ungueltig' => ['Die Aufbewahrungsfrist muss eine Zahl von 0 bis ' . Konfiguration::MAX_AUFBEWAHRUNG_TAGE . ' sein.', 'danger'],
            'kein-empfaenger' => ['Für diesen Datensatz ist keine E-Mail-Adresse hinterlegt - die Anfrage lässt sich nicht weiterleiten.', 'danger'],
            'kein-datensatz' => ['Der angefragte Datensatz existiert nicht mehr oder liegt im Papierkorb - es wurde nichts weitergeleitet.', 'danger'],
            'opt-out' => ['Dieser Datensatz hat Kontaktanfragen abgeschaltet - es wurde nichts weitergeleitet.', 'danger'],
            'versand-fehler' => ['Die Weiterleitung konnte nicht versendet werden. Bitte den Mailversand prüfen.', 'danger'],
            'fehler' => ['Die Aktion konnte nicht ausgeführt werden.', 'danger'],
        ];

        if (!isset($texte[$status])) {
            return '';
        }

        [$text, $art] = $texte[$status];
        return '<div class="card" style="color:var(--' . $art . '-fg);background:var(--' . $art . '-soft-bg);">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /**
     * Datumsangaben kommen aus der Datenbank und sind normalerweise lesbar -
     * ein unlesbarer Wert (strtotime liefert dann false) darf trotzdem nicht
     * als 01.01.1970 erscheinen, sondern als das, was er ist.
     */
    private static function zeitpunkt(string $wert): string {
        $stempel = strtotime($wert);
        return $stempel === false ? '—' : date('d.m.Y H:i', $stempel);
    }

    /**
     * Seitennummer aus der Anfrage - validiert, nicht umgedeutet: (int)"3x"
     * wäre 3, filter_var lehnt ab, was keine Zahl IST.
     */
    private static function seiteAusAnfrage(): int {
        $wert = filter_var($_GET['seite'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
        return is_int($wert) ? $wert : 1;
    }

    private function pruefeCsrf(): void {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }
    }

    private function zurueck(string $status): never {
        header('Location: /plugin/kontaktanfrage/verwaltung?ka=' . $status);
        exit;
    }
}

/**
 * Schreibt das Opt-out eines einzelnen Kontakts. Eigener Controller, weil
 * hier eine ANDERE Berechtigung gilt als in der Verwaltung: Wer einen Kontakt
 * bearbeiten darf, darf über dessen Erreichbarkeit entscheiden - dafür
 * braucht er nicht das Recht, alle eingegangenen Anfragen zu lesen.
 *
 * Geprüft wird seit #336 `contacts.edit`. Die beiden früheren Module
 * `persons` und `breeding_stations` hat der Kern zu einem zusammengeführt;
 * wer nur EINES von beiden durfte, hat das neue Recht nicht - das ist so
 * gewollt und in der Kern-Migration festgehalten.
 */
class OptOutController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    public function speichern(): void {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = Ziel::idAusAnfrage($_POST['kontakt_id'] ?? null);
        if ($id === null) {
            header('Location: /admin');
            exit;
        }

        $this->requirePermission(Ziel::MODUL, 'edit');

        $erlaubt = ($_POST['erlaubt'] ?? '0') === '1';
        $db = Database::getInstance();

        if ($erlaubt) {
            $db->prepare('DELETE FROM `plugin_kontaktanfrage_optout` WHERE contact_id = ?')
                ->execute([$id]);
        } else {
            $db->prepare(
                'INSERT INTO `plugin_kontaktanfrage_optout` (contact_id, disabled_by)
                 VALUES (:id, :benutzer)
                 ON DUPLICATE KEY UPDATE disabled_by = :benutzer2'
            )->execute([
                'id' => $id,
                'benutzer' => Eingabe::einzeilig((string) ($_SESSION['username'] ?? 'unbekannt'), 100),
                'benutzer2' => Eingabe::einzeilig((string) ($_SESSION['username'] ?? 'unbekannt'), 100),
            ]);
        }

        PluginAudit::log(
            Plugin::SLUG,
            $erlaubt ? 'Kontaktanfragen zugelassen' : 'Kontaktanfragen abgeschaltet',
            "Kontakt #{$id}"
        );

        header('Location: ' . Ziel::bearbeitenLink($id));
        exit;
    }
}
