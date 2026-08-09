<?php
// farbvererbung/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: Farbvererbungsrechner für das
// Norwegische Fjordpferd. Schätzt die Wahrscheinlichkeitsverteilung der
// Fohlenfarbe aus den Farben zweier Elterntiere anhand der bekannten
// Farbgenetik der Rasse (die fünf anerkannten Falbfarben).
//
// Installation (lokal im Framework-Repo):
//   cp -r farbvererbung plugins/farbvererbung
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Farbvererbung;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
    }

    /**
     * Zeigt auf der öffentlichen Detailseite die genetische Einordnung der
     * eingetragenen Farbe an, sofern sich der freie Text im Feld `color` einer
     * der fünf anerkannten Falbfarben zuordnen lässt. Rein informativ, keine
     * eigene DB-Abfrage.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $key = FjordColor::keyFromText($horse['color'] ?? '');
        if ($key === null) {
            return $sections;
        }

        $label = FjordColor::label($key);
        $genotype = FjordColor::genotypeHint($key);

        $sections[] = '<div style="margin-top:0.5rem;padding:0.75rem 1rem;background:var(--surface-muted);border-radius:6px;">'
            . '<strong>🎨 Falbfarbe:</strong> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '<p style="margin:0.4rem 0 0 0;color:var(--text-muted);font-size:0.85em;">'
            . 'Genetische Einordnung: ' . htmlspecialchars($genotype, ENT_QUOTES, 'UTF-8') . '. '
            . 'Alle Fjordpferde tragen das Dun-(Falb-)Gen; die Farbunterschiede entstehen '
            . 'aus der Grundfarbe (Extension/Agouti) und ggf. dem Cream-Gen.'
            . '</p></div>';

        return $sections;
    }

    /**
     * Eigenes Modul "farbvererbung" mit der Aktion "calculate" für den
     * Farbrechner (analog zum Verpaarungsrechner des Inzuchtkoeffizient-Addons).
     *
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'farbvererbung',
                'action' => 'calculate',
                'label' => 'Farbrechner nutzen',
                'module_label' => 'Farbvererbung',
            ],
        ];
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            [
                'method' => 'GET',
                'path' => '/rechner',
                'callback' => [RechnerController::class, 'show'],
            ],
        ];
    }
}

/**
 * Reine Farbgenetik-Logik des Fjordpferds, unabhängig von HTTP/Controller.
 *
 * Modell (Dun/Falb ist bei der Rasse fest vorhanden und wird daher nicht
 * modelliert; das Grau-Gen G, das zu Ausbleichen führt, kommt beim Fjordpferd
 * praktisch nicht vor und wird bewusst ignoriert):
 *
 *   - Extension (E dominant / e rezessiv): E_ = schwarzbasiert, ee = fuchsbasiert (rot)
 *   - Agouti    (A dominant / a rezessiv): nur auf schwarzer Basis sichtbar
 *   - Cream     (Cr / n, unvollständig dominant): eine Dosis verdünnt
 *
 * Phänotyp (die fünf anerkannten Falbfarben):
 *   - Braunfalbe (Brunblakk): schwarzbasiert + Agouti, kein Cream        -> E_ A_  nn
 *   - Graufalbe  (Grå):       schwarzbasiert ohne Agouti, kein Cream     -> E_ aa  nn
 *   - Rotfalbe   (Rødblakk):  fuchsbasiert, kein Cream                   -> ee     nn
 *   - Hellfalbe  (Ulsblakk):  schwarzbasiert + Cream                     -> E_ __  Cr_
 *   - Gelbfalbe  (Gulblakk):  fuchsbasiert + Cream                       -> ee     Cr_
 *
 * Vorhersage aus zwei ELTERN-PHÄNOTYPEN: Da nur der Phänotyp (nicht der exakte
 * Genotyp) bekannt ist, werden je Locus alle mit dem Phänotyp verträglichen
 * Genotypen als GLEICH WAHRSCHEINLICH angenommen (vereinfachende Annahme,
 * analog zur dokumentierten Näherung des Inzuchtkoeffizient-Addons - reale
 * Anlageträger-Häufigkeiten weichen davon ab). Die drei Loci vererben
 * unabhängig; die Fohlenverteilung ergibt sich als Produkt der
 * Einzel-Locus-Wahrscheinlichkeiten.
 */
