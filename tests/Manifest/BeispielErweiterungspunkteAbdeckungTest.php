<?php
// tests/Manifest/BeispielErweiterungspunkteAbdeckungTest.php

namespace Tests\Manifest;

use PHPUnit\Framework\TestCase;
use Plugin\BeispielErweiterungspunkte\Plugin;

/**
 * Haelt die Hooks des KERNS gegen die, die das Lehrbeispiel
 * plugins/beispiel-erweiterungspunkte belegt (Addons#128).
 *
 * WOZU DIESER TEST UEBERHAUPT EXISTIERT
 *
 * Das alte Referenz-Addon im Framework-Repo (docs/examples/demo-plugin/) zeigte
 * drei Hooks. Es war nicht falsch - es war nur zwei Jahre alt, waehrend der
 * Kern von drei auf zweiundzwanzig Hooks gewachsen war. Niemand hat das je
 * bemerkt, weil ein unvollstaendiges Beispiel genauso gruen ist wie ein
 * vollstaendiges. Ohne einen Test, der bei einem NEUEN Kern-Hook rot wird,
 * veraltet auch dieses Beispiel wieder still.
 *
 * WIE ER MISST
 *
 * Nicht anhand einer gepflegten Liste - die waere dasselbe Problem eine Ebene
 * hoeher. Gemessen wird am Quelltext des Kerns: Der Tokenizer sucht jeden
 * Aufruf von doAction()/applyFilters() und liest den Hook-Namen aus dem ersten
 * Argument. Was so gefunden wird, IST die Menge der Erweiterungspunkte.
 *
 * DYNAMISCHE AUFRUFE
 *
 * Ein Aufruf, dessen erstes Argument kein Literal ist, laesst sich nicht
 * auslesen - der Kern hat genau einen davon (die Alias-Faecherung in
 * ContactController). Solche Stellen stehen namentlich in
 * DYNAMISCHE_AUFRUFE, samt der Hook-Namen, die sie erzeugen. Kommt eine neue
 * hinzu, wird dieser Test rot, statt sie zu uebersehen - denn genau das waere
 * der Hook, der unbemerkt unabgedeckt bliebe.
 *
 * KEINE DATENBANK, KEINE LAUFENDE INSTANZ. Der Test liest Dateien. Er gehoert
 * deshalb in die Manifest-Suite und laeuft ueberall mit.
 */
class BeispielErweiterungspunkteAbdeckungTest extends TestCase {

    private const SLUG = 'beispiel-erweiterungspunkte';

    /** Der Kern-Quelltext, gegen den gemessen wird. */
    private const KERN_SRC = \FRAMEWORK_VENDOR_DIR . '/src';

    /** Die Hook-Referenz, die Addon-Entwickler tatsaechlich lesen. */
    private const KERN_DOKU = \FRAMEWORK_VENDOR_DIR . '/docs/plugin-development.md';

    /**
     * Der HookManager definiert doAction()/applyFilters() und ruft sie
     * intern auf - seine Treffer sind keine Erweiterungspunkte.
     */
    private const AUSGENOMMENE_DATEIEN = ['HookManager.php'];

    /**
     * Aufrufstellen, deren Hook-Name zur Laufzeit entsteht: Datei => [
     *   'ausdruck' => der Argument-Ausdruck, wie er im Quelltext steht,
     *   'hooks'    => die Namen, die dabei herauskommen,
     * ]
     *
     * Der Ausdruck steht mit im Schluessel, damit eine UMGEBAUTE Faecherung
     * genauso auffaellt wie eine neue - nicht nur eine zusaetzliche Datei.
     */
    private const DYNAMISCHE_AUFRUFE = [
        // ContactController::doContactAction() loest jedes Kontakt-Ereignis
        // dreimal aus: contact.*, person.*, station.*. Die beiden alten
        // Praefixe sind Aliasse aus der 0.7-Linie und entfallen in v0.9.0.
        'Controllers/ContactController.php' => [
            'ausdruck' => '$prefix.$event',
            'hooks' => [
                'contact.after_save', 'person.after_save', 'station.after_save',
                'contact.deleted', 'person.deleted', 'station.deleted',
            ],
        ],
    ];

