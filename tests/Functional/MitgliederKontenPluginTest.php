<?php
// tests/Functional/MitgliederKontenPluginTest.php

namespace Tests\Functional;

use App\Database;
use App\Security\Crypto;
use App\Service\DormantAccountService;
use App\Service\Mailer;
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

        /* M5: Die Tests zur Schluesselbindung lesen den Schluessel auch im
           PHPUnit-Prozess (Konfiguration::apiKey()). Crypto braucht dafuer
           die Konstante APP_KEY - dieselbe, die der `php -S`-Subprozess aus
           der Umgebung bekommt (tests/bootstrap.php), sonst passte der dort
           verschluesselte Schluessel hier nicht. */
        if (!defined('APP_KEY') && is_string(getenv('APP_KEY')) && getenv('APP_KEY') !== '') {
            define('APP_KEY', getenv('APP_KEY'));
        }

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
        Konfiguration::speichern([
            Konfiguration::S_GRUPPE => '0',
            Konfiguration::S_URL => '',
            Konfiguration::S_KEY => '',
            Konfiguration::S_KEY_BINDUNG => '',
            Konfiguration::S_TEAM => '',
            Konfiguration::S_TYPEN => '',
            Konfiguration::S_ANGEHALTEN => '',
        ]);
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
     * Zwei Arten von Anfragen, und sie muessen verschieden beantwortet werden
     * (N30): die Liste der laufenden Mitgliedschaften (laufendeMitgliedschaften)
     * und die gezielte Statusabfrage per `id IN` (statusNachId). Ohne diese
     * Unterscheidung bekaeme die Statusabfrage die Listenzeilen - Zeilen ohne
     * Statusfeld, also "unklar".
     *
     * $status: id => true|false|null, wobei null "Zeile ohne Statusfeld"
     * heisst und eine fehlende ID "in CiviCRM nicht auffindbar". Ohne
     * $status gilt: laufend ist, was in $zeilen steht; alles andere fehlt.
     *
     * @param array<int, array<string, mixed>> $zeilen
     * @param array<int, ?bool>|null $status
     */
    private function attrappe(array $zeilen, bool $wirft = false, ?array $status = null): CiviApi {
        return new class ('https://civi.example.org', 'schluessel', $zeilen, $wirft, $status) extends CiviApi {
            /** @var \ArrayObject<int, array<string, mixed>> Jede Anfrage, wie sie hinausginge. */
            public \ArrayObject $protokoll;

            /**
             * @param array<int, array<string, mixed>> $zeilen
             * @param array<int, ?bool>|null $status
             */
            public function __construct(
                string $basis,
                string $key,
                private readonly array $zeilen,
                private readonly bool $wirft,
                private readonly ?array $status
            ) {
                parent::__construct($basis, $key);
                $this->protokoll = new \ArrayObject();
            }

            protected function sende(string $entitaet, string $aktion, array $params): array {
                $this->protokoll[] = $params;
                if ($this->wirft) {
                    throw new CiviApiFehler('Attrappe: CiviCRM nicht erreichbar.', 'Connection refused (Attrappe)');
                }
                // Nur die erste Seite hat Inhalt - die Paginierung endet, sobald
                // weniger als eine volle Seite zurueckkommt.
                if (((int)($params['offset'] ?? 0)) !== 0) {
                    return ['values' => []];
                }

                $bedingung = $params['where'][0] ?? null;
                if (is_array($bedingung) && $bedingung[0] === 'id' && $bedingung[1] === 'IN') {
                    $status = $this->status;
                    if ($status === null) {
                        $status = array_fill_keys(array_map(static fn(array $z): int => (int)$z['id'], $this->zeilen), true);
                    }
                    $werte = [];
                    foreach ($bedingung[2] as $id) {
                        if (!array_key_exists($id, $status)) {
                            continue;
                        }
                        $werte[] = $status[$id] === null
                            ? ['id' => $id]
                            : ['id' => $id, 'status_id.is_current_member' => $status[$id]];
                    }
                    return ['values' => $werte];
                }

                return ['values' => $this->zeilen];
            }
        };
    }

    /**
     * Mailer-Attrappe (N31): App\Service\Mailer ist nicht final, der
     * Elternkonstruktor liest nur `settings`. Versandergebnis steuerbar,
     * jede Mail wird mitgeschrieben - auch das Passwort, damit sich pruefen
     * laesst, dass es NICHT im Protokoll landet.
     */
    private function mailer(bool $einzelOk = true, bool $sammelOk = true, bool $wirft = false): Mailer {
        return new class ($einzelOk, $sammelOk, $wirft) extends Mailer {
            /** @var array<int, string> */
            public array $passwoerter = [];
            /** @var array<int, string> */
            public array $empfaenger = [];

            public function __construct(private readonly bool $einzelOk, private readonly bool $sammelOk, private readonly bool $wirft) {
                parent::__construct();
            }

            public function sendWelcomeEmail(string $userEmail, string $userName, string $initialPassword): bool {
                $this->empfaenger[] = $userEmail;
                $this->passwoerter[] = $initialPassword;
                if ($this->wirft) {
                    throw new \RuntimeException('Attrappe: SMTP kaputt');
                }
                return $this->einzelOk;
            }

            public function send(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): bool {
                $this->empfaenger[] = $toEmail;
                if (preg_match_all("/monospace'>([^<]+)</", $htmlBody, $treffer)) {
                    foreach ($treffer[1] as $pw) {
                        $this->passwoerter[] = html_entity_decode($pw, ENT_QUOTES, 'UTF-8');
                    }
                }
                if ($this->wirft) {
                    throw new \RuntimeException('Attrappe: SMTP kaputt');
                }
                return $this->sammelOk;
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
        $kaputte = [
            'javascript:alert(1)',
            'https://civi.example.org/pfad?x=1',
            // M5: nur https, keine Zugangsdaten, kein internes Ziel.
            'http://civi.example.org',
            'https://127.0.0.1',
            'https://[::1]',
            'https://169.254.169.254',
            'https://localhost',
            'https://civi.localhost.',
            'https://u:p@civi.example.org',
            'https://civi.example.org/#frag',
        ];
        foreach ($kaputte as $kaputt) {
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
     * Audit H1 + Betreiberentscheidung zu M5/N30: Das Addon-Recht
     * `mitglieder_konten.manage` laesst sich an Nicht-Admins vergeben. Die
     * Einrichtung des Zugangs (Adresse, Schluessel, Zielgruppe, Typen) steht
     * seit 1.1.0 NUR Admins offen - wer die Datenquelle bestimmt, bestimmt
     * auch, wen der Tageslauf sperrt. Die Gruppenpruefung beim Speichern
     * bleibt fuer Admins bestehen: Auch ein Admin kann ueber dieses Addon
     * keine Konten in `admin` oder einer schreibenden Gruppe erzeugen.
     */
    public function testEinNichtAdminMitManageKannDenZugangNichtEinrichten(): void {
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

        // Sehen darf die Geschaeftsstelle die Seite - aendern nicht.
        $seite = $stelleClient->get('/plugin/mitglieder-konten/verwaltung');
        $this->assertSame(200, $seite->statusCode, "Body: {$seite->body}");
        $this->assertStringContainsString('Nur Administratoren können den Zugang ändern.', $seite->body);
        $this->assertStringNotContainsString("name='api_key'", $seite->body, 'Kein Schluesselfeld fuer Nicht-Admins.');
        foreach ([$adminGruppe, $schreib, $stelle, $gastGruppe] as $verboten) {
            $this->assertStringNotContainsString("value=\"{$verboten}\"", $seite->body, "Gruppe {$verboten} darf nicht zur Wahl stehen.");
        }
        // Das Token steht auch im gesperrten Formular - der POST scheitert
        // damit an der Rechtepruefung, nicht am CSRF-Check.
        $csrf = $seite->formField('csrf_token') ?? '';
        $this->assertNotSame('', $csrf);

        Konfiguration::speichern([
            Konfiguration::S_URL => 'https://civi.example.org',
            Konfiguration::S_GRUPPE => (string)$lese,
        ]);
        Konfiguration::leereCache();

        // JEDER Speicherversuch eines Nicht-Admins scheitert - auch der
        // harmlose mit einer Lesegruppe und unveraenderter Adresse.
        $versuche = [(string)$lese, (string)$adminGruppe, (string)$schreib, '0'];
        foreach ($versuche as $wert) {
            $antwort = $stelleClient->post('/plugin/mitglieder-konten/verwaltung/zugang', [
                'csrf_token' => $csrf, 'basis_url' => 'https://angreifer.example',
                'api_key' => 'eigener-schluessel', 'team_email' => '', 'gruppe' => $wert, 'typen' => '',
            ]);
            $this->assertSame(403, $antwort->statusCode, 'Gruppe ' . json_encode($wert) . " - Nicht-Admin haette abgewiesen werden muessen. Body: {$antwort->body}");
        }

        Konfiguration::leereCache();
        $this->assertSame($lese, Konfiguration::gruppeId(), 'Ein abgewiesener Versuch darf nichts speichern.');
        $this->assertSame('https://civi.example.org', Konfiguration::basis(), 'Auch die Adresse bleibt unveraendert.');
        $this->assertFalse(Konfiguration::schluesselGespeichert(), 'Und kein Schluessel.');

        // Die Gruppenpruefung gilt weiter - jetzt fuer den Admin.
        $csrfAdmin = $this->csrfTokenFrom($admin, '/plugin/mitglieder-konten/verwaltung');
        $ok = $admin->post('/plugin/mitglieder-konten/verwaltung/zugang', [
            'csrf_token' => $csrfAdmin, 'basis_url' => 'https://civi.example.org',
            'api_key' => 'admin-schluessel', 'team_email' => '', 'gruppe' => (string)$lese, 'typen' => '',
        ]);
        $this->assertStringContainsString('mk=gespeichert', (string)$ok->location(), "Body: {$ok->body}");

        $angriffe = [(string)$adminGruppe, (string)$schreib, (string)$stelle, (string)$gastGruppe, '999999', '-1', 'abc', '', ['1']];
        foreach ($angriffe as $wert) {
            $antwort = $admin->post('/plugin/mitglieder-konten/verwaltung/zugang', [
                'csrf_token' => $csrfAdmin, 'basis_url' => 'https://civi.example.org',
                'api_key' => '', 'team_email' => '', 'gruppe' => $wert, 'typen' => '',
            ]);
            $this->assertStringContainsString(
                'mk=gruppe-unzulaessig',
                (string)$antwort->location(),
                'Gruppe ' . json_encode($wert) . " haette abgelehnt werden muessen. Body: {$antwort->body}"
            );
        }

        Konfiguration::leereCache();
        $this->assertSame($lese, Konfiguration::gruppeId());

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

        // Naechster Lauf: CiviCRM meldet die Mitgliedschaft ausdruecklich als
        // nicht mehr laufend.
        $bericht = Abgleich::taeglicherLauf($this->attrappe([], false, [9301 => false]));
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

    // ---- Hilfen fuer die Faelle aus M5, N29, N30, N31 --------------------

    private function zugangSpeichern(HttpClient $admin, string $csrf, string $basis, string $schluessel, string $team = '', string $typen = ''): string {
        $antwort = $admin->post('/plugin/mitglieder-konten/verwaltung/zugang', [
            'csrf_token' => $csrf, 'basis_url' => $basis, 'api_key' => $schluessel,
            'team_email' => $team, 'gruppe' => '0', 'typen' => $typen,
        ]);

        return (string)$antwort->location();
    }

    /** @return array<string, string> */
    private function rohEinstellungen(): array {
        $stmt = Database::getInstance()->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, ?, ?, ?, ?)');
        $stmt->execute([Konfiguration::S_URL, Konfiguration::S_KEY, Konfiguration::S_TEAM, Konfiguration::S_TYPEN, Konfiguration::S_KEY_BINDUNG]);

        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** Lesegruppe anlegen und als Zielgruppe setzen; Team-Adresse fuer Konten ohne Adresse. */
    private function einrichten(HttpClient $admin, string $name): int {
        $this->addonAktivieren($admin);
        $gruppe = $this->leseGruppeAnlegen($admin, $name . ' ' . substr(uniqid(), -6));
        Konfiguration::speichern([Konfiguration::S_GRUPPE => (string)$gruppe, Konfiguration::S_TEAM => 'team@example.org']);
        Konfiguration::leereCache();

        return $gruppe;
    }

    /**
     * Legt Konten ohne eigene Adresse an (Zustellung ueber die
     * Mailer-Attrappe) und liefert membership_id => user_id.
     *
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function kontenAnlegen(array $ids, int $kontaktBasis): array {
        $zeilen = [];
        foreach ($ids as $i => $id) {
            $zeilen[] = $this->civiZeile($id, $kontaktBasis + $i, "Mitglied {$id}");
            $this->aufraeumen[] = (string)$id;
        }
        $ergebnis = Abgleich::anlegen($ids, $this->attrappe($zeilen), $this->mailer());
        $this->assertSame(count($ids), $ergebnis['angelegt'], implode(' | ', $ergebnis['fehler']));

        return array_intersect_key(Zuordnung::alle(), array_flip($ids));
    }

    /** @return array{deactivated_at:?string, deactivated_reason:?string, password_hash:string} */
    private function konto(string $benutzername): array {
        $stmt = Database::getInstance()->prepare('SELECT deactivated_at, deactivated_reason, password_hash FROM users WHERE username = ?');
        $stmt->execute([$benutzername]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function sperren(string $benutzername, string $grund): void {
        Database::getInstance()
            ->prepare('UPDATE users SET deactivated_at = NOW(), deactivated_reason = ? WHERE username = ?')
            ->execute([$grund, $benutzername]);
    }

    private function auditAnzahl(string $aktion): int {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ?');
        $stmt->execute([$aktion]);

        return (int)$stmt->fetchColumn();
    }

    private function letztesAudit(string $aktion): string {
        $stmt = Database::getInstance()->prepare('SELECT details FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$aktion]);

        return (string)$stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> membership_id => Zeile */
    private function vorschauNachId(CiviApi $client): array {
        $vorschau = Abgleich::vorschau($client);
        $this->assertNull($vorschau['fehler']);
        $nachId = [];
        foreach ($vorschau['zeilen'] as $z) {
            $nachId[(int)$z['membership_id']] = $z;
        }

        return $nachId;
    }

    // ---- M5: Schluessel an die Adresse gebunden ------------------------

    /**
     * Frueher ging der gespeicherte Schluessel an jede neu eingetragene
     * Adresse. Wer die Adresse aendert, muss ihn jetzt neu eingeben - und
     * bei Ablehnung wird GAR NICHTS gespeichert.
     */
    public function testEineGeaenderteAdresseVerlangtEinenNeuenSchluessel(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $csrf = $this->csrfTokenFrom($admin, '/plugin/mitglieder-konten/verwaltung');

        $this->assertStringContainsString('mk=gespeichert', $this->zugangSpeichern($admin, $csrf, 'https://civi.example.org', 'echter-schluessel', 'alt@example.org', '1'));
        $vorher = $this->rohEinstellungen();
        $this->assertSame(Konfiguration::bindung('https://civi.example.org'), $vorher[Konfiguration::S_KEY_BINDUNG]);

        $ort = $this->zugangSpeichern($admin, $csrf, 'https://angreifer.example.net', '', 'neu@example.org', '2');
        $this->assertStringContainsString('mk=schluessel-noetig', $ort);
        $this->assertSame($vorher, $this->rohEinstellungen(), 'Adresse, Schluessel, Team und Typen bleiben unveraendert.');

        // Dieselbe Adresse (Gross/Klein, Schraegstrich) ohne Schluessel ist "unveraendert".
        $this->assertStringContainsString('mk=gespeichert', $this->zugangSpeichern($admin, $csrf, 'https://CIVI.example.org/', '', 'alt@example.org', '1'));

        // Mit neuem Schluessel gelingt der Wechsel und bindet neu.
        $this->assertStringContainsString('mk=gespeichert', $this->zugangSpeichern($admin, $csrf, 'https://neu.example.net', 'neuer-schluessel'));
        Konfiguration::leereCache();
        $this->assertSame(Konfiguration::bindung('https://neu.example.net'), Konfiguration::gespeicherteBindung());
        $this->assertSame('neuer-schluessel', Konfiguration::apiKey());
    }

    /**
     * Schutz in der Tiefe: Wird die Adresse an zugang() vorbei geaendert,
     * gibt apiKey() den Schluessel nicht heraus. Und das Formular behandelt
     * den Fall NICHT als "kein Schluessel da, Adresse frei" (Rohwert statt
     * apiKey(), Regression fuer schluesselGespeichert()).
     */
    public function testEinSchluesselMitFremderBindungWirdNichtBenutzt(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $csrf = $this->csrfTokenFrom($admin, '/plugin/mitglieder-konten/verwaltung');
        $this->assertStringContainsString('mk=gespeichert', $this->zugangSpeichern($admin, $csrf, 'https://civi.example.org', 'echter-schluessel'));

        Konfiguration::speichern([Konfiguration::S_URL => 'https://boese.example.net']);
        Konfiguration::leereCache();

        $this->assertSame('', Konfiguration::apiKey());
        $this->assertFalse(Konfiguration::client()->eingerichtet());
        $this->assertTrue(Konfiguration::schluesselGespeichert());

        $this->assertStringContainsString('mk=schluessel-noetig', $this->zugangSpeichern($admin, $csrf, 'https://boese.example.net', ''));
        $this->assertStringContainsString('mk=schluessel-noetig', $this->zugangSpeichern($admin, $csrf, 'https://noch-boeser.example.net', ''));
        Konfiguration::leereCache();
        $this->assertSame('', Konfiguration::apiKey(), 'Nichts hat die fremde Bindung geheilt.');
    }

    /** Ein Schluessel aus der Zeit vor 1.1.0 hat keine Bindung - er wird an die jetzige Adresse gebunden. */
    public function testAltbestandOhneBindungWirdUebernommen(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);

        Konfiguration::speichern([
            Konfiguration::S_URL => 'https://civi.example.org',
            Konfiguration::S_KEY => (string)Crypto::encrypt('alter-schluessel'),
            Konfiguration::S_KEY_BINDUNG => '',
        ]);
        Konfiguration::leereCache();

        $this->assertSame('alter-schluessel', Konfiguration::apiKey());
        Konfiguration::leereCache();
        $this->assertSame(Konfiguration::bindung('https://civi.example.org'), Konfiguration::gespeicherteBindung(), 'Beim ersten Lesen gebunden.');

        // Auch das Formular behandelt den Altbestand wie gebunden.
        Konfiguration::speichern([Konfiguration::S_KEY_BINDUNG => '']);
        $csrf = $this->csrfTokenFrom($admin, '/plugin/mitglieder-konten/verwaltung');
        Konfiguration::speichern([Konfiguration::S_KEY_BINDUNG => '']);
        $this->assertStringContainsString('mk=schluessel-noetig', $this->zugangSpeichern($admin, $csrf, 'https://anders.example.net', ''));
        $this->assertStringContainsString('mk=gespeichert', $this->zugangSpeichern($admin, $csrf, 'https://civi.example.org', ''));
        Konfiguration::leereCache();
        $this->assertSame(Konfiguration::bindung('https://civi.example.org'), Konfiguration::gespeicherteBindung());
        $this->assertSame('alter-schluessel', Konfiguration::apiKey());
    }

    // ---- N30: Tageslauf ------------------------------------------------

    /**
     * Der Kernfall von N30: Eine leere Antwort hiess frueher "keine
     * Mitgliedschaft laeuft mehr" und sperrte den ganzen Bestand. Jetzt haelt
     * der Lauf an und wartet auf einen Admin.
     */
    public function testEineLeereAntwortSperrtNichtAlleSondernHaeltAn(): void {
        $this->einrichten($this->authenticatedClient(), 'MK leer');
        $ids = range(9801, 9812);
        $this->kontenAnlegen($ids, 800);
        $auditVorher = $this->auditAnzahl('Mitglieder-Abgleich angehalten');

        $bericht = Abgleich::taeglicherLauf($this->attrappe([]));

        $this->assertSame(0, $bericht['gesperrt']);
        $this->assertTrue($bericht['angehalten']);
        Konfiguration::leereCache();
        $angehalten = Konfiguration::angehalten();
        $this->assertSame($ids, $angehalten['sperren']['ids'] ?? null);
        $this->assertSame(10, $angehalten['sperren']['grenze']);
        $this->assertSame($auditVorher + 1, $this->auditAnzahl('Mitglieder-Abgleich angehalten'));
        foreach ($ids as $id) {
            $this->assertNull($this->konto((string)$id)['deactivated_at']);
        }

        // Ein Lauf ohne Anhalten leert den Vermerk.
        $bericht = Abgleich::taeglicherLauf($this->attrappe([], false, array_fill_keys($ids, true)));
        $this->assertFalse($bericht['angehalten']);
        Konfiguration::leereCache();
        $this->assertSame([], Konfiguration::angehalten());
    }

    public function testEineZeileOhneStatusSperrtNicht(): void {
        $this->einrichten($this->authenticatedClient(), 'MK unklar');
        $this->kontenAnlegen([9821], 821);

        $bericht = Abgleich::taeglicherLauf($this->attrappe([], false, [9821 => null]));

        $this->assertSame(0, $bericht['gesperrt']);
        $this->assertSame(1, $bericht['unklar']);
        $this->assertNull($this->konto('9821')['deactivated_at']);
    }

    /** Der Filter "Mitgliedschaftsarten" gilt nur fuer die Anlage - nicht fuer die Sperre. */
    public function testDerTypfilterBetrifftDieSperreNicht(): void {
        $this->einrichten($this->authenticatedClient(), 'MK Typ');
        $this->kontenAnlegen([9831], 831);
        Konfiguration::speichern([Konfiguration::S_TYPEN => '1']);
        Konfiguration::leereCache();

        // Die Mitgliedschaft laeuft - aber sie ist von einer anderen Art und
        // stuende deshalb NICHT in der gefilterten Liste.
        $client = $this->attrappe([], false, [9831 => true]);
        $bericht = Abgleich::taeglicherLauf($client);

        $this->assertSame(0, $bericht['gesperrt']);
        $this->assertNull($this->konto('9831')['deactivated_at']);
        $this->assertNotEmpty($client->protokoll);
        foreach ($client->protokoll as $anfrage) {
            $this->assertStringNotContainsString('membership_type_id', json_encode($anfrage, JSON_THROW_ON_ERROR));
        }
    }

    public function testEineWiederLaufendeMitgliedschaftEntsperrtDasKonto(): void {
        $this->einrichten($this->authenticatedClient(), 'MK wieder');
        $this->kontenAnlegen([9841], 841);

        $this->assertSame(1, Abgleich::taeglicherLauf($this->attrappe([], false, [9841 => false]))['gesperrt']);
        $this->assertSame('membership_ended', $this->konto('9841')['deactivated_reason']);

        $bericht = Abgleich::taeglicherLauf($this->attrappe([], false, [9841 => true]));

        $this->assertSame(1, $bericht['reaktiviert']);
        $konto = $this->konto('9841');
        $this->assertNull($konto['deactivated_at']);
        $this->assertNull($konto['deactivated_reason']);
        $vermerk = Database::getInstance()->query('SELECT gesperrt_am FROM `' . Zuordnung::TABELLE . '` WHERE membership_id = 9841')->fetchColumn();
        $this->assertNull($vermerk);
    }

    /** Sperren durch einen Admin oder die Ruhesperre hebt CiviCRM nicht auf. */
    public function testEineAdminOderRuhesperreWirdNichtAufgehoben(): void {
        $this->einrichten($this->authenticatedClient(), 'MK Admin-Sperre');
        $this->kontenAnlegen([9851, 9852], 851);
        $this->sperren('9851', 'admin');
        $this->sperren('9852', DormantAccountService::REASON_DORMANT);

        $bericht = Abgleich::taeglicherLauf($this->attrappe([], false, [9851 => true, 9852 => true]));

        $this->assertSame(0, $bericht['reaktiviert']);
        $this->assertSame('admin', $this->konto('9851')['deactivated_reason']);
        $this->assertSame(DormantAccountService::REASON_DORMANT, $this->konto('9852')['deactivated_reason']);
    }

    /** Ein Altkonto in einer schreibenden Gruppe (vor H1) holt CiviCRM nicht zurueck. */
    public function testEinKontoInSchreibenderGruppeWirdNichtAutomatischEntsperrt(): void {
        $admin = $this->authenticatedClient();
        $this->einrichten($admin, 'MK Schreib-Altkonto');
        $zuordnung = $this->kontenAnlegen([9861], 861);
        $schreib = $this->schreibGruppeAnlegen($admin, 'MK schreibend ' . substr(uniqid(), -6));
        Database::getInstance()->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)')->execute([$zuordnung[9861], $schreib]);
        $this->sperren('9861', Abgleich::GRUND);

        $bericht = Abgleich::taeglicherLauf($this->attrappe([], false, [9861 => true]));

        $this->assertSame(0, $bericht['reaktiviert']);
        $this->assertNotNull($this->konto('9861')['deactivated_at']);
        $this->assertStringContainsString((string)$zuordnung[9861], $this->letztesAudit('Mitglieder-Abgleich: Entsperren durch Admin pruefen'));
    }

    /** Die Grenze gilt in BEIDE Richtungen - eine gefaelschte Quelle koennte sonst alle Beendeten zurueckholen. */
    public function testMassenReaktivierungWirdAngehalten(): void {
        $this->einrichten($this->authenticatedClient(), 'MK Massen-Reaktivierung');
        $ids = range(9871, 9882);
        $this->kontenAnlegen($ids, 871);
        foreach ($ids as $id) {
            $this->sperren((string)$id, Abgleich::GRUND);
        }

        $bericht = Abgleich::taeglicherLauf($this->attrappe([], false, array_fill_keys($ids, true)));

        $this->assertSame(0, $bericht['reaktiviert']);
        $this->assertTrue($bericht['angehalten']);
        Konfiguration::leereCache();
        $this->assertSame($ids, Konfiguration::angehalten()['entsperren']['ids'] ?? null);
        $this->assertNotNull($this->konto('9871')['deactivated_at']);
    }

    /**
     * Die Bestaetigung gilt nur fuer die gespeicherte Menge. Waechst die
     * Antwort danach, loest "12 bestaetigen" nicht mehr aus - der Rest wird
     * wieder gegen die Grenze geprueft.
     */
    public function testBestaetigungSperrtNurDieGespeichertenIds(): void {
        $this->einrichten($this->authenticatedClient(), 'MK Bestaetigung');
        $erste = range(9701, 9712);
        $this->kontenAnlegen($erste, 700);
        $this->assertTrue(Abgleich::taeglicherLauf($this->attrappe([]))['angehalten']);

        $zweite = range(9721, 9732);
        $this->kontenAnlegen($zweite, 720);

        $bericht = Abgleich::taeglicherLauf($this->attrappe([]), true);

        $this->assertSame(12, $bericht['gesperrt']);
        $this->assertTrue($bericht['angehalten'], 'Die neuen 12 liegen ueber der Grenze und sind wieder angehalten.');
        foreach ($erste as $id) {
            $this->assertNotNull($this->konto((string)$id)['deactivated_at'], "{$id} war bestaetigt.");
        }
        foreach ($zweite as $id) {
            $this->assertNull($this->konto((string)$id)['deactivated_at'], "{$id} war NICHT bestaetigt.");
        }
        Konfiguration::leereCache();
        $this->assertSame($zweite, Konfiguration::angehalten()['sperren']['ids'] ?? null);
    }

    /** Bestaetigen darf nur ein Admin, mit CSRF-Token und fuer die angezeigte Menge. */
    public function testNurAdminsDuerfenBestaetigen(): void {
        $admin = $this->authenticatedClient();
        $this->addonAktivieren($admin);
        $x = substr(uniqid(), -6);
        $stelle = $this->leseGruppeAnlegen($admin, "Geschaeftsstelle B {$x}");
        $this->setGroupPermissions($admin, $stelle, ['mitglieder_konten' => ['manage']]);
        $this->aufraeumen[] = "gsb{$x}";
        $stelleClient = $this->createAndLoginEditor($admin, "gsb{$x}", "gsb-{$x}@example.org", [$stelle]);

        $vermerk = json_encode(['sperren' => [
            'richtung' => 'sperren', 'ids' => [9001, 9002], 'anzahl' => 2, 'grenze' => 10, 'zeit' => '2026-09-28 03:00:00',
        ]], JSON_THROW_ON_ERROR);
        Konfiguration::speichern([Konfiguration::S_ANGEHALTEN => $vermerk]);
        Konfiguration::leereCache();
        $abdruck = Konfiguration::angehaltenFingerabdruck();

        $seite = $stelleClient->get('/plugin/mitglieder-konten/verwaltung');
        $this->assertStringContainsString('Mitglieder-Abgleich angehalten', $seite->body);
        $this->assertStringContainsString('Bestätigen kann das nur ein Administrator.', $seite->body);
        $this->assertStringNotContainsString('sperren-bestaetigen', $seite->body);
        // Token von einer Seite, die die Geschaeftsstelle sehen darf - sonst
        // scheiterte der POST am CSRF-Check statt an der Rechtepruefung.
        $csrf = $this->csrfTokenFrom($stelleClient, '/plugin/mitglieder-konten/verwaltung');

        $antwort = $stelleClient->post('/plugin/mitglieder-konten/verwaltung/sperren-bestaetigen', [
            'csrf_token' => $csrf, 'angehalten' => $abdruck,
        ]);
        $this->assertSame(403, $antwort->statusCode, "Body: {$antwort->body}");

        $adminSeite = $admin->get('/plugin/mitglieder-konten/verwaltung');
        $this->assertStringContainsString('sperren-bestaetigen', $adminSeite->body);
        $this->assertStringContainsString($abdruck, $adminSeite->body);

        $ohneCsrf = $admin->post('/plugin/mitglieder-konten/verwaltung/sperren-bestaetigen', ['angehalten' => $abdruck]);
        $this->assertSame(403, $ohneCsrf->statusCode);

        $csrfAdmin = $this->currentCsrfToken($admin);
        $veraltet = $admin->post('/plugin/mitglieder-konten/verwaltung/sperren-bestaetigen', [
            'csrf_token' => $csrfAdmin, 'angehalten' => hash('sha256', 'etwas anderes'),
        ]);
        $this->assertStringContainsString('mk=angehalten-veraltet', (string)$veraltet->location());

        // Ohne eingerichteten Zugang kann der Lauf nichts pruefen - nichts
        // geaendert, der Vermerk bleibt.
        $passend = $admin->post('/plugin/mitglieder-konten/verwaltung/sperren-bestaetigen', [
            'csrf_token' => $csrfAdmin, 'angehalten' => $abdruck,
        ]);
        $this->assertStringContainsString('mk=bestaetigung-fehlgeschlagen', (string)$passend->location(), "Body: {$passend->body}");
        Konfiguration::leereCache();
        $this->assertSame($abdruck, Konfiguration::angehaltenFingerabdruck());
    }

    // ---- N31: Versandfehler --------------------------------------------

    public function testEinGescheiterterEinzelversandErscheintAlsFehler(): void {
        $this->einrichten($this->authenticatedClient(), 'MK Einzelversand');
        $x = substr(uniqid(), -6);
        $this->aufraeumen[] = '9901';
        $mailer = $this->mailer(false);

        $ergebnis = Abgleich::anlegen([9901], $this->attrappe([$this->civiZeile(9901, 901, 'Post weg', "post-{$x}@example.org")]), $mailer);

        $this->assertSame(1, $ergebnis['angelegt']);
        $this->assertCount(1, $ergebnis['fehler']);
        $this->assertStringContainsString('9901', $ergebnis['fehler'][0]);
        $this->assertArrayNotHasKey('zugangsdaten', $ergebnis);
        $this->assertNotEmpty($mailer->passwoerter);
        $audit = $this->letztesAudit('Zugangsdaten nicht zustellbar');
        $this->assertStringContainsString('9901', $audit);
        foreach ($mailer->passwoerter as $pw) {
            $this->assertStringNotContainsString($pw, $audit, 'Kein Passwort im Protokoll.');
            $this->assertStringNotContainsString($pw, implode(' ', $ergebnis['fehler']));
        }
    }

    public function testEineGescheiterteSammelmailErscheintAlsFehler(): void {
        $this->einrichten($this->authenticatedClient(), 'MK Sammelmail');
        $this->aufraeumen[] = '9911';
        $mailer = $this->mailer(true, false);

        $ergebnis = Abgleich::anlegen([9911], $this->attrappe([$this->civiZeile(9911, 911, 'Ohne Post')]), $mailer);

        $this->assertSame(['team@example.org'], $mailer->empfaenger);
        $this->assertCount(1, $ergebnis['fehler']);
        $this->assertStringContainsString('Sammelmail', $ergebnis['fehler'][0]);
        $this->assertStringContainsString('9911', $ergebnis['fehler'][0]);
        $audit = $this->letztesAudit('Zugangsdaten nicht zustellbar');
        $this->assertStringContainsString('9911', $audit);
        $this->assertNotEmpty($mailer->passwoerter);
        foreach ($mailer->passwoerter as $pw) {
            $this->assertStringNotContainsString($pw, $audit);
        }
    }

    public function testEinWerfenderMailerErscheintAlsFehler(): void {
        $this->einrichten($this->authenticatedClient(), 'MK Werfer');
        $x = substr(uniqid(), -6);
        $this->aufraeumen[] = '9921';
        $this->aufraeumen[] = '9922';

        $ergebnis = Abgleich::anlegen([9921, 9922], $this->attrappe([
            $this->civiZeile(9921, 921, 'Mit Post', "werfer-{$x}@example.org"),
            $this->civiZeile(9922, 922, 'Ohne Post'),
        ]), $this->mailer(true, true, true));

        $this->assertSame(2, $ergebnis['angelegt']);
        $this->assertCount(2, $ergebnis['fehler']);
        $this->assertStringContainsString('9921', $ergebnis['fehler'][0]);
        $this->assertStringContainsString('9922', $ergebnis['fehler'][1]);
        $this->assertStringNotContainsString('SMTP kaputt', implode(' ', $ergebnis['fehler']));
    }

    public function testFehlendeTeamAdresseErscheintAlsFehler(): void {
        $this->einrichten($this->authenticatedClient(), 'MK ohne Team');
        Konfiguration::speichern([Konfiguration::S_TEAM => '']);
        Konfiguration::leereCache();
        $this->aufraeumen[] = '9931';

        $ergebnis = Abgleich::anlegen([9931], $this->attrappe([$this->civiZeile(9931, 931, 'Niemand zustaendig')]), $this->mailer());

        $this->assertCount(1, $ergebnis['fehler']);
        $this->assertStringContainsString('Verwaltungsteam', $ergebnis['fehler'][0]);
        $this->assertStringContainsString('9931', $ergebnis['fehler'][0]);
        $this->assertStringContainsString('9931', $this->letztesAudit('Zugangsdaten nicht zustellbar'));
    }

    public function testErfolgreicherVersandMeldetKeinenFehler(): void {
        $this->einrichten($this->authenticatedClient(), 'MK Versand ok');
        $x = substr(uniqid(), -6);
        $this->aufraeumen[] = '9941';
        $this->aufraeumen[] = '9942';

        $ergebnis = Abgleich::anlegen([9941, 9942], $this->attrappe([
            $this->civiZeile(9941, 941, 'Mit Post', "ok-{$x}@example.org"),
            $this->civiZeile(9942, 942, 'Ohne Post'),
        ]), $this->mailer());

        $this->assertSame(2, $ergebnis['angelegt']);
        $this->assertSame([], $ergebnis['fehler']);
        $this->assertArrayNotHasKey('zugangsdaten', $ergebnis);
    }

    // ---- N29: belegte Adressen und Wiedereintritt ----------------------

    public function testEineBelegteAdresseBlockiertInDerVorschau(): void {
        $this->einrichten($this->authenticatedClient(), 'MK belegt');
        $x = substr(uniqid(), -6);
        Database::getInstance()
            ->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, 'fremd')")
            ->execute(["fam{$x}", "Familie-{$x}@Example.org"]);
        $this->aufraeumen[] = "fam{$x}";
        $this->aufraeumen[] = '9951';

        $client = $this->attrappe([$this->civiZeile(9951, 951, 'Kind', "familie-{$x}@example.org")]);
        $zeile = $this->vorschauNachId($client)[9951];
        $this->assertSame('blockiert', $zeile['zustand']);
        $this->assertStringContainsString('E-Mail-Adresse', $zeile['grund']);

        $ergebnis = Abgleich::anlegen([9951], $client, $this->mailer());
        $this->assertSame(0, $ergebnis['angelegt']);
        $this->assertSame(1, $ergebnis['uebersprungen']);
        $this->assertSame([], $ergebnis['fehler'], 'Kein Kernfehler - die Vorschau hat es vorher gesagt.');
    }

    public function testEineGemeinsameAdresseImSelbenStapelBlockiertDieZweite(): void {
        $this->einrichten($this->authenticatedClient(), 'MK gemeinsam');
        $x = substr(uniqid(), -6);
        $this->aufraeumen[] = '9961';
        $this->aufraeumen[] = '9962';

        // Absichtlich in umgekehrter Reihenfolge - das Ergebnis haengt an der ID, nicht an der Antwort.
        $client = $this->attrappe([
            $this->civiZeile(9962, 962, 'Zweite', "haus-{$x}@example.org"),
            $this->civiZeile(9961, 961, 'Erste', "HAUS-{$x}@example.org"),
        ]);
        $nachId = $this->vorschauNachId($client);
        $this->assertSame('neu', $nachId[9961]['zustand']);
        $this->assertSame('blockiert', $nachId[9962]['zustand']);

        $ergebnis = Abgleich::anlegen([9961, 9962], $client, $this->mailer());
        $this->assertSame(1, $ergebnis['angelegt']);
        $this->assertSame(1, $ergebnis['uebersprungen']);
        $this->assertSame([], $ergebnis['fehler']);
    }

    public function testWiedereintrittUebernimmtDasAlteKonto(): void {
        $this->einrichten($this->authenticatedClient(), 'MK Wiedereintritt');
        $x = substr(uniqid(), -6);
        $email = "zurueck-{$x}@example.org";
        $this->aufraeumen[] = '9601';
        $this->aufraeumen[] = '9650';

        Abgleich::anlegen([9601], $this->attrappe([$this->civiZeile(9601, 71, 'Kommt wieder', $email)]), $this->mailer());
        $userId = Zuordnung::alle()[9601];
        Abgleich::taeglicherLauf($this->attrappe([], false, [9601 => false]));
        $vorher = $this->konto('9601');
        $this->assertNotNull($vorher['deactivated_at']);

        // Neue Mitgliedschaft, derselbe CiviCRM-Kontakt, dieselbe Adresse.
        $client = $this->attrappe([$this->civiZeile(9650, 71, 'Kommt wieder', $email)], false, [9601 => false, 9650 => true]);
        $zeile = $this->vorschauNachId($client)[9650];
        $this->assertSame('wiedereintritt', $zeile['zustand']);
        $this->assertStringContainsString('9601', $zeile['grund']);

        $ergebnis = Abgleich::anlegen([9650], $client, $this->mailer());

        $this->assertSame(0, $ergebnis['angelegt']);
        $this->assertSame(1, $ergebnis['reaktiviert']);
        $this->assertSame([], $ergebnis['fehler']);
        $zuordnungen = Zuordnung::alle();
        $this->assertSame($userId, $zuordnungen[9650] ?? null);
        $this->assertArrayNotHasKey(9601, $zuordnungen);
        $nachher = $this->konto('9601');
        $this->assertNull($nachher['deactivated_at']);
        $this->assertSame($vorher['password_hash'], $nachher['password_hash'], 'Kein neues Passwort.');
        $this->assertSame(0, (int)Database::getInstance()->query("SELECT COUNT(*) FROM users WHERE username = '9650'")->fetchColumn());

        // Der naechste Tageslauf sperrt nicht wieder.
        $this->assertSame(0, Abgleich::taeglicherLauf($this->attrappe([], false, [9650 => true]))['gesperrt']);
        $this->assertNull($this->konto('9601')['deactivated_at']);
    }

    /**
     * Mit Typfilter fehlt eine noch laufende Mitgliedschaft einer ANDEREN Art
     * in der gefilterten Liste. Das darf nicht als Wiedereintritt gelten.
     */
    public function testWiedereintrittBeiNochLaufenderAlterMitgliedschaftAndererArt(): void {
        $this->einrichten($this->authenticatedClient(), 'MK andere Art');
        $this->kontenAnlegen([9602], 72);
        Konfiguration::speichern([Konfiguration::S_TYPEN => '2']);
        Konfiguration::leereCache();

        $client = $this->attrappe([$this->civiZeile(9651, 72, 'Zweite Art')], false, [9602 => true, 9651 => true]);
        $zeile = $this->vorschauNachId($client)[9651];

        $this->assertSame('blockiert', $zeile['zustand']);
        $this->assertStringContainsString('9602', $zeile['grund']);
        $this->assertSame(0, Abgleich::anlegen([9651], $client, $this->mailer())['reaktiviert']);
    }

    public function testWiedereintrittEntsperrtKeinAdminGesperrtesKonto(): void {
        $this->einrichten($this->authenticatedClient(), 'MK Admin-Wiedereintritt');
        $zuordnung = $this->kontenAnlegen([9603], 73);
        $this->sperren('9603', 'admin');

        $client = $this->attrappe([$this->civiZeile(9652, 73, 'Gesperrt vom Admin')], false, [9603 => false]);
        $this->assertSame('wiedereintritt', $this->vorschauNachId($client)[9652]['zustand']);

        $ergebnis = Abgleich::anlegen([9652], $client, $this->mailer());

        $this->assertSame(1, $ergebnis['reaktiviert']);
        $this->assertStringContainsString('bleibt gesperrt', implode(' ', $ergebnis['fehler']));
        $this->assertSame('admin', $this->konto('9603')['deactivated_reason']);
        $this->assertSame($zuordnung[9603], Zuordnung::alle()[9652] ?? null, 'Die Zuordnung ist umgehaengt.');
    }

    public function testWiedereintrittMitGeloeschtemAltkontoBlockiert(): void {
        $this->einrichten($this->authenticatedClient(), 'MK geloescht');
        $this->kontenAnlegen([9604], 74);
        Database::getInstance()->exec("UPDATE users SET deleted_at = NOW() WHERE username = '9604'");

        $client = $this->attrappe([$this->civiZeile(9653, 74, 'Im Papierkorb')], false, [9604 => false]);
        $zeile = $this->vorschauNachId($client)[9653];

        $this->assertSame('blockiert', $zeile['zustand']);
        $this->assertStringContainsString('gelöscht', $zeile['grund']);
    }
}
