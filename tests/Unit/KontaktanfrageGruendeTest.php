<?php
// tests/Unit/KontaktanfrageGruendeTest.php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\Kontaktanfrage\Gruende;

require_once __DIR__ . '/../../plugins/kontaktanfrage/Plugin.php';

/**
 * Die abgeschlossene Gründe-Liste IST der Missbrauchsschutz des Addons: Weil
 * es kein Freitextfeld gibt, setzt der Server die Nachricht aus geprüften
 * Bausteinen zusammen, und vom Absender kommt nur ein Schlüssel, der in der
 * Liste stehen muss. Fällt diese Prüfung, ist aus dem Formular ein
 * Spam-Relais geworden - deshalb steht sie hier einzeln fest.
 */
class KontaktanfrageGruendeTest extends TestCase {

    public function testStandardgruendeSindImmerGueltig(): void {
        $liste = Gruende::alle('');

        $this->assertSame(array_keys(Gruende::STANDARD), array_keys($liste));
        foreach (array_keys(Gruende::STANDARD) as $schluessel) {
            $this->assertTrue(Gruende::istGueltig($schluessel, $liste));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function ungueltigeSchluessel(): array {
        return [
            'leer' => [''],
            'unbekannt' => ['spam'],
            'Grossschreibung' => ['Deckanfrage'],
            'Anzeigetext statt Schluessel' => ['Frage zur Abstammung'],
            'Teilstring' => ['deck'],
            'SQL-Versuch' => ["deckanfrage' OR '1'='1"],
            'Zahl' => ['0'],
        ];
    }

    #[DataProvider('ungueltigeSchluessel')]
    public function testNurSchluesselAusDerListeWerdenAkzeptiert(string $eingabe): void {
        $this->assertFalse(Gruende::istGueltig($eingabe, Gruende::alle("Zuchtberatung\nBesichtigung")));
    }

    public function testErgaenzteGruendeWerdenGueltigUndBehaltenIhrenText(): void {
        $liste = Gruende::alle("Zuchtberatung\nBesichtigung vor Ort");

        $this->assertTrue(Gruende::istGueltig('zuchtberatung', $liste));
        $this->assertSame('Besichtigung vor Ort', $liste['besichtigung-vor-ort'] ?? null);
        // Die Standardgründe stehen weiterhin vorn und bleiben unverändert.
        $this->assertSame('Deckanfrage', $liste['deckanfrage'] ?? null);
    }

    public function testSchluesselWirdAusDemAnzeigetextAbgeleitetUndIstStabil(): void {
        $this->assertSame('frage-zur-abstammung', Gruende::schluessel('Frage zur Abstammung'));
        $this->assertSame('zuechterin-gesucht', Gruende::schluessel('Züchterin gesucht'));
        $this->assertSame('gruss-aus-norwegen', Gruende::schluessel('Gruß aus Norwegen'));
        // Zweimal derselbe Text ergibt zweimal denselben Schlüssel - sonst
        // zeigten alte Anfragen nach einer Umsortierung einen anderen Grund.
        $this->assertSame(Gruende::schluessel('Kauf'), Gruende::schluessel('  Kauf  '));
    }

    public function testUnbrauchbareZeilenWerdenUebergangen(): void {
        $liste = Gruende::ausText("\n   \n---\nZuchtberatung\n***\n");

        $this->assertSame(['zuchtberatung' => 'Zuchtberatung'], $liste);
    }

    public function testDoppelteUndBereitsVorhandeneGruendeErscheinenNurEinmal(): void {
        $liste = Gruende::alle("Zuchtberatung\nzuchtberatung\nDeckanfrage");

        $this->assertCount(count(Gruende::STANDARD) + 1, $liste);
        $this->assertSame('Deckanfrage', $liste['deckanfrage']);
    }

    public function testAnzahlUndLaengeSindGedeckelt(): void {
        $zeilen = [];
        for ($i = 1; $i <= Gruende::MAX_ZUSATZ + 10; $i++) {
            $zeilen[] = "Grund Nummer {$i}";
        }
        $this->assertCount(Gruende::MAX_ZUSATZ, Gruende::ausText(implode("\n", $zeilen)));

        $lang = Gruende::ausText(str_repeat('a', Gruende::LABEL_MAX + 50));
        $this->assertSame(Gruende::LABEL_MAX, mb_strlen(reset($lang)));
    }

    /**
     * Der Anzeigetext eines Grundes landet in der Betreffzeile der E-Mail -
     * ein Zeilenumbruch darin wäre eine zusätzliche Kopfzeile. Die Liste ist
     * zwar admin-gepflegt und damit kein anonymer Eingabekanal, aber die
     * Bereinigung kostet nichts und nimmt der Betreffzeile die Frage.
     */
    public function testAnzeigetexteBleibenEinzeilig(): void {
        $liste = Gruende::ausText("Kauf\r\ninteresse");

        $this->assertSame(['kauf' => 'Kauf', 'interesse' => 'interesse'], $liste);
        foreach ($liste as $label) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $label);
        }
    }
}
