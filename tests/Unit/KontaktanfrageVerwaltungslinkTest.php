<?php
// tests/Unit/KontaktanfrageVerwaltungslinkTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\Kontaktanfrage\Nachricht;
use Plugin\Kontaktanfrage\Verwaltungslink;

require_once __DIR__ . '/../../plugins/kontaktanfrage/Plugin.php';

/**
 * Der Verwaltungslink der Team-Mail entsteht nur aus einer vertrauenswürdigen
 * Stamm-URL (Audit M6, D14). Die Team-Mail wird von einem ANONYMEN Formular
 * ausgelöst; ein Link aus dem Host-Header der Anfrage wäre eine echte
 * Verbandsmail mit einem Link auf die Domain des Absenders.
 *
 * Die Quellen spielt der Test wie die Unit-Tests des Kerns per putenv() und
 * $_SERVER durch (App\Security\BaseUrl ist seiteneffektfrei).
 */
class KontaktanfrageVerwaltungslinkTest extends TestCase {

    private const ZIEL = ['id' => 7, 'name' => 'Gestüt Test', 'email' => null];

    private string|false $appUrlVorher;
    private string|false $trustedHostsVorher;
    private ?string $hostVorher;

    protected function setUp(): void {
        $this->appUrlVorher = getenv('APP_URL');
        $this->trustedHostsVorher = getenv('TRUSTED_HOSTS');
        $this->hostVorher = $_SERVER['HTTP_HOST'] ?? null;
        putenv('APP_URL');
        putenv('TRUSTED_HOSTS');
        // Der Angreifer bestimmt den Host-Header.
        $_SERVER['HTTP_HOST'] = 'evil.example';
    }

    protected function tearDown(): void {
        putenv($this->appUrlVorher === false ? 'APP_URL' : 'APP_URL=' . $this->appUrlVorher);
        putenv($this->trustedHostsVorher === false ? 'TRUSTED_HOSTS' : 'TRUSTED_HOSTS=' . $this->trustedHostsVorher);
        if ($this->hostVorher === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->hostVorher;
        }
    }

    private static function nieGerufen(): callable {
        return static function (): string {
            self::fail('Mit App\Security\BaseUrl darf der alte Rückfall (Host-Header) nicht gefragt werden.');
        };
    }

    public function testOhneFesteStammUrlGibtEsKeineBasis(): void {
        $this->assertNull(Verwaltungslink::basis(null, true, self::nieGerufen()));
        $this->assertNull(Verwaltungslink::basis('', true, self::nieGerufen()));
    }

    public function testSettingBaseUrlIstDieBasis(): void {
        $this->assertSame(
            'https://verband.example/',
            Verwaltungslink::basis('https://verband.example', true, self::nieGerufen())
        );
    }

    public function testAppUrlIstDieBasis(): void {
        putenv('APP_URL=https://env.verband.example');
        $this->assertSame('https://env.verband.example/', Verwaltungslink::basis(null, true, self::nieGerufen()));
    }

    public function testHostHeaderZaehltNurMitAllowlist(): void {
        putenv('TRUSTED_HOSTS=verband.example');
        $this->assertNull(
            Verwaltungslink::basis(null, true, self::nieGerufen()),
            'Ein Host außerhalb der Allowlist ist keine Basis.'
        );

        $_SERVER['HTTP_HOST'] = 'verband.example';
        $basis = Verwaltungslink::basis(null, true, self::nieGerufen());
        $this->assertNotNull($basis);
        $this->assertStringEndsWith('://verband.example/', $basis);
    }

    public function testAlterKernBehaeltDasBisherigeVerhalten(): void {
        $this->assertSame(
            'https://alt.example/',
            Verwaltungslink::basis(null, false, static fn(): string => 'https://alt.example/')
        );
    }

    public function testTeamMailMitBasisVerlinktDieVerwaltung(): void {
        $html = Nachricht::anTeam(self::ZIEL, 'Deckanfrage', 'Erika', 'erika@example.org', 'Verband', 'https://verband.example/');
        $this->assertStringContainsString('https://verband.example/plugin/kontaktanfrage/verwaltung', $html);
    }

    public function testTeamMailOhneBasisEnthaeltKeinenAbsolutenLink(): void {
        $html = Nachricht::anTeam(self::ZIEL, 'Deckanfrage', 'Erika', 'erika@example.org', 'Verband', null);

        $this->assertStringNotContainsString('://', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('Kachel „Kontaktanfragen“', $html);
        // Die Anfrage selbst erreicht das Team trotzdem vollständig.
        $this->assertStringContainsString('Gestüt Test', $html);
        $this->assertStringContainsString('erika@example.org', $html);
    }
}
