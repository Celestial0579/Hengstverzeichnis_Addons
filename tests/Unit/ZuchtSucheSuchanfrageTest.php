<?php
// tests/Unit/ZuchtSucheSuchanfrageTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\ZuchtSuche\Suchanfrage;

require_once __DIR__ . '/../../plugins/zucht-suche/Plugin.php';

/**
 * Prüft die Eingabeprüfung der Zucht-Suche (Addons#105, seit Addons#122 auf
 * die Kontaktliste umgestellt).
 *
 * Warum als Unit-Test: `Suchanfrage` ist die einzige Stelle, an der Werte aus
 * der Adresszeile in das Addon gelangen. Sie hängt an keiner Framework-Klasse
 * und an keiner Datenbank - über HTTP wären dieselben Fälle (Array statt
 * String, „3x" als Seitennummer, `%` im Suchtext) nur mit einer vollständigen
 * Instanz und je einem Request prüfbar, hier sind es Millisekunden.
 *
 * Das Zusammenspiel mit dem Kern - 404 ohne contacts.view, Verlinkung auf
 * /kontakt - gehört nach tests/Functional und braucht eine Datenbank.
 */
class ZuchtSucheSuchanfrageTest extends TestCase {

    /** Was ein Gast mit contacts.view UND horses.view sehen darf. */
    private const ALLE_ROLLEN = [
        Suchanfrage::ROLLE_ALLE,
        Suchanfrage::ROLLE_ZUECHTER,
        Suchanfrage::ROLLE_STATION,
        Suchanfrage::ROLLE_BESITZER,
        Suchanfrage::ROLLE_HALTER,
    ];

    /** Ohne horses.view: nur das Kennzeichen am Kontakt, keine abgeleitete Rolle. */
    private const OHNE_PFERDE = [Suchanfrage::ROLLE_ALLE, Suchanfrage::ROLLE_ZUECHTER];

    /**
     * Seit #122 gibt es keine zwei Reiter mehr, sondern einen Rollenfilter -
     * und der hat mit „(alle)" immer eine gültige Antwort. Der Rückfallwert
     * ist deshalb ROLLE_ALLE und nicht mehr der erste erlaubte Eintrag.
     */
    public function testOhneAngabeGiltAlle(): void {
        $this->assertSame(Suchanfrage::ROLLE_ALLE, Suchanfrage::aus([], self::ALLE_ROLLEN)->rolle);
        $this->assertSame(
            Suchanfrage::ROLLE_ALLE,
            Suchanfrage::aus(['rolle' => 'voellig-erfunden'], self::ALLE_ROLLEN)->rolle,
            'Eine unbekannte Rolle darf keinen sechsten Filterwert erfinden.'
        );
    }

    public function testErlaubteRolleWirdUebernommen(): void {
        foreach ([
            Suchanfrage::ROLLE_ZUECHTER,
            Suchanfrage::ROLLE_STATION,
            Suchanfrage::ROLLE_BESITZER,
            Suchanfrage::ROLLE_HALTER,
        ] as $rolle) {
            $this->assertSame($rolle, Suchanfrage::aus(['rolle' => $rolle], self::ALLE_ROLLEN)->rolle);
        }
    }

    /**
     * Der Kern der Fail-closed-Regel auf Parameterebene: Die abgeleiteten
     * Rollen sind Aussagen über Pferde. Wer Pferde nicht sehen darf, bekommt
     * sie auch nicht, wenn er sie in die Adresszeile schreibt - sonst wäre der
     * Filter ein Orakel darüber, welche Kontakte Pferde haben.
     */
    public function testAbgeleiteteRollenBrauchenDasPferderecht(): void {
        foreach ([
            Suchanfrage::ROLLE_STATION,
            Suchanfrage::ROLLE_BESITZER,
            Suchanfrage::ROLLE_HALTER,
        ] as $rolle) {
            $anfrage = Suchanfrage::aus(['rolle' => $rolle], self::OHNE_PFERDE);
            $this->assertSame(
                Suchanfrage::ROLLE_ALLE,
                $anfrage->rolle,
                "Ohne horses.view darf '{$rolle}' nicht übernommen werden."
            );
            $this->assertArrayNotHasKey('rolle', $anfrage->alsQuery());
        }

        // Das Züchter-Kennzeichen steht am Kontakt selbst (contacts.is_breeder)
        // und bleibt deshalb auch ohne horses.view wählbar.
        $this->assertSame(
            Suchanfrage::ROLLE_ZUECHTER,
            Suchanfrage::aus(['rolle' => Suchanfrage::ROLLE_ZUECHTER], self::OHNE_PFERDE)->rolle
        );
    }

