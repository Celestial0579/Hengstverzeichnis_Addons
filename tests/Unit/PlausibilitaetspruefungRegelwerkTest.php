<?php
// tests/Unit/PlausibilitaetspruefungRegelwerkTest.php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugin\Plausibilitaetspruefung\Regel;
use Plugin\Plausibilitaetspruefung\Regelwerk;

/**
 * Der Regelsatz von plugins/plausibilitaetspruefung (Addons#114) ohne
 * Datenbank und ohne laufende Instanz.
 *
 * Zwei Dinge nagelt dieser Test fest, die sich sonst unbemerkt verschieben:
 *
 * 1. **Welche Regel blockiert.** Die Entscheidung "blockierend ist nur, was
 *    nicht wahr sein kann" ist der eigentliche Inhalt des Issues. Wer sie
 *    ändert, ändert damit, welche Datensätze vom Netz gehen - dieser Test
 *    zwingt ihn, die Änderung auszusprechen.
 * 2. **Dass Bericht und Veto dieselbe Abfrage benutzen.** Jede Regel hat
 *    genau eine SQL mit dem Platzhalter {WO}. Zwei Fassungen derselben Regel
 *    driften auseinander, und zwar unbemerkt: Der Bericht zeigt einen Fall,
 *    den das Veto nicht kennt, und beide sehen für sich plausibel aus.
 */
class PlausibilitaetspruefungRegelwerkTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once __DIR__ . '/../../plugins/plausibilitaetspruefung/Plugin.php';
    }

    /**
     * Ein vollständig ausgefüllter Pferde-Datensatz, der JEDE Regel verletzen
     * könnte - so, wie ihn der Kern an `horse.publish_blockers` übergibt
     * (SELECT * FROM horses).
     *
     * @return array<string, mixed>
     */
    private function pferdMitAllenAngaben(): array {
        return [
            'id' => 42,
            'name' => "Bl\u{FFFD}me",
            'description' => "Beschreibung mit \u{FFFD}",
            'ueln' => null,
            'foreign_ueln' => null,
            'sire_id' => 7,
            'dam_id' => 7,
            'birth_year' => 2010,
            'death_year' => 2005,
            'is_deceased' => 1,
            'sex' => null,
        ];
    }

    public function testJedeRegelHatEineEindeutigeGueltigeKennung(): void {
        $gesehen = [];
        foreach (Regelwerk::alle() as $regel) {
            $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*$/', $regel->id);
            $this->assertArrayNotHasKey($regel->id, $gesehen, "Regelkennung '{$regel->id}' kommt doppelt vor.");
            $gesehen[$regel->id] = true;

            $this->assertNotSame('', trim($regel->titel), "Regel '{$regel->id}' ohne Titel.");
            // Die Klartextbegründung ist kein Beiwerk: Sie steht im Bericht und
            // im Veto-Text und ist das Einzige, woran ein Bearbeiter erkennt,
            // WARUM sein Datensatz nicht veröffentlicht wird.
            $this->assertGreaterThan(
                40,
                mb_strlen($regel->begruendung),
                "Regel '{$regel->id}': die Begründung muss den Fall erklären, nicht bloß benennen."
            );
        }
        $this->assertNotSame([], $gesehen, 'Der Regelsatz darf nicht leer sein.');
    }

    /**
     * Die Entscheidung aus #114: blockierend ist ausschließlich, was nicht
     * wahr sein KANN. Alles Übrige - fehlende Lebensnummer, fehlendes
     * Geschlecht, offener Zeitraum bei einem verstorbenen Pferd,
     * Zeichenschaden - ist ein Hinweis. "Gestorbenes Pferd mit offenem
     * Halterzeitraum" trifft im Bestand 35 Datensätze und wäre als Blocker
     * eine Zumutung.
     */
    public function testNurPhysikalischUnmoeglichesBlockiert(): void {
        $blockend = [];
        foreach (Regelwerk::alle() as $regel) {
            if ($regel->blockiert()) {
                $blockend[] = $regel->id;
            }
        }
        sort($blockend);

        $this->assertSame(
            ['eltern-juenger', 'tod-vor-geburt', 'vater-gleich-mutter', 'zeitraum-nach-tod'],
            $blockend,
            'Wer diese Liste ändert, ändert, welche Datensätze vom Netz gehen - siehe Regelwerk-Docblock.'
        );
    }

    public function testHinweisRegelnBleibenHinweise(): void {
        foreach (['gestorben-offener-zeitraum', 'ohne-lebensnummer', 'ohne-geschlecht', 'zeichenschaden'] as $id) {
            $regel = Regelwerk::nach($id);
            $this->assertInstanceOf(Regel::class, $regel, "Regel '{$id}' fehlt.");
            $this->assertFalse($regel->blockiert(), "Regel '{$id}' darf die Veröffentlichung nicht blockieren.");
        }
    }

    /**
     * Eine Abfrage je Regel, mit dem Platzhalter - und mit genau den vier
     * Spalten, auf die sich Bericht, Kachel und Veto verlassen.
     */
    public function testJedeRegelHatEineAbfrageMitPlatzhalterUndFestemSpaltensatz(): void {
        foreach (Regelwerk::alle() as $regel) {
            $this->assertStringContainsString('{WO}', $regel->sql, "Regel '{$regel->id}' ohne {WO}-Platzhalter.");
            foreach (['AS horse_id', 'AS name', 'AS oeffentlich', 'AS detail'] as $spalte) {
                $this->assertStringContainsString(
                    $spalte,
                    $regel->sql,
                    "Regel '{$regel->id}': Spalte '{$spalte}' fehlt im Abfrageergebnis."
                );
            }
        }
    }

    public function testAbfrageSetztDenPlatzhalterUndTraegtDieRegelkennung(): void {
        $regel = Regelwerk::nach('vater-gleich-mutter');
        $this->assertInstanceOf(Regel::class, $regel);

        $bestand = Regelwerk::abfrage($regel, '1=1');
        $einPferd = Regelwerk::abfrage($regel, 'h.id = ?');

        $this->assertStringNotContainsString('{WO}', $bestand);
        $this->assertStringNotContainsString('{WO}', $einPferd);
        $this->assertStringContainsString('1=1', $bestand);
        $this->assertStringContainsString('h.id = ?', $einPferd);
        $this->assertStringContainsString("'vater-gleich-mutter' AS regel", $bestand);

        // Beide Fassungen stammen aus derselben Vorlage - das ist der Punkt.
        $this->assertSame(
            str_replace('h.id = ?', '1=1', $einPferd),
            $bestand,
            'Bericht und Veto müssen dieselbe Abfrage benutzen, nur mit anderer Einschränkung.'
        );
    }

    /**
     * Die Vorbedingung spart Abfragen - aber nur dann gefahrlos, wenn sie
     * einen Datensatz, der die Regel verletzen KÖNNTE, nie aussortiert. Ein
     * Pferd mit allen relevanten Angaben muss deshalb jede Regel passieren.
     */
    public function testVorbedingungLaesstEinenVollstaendigenDatensatzDurchAlleRegeln(): void {
        $pferd = $this->pferdMitAllenAngaben();
        foreach (Regelwerk::alle() as $regel) {
            $vorbedingung = $regel->vorbedingung;
            $this->assertTrue(
                $vorbedingung($pferd),
                "Regel '{$regel->id}': Vorbedingung sortiert einen Datensatz aus, der die Regel verletzen könnte - "
                . 'das Veto übersähe dann, was der Bericht zeigt.'
            );
        }
    }

    /**
     * Und umgekehrt: Beim leeren Datensatz darf keine einzige Regel eine
     * Abfrage auslösen. Das ist der Normalfall beim Speichern - null Regeln,
     * null Abfragen.
     */
    public function testVorbedingungSpartBeimLeerenDatensatzJedeAbfrage(): void {
        $leer = [
            'id' => 1, 'name' => 'Ohne Angaben', 'description' => null,
            'ueln' => 'DE123', 'foreign_ueln' => null,
            'sire_id' => null, 'dam_id' => null,
            'birth_year' => null, 'death_year' => null, 'is_deceased' => 0,
            'sex' => 'stallion',
        ];

        foreach (Regelwerk::alle() as $regel) {
            $vorbedingung = $regel->vorbedingung;
            $this->assertFalse(
                $vorbedingung($leer),
                "Regel '{$regel->id}': Vorbedingung greift bei einem Datensatz, der die Regel gar nicht "
                . 'verletzen kann - das kostet bei jedem Speichern eine Abfrage.'
            );
        }
    }

    /**
     * Einzelfälle, an denen die Vorbedingung tatsächlich etwas entscheidet.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}>
     */
    public static function vorbedingungFaelle(): array {
        $basis = [
            'id' => 1, 'name' => 'X', 'description' => null, 'ueln' => 'DE1', 'foreign_ueln' => null,
            'sire_id' => null, 'dam_id' => null, 'birth_year' => null, 'death_year' => null,
            'is_deceased' => 0, 'sex' => 'mare',
        ];

        return [
            'Zeitraum nach Tod ohne Todesjahr' => ['zeitraum-nach-tod', $basis, false],
            'Zeitraum nach Tod mit Todesjahr' => ['zeitraum-nach-tod', ['death_year' => 2001] + $basis, true],
            'Eltern juenger ohne Verknuepfung' => ['eltern-juenger', ['birth_year' => 2010] + $basis, false],
            'Eltern juenger mit Vater' => ['eltern-juenger', ['birth_year' => 2010, 'sire_id' => 5] + $basis, true],
            'Vater gleich Mutter nur ein Elternteil' => ['vater-gleich-mutter', ['sire_id' => 5] + $basis, false],
            'Vater gleich Mutter beide gesetzt' => ['vater-gleich-mutter', ['sire_id' => 5, 'dam_id' => 5] + $basis, true],
            'Lebensnummer nur auslaendisch' => ['ohne-lebensnummer', ['ueln' => '', 'foreign_ueln' => 'NO7'] + $basis, false],
            'Lebensnummer gar keine' => ['ohne-lebensnummer', ['ueln' => '  ', 'foreign_ueln' => null] + $basis, true],
            'Zeichenschaden nur Beschreibung' => ['zeichenschaden', ['description' => "a\u{FFFD}b"] + $basis, true],
            'Zeichenschaden sauber' => ['zeichenschaden', ['description' => 'völlig in Ordnung'] + $basis, false],
        ];
    }

    /**
     * @param array<string, mixed> $pferd
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('vorbedingungFaelle')]
    public function testVorbedingungEntscheidetImEinzelfall(string $regelId, array $pferd, bool $erwartet): void {
        $regel = Regelwerk::nach($regelId);
        $this->assertInstanceOf(Regel::class, $regel);

        $vorbedingung = $regel->vorbedingung;
        $this->assertSame($erwartet, $vorbedingung($pferd));
    }

    public function testUnbekannteRegelLiefertNull(): void {
        $this->assertNull(Regelwerk::nach('gibt-es-nicht'));
        $this->assertNull(Regelwerk::nach(''));
    }

    /**
     * Die Kennung landet als Literal in der SQL. Sie kommt aus dem Code des
     * Addons, nicht von aussen - aber "erweiterbar, ohne den Kern anzufassen"
     * heisst, dass irgendwann jemand eine Regel ergänzt.
     */
    public function testRegelWeistUngueltigeKennungAb(): void {
        $this->expectException(InvalidArgumentException::class);
        new Regel(
            "boese'; DROP TABLE horses; --",
            Regelwerk::SCHWERE_HINWEIS,
            'Titel',
            'Eine Begründung, die lang genug ist, um den Fall wirklich zu erklären.',
            'SELECT 1 AS horse_id WHERE {WO}',
            static fn(array $h): bool => true
        );
    }

    public function testRegelWeistUnbekannteSchwereAb(): void {
        $this->expectException(InvalidArgumentException::class);
        new Regel(
            'irgendwas',
            'katastrophe',
            'Titel',
            'Eine Begründung, die lang genug ist, um den Fall wirklich zu erklären.',
            'SELECT 1 AS horse_id WHERE {WO}',
            static fn(array $h): bool => true
        );
    }

    public function testMitSchwereTrenntDieBeidenGruppen(): void {
        $blocker = Regelwerk::mitSchwere(Regelwerk::SCHWERE_BLOCKER);
        $hinweise = Regelwerk::mitSchwere(Regelwerk::SCHWERE_HINWEIS);

        $this->assertCount(4, $blocker);
        $this->assertCount(count(Regelwerk::alle()) - 4, $hinweise);
        foreach ($blocker as $regel) {
            $this->assertTrue($regel->blockiert());
        }
        foreach ($hinweise as $regel) {
            $this->assertFalse($regel->blockiert());
        }
    }
}