    /**
     * Hooks, die der Kern ausloest, die in docs/plugin-development.md aber
     * NICHT in der Hook-Tabelle stehen.
     *
     * Diese Liste ist ein BEFUND, kein Freibrief: Code und Dokumentation sind
     * auseinandergelaufen, und die Doku ist die Fassung, die Addon-Entwickler
     * lesen. Der Test besteht auf GLEICHHEIT, nicht auf "hoechstens" - wird
     * die Doku im Kern nachgezogen, wird er rot und verlangt, dass der Eintrag
     * hier verschwindet. Anders bliebe die Liste stehen und deckte irgendwann
     * eine echte Luecke zu.
     *
     * Stand 2026-08-21 (Kern 0.8.0): LEER. Die vier Hooks aus #335, #346 und
     * #356 (home.sections_top, home.sections_bottom, horse.publish_blockers,
     * horse.search_ids) stehen inzwischen in der Hook-Tabelle des Kerns - der
     * Eintrag ist deshalb hier entfallen, genau wie der Kommentar oben es
     * vorschreibt.
     *
     * Dass es auffiel, hat einen Nebenaspekt, der hier festgehalten gehoert:
     * Gegen den in composer.lock festgenagelten Kern war der Test gruen, gegen
     * den AKTUELLEN Kern rot. Wer diese Liste pflegt, prueft sie gegen den
     * Kern-Stand, gegen den das Addon-Release tatsaechlich ausgeliefert wird.
     */
    private const DOKU_LUECKEN = [];

    /**
     * Die Plugin-Klasse steht nicht im Composer-Autoloader (Addons liegen
     * ausserhalb) - sie wird geladen wie der Kern es tut: per require_once auf
     * die Entry-Datei.
     */
    public static function setUpBeforeClass(): void {
        require_once __DIR__ . '/../../plugins/' . self::SLUG . '/Plugin.php';
    }

    // -----------------------------------------------------------------

    /**
     * DER EIGENTLICHE TEST: Jeder Hook des Kerns ist entweder belegt oder
     * ausdruecklich und begruendet ausgenommen.
     */
    public function testJederKernHookIstBelegtOderBegruendetAusgenommen(): void {
        $kern = self::kernHooks();
        $belegt = self::belegteHooks();
        $ausgenommen = Plugin::BEWUSST_NICHT_ABGEDECKT;

        $offen = array_values(array_diff($kern, $belegt, array_keys($ausgenommen)));
        sort($offen);

        $this->assertSame(
            [],
            $offen,
            "Der Kern loest Hooks aus, die das Lehrbeispiel weder belegt noch ausdruecklich ausnimmt:\n"
            . '  ' . implode("\n  ", $offen) . "\n\n"
            . "So wird das Beispiel wieder unvollstaendig - genau der Zustand, gegen den Addons#128\n"
            . "angetreten ist. Entweder in plugins/" . self::SLUG . "/Plugin.php einen Rueckruf\n"
            . "ergaenzen (AKTIONEN bzw. FILTER, mit Kommentar: wofuer man den Hook nimmt und was die\n"
            . "Falle daran ist) - oder ihn mit Begruendung in BEWUSST_NICHT_ABGEDECKT aufnehmen."
        );
    }

    /**
     * Die Gegenrichtung: Das Beispiel darf sich nicht an Hooks haengen, die es
     * gar nicht gibt. Ein Tippfehler im Hook-Namen faellt sonst nie auf - der
     * Rueckruf wird schlicht nie gerufen, und alles sieht richtig aus.
     */
    public function testKeinRegistrierterHookOhneEntsprechungImKern(): void {
        $kern = self::kernHooks();
        $unbekannt = array_values(array_diff(self::belegteHooks(), $kern));
        sort($unbekannt);

        $this->assertSame(
            [],
            $unbekannt,
            "Das Lehrbeispiel registriert Hooks, die der Kern nicht (mehr) ausloest:\n"
            . '  ' . implode("\n  ", $unbekannt) . "\n\n"
            . 'Entweder ein Tippfehler, oder der Kern hat den Hook entfernt.'
        );
    }

