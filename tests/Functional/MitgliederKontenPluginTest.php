<?php
// tests/Functional/MitgliederKontenPluginTest.php

namespace Tests\Functional;

use App\Database;
use Plugin\MitgliederKonten\Abgleich;
use Plugin\MitgliederKonten\CiviApi;
use Plugin\MitgliederKonten\CiviApiFehler;
use Plugin\MitgliederKonten\Konfiguration;
use Plugin\MitgliederKonten\Zuordnung;
use Tests\Support\HttpClient;

/**
 * Mitglieder-Konten (Addons#131) gegen eine echte Instanz.
 *
 * WARUM CIVICRM HIER NICHT ANGERUFEN WIRD. Ein Test, der eine fremde
 * Anwendung anruft, misst deren Erreichbarkeit und nicht diesen Code - im
 * naechtlichen Lauf waere er rot, ohne dass etwas kaputt ist. Der Zugang
 * steckt deshalb in einer einzigen ueberschreibbaren Methode
 * (CiviApi::sende()), und hier steht eine Attrappe. Geprueft wird alles
 * andere: Auswertung der Antwort, Vorschau samt Hinderungsgruenden, Anlegen,
 * die Sperre bei beendeter Mitgliedschaft - und was passiert, wenn CiviCRM
 * NICHT erreichbar ist.
 */
class MitgliederKontenPluginTest extends FunctionalTestCase {

    private const SLUG = 'mitglieder-konten';

