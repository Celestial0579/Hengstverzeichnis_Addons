<?php
// embed-widget/Plugin.php
//
// Addon für Hengstverzeichnis_Framework (Addons#89): erzeugt den fertigen
// iframe-Schnipsel, mit dem ein Zuchtverband den öffentlichen Pferdekatalog
// auf seiner eigenen Website einbettet - statt nur darauf zu verlinken.
//
// WAS DIESES ADDON BEWUSST NICHT TUT: den Katalog nachbauen.
//
// Der Kern rendert den Katalog seit #260/#264 selbst einbettbar: `?embed=1`
// liefert ihn über layout_embed.php ohne Kopfbereich, Navigation und Fußzeile
// (aber MIT Theme-Variablen, style.css und Darkmode-Fix - ein iframe erbt kein
// CSS von der einbettenden Seite). Filter, Nachladen, Sprachen, die
// Bildauslieferung mit is_published-Prüfung und der Hook catalog.card_sections
// stecken alle dort. Ein eigener Nachbau im Addon hätte all das dupliziert und
// wäre bei jeder Kern-Änderung zurückgefallen.
//
// Was hier fehlte, war nichts Technisches, sondern der Weg vom "geht
// grundsätzlich" zum "Betreiber hat den Code in der Zwischenablage": die
// richtige absolute URL, die passenden Filterparameter, und vor allem der
// Hinweis auf EMBED_ALLOWED_DOMAINS - ohne die bleibt der Rahmen beim
// Empfänger schlicht leer.
//
// Installation (lokal im Framework-Repo):
//   cp -r embed-widget plugins/embed-widget
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und der
// gewünschten Gruppe unter /admin/groups die Berechtigung
// "Embed-Widget -> Verwalten" zuweisen.

namespace Plugin\EmbedWidget;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Plugin\PluginPage;
use App\Security\FrameGuard;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'addDashboardTile']);
    }

    /**
     * @param array<int, array<string, string>> $tiles
     * @return array<int, array<string, string>>
     */
    public function addDashboardTile(array $tiles): array {
        $tiles[] = [
            'url' => '/plugin/embed-widget/generator',
            'label' => 'Embed-Widget',
            'icon' => '🖼️',
        ];
        return $tiles;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'embed-widget',
                'action' => 'manage',
                'label' => 'Verwalten',
                'module_label' => 'Embed-Widget',
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
                'path' => '/generator',
                'callback' => [GeneratorController::class, 'index'],
            ],
        ];
    }
}

/**
 * Die reine Logik: Welche Filter gelten, wie sieht die Adresse aus, wie der
 * Schnipsel. Bewusst OHNE Framework-Bindung und ohne Datenbank - dadurch ist
 * sie einzeln prüfbar (tests/Unit/EmbedWidgetCodeTest.php), auch solange der
 * Kern das Addon wegen `core_compatibility` gar nicht lädt.
 */
final class EmbedCode {

    /** Filter, die der Kern-Katalog versteht (PublicController::catalog()). */
    public const FILTERS = [
        'search' => 'Allgemeine Suche',
        'q_breed' => 'Rasse',
        'q_color' => 'Farbe',
        'q_station' => 'Deckstation',
        'q_breeder' => 'Züchter',
        'q_owner' => 'Besitzer',
    ];

    public const SEXES = ['stallion' => 'Hengst', 'mare' => 'Stute', 'gelding' => 'Wallach'];

