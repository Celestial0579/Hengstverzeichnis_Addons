<?php
// plausibilitaetspruefung/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#114.
//
// WOZU DAS ADDON DA IST. Beim Aufräumen der Deckstationsdaten (hv-migration
// PR #70) ist über Tage immer dasselbe passiert: Ein Widerspruch fällt auf,
// weil ihn jemand zufällig ansieht - nicht, weil ihn etwas meldet. Und er
// steht die ganze Zeit öffentlich im Katalog. Gemessen an der Dev-Instanz
// (Stand v0.7.1, nach der Bereinigung): 9 Elternteile jünger als das Fohlen,
// 1x Vater = Mutter, 7 Halterzeiträume nach dem Todesjahr, 35 gestorbene
// Pferde mit offenem Zeitraum, 36 Pferde ohne Lebensnummer, 53 ohne
// Geschlecht, 26 Datensätze mit Zeichenschaden. Alles öffentlich, nichts
// gemeldet.
//
// WAS ES NICHT TUT: reparieren. Ob das Todesjahr falsch ist oder der
// Zeitraum, ist eine Sachfrage - das Addon legt sie vor und entscheidet
// nicht. Und es baut nichts nach, was der Kern beim Speichern schon prüft
// (pedigreeContradiction, parentSexMismatch, personPeriodAfterDeath,
// death_year < birth_year); es ist für den ALTBESTAND da, der nie durch
// dieses Formular gelaufen ist. Wo dieselbe Aussage doppelt vorkommt, ist es
// dieselbe Regel, nur an einem anderen Zeitpunkt: der Kern beim Speichern
// eines neuen Datensatzes, dieses Addon beim Veröffentlichen eines alten.
//
// Installation (lokal im Framework-Repo):
//   cp -r plausibilitaetspruefung plugins/plausibilitaetspruefung
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// unter /admin/groups die Rechte "Plausibilitätsprüfung -> Bericht ansehen"
// bzw. "-> Fälle abhaken" vergeben.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Plausibilitaetspruefung;

use App\Controllers\BaseController;
use App\Database;
use App\Permission\GroupMembership;
use App\Plugin\HookManager;
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;
use App\Router;
use PDO;
use Throwable;

class Plugin {

    /** Der eigene Slug - Kategorie jedes Protokolleintrags (Kern-#352). */
    public const SLUG = 'plausibilitaetspruefung';

    /** Rechte-Modul dieses Addons (eigenes Modul, kein Kern-Modul). */
    public const MODUL = 'plausibilitaetspruefung';

    /** Basis der eigenen Routen - der Kern stellt /plugin/<slug> selbst voran. */
    public const BERICHT_URL = '/plugin/plausibilitaetspruefung/bericht';

    public function register(HookManager $hooks): void {
        // Das Veto gegen das VERÖFFENTLICHEN (Kern-#335). Ausdrücklich nicht
        // `horse.before_save`: Wer seine halbfertige Eingabe nicht speichern
        // kann, kommt nie an den Punkt, an dem er den Widerspruch auflöst -
        // die Begründung steht im Kommentar an HorseController::publishBlockers().
        $hooks->addFilter('horse.publish_blockers', [$this, 'veroeffentlichungsEinwaende']);

        // Der Befund am Datensatz selbst. Ohne diesen Abschnitt müsste ein
        // Bearbeiter den Bericht aufschlagen, um zu erfahren, WARUM sein
        // Häkchen "veröffentlichen" beim Speichern wieder wegfiel.
        $hooks->addFilter('horse.edit_sections', [$this, 'pferdeAbschnitt']);

        $hooks->addFilter('admin.dashboard_tiles', [$this, 'dashboardKachel']);
    }