    /**
     * Die Addon-Klassen laufen normalerweise erst im `php -S`-Subprozess an -
     * der Kern bindet sie beim Booten des Plugins per require_once ein. Dieser
     * Test ruft sie zusaetzlich DIREKT auf (mit einer Attrappe des
     * CiviCRM-Zugangs), und dafuer muessen sie auch im PHPUnit-Prozess da
     * sein.
     *
     * GELADEN WIRD DIE REPO-FASSUNG, NICHT DIE VENDORIERTE KOPIE (#160).
     * Inhaltlich sind beide gleich - tests/bootstrap.php spiegelt plugins/
     * vor jedem Lauf nach vendor/.../plugins, und zwar Datei fuer Datei. Fuer
     * `require_once` sind es aber ZWEI Pfade und damit zwei Ladevorgaenge
     * derselben Klasse.
     *
     * Das ist nicht theoretisch: tests/Manifest/PluginManifestTest laedt die
     * Entry-Datei JEDES Plugins aus plugins/ (loadPluginClass()), und
     * Plugin.php bindet CiviApi.php per __DIR__ ein - also aus dem
     * Repo-Verzeichnis. Wurde hier anschliessend die vendorierte Fassung
     * verlangt, loeste ihr eigenes __DIR__ auf den anderen Pfad auf, und PHP
     * brach mit "Cannot redeclare Plugin\MitgliederKonten\CiviApi" ab.
     *
     * In der CI fiel das nie auf, weil tests.yml die drei Suiten in DREI
     * getrennten Prozessen faehrt. `composer test` faehrt sie in EINEM - und
     * genau so ruft framework-update.yml sie auf. Der woechentliche Lauf
     * gegen Framework-main stand deshalb seit dem 25.08. still und meldete
     * "Addons brechen gegen Framework-main", obwohl gar nichts geprueft
     * worden war.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        $eintrag = __DIR__ . '/../../plugins/' . self::SLUG . '/Plugin.php';
        self::assertFileExists($eintrag, "Entry-Datei des Addons '" . self::SLUG . "' fehlt.");
        require_once $eintrag;

        /* Die Kopie muss trotzdem dasein - der `php -S`-Subprozess laedt sie,
           und ohne sie waere jede Zusicherung ueber HTTP gegenstandslos. */
        self::assertFileExists(
            FRAMEWORK_VENDOR_DIR . '/plugins/' . self::SLUG . '/Plugin.php',
            'Das Addon wurde nicht in die Framework-Instanz kopiert.'
        );
    }

    /** @var array<int, string> Benutzernamen, die wieder weg muessen. */
    private array $aufraeumen = [];

    protected function tearDown(): void {
        // Die Zielgruppe ist geteilter Zustand der Suite - ein Test, der sie
        // auf eine unzulaessige Gruppe stellt, darf die naechsten nicht
        // beeinflussen.
        Konfiguration::speichern([Konfiguration::S_GRUPPE => '0']);
        Konfiguration::leereCache();

        $db = Database::getInstance();
        $db->exec('DELETE FROM `' . Zuordnung::TABELLE . '`');
        if ($this->aufraeumen !== []) {
            $stmt = $db->prepare('DELETE FROM users WHERE username = ?');
            foreach ($this->aufraeumen as $name) {
                $stmt->execute([$name]);
            }
            $this->aufraeumen = [];
        }
        parent::tearDown();
    }

    private function addonAktivieren(HttpClient $admin): void {
        $antwort = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $antwort->location(), "Aktivieren fehlgeschlagen: {$antwort->body}");
    }

    private function leseGruppeAnlegen(HttpClient $admin, string $name): int {
        $seite = $admin->get('/admin/groups');
        $antwort = $admin->post('/admin/groups/create', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        preg_match('/group=(\d+)/', (string)$antwort->location(), $treffer);
        $this->assertNotEmpty($treffer, "Konnte Gruppen-ID nicht ermitteln: {$antwort->body}");
        $gruppe = (int)$treffer[1];
        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view']]);

        return $gruppe;
    }

    /**
     * Attrappe des Zugangs: liefert vorgegebene Mitgliedschaften, ohne je ins
     * Netz zu gehen.
     *
     * @param array<int, array<string, mixed>> $zeilen
     */
    private function attrappe(array $zeilen, bool $wirft = false): CiviApi {
        return new class ('https://civi.example.org', 'schluessel', $zeilen, $wirft) extends CiviApi {
            /** @param array<int, array<string, mixed>> $zeilen */
            public function __construct(
                string $basis,
                string $key,
                private readonly array $zeilen,
                private readonly bool $wirft
            ) {
                parent::__construct($basis, $key);
            }

            protected function sende(string $entitaet, string $aktion, array $params): array {
                if ($this->wirft) {
                    throw new CiviApiFehler('Attrappe: CiviCRM nicht erreichbar.');
                }
                // Nur die erste Seite hat Inhalt - die Paginierung endet, sobald
                // weniger als eine volle Seite zurueckkommt.
                return ((int)($params['offset'] ?? 0)) === 0 ? ['values' => $this->zeilen] : ['values' => []];
            }
        };
    }

    /** @return array<string, mixed> */
    private function civiZeile(int $mitgliedschaft, int $kontakt, string $name, string $email = ''): array {
        return [
            'id' => $mitgliedschaft,
            'contact_id' => $kontakt,
            'contact_id.display_name' => $name,
            'contact_id.email_primary.email' => $email,
        ];
    }

    public function testDieVerwaltungsseiteStehtNurBerechtigtenOffen(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);

        $seite = $admin->get('/plugin/mitglieder-konten/verwaltung');
        $this->assertSame(200, $seite->statusCode, "Body: {$seite->body}");
        $this->assertStringContainsString('CiviCRM-Zugang', $seite->body);

        $anonym = $this->newClient();
        $this->assertSame('/login', $anonym->get('/plugin/mitglieder-konten/verwaltung')->location());
    }

    /**
     * Der API-Schluessel darf nirgends im Klartext landen - nicht in der
     * Datenbank und nicht wieder auf der Seite.
     */
    public function testDerApiSchluesselWirdVerschluesseltGespeichertUndNieAngezeigt(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $geheim = 'civi-testschluessel-4711-nie-im-klartext';

        $seite = $admin->get('/plugin/mitglieder-konten/verwaltung');
        $antwort = $admin->post('/plugin/mitglieder-konten/verwaltung/zugang', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'basis_url' => 'https://civi.example.org',
            'api_key' => $geheim,
            'team_email' => 'team@example.org',
            'gruppe' => '0',
            'typen' => '',
        ]);
        $this->assertStringContainsString('mk=gespeichert', (string)$antwort->location(), "Body: {$antwort->body}");

        $abgelegt = (string)Database::getInstance()
            ->query("SELECT setting_value FROM settings WHERE setting_key = '" . Konfiguration::S_KEY . "'")
            ->fetchColumn();
        $this->assertNotSame('', $abgelegt);
        $this->assertStringNotContainsString($geheim, $abgelegt, 'Der Schluessel darf nicht im Klartext in settings stehen.');

        $wieder = $admin->get('/plugin/mitglieder-konten/verwaltung');
        $this->assertStringNotContainsString($geheim, $wieder->body, 'Der Schluessel darf nie wieder ausgegeben werden.');
    }

    public function testEineUnbrauchbareAdresseWirdAbgelehnt(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);

        $seite = $admin->get('/plugin/mitglieder-konten/verwaltung');
        foreach (['javascript:alert(1)', 'https://civi.example.org/pfad?x=1'] as $kaputt) {
            $antwort = $admin->post('/plugin/mitglieder-konten/verwaltung/zugang', [
                'csrf_token' => $seite->formField('csrf_token') ?? '',
                'basis_url' => $kaputt,
                'api_key' => '',
                'team_email' => '',
                'gruppe' => '0',
                'typen' => '',
            ]);
            $this->assertStringContainsString('mk=url-ungueltig', (string)$antwort->location(), "'{$kaputt}' haette abgelehnt werden muessen.");
        }
    }

    /**
     * Ein leeres Schluesselfeld heisst "nicht aendern", nicht "loeschen" -
     * sonst entfernte jedes Speichern der uebrigen Einstellungen den
     * Schluessel, weil das Formular ihn nie zurueckgibt.
     */
    public function testEinLeeresSchluesselfeldLaesstDenSchluesselStehen(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $seite = $admin->get('/plugin/mitglieder-konten/verwaltung');
        $csrf = $seite->formField('csrf_token') ?? '';

        $admin->post('/plugin/mitglieder-konten/verwaltung/zugang', [
            'csrf_token' => $csrf, 'basis_url' => 'https://civi.example.org',
            'api_key' => 'erster-schluessel', 'team_email' => '', 'gruppe' => '0', 'typen' => '',
        ]);
        $vorher = (string)Database::getInstance()
            ->query("SELECT setting_value FROM settings WHERE setting_key = '" . Konfiguration::S_KEY . "'")
            ->fetchColumn();

        $admin->post('/plugin/mitglieder-konten/verwaltung/zugang', [
            'csrf_token' => $csrf, 'basis_url' => 'https://civi.example.org',
            'api_key' => '', 'team_email' => 'team@example.org', 'gruppe' => '0', 'typen' => '',
        ]);
        $nachher = (string)Database::getInstance()
            ->query("SELECT setting_value FROM settings WHERE setting_key = '" . Konfiguration::S_KEY . "'")
            ->fetchColumn();

        $this->assertSame($vorher, $nachher);
    }

    public function testDieVorschauNenntJedenHinderungsgrundVorDemErstenKonto(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $einmalig = substr(uniqid(), -6);
        $gruppe = $this->leseGruppeAnlegen($admin, "Mitglieder lesen {$einmalig}");
        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$gruppe]);
        Konfiguration::leereCache();

        // Ein Konto, dessen Name schon vergeben ist - hier der Admin selbst.
        $adminName = (string)Database::getInstance()
            ->query('SELECT username FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();

        $client = $this->attrappe([
            $this->civiZeile(9001, 11, 'Anna Mit Adresse', "anna-{$einmalig}@example.org"),
            $this->civiZeile(9002, 12, 'Bert Ohne Adresse'),
            $this->civiZeile((int)$adminName === 0 ? 9003 : (int)$adminName, 13, 'Namenskollision'),
        ]);

        $vorschau = Abgleich::vorschau($client);
        $this->assertNull($vorschau['fehler']);

        $nachId = [];
        foreach ($vorschau['zeilen'] as $z) {
            $nachId[(int)$z['membership_id']] = $z;
        }

        $this->assertSame('neu', $nachId[9001]['zustand']);
        $this->assertSame('neu', $nachId[9002]['zustand'], 'Ohne Adresse ist in einer reinen Lesegruppe zulaessig.');
    }

    private function schreibGruppeAnlegen(HttpClient $admin, string $name): int {
        $gruppe = $this->leseGruppeAnlegen($admin, $name);
        $this->setGroupPermissions($admin, $gruppe, ['horses' => ['view', 'edit']]);

        return $gruppe;
    }

    private function gruppeNachSlug(string $slug): int {
        $stmt = Database::getInstance()->prepare('SELECT id FROM `groups` WHERE slug = ?');
        $stmt->execute([$slug]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Eine schreibende Zielgruppe wird nicht mehr nur fuer Mitglieder ohne
     * Adresse abgewiesen, sondern ganz: Die Vorschau bietet nichts an und
     * fragt CiviCRM gar nicht erst (Audit H1).
     */
    public function testEineSchreibendeZielgruppeBlockiertDieGanzeVorschau(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $gruppe = $this->schreibGruppeAnlegen($admin, 'Mitglieder schreiben ' . substr(uniqid(), -6));

        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$gruppe]);
        Konfiguration::leereCache();

        $vorschau = Abgleich::vorschau($this->attrappe([$this->civiZeile(9101, 21, 'Ohne Adresse')]));

        $this->assertSame([], $vorschau['zeilen']);
        $this->assertStringContainsString('Lesegruppe', (string)$vorschau['fehler']);
    }

    /**
     * Audit H1: Das Addon-Recht `mitglieder_konten.manage` laesst sich an
     * Nicht-Admins vergeben. Es darf nicht dazu fuehren, dass Konten in
     * `admin` oder einer schreibenden Gruppe entstehen - im Kern legt nur ein
     * Admin Konten an und weist Gruppen zu.
     */
    public function testEinNichtAdminMitManageKannKeineAdminOderSchreibgruppeSetzen(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $x = substr(uniqid(), -6);

        $lese = $this->leseGruppeAnlegen($admin, "MK lesen {$x}");
        $schreib = $this->schreibGruppeAnlegen($admin, "MK schreiben {$x}");
        $stelle = $this->leseGruppeAnlegen($admin, "Geschaeftsstelle {$x}");
        $this->setGroupPermissions($admin, $stelle, ['mitglieder_konten' => ['manage']]);
        $adminGruppe = $this->gruppeNachSlug('admin');
        $gastGruppe = $this->gruppeNachSlug('public');

        $this->aufraeumen[] = "gst{$x}";
        $stelleClient = $this->createAndLoginEditor($admin, "gst{$x}", "gst-{$x}@example.org", [$stelle]);

        $seite = $stelleClient->get('/plugin/mitglieder-konten/verwaltung');
        $this->assertSame(200, $seite->statusCode, "Body: {$seite->body}");
        $this->assertStringContainsString("value=\"{$lese}\"", $seite->body);
        foreach ([$adminGruppe, $schreib, $stelle, $gastGruppe] as $verboten) {
            $this->assertStringNotContainsString("value=\"{$verboten}\"", $seite->body, "Gruppe {$verboten} darf nicht zur Wahl stehen.");
        }
        $csrf = $seite->formField('csrf_token') ?? '';

        // Positivkontrolle: eine Lesegruppe laesst sich speichern.
        $ok = $stelleClient->post('/plugin/mitglieder-konten/verwaltung/zugang', [
            'csrf_token' => $csrf, 'basis_url' => 'https://civi.example.org',
            'api_key' => '', 'team_email' => '', 'gruppe' => (string)$lese, 'typen' => '',
        ]);
        $this->assertStringContainsString('mk=gespeichert', (string)$ok->location(), "Body: {$ok->body}");

        $angriffe = [(string)$adminGruppe, (string)$schreib, (string)$stelle, (string)$gastGruppe, '999999', '-1', 'abc', '', ['1']];
        foreach ($angriffe as $wert) {
            $antwort = $stelleClient->post('/plugin/mitglieder-konten/verwaltung/zugang', [
                'csrf_token' => $csrf, 'basis_url' => 'https://angreifer.example',
                'api_key' => '', 'team_email' => '', 'gruppe' => $wert, 'typen' => '',
            ]);
            $this->assertStringContainsString(
                'mk=gruppe-unzulaessig',
                (string)$antwort->location(),
                'Gruppe ' . json_encode($wert) . " haette abgelehnt werden muessen. Body: {$antwort->body}"
            );
        }

        Konfiguration::leereCache();
        $this->assertSame($lese, Konfiguration::gruppeId(), 'Ein abgelehnter Versuch darf nichts speichern.');
        $this->assertSame('https://civi.example.org', Konfiguration::basis(), 'Auch die Adresse bleibt unveraendert.');

        $protokolliert = (int)Database::getInstance()
            ->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'Unzulaessige Zielgruppe abgelehnt'")
            ->fetchColumn();
        $this->assertGreaterThan(0, $protokolliert);
    }

    /**
     * Altbestand: Eine frueher gespeicherte Admin- oder Schreibgruppe, oder
     * eine Lesegruppe, die NACH dem Speichern Schreibrechte bekommen hat,
     * darf beim Anlegen kein Konto erzeugen.
     */
    public function testEineUnzulaessigeGespeicherteGruppeLegtKeinKontoAn(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $x = substr(uniqid(), -6);
        $db = Database::getInstance();
        $adminGruppe = $this->gruppeNachSlug('admin');
        $this->aufraeumen[] = '9601';
        $this->aufraeumen[] = '9602';
        $this->aufraeumen[] = '9603';

        $mitgliederVorher = (int)$db->query("SELECT COUNT(*) FROM user_groups WHERE group_id = {$adminGruppe}")->fetchColumn();

        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$adminGruppe]);
        Konfiguration::leereCache();
        $vorschau = Abgleich::vorschau($this->attrappe([$this->civiZeile(9601, 61, 'Mit Adresse', "a-{$x}@example.org")]));
        $this->assertSame([], $vorschau['zeilen']);
        $this->assertStringContainsString('Lesegruppe', (string)$vorschau['fehler']);

        $ergebnis = Abgleich::anlegen([9601], $this->attrappe([$this->civiZeile(9601, 61, 'Mit Adresse', "a-{$x}@example.org")]));
        $this->assertSame(0, $ergebnis['angelegt']);
        $this->assertNotSame([], $ergebnis['fehler']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM users WHERE username = '9601'")->fetchColumn());
        $this->assertSame($mitgliederVorher, (int)$db->query("SELECT COUNT(*) FROM user_groups WHERE group_id = {$adminGruppe}")->fetchColumn());

        // Lesegruppe gespeichert, danach bekommt sie Schreibrechte.
        $spaeter = $this->leseGruppeAnlegen($admin, "MK spaeter schreibend {$x}");
        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$spaeter]);
        Konfiguration::leereCache();
        $this->setGroupPermissions($admin, $spaeter, ['horses' => ['view', 'edit']]);
        $ergebnis = Abgleich::anlegen([9602], $this->attrappe([$this->civiZeile(9602, 62, 'Spaet', "b-{$x}@example.org")]));
        $this->assertSame(0, $ergebnis['angelegt']);

        // Eine Gruppe, die es nicht mehr gibt.
        Konfiguration::speichern([Konfiguration::S_GRUPPE => '999999']);
        Konfiguration::leereCache();
        $ergebnis = Abgleich::anlegen([9603], $this->attrappe([$this->civiZeile(9603, 63, 'Weg', "c-{$x}@example.org")]));
        $this->assertSame(0, $ergebnis['angelegt']);
        $this->assertNotSame([], $ergebnis['fehler']);
    }

    /** Der Tageslauf legt nichts an - er warnt, sperrt aber weiter. */
    public function testDerTageslaufWarntBeiUnzulaessigerZielgruppeUndSperrtTrotzdem(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $x = substr(uniqid(), -6);
        $gruppe = $this->leseGruppeAnlegen($admin, "MK Tageslauf {$x}");
        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$gruppe]);
        Konfiguration::leereCache();

        $this->aufraeumen[] = '9701';
        Abgleich::anlegen([9701], $this->attrappe([$this->civiZeile(9701, 71, 'Geht bald', "t-{$x}@example.org")]));

        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$this->gruppeNachSlug('admin')]);
        Konfiguration::leereCache();

        $bericht = Abgleich::taeglicherLauf($this->attrappe([]));
        $this->assertSame(1, $bericht['gesperrt'], 'Die Sperre beendeter Mitgliedschaften laeuft trotz unzulaessiger Gruppe.');
        $gewarnt = (int)Database::getInstance()
            ->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'Mitglieder-Konten: Zielgruppe unzulaessig'")
            ->fetchColumn();
        $this->assertGreaterThan(0, $gewarnt);
    }

    public function testKontenEntstehenGenauEinmalUndMitZuordnung(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $einmalig = substr(uniqid(), -6);
        $gruppe = $this->leseGruppeAnlegen($admin, "Mitglieder einmal {$einmalig}");
        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$gruppe, Konfiguration::S_TEAM => 'team@example.org']);
        Konfiguration::leereCache();

        $client = $this->attrappe([
            $this->civiZeile(9201, 31, 'Erste Person', "erste-{$einmalig}@example.org"),
            $this->civiZeile(9202, 32, 'Zweite Person'),
        ]);
        $this->aufraeumen[] = '9201';
        $this->aufraeumen[] = '9202';

        $erst = Abgleich::anlegen([9201, 9202], $client);
        $this->assertSame(2, $erst['angelegt'], implode(' | ', $erst['fehler']));

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT email, must_change_password FROM users WHERE username = ?');
        $stmt->execute(['9202']);
        $konto = $stmt->fetch();
        $this->assertNull($konto['email'], 'Ohne Adresse heisst NULL, nicht Leerstring.');
        $this->assertSame(1, (int)$konto['must_change_password']);

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE username = ?');
        $stmt->execute(['9201']);
        $hashVorher = (string)$stmt->fetchColumn();

        // Zweiter Lauf: keine stille Zweitanlage.
        //
        // Geprueft wird der GRUND, nicht nur das Ergebnis: Die Vorschau muss
        // "vorhanden" sagen. Ohne diese Zusicherung waere der Test auch dann
        // gruen, wenn die Zuordnung gar nicht griffe - der Benutzername ist
        // nach dem ersten Lauf ohnehin belegt, und dann hiesse es
        // "blockiert". Zwei verschiedene Wege zum selben sichtbaren Ergebnis,
        // und nur einer davon ist der, den Addons#131 verlangt.
        $zweiteVorschau = Abgleich::vorschau($client);
        $zustaende = [];
        foreach ($zweiteVorschau['zeilen'] as $z) {
            $zustaende[(int)$z['membership_id']] = $z['zustand'];
        }
        $this->assertSame('vorhanden', $zustaende[9201] ?? '-', 'Die Zuordnung muss das Konto wiedererkennen.');
        $this->assertSame('vorhanden', $zustaende[9202] ?? '-');

        $zweit = Abgleich::anlegen([9201, 9202], $client);
        $this->assertSame(0, $zweit['angelegt']);
        $this->assertSame(2, $zweit['uebersprungen']);
        $this->assertSame(
            2,
            (int)$db->query("SELECT COUNT(*) FROM users WHERE username IN ('9201','9202')")->fetchColumn()
        );

        // Und ein bestehendes Passwort wird nie zurueckgesetzt.
        $stmt->execute(['9201']);
        $this->assertSame($hashVorher, (string)$stmt->fetchColumn());
    }

    public function testEineBeendeteMitgliedschaftSperrtDasKontoUndLoeschtEsNicht(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $einmalig = substr(uniqid(), -6);
        $gruppe = $this->leseGruppeAnlegen($admin, "Mitglieder Ende {$einmalig}");
        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$gruppe]);
        Konfiguration::leereCache();

        $this->aufraeumen[] = '9301';
        Abgleich::anlegen([9301], $this->attrappe([$this->civiZeile(9301, 41, 'Tritt bald aus', "aus-{$einmalig}@example.org")]));

        // Naechster Lauf: Die Mitgliedschaft laeuft nicht mehr.
        $bericht = Abgleich::taeglicherLauf($this->attrappe([]));
        $this->assertSame(1, $bericht['gesperrt']);

        $stmt = Database::getInstance()->prepare(
            'SELECT deactivated_at IS NOT NULL AS gesperrt, deactivated_reason, deleted_at IS NULL AS lebt
             FROM users WHERE username = ?'
        );
        $stmt->execute(['9301']);
        $konto = $stmt->fetch();
        $this->assertSame(1, (int)$konto['gesperrt']);
        $this->assertSame('membership_ended', $konto['deactivated_reason']);
        $this->assertSame(1, (int)$konto['lebt'], 'Gesperrt, nicht geloescht.');
    }

    /**
     * Der Fall, an dem eine Automatik ueber Nacht den ganzen Bestand
     * abraeumt: CiviCRM ist nicht erreichbar. "Konnte nicht pruefen" und
     * "geprueft, laeuft nicht mehr" sind verschiedene Aussagen.
     */
    public function testEinUnerreichbaresCiviCrmSperrtNiemanden(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $einmalig = substr(uniqid(), -6);
        $gruppe = $this->leseGruppeAnlegen($admin, "Mitglieder Netz {$einmalig}");
        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$gruppe]);
        Konfiguration::leereCache();

        $this->aufraeumen[] = '9401';
        Abgleich::anlegen([9401], $this->attrappe([$this->civiZeile(9401, 51, 'Bleibt Mitglied', "netz-{$einmalig}@example.org")]));

        $bericht = Abgleich::taeglicherLauf($this->attrappe([], true));

        $this->assertSame(0, $bericht['gesperrt']);
        $stmt = Database::getInstance()->prepare('SELECT deactivated_at FROM users WHERE username = ?');
        $stmt->execute(['9401']);
        $this->assertNull($stmt->fetchColumn(), 'Ein Netzfehler darf kein Konto sperren.');
    }

    /**
     * Die Auswahl kommt aus einem Formular. Was die Vorschau nicht als `neu`
     * fuehrt, darf auch dann nicht entstehen, wenn es im POST steht.
     */
    /**
     * Die Auswahl kommt aus einem Formular und ist damit nutzergesteuert.
     * Zwei Faelle, und der zweite ist der gefaehrliche:
     *
     *  1. Eine Nummer, die in der Vorschau gar nicht vorkommt.
     *  2. Eine Nummer, die drinsteht, aber BLOCKIERT ist. Wer nur den ersten
     *     Fall prueft, prueft `$zeile === null` - und merkt nicht, wenn die
     *     Zustandspruefung fehlt.
     */
    public function testEineUntergeschobeneAuswahlLegtNichtsAn(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $einmalig = substr(uniqid(), -6);
        $gruppe = $this->leseGruppeAnlegen($admin, "Mitglieder fremd {$einmalig}");
        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$gruppe]);
        Konfiguration::leereCache();

        $db = Database::getInstance();

        // Ein Konto, das dieses Addon NICHT angelegt hat und das genau so
        // heisst wie eine Mitgliedschaftsnummer - der Blockierfall.
        $db->prepare("INSERT INTO users (username, email, password_hash) VALUES ('9502', ?, 'fremdes-konto')")
           ->execute(["fremd-{$einmalig}@example.org"]);
        $this->aufraeumen[] = '9502';

        $client = $this->attrappe([
            $this->civiZeile(9501, 61, 'Echt', "echt-{$einmalig}@example.org"),
            $this->civiZeile(9502, 62, 'Namensgleich', "gleich-{$einmalig}@example.org"),
        ]);

        $vorschau = Abgleich::vorschau($client);
        $zustaende = [];
        foreach ($vorschau['zeilen'] as $z) {
            $zustaende[(int)$z['membership_id']] = $z['zustand'];
        }
        $this->assertSame('blockiert', $zustaende[9502] ?? '-', 'Voraussetzung: 9502 ist blockiert.');

        $ergebnis = Abgleich::anlegen([999999, 9502], $client);

        $this->assertSame(0, $ergebnis['angelegt']);
        $this->assertSame(2, $ergebnis['uebersprungen']);
        $this->assertSame(
            0,
            (int)$db->query("SELECT COUNT(*) FROM users WHERE username = '999999'")->fetchColumn()
        );
        // Das fremde Konto ist unangetastet - kein neues Passwort, keine
        // Zuordnung, die es diesem Addon zuschlaegt.
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE username = ?');
        $stmt->execute(['9502']);
        $this->assertSame('fremdes-konto', (string)$stmt->fetchColumn());
        $this->assertSame(
            0,
            (int)$db->query('SELECT COUNT(*) FROM `' . Zuordnung::TABELLE . '` WHERE membership_id = 9502')->fetchColumn()
        );
    }
}
