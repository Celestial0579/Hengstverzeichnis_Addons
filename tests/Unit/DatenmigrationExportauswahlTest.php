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

    /**
     * Das Schema des Kerns in v0.9 (database/schema.sql) plus Addon-Tabellen -
     * darunter eine mit Fremdschlüssel auf users (mitglieder-konten).
     */
    private const VORHANDEN = [
        'addon_repos', 'api_keys', 'audit_logs', 'contact_id_map', 'contacts',
        'email_2fa_codes', 'gdpr_requests', 'group_permissions', 'groups', 'horse_media',
        'horse_persons', 'horse_registrations', 'horses', 'login_attempts', 'match_labels',
        'password_resets', 'plugins', 'settings', 'user_groups', 'user_passkeys', 'users',
        'plugin_galerie_media', 'plugin_kontaktanfrage_requests', 'plugin_mitglieder_konten_zuordnung',
    ];

    /** Die Fremdschlüssel dazu, wie information_schema sie liefert (Tabelle => Ziele). */
    private const FKS = [
        'contact_id_map' => ['contacts'],
        'email_2fa_codes' => ['users'],
        'horse_media' => ['horses'],
        'horse_persons' => ['horses', 'contacts'],
        'horse_registrations' => ['horses'],
        'horses' => ['contacts'],
        'user_groups' => ['users', 'groups'],
        'group_permissions' => ['groups'],
        'user_passkeys' => ['users'],
        'api_keys' => ['users'],
        'plugin_mitglieder_konten_zuordnung' => ['users'],
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

        $mitFks = Exportauswahl::tabellen(Exportauswahl::schluessel(), self::VORHANDEN, self::FKS);
        $this->assertSame(self::VORHANDEN, $mitFks);
    }

    /**
     * Audit M3: Passkeys, E-Mail-Anmeldecodes und jede Tabelle mit
     * Fremdschlüssel auf users gehen mit der Vorgabe NICHT mehr mit - mit und
     * ohne Fremdschlüssel-Karte. Bis 1.1.0 liefen die beiden Kerntabellen
     * unter "sonstiges" (per Vorgabe an) und hängten sich beim Einspielen an
     * fremde Konten gleicher Kennung.
     */
    public function testVorgabeEnthaeltKeineBenutzerbezogenenTabellen(): void {
        foreach ([[], self::FKS] as $fks) {
            $tabellen = Exportauswahl::tabellen(Exportauswahl::vorgabe(), self::VORHANDEN, $fks);
            $this->assertNotContains('user_passkeys', $tabellen);
            $this->assertNotContains('email_2fa_codes', $tabellen);
        }
        $mitFks = Exportauswahl::tabellen(Exportauswahl::vorgabe(), self::VORHANDEN, self::FKS);
        $this->assertNotContains('plugin_mitglieder_konten_zuordnung', $mitFks);
        $this->assertContains('plugin_galerie_media', $mitFks);

        $benutzer = Exportauswahl::tabellen([Exportauswahl::GRUPPE_BENUTZER], self::VORHANDEN, self::FKS);
        foreach (['users', 'user_passkeys', 'email_2fa_codes', 'plugin_mitglieder_konten_zuordnung'] as $t) {
            $this->assertContains($t, $benutzer);
        }
    }

    /**
     * Die neue Zuordnung gilt für Export und Plan; die alte bleibt für die
     * Importregel, damit ältere Archive mit user_passkeys unter "sonstiges"
     * nicht abgewiesen, sondern ohne diese Tabelle eingespielt werden.
     */
    public function testNeueUndAlteZuordnung(): void {
        $this->assertSame(Exportauswahl::GRUPPE_BENUTZER, Exportauswahl::gruppeFuer('user_passkeys'));
        $this->assertSame(Exportauswahl::GRUPPE_SONSTIGES, Exportauswahl::altGruppeFuer('user_passkeys'));
        $this->assertSame(Exportauswahl::GRUPPE_BENUTZER, Exportauswahl::gruppeFuer('plugin_x', ['users']));
        $this->assertSame('addons', Exportauswahl::altGruppeFuer('plugin_x'));
        $this->assertSame('addons', Exportauswahl::gruppeFuer('plugin_x', ['horses']));
        // Die feste Zuordnung geht vor: horses bleibt Pferde, auch mit Verweis auf users.
        $this->assertSame('pferde', Exportauswahl::gruppeFuer('horses', ['users']));
    }

    /**
     * `weiche_verweise` aus einer plugin.json steuert, was "trennen" beim
     * Import LÖSCHT. Ein Addon darf deshalb nur eigene plugin_-Tabellen aus
     * owns.tables beschreiben, und nur mit schlichten Bezeichnern - sonst
     * könnte jedes installierte Addon `trennen: loeschen` auf users erklären.
     */
    public function testWeicheVerweiseAusPluginJsonWerdenGeprueft(): void {
        $manifest = [
            'slug' => 'beispiel',
            'owns' => ['tables' => ['plugin_beispiel_a', 'plugin_beispiel_b', 'users_kopie']],
            'weiche_verweise' => [
                'plugin_beispiel_a' => [
                    ['ziel' => 'contacts', 'spalte' => 'contact_id', 'trennen' => 'null_wert'],
                    ['ziel' => 'horses', 'spalte' => 'horse_id', 'wo' => ['art' => 'pferd'], 'trennen' => 'loeschen'],
                    ['ziel' => 'horses; DROP TABLE users', 'spalte' => 'x', 'trennen' => 'loeschen'],
                    ['ziel' => 'horses', 'spalte' => 'Horse_Id', 'trennen' => 'loeschen'],
                    ['ziel' => 'horses', 'spalte' => 'horse_id', 'trennen' => 'alles'],
                    ['ziel' => 'horses', 'spalte' => 'horse_id', 'wo' => ['art' => ['x']], 'trennen' => 'loeschen'],
                    'kein-objekt',
                ],
                // fremde Tabelle, nicht in owns.tables
                'users' => [['ziel' => 'contacts', 'spalte' => 'id', 'trennen' => 'loeschen']],
                'plugin_fremd_x' => [['ziel' => 'contacts', 'spalte' => 'id', 'trennen' => 'loeschen']],
                // in owns.tables, aber ohne plugin_-Präfix
                'users_kopie' => [['ziel' => 'contacts', 'spalte' => 'id', 'trennen' => 'loeschen']],
            ],
        ];

        $ergebnis = Exportauswahl::weicheVerweiseAusManifest($manifest);

        $this->assertSame(['plugin_beispiel_a'], array_keys($ergebnis['verweise']));
        $this->assertSame([
            ['ziel' => 'contacts', 'spalte' => 'contact_id', 'wo' => [], 'trennen' => 'null_wert'],
            ['ziel' => 'horses', 'spalte' => 'horse_id', 'wo' => ['art' => 'pferd'], 'trennen' => 'loeschen'],
        ], $ergebnis['verweise']['plugin_beispiel_a']);
        $this->assertCount(8, $ergebnis['verworfen']);

        $this->assertSame(['verweise' => [], 'verworfen' => []], Exportauswahl::weicheVerweiseAusManifest(['slug' => 'x']));
        $this->assertCount(1, Exportauswahl::weicheVerweiseAusManifest(['weiche_verweise' => 'x'])['verworfen']);
    }

    /**
     * Die Kontaktanfrage-Tabellen stehen in der Konstante UND in der
     * plugin.json des Addons - zusammengeführt zählen sie einmal.
     */
    public function testVerweiseAusKonstanteUndPluginJsonWerdenZusammengefuehrt(): void {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../../plugins/kontaktanfrage/plugin.json'), true);
        $ausJson = Exportauswahl::weicheVerweiseAusManifest($json);
        $this->assertSame([], $ausJson['verworfen']);

        $alle = Exportauswahl::verweiseZusammenfuehren(Exportauswahl::WEICHE_VERWEISE, $ausJson['verweise']);
        $this->assertCount(1, $alle['plugin_kontaktanfrage_requests']);
        $this->assertCount(1, $alle['plugin_kontaktanfrage_optout']);
        $this->assertSame('null_wert', $alle['plugin_kontaktanfrage_requests'][0]['trennen']);
        $this->assertCount(4, $alle['match_labels']);
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