    /**
     * Framework-Hook (#75): läuft bei jeder Aktivierung und nach jedem
     * Addon-Update, deshalb idempotent - und deshalb steht das DDL hier und
     * nicht in register(), das bei JEDEM Request liefe.
     */
    public function install(): void {
        $db = Database::getInstance();

        // Der abgehakte Einzelfall. Die Möglichkeit, einen Fund mit Begründung
        // stehenzulassen, ist der wichtigste Teil einer solchen Liste: Ohne
        // sie wächst sie zu, wird ignoriert und ist wertlos.
        //
        // UNIQUE (horse_id, regel), weil "abgehakt" ein Zustand ist und kein
        // Vorgang - ein zweites Abhaken derselben Sache ändert die Begründung,
        // es legt keine zweite Zeile an.
        //
        // FK mit CASCADE: Ein endgültig gelöschtes Pferd nimmt seine Ausnahmen
        // mit; für ein Pferd im Papierkorb (Soft-Delete) greift der FK nicht,
        // und das ist richtig - wird es wiederhergestellt, gilt die geprüfte
        // Begründung weiter.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_plausibilitaet_ausnahmen` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `regel` VARCHAR(64) NOT NULL,
                `begruendung` VARCHAR(500) NOT NULL,
                `geprueft_von` VARCHAR(100) NULL DEFAULT NULL,
                `geprueft_am` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_plaus_pferd_regel` (`horse_id`, `regel`),
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Zwischenstand der Bestandszahlen für die Dashboard-Kachel. Siehe
        // Zaehler: Die Kachel darf nicht acht Aggregate über den ganzen
        // Bestand kosten, nur weil jemand /admin aufruft.
        $db->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_plausibilitaet_zaehler` (
                `regel` VARCHAR(64) NOT NULL PRIMARY KEY,
                `anzahl` INT NOT NULL DEFAULT 0,
                `berechnet_am` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    // Kein uninstall(): Dieses Addon legt nichts in Kern-Tabellen ab. Seine
    // zwei eigenen Tabellen stehen unter "owns" in der plugin.json, der Kern
    // zählt daraus vor dem Löschen zusammen, was verschwände (Kern-#338).
    // Die Protokolleinträge unter der Kategorie `plausibilitaetspruefung`
    // bleiben bewusst stehen - sie sind der Nachweis, WER einen Widerspruch
    // als geprüft abgehakt hat; ein Nachweis, den das Deinstallieren
    // mitnimmt, ist keiner.

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label?:string}>
     */
    public function permissions(): array {
        // ZWEI Rechte, nicht eines. Der Bericht zeigt nur, was ohnehin im
        // Bestand steht - ihn zu lesen ist harmlos. Das Abhaken hebt dagegen
        // eine Veröffentlichungssperre auf: Wer abhakt, entscheidet, dass ein
        // Widerspruch öffentlich stehen bleiben darf. Das ist eine andere
        // Befugnis und gehört nicht automatisch jedem, der die Liste ansieht.
        return [
            [
                'module' => self::MODUL,
                'action' => 'bericht',
                'label' => 'Bericht ansehen',
                'module_label' => 'Plausibilitätsprüfung',
            ],
            [
                'module' => self::MODUL,
                'action' => 'abhaken',
                'label' => 'Fälle als geprüft abhaken (hebt die Veröffentlichungssperre auf)',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET', 'path' => '/bericht', 'callback' => [BerichtController::class, 'index']],
            ['method' => 'POST', 'path' => '/abhaken', 'callback' => [BerichtController::class, 'abhaken']],
            ['method' => 'POST', 'path' => '/zuruecknehmen', 'callback' => [BerichtController::class, 'zuruecknehmen']],
        ];
    }

    /**
     * Kern-Filter `horse.publish_blockers` (#335). Läuft bei JEDEM Speichern
     * eines Pferds, das veröffentlicht werden soll - und bei jedem Eintrag
     * einer Massen-Veröffentlichung. Der Preis ist deshalb der eigentliche
     * Entwurfsgegenstand, siehe Pruefung::fuerPferd().
     *
     * @param array<int, string> $einwaende
     * @param array<string, mixed> $horse
     * @return array<int, string>
     */
    public function veroeffentlichungsEinwaende(array $einwaende, int $horseId, array $horse): array {
        foreach (Pruefung::fuerPferd($horseId, $horse, Regelwerk::SCHWERE_BLOCKER) as $fund) {
            $einwaende[] = sprintf(
                '%s: %s. Prüfen und korrigieren - oder im Bericht der Plausibilitätsprüfung mit Begründung abhaken.',
                $fund['titel'],
                $fund['detail']
            );
        }
        return $einwaende;
    }

    /**
     * Kern-Filter `horse.edit_sections`: die Befunde AM Datensatz, mitsamt der
     * Begründung eines bereits abgehakten Falls ("die Begründung bleibt am
     * Datensatz sichtbar", #114).
     *
     * Fail-closed: Ohne `plausibilitaetspruefung.bericht` erscheint der
     * Abschnitt gar nicht. Das Bearbeitungsformular selbst verlangt nur
     * `horses.edit` - ein Redakteur ohne dieses Recht sähe sonst einen
     * Abschnitt, dessen Knöpfe mit 403 antworten.
     *
     * @param array<int, string> $sections
     * @param array<string, mixed> $horse
     * @return array<int, string>
     */
    public function pferdeAbschnitt(array $sections, array $horse): array {
        $benutzer = (int) ($_SESSION['user_id'] ?? 0);
        if (!GroupMembership::hasPermission($benutzer, self::MODUL, 'bericht')) {
            return $sections;
        }

        $horseId = (int) ($horse['id'] ?? 0);
        if ($horseId <= 0) {
            return $sections;
        }

        // $horse ist hier der ROHE Datensatz (SELECT * FROM horses, ohne
        // Sichtbarkeitsfilter, siehe docs/plugin-development.md) - genau das,
        // was die Vorbedingungen der Regeln brauchen. Ihn erneut zu laden wäre
        // eine Abfrage für Daten, die schon vorliegen.
        $html = Ansicht::pferdeAbschnitt(
            $horseId,
            $horse,
            GroupMembership::hasPermission($benutzer, self::MODUL, 'abhaken')
        );
        if ($html !== '') {
            $sections[] = $html;
        }
        return $sections;
    }

    /**
     * Kachel mit Zähler. Fail-closed wie der Abschnitt oben: Wer den Bericht
     * nicht sehen darf, bekommt auch keine Kachel, die auf ihn zeigt.
     *
     * @param array<int, array{url:string,label:string,icon:string}> $tiles
     * @return array<int, array{url:string,label:string,icon:string}>
     */
    public function dashboardKachel(array $tiles): array {
        if (!GroupMembership::hasPermission((int) ($_SESSION['user_id'] ?? 0), self::MODUL, 'bericht')) {
            return $tiles;
        }

        $stand = Zaehler::stand();
        $offen = $stand['blocker'] + $stand['hinweis'];

        $tiles[] = [
            'url' => self::BERICHT_URL,
            'label' => $offen === 0
                ? 'Plausibilität: keine offenen Funde'
                : sprintf('Plausibilität: %d offen (%d blockierend)', $offen, $stand['blocker']),
            'icon' => $stand['blocker'] > 0 ? '⚠️' : '🔎',
        ];
        return $tiles;
    }
}

/**
 * Eine einzelne Regel: Kennung, Schwere, Klartextbegründung und die eine
 * Abfrage, aus der SOWOHL der Bestandsbericht ALS AUCH das Veto für ein
 * einzelnes Pferd entsteht.
 *
 * Warum eine gemeinsame Abfrage und nicht je eine Fassung für beide Zwecke:
 * Zwei Fassungen derselben Regel driften auseinander, und zwar unbemerkt in
 * die schlimmere Richtung - der Bericht zeigt einen Fall, den das Veto nicht
 * kennt (oder umgekehrt), und niemand merkt es, weil beide für sich plausibel
 * aussehen. Die SQL trägt deshalb den Platzhalter {WO}, der einmal zu "1=1"
 * (ganzer Bestand) und einmal zu "h.id = ?" (ein Pferd, Primärschlüssel)
 * wird.
 *
 * Die Abfrage liefert immer dieselben vier Spalten: horse_id, name,
 * oeffentlich, detail.
 */
final class Regel {

    public function __construct(
        public readonly string $id,
        public readonly string $schwere,
        public readonly string $titel,
        public readonly string $begruendung,
        public readonly string $sql,
        /**
         * Billige Vorbedingung aus den Feldern des Pferds allein: Kann dieses
         * Pferd die Regel überhaupt verletzen? Spart beim Veröffentlichen die
         * Abfrage für Regeln, die schon an den vorliegenden Werten scheitern
         * (kein Todesjahr -> kein Zeitraum nach dem Todesjahr). Sie darf nur
         * ENGER sein als die WHERE-Klausel der SQL, nie enger als nötig -
         * sonst übersieht das Veto, was der Bericht zeigt. Deshalb steht sie
         * unmittelbar neben der Abfrage und wiederholt nur deren erste
         * Bedingung.
         *
         * @var callable(array<string, mixed>): bool
         */
        public readonly mixed $vorbedingung,
    ) {
        // Die Kennung landet als Literal in der SQL (siehe Regelwerk::abfrage())
        // und als Wert in der Ausnahmen-Tabelle. Sie kommt aus dem Code dieses
        // Addons, nicht von aussen - aber "erweiterbar, ohne den Kern
        // anzufassen" heisst, dass irgendwann jemand eine Regel ergänzt, und
        // dann soll ein Tippfehler auffallen und nicht in die Abfrage geraten.
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $this->id)) {
            throw new \InvalidArgumentException("Regelkennung '{$this->id}' muss ^[a-z0-9][a-z0-9-]*\$ entsprechen.");
        }
        if (!in_array($this->schwere, [Regelwerk::SCHWERE_BLOCKER, Regelwerk::SCHWERE_HINWEIS], true)) {
            throw new \InvalidArgumentException("Regel '{$this->id}': unbekannte Schwere '{$this->schwere}'.");
        }
    }

    public function blockiert(): bool {
        return $this->schwere === Regelwerk::SCHWERE_BLOCKER;
    }
}