class FjordColor {

    /** @var array<string,string> */
    private const LABELS = [
        'brunblakk' => 'Braunfalbe (Brunblakk)',
        'rodblakk'  => 'Rotfalbe (Rødblakk)',
        'graa'      => 'Graufalbe (Grå)',
        'ulsblakk'  => 'Hellfalbe (Ulsblakk)',
        'gulblakk'  => 'Gelbfalbe (Gulblakk)',
    ];

    /** @var array<string,string> */
    private const GENOTYPE_HINT = [
        'brunblakk' => 'schwarze Basis mit Agouti, ohne Cream (E_ A_ nn)',
        'rodblakk'  => 'fuchsfarbene Basis, ohne Cream (ee nn)',
        'graa'      => 'schwarze Basis ohne Agouti, ohne Cream (E_ aa nn)',
        'ulsblakk'  => 'schwarze Basis mit einer Cream-Dosis (E_ Cr)',
        'gulblakk'  => 'fuchsfarbene Basis mit einer Cream-Dosis (ee Cr)',
    ];

    /** Reihenfolge für die Anzeige (häufigste zuerst). */
    public const ORDER = ['brunblakk', 'graa', 'rodblakk', 'ulsblakk', 'gulblakk'];

    /** @return array<string,string> key => Anzeigename */
    public static function options(): array {
        $out = [];
        foreach (self::ORDER as $key) {
            $out[$key] = self::LABELS[$key];
        }
        return $out;
    }

    public static function isKnown(string $key): bool {
        return isset(self::LABELS[$key]);
    }

    public static function label(string $key): string {
        return self::LABELS[$key] ?? $key;
    }

    public static function genotypeHint(string $key): string {
        return self::GENOTYPE_HINT[$key] ?? '';
    }

    private static function isRed(string $key): bool {
        return $key === 'rodblakk' || $key === 'gulblakk';
    }

    private static function hasCream(string $key): bool {
        return $key === 'ulsblakk' || $key === 'gulblakk';
    }

    /** @return array{0:float,1:float} [p(e-Allel für Rotbasis)] -> hier: p(e) */
    private static function pRedAllele(string $key): float {
        // ee -> gibt immer e weiter; E_ (verträglich EE/Ee, gleich gewichtet) -> p(e)=0.25
        return self::isRed($key) ? 1.0 : 0.25;
    }

    /** @return float p(a-Allel) */
    private static function pAgoutiRecessive(string $key): float {
        if ($key === 'brunblakk') {
            return 0.25;            // A∈{AA,Aa} gleich gewichtet -> p(a)=0.25
        }
        if ($key === 'graa') {
            return 1.0;             // aa
        }
        return 0.5;                 // Agouti verdeckt (rot/uls/gul) -> A∈{AA,Aa,aa} -> p(a)=0.5
    }

    /** @return float p(n-Allel, also KEIN Cream) */
    private static function pNoCreamAllele(string $key): float {
        // Cream-Träger (Cr∈{Cr n, Cr Cr} gleich gewichtet) -> p(n)=0.25; sonst nn -> p(n)=1
        return self::hasCream($key) ? 0.25 : 1.0;
    }

