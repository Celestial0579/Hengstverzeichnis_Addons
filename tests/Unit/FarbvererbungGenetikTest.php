<?php
// tests/Unit/FarbvererbungGenetikTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\Farbvererbung\FjordColor;

/**
 * Rechenkern des Plugins "farbvererbung": Fohlenfarb-Verteilung aus zwei
 * Eltern-Phänotypen (`FjordColor::predictFoal()`), rein statisch - keine
 * Datenbank, keine laufende Instanz (#76).
 *
 * Warum ein eigener Unit-Test neben tests/Functional/FarbvererbungPluginTest.php:
 * Der Functional-Test prüft genau EINEN Rechenfall (Rotfalbe x Rotfalbe ->
 * 100 % Rotfalbe) - und der setzt p(rot) = 1 und p(kein Cream) = 1, wodurch
 * brunblakk, graa, ulsblakk und gulblakk allesamt rechnerisch auf 0 fallen.
 * Agouti-Locus, Cream-Locus und die Heterozygotie-Annahme p(e) = 0,25 bei E_
 * waren damit vollständig unbeobachtet; ein Vertauschen der brunblakk-/
 * graa-Zeilen im Rückgabe-Array wäre grün geblieben. Die fünf Testfamilien
 * hier decken genau diese blinden Flecken ab.
 *
 * Modellannahme (siehe Klassenkommentar von FjordColor): Je Locus gelten alle
 * mit dem Eltern-PHÄNOTYP verträglichen Genotypen als gleich wahrscheinlich,
 * z. B. E_ ∈ {EE, Ee} -> p(e) = 0,25. Alle Sollwerte unten sind von Hand aus
 * dieser Annahme hergeleitet (Produkt unabhängiger Loci), nicht aus der
 * Implementierung abgeschrieben.
 */
class FarbvererbungGenetikTest extends TestCase {

    public static function setUpBeforeClass(): void {
        // Plugins liegen nicht im Composer-Autoloader (sie werden zur Laufzeit
        // vom PluginManager des Kerns geladen), deshalb hier ausdrücklich.
        require_once __DIR__ . '/../../plugins/farbvererbung/Plugin.php';
    }

    /**
     * Familie 1: Jede der 25 Paarungen ergibt in Summe exakt 1,0 - die fünf
     * Phänotypen sind eine vollständige Zerlegung. Fängt jede Regression, bei
     * der die Prozentanzeige sich still auf z. B. 90 % addiert.
     */
    public function testEveryPairingSumsToOne(): void {
        foreach (FjordColor::ORDER as $sire) {
            foreach (FjordColor::ORDER as $dam) {
                $result = FjordColor::predictFoal($sire, $dam);
                $this->assertEqualsWithDelta(
                    1.0,
                    array_sum($result),
                    1e-9,
                    "Summe der Fohlenfarb-Wahrscheinlichkeiten für {$sire} x {$dam} muss 1,0 sein."
                );
                $this->assertSame(
                    FjordColor::ORDER,
                    array_keys($result),
                    "predictFoal({$sire}, {$dam}) muss genau die fünf Falbfarben liefern."
                );
                foreach ($result as $key => $p) {
                    $this->assertGreaterThanOrEqual(0.0, $p, "Negative Wahrscheinlichkeit für {$key} bei {$sire} x {$dam}.");
                }
            }
        }
    }

    /**
     * Familie 2: Graufalbe x Graufalbe isoliert den Agouti-Locus, den der
     * Functional-Test nie erreicht: aa x aa kann kein A-Allel liefern, also
     * ist Braunfalbe EXAKT 0 und alle schwarzbasierte, cream-freie Masse
     * gehört dem Graufalben.
     *
     * Bewusste Abweichung vom Issue-#76-Entwurf (dort: graa == 1,0): Beide
     * Eltern sind am Extension-Locus nur als E_ bekannt, nach der
     * Modellannahme also mit p(e) = 0,25 je Elternteil - Ee x Ee kann ein
     * ee-Fohlen (Rotfalbe) ergeben. Korrekt sind daher
     * graa = 0,9375 und rodblakk = 0,0625; genau damit ist hier zusätzlich
     * die sonst unbeobachtete Heterozygotie-Annahme p(e) = 0,25 festgenagelt.
     * (graa == 1,0 würde gegen die dokumentierte Modellannahme fehlschlagen.)
     */
    public function testGraaTimesGraaIsolatesAgoutiLocus(): void {
        $r = FjordColor::predictFoal('graa', 'graa');

        $this->assertEqualsWithDelta(0.0, $r['brunblakk'], 1e-9, 'aa x aa kann kein Agouti-Allel liefern - Braunfalbe unmöglich.');
        $this->assertEqualsWithDelta(0.9375, $r['graa'], 1e-9, 'p(schwarzbasiert) = 1 - 0,25*0,25 = 0,9375, davon sicher aa und sicher ohne Cream.');
        $this->assertEqualsWithDelta(0.0625, $r['rodblakk'], 1e-9, 'Heterozygotie-Annahme p(e) = 0,25 bei E_: Ee x Ee -> 0,25*0,25 Rotfalbe.');
        $this->assertEqualsWithDelta(0.0, $r['ulsblakk'], 1e-9, 'nn x nn: kein Cream möglich.');
        $this->assertEqualsWithDelta(0.0, $r['gulblakk'], 1e-9, 'nn x nn: kein Cream möglich.');
    }