/**
 * Der Regelsatz. Erweitern heisst: einen Eintrag in alle() ergänzen - kein
 * Eingriff in den Kern, kein Eingriff an anderer Stelle dieses Addons.
 *
 * WELCHE REGEL BLOCKIERT UND WELCHE NUR MELDET - die Entscheidung, um die es
 * bei diesem Addon eigentlich geht:
 *
 * Blockierend ist, was NICHT WAHR SEIN KANN. Ein Elternteil, das im selben
 * Jahr oder später geboren ist als sein Nachkomme, ist keine ungewöhnliche
 * Angabe, sondern eine unmögliche; dasselbe gilt für "Vater und Mutter sind
 * dasselbe Pferd", für ein Todesjahr vor dem Geburtsjahr und für einen
 * Halterzeitraum, der nach dem Todesjahr des Pferdes beginnt. So etwas gehört
 * nicht auf eine öffentliche Seite, und es zu blockieren nimmt niemandem
 * etwas: Der Datensatz bleibt gespeichert, nur das Häkchen fällt.
 *
 * Ein Hinweis ist, was UNVOLLSTÄNDIG oder UNSCHÖN ist. "Gestorbenes Pferd mit
 * offenem Halterzeitraum" trifft im Bestand 35 Datensätze und ist dort schlicht
 * die Regel - wer bis zum Tod Halter war, hat kein Endjahr eingetragen. Das als
 * Blocker zu führen wäre eine Zumutung: Es nähme 35 gepflegte Seiten vom Netz,
 * um eine Konvention durchzusetzen, über die noch niemand entschieden hat.
 * Ebenso "ohne Lebensnummer" (36) und "ohne Geschlecht" (53) - das sind
 * fehlende Angaben, keine falschen; ein Verzeichnis, das den Altbestand
 * versteckt, bis jeder Datensatz vollständig ist, zeigt nichts mehr. Und der
 * Zeichenschaden (26) ist ein Darstellungsfehler: hässlich, aber die Aussage
 * des Datensatzes bleibt richtig.
 *
 * Die Trennlinie ist also nicht "wie schlimm sieht es aus", sondern "steht
 * hier etwas Falsches". Wer sie verschiebt, verschiebt sie an dieser Stelle
 * und begründet es hier.
 */
final class Regelwerk {

    public const SCHWERE_BLOCKER = 'blocker';
    public const SCHWERE_HINWEIS = 'hinweis';

    /**
     * Das Unicode-Ersatzzeichen U+FFFD als SQL-Suchmuster. Bewusst über die
     * Byte-Folge und CONVERT statt als Literal in der Quelldatei: Ein
     * Ersatzzeichen im Quelltext einer Datei, die Ersatzzeichen sucht,
     * übersteht die erste Kodierungspanne nicht.
     */
    private const ERSATZZEICHEN = "CONCAT('%', CONVERT(0xEFBFBD USING utf8mb4), '%')";

    /** @var array<int, Regel>|null */
    private static ?array $regeln = null;