    /**
     * Übernimmt nur bekannte, nicht leere Filter. Alles andere fällt weg -
     * ein durchgereichter Unsinn-Parameter landete sonst im Schnipsel, den
     * der Betreiber an Dritte weitergibt.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    public static function filtersFrom(array $query): array {
        $gewaehlt = [];
        foreach (array_keys(self::FILTERS) as $key) {
            $wert = trim((string)($query[$key] ?? ''));
            if ($wert !== '') {
                $gewaehlt[$key] = $wert;
            }
        }

        $sex = (string)($query['q_sex'] ?? '');
        if (isset(self::SEXES[$sex])) {
            $gewaehlt['q_sex'] = $sex;
        }

        return $gewaehlt;
    }

    /**
     * Ganzzahl mit Grenzen. Alles außerhalb - auch Text und Negativwerte -
     * fällt auf den Standard zurück, statt ein kaputtes Maß in ein
     * HTML-Attribut zu schreiben.
     */
    public static function size(mixed $roh, int $min, int $max, int $standard): int {
        if (!is_numeric($roh)) {
            return $standard;
        }
        $wert = (int)$roh;

        return ($wert < $min || $wert > $max) ? $standard : $wert;
    }

    /**
     * @param array<string, string> $filter
     */
    public static function url(string $basis, array $filter): string {
        return rtrim($basis, '/') . '/katalog?' . http_build_query(['embed' => '1'] + $filter);
    }

    public static function snippet(string $url, int $breite, int $hoehe, bool $volleBreite): string {
        $stil = $volleBreite
            ? 'width:100%;max-width:100%;border:0;'
            : sprintf('width:%dpx;max-width:100%%;border:0;', $breite);

        return sprintf(
            '<iframe src="%s"' . "\n"
            . '        style="%sheight:%dpx;"' . "\n"
            . '        title="Hengstkatalog"' . "\n"
            . '        loading="lazy"' . "\n"
            . '        referrerpolicy="strict-origin-when-cross-origin"></iframe>',
            htmlspecialchars($url, ENT_QUOTES),
            $stil,
            $hoehe
        );
    }
}

/**
 * Der Generator ist eine reine ADMIN-Seite und verlangt Anmeldung plus
 * Berechtigung. Sie liefert keine Katalogdaten aus - das tut der Kern unter
 * `/katalog?embed=1`, öffentlich wie der Katalog selbst. Hier entsteht nur der
 * Schnipsel, der dorthin zeigt.
 */