    /**
     * Fohlenfarb-Verteilung aus zwei Eltern-Phänotypen.
     *
     * @return array<string,float> key => Wahrscheinlichkeit (0..1), Summe 1.0
     */
    public static function predictFoal(string $sireKey, string $damKey): array {
        $pOffspringRed = self::pRedAllele($sireKey) * self::pRedAllele($damKey);
        $pOffspringBlack = 1.0 - $pOffspringRed;

        $pOffspringAa = self::pAgoutiRecessive($sireKey) * self::pAgoutiRecessive($damKey);
        $pOffspringHasA = 1.0 - $pOffspringAa;

        $pNoCream = self::pNoCreamAllele($sireKey) * self::pNoCreamAllele($damKey);
        $pCream = 1.0 - $pNoCream;

        return [
            'brunblakk' => $pOffspringBlack * $pNoCream * $pOffspringHasA,
            'graa'      => $pOffspringBlack * $pNoCream * $pOffspringAa,
            'rodblakk'  => $pOffspringRed   * $pNoCream,
            'ulsblakk'  => $pOffspringBlack * $pCream,
            'gulblakk'  => $pOffspringRed   * $pCream,
        ];
    }

    /**
     * Ordnet einen freien Farbtext (Feld `color`) einer der fünf Falbfarben zu,
     * oder null, wenn keine eindeutige Zuordnung möglich ist. Erkennt deutsche
     * und norwegische Bezeichnungen sowie gängige Schreibweisen.
     */
    public static function keyFromText(?string $raw): ?string {
        if ($raw === null) {
            return null;
        }
        $t = self::normalize($raw);
        if ($t === '') {
            return null;
        }

        // Reihenfolge: spezifische (Cream-)Farben zuerst, damit z. B. "gelbfalbe"
        // nicht fälschlich über ein enthaltenes "falbe" o. ä. woanders greift.
        $needles = [
            'ulsblakk' => ['ulsblakk', 'uls', 'hellfalbe', 'weissfalbe', 'weisfalbe'],
            'gulblakk' => ['gulblakk', 'gelbfalbe', 'gelb'],
            'brunblakk' => ['brunblakk', 'braunfalbe', 'braun', 'brown'],
            'rodblakk' => ['rodblakk', 'rotfalbe', 'rot', 'red'],
            'graa' => ['graablakk', 'grablakk', 'graufalbe', 'grau', 'graa', 'gra', 'grey', 'gray', 'grullo'],
        ];

        foreach ($needles as $key => $variants) {
            foreach ($variants as $needle) {
                if (str_contains($t, $needle)) {
                    return $key;
                }
            }
        }
        return null;
    }

    private static function normalize(string $raw): string {
        $t = mb_strtolower(trim($raw), 'UTF-8');
        $map = ['ø' => 'o', 'å' => 'a', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'æ' => 'ae'];
        $t = strtr($t, $map);
        return preg_replace('/[^a-z0-9]/', '', $t) ?? '';
    }
}

/**
 * Farbrechner: schätzt die Fohlenfarb-Verteilung aus zwei gewählten
 * Elternfarben. Rein GET-basiert (keine Datenänderung); Zugriffsschutz über die
 * selbst registrierte Berechtigung "farbvererbung.calculate". Optional lassen
 * sich die Elternfarben aus im Register vorhandenen Pferden vorbelegen.
 */