    /** Jede Ausnahme traegt eine Begruendung - ein nackter Eintrag waere ein Freibrief. */
    public function testJedeAusnahmeIstBegruendet(): void {
        foreach (Plugin::BEWUSST_NICHT_ABGEDECKT as $hook => $grund) {
            $this->assertIsString($hook, 'BEWUSST_NICHT_ABGEDECKT ist nach Hook-Namen indiziert.');
            $this->assertGreaterThan(
                30,
                mb_strlen(trim((string)$grund)),
                "Die Ausnahme fuer '{$hook}' hat keine tragfaehige Begruendung. "
                . 'Wer einen Erweiterungspunkt auslaesst, sagt warum - sonst ist die Liste ein Freibrief.'
            );
        }
    }

    /**
     * Das Manifest ist Selbstauskunft gegenueber dem Administrator, der vor
     * der Aktivierung wissen will, was das Addon anfasst. Weicht es von den
     * tatsaechlich registrierten Hooks ab, ist die Auskunft falsch - der Kern
     * erzwingt das nicht, also tut es dieser Test.
     */
    public function testManifestNenntGenauDieRegistriertenHooks(): void {
        $manifest = json_decode(
            (string)file_get_contents(__DIR__ . '/../../plugins/' . self::SLUG . '/plugin.json'),
            true
        );
        $this->assertIsArray($manifest);

        $genannt = (array)($manifest['hooks'] ?? []);
        sort($genannt);
        $belegt = self::belegteHooks();
        sort($belegt);

        $this->assertSame(
            $belegt,
            $genannt,
            'Das Feld "hooks" in plugin.json muss genau die in Plugin::AKTIONEN und Plugin::FILTER '
            . 'registrierten Hooks nennen.'
        );
    }

    /** Zu jedem registrierten Hook gehoert ein Rueckruf, der wirklich existiert. */
    public function testJederRueckrufExistiert(): void {
        $plugin = new Plugin();

        foreach (Plugin::AKTIONEN + Plugin::FILTER as $hook => $methode) {
            $this->assertTrue(
                method_exists($plugin, $methode),
                "Fuer '{$hook}' ist die Methode '{$methode}' registriert, es gibt sie aber nicht."
            );
        }
    }

    /**
     * Code gegen Dokumentation. Ein Hook, den der Kern ausloest, der aber
     * nicht in der Hook-Tabelle von docs/plugin-development.md steht, ist fuer
     * jeden Addon-Entwickler unsichtbar - er findet ihn nur, wenn er den
     * Kern-Quelltext liest.
     *
     * Geprueft wird auf GLEICHHEIT mit DOKU_LUECKEN: Sowohl ein neuer
     * undokumentierter Hook als auch eine nachgezogene Dokumentation macht den
     * Test rot. Das zweite ist Absicht - eine Ausnahmeliste, die nach der
     * Behebung stehen bleibt, deckt beim naechsten Mal eine echte Luecke zu.
     */
    public function testKernDokumentationNenntJedenAusgeloestenHook(): void {
        if (!is_file(self::KERN_DOKU)) {
            $this->fail('docs/plugin-development.md des Kerns nicht gefunden unter ' . self::KERN_DOKU);
        }

        $doku = (string)file_get_contents(self::KERN_DOKU);
        $fehlend = [];
        foreach (self::kernHooks() as $hook) {
            // Die Hook-Tabelle nennt jeden Hook in Backticks. Ein blosser
            // Vorkommen im Fliesstext genuegt bewusst - es geht darum, ob ein
            // Suchender ihn ueberhaupt findet.
            if (!str_contains($doku, '`' . $hook . '`')) {
                $fehlend[] = $hook;
            }
        }
        sort($fehlend);

        $erwartet = self::DOKU_LUECKEN;
        sort($erwartet);

        $this->assertSame(
            $erwartet,
            $fehlend,
            "Abgleich Kern-Code gegen Kern-Doku (docs/plugin-development.md) stimmt nicht mehr.\n"
            . 'Gefunden: ' . (($fehlend === []) ? '(keine Luecke)' : implode(', ', $fehlend)) . "\n"
            . 'Erwartet: ' . implode(', ', $erwartet) . "\n\n"
            . "Steht ein NEUER Hook in der Liste der Gefundenen, fehlt er in der Hook-Tabelle des\n"
            . "Kerns - das gehoert dorthin gemeldet. Ist die Liste kuerzer geworden, wurde die Doku\n"
            . "nachgezogen: dann den Eintrag aus DOKU_LUECKEN entfernen."
        );
    }

