<?php
// tests/Unit/MitgliederKontenCiviApiTest.php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\MitgliederKonten\CiviApi;
use Plugin\MitgliederKonten\CiviApiFehler;

// CiviApi.php hat keine Kernabhaengigkeit. Geladen wird die REPO-Fassung,
// nicht die vendorierte Kopie - sonst zwei Pfade, zwei Ladevorgaenge, und
// "Cannot redeclare" im gemeinsamen Lauf aller Suiten (#160).
require_once __DIR__ . '/../../plugins/mitglieder-konten/CiviApi.php';

/**
 * Der CiviCRM-Zugang des Addons `mitglieder-konten` als reine Funktionen -
 * ohne Netz, ohne Datenbank.
 *
 * M5: Wohin darf der API-Schluessel gehen? Nur an https-Ziele, deren JEDE
 * aufgeloeste Adresse oeffentlich ist - auch dann, wenn sich eine interne
 * IPv4 in einer IPv6-Form versteckt. Die Aufloesung kommt als Parameter
 * herein, damit sich jeder Fall ohne DNS festnageln laesst.
 *
 * N30: statusNachId() fragt gezielt per ID und OHNE Typfilter, in Bloecken,
 * und unterscheidet "laeuft nicht" von "unklar".
 */
class MitgliederKontenCiviApiTest extends TestCase {

    /** @return callable(string): array<int, string> */
    private static function resolver(array $tabelle): callable {
        return static fn(string $host): array => $tabelle[$host] ?? [];
    }

    /** @return array<string, array{0: string, 1: array<string, array<int, string>>}> */
    public static function abgelehnteZiele(): array {
        $oeffentlich = ['civi.example.org' => ['93.184.216.34']];

        return [
            'http statt https' => ['http://civi.example.org', $oeffentlich],
            'Loopback literal' => ['https://127.0.0.1', []],
            'privates Netz mit Port' => ['https://10.0.0.5:6379', []],
            'IPv6 Loopback' => ['https://[::1]', []],
            'Cloud-Metadaten' => ['https://169.254.169.254', []],
            'IPv4-mapped Loopback' => ['https://[::ffff:127.0.0.1]', []],
            'NAT64 auf privates Netz' => ['https://[64:ff9b::a00:5]', []],
            '6to4 auf privates Netz' => ['https://[2002:a00:5::]', []],
            'CGNAT' => ['https://100.64.0.1', []],
            'Zugangsdaten in der URL' => ['https://user:pw@civi.example.org', $oeffentlich],
            // Fuer filter_var keine IP - erst die Aufloesung verraet sie.
            'numerischer Host' => ['https://2130706433', ['2130706433' => ['127.0.0.1']]],
            'oktaler Host' => ['https://0177.0.0.1', ['0177.0.0.1' => ['127.0.0.1']]],
            'Host loest privat auf' => ['https://intern.example.org', ['intern.example.org' => ['192.168.1.10']]],
            'gemischte Antwort' => ['https://gemischt.example.org', ['gemischt.example.org' => ['93.184.216.34', '10.1.2.3']]],
            'gemischte Antwort, privat zuerst' => ['https://gemischt.example.org', ['gemischt.example.org' => ['10.1.2.3', '93.184.216.34']]],
            'leere Aufloesung' => ['https://gibtsnicht.example.org', []],
            'Query' => ['https://civi.example.org/?x=1', $oeffentlich],
        ];
    }

    #[DataProvider('abgelehnteZiele')]
    public function testUnzulaessigeZieleWerdenAbgelehnt(string $basis, array $dns): void {
        try {
            CiviApi::zielPruefen($basis, self::resolver($dns));
            $this->fail("'{$basis}' haette abgelehnt werden muessen.");
        } catch (CiviApiFehler $e) {
            // Nach aussen immer dieselbe Meldung - kein Hinweis auf das interne Netz.
            $this->assertSame(CiviApi::FEHLER_NETZ, $e->getMessage());
            $this->assertNotSame('', $e->detail());
        }
    }