    /**
     * Bis 0.7 gab es `membership_status` nur auf `persons` - der Filter war an
     * den Züchter-Reiter gebunden und wurde sonst verworfen. Seit dem
     * Zusammenlegen (#336) ist er ein Feld der gemeinsamen Kontaktliste und
     * gilt für jeden Kontakt, unabhängig von der Rolle.
     */
    public function testMitgliedsstatusGiltFuerJedeRolle(): void {
        foreach ([Suchanfrage::ROLLE_ALLE, Suchanfrage::ROLLE_ZUECHTER, Suchanfrage::ROLLE_STATION] as $rolle) {
            $anfrage = Suchanfrage::aus(['rolle' => $rolle, 'mitglied' => 'Mitglied'], self::ALLE_ROLLEN);
            $this->assertSame('Mitglied', $anfrage->mitglied);
            $this->assertSame('Mitglied', $anfrage->alsQuery()['mitglied'] ?? null);
        }
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

    /** Ein Rollen- oder Blätterwechsel darf die eingestellte Suche nicht verwerfen. */
    public function testAlsQueryTraegtDieGesetztenFilterWeiter(): void {
        $anfrage = Suchanfrage::aus([
            'rolle' => Suchanfrage::ROLLE_ZUECHTER,
            'name' => 'Meier',
            'ort' => '',
            'land' => 'Deutschland',
            'seite' => '4',
        ], self::ALLE_ROLLEN);

        $this->assertSame(
            ['rolle' => Suchanfrage::ROLLE_ZUECHTER, 'name' => 'Meier', 'land' => 'Deutschland'],
            $anfrage->alsQuery(),
            'Leere Filter und die Seitennummer gehören nicht in den Grund-Link.'
        );

        $this->assertSame(
            ['rolle' => Suchanfrage::ROLLE_STATION, 'name' => 'Meier', 'land' => 'Deutschland'],
            $anfrage->alsQuery(['rolle' => Suchanfrage::ROLLE_STATION])
        );

        $this->assertSame(
            ['rolle' => Suchanfrage::ROLLE_ZUECHTER, 'name' => 'Meier'],
            $anfrage->alsQuery(['land' => '']),
            'Ein leerer Wert entfernt den Parameter, statt ihn leer anzuhängen.'
        );

        $this->assertSame('5', $anfrage->alsQuery(['seite' => '5'])['seite']);
    }

    /**
     * „(alle)" ist der Leerwert und gehört nicht in den Link - sonst stünde
     * `?rolle=` in jeder Adresse und sähe wie ein gesetzter Filter aus.
     */
    public function testRolleAlleStehtNichtImLink(): void {
        $anfrage = Suchanfrage::aus(['name' => 'Meier'], self::ALLE_ROLLEN);
        $this->assertArrayNotHasKey('rolle', $anfrage->alsQuery());
        $this->assertSame(['name' => 'Meier'], $anfrage->alsQuery());
    }

    /** Die Seitennummer bleibt gelesen - nur der Grund-Link führt sie nicht mit. */
    public function testSeitennummerWirdGelesenAberNichtInDenGrundLinkGeschrieben(): void {
        $anfrage = Suchanfrage::aus(['seite' => '4'], self::ALLE_ROLLEN);
        $this->assertSame(4, $anfrage->seite);
        $this->assertArrayNotHasKey('seite', $anfrage->alsQuery());
    }
}