    /** Der Kern-Quelltext muss ueberhaupt lesbar sein - sonst misst der Test nichts. */
    public function testKernQuelltextWurdeGefunden(): void {
        $this->assertDirectoryExists(
            self::KERN_SRC,
            'Ohne den vendorierten Kern misst dieser Test nichts. composer install ausfuehren.'
        );
        $this->assertGreaterThan(
            15,
            count(self::kernHooks()),
            'Auffaellig wenige Hooks im Kern gefunden - vermutlich hat der Scanner nichts erkannt, '
            . 'statt dass der Kern geschrumpft ist. Das waere ein still gruener Test.'
        );
    }

    // -----------------------------------------------------------------

    /**
     * Alle Hook-Namen, die dieses Addon belegt.
     *
     * @return array<int, string>
     */
    private static function belegteHooks(): array {
        return array_values(array_unique(array_merge(
            array_keys(Plugin::AKTIONEN),
            array_keys(Plugin::FILTER)
        )));
    }

    /** @var array<int, string>|null */
    private static ?array $kernHooksCache = null;

    /**
     * Alle Hook-Namen, die der Kern ausloest.
     *
     * @return array<int, string>
     */
    private static function kernHooks(): array {
        if (self::$kernHooksCache !== null) {
            return self::$kernHooksCache;
        }

        $hooks = [];
        $dynamischGefunden = [];

        foreach (self::phpDateienIn(self::KERN_SRC) as $datei) {
            if (in_array(basename($datei), self::AUSGENOMMENE_DATEIEN, true)) {
                continue;
            }

            foreach (self::aufrufeIn($datei) as $aufruf) {
                if ($aufruf['literal'] !== null) {
                    $hooks[] = $aufruf['literal'];
                    continue;
                }
                $dynamischGefunden[self::relativ($datei)] = $aufruf['ausdruck'];
            }
        }

        // Dynamische Stellen: nur die bekannten sind zulaessig, und nur mit
        // unveraendertem Ausdruck. Alles andere ist ein Hook, den der Scanner
        // nicht lesen kann - und damit einer, der unbemerkt unabgedeckt bliebe.
        foreach ($dynamischGefunden as $datei => $ausdruck) {
            $bekannt = self::DYNAMISCHE_AUFRUFE[$datei] ?? null;
            if ($bekannt === null || $bekannt['ausdruck'] !== $ausdruck) {
                throw new \RuntimeException(
                    "Unbekannter dynamischer Hook-Aufruf in src/{$datei}: doAction/applyFilters mit "
                    . "'{$ausdruck}' statt einem Literal.\n"
                    . 'Dieser Aufruf erzeugt Hook-Namen, die der Scanner nicht lesen kann. Die Stelle '
                    . 'gehoert samt der Namen, die sie erzeugt, in '
                    . self::class . '::DYNAMISCHE_AUFRUFE - sonst bleibt der Hook unabgedeckt, ohne '
                    . 'dass es jemandem auffaellt.'
                );
            }
            foreach ($bekannt['hooks'] as $hook) {
                $hooks[] = $hook;
            }
        }

        // Und die Gegenrichtung: Eine bekannte dynamische Stelle, die es nicht
        // mehr gibt, darf nicht stillschweigend weiter Hooks beisteuern.
        foreach (self::DYNAMISCHE_AUFRUFE as $datei => $_erwartet) {
            if (!isset($dynamischGefunden[$datei])) {
                throw new \RuntimeException(
                    "Die in DYNAMISCHE_AUFRUFE eingetragene Stelle src/{$datei} loest keinen "
                    . 'dynamischen Hook mehr aus. Eintrag entfernen - sonst behauptet dieser Test '
                    . 'Hooks, die es nicht mehr gibt.'
                );
            }
        }

        $hooks = array_values(array_unique($hooks));
        sort($hooks);
        self::$kernHooksCache = $hooks;

        return $hooks;
    }

