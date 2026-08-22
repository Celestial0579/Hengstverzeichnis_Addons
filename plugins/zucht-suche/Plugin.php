<?php
// zucht-suche/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#105. Öffentliche Einstiegsseite
// "Zucht" unter /plugin/zucht-suche, auf der sich KONTAKTE - Züchter,
// Deckstationen, Besitzer, Halter - suchen lassen. Bis dahin führte der Weg zu
// einem Kontakt immer über ein Pferd; die Frage "welche Züchter gibt es in
// meiner Region" hatte keinen Einstieg, obwohl die Daten seit Kern-#293 da
// sind.
//
// Umbau auf die Kontaktliste (Addons#122 / Framework#336): `persons` und
// `breeding_stations` sind seit Kern 0.8 EINE Tabelle `contacts`. Die
// Gattung, auf der dieses Addon gebaut war - zwei Reiter, zwei Abfragen, zwei
// Ziel-Adressen -, gibt es damit nicht mehr. Was an ihre Stelle tritt und
// warum, steht bei Suchanfrage::ROLLE_* und bei
// SucheController::rollenBedingung().
//
// Installation (lokal im Framework-Repo):
//   cp -r zucht-suche plugins/zucht-suche
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren. Eine
// eigene Berechtigung bringt das Addon bewusst nicht mit, siehe README.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\ZuchtSuche;

use App\Controllers\BaseController;
use App\Database;
use App\Permission\GroupMembership;
use App\Plugin\HookManager;
use App\Plugin\PluginPage;
use PDO;

class Plugin {

    /** Öffentliche Adresse der Suchseite - routes() erzeugt sie aus path '/'. */
    public const SEITE = '/plugin/zucht-suche';

    /**
     * Rechte-Modul der Kontaktliste (#336). Bis 0.7 waren es zwei Module,
     * 'persons' und 'breeding_stations'; sie sind mit den Tabellen
     * zusammengefallen.
     */
    public const MODUL = 'contacts';

    public function register(HookManager $hooks): void {
        // Bewusst KEINE Dashboard-Kachel (#115): Der Menüpunkt "Zucht" unten
        // wird im Kern (BaseController::render()) für jede View aufgebaut,
        // also auch für /admin, und layout.php rendert ihn dort in der
        // Kopfnavigation. Kachel und Menüpunkt standen damit nebeneinander auf
        // derselben Seite und zeigten auf dieselbe öffentliche Adresse. Die
        // Kachel war dabei sogar die schlechtere von beiden: Ihr fehlte die
        // Sichtbarkeitsprüfung aus addNavItem(), sie erschien also auch, wenn
        // die Seite dem Betrachter mit 404 antwortet.
        //
        // Der eigentliche Menüpunkt "Zucht" neben "Verzeichnis" (Kern 0.7.0,
        // Filter layout.nav_items). Genau dafür ist dieses Addon da: Züchter
        // und Deckstationen sollen einen eigenen Einstieg haben, nicht nur
        // über ein einzelnes Pferd erreichbar sein.
        $hooks->addFilter('layout.nav_items', [$this, 'addNavItem']);
        // Zusätzlich ein Verweis auf den beiden Detailseiten - dort stößt ein
        // Besucher heute überhaupt erst auf einen Züchter oder eine Station,
        // und von dort ist der Weg zur Suche am kürzesten.
        $hooks->addFilter('horse.detail_sections', [$this, 'addHorseSection']);
        // NUR contact.detail_sections, nicht zusätzlich person.* und station.*
        // (#122): Der Kern feuert die beiden alten Namen seit 0.8 als Alias
        // hinterher, kaskadierend auf demselben Ergebnis. Wer - wie dieses
        // Addon bis 0.7 - BEIDE alten Paare registriert hatte, bekam seinen
        // Abschnitt seither zweimal auf derselben Seite, denn es gibt nur noch
        // einen Datensatz und damit nur noch eine Detailseite. Die Aliasse
        // entfallen in 0.9.0 ohnehin.
        $hooks->addFilter('contact.detail_sections', [$this, 'addContactSection']);
    }

    /**
     * Menüpunkt in der öffentlichen Navigation.
     *
     * Er entfällt, wenn Gäste die Kontaktliste nicht sehen dürfen - ein
     * Menüpunkt, der auf eine Seite führt, die selbst mit 404 antwortet, wäre
     * eine Sackgasse. Dieselbe Prüfung wie bei den Verweisen auf den
     * Detailseiten, siehe mitHinweis().
     *
     * @param array<int, array{url:string, label:string, icon:string}> $items
     * @return array<int, array{url:string, label:string, icon:string}>
     */
    public function addNavItem(array $items): array {
        if (!self::gastDarfSehen(self::MODUL)) {
            return $items;
        }

        $items[] = [
            'url' => self::SEITE,
            'label' => 'Zucht',
            'icon' => '🧬',
        ];
        return $items;
    }

