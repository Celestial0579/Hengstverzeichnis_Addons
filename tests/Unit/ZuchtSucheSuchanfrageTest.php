<?php
// tests/Unit/ZuchtSucheSuchanfrageTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\ZuchtSuche\Suchanfrage;

require_once __DIR__ . '/../../plugins/zucht-suche/Plugin.php';

/**
 * Prüft die Eingabeprüfung der Zucht-Suche (Addons#105).
 *
 * Warum als Unit-Test: `Suchanfrage` ist die einzige Stelle, an der Werte aus
 * der Adresszeile in das Addon gelangen. Sie hängt an keiner Framework-Klasse
 * und an keiner Datenbank - über HTTP wären dieselben Fälle (Array statt
 * String, „3x" als Seitennummer, `%` im Suchtext) nur mit einer vollständigen
 * Instanz und je einem Request prüfbar, hier sind es Millisekunden.
 *
 * Das Zusammenspiel mit dem Kern - 404 ohne persons.view/breeding_stations.view,
 * Verlinkung auf /person und /station - gehört nach tests/Functional und
 * braucht eine Datenbank.
 */
class ZuchtSucheSuchanfrageTest extends TestCase {

    private const BEIDE = [Suchanfrage::ART_ZUECHTER, Suchanfrage::ART_STATIONEN];

    public function testArtFaelltAufDenErstenErlaubtenReiterZurueck(): void {
        $this->assertSame(Suchanfrage::ART_ZUECHTER, Suchanfrage::aus([], self::BEIDE)->art);
        $this->assertSame(
            Suchanfrage::ART_ZUECHTER,
            Suchanfrage::aus(['art' => 'voellig-erfunden'], self::BEIDE)->art,
            'Eine unbekannte Art darf keinen dritten Reiter erfinden.'
        );
        $this->assertSame(
            Suchanfrage::ART_STATIONEN,
            Suchanfrage::aus([], [Suchanfrage::ART_STATIONEN])->art,
            'Ohne persons.view ist "Deckstationen" der Standard.'
        );
    }

    /**
     * Der Kern der Fail-closed-Regel auf Parameterebene: Wer die Gattung nicht
     * sehen darf, bekommt sie auch nicht, wenn er sie in die Adresszeile
     * schreibt.
     */
    public function testEineNichtErlaubteArtWirdNichtUebernommen(): void {
        $anfrage = Suchanfrage::aus(['art' => Suchanfrage::ART_STATIONEN], [Suchanfrage::ART_ZUECHTER]);
        $this->assertSame(Suchanfrage::ART_ZUECHTER, $anfrage->art);
    }

    public function testErlaubteArtWirdUebernommen(): void {
        $this->assertSame(
            Suchanfrage::ART_STATIONEN,
            Suchanfrage::aus(['art' => Suchanfrage::ART_STATIONEN], self::BEIDE)->art
        );
    }

    /** Der Mitgliedsstatus gehört zur Person - Deckstationen haben die Spalte nicht. */
    public function testMitgliedsstatusGiltNurFuerZuechter(): void {
        $zuechter = Suchanfrage::aus(['art' => Suchanfrage::ART_ZUECHTER, 'mitglied' => 'Mitglied'], self::BEIDE);
        $this->assertSame('Mitglied', $zuechter->mitglied);

        $stationen = Suchanfrage::aus(['art' => Suchanfrage::ART_STATIONEN, 'mitglied' => 'Mitglied'], self::BEIDE);
        $this->assertSame('', $stationen->mitglied);
        $this->assertArrayNotHasKey('mitglied', $stationen->alsQuery());
    }

    /** `?name[]=x` darf weder Warnung noch TypeError auslösen. */
    public function testNichtStringsWerdenZuLeerstring(): void {
        foreach ([['x'], null, 5, 1.5, true, new \stdClass()] as $wert) {
            $this->assertSame('', Suchanfrage::text($wert));
        }
    }

