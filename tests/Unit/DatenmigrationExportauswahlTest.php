<?php
// tests/Unit/DatenmigrationExportauswahlTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\Datenmigration\Exportauswahl;

require_once __DIR__ . '/../../plugins/datenmigration/Plugin.php';

/**
 * Die Auswahl, was in ein Export-Archiv kommt (#121).
 *
 * Der Export nahm bis v0.7 zwangsläufig ALLES mit - also `users` mit den
 * Passwort-Hashes, den TOTP-Geheimnissen und den Backup-Codes, dazu
 * `api_keys`. Die Zuordnung Tabelle -> Gruppe ist deshalb keine Kosmetik,
 * sondern die Stelle, an der entschieden wird, ob Zugangsmaterial ein Archiv
 * verlässt. Sie steht als eigene Klasse im Plugin und ist damit ohne
 * Datenbank und ohne Kern-Instanz prüfbar.
 */
class DatenmigrationExportauswahlTest extends TestCase {

    /** Das Schema des Kerns in v0.8 (database/schema.sql) plus zwei Addon-Tabellen. */
    private const VORHANDEN = [
        'addon_repos', 'api_keys', 'audit_logs', 'contact_id_map', 'contacts',
        'gdpr_requests', 'group_permissions', 'groups', 'horse_persons',
        'horse_registrations', 'horses', 'login_attempts', 'match_labels',
        'password_resets', 'plugins', 'settings', 'user_groups', 'users',
        'plugin_galerie_media', 'plugin_kontaktanfrage_requests',
    ];

    /**
     * Der Kern der Sache: Die Vorgabe darf das Zugangsmaterial NICHT
     * enthalten. Fällt dieser Test, ist der Regelfall wieder der Vollexport
     * samt Passwort-Hashes - und niemand merkt es, weil das Archiv ja
     * funktioniert.
     */
    public function testVorgabeLaesstBenutzerUndZugangsmaterialWeg(): void {
        $vorgabe = Exportauswahl::vorgabe();

        $this->assertNotContains(Exportauswahl::GRUPPE_BENUTZER, $vorgabe);

        $tabellen = Exportauswahl::tabellen($vorgabe, self::VORHANDEN);
        foreach (['users', 'api_keys', 'password_resets', 'group_permissions', 'groups', 'user_groups', 'login_attempts'] as $heikel) {
            $this->assertNotContains(
                $heikel,
                $tabellen,
                "Tabelle '{$heikel}' darf bei der Vorgabe-Auswahl nicht im Archiv landen."
            );
        }
    }

    /**
     * Die andere bequeme Voreinstellung wäre "nichts angehakt" - ein leeres
     * Archiv, das zum gedankenlosen Alles-Anhaken erzieht. Die Vorgabe muss
     * den Regelfall (Pferde, Kontakte, Dateien) abdecken.
     */
    public function testVorgabeIstWederLeerNochAlles(): void {
        $vorgabe = Exportauswahl::vorgabe();

        $this->assertNotEmpty($vorgabe);
        $this->assertFalse(
            Exportauswahl::istVollstaendig($vorgabe),
            'Eine Vorgabe, die alles anhakt, macht die Auswahl wirkungslos.'
        );

        $tabellen = Exportauswahl::tabellen($vorgabe, self::VORHANDEN);
        foreach (['horses', 'contacts', 'contact_id_map', 'horse_persons', 'settings', 'plugin_galerie_media'] as $noetig) {
            $this->assertContains($noetig, $tabellen);
        }
        $this->assertContains(Exportauswahl::GRUPPE_DATEIEN, $vorgabe);
    }

    /**
     * Jede Tabelle des Schemas muss in GENAU einer Gruppe landen, und bei
     * voller Auswahl muss das Ergebnis wieder der vollständige Bestand sein.
     * Eine Tabelle, die durch das Raster fällt, verschwände aus jedem Export,
     * ohne dass es jemand merkt.
     */
    public function testVollstaendigeAuswahlErfasstJedeVorhandeneTabelle(): void {
        $alle = Exportauswahl::tabellen(Exportauswahl::schluessel(), self::VORHANDEN);

        $this->assertSame(self::VORHANDEN, $alle);
    }

    /**
     * Der Kern bekommt in der nächsten Version neue Tabellen. Sie müssen in
     * der sichtbaren Auffanggruppe landen - und dort auch tatsächlich
     * exportiert werden.
     */
    public function testUnbekannteTabelleLandetInDerAuffanggruppe(): void {
        $this->assertSame(
            Exportauswahl::GRUPPE_SONSTIGES,
            Exportauswahl::gruppeFuer('irgendwas_neues_aus_v0_9')
        );

        $mit = Exportauswahl::tabellen(
            [Exportauswahl::GRUPPE_SONSTIGES],
            ['horses', 'irgendwas_neues_aus_v0_9']
        );
        $this->assertSame(['irgendwas_neues_aus_v0_9'], $mit);
    }

    /** Addon-Tabellen erkennt die Zuordnung am Präfix, ohne sie zu kennen. */
    public function testAddonTabellenGehenUeberDasPraefix(): void {
        $this->assertSame('addons', Exportauswahl::gruppeFuer('plugin_verkaufsboerse_listings'));
        $this->assertSame('addons', Exportauswahl::gruppeFuer('plugins'));
    }

    /**
     * `bereinige()` verarbeitet Formulareingaben UND fremde Manifeste. Was
     * dort ankommt, steuert am Ende, welche Tabellen ersetzt werden - ein
     * durchgereichter Fantasieschlüssel hätte in dieser Kette nichts zu
     * suchen.
     */
    public function testBereinigeNimmtNurBekannteSchluesselUndSortiertSie(): void {
        $this->assertSame(
            ['pferde', 'kontakte'],
            Exportauswahl::bereinige(['kontakte', 'pferde', 'kontakte', 'gibtsnicht', 42, null])
        );
        $this->assertSame([], Exportauswahl::bereinige('pferde'));
        $this->assertSame([], Exportauswahl::bereinige(null));
    }

    public function testIstVollstaendigNurBeiJederGruppe(): void {
        $this->assertTrue(Exportauswahl::istVollstaendig(Exportauswahl::schluessel()));
        $this->assertFalse(Exportauswahl::istVollstaendig(
            array_diff(Exportauswahl::schluessel(), [Exportauswahl::GRUPPE_DATEIEN])
        ));
        $this->assertFalse(Exportauswahl::istVollstaendig([]));
    }

    /**
     * contact_id_map bildet die alten Personen-/Stationskennungen auf
     * Kontakte ab (Framework#336). Sie gehört zu den Kontakten, nicht zu den
     * Pferden - sonst führe sie bei "nur Pferde" mit und liefe auf dem Ziel
     * gegen Kontakte, die es dort nicht gibt.
     */
    public function testKontaktgruppeFuehrtDieKennungsabbildungMit(): void {
        $nurKontakte = Exportauswahl::tabellen(['kontakte'], self::VORHANDEN);
        $this->assertSame(['contact_id_map', 'contacts'], $nurKontakte);
    }
}