class GeneratorController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('embed-widget', 'manage');
    }

    public function index(): void {
        $gewaehlt = EmbedCode::filtersFrom($_GET);
        $sex = $gewaehlt['q_sex'] ?? '';

        // Maße: Zahlen mit Deckel. Sie landen in einem HTML-Attribut, das der
        // Betreiber weitergibt - eine freie Zeichenkette hätte hier nichts zu
        // suchen, auch wenn sie unten ohnehin escaped wird.
        $breite = EmbedCode::size($_GET['breite'] ?? '', 100, 4000, 100);
        $hoehe = EmbedCode::size($_GET['hoehe'] ?? '', 200, 4000, 900);
        $volleBreite = ($_GET['breite_prozent'] ?? '1') !== '0';

        $basis = $this->basisUrl();
        $url = EmbedCode::url($basis, $gewaehlt);
        $schnipsel = EmbedCode::snippet($url, $breite, $hoehe, $volleBreite);

        $erlaubte = FrameGuard::allowedAncestors();

        PluginPage::render('Embed-Widget', $this->html(
            $gewaehlt,
            $sex,
            $breite,
            $hoehe,
            $volleBreite,
            $url,
            $schnipsel,
            $erlaubte,
            $basis
        ));
    }


    /**
     * Absolute Basis-URL. `settings.base_url` ist die verlässliche Quelle -
     * der Schnipsel wird auf einer FREMDEN Seite eingesetzt, ein relativer
     * Pfad zeigte dort ins Leere. Ist nichts gesetzt, wird der Host-Header
     * NICHT ersatzweise genommen: Er ist vom Aufrufer bestimmbar, und ein
     * daraus gebauter Schnipsel würde stillschweigend auf eine falsche Domain
     * zeigen. Stattdessen bleibt das Feld leer und die Seite sagt es.
     */
    private function basisUrl(): string {
        $stmt = Database::getInstance()->prepare(
            "SELECT setting_value FROM settings WHERE setting_key = 'base_url'"
        );
        $stmt->execute();
        $wert = (string)($stmt->fetchColumn() ?: '');

        return $wert === '' ? '' : rtrim($wert, '/');
    }


    /**
     * @param array<string, string> $gewaehlt
     * @param array<int, string> $erlaubte
     */
    private function html(
        array $gewaehlt,
        string $sex,
        int $breite,
        int $hoehe,
        bool $volleBreite,
        string $url,
        string $schnipsel,
        array $erlaubte,
        string $basis
    ): string {
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);

        $out = '<style>';
        $out .= '.ew-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}';
        // Keine eigene Schriftart: <textarea> rendert ohnehin dicktengleich, und
        // das Theme kennt keine Mono-Variable - eine eigene brächte den
        // Dark-Mode-/Markenabgleich durcheinander (siehe PluginThemingLintTest).
        $out .= '.ew-code{width:100%;min-height:8rem;font-size:.85rem;}';
        $out .= '.ew-hinweis{border-left:4px solid var(--border-color);padding:.75rem 1rem;margin:1rem 0;}';
        $out .= '.ew-warn{border-left-color:var(--warning-fg);}';
        $out .= '.ew-ok{border-left-color:var(--success-fg);}';
        $out .= '@media (max-width:700px){.ew-grid{grid-template-columns:1fr;}}';
        $out .= '</style>';

        $out .= '<div class="card">';
        $out .= '<h1>🖼️ Embed-Widget</h1>';
        $out .= '<p>Erzeugt den HTML-Schnipsel, mit dem der Pferdekatalog auf einer fremden Website eingebettet wird. '
             . 'Die Darstellung im Rahmen kommt vollständig aus dem Kern (<code>/katalog?embed=1</code>) - '
             . 'inklusive Filter, Nachladen und Sprachen.</p>';

        // --- Voraussetzungen: der Teil, der sonst als Fehlermeldung zurückkommt
        if ($basis === '') {
            $out .= '<div class="ew-hinweis ew-warn"><strong>Basis-URL fehlt.</strong> '
                 . 'Ohne <code>base_url</code> unter <a href="/admin/system-settings">System-Einstellungen</a> '
                 . 'kann kein absoluter Link erzeugt werden - ein relativer Pfad zeigt auf der fremden Seite ins Leere. '
                 . 'Der Host-Header wird bewusst nicht ersatzweise verwendet: Er ist vom Aufrufer bestimmbar.</div>';
        }

        if ($erlaubte === []) {
            $out .= '<div class="ew-hinweis ew-warn">';
            $out .= '<strong>Einbetten ist derzeit nicht freigegeben - der Rahmen bliebe leer.</strong><br>';
            $out .= 'Der Kern blockiert das Einbetten grundsätzlich (Clickjacking-Schutz). Die Freigabe ist eine '
                 . 'bewusste Handlung des Betreibers und keine Nebenwirkung davon, dass dieses Addon aktiv ist.<br>';
            $out .= 'Dafür <code>EMBED_ALLOWED_DOMAINS</code> setzen (Umgebungsvariable oder <code>db_config.php</code>), '
                 . 'kommagetrennt und mit Schema, zum Beispiel:<br>';
            $out .= '<code>EMBED_ALLOWED_DOMAINS=https://www.zuchtverband.de,https://zuchtverband.de</code><br>';
            $out .= 'Der Schnipsel unten funktioniert erst danach. <em>Bis dahin ist ein leerer Rahmen kein Fehler, '
                 . 'sondern genau das erwartete Verhalten.</em>';
            $out .= '</div>';
        } else {
            $out .= '<div class="ew-hinweis ew-ok"><strong>Freigegeben für:</strong> ';
            $out .= implode(', ', array_map(static fn(string $d): string => '<code>' . htmlspecialchars($d, ENT_QUOTES) . '</code>', $erlaubte));
            $out .= '<br>Nur von diesen Herkünften aus wird der Rahmen angezeigt. Eine andere Domain sieht nichts.</div>';
        }

        // --- Formular
        $out .= '<form method="GET" action="/plugin/embed-widget/generator">';
        $out .= '<h2>Vorfilter (optional)</h2>';
        $out .= '<p>Leer lassen für den gesamten Katalog. Die Werte werden als Adressparameter mitgegeben - '
             . 'der Besucher kann im Rahmen weiter filtern.</p>';
        $out .= '<div class="ew-grid">';
        foreach (EmbedCode::FILTERS as $key => $label) {
            $out .= '<div class="form-group"><label for="' . $h($key) . '">' . $h($label) . '</label>'
                 . '<input type="text" class="form-control" id="' . $h($key) . '" name="' . $h($key) . '" value="'
                 . $h($gewaehlt[$key] ?? '') . '"></div>';
        }
        $out .= '<div class="form-group"><label for="q_sex">Geschlecht</label><select class="form-control" id="q_sex" name="q_sex">';
        foreach (['' => 'alle'] + EmbedCode::SEXES as $wert => $label) {
            $out .= '<option value="' . $h((string)$wert) . '"' . ($sex === $wert ? ' selected' : '') . '>' . $h($label) . '</option>';
        }
        $out .= '</select></div>';
        $out .= '</div>';

        $out .= '<h2>Maße</h2>';
        $out .= '<div class="ew-grid">';
        $out .= '<div class="form-group"><label for="breite_prozent">Breite</label><select class="form-control" id="breite_prozent" name="breite_prozent">'
             . '<option value="1"' . ($volleBreite ? ' selected' : '') . '>volle Breite des Containers (empfohlen)</option>'
             . '<option value="0"' . ($volleBreite ? '' : ' selected') . '>feste Breite in Pixel</option>'
             . '</select></div>';
        $out .= '<div class="form-group"><label for="breite">Feste Breite (px, 100-4000)</label>'
             . '<input type="number" class="form-control" id="breite" name="breite" min="100" max="4000" value="' . $breite . '"></div>';
        $out .= '<div class="form-group"><label for="hoehe">Höhe (px, 200-4000)</label>'
             . '<input type="number" class="form-control" id="hoehe" name="hoehe" min="200" max="4000" value="' . $hoehe . '"></div>';
        $out .= '</div>';
        $out .= '<button type="submit" class="btn btn-primary">Schnipsel erzeugen</button>';
        $out .= '</form>';
        $out .= '</div>';

        // --- Ergebnis
        $out .= '<div class="card">';
        $out .= '<h2>Zum Kopieren</h2>';
        $out .= '<textarea class="ew-code" readonly onclick="this.select()">' . $h($schnipsel) . '</textarea>';
        $out .= '<p><strong>Ziel-Adresse:</strong> <a href="' . $h($url) . '" target="_blank" rel="noopener">' . $h($url) . '</a></p>';
        $out .= '<p>Die Höhe ist fest, weil ein iframe nicht mit seinem Inhalt mitwächst - ohne Skript auf der '
             . 'fremden Seite geht das nicht, und dieses Addon liefert bewusst keines: Ein Skript, das ein '
             . 'Verband auf seiner Seite einbindet, ist eine ganz andere Vertrauensfrage als ein Rahmen.</p>';
        $out .= '</div>';

        // --- Vorschau
        $out .= '<div class="card">';
        $out .= '<h2>Vorschau</h2>';
        if ($basis === '') {
            $out .= '<p>Keine Vorschau ohne <code>base_url</code>.</p>';
        } else {
            $out .= '<p>So sieht der Rahmen aus - hier auf der eigenen Domain, also unabhängig von der Allowlist. '
                 . 'Ein leerer Rahmen auf der fremden Seite trotz gefüllter Vorschau bedeutet: '
                 . '<code>EMBED_ALLOWED_DOMAINS</code> kennt diese Domain nicht.</p>';
            $out .= '<iframe src="' . $h($url) . '" style="width:100%;border:1px solid var(--border-color);height:'
                 . $hoehe . 'px;" title="Vorschau" loading="lazy"></iframe>';
        }
        $out .= '</div>';

        return $out;
    }
}
