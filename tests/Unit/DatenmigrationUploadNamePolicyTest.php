<?php
// tests/Unit/DatenmigrationUploadNamePolicyTest.php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\Datenmigration\UploadNamePolicy;

require_once __DIR__ . '/../../plugins/datenmigration/Plugin.php';

/**
 * Die Pfadhärtung des Imports prüfte, WOHIN geschrieben wird (keine
 * Traversal, keine absoluten Pfade), aber nicht WAS. Ziel ist am Ende
 * public/uploads, und das Recht, einen Import auszulösen, war an jede Gruppe
 * vergebbar - ein Archiv mit einer .php darin genügte für Codeausführung.
 *
 * Diese Regel ist die Ergänzung. Sie steht als eigene Klasse im Plugin und
 * ist deshalb ohne Datenbank und ohne Kern-Instanz prüfbar.
 */
class DatenmigrationUploadNamePolicyTest extends TestCase {

    /**
     * @return array<string, array{0: string}>
     */
    public static function erlaubteNamen(): array {
        return [
            'Bild' => ['pferd.jpg'],
            'Bild in Unterverzeichnis' => ['horses/horse_123_abc.png'],
            'Grossschreibung' => ['LOGO.PNG'],
            'webp' => ['branding/logo.webp'],
            'PDF aus gesundheitstests' => ['dokumente/befund.pdf'],
            'Punkt im Namen' => ['stute.2024.jpg'],
            'Textdatei aus dem Altbestand' => ['horses/notiz.txt'],
        ];
    }

    #[DataProvider('erlaubteNamen')]
    public function testGewoehnlicheDateienKommenDurch(string $name): void {
        UploadNamePolicy::assertAllowed($name);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function abgelehnteNamen(): array {
        return [
            'PHP-Datei' => ['boese.php'],
            'PHP-Variante' => ['boese.php5'],
            'phtml' => ['boese.phtml'],
            'phar' => ['boese.phar'],
            // Der Ausführungsschutz des Zielverzeichnisses selbst.
            'htaccess' => ['.htaccess'],
            'htaccess im Unterverzeichnis' => ['horses/.htaccess'],
            'Punktdatei' => ['.env'],
            // Ein Apache mit AddHandler wertet ALLE Endungen aus, nicht nur
            // die letzte - deshalb muss jede erlaubt sein.
            'Doppelte Endung' => ['bild.php.jpg'],
            'Grossgeschriebene PHP-Endung' => ['BOESE.PHP'],
            'Ohne Endung' => ['readme'],
            'Skript' => ['tu.sh'],
            'Archiv' => ['daten.zip'],
            'Unbekannte Endung' => ['datei.xyz'],
        ];
    }

    #[DataProvider('abgelehnteNamen')]
    public function testAusfuehrbaresUndPunktdateienWerdenAbgelehnt(string $name): void {
        $this->expectException(\RuntimeException::class);
        UploadNamePolicy::assertAllowed($name);
    }
}