    public function testEinOeffentlichesZielWirdAngenommenUndFestgenagelt(): void {
        $ziel = CiviApi::zielPruefen(
            'https://Civi.Example.org./crm',
            self::resolver(['civi.example.org' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']])
        );

        $this->assertSame('civi.example.org', $ziel['host']);
        $this->assertSame(443, $ziel['port']);
        $this->assertSame('93.184.216.34', $ziel['ips'][0]);
    }

    public function testEinLiteralesOeffentlichesZielBrauchtKeinDns(): void {
        $gefragt = false;
        $ziel = CiviApi::zielPruefen('https://93.184.216.34:8443', function () use (&$gefragt): array {
            $gefragt = true;
            return [];
        });

        $this->assertFalse($gefragt);
        $this->assertSame(['93.184.216.34'], $ziel['ips']);
        $this->assertSame(8443, $ziel['port']);
    }

    /**
     * Die Ausnahme fuer ein CiviCRM im eigenen Netz gilt nur fuer den
     * BENANNTEN Host - nicht fuer jede Adresse, auf die er aufloest.
     */
    public function testDieAusnahmeGiltNurFuerDenBenanntenHost(): void {
        $dns = self::resolver([
            'civi.intern' => ['10.0.0.5'],
            'anderer.intern' => ['10.0.0.5'],
        ]);

        $ziel = CiviApi::zielPruefen('https://civi.intern', $dns, ['civi.intern']);
        $this->assertSame(['10.0.0.5'], $ziel['ips']);

        $this->expectException(CiviApiFehler::class);
        CiviApi::zielPruefen('https://anderer.intern', $dns, ['civi.intern']);
    }

    public function testDieAusnahmeHebtDieHttpsPflichtNichtAuf(): void {
        $this->expectException(CiviApiFehler::class);
        CiviApi::zielPruefen('http://civi.intern', self::resolver(['civi.intern' => ['10.0.0.5']]), ['civi.intern']);
    }

    public function testDieAusnahmeKommtAusDerUmgebung(): void {
        $vorher = getenv(CiviApi::ENV_INTERNE_HOSTS);
        try {
            putenv(CiviApi::ENV_INTERNE_HOSTS . '= Civi.Intern. , zweiter.intern,');
            $this->assertSame(['civi.intern', 'zweiter.intern'], CiviApi::interneHosts());
            putenv(CiviApi::ENV_INTERNE_HOSTS);
            $this->assertSame([], CiviApi::interneHosts());
        } finally {
            putenv($vorher === false ? CiviApi::ENV_INTERNE_HOSTS : CiviApi::ENV_INTERNE_HOSTS . '=' . $vorher);
        }
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function ips(): array {
        return [
            'oeffentlich v4' => ['93.184.216.34', true],
            'oeffentlich v6' => ['2606:4700::1111', true],
            'NAT64 auf oeffentlich' => ['64:ff9b::5db8:d822', true],
            'Loopback' => ['127.0.0.1', false],
            'privat 172.16' => ['172.16.5.4', false],
            'Link-Local' => ['169.254.169.254', false],
            'CGNAT' => ['100.64.0.1', false],
            'unbestimmt' => ['0.0.0.0', false],
            'Multicast v4' => ['224.0.0.1', false],
            'Multicast v6' => ['ff02::1', false],
            'ULA' => ['fc00::1', false],
            'Link-Local v6' => ['fe80::1', false],
            'IPv4-mapped privat' => ['::ffff:192.168.0.1', false],
            'NAT64 privat' => ['64:ff9b::c0a8:1', false],
            'NAT64 lokal' => ['64:ff9b:1::5db8:d822', false],
            '6to4 Loopback' => ['2002:7f00:1::', false],
            'keine IP' => ['civi.example.org', false],
        ];
    }

    #[DataProvider('ips')]
    public function testIpIstOeffentlich(string $ip, bool $erwartet): void {
        $this->assertSame($erwartet, CiviApi::ipIstOeffentlich($ip));
    }

    /**
     * Die Oberflaeche sieht nur die generische Meldung - ein curl-Text wie
     * "Connection refused" waere eine Auskunft ueber das interne Netz.
     */
    public function testDieMeldungIstGenerischDasDetailNicht(): void {
        $e = new CiviApiFehler(CiviApi::FEHLER_NETZ, 'Keine Verbindung zu civi.example.org: Connection refused');

        $this->assertStringNotContainsString('Connection refused', $e->getMessage());
        $this->assertStringContainsString('Connection refused', $e->detail());
    }

    // ---- statusNachId (N30) --------------------------------------------

    /**
     * @param array<int, bool|null|string> $status id => Status; 'ohne' = Zeile ohne Statusfeld
     * @param \ArrayObject<int, array<string, mixed>> $protokoll
     */
    private function attrappe(array $status, \ArrayObject $protokoll): CiviApi {
        return new class ('https://civi.example.org', 'schluessel', $status, $protokoll) extends CiviApi {
            /** @param \ArrayObject<int, array<string, mixed>> $protokoll */
            public function __construct(string $basis, string $key, private readonly array $status, private readonly \ArrayObject $protokoll) {
                parent::__construct($basis, $key);
            }

            protected function sende(string $entitaet, string $aktion, array $params): array {
                $this->protokoll[] = $params;
                if ((int)($params['offset'] ?? 0) > 0) {
                    return ['values' => []];
                }
                $werte = [];
                foreach ($params['where'][0][2] as $id) {
                    if (!array_key_exists($id, $this->status)) {
                        continue;
                    }
                    $werte[] = $this->status[$id] === 'ohne'
                        ? ['id' => $id]
                        : ['id' => $id, 'status_id.is_current_member' => $this->status[$id]];
                }

                return ['values' => $werte];
            }
        };
    }

    public function testStatusNachIdFragtGezieltUndOhneTypfilter(): void {
        $protokoll = new \ArrayObject();
        $client = $this->attrappe([1 => true, 2 => false, 3 => 'ohne', 4 => null], $protokoll);

        $status = $client->statusNachId([1, 2, 3, 4, 5, 2, -1, 0]);

        $this->assertSame([1 => true, 2 => false, 3 => null, 4 => null], $status, 'Fehlende IDs fehlen, ohne Statusfeld = unklar.');
        $this->assertCount(1, $protokoll);
        $this->assertSame([['id', 'IN', [1, 2, 3, 4, 5]]], $protokoll[0]['where']);
        $this->assertSame(['id', 'status_id.is_current_member'], $protokoll[0]['select']);
        $this->assertStringNotContainsString('membership_type_id', json_encode($protokoll[0], JSON_THROW_ON_ERROR));
    }

    public function testStatusNachIdFragtInBloecken(): void {
        $protokoll = new \ArrayObject();
        $ids = range(1, 250);
        $client = $this->attrappe(array_fill_keys($ids, true), $protokoll);

        $status = $client->statusNachId($ids);

        $this->assertCount(250, $status);
        $this->assertCount(2, $protokoll, 'Zwei Bloecke zu hoechstens ' . CiviApi::ID_BLOCK . '.');
        $this->assertCount(CiviApi::ID_BLOCK, $protokoll[0]['where'][0][2]);
        $this->assertCount(50, $protokoll[1]['where'][0][2]);
    }

    public function testStatusWerteWerdenStrengAbgebildet(): void {
        $protokoll = new \ArrayObject();
        $client = $this->attrappe([1 => '1', 2 => 0, 3 => 'ja', 4 => 'false'], $protokoll);

        $this->assertSame([1 => true, 2 => false, 3 => null, 4 => false], $client->statusNachId([1, 2, 3, 4]));
    }

    public function testFremdeIdsInDerAntwortZaehlenNicht(): void {
        $client = new class ('https://civi.example.org', 'k') extends CiviApi {
            protected function sende(string $entitaet, string $aktion, array $params): array {
                return ['values' => [['id' => 1, 'status_id.is_current_member' => true], ['id' => 99, 'status_id.is_current_member' => false]]];
            }
        };

        $this->assertSame([1 => true], $client->statusNachId([1]));
    }
}
