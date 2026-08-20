<?php
// tests/Unit/MitgliedsstatusWerteTest.php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\Mitgliedsstatus\Werte;

require_once __DIR__ . '/../../plugins/mitgliedsstatus/Plugin.php';

/**
 * Die Abbildungsregel des Addons `mitgliedsstatus` (Addons#132) als reine
 * Funktion - ohne Datenbank, ohne Framework-Instanz.
 *
 * Warum sie einen eigenen Test verdient: Sie läuft genau EINMAL, bei der
 * Übernahme der Bestandswerte, über den kompletten Kontaktbestand. Ein Fehler
 * darin sortiert lautlos alles falsch ein, und der Marker sorgt anschliessend
 * dafür, dass ein zweiter Lauf es nicht mehr richtigstellt. Die Regel ist
 * damit die Stelle mit dem grössten Schaden je Zeile Code - und die einzige,
 * die sich ohne HTTP festnageln lässt.
 *
 * Der eigentliche Prüfstein ist der Fall `Nichtmitglied NO`: Er enthält
 * sichtbar das Wort "Nichtmitglied", darf aber NICHT abgebildet werden - das
 * `NO` ist in diesem Bestand ein Länderkürzel (siehe database/schema.sql im
 * Kern), und es wegzuwerfen wäre Raten. Ohne diesen Fall liesse sich eine
 * "hilfreiche" Teilstring-Suche nicht von der richtigen Lösung unterscheiden.
 */
class MitgliedsstatusWerteTest extends TestCase {

    /** @return array<string, array{0: string}> */
    public static function mitgliedSchreibweisen(): array {
        return [
            'schlicht' => ['Mitglied'],
            'klein' => ['mitglied'],
            'gross' => ['MITGLIED'],
            'mit Rand-Leerraum' => ['  Mitglied '],
            'Vollmitglied' => ['Vollmitglied'],
            'mehrfacher Leerraum' => ['ordentliches   Mitglied'],
            'englisch' => ['Member'],
        ];
    }

    #[DataProvider('mitgliedSchreibweisen')]
    public function testSchreibweisenVonMitgliedWerdenAbgebildet(string $eingabe): void {
        $this->assertSame(Werte::MITGLIED, Werte::ausFreitext($eingabe));
    }

    /** @return array<string, array{0: string}> */
    public static function nichtmitgliedSchreibweisen(): array {
        return [
            'zusammen' => ['Nichtmitglied'],
            'getrennt' => ['Nicht Mitglied'],
            'Bindestrich' => ['nicht-mitglied'],
            'kein Mitglied' => ['kein Mitglied'],
            'englisch' => ['non-member'],
        ];
    }

    #[DataProvider('nichtmitgliedSchreibweisen')]
    public function testSchreibweisenVonNichtmitgliedWerdenAbgebildet(string $eingabe): void {
        $this->assertSame(Werte::NICHTMITGLIED, Werte::ausFreitext($eingabe));
    }

    /** @return array<string, array{0: string}> */
    public static function nichtAbbildbar(): array {
        return [
            // Der Platzhalter aus dem Kern-Formular selbst. Das Kürzel ist
            // eine zusätzliche Aussage (Herkunft), kein Rauschen.
            'Nichtmitglied mit Länderkürzel' => ['Nichtmitglied NO'],
            'Mitglied mit Länderkürzel' => ['Mitglied DK'],
            'Mitglied mit Jahr' => ['Mitglied seit 1998'],
            'Ehrenmitglied' => ['Ehrenmitglied'],
            'Fördermitglied' => ['Fördermitglied'],
            'zwei Angaben' => ['Mitglied / Nichtmitglied'],
            'freie Notiz' => ['ausgetreten 2021'],
            'leer' => [''],
            'nur Leerraum' => ["  \t "],
        ];
    }

    #[DataProvider('nichtAbbildbar')]
    public function testWasSichNichtAbbildenLaesstBleibtOffen(string $eingabe): void {
        $this->assertNull(
            Werte::ausFreitext($eingabe),
            "'{$eingabe}' darf nicht abgebildet werden - der Wortlaut gehört einem Menschen vorgelegt."
        );
    }

    /**
     * null heisst "unklar", nicht "keine Angabe". Die beiden fallen in der
     * Übernahme zwar auf denselben gespeicherten Statuswert, aber nur der
     * unklare Fall wird als offen markiert und nachgearbeitet - fiele die
     * Unterscheidung weg, verschwänden die offenen Wortlaute ungefragt in
     * "keine Angabe".
     */
    public function testNullIstNichtDerWertKeineAngabe(): void {
        $this->assertNotSame(Werte::KEINE_ANGABE, Werte::ausFreitext('Ehrenmitglied'));
        $this->assertNull(Werte::ausFreitext('Ehrenmitglied'));
    }

    /**
     * Normalisiert wird ausschliesslich, was keine Bedeutung trägt. Insbesondere
     * bleibt jedes zusätzliche Wort stehen - sonst fiele 'Mitglied seit 1998'
     * mit 'Mitglied' zusammen.
     */
    public function testNormalisierungEbnetNurBedeutungslosesEin(): void {
        $this->assertSame('mitglied', Werte::normalisieren("  MITGLIED\t"));
        $this->assertSame('nicht mitglied', Werte::normalisieren("Nicht    Mitglied"));
        $this->assertSame('mitglied seit 1998', Werte::normalisieren('Mitglied seit 1998'));
    }

    public function testWerteListeIstAbgeschlossen(): void {
        $this->assertSame(
            [Werte::MITGLIED, Werte::NICHTMITGLIED, Werte::KEINE_ANGABE],
            array_keys(Werte::alle())
        );

        $this->assertTrue(Werte::istGueltig(Werte::MITGLIED));
        $this->assertFalse(Werte::istGueltig('ehrenmitglied'));
    }

    /**
     * Eine Formulareingabe wird nie zu einem Status "gemacht": Was nicht in
     * der Liste steht, fällt auf "keine Angabe" - nie auf Mitglied.
     */
    public function testEingabeAusserhalbDerListeFaelltAufKeineAngabe(): void {
        $this->assertSame(Werte::KEINE_ANGABE, Werte::ausEingabe('ehrenmitglied'));
        $this->assertSame(Werte::KEINE_ANGABE, Werte::ausEingabe(null));
        $this->assertSame(Werte::KEINE_ANGABE, Werte::ausEingabe(['mitglied']));
        $this->assertSame(Werte::MITGLIED, Werte::ausEingabe('mitglied'));
    }
}