    public function testTextWirdGetrimmtUndGedeckelt(): void {
        $this->assertSame('Kiel', Suchanfrage::text("  Kiel \n"));
        $this->assertSame('', Suchanfrage::text('   '));

        $lang = str_repeat('a', Suchanfrage::TEXT_MAX + 50);
        $this->assertSame(Suchanfrage::TEXT_MAX, strlen(Suchanfrage::text($lang)));
    }

    /** Geschnitten wird zeichenweise - ein halbes UTF-8-Byte wäre kaputtes HTML. */
    public function testDerSchnittZerlegtKeineMehrbyteZeichen(): void {
        $gekuerzt = Suchanfrage::text(str_repeat('ü', 10), 5);
        $this->assertSame('üüüüü', $gekuerzt);
        $this->assertSame($gekuerzt, mb_convert_encoding($gekuerzt, 'UTF-8', 'UTF-8'));
    }

    /**
     * `%` und `_` sind LIKE-Platzhalter: Ohne Maskierung fände die Eingabe "%"
     * jeden Datensatz. Das ist kein Injection-Schutz (der Wert wird gebunden),
     * sondern die Zusicherung, dass eine Suche nach "%" nach dem Zeichen sucht.
     */
    public function testLikePlatzhalterWerdenMaskiert(): void {
        $this->assertSame('%Hof\\%%', Suchanfrage::likeMuster('Hof%'));
        $this->assertSame('%a\\_b%', Suchanfrage::likeMuster('a_b'));
        $this->assertSame('%c\\\\d%', Suchanfrage::likeMuster('c\\d'));
        $this->assertSame('%Kiel%', Suchanfrage::likeMuster('Kiel'), 'Harmlose Eingaben bleiben unverändert.');
    }

    public function testSeitennummerWirdValidiertNichtUmgedeutet(): void {
        $this->assertSame(3, Suchanfrage::seite('3'));
        $this->assertSame(1, Suchanfrage::seite('3x'), 'Ein (int)-Cast machte daraus eine 3.');
        $this->assertSame(1, Suchanfrage::seite('abc'));
        $this->assertSame(1, Suchanfrage::seite('0'));
        $this->assertSame(1, Suchanfrage::seite('-7'));
        $this->assertSame(1, Suchanfrage::seite(''));
        $this->assertSame(1, Suchanfrage::seite(['5']), 'Ein Array darf keinen TypeError auslösen.');
    }

    /** Ein Reiter- oder Blätterwechsel darf die eingestellte Suche nicht verwerfen. */
    public function testAlsQueryTraegtDieGesetztenFilterWeiter(): void {
        $anfrage = Suchanfrage::aus([
            'art' => Suchanfrage::ART_ZUECHTER,
            'name' => 'Meier',
            'ort' => '',
            'land' => 'Deutschland',
            'seite' => '4',
        ], self::BEIDE);

        $this->assertSame(
            ['art' => Suchanfrage::ART_ZUECHTER, 'name' => 'Meier', 'land' => 'Deutschland'],
            $anfrage->alsQuery(),
            'Leere Filter und die Seitennummer gehören nicht in den Grund-Link.'
        );

        $this->assertSame(
            ['art' => Suchanfrage::ART_STATIONEN, 'name' => 'Meier', 'land' => 'Deutschland'],
            $anfrage->alsQuery(['art' => Suchanfrage::ART_STATIONEN])
        );

        $this->assertSame(
            ['art' => Suchanfrage::ART_ZUECHTER, 'name' => 'Meier'],
            $anfrage->alsQuery(['land' => '']),
            'Ein leerer Wert entfernt den Parameter, statt ihn leer anzuhängen.'
        );

        $this->assertSame('5', $anfrage->alsQuery(['seite' => '5'])['seite']);
    }

    /** Die Seitennummer bleibt gelesen - nur der Grund-Link führt sie nicht mit. */
    public function testSeitennummerWirdGelesenAberNichtInDenGrundLinkGeschrieben(): void {
        $anfrage = Suchanfrage::aus(['seite' => '4'], self::BEIDE);
        $this->assertSame(4, $anfrage->seite);
        $this->assertArrayNotHasKey('seite', $anfrage->alsQuery());
    }
}