    /**
     * @param array<int, string> $sections
     * @param array<string, mixed> $horse
     * @param array<int, array<string, mixed>> $horsePersons
     * @param array<string, mixed>|null $pedigree
     * @return array<int, string>
     */
    public function addHorseSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        return self::mitHinweis($sections);
    }

    /**
     * Verweis auf der öffentlichen Kontaktseite (/kontakt?id=). Ersetzt die
     * früheren addPersonSection()/addStationSection() - es gibt seit #336 nur
     * noch eine Detailseite (#122).
     *
     * @param array<int, string> $sections
     * @param array<string, mixed> $contact
     * @param array<string, array<int, array<string, mixed>>> $horsesByRole
     * @param array<int, array<string, mixed>> $stationHorses
     * @return array<int, string>
     */
    public function addContactSection(array $sections, array $contact, array $horsesByRole, array $stationHorses): array {
        return self::mitHinweis($sections);
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array<int, string>}>
     */
    public function routes(): array {
        // path '/' ergibt exakt /plugin/zucht-suche (PluginManager schneidet den
        // Schrägstrich ab) - eine Adresse, die sich verlinken und im Menü
        // eintragen lässt, ohne dass ein zweites Wegstück dazukommt.
        return [
            ['method' => 'GET', 'path' => '/', 'callback' => [SucheController::class, 'show']],
        ];
    }

    /**
     * Einzeiliger Verweis auf die Suchseite. Liefert die Abschnitte
     * unverändert zurück, wenn Gäste die Kontaktliste nicht sehen dürfen - ein
     * Link auf eine Seite, die dann selbst mit 404 antwortet, wäre eine
     * Sackgasse.
     *
     * @param array<int, string> $sections
     * @return array<int, string>
     */
    private static function mitHinweis(array $sections): array {
        if (!self::gastDarfSehen(self::MODUL)) {
            return $sections;
        }

        $sections[] = '<p style="margin-top:1rem;color:var(--text-muted);">'
            . '🧬 <a href="' . self::SEITE . '">Zucht-Suche</a> - '
            . 'Züchter und Deckstationen nach Ort, Bundesland/Kanton und Land finden.'
            . '</p>';

        return $sections;
    }

    /**
     * Dieselbe Prüfung wie BaseController::hasPermission(), nur außerhalb
     * eines Controllers: Hook-Callbacks bekommen keine Controller-Instanz,
     * und GroupMembership ist der dokumentierte Weg für genau diesen Fall
     * (fail-closed bei fehlender Zeile oder DB-Fehler).
     */
    private static function gastDarfSehen(string $modul): bool {
        return GroupMembership::hasPermission($_SESSION['user_id'] ?? null, $modul, 'view');
    }
}

/**
 * Die Suchanfrage als geprüfter Wert - alles, was aus der Adresszeile kommt,
 * geht durch diese Klasse und nur durch sie.
 *
 * Bewusst ohne Datenbank und ohne Framework-Klassen, damit die Prüfregeln in
 * tests/Unit/ZuchtSucheSuchanfrageTest.php direkt festgenagelt werden können
 * (Muster: EmbedCode im Addon embed-widget). Die Filterwerte selbst wandern
 * ausschließlich als gebundene Parameter in die Abfragen; hier geht es um
 * Form und Grenzen, nicht um Escaping.
 */
final class Suchanfrage {

    /**
     * Die Rolle als FILTER, nicht als Reiter (#122).
     *
     * Bis 0.7 waren "Züchter" und "Deckstation" zwei Reiter, weil sie zwei
     * Tabellen waren. Seit #336 ist beides derselbe Datensatz: Ein Hof kann
     * gleichzeitig züchten, Pferde besitzen und Deckstation sein - eine
     * Gattung müsste sich für eine der drei Aussagen entscheiden und die
     * anderen unsichtbar machen. Die Rolle ist deshalb kein Feld des
     * Datensatzes mehr, sondern eine Frage an das, was um ihn herum steht:
     *
     *   ROLLE_ZUECHTER   contacts.is_breeder - das redaktionell gepflegte
     *                    Kennzeichen "züchtet heute". Ausdrücklich NICHT aus
     *                    horse_persons.role='breeder' abgeleitet (siehe
     *                    database/schema.sql im Kern): Wer noch kein Pferd im
     *                    Verzeichnis hat, wäre sonst nicht auffindbar, und wer
     *                    früher gezüchtet hat, bliebe dauerhaft markiert.
     *   ROLLE_STATION    "wird von einem veröffentlichten Pferd als
     *                    Deckstation genannt" - das ist eine Aussage über
     *                    Pferde, kein Feld am Kontakt. Ein Kennzeichen dafür
     *                    gibt es nicht, und es zu erfinden hieße, die gerade
     *                    abgeschaffte Gattung wieder in die Daten zu
     *                    schreiben.
     *   ROLLE_BESITZER   horse_persons.role='owner'
     *   ROLLE_HALTER     horse_persons.role='keeper'
     *
     * ROLLE_ALLE ist der Standard und der Rückfallwert: Anders als bei den
     * Reitern gibt es hier immer eine gültige Antwort, auch wenn die Rechte
     * einzelne Rollen ausschließen.
     */
    public const ROLLE_ALLE = '';
    public const ROLLE_ZUECHTER = 'zuechter';
    public const ROLLE_STATION = 'station';
    public const ROLLE_BESITZER = 'besitzer';
    public const ROLLE_HALTER = 'halter';