    /**
     * Findet jeden doAction()/applyFilters()-Aufruf einer Datei ueber den
     * PHP-Tokenizer.
     *
     * WARUM NICHT MIT EINEM REGEX: Ein Regex trifft auch Erwaehnungen in
     * Kommentaren - und davon hat der Kern etliche, weil er seine Hooks
     * ausfuehrlich begruendet. Ein Test, der sich an einem Kommentar verschluckt
     * oder umgekehrt an einem Kommentar gruen wird, misst nicht, was er
     * behauptet. Der Tokenizer kennt den Unterschied.
     *
     * @return array<int, array{literal: string|null, ausdruck: string}>
     */
    private static function aufrufeIn(string $datei): array {
        $tokens = token_get_all((string)file_get_contents($datei));
        $gefunden = [];
        $anzahl = count($tokens);

        for ($i = 0; $i < $anzahl; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            if ($token[1] !== 'doAction' && $token[1] !== 'applyFilters') {
                continue;
            }

            // Die Definition selbst ("function doAction(") ist kein Aufruf.
            if (self::vorherigerBedeutenderToken($tokens, $i) === 'function') {
                continue;
            }

            $j = self::naechsterBedeutenderIndex($tokens, $i + 1);
            if ($j === null || $tokens[$j] !== '(') {
                continue;
            }

            $k = self::naechsterBedeutenderIndex($tokens, $j + 1);
            if ($k === null) {
                continue;
            }

            if (is_array($tokens[$k]) && $tokens[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                $gefunden[] = [
                    'literal' => trim($tokens[$k][1], "'\""),
                    'ausdruck' => $tokens[$k][1],
                ];
                continue;
            }

            $gefunden[] = ['literal' => null, 'ausdruck' => self::argumentAusdruck($tokens, $k)];
        }

        return $gefunden;
    }

    /**
     * Der erste Argument-Ausdruck als kompakte Zeichenkette (ohne Leerraum),
     * damit er sich stabil mit DYNAMISCHE_AUFRUFE vergleichen laesst.
     *
     * @param array<int, mixed> $tokens
     */
    private static function argumentAusdruck(array $tokens, int $start): string {
        $ausdruck = '';
        $tiefe = 0;
        $anzahl = count($tokens);

        for ($i = $start; $i < $anzahl; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '(') {
                $tiefe++;
            } elseif ($text === ')') {
                if ($tiefe === 0) {
                    break;
                }
                $tiefe--;
            } elseif ($text === ',' && $tiefe === 0) {
                break;
            }

            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }
            $ausdruck .= $text;
        }

        return $ausdruck;
    }

    /** @param array<int, mixed> $tokens */
    private static function naechsterBedeutenderIndex(array $tokens, int $von): ?int {
        $anzahl = count($tokens);
        for ($i = $von; $i < $anzahl; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /** @param array<int, mixed> $tokens */
    private static function vorherigerBedeutenderToken(array $tokens, int $von): ?string {
        for ($i = $von - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($token) ? $token[1] : $token;
        }
        return null;
    }

    /** @return array<int, string> */
    private static function phpDateienIn(string $wurzel): array {
        if (!is_dir($wurzel)) {
            return [];
        }

        $dateien = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $datei) {
            if ($datei->isFile() && $datei->getExtension() === 'php') {
                $dateien[] = $datei->getPathname();
            }
        }
        sort($dateien);

        return $dateien;
    }

    /** Pfad relativ zu src/, mit Schrägstrichen - der Schluessel in DYNAMISCHE_AUFRUFE. */
    private static function relativ(string $datei): string {
        return ltrim(str_replace('\\', '/', substr($datei, strlen(self::KERN_SRC))), '/');
    }
}