    /**
     * Familie 3: Braunfalbe x Braunfalbe mit festen Sollwerten - fängt genau
     * den naheliegenden Dreher, bei dem im Rückgabe-Array die Faktoren von
     * brunblakk und graa (p(A_) <-> p(aa)) vertauscht werden: Beide Zeilen
     * sehen bis auf den letzten Faktor identisch aus, und der bisherige
     * Functional-Fall (beide Werte 0) bliebe dabei grün.
     *
     * Herleitung: p(rot) = 0,25*0,25 = 0,0625; p(aa) = 0,25*0,25 = 0,0625
     * (A_ ∈ {AA, Aa} -> p(a) = 0,25); kein Cream möglich.
     *   brunblakk = 0,9375 * (1 - 0,0625) = 0,87890625
     *   graa      = 0,9375 * 0,0625       = 0,05859375
     *   rodblakk  = 0,0625
     */
    public function testBrunblakkTimesBrunblakkKeepsBrunblakkDominant(): void {
        $r = FjordColor::predictFoal('brunblakk', 'brunblakk');

        $this->assertEqualsWithDelta(0.87890625, $r['brunblakk'], 1e-9);
        $this->assertEqualsWithDelta(0.05859375, $r['graa'], 1e-9);
        $this->assertEqualsWithDelta(0.0625, $r['rodblakk'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $r['ulsblakk'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $r['gulblakk'], 1e-9);

        // Der eigentliche Vertauscher-Fang, unabhängig von den Absolutwerten:
        // Braunfalbe x Braunfalbe ergibt ganz überwiegend Braunfalbe.
        $this->assertGreaterThan(
            $r['graa'],
            $r['brunblakk'],
            'Braunfalbe x Braunfalbe muss überwiegend Braunfalbe ergeben - ein Tausch p(A_) <-> p(aa) dreht die Zuchtempfehlung um.'
        );
    }

    /**
     * Familie 4: Hellfalbe x Braunfalbe isoliert den Cream-Locus. Ein
     * Hellfalbe ist Cream-Träger (Cr ∈ {Cr n, Cr Cr} gleich gewichtet ->
     * p(n) = 0,25), der Braunfalbe sicher nn -> p(kein Cream beim Fohlen)
     * = 0,25 * 1 = 0,25, also 0,75 Cream-Anteil - und der verteilt sich
     * vollständig auf die beiden Cream-Phänotypen Hell- und Gelbfalbe.
     * Fängt die Regression p(n) = 0,5 statt 0,25 (Cream-Anteil fiele auf 0,5).
     */
    public function testCreamCarrierProducesCreamOffspringWithExpectedShare(): void {
        $r = FjordColor::predictFoal('ulsblakk', 'brunblakk');

        $this->assertEqualsWithDelta(
            0.75,
            $r['ulsblakk'] + $r['gulblakk'],
            1e-9,
            'Cream-Träger x nn: p(Cream beim Fohlen) = 1 - 0,25*1 = 0,75.'
        );
        // Gegenprobe: die restlichen 25 % sind die cream-freien Phänotypen.
        $this->assertEqualsWithDelta(0.25, $r['brunblakk'] + $r['graa'] + $r['rodblakk'], 1e-9);
    }

    /**
     * Familie 5: Vater- und Mutterfarbe sind vertauschbar - alle drei Loci
     * vererben symmetrisch, die Reihenfolge der Argumente darf das Ergebnis
     * nicht ändern.
     */
    public function testSymmetry(): void {
        foreach (FjordColor::ORDER as $a) {
            foreach (FjordColor::ORDER as $b) {
                $this->assertSame(
                    FjordColor::predictFoal($a, $b),
                    FjordColor::predictFoal($b, $a),
                    "predictFoal muss symmetrisch sein: {$a} x {$b} != {$b} x {$a}."
                );
            }
        }
    }
}
