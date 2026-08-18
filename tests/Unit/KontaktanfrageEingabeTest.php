<?php
// tests/Unit/KontaktanfrageEingabeTest.php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\Kontaktanfrage\Eingabe;

require_once __DIR__ . '/../../plugins/kontaktanfrage/Plugin.php';

/**
 * Die Absenderadresse ist das einzige freie Textfeld, das aus einem
 * öffentlichen Formular bis in eine E-Mail durchgereicht wird - alles andere
 * (Grund, Empfänger, Betreff) setzt der Server aus geprüften Bausteinen
 * zusammen. Sie ist damit die Stelle, an der eine Header-Injection ansetzen
 * müsste, und der Grund, warum diese Prüfung eine reine Funktion ist: So
 * lässt sie sich ohne Datenbank und ohne Mailserver festnageln.
 */
class KontaktanfrageEingabeTest extends TestCase {

    /** @return array<string, array{0: string}> */
    public static function abgelehnteAdressen(): array {
        return [
            'leer' => [''],
            'nur Leerzeichen' => ['   '],
            'kein @' => ['besucher.example.org'],
            'kein Teil vor dem @' => ['@example.org'],
            'kein Punkt in der Domain' => ['besucher@localhost'],
            'Leerzeichen mittendrin' => ['bes ucher@example.org'],
            'zwei Adressen' => ['a@example.org, b@example.org'],
        ];
    }

    #[DataProvider('abgelehnteAdressen')]
    public function testUngueltigeAdressenWerdenAbgelehnt(string $eingabe): void {
        $this->assertNull(Eingabe::email($eingabe));
    }

    /** @return array<string, array{0: string}> */
    public static function adressenMitZeilenumbruch(): array {
        return [
            'LF mit angehaengter Kopfzeile' => ["opfer@example.org\nBcc: masse@example.org"],
            'CRLF mit angehaengter Kopfzeile' => ["opfer@example.org\r\nBcc: masse@example.org"],
            'nur ein angehaengtes LF' => ["opfer@example.org\n"],
            'nur ein angehaengtes CR' => ["opfer@example.org\r"],
            'fuehrendes CRLF' => ["\r\nopfer@example.org"],
        ];
    }

    /**
     * Der angehängte, für sich harmlose Zeilenumbruch ist der eigentliche
     * Prüfstein: trim() würde ihn entfernen und die Adresse damit gültig
     * machen. Die Prüfung muss deshalb VOR dem Trimmen greifen - sonst
     * unterscheidet sie nicht mehr zwischen "sauber eingegeben" und "mit
     * Zeilenumbruch geliefert".
     */
    #[DataProvider('adressenMitZeilenumbruch')]
    public function testZeilenumbruecheWerdenAbgelehnt(string $eingabe): void {
        $this->assertNull(Eingabe::email($eingabe));
    }

    public function testGueltigeAdresseKommtGetrimmtZurueck(): void {
        $this->assertSame('besucher@example.org', Eingabe::email('  besucher@example.org  '));
        $this->assertSame('vor.nach+filter@example.co.uk', Eingabe::email('vor.nach+filter@example.co.uk'));
    }

    public function testUeberlangeAdresseWirdAbgelehnt(): void {
        // Die Spalte fasst 150 Zeichen; eine längere Adresse würde beim
        // Einfügen abgeschnitten und wäre danach eine andere Adresse.
        $zuLang = str_repeat('a', 140) . '@example.org';
        $this->assertGreaterThan(Eingabe::EMAIL_MAX, strlen($zuLang));
        $this->assertNull(Eingabe::email($zuLang));
    }

    public function testEinzeiligEntferntSteuerzeichenUndFasstWhitespaceZusammen(): void {
        $this->assertSame('Anna Muster', Eingabe::einzeilig("Anna\r\n\tMuster", 150));
        $this->assertSame('Anna Muster', Eingabe::einzeilig('   Anna    Muster   ', 150));
        // Steuerzeichen werden durch ein Leerzeichen ersetzt, nicht gestrichen:
        // Sonst würden zwei durch einen Umbruch getrennte Wörter zu einem.
        $this->assertSame('A B', Eingabe::einzeilig("A\x00B", 150));
    }

    public function testEinzeiligKuerztAufDieAngegebeneLaenge(): void {
        $this->assertSame('abcde', Eingabe::einzeilig('abcdefghij', 5));
        // Mehrbyte-Zeichen zählen als ein Zeichen, nicht als zwei Bytes.
        $this->assertSame('äöü', Eingabe::einzeilig('äöüßa', 3));
    }
}