    /**
     * Obergrenze je Textfeld. Die Spalten sind VARCHAR(100)/(150) - längere
     * Eingaben können ohnehin nichts treffen und blähen nur Abfrage und
     * Blätter-Links auf.
     */
    public const TEXT_MAX = 100;

    private function __construct(
        public readonly string $rolle,
        public readonly string $name,
        public readonly string $ort,
        public readonly string $bundesland,
        public readonly string $land,
        public readonly int $seite,
    ) {}

    /**
     * @param array<string, mixed> $eingabe  üblicherweise $_GET
     * @param array<int, string> $erlaubteRollen  Rollen, die die Berechtigungen
     *        zulassen. ROLLE_ALLE ist immer dabei; alles, was nicht in der
     *        Liste steht, fällt darauf zurück.
     */
    public static function aus(array $eingabe, array $erlaubteRollen): self {
        $rolle = self::text($eingabe['rolle'] ?? '');
        if (!in_array($rolle, $erlaubteRollen, true)) {
            $rolle = self::ROLLE_ALLE;
        }

        // Den Filter "Mitgliedsstatus" gab es hier bis v0.8. Er ist mit
        // Framework#349 ERSATZLOS entfallen, zusammen mit dem Feld im Kern:
        // Es war Freitext ohne Vokabular und bedingungslos öffentlich, und
        // "X ist kein Mitglied" ist eine Aussage über einen Menschen. Die
        // Angabe führt jetzt das Addon `mitgliedsstatus` mit fester
        // Werteliste und Freigabe JE KONTAKT (Addons#132) - eine
        // Trefferliste, die ungefragt danach filtert und die Werte in einer
        // Spalte ausgibt, würde genau diese Freigabe umgehen.
        //
        // Ein alter Lesezeichen-Link mit `?mitglied=…` schadet nicht: Der
        // Parameter wird nicht mehr gelesen, die Suche liefert dann die
        // Treffer ohne diese Einschränkung.
        return new self(
            $rolle,
            self::text($eingabe['name'] ?? ''),
            self::text($eingabe['ort'] ?? ''),
            self::text($eingabe['bundesland'] ?? ''),
            self::text($eingabe['land'] ?? ''),
            self::seite($eingabe['seite'] ?? 1),
        );
    }

    /**
     * Ein Textfeld ist ein String, sonst nichts: `?name[]=x` liefert ein
     * Array und dürfte weder eine Warnung noch einen TypeError auslösen.
     * Geschnitten wird mit mb_substr, damit kein halbes UTF-8-Zeichen
     * stehen bleibt.
     */
    public static function text(mixed $wert, int $max = self::TEXT_MAX): string {
        if (!is_string($wert)) {
            return '';
        }
        return mb_substr(trim($wert), 0, $max);
    }

    /**
     * Seitennummer - validiert, nicht umgedeutet. Ein blanker (int)-Cast
     * machte aus "3x" eine 3 und aus "abc" eine 0.
     */
    public static function seite(mixed $wert): int {
        if (!is_scalar($wert)) {
            return 1;
        }
        $nummer = filter_var($wert, FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
        return is_int($nummer) ? $nummer : 1;
    }

    /**
     * Muster für eine LIKE-Teilstringsuche. `%` und `_` sind in LIKE
     * Platzhalter: ohne die Maskierung fände die Eingabe "%" jeden Datensatz,
     * und "_" jeden mit beliebigem Zeichen an der Stelle. Der Wert bleibt ein
     * gebundener Parameter - das hier ist keine Escaping-Maßnahme gegen
     * Injection, sondern gegen ein falsches Suchergebnis.
     */
    public static function likeMuster(string $wert): string {
        return '%' . addcslashes($wert, '\\%_') . '%';
    }

    /**
     * Die gesetzten Filter als Query-Parameter, Grundlage für die
     * Blätter-Links: Ein Seitenwechsel darf die Suche nicht verwerfen.
     * Die Seitennummer ist bewusst NICHT enthalten - wer einen Filter ändert,
     * fängt bei Seite 1 an; die Blätter-Links setzen sie ausdrücklich.
     *
     * @param array<string, string> $ueberschreiben  leerer Wert entfernt den Parameter
     * @return array<string, string>
     */
    public function alsQuery(array $ueberschreiben = []): array {
        $query = [];
        foreach ([
            'rolle' => $this->rolle,
            'name' => $this->name,
            'ort' => $this->ort,
            'bundesland' => $this->bundesland,
            'land' => $this->land,
        ] as $schluessel => $wert) {
            if ($wert !== '') {
                $query[$schluessel] = $wert;
            }
        }

        foreach ($ueberschreiben as $schluessel => $wert) {
            if ($wert === '') {
                unset($query[$schluessel]);
                continue;
            }
            $query[$schluessel] = $wert;
        }

        return $query;
    }
}

/**
 * Die öffentliche Suchseite über die Kontaktliste (#336/#122).
 *
 * Sichtbarkeit exakt wie im Kern (PublicController::contactDetail()): Kontakte
 * erscheinen nur mit `contacts.view` der Gast-Gruppe, alles, was aus
 * Pferde-Zuordnungen abgeleitet ist (die Rollen Deckstation/Besitzer/Halter
 * und die beiden Pferdezahlen), nur zusätzlich mit `horses.view`. Fehlt
 * `contacts.view`, antwortet die Seite mit 404 statt mit einer leeren Liste -
 * eine leere Liste wäre die Aussage "es gibt keine", und die stimmt dann nicht.
 *
 * Datenschutz: Seit die Trennung persons/breeding_stations weggefallen ist,
 * ist `contact_public` der einzige Schutz je Datensatz - die Trefferliste hält
 * sich deshalb an die strengere der beiden alten Regeln (siehe
 * docs/kontaktliste-umstellung.md im Kern). Abgefragt werden ausschließlich
 * die immer-öffentlichen Spalten: id, name, city, state, country,
 * is_breeder. E-Mail, Telefon, Mobil, Straße, Hausnummer,
 * Anschrift, Ansprechpartner und contact_info kommen gar nicht erst an.
 *
 * Ausdrücklich AUCH NICHT die Postleitzahl: Die alte Stationsliste zeigte sie
 * in der Ortsspalte, weil eine Deckstation eine Geschäftsadresse war. Diese
 * Begründung ist mit der Gattung weggefallen; `postal_code` steht jetzt in
 * der Gruppe, die nur bei `contact_public = 1` öffentlich ist. Die Suche
 * würde sie sonst für jeden migrierten Stationsdatensatz weiter ausgeben -
 * genau die Sichtbarkeitserhöhung, die #336 ausschließt.
 */
class SucheController extends BaseController {