    /** @return array<int, Regel> */
    public static function alle(): array {
        if (self::$regeln !== null) {
            return self::$regeln;
        }

        self::$regeln = [
            // ---------------------------------------------------------------
            // Blockierend: Aussagen, die nicht wahr sein können.
            // ---------------------------------------------------------------

            // Der Kern verhindert das seit #298 beim Speichern
            // (pedigreeContradiction). Im Bestand stehen trotzdem 9 Fälle -
            // sie stammen aus der Migration und sind nie durch das Formular
            // gelaufen. Genau dafür ist dieses Addon da.
            //
            // Nur VERKNÜPFTE Eltern (sire_id/dam_id): Ein Freitext-Elternteil
            // hat kein Geburtsjahr, über das sich etwas aussagen liesse.
            new Regel(
                'eltern-juenger',
                self::SCHWERE_BLOCKER,
                'Elternteil jünger als das Fohlen',
                'Ein Elternteil, das im selben Jahr oder später geboren ist als sein Nachkomme, kann dessen Elternteil nicht sein. Entweder ist eine der beiden Jahresangaben falsch, oder die Verknüpfung zeigt auf den falschen Datensatz.',
                'SELECT h.id AS horse_id, h.name AS name, h.is_published AS oeffentlich,
                        CONCAT(
                            \'Jahrgang \', h.birth_year, \', aber nicht älter: \',
                            CONCAT_WS(\' und \',
                                CASE WHEN v.birth_year IS NOT NULL AND v.birth_year >= h.birth_year
                                     THEN CONCAT(\'Vater "\', v.name, \'" (\', v.birth_year, \')\') END,
                                CASE WHEN m.birth_year IS NOT NULL AND m.birth_year >= h.birth_year
                                     THEN CONCAT(\'Mutter "\', m.name, \'" (\', m.birth_year, \')\') END
                            )
                        ) AS detail
                 FROM horses h
                 LEFT JOIN horses v ON v.id = h.sire_id
                 LEFT JOIN horses m ON m.id = h.dam_id
                 WHERE h.deleted_at IS NULL AND {WO}
                   AND h.birth_year IS NOT NULL
                   AND (v.birth_year >= h.birth_year OR m.birth_year >= h.birth_year)',
                static fn(array $h): bool => $h['birth_year'] !== null
                    && ($h['sire_id'] !== null || $h['dam_id'] !== null)
            ),

            new Regel(
                'vater-gleich-mutter',
                self::SCHWERE_BLOCKER,
                'Vater und Mutter sind dasselbe Pferd',
                'Dasselbe Pferd kann nicht Vater und Mutter desselben Nachkommen sein. In aller Regel wurde beim Verknüpfen einer der beiden Elternteile versehentlich doppelt gewählt.',
                'SELECT h.id AS horse_id, h.name AS name, h.is_published AS oeffentlich,
                        CONCAT(\'Vater und Mutter verweisen beide auf Pferd #\', h.sire_id) AS detail
                 FROM horses h
                 WHERE h.deleted_at IS NULL AND {WO}
                   AND h.sire_id IS NOT NULL AND h.sire_id = h.dam_id',
                static fn(array $h): bool => $h['sire_id'] !== null && $h['dam_id'] !== null
            ),

            new Regel(
                'tod-vor-geburt',
                self::SCHWERE_BLOCKER,
                'Todesjahr liegt vor dem Geburtsjahr',
                'Ein Pferd kann nicht vor seiner Geburt gestorben sein. Eine der beiden Jahresangaben ist falsch.',
                'SELECT h.id AS horse_id, h.name AS name, h.is_published AS oeffentlich,
                        CONCAT(\'Geboren \', h.birth_year, \', gestorben \', h.death_year) AS detail
                 FROM horses h
                 WHERE h.deleted_at IS NULL AND {WO}
                   AND h.birth_year IS NOT NULL AND h.death_year IS NOT NULL
                   AND h.death_year < h.birth_year',
                static fn(array $h): bool => $h['birth_year'] !== null && $h['death_year'] !== null
            ),

            // Framework-#334 hat die Prüfung in den Speicherpfad gebracht;
            // im Bestand stehen weiterhin 7 Zeiträume, die NACH dem Todesjahr
            // beginnen oder enden.
            new Regel(
                'zeitraum-nach-tod',
                self::SCHWERE_BLOCKER,
                'Zuordnungszeitraum nach dem Todesjahr',
                'Züchter, Eigentümer oder Halter eines Pferdes können ihre Zuordnung nicht in einem Jahr beginnen oder beenden, in dem das Pferd bereits tot war. Entweder stimmt das Todesjahr nicht, oder der Zeitraum gehört zu einem anderen Pferd.',
                'SELECT h.id AS horse_id, h.name AS name, h.is_published AS oeffentlich,
                        CONCAT(
                            \'Todesjahr \', h.death_year,
                            \', späteste Jahresangabe einer Zuordnung: \',
                            MAX(GREATEST(COALESCE(hp.from_year, 0), COALESCE(hp.until_year, 0)))
                        ) AS detail
                 FROM horses h
                 JOIN horse_persons hp ON hp.horse_id = h.id
                 WHERE h.deleted_at IS NULL AND {WO}
                   AND h.death_year IS NOT NULL
                   AND (hp.from_year > h.death_year OR hp.until_year > h.death_year)
                 GROUP BY h.id, h.name, h.is_published, h.death_year',
                static fn(array $h): bool => $h['death_year'] !== null
            ),

            // ---------------------------------------------------------------
            // Hinweis: unvollständig oder unschön, aber nicht falsch.
            // ---------------------------------------------------------------

            new Regel(
                'gestorben-offener-zeitraum',
                self::SCHWERE_HINWEIS,
                'Verstorbenes Pferd mit offenem Zuordnungszeitraum',
                'Ein Zeitraum ohne Endjahr wird als "bis heute" gelesen - bei einem verstorbenen Pferd ist das schief. Bewusst nur ein Hinweis: Im Bestand ist genau das der Normalfall (35 Datensätze), weil wer bis zum Tod Halter war, kein Endjahr eingetragen hat. Das Endjahr nachzutragen ist eine Sachentscheidung, keine Fehlerkorrektur.',
                'SELECT h.id AS horse_id, h.name AS name, h.is_published AS oeffentlich,
                        CONCAT(
                            COUNT(*), \' Zuordnung(en) ohne Endjahr\',
                            IFNULL(CONCAT(\', Todesjahr \', h.death_year), \'\')
                        ) AS detail
                 FROM horses h
                 JOIN horse_persons hp ON hp.horse_id = h.id
                 WHERE h.deleted_at IS NULL AND {WO}
                   AND h.is_deceased = 1
                   AND hp.from_year IS NOT NULL AND hp.until_year IS NULL
                 GROUP BY h.id, h.name, h.is_published, h.death_year',
                static fn(array $h): bool => (int) ($h['is_deceased'] ?? 0) === 1
            ),

            new Regel(
                'ohne-lebensnummer',
                self::SCHWERE_HINWEIS,
                'Keine Lebensnummer erfasst',
                'Ohne UELN oder ausländische Lebensnummer ist ein Pferd nicht eindeutig identifizierbar - Dubletten fallen dann erst über den Namen auf. Eine fehlende Angabe ist aber keine falsche: Der Altbestand kennt sie oft schlicht nicht, und ihn deswegen unsichtbar zu machen hilft niemandem.',
                'SELECT h.id AS horse_id, h.name AS name, h.is_published AS oeffentlich,
                        \'Weder UELN noch ausländische Lebensnummer hinterlegt\' AS detail
                 FROM horses h
                 WHERE h.deleted_at IS NULL AND {WO}
                   AND (h.ueln IS NULL OR h.ueln = \'\')
                   AND (h.foreign_ueln IS NULL OR h.foreign_ueln = \'\')',
                static fn(array $h): bool => trim((string) ($h['ueln'] ?? '')) === ''
                    && trim((string) ($h['foreign_ueln'] ?? '')) === ''
            ),

            new Regel(
                'ohne-geschlecht',
                self::SCHWERE_HINWEIS,
                'Kein Geschlecht erfasst',
                'Ohne Geschlecht greifen die Abstammungsprüfungen des Kerns nicht (ein Datensatz ohne Angabe besteht sie immer) und die Filter des Katalogs zeigen das Pferd nicht. NULL bedeutet hier "unbekannt" und ist für den Altbestand ausdrücklich zugelassen - deshalb nur ein Hinweis.',
                'SELECT h.id AS horse_id, h.name AS name, h.is_published AS oeffentlich,
                        \'Geschlecht unbekannt (NULL)\' AS detail
                 FROM horses h
                 WHERE h.deleted_at IS NULL AND {WO} AND h.sex IS NULL',
                static fn(array $h): bool => ($h['sex'] ?? null) === null
            ),

            new Regel(
                'zeichenschaden',
                self::SCHWERE_HINWEIS,
                'Zeichenschaden (U+FFFD) im Text',
                'Das Unicode-Ersatzzeichen U+FFFD entsteht, wenn Text aus einer anderen Kodierung übernommen wurde - typischerweise trifft es Umlaute und nordische Buchstaben. Der Datensatz ist dadurch nicht falsch, nur falsch dargestellt; korrigieren kann das nur, wer den ursprünglichen Namen kennt.',
                'SELECT h.id AS horse_id, h.name AS name, h.is_published AS oeffentlich,
                        CONCAT(\'Ersatzzeichen \', CONCAT_WS(\' und \',
                            CASE WHEN h.name LIKE ' . self::ERSATZZEICHEN . ' THEN \'im Namen\' END,
                            CASE WHEN h.description LIKE ' . self::ERSATZZEICHEN . ' THEN \'in der Beschreibung\' END
                        )) AS detail
                 FROM horses h
                 WHERE h.deleted_at IS NULL AND {WO}
                   AND (h.name LIKE ' . self::ERSATZZEICHEN . ' OR h.description LIKE ' . self::ERSATZZEICHEN . ')',
                static fn(array $h): bool => str_contains((string) ($h['name'] ?? ''), "\u{FFFD}")
                    || str_contains((string) ($h['description'] ?? ''), "\u{FFFD}")
            ),
        ];

        return self::$regeln;
    }

    /** @return array<int, Regel> */
    public static function mitSchwere(string $schwere): array {
        return array_values(array_filter(self::alle(), static fn(Regel $r): bool => $r->schwere === $schwere));
    }

    public static function nach(string $id): ?Regel {
        foreach (self::alle() as $regel) {
            if ($regel->id === $id) {
                return $regel;
            }
        }
        return null;
    }

    /**
     * Setzt den Platzhalter {WO} und hängt die Regelkennung als Spalte an.
     * Die Kennung ist im Konstruktor gegen ^[a-z0-9-]+$ geprüft und stammt aus
     * dem Code dieses Addons - sie kann deshalb als Literal in die Abfrage,
     * ohne dass daraus eine Einschleusungsstelle wird.
     */
    public static function abfrage(Regel $regel, string $wo): string {
        return "SELECT '{$regel->id}' AS regel, t.* FROM ("
            . str_replace('{WO}', $wo, $regel->sql)
            . ') t';
    }
}

/**
 * Führt die Regeln aus - für ein Pferd (Veto) und für den Bestand (Bericht).
 */
final class Pruefung {

    /** Höchstzahl gezeigter Fälle je Regel im Bericht. */
    public const JE_REGEL_MAX = 200;

    private function __construct() {}

    /**
     * Die Befunde EINES Pferds. Diese Methode ist der Preis, den jeder
     * Speichervorgang mit gesetztem Veröffentlichungshäkchen zahlt - und
     * jeder Eintrag einer Massen-Veröffentlichung. Deshalb:
     *
     * 1. Regeln, deren Vorbedingung an den bereits vorliegenden Feldern des
     *    Pferds scheitert, kosten gar nichts (kein Todesjahr -> kein Zeitraum
     *    nach dem Todesjahr). Beim typischen Datensatz bleiben davon null bis
     *    zwei Regeln übrig.
     * 2. Was übrig bleibt, läuft in EINER Abfrage: die Teilabfragen per
     *    UNION ALL, jede über den Primärschlüssel des Pferds. Ein Roundtrip,
     *    ein paar Index-Zugriffe.
     * 3. Die abgehakten Fälle werden nur dann nachgeschlagen, wenn überhaupt
     *    etwas gefunden wurde.
     *
     * Fail-open bei jedem Fehler: Ein abgestürztes Addon darf keine
     * Veröffentlichung blockieren, denn niemand könnte den Grund beheben.
     * Genau das schreibt der Kommentar an HorseController::publishBlockers()
     * vor - und der Kern kann es nicht erzwingen, weil eine leere Liste vom
     * Filter nicht von "keine Einwände" zu unterscheiden ist.
     *
     * @param array<string, mixed> $horse Der volle Datensatz aus dem Kern.
     * @return array<int, array{regel:string, titel:string, detail:string}>
     */
    public static function fuerPferd(int $horseId, array $horse, ?string $schwere = null): array {
        if ($horseId <= 0) {
            return [];
        }

        $kandidaten = [];
        foreach (Regelwerk::alle() as $regel) {
            if ($schwere !== null && $regel->schwere !== $schwere) {
                continue;
            }
            $vorbedingung = $regel->vorbedingung;
            try {
                if (!$vorbedingung($horse)) {
                    continue;
                }
            } catch (Throwable) {
                // Eine fehlerhafte Vorbedingung darf keine Regel verschlucken -
                // im Zweifel wird die Abfrage ausgeführt.
            }
            $kandidaten[] = $regel;
        }

        if ($kandidaten === []) {
            return [];
        }

        try {
            $teile = [];
            foreach ($kandidaten as $regel) {
                $teile[] = '(' . Regelwerk::abfrage($regel, 'h.id = ?') . ')';
            }
            $stmt = Database::getInstance()->prepare(implode(' UNION ALL ', $teile));
            $stmt->execute(array_fill(0, count($kandidaten), $horseId));
            $zeilen = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($zeilen === []) {
                return [];
            }

            $abgehakt = Ausnahmen::regelnFuer($horseId);
        } catch (Throwable) {
            return [];
        }

        $funde = [];
        foreach ($zeilen as $zeile) {
            $id = (string) $zeile['regel'];
            if (isset($abgehakt[$id])) {
                continue;
            }
            $regel = Regelwerk::nach($id);
            if ($regel === null) {
                continue;
            }
            $funde[] = [
                'regel' => $id,
                'titel' => $regel->titel,
                'detail' => (string) ($zeile['detail'] ?? ''),
            ];
        }
        return $funde;
    }

    /**
     * Alle Fälle einer Regel im Bestand, getrennt nach offen und abgehakt.
     *
     * @return array{offen: array<int, array<string, mixed>>, abgehakt: array<int, array<string, mixed>>, abgeschnitten: bool}
     */
    public static function fuerBestand(Regel $regel): array {
        $leer = ['offen' => [], 'abgehakt' => [], 'abgeschnitten' => false];

        try {
            $sql = Regelwerk::abfrage($regel, '1=1')
                . ' ORDER BY t.name ASC, t.horse_id ASC LIMIT ' . (self::JE_REGEL_MAX + 1);
            $zeilen = Database::getInstance()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $ausnahmen = Ausnahmen::fuerRegel($regel->id);
        } catch (Throwable) {
            return $leer;
        }

        $abgeschnitten = count($zeilen) > self::JE_REGEL_MAX;
        if ($abgeschnitten) {
            $zeilen = array_slice($zeilen, 0, self::JE_REGEL_MAX);
        }

        $offen = [];
        $abgehakt = [];
        foreach ($zeilen as $zeile) {
            $id = (int) $zeile['horse_id'];
            if (isset($ausnahmen[$id])) {
                $zeile['ausnahme'] = $ausnahmen[$id];
                $abgehakt[] = $zeile;
            } else {
                $offen[] = $zeile;
            }
        }

        return ['offen' => $offen, 'abgehakt' => $abgehakt, 'abgeschnitten' => $abgeschnitten];
    }

    /**
     * Zählt die OFFENEN Fälle einer Regel im Bestand - das Aggregat für die
     * Kachel. Der LEFT JOIN gegen die Ausnahmen steckt in der Abfrage, damit
     * nicht erst alle Zeilen geholt und dann in PHP verworfen werden.
     */
    public static function anzahlOffen(Regel $regel): int {
        try {
            $sql = 'SELECT COUNT(*) FROM (' . Regelwerk::abfrage($regel, '1=1') . ') f
                    LEFT JOIN `plugin_plausibilitaet_ausnahmen` a
                           ON a.horse_id = f.horse_id AND a.regel = ?
                    WHERE a.id IS NULL';
            $stmt = Database::getInstance()->prepare($sql);
            $stmt->execute([$regel->id]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}

/**
 * Die bewusst stehengelassenen Fälle. Ohne sie wäre der Bericht nach zwei
 * Wochen eine Liste, die niemand mehr aufschlägt.
 */
final class Ausnahmen {

    public const BEGRUENDUNG_MAX = 500;

    private function __construct() {}

    /**
     * Welche Regeln sind für dieses Pferd abgehakt?
     *
     * @return array<string, array<string, mixed>> Regelkennung => Zeile
     */
    public static function regelnFuer(int $horseId): array {
        $stmt = Database::getInstance()->prepare(
            'SELECT regel, begruendung, geprueft_von, geprueft_am
             FROM `plugin_plausibilitaet_ausnahmen` WHERE horse_id = ?'
        );
        $stmt->execute([$horseId]);

        $nach = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
            $nach[(string) $zeile['regel']] = $zeile;
        }
        return $nach;
    }

    /**
     * @return array<int, array<string, mixed>> horse_id => Zeile
     */
    public static function fuerRegel(string $regelId): array {
        $stmt = Database::getInstance()->prepare(
            'SELECT horse_id, begruendung, geprueft_von, geprueft_am
             FROM `plugin_plausibilitaet_ausnahmen` WHERE regel = ?'
        );
        $stmt->execute([$regelId]);

        $nach = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
            $nach[(int) $zeile['horse_id']] = $zeile;
        }
        return $nach;
    }

    /**
     * Legt die Ausnahme an oder ändert ihre Begründung (UNIQUE auf
     * horse_id+regel, siehe install()).
     */
    public static function setzen(int $horseId, string $regelId, string $begruendung, string $benutzer): void {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO `plugin_plausibilitaet_ausnahmen` (horse_id, regel, begruendung, geprueft_von)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE begruendung = VALUES(begruendung),
                                     geprueft_von = VALUES(geprueft_von),
                                     geprueft_am = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$horseId, $regelId, $begruendung, $benutzer]);
    }

    public static function aufheben(int $horseId, string $regelId): bool {
        $stmt = Database::getInstance()->prepare(
            'DELETE FROM `plugin_plausibilitaet_ausnahmen` WHERE horse_id = ? AND regel = ?'
        );
        $stmt->execute([$horseId, $regelId]);
        return $stmt->rowCount() > 0;
    }
}

/**
 * Zwischenstand der Bestandszahlen für die Dashboard-Kachel.
 *
 * WARUM ÜBERHAUPT EIN ZWISCHENSTAND. Die Kachel steht auf /admin, und ohne
 * Zwischenstand kostete jeder Aufruf dieser Seite acht Aggregate über den
 * ganzen Pferdebestand - darunter zwei mit Join auf horse_persons. Das ist
 * genau die Art Beitrag, die ein Addon unbeliebt macht: unsichtbar, dauerhaft
 * und an einer Stelle, an der niemand ihn sucht.
 *
 * Der Preis dafür ist eine mögliche Verzögerung von wenigen Minuten in der
 * ZAHL auf der Kachel. Das Veto beim Veröffentlichen und der Bericht selbst
 * rechnen dagegen immer frisch - dort zählt Aktualität, hier nicht.
 */
final class Zaehler {

    /** Nach dieser Zeit gilt der Zwischenstand als veraltet. */
    public const HALTBAR_SEKUNDEN = 900;

    private function __construct() {}

    /**
     * @return array{blocker:int, hinweis:int, je_regel:array<string,int>, berechnet_am:?string}
     */
    public static function stand(): array {
        try {
            $zeilen = Database::getInstance()
                ->query('SELECT regel, anzahl, UNIX_TIMESTAMP(berechnet_am) AS ts, berechnet_am
                         FROM `plugin_plausibilitaet_zaehler`')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return ['blocker' => 0, 'hinweis' => 0, 'je_regel' => [], 'berechnet_am' => null];
        }

        $aeltester = null;
        $aeltesterText = '';
        $jeRegel = [];
        foreach ($zeilen as $zeile) {
            $jeRegel[(string) $zeile['regel']] = (int) $zeile['anzahl'];
            $ts = (int) $zeile['ts'];
            // Der ÄLTESTE Zeitstempel, nicht irgendeiner: Er beantwortet die
            // Frage, auf die es ankommt - wie alt ist die Aussage insgesamt.
            if ($aeltester === null || $ts < $aeltester) {
                $aeltester = $ts;
                $aeltesterText = (string) ($zeile['berechnet_am'] ?? '');
            }
        }

        // Fehlt eine Regel im Zwischenstand (frisch installiert, oder eine
        // Regel ist dazugekommen), wird neu gerechnet - eine Kachel, die "0
        // offen" behauptet, weil noch nie jemand gerechnet hat, wäre die
        // schlechteste aller Antworten.
        $vollstaendig = count($jeRegel) === count(Regelwerk::alle());
        if (!$vollstaendig || $aeltester === null || (time() - $aeltester) > self::HALTBAR_SEKUNDEN) {
            return self::neuBerechnen();
        }

        return self::summieren($jeRegel, $aeltesterText);
    }

    /**
     * @return array{blocker:int, hinweis:int, je_regel:array<string,int>, berechnet_am:?string}
     */
    public static function neuBerechnen(): array {
        $jeRegel = [];
        foreach (Regelwerk::alle() as $regel) {
            $jeRegel[$regel->id] = Pruefung::anzahlOffen($regel);
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'INSERT INTO `plugin_plausibilitaet_zaehler` (regel, anzahl) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE anzahl = VALUES(anzahl), berechnet_am = CURRENT_TIMESTAMP'
            );
            foreach ($jeRegel as $id => $anzahl) {
                $stmt->execute([$id, $anzahl]);
            }
        } catch (Throwable) {
            // Der Zwischenstand ist eine Bequemlichkeit, kein Datenbestand -
            // lässt er sich nicht schreiben, wird eben beim nächsten Mal
            // wieder gerechnet.
        }

        return self::summieren($jeRegel, date('Y-m-d H:i:s'));
    }

    /**
     * @param array<string,int> $jeRegel
     * @return array{blocker:int, hinweis:int, je_regel:array<string,int>, berechnet_am:?string}
     */
    private static function summieren(array $jeRegel, string $berechnetAm): array {
        $blocker = 0;
        $hinweis = 0;
        foreach (Regelwerk::alle() as $regel) {
            $anzahl = $jeRegel[$regel->id] ?? 0;
            if ($regel->blockiert()) {
                $blocker += $anzahl;
            } else {
                $hinweis += $anzahl;
            }
        }
        return [
            'blocker' => $blocker,
            'hinweis' => $hinweis,
            'je_regel' => $jeRegel,
            'berechnet_am' => $berechnetAm !== '' ? $berechnetAm : null,
        ];
    }
}

/**
 * HTML-Bausteine. Farben ausschliesslich über die Theme-Variablen des Kerns
 * (siehe :root in public/css/style.css) - rohe Hex-Werte brächen Darkmode und
 * Markenfarbe des Betreibers.
 */
final class Ansicht {

    private function __construct() {}

    public static function h(mixed $wert): string {
        return htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Der Abschnitt im Bearbeitungsformular eines Pferds (horse.edit_sections).
     *
     * Der Hook setzt den Abschnitt AUSSERHALB des Kern-Formulars ab (siehe
     * docs/plugin-development.md) - deshalb ein eigenes <form> mit eigener
     * POST-Route und eigener Rechteprüfung, und deshalb ist der Knopf nicht
     * mit "Speichern" beschriftet: Auf der Seite gibt es dann zwei
     * Speichern-Knöpfe, und wer oben die Stammdaten geändert hat und unten
     * diesen drückt, verliert die Änderung.
     *
     * @param array<string, mixed> $horse Der rohe Datensatz, wie ihn der Hook
     *                                    liefert - nicht erneut geladen.
     */
    public static function pferdeAbschnitt(int $horseId, array $horse, bool $darfAbhaken): string {
        try {
            $abgehakt = Ausnahmen::regelnFuer($horseId);
        } catch (Throwable) {
            return '';
        }

        $offen = Pruefung::fuerPferd($horseId, $horse);
        if ($offen === [] && $abgehakt === []) {
            return '';
        }

        $csrf = self::h(Router::generateCsrfToken());
        $zurueck = '/admin/horses/edit?id=' . $horseId;

        $html = '<div class="card" style="margin-top:1.5rem;">';
        $html .= '<h2 style="margin-bottom:0.25rem;">🔎 Plausibilitätsprüfung</h2>';
        $html .= '<p style="color:var(--text-muted);margin-bottom:1rem;">'
            . 'Widersprüche in diesem Datensatz. Blockierende Funde verhindern die Veröffentlichung, '
            . 'bis sie behoben oder mit Begründung abgehakt sind.</p>';

        if ($offen === []) {
            $html .= '<p style="color:var(--success-fg);">Keine offenen Funde.</p>';
        }

        foreach ($offen as $fund) {
            $regel = Regelwerk::nach($fund['regel']);
            if ($regel === null) {
                continue;
            }
            $html .= '<div style="border:1px solid var(--border-color);border-radius:var(--border-radius);'
                . 'padding:0.75rem;margin-bottom:0.75rem;background:'
                . ($regel->blockiert() ? 'var(--danger-soft-bg)' : 'var(--warning-soft-bg)') . ';">';
            $html .= self::abzeichen($regel) . ' <strong>' . self::h($regel->titel) . '</strong>';
            $html .= '<div style="margin:0.35rem 0;">' . self::h($fund['detail']) . '</div>';
            $html .= '<div style="color:var(--text-muted);font-size:0.9rem;">' . self::h($regel->begruendung) . '</div>';

            if ($darfAbhaken) {
                $html .= self::abhakenFormular($csrf, $horseId, $regel->id, $zurueck);
            }
            $html .= '</div>';
        }

        if ($abgehakt !== []) {
            $html .= '<h3 style="margin-top:1rem;">Abgehakt</h3>';
            $html .= '<ul style="margin:0.5rem 0 0 1.25rem;">';
            foreach ($abgehakt as $regelId => $zeile) {
                $regel = Regelwerk::nach((string) $regelId);
                $titel = $regel !== null ? $regel->titel : (string) $regelId;
                $html .= '<li style="margin-bottom:0.5rem;"><strong>' . self::h($titel) . '</strong>: '
                    . self::h($zeile['begruendung'])
                    . '<br><span style="color:var(--text-subtle);font-size:0.85rem;">geprüft von '
                    . self::h($zeile['geprueft_von'] ?? 'unbekannt') . ' am '
                    . self::h($zeile['geprueft_am'] ?? '') . '</span>';
                if ($darfAbhaken) {
                    $html .= self::zuruecknehmenFormular($csrf, $horseId, (string) $regelId, $zurueck);
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<p style="margin-top:1rem;"><a href="' . Plugin::BERICHT_URL . '">'
            . 'Zum vollständigen Bericht der Plausibilitätsprüfung</a></p>';
        $html .= '</div>';

        return $html;
    }

    public static function abzeichen(Regel $regel): string {
        $farbe = $regel->blockiert() ? 'var(--danger-fg)' : 'var(--warning-fg)';
        $text = $regel->blockiert() ? 'blockierend' : 'Hinweis';
        return '<span style="color:' . $farbe . ';font-weight:600;text-transform:uppercase;font-size:0.75rem;">'
            . $text . '</span>';
    }

    public static function abhakenFormular(string $csrf, int $horseId, string $regelId, string $zurueck): string {
        return '<form method="POST" action="/plugin/plausibilitaetspruefung/abhaken" '
            . 'style="margin-top:0.5rem;display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-start;">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="horse_id" value="' . $horseId . '">'
            . '<input type="hidden" name="regel" value="' . self::h($regelId) . '">'
            . '<input type="hidden" name="zurueck" value="' . self::h($zurueck) . '">'
            . '<input type="text" name="begruendung" class="form-control" required maxlength="'
            . Ausnahmen::BEGRUENDUNG_MAX . '" style="flex:1 1 20rem;" '
            . 'placeholder="Begründung - warum darf das so stehen bleiben?">'
            . '<button type="submit" class="btn btn-secondary">Als geprüft abhaken</button>'
            . '</form>';
    }

    public static function zuruecknehmenFormular(string $csrf, int $horseId, string $regelId, string $zurueck): string {
        return '<form method="POST" action="/plugin/plausibilitaetspruefung/zuruecknehmen" '
            . 'style="display:inline;margin-left:0.5rem;">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="horse_id" value="' . $horseId . '">'
            . '<input type="hidden" name="regel" value="' . self::h($regelId) . '">'
            . '<input type="hidden" name="zurueck" value="' . self::h($zurueck) . '">'
            . '<button type="submit" class="btn btn-secondary">Abhaken zurücknehmen</button>'
            . '</form>';
    }
}

/**
 * Der Bericht-Bereich (#114, Teil 3). Zugriff über das eigene Recht
 * `plausibilitaetspruefung.bericht`; das Abhaken zusätzlich über
 * `plausibilitaetspruefung.abhaken`.
 */
class BerichtController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission(Plugin::MODUL, 'bericht');
    }

    public function index(): void {
        $darfAbhaken = $this->hasPermission(Plugin::MODUL, 'abhaken');
        $csrf = Ansicht::h(Router::generateCsrfToken());

        // Der Bericht rechnet immer frisch - er ist die Seite, auf der man
        // nachsieht, ob eine Korrektur gewirkt hat. Bei dieser Gelegenheit
        // wird der Zwischenstand der Kachel gleich mit erneuert.
        $stand = Zaehler::neuBerechnen();

        $inhalt = $this->meldung();
        $inhalt .= $this->kopf($stand);

        foreach ([Regelwerk::SCHWERE_BLOCKER, Regelwerk::SCHWERE_HINWEIS] as $schwere) {
            foreach (Regelwerk::mitSchwere($schwere) as $regel) {
                $inhalt .= $this->regelKarte($regel, $csrf, $darfAbhaken);
            }
        }

        PluginPage::render('Plausibilitätsprüfung', $inhalt);
    }

    public function abhaken(): void {
        $this->pruefeCsrf();
        $this->requirePermission(Plugin::MODUL, 'abhaken');

        [$horseId, $regel] = $this->fallAusAnfrage();
        $begruendung = trim((string) ($_POST['begruendung'] ?? ''));

        // Ohne Begründung kein Abhaken. Ein Häkchen ohne Grund ist genau das,
        // was aus einer Prüfliste eine leere Liste macht: Irgendwann hakt
        // jemand alles ab, und niemand kann hinterher sagen, ob der Fall
        // geprüft wurde oder nur im Weg war.
        if ($begruendung === '') {
            $this->zurueck('begruendung-fehlt');
        }
        if (mb_strlen($begruendung) > Ausnahmen::BEGRUENDUNG_MAX) {
            $begruendung = mb_substr($begruendung, 0, Ausnahmen::BEGRUENDUNG_MAX);
        }

        $benutzer = (string) ($_SESSION['username'] ?? 'unbekannt');
        Ausnahmen::setzen($horseId, $regel->id, $begruendung, $benutzer);
        Zaehler::neuBerechnen();

        // Protokollpflicht (Kern-#352): Das Abhaken hebt eine
        // Veröffentlichungssperre auf - eine der wenigen Aktionen dieses
        // Addons, die überhaupt etwas verändern, und die einzige mit
        // Aussenwirkung. Die Begründung geht mit ins Protokoll, sie beschreibt
        // einen Datensatz und keine Person.
        PluginAudit::log(
            Plugin::SLUG,
            'Plausibilitätsfund als geprüft abgehakt',
            "Pferd #{$horseId}",
            "Regel '{$regel->id}': " . $begruendung
        );

        $this->zurueck('abgehakt');
    }

    public function zuruecknehmen(): void {
        $this->pruefeCsrf();
        $this->requirePermission(Plugin::MODUL, 'abhaken');

        [$horseId, $regel] = $this->fallAusAnfrage();

        if (Ausnahmen::aufheben($horseId, $regel->id)) {
            Zaehler::neuBerechnen();
            PluginAudit::log(
                Plugin::SLUG,
                'Abhaken eines Plausibilitätsfunds zurückgenommen',
                "Pferd #{$horseId}",
                "Regel '{$regel->id}'"
            );
        }

        $this->zurueck('zurueckgenommen');
    }

    /**
     * @return array{0:int, 1:Regel}
     */
    private function fallAusAnfrage(): array {
        $horseId = filter_var($_POST['horse_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $regel = Regelwerk::nach((string) ($_POST['regel'] ?? ''));

        if (!is_int($horseId) || $regel === null) {
            $this->zurueck('fehler');
        }
        return [$horseId, $regel];
    }

    private function pruefeCsrf(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }
    }

    /**
     * Zurück zum Bericht - oder zu dem Pferd, von dessen Bearbeitungsformular
     * aus abgehakt wurde.
     *
     * Das Ziel wird NICHT aus dem Formularfeld übernommen, sondern daraus nur
     * die Pferde-ID gelesen und der Pfad selbst gebaut. Ein Feld, dessen Wert
     * in einen Location-Header wandert, ist sonst eine offene Weiterleitung -
     * und die Seite hier steht hinter einem Login, also genau dort, wo eine
     * Weiterleitung Vertrauen mitnimmt.
     */
    private function zurueck(string $status): never {
        $ziel = Plugin::BERICHT_URL;
        if (preg_match('~^/admin/horses/edit\?id=(\d+)$~', (string) ($_POST['zurueck'] ?? ''), $m)) {
            $ziel = '/admin/horses/edit?id=' . (int) $m[1];
        }
        $trenner = str_contains($ziel, '?') ? '&' : '?';
        header('Location: ' . $ziel . $trenner . 'plaus=' . urlencode($status));
        exit;
    }

    private function meldung(): string {
        $status = (string) ($_GET['plaus'] ?? '');
        $texte = [
            'abgehakt' => ['var(--success-soft-bg)', 'Der Fall ist als geprüft abgehakt. Er blockiert die Veröffentlichung nicht mehr.'],
            'zurueckgenommen' => ['var(--info-soft-bg)', 'Das Abhaken wurde zurückgenommen. Der Fall zählt wieder als offen.'],
            'begruendung-fehlt' => ['var(--warning-soft-bg)', 'Ohne Begründung wird nichts abgehakt - sie ist der eigentliche Zweck der Ausnahme.'],
            'fehler' => ['var(--danger-soft-bg)', 'Unbekanntes Pferd oder unbekannte Regel - nichts geändert.'],
        ];
        if (!isset($texte[$status])) {
            return '';
        }
        [$farbe, $text] = $texte[$status];
        return '<div class="card" style="background:' . $farbe . ';">' . Ansicht::h($text) . '</div>';
    }

    /**
     * @param array{blocker:int, hinweis:int, je_regel:array<string,int>, berechnet_am:?string} $stand
     */
    private function kopf(array $stand): string {
        $html = '<div class="card">';
        $html .= '<h1 style="margin-bottom:0.5rem;">🔎 Plausibilitätsprüfung</h1>';
        $html .= '<p style="color:var(--text-muted);">'
            . 'Widersprüche im Bestand. <strong style="color:var(--danger-fg);">Blockierende</strong> Funde '
            . 'verhindern, dass das betroffene Pferd veröffentlicht wird - der Datensatz bleibt gespeichert, '
            . 'nur das Häkchen fällt. <strong style="color:var(--warning-fg);">Hinweise</strong> sind '
            . 'unvollständige, aber nicht falsche Angaben und ändern an der Veröffentlichung nichts.</p>';
        $html .= '<p style="color:var(--text-muted);">Repariert wird hier nichts von selbst: Ob das Todesjahr '
            . 'falsch ist oder der Zeitraum, ist eine Sachfrage. Jeder Fund führt deshalb ins '
            . 'Bearbeitungsformular des Datensatzes - oder lässt sich mit Begründung abhaken.</p>';
        $html .= '<p><strong>' . (int) $stand['blocker'] . '</strong> blockierend, <strong>'
            . (int) $stand['hinweis'] . '</strong> Hinweise'
            . ($stand['berechnet_am'] !== null ? ' (Stand ' . Ansicht::h($stand['berechnet_am']) . ')' : '')
            . '</p>';
        $html .= '</div>';
        return $html;
    }

    private function regelKarte(Regel $regel, string $csrf, bool $darfAbhaken): string {
        $ergebnis = Pruefung::fuerBestand($regel);

        $html = '<div class="card">';
        $html .= '<h2 style="margin-bottom:0.25rem;">' . Ansicht::abzeichen($regel) . ' '
            . Ansicht::h($regel->titel) . '</h2>';
        $html .= '<p style="color:var(--text-muted);">' . Ansicht::h($regel->begruendung) . '</p>';

        if ($ergebnis['offen'] === [] && $ergebnis['abgehakt'] === []) {
            $html .= '<p style="color:var(--success-fg);">Keine Fälle im Bestand.</p></div>';
            return $html;
        }

        if ($ergebnis['abgeschnitten']) {
            $html .= '<p style="color:var(--warning-fg);">Mehr als ' . Pruefung::JE_REGEL_MAX
                . ' Fälle - gezeigt werden die ersten ' . Pruefung::JE_REGEL_MAX . ' nach Namen.</p>';
        }

        if ($ergebnis['offen'] !== []) {
            $html .= '<p><strong>' . count($ergebnis['offen']) . '</strong> offen</p>';
            $html .= '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">';
            $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
                . '<th style="padding:0.4rem;">Pferd</th>'
                . '<th style="padding:0.4rem;">Öffentlich</th>'
                . '<th style="padding:0.4rem;">Befund</th>'
                . '<th style="padding:0.4rem;">' . ($darfAbhaken ? 'Abhaken' : '') . '</th>'
                . '</tr></thead><tbody>';

            foreach ($ergebnis['offen'] as $zeile) {
                $id = (int) $zeile['horse_id'];
                $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
                $html .= '<td style="padding:0.4rem;"><a href="/admin/horses/edit?id=' . $id . '">'
                    . Ansicht::h($zeile['name']) . '</a> <span style="color:var(--text-subtle);">#' . $id . '</span></td>';
                $html .= '<td style="padding:0.4rem;">' . ((int) $zeile['oeffentlich'] === 1
                    ? '<span style="color:var(--danger-fg);">ja</span>' : 'nein') . '</td>';
                $html .= '<td style="padding:0.4rem;">' . Ansicht::h($zeile['detail']) . '</td>';
                $html .= '<td style="padding:0.4rem;">'
                    . ($darfAbhaken ? Ansicht::abhakenFormular($csrf, $id, $regel->id, '') : '')
                    . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }

        if ($ergebnis['abgehakt'] !== []) {
            $html .= '<h3 style="margin-top:1rem;">' . count($ergebnis['abgehakt'])
                . ' bewusst stehengelassen</h3>';
            $html .= '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">';
            $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
                . '<th style="padding:0.4rem;">Pferd</th>'
                . '<th style="padding:0.4rem;">Begründung</th>'
                . '<th style="padding:0.4rem;">Geprüft</th>'
                . '<th style="padding:0.4rem;"></th></tr></thead><tbody>';
            foreach ($ergebnis['abgehakt'] as $zeile) {
                $id = (int) $zeile['horse_id'];
                $ausnahme = $zeile['ausnahme'];
                $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
                $html .= '<td style="padding:0.4rem;"><a href="/admin/horses/edit?id=' . $id . '">'
                    . Ansicht::h($zeile['name']) . '</a> <span style="color:var(--text-subtle);">#' . $id . '</span></td>';
                $html .= '<td style="padding:0.4rem;">' . Ansicht::h($ausnahme['begruendung']) . '</td>';
                $html .= '<td style="padding:0.4rem;color:var(--text-subtle);">'
                    . Ansicht::h($ausnahme['geprueft_von'] ?? 'unbekannt') . '<br>'
                    . Ansicht::h($ausnahme['geprueft_am'] ?? '') . '</td>';
                $html .= '<td style="padding:0.4rem;">'
                    . ($darfAbhaken ? Ansicht::zuruecknehmenFormular($csrf, $id, $regel->id, '') : '')
                    . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }

        $html .= '</div>';
        return $html;
    }
}