class RechnerController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('farbvererbung', 'calculate');
    }

    public function show(): void {
        $sireColor = self::readColorParam('sire_color');
        $damColor = self::readColorParam('dam_color');

        $horses = Database::getInstance()->query(
            "SELECT id, name, birth_year, color FROM horses WHERE deleted_at IS NULL ORDER BY name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $result = ($sireColor !== null && $damColor !== null)
            ? FjordColor::predictFoal($sireColor, $damColor)
            : null;

        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Fjord-Farbvererbungsrechner</title>';
        echo '<link rel="stylesheet" href="/css/style.css">';
        echo <<<'HTML'
        <script>
        // Theme-Bootstrap wie im Framework-Layout (dort ausführlich begründet):
        // synchron im <head>, damit data-theme vor dem ersten Rendern steht.
        (function () {
            var stored = localStorage.getItem('theme');
            if (stored === 'dark' || stored === 'light') {
                document.documentElement.setAttribute('data-theme', stored);
            }
        })();
        </script>
        HTML;
        echo '<style>body{font-family:sans-serif;padding:2rem;max-width:720px;margin:0 auto;background:var(--bg-color);}';
        echo 'label{display:block;margin-top:1rem;font-weight:bold;} select{width:100%;padding:0.5rem;margin-top:0.3rem;}';
        echo '.result{margin-top:1.5rem;padding:1rem;background:var(--surface-muted);border-radius:6px;}';
        echo '.bar{height:1.1rem;background:var(--secondary-color);border-radius:3px;}';
        echo 'table{width:100%;border-collapse:collapse;margin-top:0.5rem;} td{padding:0.35rem 0.5rem;vertical-align:middle;}';
        echo '.muted{color:var(--text-muted);font-size:0.85em;}</style></head><body>';
        echo '<h1>🎨 Fjord-Farbvererbungsrechner</h1>';
        echo '<p>Schätzt die voraussichtliche Fohlenfarbe aus den Farben von Vater und Mutter '
            . 'anhand der Farbgenetik des Norwegischen Fjordpferds.</p>';

        echo '<form method="GET">';
        echo self::colorSelect('sire_color', 'Farbe des Vaters (Hengst)', $sireColor);
        echo self::colorSelect('dam_color', 'Farbe der Mutter (Stute)', $damColor);
        echo '<p><button type="submit" style="margin-top:1rem;padding:0.6rem 1.2rem;">Berechnen</button></p>';
        echo '</form>';

        if ($result !== null) {
            echo '<div class="result"><strong>Voraussichtliche Fohlenfarbe:</strong><table>';
            foreach (self::sortResult($result) as $key => $prob) {
                $percent = number_format($prob * 100, 2, ',', '.');
                $width = max(0, min(100, $prob * 100));
                echo '<tr>';
                echo '<td style="width:11rem;">' . htmlspecialchars(FjordColor::label($key), ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td style="width:4.5rem;text-align:right;"><strong>' . $percent . ' %</strong></td>';
                echo '<td><div class="bar" style="width:' . number_format($width, 2, '.', '') . '%"></div></td>';
                echo '</tr>';
            }
            echo '</table>';
            echo '<p class="muted">Vereinfachtes Modell: unbekannte Anlageträger-Genotypen werden je Locus '
                . 'als gleich wahrscheinlich angenommen. Das Dun-(Falb-)Gen gilt bei der Rasse als fest '
                . 'vorhanden. Werte sind Schätzungen, kein Ersatz für einen Gentest.</p>';
            echo '</div>';
        }

        echo '<details style="margin-top:1.5rem;"><summary>Farben im Register (zum Nachschlagen)</summary>';
        echo '<table style="margin-top:0.5rem;">';
        foreach ($horses as $h) {
            $key = FjordColor::keyFromText($h['color'] ?? '');
            $mapped = $key !== null ? FjordColor::label($key) : '—';
            echo '<tr><td>' . htmlspecialchars((string) $h['name'], ENT_QUOTES, 'UTF-8')
                . ($h['birth_year'] ? ' (' . (int) $h['birth_year'] . ')' : '') . '</td>'
                . '<td>' . htmlspecialchars((string) ($h['color'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="muted">' . htmlspecialchars($mapped, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        echo '</table></details>';

        echo '<p style="margin-top:2rem;"><a href="/admin">Zurück zum Dashboard</a></p>';
        echo '</body></html>';
    }

    private static function readColorParam(string $name): ?string {
        $val = isset($_GET[$name]) ? (string) $_GET[$name] : '';
        return ($val !== '' && FjordColor::isKnown($val)) ? $val : null;
    }

    private static function colorSelect(string $name, string $label, ?string $selected): string {
        $html = '<label for="' . $name . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>';
        $html .= '<select name="' . $name . '" id="' . $name . '">';
        $html .= '<option value="">– auswählen –</option>';
        foreach (FjordColor::options() as $key => $optLabel) {
            $sel = ($selected === $key) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                . htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    /**
     * @param array<string,float> $result
     * @return array<string,float> nach Wahrscheinlichkeit absteigend
     */
    private static function sortResult(array $result): array {
        arsort($result);
        return $result;
    }
}
