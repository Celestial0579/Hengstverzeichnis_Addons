<?php
// tests/Unit/DatenmigrationTlsNachbauTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\Datenmigration\TlsNachbau;

require_once __DIR__ . '/../../plugins/datenmigration/Plugin.php';

/**
 * TLS im Nachbau der Importverbindung für Kerne ohne
 * Database::neueVerbindung() (GHSA-32x3-72jg-f2jc).
 *
 * pdo_mysql fordert TLS nur an, wenn mindestens eine CA-Datei oder ein
 * CA-Verzeichnis gesetzt ist. Der Nachbau setzte ohne DB_SSL_CA nur den
 * Prüfschalter und verband deshalb im Klartext.
 */
class DatenmigrationTlsNachbauTest extends TestCase {

    private string $verzeichnis;
    private string $datei;

    protected function setUp(): void {
        if (!class_exists(\Pdo\Mysql::class)) {
            $this->markTestSkipped('pdo_mysql fehlt');
        }
        $this->verzeichnis = sys_get_temp_dir() . '/dm-tls-' . bin2hex(random_bytes(4));
        mkdir($this->verzeichnis);
        $this->datei = $this->verzeichnis . '/ca.pem';
        file_put_contents($this->datei, "-----BEGIN CERTIFICATE-----\n");
    }

    protected function tearDown(): void {
        if (isset($this->datei) && is_file($this->datei)) {
            unlink($this->datei);
        }
        if (isset($this->verzeichnis) && is_dir($this->verzeichnis)) {
            rmdir($this->verzeichnis);
        }
    }

    private static function keineUmgebung(): callable {
        return static fn(string $name): string => '';
    }

    public function testOhneDbSslGibtEsKeineOptionen(): void {
        $this->assertSame([], TlsNachbau::optionen(false, '', true, ['default_cert_file' => $this->datei], self::keineUmgebung()));
    }

    public function testEigeneCaDateiGehtVor(): void {
        $optionen = TlsNachbau::optionen(true, '/etc/eigene-ca.pem', true, ['default_cert_file' => $this->datei], self::keineUmgebung());
        $this->assertSame('/etc/eigene-ca.pem', $optionen[\Pdo\Mysql::ATTR_SSL_CA]);
        $this->assertTrue($optionen[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    /** Der eigentliche Fehler: ohne DB_SSL_CA muss trotzdem eine CA-Option stehen, sonst kein TLS. */
    public function testOhneCaDateiGiltDerZertifikatsspeicherDesSystems(): void {
        $optionen = TlsNachbau::optionen(true, '', true, ['default_cert_file' => $this->datei], self::keineUmgebung());
        $this->assertSame($this->datei, $optionen[\Pdo\Mysql::ATTR_SSL_CA]);
        $this->assertTrue($optionen[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function testAuchOhnePruefungWirdTlsAngefordert(): void {
        $optionen = TlsNachbau::optionen(true, '', false, ['default_cert_dir' => $this->verzeichnis], self::keineUmgebung());
        $this->assertSame($this->verzeichnis, $optionen[\Pdo\Mysql::ATTR_SSL_CAPATH]);
        $this->assertFalse($optionen[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function testReihenfolgeIniVorUmgebungVorVoreinstellung(): void {
        $umgebung = fn(string $name): string => $name === 'SSL_CERT_DIR' ? $this->verzeichnis : '';
        $orte = ['ini_cafile' => $this->datei, 'default_cert_dir_env' => 'SSL_CERT_DIR', 'default_cert_file' => '/fehlt/nicht-da.pem'];
        $this->assertSame([\Pdo\Mysql::ATTR_SSL_CA => $this->datei], TlsNachbau::zertifikatsspeicher($orte, $umgebung));

        unset($orte['ini_cafile']);
        $this->assertSame([\Pdo\Mysql::ATTR_SSL_CAPATH => $this->verzeichnis], TlsNachbau::zertifikatsspeicher($orte, $umgebung));
    }

    public function testNichtVorhandenePfadeZaehlenNicht(): void {
        $orte = ['ini_cafile' => '/fehlt/a.pem', 'ini_capath' => '/fehlt/dir', 'default_cert_file' => '/fehlt/b.pem'];
        $this->assertSame([], TlsNachbau::zertifikatsspeicher($orte, self::keineUmgebung()));
    }

    /** Fail-closed: lieber kein Import als ein Import im Klartext. */
    public function testOhneJedenZertifikatsspeicherBrichtDerAufbauAb(): void {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('DB_SSL_CA');
        TlsNachbau::optionen(true, '', true, [], self::keineUmgebung());
    }
}
