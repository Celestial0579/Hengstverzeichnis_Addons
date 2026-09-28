<?php
// tests/Unit/CaptchaAltchaAufgabeTest.php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Plugin\CaptchaAltcha\Aufgabe;

/**
 * Die Sitzungsplätze des ALTCHA-Nachweises je Formular-Kontext (Audit N3).
 *
 * Der Funktionstest (CaptchaAnbieterPluginTest) zeigt, dass zwei Formulare
 * auf einer Seite unabhängig durchgehen. Was er über HTTP nur mit
 * unverhältnismäßigem Aufwand zeigen könnte, steht hier: die Obergrenze der
 * offenen Aufgaben, das Aufräumen abgelaufener und der einmalige Rückgriff
 * auf den alten gemeinsamen Platz.
 *
 * Jeder Test läuft in einem eigenen Prozess mit einer Sitzung im Speicher
 * (kein Cookie, keine Datei) - Aufgabe::stellen() verlangt eine aktive
 * Sitzung, und eine echte Sitzung liesse sich im PHPUnit-Hauptprozess nach
 * der ersten Ausgabe nicht mehr starten.
 */
#[RunTestsInSeparateProcesses]
class CaptchaAltchaAufgabeTest extends TestCase {

    private const SCHLUESSEL = 'plugin_captcha_altcha_challenges';

    protected function setUp(): void {
        require_once __DIR__ . '/../../plugins/captcha-altcha/Plugin.php';

        session_set_save_handler(new class implements \SessionHandlerInterface {
            public function open(string $path, string $name): bool { return true; }
            public function close(): bool { return true; }
            public function read(string $id): string { return ''; }
            public function write(string $id, string $data): bool { return true; }
            public function destroy(string $id): bool { return true; }
            public function gc(int $max_lifetime): int { return 0; }
        }, true);
        $this->assertTrue(session_start(['use_cookies' => 0, 'cache_limiter' => '']));
        $_SESSION = [];
    }

    public function testJederKontextHatSeineEigeneAufgabe(): void {
        $deck = Aufgabe::stellen(100, 'deckanfrage');
        $verkauf = Aufgabe::stellen(100, 'verkaufsboerse');
        $this->assertNotNull($deck);
        $this->assertNotNull($verkauf);

        // Erneutes Stellen desselben Kontexts ersetzt nur dessen Aufgabe.
        $deckNeu = Aufgabe::stellen(100, 'deckanfrage');
        $this->assertNotNull($deckNeu);

        $abgeholt = Aufgabe::abholen('verkaufsboerse');
        $this->assertNotNull($abgeholt);
        $this->assertSame($verkauf['challenge'], $abgeholt['challenge']);
        $this->assertNull(Aufgabe::abholen('verkaufsboerse'), 'Einmalverwendung: ein zweites Abholen findet nichts');

        $abgeholt = Aufgabe::abholen('deckanfrage');
        $this->assertNotNull($abgeholt);
        $this->assertSame($deckNeu['challenge'], $abgeholt['challenge']);
    }

    public function testHoechstensZehnAufgabenDieAeltesteFaelltWeg(): void {
        $this->assertSame(10, Aufgabe::MAX_KONTEXTE);
        for ($i = 0; $i <= Aufgabe::MAX_KONTEXTE; $i++) {
            Aufgabe::stellen(10, 'formular' . $i);
        }

        $this->assertCount(Aufgabe::MAX_KONTEXTE, $_SESSION[self::SCHLUESSEL]);
        $this->assertNull(Aufgabe::abholen('formular0'), 'Die am längsten nicht mehr gestellte fällt zuerst weg');
        $this->assertNotNull(Aufgabe::abholen('formular1'));
        $this->assertNotNull(Aufgabe::abholen('formular' . Aufgabe::MAX_KONTEXTE));
    }

    public function testNeuGestellteAufgabeRuecktAnsEnde(): void {
        for ($i = 0; $i < Aufgabe::MAX_KONTEXTE; $i++) {
            Aufgabe::stellen(10, 'formular' . $i);
        }
        // formular0 wird erneut ausgegeben und ist damit die jüngste.
        Aufgabe::stellen(10, 'formular0');
        Aufgabe::stellen(10, 'neu');

        $this->assertNotNull(Aufgabe::abholen('formular0'));
        $this->assertNull(Aufgabe::abholen('formular1'));
    }

    public function testAbgelaufeneAufgabenRaeumtDasNaechsteStellenAb(): void {
        Aufgabe::stellen(10, 'alt');
        $_SESSION[self::SCHLUESSEL]['alt']['gestellt'] = time() - \App\Security\Captcha::TTL_SECONDS - 1;
        Aufgabe::stellen(10, 'frisch');

        $this->assertArrayNotHasKey('alt', $_SESSION[self::SCHLUESSEL]);
        $this->assertArrayHasKey('frisch', $_SESSION[self::SCHLUESSEL]);
    }

    public function testAlterGemeinsamerPlatzGiltNochEinmal(): void {
        $_SESSION['plugin_captcha_altcha_challenge'] = ['salt' => 'x', 'challenge' => 'y', 'max' => 1, 'gestellt' => time()];
        Aufgabe::stellen(10, 'verkaufsboerse');

        // Ein Kontext mit eigener Aufgabe nimmt seine eigene.
        $eigene = Aufgabe::abholen('verkaufsboerse');
        $this->assertNotNull($eigene);
        $this->assertNotSame('y', $eigene['challenge']);

        // Ein Kontext ohne eigene Aufgabe nimmt die aus der Zeit vor dem
        // Update - einmal.
        $alt = Aufgabe::abholen('deckanfrage');
        $this->assertNotNull($alt);
        $this->assertSame('y', $alt['challenge']);
        $this->assertNull(Aufgabe::abholen('deckanfrage'));
    }
}
