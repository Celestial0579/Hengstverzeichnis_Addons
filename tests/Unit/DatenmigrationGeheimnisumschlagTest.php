<?php
// tests/Unit/DatenmigrationGeheimnisumschlagTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\Datenmigration\Geheimnisumschlag;

require_once __DIR__ . '/../../plugins/datenmigration/Plugin.php';

/**
 * Der Umschlag für Zugangsdaten beim Umzug auf einen anderen APP_KEY
 * (Audit M25): PBKDF2-SHA256 + AES-256-GCM unter einem Exportpasswort.
 *
 * Alle Fälle laufen mit der Untergrenze von 100 000 Runden statt der 600 000
 * des Betriebs - sonst dauerte jeder Fall eine halbe Sekunde.
 */
class DatenmigrationGeheimnisumschlagTest extends TestCase {

    private const ITER = Geheimnisumschlag::MIN_ITERATIONEN;
    private const PASSWORT = 'richtig-langes-Passwort-1';

    /** @return array{settings: array<string, string>, users_totp: array<int, string>} */
    private static function daten(): array {
        return [
            'settings' => ['smtp_pass' => 'geheim-ä', 'plugin_captcha_hcaptcha_secret' => '0x123'],
            'users_totp' => [3 => 'JBSWY3DPEHPK3PXP', 17 => 'KRSXG5CTMVRXEZLU'],
        ];
    }

    private static function verpackt(): string {
        return Geheimnisumschlag::verpacken(self::daten(), self::PASSWORT, self::ITER);
    }

    /** Ändert ein Feld des Umschlags (nach base64-Dekodierung per $aendern). */
    private static function manipuliert(string $feld, callable $aendern): string {
        $u = json_decode(self::verpackt(), true);
        $u[$feld] = base64_encode($aendern(base64_decode($u[$feld])));
        return (string) json_encode($u);
    }

    public function testRundreise(): void {
        $umschlag = self::verpackt();
        $this->assertStringNotContainsString('geheim', $umschlag);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $umschlag);
        $u = json_decode($umschlag, true);
        $this->assertSame(1, $u['v']);
        $this->assertSame('pbkdf2-sha256', $u['kdf']);
        $this->assertSame(self::ITER, $u['iter']);