    private const TREFFER_PRO_SEITE = 50;

    /**
     * Auswahllisten der Filter. Vollständig literale Abfragen statt eines
     * zusammengesetzten Spaltennamens: Der Spaltenname ist der eine Teil
     * einer SQL-Anweisung, den ein Platzhalter nicht binden kann - also darf
     * er auch nie aus einer Variablen kommen. Das LIMIT deckelt den Fall
     * "Freitextfeld wurde als Sammelbecken benutzt".
     *
     * Je Feld nur noch EINE Abfrage (vorher je eine für Personen und für
     * Stationen): Die Auswahl zeigt den Bestand der Kontaktliste, unabhängig
     * vom Rollenfilter. Eine je Rolle gefilterte Auswahl wäre genauer, würde
     * aber die abgeleiteten Rollen (siehe rollenBedingung()) in jede
     * Auswahlliste hineinziehen - für einen Gewinn, den die Trefferliste
     * ohnehin sofort zeigt.
     */
    private const SQL_BUNDESLAENDER = "SELECT DISTINCT state FROM contacts WHERE is_published = 1 AND deleted_at IS NULL AND state IS NOT NULL AND state <> '' ORDER BY state ASC LIMIT 500";
    private const SQL_LAENDER = "SELECT DISTINCT country FROM contacts WHERE is_published = 1 AND deleted_at IS NULL AND country IS NOT NULL AND country <> '' ORDER BY country ASC LIMIT 500";

    /** Beschriftung der Rollen-Auswahl, zugleich die Reihenfolge im Formular. */
    private const ROLLEN_BESCHRIFTUNG = [
        Suchanfrage::ROLLE_ZUECHTER => '🐴 Züchter',
        Suchanfrage::ROLLE_STATION => '🏠 Deckstation',
        Suchanfrage::ROLLE_BESITZER => '👤 Besitzer',
        Suchanfrage::ROLLE_HALTER => '🌾 Halter',
    ];

    public function show(): void {
        if (!$this->hasPermission(Plugin::MODUL, 'view')) {
            $this->renderNotFound('Nicht gefunden.');
        }

        // Die Pferdezahlen und die abgeleiteten Rollen sind Aussagen ÜBER
        // PFERDE und hängen deshalb an horses.view - genau wie die Pferdelisten
        // auf /kontakt. Ohne dieses Recht bliebe "welche Kontakte haben
        // überhaupt Pferde" sonst über den Rollenfilter abfragbar, obwohl die
        // Pferde selbst unsichtbar sind.
        $darfPferdeSehen = $this->hasPermission('horses', 'view');

        $erlaubteRollen = [Suchanfrage::ROLLE_ALLE, Suchanfrage::ROLLE_ZUECHTER];
        if ($darfPferdeSehen) {
            $erlaubteRollen[] = Suchanfrage::ROLLE_STATION;
            $erlaubteRollen[] = Suchanfrage::ROLLE_BESITZER;
            $erlaubteRollen[] = Suchanfrage::ROLLE_HALTER;
        }

        $anfrage = Suchanfrage::aus($_GET, $erlaubteRollen);

        [$bedingung, $werte] = self::bedingung($anfrage);
        $gesamt = $this->zaehlen($bedingung, $werte);
        $seitenzahl = max(1, (int) ceil($gesamt / self::TREFFER_PRO_SEITE));
        $seite = min($seitenzahl, $anfrage->seite);
        $treffer = $this->treffer($bedingung, $werte, $seite);

        $pferdezahlen = $darfPferdeSehen
            ? $this->pferdezahlen(array_column($treffer, 'id'))
            : null;

        PluginPage::render('Zucht', $this->seiteBauen(
            $anfrage,
            $erlaubteRollen,
            $treffer,
            $pferdezahlen,
            $gesamt,
            $seite,
            $seitenzahl
        ));
    }

