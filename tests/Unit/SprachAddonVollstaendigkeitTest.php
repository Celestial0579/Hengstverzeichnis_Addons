<?php
// tests/Unit/SprachAddonVollstaendigkeitTest.php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vollständigkeits-Gate für die Sprach-Addons (Framework#344).
 *
 * WARUM ES DIESEN TEST GEBEN MUSS. Bis v0.8 lagen alle zwölf Sprachen im Kern,
 * und `LocaleCompletenessTest` dort hielt sie hart auf dem Schlüsselsatz von
 * `de.php`. Mit der Auslagerung verliert der Kern diese Prüfung für zehn
 * Sprachen — er kennt die Addons nicht. Ohne Ersatz **verrottet jede
 * Übersetzung unbemerkt**: Der Kern bekommt neue Texte, das Addon zieht nicht
 * nach, und die fehlenden Schlüssel erscheinen still auf Deutsch. Eine
 * gemischtsprachige Seite sieht nicht nach einem Fehler aus, sondern nach
 * einer unfertigen Übersetzung — und niemand meldet sie.
 *
 * Framework#344 verlangt deshalb ausdrücklich beides: diesen Test **und** eine
 * Anzeige im Adminbereich („nl: 287 von 302 Schlüsseln", dort umgesetzt).
 *
 * Der Maßstab ist der Schlüsselsatz des Kerns, den dieses Repo über
 * `composer.lock` ohnehin genau kennt.
 */
class SprachAddonVollstaendigkeitTest extends TestCase {

    private const PLUGINS_DIR = __DIR__ . '/../../plugins';

    /** @return array<string, mixed> */
    private static function kernSchluessel(): array {
        $datei = \FRAMEWORK_VENDOR_DIR . '/lang/de.php';
        self::assertFileExists($datei, 'Die Quellsprache des Kerns fehlt - composer.lock aktuell?');
        $tabelle = require $datei;
        self::assertIsArray($tabelle);

        return $tabelle;
    }

    /** @return array<string, array{0: string}> */
    public static function sprachAddonProvider(): array {
        $faelle = [];
        foreach (glob(self::PLUGINS_DIR . '/sprache-*', GLOB_ONLYDIR) ?: [] as $verzeichnis) {
            $slug = basename($verzeichnis);
            $faelle[$slug] = [$slug];
        }
        self::assertNotSame([], $faelle, 'Kein einziges Sprach-Addon gefunden - Verzeichnisname geaendert?');

        return $faelle;
    }

    /** @return array<string, string> */
    private function sprachtabelle(string $slug): array {
        $code = substr($slug, strlen('sprache-'));
        $datei = self::PLUGINS_DIR . "/{$slug}/lang/core/{$code}.php";

        $this->assertFileExists(
            $datei,
            "{$slug}: Die Sprachdatei muss unter lang/core/{$code}.php liegen - nur dort findet der Kern sie."
        );
        $tabelle = require $datei;
        $this->assertIsArray($tabelle, "{$slug}: {$code}.php liefert kein Array.");

        return $tabelle;
    }

    #[DataProvider('sprachAddonProvider')]
    public function testJedesSprachAddonDecktDenKernSatzExaktAb(string $slug): void {
        $kern = array_keys(self::kernSchluessel());
        $eigen = array_keys($this->sprachtabelle($slug));
        sort($kern);
        sort($eigen);

        $fehlend = array_values(array_diff($kern, $eigen));
        $ueberzaehlig = array_values(array_diff($eigen, $kern));

        $this->assertSame(
            [],
            $fehlend,
            "{$slug}: Diese Schlüssel fehlen und erschienen zur Laufzeit still auf Deutsch: "
            . implode(', ', array_slice($fehlend, 0, 15)) . (count($fehlend) > 15 ? ' …' : '')
        );
        $this->assertSame(
            [],
            $ueberzaehlig,
            "{$slug}: Diese Schlüssel kennt der Kern nicht (mehr) - übrig geblieben oder vertippt: "
            . implode(', ', array_slice($ueberzaehlig, 0, 15)) . (count($ueberzaehlig) > 15 ? ' …' : '')
        );
    }

    /**
     * Ein leerer Wert ist kein übersetzter Wert. Er käme als leerer Platz auf
     * die Seite - schlimmer als der deutsche Rückfall, denn den gäbe es dann
     * nicht mehr.
     */
    #[DataProvider('sprachAddonProvider')]
    public function testKeinSchluesselIstLeer(string $slug): void {
        $leer = [];
        foreach ($this->sprachtabelle($slug) as $schluessel => $wert) {
            if (!is_string($wert) || trim($wert) === '') {
                $leer[] = (string)$schluessel;
            }
        }

        $this->assertSame([], $leer, "{$slug}: leere Übersetzungen bei " . implode(', ', $leer));
    }

    /**
     * Platzhalter wie `{provider}` müssen mitübersetzt werden - fehlt einer,
     * steht in der Oberfläche ein Satz mit einer Lücke statt eines Namens.
     */
    #[DataProvider('sprachAddonProvider')]
    public function testPlatzhalterBleibenErhalten(string $slug): void {
        $kern = self::kernSchluessel();
        $eigen = $this->sprachtabelle($slug);
        $abweichungen = [];

        foreach ($kern as $schluessel => $quelle) {
            if (!is_string($quelle) || !isset($eigen[$schluessel])) {
                continue;
            }
            preg_match_all('/\{[a-z_]+\}/', $quelle, $quellPlatzhalter);
            preg_match_all('/\{[a-z_]+\}/', (string)$eigen[$schluessel], $zielPlatzhalter);

            $fehlen = array_diff($quellPlatzhalter[0], $zielPlatzhalter[0]);
            if ($fehlen !== []) {
                $abweichungen[] = $schluessel . ' (' . implode(', ', $fehlen) . ')';
            }
        }

        $this->assertSame([], $abweichungen, "{$slug}: fehlende Platzhalter bei " . implode('; ', $abweichungen));
    }

    /**
     * Ein Sprach-Addon bringt eine Sprache mit und sonst nichts. Routen,
     * Berechtigungen oder eigene Tabellen wären ein anderes Addon.
     */
    #[DataProvider('sprachAddonProvider')]
    public function testEinSprachAddonBringtNurEineSpracheMit(string $slug): void {
        $manifest = json_decode(
            (string)file_get_contents(self::PLUGINS_DIR . "/{$slug}/plugin.json"),
            true
        );
        $this->assertIsArray($manifest);

        $this->assertSame([], $manifest['hooks'] ?? [], "{$slug}: ein Sprach-Addon registriert keine Hooks.");
        $this->assertArrayNotHasKey('permissions', $manifest, "{$slug}: ein Sprach-Addon braucht keine Berechtigung.");
        $this->assertArrayNotHasKey('owns', $manifest, "{$slug}: ein Sprach-Addon besitzt nichts.");
    }
}
