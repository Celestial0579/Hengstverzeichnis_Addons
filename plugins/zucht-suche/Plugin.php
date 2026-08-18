<?php
// zucht-suche/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#105. Öffentliche Einstiegsseite
// "Zucht" unter /plugin/zucht-suche, auf der sich ZÜCHTER und DECKSTATIONEN
// suchen lassen. Bis dahin führte der Weg zu einer Person oder Station immer
// über ein Pferd - die Frage "welche Züchter gibt es in meiner Region" hatte
// keinen Einstieg, obwohl die Daten seit Kern-#293 da sind.
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

    public function register(HookManager $hooks): void {
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
        // Der eigentliche Menüpunkt "Zucht" neben "Verzeichnis" (Kern 0.7.0,
        // Filter layout.nav_items). Genau dafür ist dieses Addon da: Züchter
        // und Deckstationen sollen einen eigenen Einstieg haben, nicht nur
        // über ein einzelnes Pferd erreichbar sein.
        $hooks->addFilter('layout.nav_items', [$this, 'addNavItem']);
        // Zusätzlich ein Verweis auf den drei Detailseiten - dort stößt ein
        // Besucher heute überhaupt erst auf einen Züchter oder eine Station,
        // und von dort ist der Weg zur Suche am kürzesten.
        $hooks->addFilter('horse.detail_sections', [$this, 'addHorseSection']);
        $hooks->addFilter('person.detail_sections', [$this, 'addPersonSection']);
        $hooks->addFilter('station.detail_sections', [$this, 'addStationSection']);
    }

    /**
     * @param array<int, array{url:string, label:string, icon:string}> $tiles
     * @return array<int, array{url:string, label:string, icon:string}>
     */
    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => self::SEITE,
            'label' => 'Zucht-Suche',
            'icon' => '🧬',
        ];
        return $tiles;
    }

    /**
     * Menüpunkt in der öffentlichen Navigation.
     *
     * Er entfällt, wenn Gäste weder Personen noch Deckstationen sehen dürfen -
     * ein Menüpunkt, der auf eine Seite führt, die selbst mit 404 antwortet,
     * wäre eine Sackgasse. Dieselbe Prüfung wie bei den Verweisen auf den
     * Detailseiten, siehe mitHinweis().
     *
     * @param array<int, array{url:string, label:string, icon:string}> $items
     * @return array<int, array{url:string, label:string, icon:string}>
     */
    public function addNavItem(array $items): array {
        if (!self::gastDarfSehen('persons') && !self::gastDarfSehen('breeding_stations')) {
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
     * @param array<int, string> $sections
     * @param array<string, mixed> $person
     * @param array<string, array<int, array<string, mixed>>> $horsesByRole
     * @return array<int, string>
     */
    public function addPersonSection(array $sections, array $person, array $horsesByRole): array {
        return self::mitHinweis($sections);
    }

    /**
     * @param array<int, string> $sections
     * @param array<string, mixed> $station
     * @param array<int, array<string, mixed>> $horses
     * @return array<int, string>
     */
    public function addStationSection(array $sections, array $station, array $horses): array {
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
     * unverändert zurück, wenn Gäste weder Personen noch Deckstationen sehen
     * dürfen - ein Link auf eine Seite, die dann selbst mit 404 antwortet,
     * wäre eine Sackgasse.
     *
     * @param array<int, string> $sections
     * @return array<int, string>
     */
    private static function mitHinweis(array $sections): array {
        $gattungen = [];
        if (self::gastDarfSehen('persons')) {
            $gattungen[] = 'Züchter';
        }
        if (self::gastDarfSehen('breeding_stations')) {
            $gattungen[] = 'Deckstationen';
        }
        if ($gattungen === []) {
            return $sections;
        }

        $sections[] = '<p style="margin-top:1rem;color:var(--text-muted);">'
            . '🧬 <a href="' . self::SEITE . '">Zucht-Suche</a> - '
            . htmlspecialchars(implode(' und ', $gattungen), ENT_QUOTES, 'UTF-8')
            . ' nach Ort, Bundesland/Kanton und Land finden.'
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

    public const ART_ZUECHTER = 'zuechter';
    public const ART_STATIONEN = 'stationen';

    /**
     * Obergrenze je Textfeld. Die Spalten sind VARCHAR(100)/(150) - längere
     * Eingaben können ohnehin nichts treffen und blähen nur Abfrage und
     * Blätter-Links auf.
     */
    public const TEXT_MAX = 100;

    private function __construct(
        public readonly string $art,
        public readonly string $name,
        public readonly string $ort,
        public readonly string $bundesland,
        public readonly string $land,
        public readonly string $mitglied,
        public readonly int $seite,
    ) {}

    /**
     * @param array<string, mixed> $eingabe  üblicherweise $_GET
     * @param array<int, string> $erlaubteArten  Reiter, die die Berechtigungen
     *        zulassen; der erste Eintrag ist der Standard. Leer nur, wenn gar
     *        nichts sichtbar ist - dann bleibt auch die Art leer.
     */
    public static function aus(array $eingabe, array $erlaubteArten): self {
        $art = self::text($eingabe['art'] ?? '');
        if (!in_array($art, $erlaubteArten, true)) {
            $art = $erlaubteArten[0] ?? '';
        }

        // Der Mitgliedsstatus gehört zur Person; bei Deckstationen gibt es die
        // Spalte nicht. Er wird deshalb hier verworfen statt später in der
        // Abfrage ignoriert - sonst zeigte das Formular einen Filter an, der
        // nichts tut.
        $mitglied = $art === self::ART_ZUECHTER ? self::text($eingabe['mitglied'] ?? '') : '';

        return new self(
            $art,
            self::text($eingabe['name'] ?? ''),
            self::text($eingabe['ort'] ?? ''),
            self::text($eingabe['bundesland'] ?? ''),
            self::text($eingabe['land'] ?? ''),
            $mitglied,
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
     * Die gesetzten Filter als Query-Parameter, Grundlage für Reiter- und
     * Blätter-Links: Ein Seitenwechsel darf die Suche nicht verwerfen.
     * Die Seitennummer ist bewusst NICHT enthalten - wer den Reiter wechselt
     * oder einen Filter ändert, fängt bei Seite 1 an; die Blätter-Links
     * setzen sie ausdrücklich.
     *
     * @param array<string, string> $ueberschreiben  leerer Wert entfernt den Parameter
     * @return array<string, string>
     */
    public function alsQuery(array $ueberschreiben = []): array {
        $query = ['art' => $this->art];
        foreach ([
            'name' => $this->name,
            'ort' => $this->ort,
            'bundesland' => $this->bundesland,
            'land' => $this->land,
            'mitglied' => $this->mitglied,
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
 * Die öffentliche Suchseite.
 *
 * Sichtbarkeit exakt wie im Kern (PublicController::personDetail() /
 * stationDetail()): Züchter erscheinen nur mit `persons.view` der
 * Gast-Gruppe, Deckstationen nur mit `breeding_stations.view`, und die Zahl
 * der zugeordneten Pferde nur mit `horses.view`. Fehlt beides, antwortet die
 * Seite mit 404 statt mit einer leeren Liste - eine leere Liste wäre die
 * Aussage "es gibt keine", und die stimmt dann nicht.
 *
 * Datenschutz: Ausgegeben werden ausschließlich die öffentlichen
 * Personenfelder (Ort, Bundesland/Kanton, Land, Mitgliedsstatus). E-Mail,
 * Telefon und Mobil werden nicht einmal abgefragt - eine Trefferliste braucht
 * sie nicht, ihr Zweck ist der Klick auf /person?id= bzw. /station?id=, und
 * dort entscheidet der Kern anhand von contact_public. Was gar nicht erst
 * ankommt, kann auch der nächste nicht versehentlich ausgeben (so begründet
 * der Kern seine Spaltenliste in PublicController::personDetail()).
 */
class SucheController extends BaseController {

    private const TREFFER_PRO_SEITE = 50;

    /**
     * Auswahllisten der Filter. Vollständig literale Abfragen statt eines
     * zusammengesetzten Spaltennamens: Der Spaltenname ist der eine Teil
     * einer SQL-Anweisung, den ein Platzhalter nicht binden kann - also darf
     * er auch nie aus einer Variablen kommen. Das LIMIT deckelt den Fall
     * "Freitextfeld wurde als Sammelbecken benutzt".
     */
    private const SQL_ZUECHTER_BUNDESLAENDER = "SELECT DISTINCT state FROM persons WHERE is_breeder = 1 AND is_published = 1 AND deleted_at IS NULL AND state IS NOT NULL AND state <> '' ORDER BY state ASC LIMIT 500";
    private const SQL_ZUECHTER_LAENDER = "SELECT DISTINCT country FROM persons WHERE is_breeder = 1 AND is_published = 1 AND deleted_at IS NULL AND country IS NOT NULL AND country <> '' ORDER BY country ASC LIMIT 500";
    private const SQL_ZUECHTER_MITGLIEDSSTATUS = "SELECT DISTINCT membership_status FROM persons WHERE is_breeder = 1 AND is_published = 1 AND deleted_at IS NULL AND membership_status IS NOT NULL AND membership_status <> '' ORDER BY membership_status ASC LIMIT 500";
    private const SQL_STATIONEN_BUNDESLAENDER = "SELECT DISTINCT state FROM breeding_stations WHERE is_published = 1 AND deleted_at IS NULL AND state IS NOT NULL AND state <> '' ORDER BY state ASC LIMIT 500";
    private const SQL_STATIONEN_LAENDER = "SELECT DISTINCT country FROM breeding_stations WHERE is_published = 1 AND deleted_at IS NULL AND country IS NOT NULL AND country <> '' ORDER BY country ASC LIMIT 500";

    public function show(): void {
        $erlaubteArten = [];
        if ($this->hasPermission('persons', 'view')) {
            $erlaubteArten[] = Suchanfrage::ART_ZUECHTER;
        }
        if ($this->hasPermission('breeding_stations', 'view')) {
            $erlaubteArten[] = Suchanfrage::ART_STATIONEN;
        }
        if ($erlaubteArten === []) {
            $this->renderNotFound('Nicht gefunden.');
        }

        $anfrage = Suchanfrage::aus($_GET, $erlaubteArten);
        $istZuechter = $anfrage->art === Suchanfrage::ART_ZUECHTER;

        [$bedingung, $werte] = self::bedingung($anfrage);
        $gesamt = $this->zaehlen($istZuechter, $bedingung, $werte);
        $seitenzahl = max(1, (int) ceil($gesamt / self::TREFFER_PRO_SEITE));
        $seite = min($seitenzahl, $anfrage->seite);
        $treffer = $this->treffer($istZuechter, $bedingung, $werte, $seite);

        // Die Pferdezahl ist eine Aussage über Pferde und hängt deshalb an
        // horses.view - genau wie die Pferdelisten auf /person und /station.
        $pferdezahlen = $this->hasPermission('horses', 'view')
            ? $this->pferdezahlen($istZuechter, array_column($treffer, 'id'))
            : null;

        PluginPage::render('Zucht', $this->seiteBauen(
            $anfrage,
            $erlaubteArten,
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
     * Die Spaltennamen sind in beiden Tabellen gleich (name, city, state,
     * country) - nur is_breeder und membership_status gibt es allein bei
     * Personen. Deshalb reicht eine gemeinsame Klausel.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function bedingung(Suchanfrage $anfrage): array {
        $istZuechter = $anfrage->art === Suchanfrage::ART_ZUECHTER;

        $wo = $istZuechter
            ? ['is_breeder = 1', 'is_published = 1', 'deleted_at IS NULL']
            : ['is_published = 1', 'deleted_at IS NULL'];
        $werte = [];

        if ($anfrage->name !== '') {
            $wo[] = 'name LIKE :name';
            $werte['name'] = Suchanfrage::likeMuster($anfrage->name);
        }
        if ($anfrage->ort !== '') {
            $wo[] = 'city LIKE :ort';
            $werte['ort'] = Suchanfrage::likeMuster($anfrage->ort);
        }
        if ($anfrage->bundesland !== '') {
            $wo[] = 'state = :bundesland';
            $werte['bundesland'] = $anfrage->bundesland;
        }
        if ($anfrage->land !== '') {
            $wo[] = 'country = :land';
            $werte['land'] = $anfrage->land;
        }
        if ($istZuechter && $anfrage->mitglied !== '') {
            $wo[] = 'membership_status = :mitglied';
            $werte['mitglied'] = $anfrage->mitglied;
        }

        return [implode(' AND ', $wo), $werte];
    }

    /** @param array<string, string> $werte */
    private function zaehlen(bool $istZuechter, string $bedingung, array $werte): int {
        $sql = 'SELECT COUNT(*) FROM ' . self::tabelle($istZuechter) . ' WHERE ' . $bedingung;
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
    private function treffer(bool $istZuechter, string $bedingung, array $werte, int $seite): array {
        // Spaltenliste als Literal, nicht als SELECT * - dieselbe Begründung
        // wie im Kern: persons enthält E-Mail, Telefon, Mobil und die
        // Anschrift, und was hier nicht steht, kann später niemand
        // versehentlich ausgeben.
        $spalten = $istZuechter
            ? 'id, name, city, state, country, membership_status'
            : 'id, name, postal_code, city, state, country';

        $sql = 'SELECT ' . $spalten
            . ' FROM ' . self::tabelle($istZuechter)
            . ' WHERE ' . $bedingung
            . ' ORDER BY name ASC, id ASC LIMIT :limit OFFSET :offset';

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
     * Zahl der zugeordneten, veröffentlichten Pferde je Treffer der aktuellen
     * Seite - eine einzige Abfrage für die ganze Seite statt einer je Zeile.
     *
     * Bei Züchtern zählt jedes Pferd genau einmal, auch wenn die Person ihm
     * mit mehreren Rollen zugeordnet ist (Züchter UND Besitzer ist der
     * Normalfall, nicht die Ausnahme).
     *
     * @param array<int, mixed> $ids
     * @return array<int, int>  Treffer-ID => Anzahl
     */
    private function pferdezahlen(bool $istZuechter, array $ids): array {
        $ids = array_map('intval', $ids);
        if ($ids === []) {
            return [];
        }

        $platzhalter = implode(',', array_fill(0, count($ids), '?'));
        $sql = $istZuechter
            ? 'SELECT hp.person_id AS schluessel, COUNT(DISTINCT h.id) AS anzahl
               FROM horse_persons hp
               JOIN horses h ON h.id = hp.horse_id AND h.deleted_at IS NULL AND h.is_published = 1
               WHERE hp.person_id IN (' . $platzhalter . ')
               GROUP BY hp.person_id'
            : 'SELECT breeding_station_id AS schluessel, COUNT(*) AS anzahl
               FROM horses
               WHERE deleted_at IS NULL AND is_published = 1 AND breeding_station_id IN (' . $platzhalter . ')
               GROUP BY breeding_station_id';

        $stmt = Database::getInstance()->prepare($sql);
        foreach ($ids as $position => $id) {
            $stmt->bindValue($position + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $zahlen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
            $zahlen[(int) $zeile['schluessel']] = (int) $zeile['anzahl'];
        }
        return $zahlen;
    }

    private static function tabelle(bool $istZuechter): string {
        return $istZuechter ? 'persons' : 'breeding_stations';
    }

    /** @return array<int, string> */
    private function werteliste(string $sql): array {
        $spalte = Database::getInstance()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $spalte);
    }

    /**
     * @param array<int, string> $erlaubteArten
     * @param array<int, array<string, mixed>> $treffer
     * @param array<int, int>|null $pferdezahlen  null = Gäste dürfen keine Pferde sehen
     */
    private function seiteBauen(
        Suchanfrage $anfrage,
        array $erlaubteArten,
        array $treffer,
        ?array $pferdezahlen,
        int $gesamt,
        int $seite,
        int $seitenzahl
    ): string {
        $istZuechter = $anfrage->art === Suchanfrage::ART_ZUECHTER;

        // Addon-eigene Geometrie; Farben ausschließlich über die
        // Theme-Variablen des Kerns, damit Darkmode und Markenfarbe greifen.
        $html = '<style>
            .zucht-reiter{display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1.2rem;}
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

        $html .= $this->reiter($anfrage, $erlaubteArten);
        $html .= $this->filterformular($anfrage, $istZuechter);
        $html .= $this->trefferliste($istZuechter, $treffer, $pferdezahlen, $gesamt);
        $html .= $this->blaetterleiste($anfrage, $seite, $seitenzahl);

        if ($pferdezahlen !== null && $treffer !== []) {
            $html .= '<p class="zucht-hinweis">Die Spalte „Pferde" zählt die veröffentlichten Pferde, '
                . 'die diesem Eintrag zugeordnet sind - je Pferd einmal, auch bei mehreren Rollen.</p>';
        }

        $html .= '</div>';
        return $html;
    }

    /** @param array<int, string> $erlaubteArten */
    private function reiter(Suchanfrage $anfrage, array $erlaubteArten): string {
        if (count($erlaubteArten) < 2) {
            // Nur eine Gattung sichtbar: Ein Reiter, der allein steht und
            // immer aktiv ist, wäre eine Schaltfläche ohne Wirkung.
            return '';
        }

        $beschriftung = [
            Suchanfrage::ART_ZUECHTER => '👤 Züchter',
            Suchanfrage::ART_STATIONEN => '🏠 Deckstationen',
        ];

        $html = '<div class="zucht-reiter">';
        foreach ($erlaubteArten as $art) {
            $aktiv = $art === $anfrage->art;
            // Beim Wechsel zu den Deckstationen fällt der Mitgliedsstatus
            // heraus: Suchanfrage verwirft ihn dort ohnehin, er stünde sonst
            // wirkungslos in der Adresszeile und sähe wie ein aktiver Filter aus.
            $ueberschreiben = $art === Suchanfrage::ART_ZUECHTER
                ? ['art' => $art]
                : ['art' => $art, 'mitglied' => ''];
            $html .= '<a class="btn' . ($aktiv ? '' : ' btn-secondary') . '"'
                . ' href="' . self::link($anfrage->alsQuery($ueberschreiben)) . '"'
                . ($aktiv ? ' aria-current="page"' : '') . '>'
                . htmlspecialchars($beschriftung[$art], ENT_QUOTES, 'UTF-8')
                . '</a>';
        }
        return $html . '</div>';
    }

    private function filterformular(Suchanfrage $anfrage, bool $istZuechter): string {
        // GET, damit eine Suche verlinkbar und lesbar bleibt - und bewusst
        // ohne CSRF-Token: Das Addon hat keinen schreibenden Endpunkt, ein
        // Token in der Adresszeile schützte nichts und landete in Protokollen
        // und Referrern. Der Kern hält es beim Katalogfilter genauso.
        $html = '<form method="GET" action="' . Plugin::SEITE . '">';
        $html .= '<input type="hidden" name="art" value="' . htmlspecialchars($anfrage->art, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<div class="zucht-filter">';

        $html .= self::textfeld('name', 'Name', $anfrage->name);
        $html .= self::textfeld('ort', 'Ort', $anfrage->ort);
        $html .= self::auswahlfeld(
            'bundesland',
            'Bundesland / Kanton',
            $this->werteliste($istZuechter ? self::SQL_ZUECHTER_BUNDESLAENDER : self::SQL_STATIONEN_BUNDESLAENDER),
            $anfrage->bundesland
        );
        $html .= self::auswahlfeld(
            'land',
            'Land',
            $this->werteliste($istZuechter ? self::SQL_ZUECHTER_LAENDER : self::SQL_STATIONEN_LAENDER),
            $anfrage->land
        );
        if ($istZuechter) {
            $html .= self::auswahlfeld(
                'mitglied',
                'Mitgliedsstatus',
                $this->werteliste(self::SQL_ZUECHTER_MITGLIEDSSTATUS),
                $anfrage->mitglied
            );
        }

        $html .= '</div>';
        $html .= '<p><button type="submit" class="btn">Suchen</button> '
            . '<a class="btn btn-secondary" href="' . self::link(['art' => $anfrage->art]) . '">Filter zurücksetzen</a></p>';
        return $html . '</form>';
    }

    /**
     * @param array<int, array<string, mixed>> $treffer
     * @param array<int, int>|null $pferdezahlen
     */
    private function trefferliste(
        bool $istZuechter,
        array $treffer,
        ?array $pferdezahlen,
        int $gesamt
    ): string {
        if ($treffer === []) {
            return '<p class="zucht-leer">Keine Treffer. Vielleicht hilft ein weiterer Filter - oder die Suche ohne Ort.</p>';
        }

        $html = '<p class="zucht-hinweis">' . (int) $gesamt . ' '
            . ($istZuechter ? 'Züchter' : 'Deckstationen') . ' gefunden.</p>';

        $html .= '<table class="zucht-tabelle"><thead><tr>';
        $html .= '<th scope="col">Name</th><th scope="col">Ort</th>';
        $html .= '<th scope="col">Bundesland / Kanton</th><th scope="col">Land</th>';
        if ($istZuechter) {
            $html .= '<th scope="col">Mitgliedsstatus</th>';
        }
        if ($pferdezahlen !== null) {
            $html .= '<th scope="col">Pferde</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($treffer as $zeile) {
            $id = (int) $zeile['id'];
            $ziel = ($istZuechter ? '/person?id=' : '/station?id=') . $id;

            // Bei Deckstationen ist die Anschrift eine Geschäftsadresse und
            // vollständig öffentlich - die PLZ darf deshalb mit in die
            // Ortsspalte. Bei Personen bleibt sie intern (zustellbare Angabe).
            $ort = $istZuechter
                ? (string) ($zeile['city'] ?? '')
                : trim(((string) ($zeile['postal_code'] ?? '')) . ' ' . ((string) ($zeile['city'] ?? '')));

            $html .= '<tr>';
            $html .= '<td><a href="' . $ziel . '">' . self::sicher($zeile['name']) . '</a></td>';
            $html .= '<td>' . self::sicherOderStrich($ort) . '</td>';
            $html .= '<td>' . self::sicherOderStrich($zeile['state'] ?? '') . '</td>';
            $html .= '<td>' . self::sicherOderStrich($zeile['country'] ?? '') . '</td>';
            if ($istZuechter) {
                $html .= '<td>' . self::sicherOderStrich($zeile['membership_status'] ?? '') . '</td>';
            }
            if ($pferdezahlen !== null) {
                $html .= '<td>' . (int) ($pferdezahlen[$id] ?? 0) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
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

        $html = '<div class="form-group">'
            . '<label for="zs-' . $name . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>'
            . '<select class="form-control" id="zs-' . $name . '" name="' . $name . '">'
            . '<option value="">(alle)</option>';

        foreach ($optionen as $wert) {
            $sicher = self::sicher($wert);
            $html .= '<option value="' . $sicher . '"' . ($wert === $gewaehlt ? ' selected' : '') . '>' . $sicher . '</option>';
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