    /**
     * WHERE-Klausel und Parameter. Die Klausel besteht ausschließlich aus
     * Literalen des Quelltexts; jeder Wert aus der Anfrage steckt hinter
     * einem benannten Platzhalter.
     *
     * Eine Klausel für eine Tabelle - die zweite Fassung für
     * `breeding_stations` ist mit der Tabelle weggefallen (#336).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function bedingung(Suchanfrage $anfrage): array {
        $wo = ['c.is_published = 1', 'c.deleted_at IS NULL'];
        $werte = [];

        $rollenBedingung = self::rollenBedingung($anfrage->rolle);
        if ($rollenBedingung !== null) {
            $wo[] = $rollenBedingung;
        }

        if ($anfrage->name !== '') {
            $wo[] = 'c.name LIKE :name';
            $werte['name'] = Suchanfrage::likeMuster($anfrage->name);
        }
        if ($anfrage->ort !== '') {
            $wo[] = 'c.city LIKE :ort';
            $werte['ort'] = Suchanfrage::likeMuster($anfrage->ort);
        }
        if ($anfrage->bundesland !== '') {
            $wo[] = 'c.state = :bundesland';
            $werte['bundesland'] = $anfrage->bundesland;
        }
        if ($anfrage->land !== '') {
            $wo[] = 'c.country = :land';
            $werte['land'] = $anfrage->land;
        }
        return [implode(' AND ', $wo), $werte];
    }

    /**
     * Der Rollenfilter als SQL-Baustein - null bei ROLLE_ALLE und bei jedem
     * unbekannten Wert (fail-closed über den `default`-Zweig; Suchanfrage
     * lässt ohnehin nur die erlaubten Konstanten durch).
     *
     * Jeder Zweig ist ein vollständiges Literal des Quelltexts, auch die
     * Rollennamen 'owner'/'keeper': Sie stehen hier, weil `match` auf
     * geprüften Konstanten arbeitet - kein Wert aus der Anfrage erreicht diese
     * Zeichenketten.
     *
     * Die drei abgeleiteten Rollen zählen ausschließlich VERÖFFENTLICHTE, nicht
     * gelöschte Pferde. Ohne diese Bedingung wäre der Filter ein Orakel über
     * unveröffentlichte Pferde: "Kontakt X erscheint unter Deckstation, obwohl
     * im Katalog kein Pferd bei ihm steht" verrät genau das, was das
     * Depublizieren verbergen soll (Kern-#121).
     *
     * ROLLE_STATION prüft BEIDE Wege, auf denen ein Pferd eine Deckstation
     * nennt (siehe PublicController::contactDetail()): horses.breeding_station_id
     * ist die aktuelle Station des Pferds, horse_persons.station_contact_id die
     * einer einzelnen - auch historischen - Zuordnungszeile. Nur den ersten zu
     * prüfen ließe jede Station verschwinden, bei der ein Pferd früher stand.
     */
    private static function rollenBedingung(string $rolle): ?string {
        return match ($rolle) {
            Suchanfrage::ROLLE_ZUECHTER => 'c.is_breeder = 1',
            Suchanfrage::ROLLE_STATION =>
                '(EXISTS (SELECT 1 FROM horses hs
                          WHERE hs.breeding_station_id = c.id
                            AND hs.deleted_at IS NULL AND hs.is_published = 1)
                  OR EXISTS (SELECT 1 FROM horse_persons hps
                             JOIN horses hst ON hst.id = hps.horse_id
                                  AND hst.deleted_at IS NULL AND hst.is_published = 1
                             WHERE hps.station_contact_id = c.id))',
            Suchanfrage::ROLLE_BESITZER =>
                "EXISTS (SELECT 1 FROM horse_persons hpo
                         JOIN horses hoo ON hoo.id = hpo.horse_id
                              AND hoo.deleted_at IS NULL AND hoo.is_published = 1
                         WHERE hpo.contact_id = c.id AND hpo.role = 'owner')",
            Suchanfrage::ROLLE_HALTER =>
                "EXISTS (SELECT 1 FROM horse_persons hpk
                         JOIN horses hok ON hok.id = hpk.horse_id
                              AND hok.deleted_at IS NULL AND hok.is_published = 1
                         WHERE hpk.contact_id = c.id AND hpk.role = 'keeper')",
            default => null,
        };
    }

    /** @param array<string, string> $werte */
    private function zaehlen(string $bedingung, array $werte): int {
        $sql = 'SELECT COUNT(*) FROM contacts c WHERE ' . $bedingung;
        $stmt = Database::getInstance()->prepare($sql);
        foreach ($werte as $schluessel => $wert) {
            $stmt->bindValue(':' . $schluessel, $wert, PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, string> $werte
     * @return array<int, array<string, mixed>>
     */
    private function treffer(string $bedingung, array $werte, int $seite): array {
        // Spaltenliste als Literal, nicht als SELECT * - der Kern verbietet
        // SELECT * auf `contacts` in einem öffentlichen Pfad ausdrücklich
        // (docs/kontaktliste-umstellung.md, Lehre aus #293). Hier stehen
        // ausschließlich Spalten aus der Gruppe "öffentlich immer"; was nicht
        // dasteht, kann später niemand versehentlich ausgeben.
        $sql = 'SELECT c.id, c.name, c.city, c.state, c.country, c.is_breeder'
            . ' FROM contacts c'
            . ' WHERE ' . $bedingung
            . ' ORDER BY c.name ASC, c.id ASC LIMIT :limit OFFSET :offset';

        $stmt = Database::getInstance()->prepare($sql);
        foreach ($werte as $schluessel => $wert) {
            $stmt->bindValue(':' . $schluessel, $wert, PDO::PARAM_STR);
        }
        // Ohne PARAM_INT bindet PDO die Grenzen als Zeichenketten - mit
        // ATTR_EMULATE_PREPARES = false (siehe src/Database.php) lehnt MySQL
        // "LIMIT '50'" ab.
        $stmt->bindValue(':limit', self::TREFFER_PRO_SEITE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($seite - 1) * self::TREFFER_PRO_SEITE, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * ZWEI Zahlen je Treffer, nicht eine (#122): Ein Kontakt hängt seit #336
     * auf zwei Wegen an Pferden, und der Kern zeigt beide getrennt - auf
     * /kontakt als zwei Blöcke, in der Verwaltungsliste als zwei Spalten.
     * Sie zu addieren wäre falsch, denn "hat dieses Pferd gezüchtet" und
     * "dieses Pferd stand hier" sind verschiedene Aussagen über dasselbe
     * Pferd; die Summe zählte es doppelt.
     *
     *   'zuordnung'  Pferde, denen der Kontakt als Züchter, Besitzer oder
     *                Halter zugeordnet ist (horse_persons.contact_id)
     *   'station'    Pferde, die ihn als Deckstation nennen - beide Wege,
     *                siehe rollenBedingung()
     *
     * Je Zahl eine Abfrage für die ganze Seite statt einer je Zeile. Innerhalb
     * einer Zahl zählt jedes Pferd genau einmal, auch bei mehreren
     * Zuordnungszeilen (Züchter UND Besitzer ist der Normalfall, nicht die
     * Ausnahme).
     *
     * @param array<int, mixed> $ids
     * @return array{zuordnung: array<int, int>, station: array<int, int>}
     */
    private function pferdezahlen(array $ids): array {
        $ids = array_map('intval', $ids);
        if ($ids === []) {
            return ['zuordnung' => [], 'station' => []];
        }

        $platzhalter = implode(',', array_fill(0, count($ids), '?'));

        $zuordnung = $this->zaehlabfrage(
            'SELECT hp.contact_id AS schluessel, COUNT(DISTINCT h.id) AS anzahl
             FROM horse_persons hp
             JOIN horses h ON h.id = hp.horse_id AND h.deleted_at IS NULL AND h.is_published = 1
             WHERE hp.contact_id IN (' . $platzhalter . ')
             GROUP BY hp.contact_id',
            $ids
        );

        // UNION ALL über die beiden Wege, danach COUNT(DISTINCT ...): Ein Pferd,
        // das denselben Kontakt sowohl als aktuelle Station führt als auch in
        // einer Zuordnungszeile nennt, zählt einmal. Ein OR im JOIN täte es
        // fachlich auch, zwänge MySQL aber zu einem Durchlauf über `horses` je
        // Kontakt statt zu zwei Index-Zugriffen.
        $station = $this->zaehlabfrage(
            'SELECT schluessel, COUNT(DISTINCT horse_id) AS anzahl FROM (
                 SELECT h.breeding_station_id AS schluessel, h.id AS horse_id
                 FROM horses h
                 WHERE h.deleted_at IS NULL AND h.is_published = 1
                   AND h.breeding_station_id IN (' . $platzhalter . ')
                 UNION ALL
                 SELECT hp.station_contact_id AS schluessel, h.id AS horse_id
                 FROM horse_persons hp
                 JOIN horses h ON h.id = hp.horse_id AND h.deleted_at IS NULL AND h.is_published = 1
                 WHERE hp.station_contact_id IN (' . $platzhalter . ')
             ) t GROUP BY schluessel',
            array_merge($ids, $ids)
        );

        return ['zuordnung' => $zuordnung, 'station' => $station];
    }

    /**
     * Führt eine Zählabfrage mit ausschließlich ganzzahligen Parametern aus.
     *
     * @param array<int, int> $ids
     * @return array<int, int>  Kontakt-ID => Anzahl
     */
    private function zaehlabfrage(string $sql, array $ids): array {
        $stmt = Database::getInstance()->prepare($sql);
        foreach (array_values($ids) as $position => $id) {
            $stmt->bindValue($position + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $zahlen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
            $zahlen[(int) $zeile['schluessel']] = (int) $zeile['anzahl'];
        }
        return $zahlen;
    }

    /** @return array<int, string> */
    private function werteliste(string $sql): array {
        $spalte = Database::getInstance()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $spalte);
    }

    /**
     * @param array<int, string> $erlaubteRollen
     * @param array<int, array<string, mixed>> $treffer
     * @param array{zuordnung: array<int, int>, station: array<int, int>}|null $pferdezahlen
     *        null = Gäste dürfen keine Pferde sehen
     */
    private function seiteBauen(
        Suchanfrage $anfrage,
        array $erlaubteRollen,
        array $treffer,
        ?array $pferdezahlen,
        int $gesamt,
        int $seite,
        int $seitenzahl
    ): string {
        // Addon-eigene Geometrie; Farben ausschließlich über die
        // Theme-Variablen des Kerns, damit Darkmode und Markenfarbe greifen.
        $html = '<style>
            .zucht-filter{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0 1rem;}
            .zucht-tabelle{width:100%;border-collapse:collapse;}
            .zucht-tabelle th,.zucht-tabelle td{text-align:left;padding:0.55rem 0.6rem;border-bottom:1px solid var(--border-color);vertical-align:top;}
            .zucht-tabelle th{color:var(--text-muted);font-weight:600;}
            .zucht-tabelle tr:nth-child(even) td{background:var(--surface-muted);}
            .zucht-leer{color:var(--text-muted);}
            .zucht-hinweis{color:var(--text-muted);font-size:0.9rem;}
        </style>';

        $html .= '<div class="card">';
        $html .= '<h1>🧬 Zucht</h1>';
        $html .= '<p class="zucht-hinweis">Züchter und Deckstationen finden - ohne den Umweg über ein einzelnes Pferd.</p>';

        $html .= $this->filterformular($anfrage, $erlaubteRollen);
        $html .= $this->trefferliste($treffer, $pferdezahlen, $gesamt);
        $html .= $this->blaetterleiste($anfrage, $seite, $seitenzahl);

        if ($pferdezahlen !== null && $treffer !== []) {
            $html .= '<p class="zucht-hinweis">„Pferde" zählt die veröffentlichten Pferde, die diesem Kontakt '
                . 'als Züchter, Besitzer oder Halter zugeordnet sind - je Pferd einmal, auch bei mehreren Rollen. '
                . '„Als Deckstation" zählt die veröffentlichten Pferde, die ihn als Deckstation nennen. '
                . 'Dasselbe Pferd kann in beiden Spalten stehen.</p>';
        }

        $html .= '</div>';
        return $html;
    }

    /** @param array<int, string> $erlaubteRollen */
    private function filterformular(Suchanfrage $anfrage, array $erlaubteRollen): string {
        // GET, damit eine Suche verlinkbar und lesbar bleibt - und bewusst
        // ohne CSRF-Token: Das Addon hat keinen schreibenden Endpunkt, ein
        // Token in der Adresszeile schützte nichts und landete in Protokollen
        // und Referrern. Der Kern hält es beim Katalogfilter genauso.
        $html = '<form method="GET" action="' . Plugin::SEITE . '">';
        $html .= '<div class="zucht-filter">';

        // Die Rolle steht als erstes Feld - sie hat die beiden Reiter ersetzt
        // (#122) und ist der Filter, mit dem die Seite gemeint ist.
        // Angeboten werden nur die Rollen, die die Berechtigungen zulassen;
        // ein von Hand gesetztes ?rolle=station fällt in Suchanfrage::aus()
        // ohnehin auf "(alle)" zurück.
        $rollen = [];
        foreach (self::ROLLEN_BESCHRIFTUNG as $wert => $beschriftung) {
            if (in_array($wert, $erlaubteRollen, true)) {
                $rollen[$wert] = $beschriftung;
            }
        }
        $html .= self::auswahlfeldMitSchluessel('rolle', 'Rolle', $rollen, $anfrage->rolle, '(alle)');

        $html .= self::textfeld('name', 'Name', $anfrage->name);
        $html .= self::textfeld('ort', 'Ort', $anfrage->ort);
        $html .= self::auswahlfeld(
            'bundesland',
            'Bundesland / Kanton',
            $this->werteliste(self::SQL_BUNDESLAENDER),
            $anfrage->bundesland
        );
        $html .= self::auswahlfeld(
            'land',
            'Land',
            $this->werteliste(self::SQL_LAENDER),
            $anfrage->land
        );

        $html .= '</div>';
        $html .= '<p><button type="submit" class="btn">Suchen</button> '
            . '<a class="btn btn-secondary" href="' . self::link([]) . '">Filter zurücksetzen</a></p>';
        return $html . '</form>';
    }

    /**
     * @param array<int, array<string, mixed>> $treffer
     * @param array{zuordnung: array<int, int>, station: array<int, int>}|null $pferdezahlen
     */
    private function trefferliste(array $treffer, ?array $pferdezahlen, int $gesamt): string {
        if ($treffer === []) {
            return '<p class="zucht-leer">Keine Treffer. Vielleicht hilft ein weiterer Filter - oder die Suche ohne Ort.</p>';
        }

        $html = '<p class="zucht-hinweis">' . (int) $gesamt . ' Kontakte gefunden.</p>';

        $html .= '<div class="tabelle-scroll"><table class="zucht-tabelle"><thead><tr>';
        $html .= '<th scope="col">Name</th><th scope="col">Ort</th>';
        $html .= '<th scope="col">Bundesland / Kanton</th><th scope="col">Land</th>';
        if ($pferdezahlen !== null) {
            $html .= '<th scope="col">Pferde</th><th scope="col">Als Deckstation</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($treffer as $zeile) {
            $id = (int) $zeile['id'];
            // Ein Ziel statt zweier (#336): /person?id= und /station?id=
            // leiten zwar dauerhaft auf /kontakt?id= um, aber über
            // contact_id_map und mit der ALTEN Kennung - die neue steht hier
            // längst zur Verfügung, ein Umweg über eine 301 wäre sinnlos.
            $ziel = '/kontakt?id=' . $id;

            $html .= '<tr>';
            $html .= '<td><a href="' . $ziel . '">' . self::sicher($zeile['name']) . '</a>';
            if (!empty($zeile['is_breeder'])) {
                // Dasselbe Zeichen wie in der Verwaltungsliste des Kerns: Es
                // sagt, warum ein Kontakt unter "Züchter" auftaucht, ohne
                // dafür eine eigene Spalte zu brauchen.
                $html .= ' <span title="Als Züchter gekennzeichnet">🐴</span>';
            }
            $html .= '</td>';
            $html .= '<td>' . self::sicherOderStrich($zeile['city'] ?? '') . '</td>';
            $html .= '<td>' . self::sicherOderStrich($zeile['state'] ?? '') . '</td>';
            $html .= '<td>' . self::sicherOderStrich($zeile['country'] ?? '') . '</td>';
            if ($pferdezahlen !== null) {
                $html .= '<td>' . (int) ($pferdezahlen['zuordnung'][$id] ?? 0) . '</td>';
                $html .= '<td>' . (int) ($pferdezahlen['station'][$id] ?? 0) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private function blaetterleiste(Suchanfrage $anfrage, int $seite, int $seitenzahl): string {
        if ($seitenzahl < 2) {
            return '';
        }

        $html = '<p>';
        if ($seite > 1) {
            $html .= '<a class="btn btn-secondary" href="'
                . self::link($anfrage->alsQuery(['seite' => (string) ($seite - 1)])) . '">&laquo; Zurück</a> ';
        }
        $html .= 'Seite ' . (int) $seite . ' von ' . (int) $seitenzahl;
        if ($seite < $seitenzahl) {
            $html .= ' <a class="btn btn-secondary" href="'
                . self::link($anfrage->alsQuery(['seite' => (string) ($seite + 1)])) . '">Weiter &raquo;</a>';
        }
        return $html . '</p>';
    }

    /** @param array<string, string> $query */
    private static function link(array $query): string {
        if ($query === []) {
            return htmlspecialchars(Plugin::SEITE, ENT_QUOTES, 'UTF-8');
        }
        return htmlspecialchars(Plugin::SEITE . '?' . http_build_query($query), ENT_QUOTES, 'UTF-8');
    }

    private static function textfeld(string $name, string $label, string $wert): string {
        return '<div class="form-group">'
            . '<label for="zs-' . $name . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>'
            . '<input type="text" class="form-control" id="zs-' . $name . '" name="' . $name . '"'
            . ' maxlength="' . Suchanfrage::TEXT_MAX . '"'
            . ' value="' . self::sicher($wert) . '">'
            . '</div>';
    }

    /** @param array<int, string> $optionen */
    private static function auswahlfeld(string $name, string $label, array $optionen, string $gewaehlt): string {
        // Ein von Hand gesetzter Wert, den es im Bestand nicht (mehr) gibt,
        // wird trotzdem als Option angeboten - sonst zeigte das Formular
        // "(alle)" an, während die Abfrage weiterhin filtert und nichts
        // findet. Das Formular soll sagen, was tatsächlich gilt.
        if ($gewaehlt !== '' && !in_array($gewaehlt, $optionen, true)) {
            array_unshift($optionen, $gewaehlt);
        }

        $paare = [];
        foreach ($optionen as $wert) {
            $paare[$wert] = $wert;
        }

        return self::auswahlfeldMitSchluessel($name, $label, $paare, $gewaehlt, '(alle)');
    }

    /**
     * Auswahlfeld mit eigenem Wert je Beschriftung - für den Rollenfilter, bei
     * dem der übertragene Wert ('station') und die Beschriftung
     * ('🏠 Deckstation') auseinanderfallen.
     *
     * @param array<string, string> $optionen  Wert => Beschriftung
     */
    private static function auswahlfeldMitSchluessel(
        string $name,
        string $label,
        array $optionen,
        string $gewaehlt,
        string $leerBeschriftung
    ): string {
        $html = '<div class="form-group">'
            . '<label for="zs-' . $name . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>'
            . '<select class="form-control" id="zs-' . $name . '" name="' . $name . '">'
            . '<option value="">' . htmlspecialchars($leerBeschriftung, ENT_QUOTES, 'UTF-8') . '</option>';

        foreach ($optionen as $wert => $beschriftung) {
            $html .= '<option value="' . self::sicher($wert) . '"'
                . ((string) $wert === $gewaehlt ? ' selected' : '') . '>'
                . self::sicher($beschriftung) . '</option>';
        }

        return $html . '</select></div>';
    }

    private static function sicher(mixed $wert): string {
        return htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8');
    }

    private static function sicherOderStrich(mixed $wert): string {
        $text = trim((string) $wert);
        return $text === '' ? '–' : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
