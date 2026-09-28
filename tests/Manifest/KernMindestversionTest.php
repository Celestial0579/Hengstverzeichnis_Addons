<?php
// tests/Manifest/KernMindestversionTest.php

namespace Tests\Manifest;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Die Kern-Untergrenze eines Addons (`core_compatibility`) muss zu den
 * Kern-APIs passen, die es tatsaechlich nutzt (Audit N35).
 *
 * ANLASS. qr-code rief seit dem Sicherheitsfix GHSA-xrrq-9j94-fr5g
 * `App\Helper\MediaUrl::horseImage()` auf - eine Klasse, die es erst ab Kern
 * 0.5.1 gibt (Framework#262). Das Manifest erlaubte weiter `>=0.4.0`. Auf
 * Kernen 0.4.0-0.5.0 lud der PluginManager das Addon deshalb als kompatibel,
 * und "Aushang drucken" endete bei jedem Pferd mit HTTP 500. merkliste hatte
 * denselben Fehler. Kein Test hat das gesehen, weil alle Tests gegen den
 * GEPINNTEN, also neuesten Kern laufen.
 *
 * ZWEI PRUEFUNGEN je Addon, beide ohne Datenbank und ohne ein Plugin zu laden
 * (die Quelltexte werden nur tokenisiert, #160 / TestsLadenNurRepoPluginsTest):
 *
 *   1. Jedes genutzte Symbol aus KERN_SYMBOLE_AB verlangt eine Untergrenze
 *      mindestens auf seiner Einfuehrungsversion.
 *   2. Jede referenzierte `App\`-Klasse (und jede statisch aufgerufene
 *      Methode) existiert im gepinnten Framework. Das faengt umbenannte oder
 *      entfernte Kern-APIs beim woechentlichen Lauf "deps: Framework auf ...
 *      heben" - dort rot zu werden ist gewollt: Es ist ein echter API-Bruch.
 *
 * BEKANNTE GRENZE. Die Tabelle erfasst Klassen und statische Methoden. Geerbte
 * BaseController-Helfer, Hook-Namen und neue optionale Parameter erfasst sie
 * nicht. Dass die Addons mit `>=0.4.0` wirklich auf 0.4.0 laufen, beweist der
 * Test deshalb nicht - er belegt nur, dass sie keines der eingetragenen
 * Symbole brauchen.
 */
class KernMindestversionTest extends TestCase {

    private const PLUGINS_DIR = __DIR__ . '/../../plugins';

    /**
     * Kern-Symbol => [Einfuehrungsversion, Beleg].
     *
     * Die Tabelle wird bewusst VON HAND gepflegt: Die Git-Historie des
     * Frameworks beginnt erst bei v0.7.1, eine automatische Ableitung ginge
     * fuer aeltere Symbole nicht. Wer ein Addon auf ein neues Kern-API
     * umstellt, traegt dessen Einfuehrungsversion aus dem Kern-CHANGELOG (bzw.
     * dem ersten Tag, der es enthaelt) hier ein. Eine veraltete Tabelle meldet
     * zu wenig, aber nie faelschlich.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const KERN_SYMBOLE_AB = [
        // Einbettungsschutz fuer Pferdefotos, Kern-CHANGELOG [0.5.1].
        'App\Helper\MediaUrl' => ['0.5.1', 'Framework#262'],
        // Galerie im Kern. Im CHANGELOG-Abschnitt [0.8.0-beta.1] steht #339
        // nur unter "Nicht enthalten"; die Methode kam mit dem Galerie-Umbau
        // (Framework 8526a49), erster Tag damit: v0.9.0-beta.4.
        'App\Helper\MediaUrl::horseMediaImage' => ['0.9.0-beta.4', 'Framework#339'],
        // Kontoanlage an genau einer Stelle, Kern-CHANGELOG [0.9.0-beta.3].
        'App\Service\UserProvisioning' => ['0.9.0-beta.3', 'Framework#384'],
    ];

    private const CONSTRAINT_PATTERN = '/^(>=|<=|>|<|=)?\s*(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$/';

    // --- Tests je Addon ------------------------------------------------------

    #[DataProvider('pluginSlugProvider')]
    public function testCoreCompatibilityDecktGenutzteKernSymbole(string $slug): void {
        $manifest = $this->readManifest($slug);
        $constraint = (string) ($manifest['core_compatibility'] ?? '');

        $verstoesse = self::verstoesse($constraint, self::symboleDesPlugins($slug), self::KERN_SYMBOLE_AB);

        $this->assertSame(
            [],
            $verstoesse,
            implode("\n", array_map(static fn(string $v): string => "{$slug} {$v}", $verstoesse))
        );
    }

    #[DataProvider('pluginSlugProvider')]
    public function testReferenzierteKernSymboleExistierenImGepinntenFramework(string $slug): void {
        $symbole = self::symboleDesPlugins($slug);
        if ($symbole === []) {
            $this->assertSame([], $symbole);
            return;
        }

        $fehlend = [];
        foreach ($symbole as $symbol) {
            if (str_contains($symbol, '::')) {
                [$klasse, $methode] = explode('::', $symbol, 2);
                if (!self::klassenartigExistiert($klasse) || !method_exists($klasse, $methode)) {
                    $fehlend[] = $symbol . '()';
                }
            } elseif (!self::klassenartigExistiert($symbol)) {
                $fehlend[] = $symbol;
            }
        }

        $this->assertSame(
            [],
            $fehlend,
            "'{$slug}' referenziert Kern-Symbole, die es im gepinnten Framework "
            . "(vendor/hengstverzeichnis/framework) nicht gibt:\n  " . implode("\n  ", $fehlend)
        );
    }

    // --- Logiktests ---------------------------------------------------------

    public function testVerstoesseMitSynthetischerTabelle(): void {
        $tabelle = [
            'App\Neu' => ['0.5.1', 'Framework#1'],
            'App\Beta::m' => ['0.9.0-beta.3', 'Framework#2'],
        ];

        $this->assertCount(1, self::verstoesse('>=0.4.0', ['App\Neu'], $tabelle));
        $this->assertStringContainsString(
            'nutzt App\Neu (Kern >= 0.5.1, Framework#1), core_compatibility erlaubt aber >=0.4.0',
            self::verstoesse('>=0.4.0', ['App\Neu'], $tabelle)[0]
        );
        $this->assertSame([], self::verstoesse('>=0.5.1', ['App\Neu'], $tabelle));
        $this->assertSame([], self::verstoesse('0.5.1', ['App\Neu'], $tabelle));
        $this->assertSame([], self::verstoesse('>=0.9.0', ['App\Beta::m'], $tabelle));

        // Pre-Releases: 0.9.0-beta.1 < 0.9.0-beta.3.
        $this->assertCount(1, self::verstoesse('>=0.9.0-beta.1', ['App\Beta::m'], $tabelle));
        $this->assertSame([], self::verstoesse('>=0.9.0-beta.3', ['App\Beta::m'], $tabelle));

        // '>0.5.0' liesse z. B. 0.5.1-beta.1 zu - kein Beleg fuer 0.5.1.
        $this->assertCount(1, self::verstoesse('>0.5.0', ['App\Neu'], $tabelle));
        $this->assertSame([], self::verstoesse('>0.5.1', ['App\Neu'], $tabelle));

        // Symbole ausserhalb der Tabelle verlangen nichts.
        $this->assertSame([], self::verstoesse('>=0.4.0', ['App\Database', 'App\Alt::x'], $tabelle));

        // Keine Untergrenze bzw. ungueltiges Format: immer ein Verstoss.
        $this->assertStringContainsString('keine Untergrenze', self::verstoesse('<=0.9.0', [], $tabelle)[0]);
        $this->assertStringContainsString('kein gueltiges Format', self::verstoesse('<=0.9', [], $tabelle)[0]);
    }

    public function testSymbolsammlungLoestAliasUndGruppenUseAuf(): void {
        $alias = <<<'PHP'
<?php
namespace Plugin\Test;

use App\Helper\MediaUrl as M;
use function App\helfer;
use const App\KONSTANTE;

class Plugin {
    public function a(array $h): ?string {
        return M::horseImage($h);
    }
}
PHP;
        $this->assertSame(
            ['App\Helper\MediaUrl', 'App\Helper\MediaUrl::horseImage'],
            self::sammleSymbole($alias)
        );

        $gruppe = <<<'PHP'
<?php
namespace Plugin\Test;

use App\{Database, Plugin\HookManager as H};
use App\Helper\{MediaUrl};

class Plugin extends \App\Controllers\BaseController implements \App\Plugin\PluginInterface {
    public function a(): void {
        H::getInstance();
        $x = new \App\Service\UserProvisioning();
        MediaUrl::horseMediaImage(1);
    }
}
PHP;
        $this->assertSame(
            [
                'App\Controllers\BaseController',
                'App\Database',
                'App\Helper\MediaUrl',
                'App\Helper\MediaUrl::horseMediaImage',
                'App\Plugin\HookManager',
                'App\Plugin\HookManager::getInstance',
                'App\Plugin\PluginInterface',
                'App\Service\UserProvisioning',
            ],
            self::sammleSymbole($gruppe)
        );
    }

    public function testSymbolsammlungIgnoriertFunktionenKommentareUndFremdeNamespaces(): void {
        $code = <<<'PHP'
<?php
namespace Plugin\Test;

// App\Helper\MediaUrl::horseImage() steht nur im Kommentar.
/** @see \App\Service\UserProvisioning */
class Plugin {
    public function a(): string {
        \App\foo();
        $s = 'App\Helper\MediaUrl';
        $t = App\Relativ::x();   // relativ: Plugin\Test\App\Relativ, kein Kern
        return "App\\Database";
    }
}
PHP;
        $this->assertSame([], self::sammleSymbole($code));
    }

    // --- Reine Logik (mit synthetischer Tabelle getestet) --------------------

    /**
     * Welche genutzten Symbole verlangen mehr, als die Untergrenze zusichert?
     *
     * Die Tabelle ist ein Parameter, damit der Logiktest mit synthetischen
     * Werten arbeitet und nicht bricht, wenn echte Eintraege korrigiert werden.
     *
     * @param list<string> $genutzteSymbole
     * @param array<string, array{0: string, 1: string}> $tabelle
     * @return list<string>
     */
    public static function verstoesse(string $constraint, array $genutzteSymbole, array $tabelle): array {
        $constraint = trim($constraint);
        if (!preg_match(self::CONSTRAINT_PATTERN, $constraint, $m)) {
            return ["core_compatibility '{$constraint}' hat kein gueltiges Format (ein Operator + Version)."];
        }
        $op = $m[1] === '' ? '=' : $m[1];
        $grenze = $m[2];

        if ($op === '<' || $op === '<=') {
            return ["core_compatibility '{$constraint}' nennt keine Untergrenze - "
                . "'<'/'<=' laesst jeden alten Kern zu."];
        }

        $verstoesse = [];
        foreach (array_values(array_unique($genutzteSymbole)) as $symbol) {
            if (!isset($tabelle[$symbol])) {
                continue;
            }
            [$ab, $beleg] = $tabelle[$symbol];
            // '>=', '=' und ohne Operator: die Grenze selbst ist die kleinste
            // zugelassene Version. '>': erfuellt nur, wenn schon die Grenze
            // selbst reicht (sonst liesse '>0.5.0' z. B. 0.5.1-beta.1 zu).
            $ok = version_compare($grenze, $ab, '>=');
            if (!$ok) {
                $verstoesse[] = "nutzt {$symbol} (Kern >= {$ab}, {$beleg}), "
                    . "core_compatibility erlaubt aber {$constraint}";
            }
        }
        sort($verstoesse);
        return $verstoesse;
    }

    /**
     * Sammelt die referenzierten `App\`-Symbole eines Quelltexts: Klassen,
     * Interfaces, Enums, Traits sowie statisch aufgerufene Methoden als
     * 'App\Klasse::methode'.
     *
     * Nur Klassenkontexte zaehlen: use-Importe (auch mit Alias und als
     * Gruppe), `new`, `instanceof`, `extends`, `implements`, `catch`,
     * Typdeklarationen und `Name::`. Ein vollqualifizierter Name direkt vor
     * '(' ohne `new` ist ein Funktionsaufruf und wird ausgelassen, ebenso
     * `use function` / `use const`. Kommentare und Strings liefert der
     * Tokenizer nicht als Namen.
     *
     * @return list<string>
     */
    public static function sammleSymbole(string $quelltext): array {
        // Ohne TOKEN_PARSE: Syntax einer neueren PHP-Version soll hier nicht
        // stoeren, Syntaxfehler findet PluginManifestTest.
        $alle = token_get_all($quelltext);
        $t = [];
        foreach ($alle as $tok) {
            $id = is_array($tok) ? $tok[0] : $tok;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            $t[] = is_array($tok) ? [$tok[0], $tok[1]] : [$tok, $tok];
        }
        $n = count($t);

        $namensToken = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
        $namespace = '';
        $imports = [];          // kleingeschriebener Alias => voller Name
        $tiefe = 0;
        $nsTiefe = 0;           // Klammertiefe, auf der use-Importe stehen
        $symbole = [];
        $imImplements = false;
        $imCatch = false;

        $id = static fn(int $i) => $i >= 0 && $i < $n ? $t[$i][0] : null;

        for ($i = 0; $i < $n; $i++) {
            [$tid, $text] = $t[$i];

            if ($tid === '{' || $tid === T_CURLY_OPEN || $tid === T_DOLLAR_OPEN_CURLY_BRACES) {
                $tiefe++;
                $imImplements = false;
                continue;
            }
            if ($tid === '}') {
                $tiefe--;
                continue;
            }

            if ($tid === T_NAMESPACE && in_array($id($i + 1), [T_STRING, T_NAME_QUALIFIED, '{'], true)) {
                $namespace = $id($i + 1) === '{' ? '' : $t[$i + 1][1];
                $imports = [];
                // Geklammerter Namespace: Importe stehen eine Ebene tiefer.
                $j = $i + 1;
                while ($j < $n && $t[$j][0] !== ';' && $t[$j][0] !== '{') {
                    $j++;
                }
                $nsTiefe = ($j < $n && $t[$j][0] === '{') ? $tiefe + 1 : $tiefe;
                continue;
            }

            if ($tid === T_USE) {
                if ($id($i + 1) === '(') {
                    continue; // Closure: function () use ($x)
                }
                if ($tiefe === $nsTiefe) {
                    $i = self::leseImport($t, $i + 1, $imports);
                    foreach ($imports as $voll) {
                        if (str_starts_with($voll, 'App\\')) {
                            $symbole[$voll] = true;
                        }
                    }
                    continue;
                }
                // Trait-use im Klassenrumpf: jeder Name ist ein Symbol.
                for ($j = $i + 1; $j < $n && $t[$j][0] !== ';' && $t[$j][0] !== '{'; $j++) {
                    if (in_array($t[$j][0], $namensToken, true)) {
                        $voll = self::aufloesen($t[$j][0], $t[$j][1], $namespace, $imports);
                        if ($voll !== null && str_starts_with($voll, 'App\\')) {
                            $symbole[$voll] = true;
                        }
                    }
                }
                $i = $j - 1;
                continue;
            }

            if ($tid === T_IMPLEMENTS) {
                $imImplements = true;
                continue;
            }
            if ($tid === T_CATCH) {
                $imCatch = true;
                continue;
            }
            if ($tid === ')' && $imCatch) {
                $imCatch = false;
                continue;
            }

            if (!in_array($tid, $namensToken, true)) {
                continue;
            }

            $davor = $id($i - 1);
            $danach = $id($i + 1);

            // Methoden-/Eigenschaftsnamen nach -> / ?-> / :: sind keine Klassen.
            if ($davor === T_OBJECT_OPERATOR || $davor === T_NULLSAFE_OBJECT_OPERATOR
                || $davor === T_DOUBLE_COLON || $davor === T_FUNCTION || $davor === T_CONST
                || $davor === T_CLASS || $davor === T_INTERFACE || $davor === T_TRAIT || $davor === T_ENUM
                || $davor === T_GOTO) {
                continue;
            }

            $klassenkontext =
                $danach === T_DOUBLE_COLON
                || in_array($davor, [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS], true)
                || ($imImplements && $davor === ',')
                || ($imCatch && ($davor === '(' || $davor === '|'))
                // Typdeklarationen: Parameter (Typ $x, Typ ...$x, Typ &$x),
                // nullable (?Typ), Rueckgabetyp (): Typ.
                || in_array($danach, [T_VARIABLE, T_ELLIPSIS], true)
                || ($danach === '&' && $id($i + 2) === T_VARIABLE)
                || $davor === '?'
                || ($davor === ':' && $id($i - 2) === ')');

            if (!$klassenkontext) {
                continue;
            }
            // Funktionsaufruf: Name direkt vor '(' ohne `new`.
            if ($danach === '(' && $davor !== T_NEW) {
                continue;
            }

            $voll = self::aufloesen($tid, $text, $namespace, $imports);
            if ($voll === null || !str_starts_with($voll, 'App\\')) {
                continue;
            }
            $symbole[$voll] = true;

            // Statischer Methodenaufruf: Name :: methode (
            if ($danach === T_DOUBLE_COLON && $id($i + 2) === T_STRING && $id($i + 3) === '(') {
                $symbole[$voll . '::' . $t[$i + 2][1]] = true;
            }
        }

        $liste = array_keys($symbole);
        sort($liste);
        return $liste;
    }

    // --- Hilfen --------------------------------------------------------------

    /**
     * Liest eine use-Anweisung ab $i (Token nach T_USE) bis ';' und traegt die
     * Klassen-Importe in $imports ein. `use function` / `use const` werden
     * uebersprungen, auch als Einzelposten in Gruppen.
     *
     * @param list<array{0: int|string, 1: string}> $t
     * @param array<string, string> $imports
     * @return int Index des abschliessenden ';'
     */
    private static function leseImport(array $t, int $i, array &$imports): int {
        $n = count($t);
        if ($i < $n && ($t[$i][0] === T_FUNCTION || $t[$i][0] === T_CONST)) {
            while ($i < $n && $t[$i][0] !== ';') {
                $i++;
            }
            return $i;
        }

        $lesePosten = static function (int $i) use ($t, $n): array {
            // Liefert [Name, Alias|null, naechster Index, Art-uebersprungen]
            $ueberspringen = false;
            if ($i < $n && ($t[$i][0] === T_FUNCTION || $t[$i][0] === T_CONST)) {
                $ueberspringen = true;
                $i++;
            }
            $name = '';
            while ($i < $n && in_array($t[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $t[$i][1];
                $i++;
            }
            $alias = null;
            if ($i < $n && $t[$i][0] === T_AS) {
                $alias = $t[$i + 1][1] ?? null;
                $i += 2;
            }
            return [ltrim($name, '\\'), $alias, $i, $ueberspringen];
        };

        while ($i < $n) {
            [$name, $alias, $i, $skip] = $lesePosten($i);
            if ($i < $n && $t[$i][0] === '{') {
                // Gruppen-use: Praefix\{A, B as C}
                $praefix = rtrim($name, '\\');
                $i++;
                while ($i < $n && $t[$i][0] !== '}') {
                    [$inner, $innerAlias, $i, $innerSkip] = $lesePosten($i);
                    if ($inner !== '' && !$innerSkip) {
                        $voll = $praefix . '\\' . $inner;
                        $kurz = $innerAlias ?? substr($voll, (int) strrpos($voll, '\\') + 1);
                        $imports[strtolower($kurz)] = $voll;
                    }
                    if ($i < $n && $t[$i][0] === ',') {
                        $i++;
                    } elseif ($i < $n && $t[$i][0] !== '}') {
                        $i++; // unerwartetes Token: weiterlesen statt haengen
                    }
                }
                $i++; // '}'
            } elseif ($name !== '' && !$skip) {
                $pos = strrpos($name, '\\');
                $kurz = $alias ?? ($pos === false ? $name : substr($name, $pos + 1));
                $imports[strtolower($kurz)] = $name;
            }
            if ($i < $n && $t[$i][0] === ',') {
                $i++;
                continue;
            }
            break;
        }
        while ($i < $n && $t[$i][0] !== ';') {
            $i++;
        }
        return $i;
    }

    /**
     * Loest einen Klassennamen nach PHP-Regeln auf (Import, Namespace).
     *
     * @param array<string, string> $imports
     */
    private static function aufloesen(int|string $tid, string $text, string $namespace, array $imports): ?string {
        if ($tid === T_NAME_FULLY_QUALIFIED) {
            return ltrim($text, '\\');
        }
        if ($tid === T_NAME_RELATIVE) {
            $rest = substr($text, strlen('namespace\\'));
            return $namespace === '' ? $rest : $namespace . '\\' . $rest;
        }
        if (in_array(strtolower($text), ['self', 'static', 'parent'], true)) {
            return null;
        }
        $erstes = strtolower(explode('\\', $text)[0]);
        if (isset($imports[$erstes])) {
            $rest = substr($text, strlen($erstes));
            return $imports[$erstes] . $rest;
        }
        return $namespace === '' ? $text : $namespace . '\\' . $text;
    }

    private static function klassenartigExistiert(string $name): bool {
        return class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name);
    }

    /** @return list<string> */
    private static function symboleDesPlugins(string $slug): array {
        $symbole = [];
        foreach (self::phpFilesIn(self::PLUGINS_DIR . "/{$slug}") as $datei) {
            foreach (self::sammleSymbole((string) file_get_contents($datei)) as $s) {
                $symbole[$s] = true;
            }
        }
        $liste = array_keys($symbole);
        sort($liste);
        return $liste;
    }

    /** @return array<string, array{0: string}> */
    public static function pluginSlugProvider(): array {
        $cases = [];
        foreach (scandir(self::PLUGINS_DIR) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir(self::PLUGINS_DIR . "/{$entry}")) {
                $cases[$entry] = [$entry];
            }
        }
        ksort($cases);
        return $cases;
    }

    /** @return list<string> */
    private static function phpFilesIn(string $dir): array {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /** @return array<string, mixed> */
    private function readManifest(string $slug): array {
        $json = json_decode((string) file_get_contents(self::PLUGINS_DIR . "/{$slug}/plugin.json"), true);
        $this->assertIsArray($json, "plugin.json fuer '{$slug}' ist kein gueltiges JSON.");
        return $json;
    }
}