        $this->assertSame(self::daten(), Geheimnisumschlag::entpacken($umschlag, self::PASSWORT));
    }

    public function testLeereSammlungUeberlebtDieRundreise(): void {
        $leer = ['settings' => [], 'users_totp' => []];
        $this->assertSame(
            $leer,
            Geheimnisumschlag::entpacken(Geheimnisumschlag::verpacken($leer, self::PASSWORT, self::ITER), self::PASSWORT)
        );
    }

    public function testFalschesPasswortWirft(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Exportpasswort falsch');
        Geheimnisumschlag::entpacken(self::verpackt(), 'falsches-Passwort-123');
    }

    /** Ein gekipptes Bit in Chiffrat, Tag oder IV fällt am GCM-Tag auf. */
    public function testManipulationWirdErkannt(): void {
        $kippen = static function (string $roh): string {
            $roh[0] = chr(ord($roh[0]) ^ 0x01);
            return $roh;
        };
        foreach (['daten', 'tag', 'iv', 'salt'] as $feld) {
            try {
                Geheimnisumschlag::entpacken(self::manipuliert($feld, $kippen), self::PASSWORT);
                $this->fail("Manipulation an {$feld} nicht erkannt");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Exportpasswort falsch oder Geheimnis-Datei beschädigt', $e->getMessage(), $feld);
            }
        }
    }

    public function testFalscheLaengeVonIvTagSaltWirdAbgewiesen(): void {
        foreach (['iv', 'tag', 'salt'] as $feld) {
            try {
                Geheimnisumschlag::entpacken(self::manipuliert($feld, static fn(string $r): string => $r . 'x'), self::PASSWORT);
                $this->fail("Falsche Länge von {$feld} nicht erkannt");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString("beschädigt ({$feld})", $e->getMessage());
            }
        }
    }

    public function testZuKurzesPasswortWirft(): void {
        $this->expectException(\InvalidArgumentException::class);
        Geheimnisumschlag::verpacken(self::daten(), str_repeat('x', Geheimnisumschlag::MIN_PASSWORT - 1), self::ITER);
    }

    /** Zeichen, nicht Bytes: zwölf Umlaute genügen, elf nicht. */
    public function testMindestlaengeZaehltZeichen(): void {
        $this->assertNotSame('', Geheimnisumschlag::verpacken(self::daten(), str_repeat('ä', 12), self::ITER));
        $this->expectException(\InvalidArgumentException::class);
        Geheimnisumschlag::verpacken(self::daten(), str_repeat('ä', 11), self::ITER);
    }

    /** Ein fremdes Archiv darf den Server nicht mit Milliarden Runden beschäftigen. */
    public function testRundenzahlAusserhalbDerGrenzenWirft(): void {
        foreach ([Geheimnisumschlag::MIN_ITERATIONEN - 1, Geheimnisumschlag::MAX_ITERATIONEN + 1, '100000'] as $iter) {
            $u = json_decode(self::verpackt(), true);
            $u['iter'] = $iter;
            try {
                Geheimnisumschlag::entpacken((string) json_encode($u), self::PASSWORT);
                $this->fail('Rundenzahl ' . var_export($iter, true) . ' angenommen');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Rundenzahl', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Geheimnisumschlag::verpacken(self::daten(), self::PASSWORT, 1000);
    }

    public function testUnbekanntesFormatWirft(): void {
        foreach (['kein json', '{"v":2,"kdf":"pbkdf2-sha256"}', '{"v":1,"kdf":"scrypt"}', '[]'] as $json) {
            try {
                Geheimnisumschlag::entpacken($json, self::PASSWORT);
                $this->fail("Format angenommen: {$json}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('unbekanntes Format', $e->getMessage());
            }
        }
    }

    /**
     * Der Aufbau wird geprüft, bevor der Import unter Wartungsmodus damit
     * arbeitet - sonst ein TypeError mitten im Einspielen. Geprüft auf beiden
     * Seiten: verpacken() lehnt ab, und ein von Hand verschlüsselter Inhalt
     * mit falschem Aufbau wird beim Entpacken abgewiesen.
     */
    public function testFalscherAufbauWirdAbgewiesen(): void {
        $falsch = [
            'Einstellung als Array' => ['settings' => ['smtp_pass' => ['x']], 'users_totp' => []],
            'Schlüssel über 50 Zeichen' => ['settings' => [str_repeat('a', 51) => 'x'], 'users_totp' => []],
            'TOTP-Schlüssel kein Konto' => ['settings' => [], 'users_totp' => ['admin' => 'x']],
            'TOTP-Wert keine Zeichenkette' => ['settings' => [], 'users_totp' => [3 => 42]],
            'settings fehlt' => ['users_totp' => []],
        ];
        foreach ($falsch as $fall => $daten) {
            try {
                Geheimnisumschlag::verpacken($daten, self::PASSWORT, self::ITER);
                $this->fail("verpacken: {$fall} angenommen");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('ungültigen Aufbau', $e->getMessage(), $fall);
            }
            try {
                Geheimnisumschlag::entpacken(self::vonHandVerpackt($daten), self::PASSWORT);
                $this->fail("entpacken: {$fall} angenommen");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('ungültigen Aufbau', $e->getMessage(), $fall);
            }
        }
    }

    /** Verschlüsselt beliebigen Inhalt im Format des Umschlags - an verpacken() vorbei. */
    private static function vonHandVerpackt(mixed $daten): string {
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $schluessel = hash_pbkdf2('sha256', self::PASSWORT, $salt, self::ITER, 32, true);
        $tag = '';
        $chiffrat = openssl_encrypt((string) json_encode($daten), 'aes-256-gcm', $schluessel, OPENSSL_RAW_DATA, $iv, $tag,
            'datenmigration-geheimnisse-v1', 16);
        return (string) json_encode([
            'v' => 1, 'kdf' => 'pbkdf2-sha256', 'iter' => self::ITER,
            'salt' => base64_encode($salt), 'iv' => base64_encode($iv),
            'tag' => base64_encode($tag), 'daten' => base64_encode((string) $chiffrat),
        ]);
    }

    public function testZweiVerpackungenUnterscheidenSich(): void {
        $a = json_decode(self::verpackt(), true);
        $b = json_decode(self::verpackt(), true);
        $this->assertNotSame($a['salt'], $b['salt']);
        $this->assertNotSame($a['iv'], $b['iv']);
        $this->assertNotSame($a['daten'], $b['daten']);
    }
}
