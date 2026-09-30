<?php
// tests/Functional/MitgliedsstatusPluginTest.php

namespace Tests\Functional;

use App\Database;
use PDO;
use Tests\Support\HttpClient;

/**
 * End-to-End-Test für plugins/mitgliedsstatus gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Geprüft werden die vier Zusicherungen, an denen das Addon hängt
 * (Addons#132, Zuschnitt A aus dem Bericht zu Addons#130):
 *
 * 1. **Die Übernahme der Bestandswerte läuft und rät nicht.** 'Mitglied' wird
 *    abgebildet, 'Nichtmitglied NO' bleibt als Wortlaut stehen und wird als
 *    offen markiert - nicht verworfen und nicht geraten.
 * 2. **Der Marker schützt sie.** Eine zweite Aktivierung wiederholt sie nicht.
 *    Der Gegenbeweis steht in testUebernahmeLaeuftNurEinmal(): OHNE den Marker
 *    läuft sie erneut und überschreibt die Entscheidung eines Menschen. Ein
 *    Test, der nur die geschützte Richtung prüft, wäre auch dann grün, wenn es
 *    gar nichts zu schützen gäbe.
 * 3. **Fail-closed in beide Richtungen.** Die Angabe erscheint öffentlich nur,
 *    wenn der Kontakt freigegeben IST und die Gast-Gruppe das Recht
 *    `mitgliedsstatus.view` HAT. Jede der beiden Bedingungen wird einzeln
 *    weggenommen.
 * 4. **CiviCRM ist eine Verlinkung, kein Abgleich.** Eine Kennung, eine
 *    Basis-URL, ein Link - und die Kennung erscheint nie öffentlich.
 */
class MitgliedsstatusPluginTest extends FunctionalTestCase {

    use PersonStationHelper;

    private const SLUG = 'mitgliedsstatus';
    private const VERWALTUNG = '/plugin/mitgliedsstatus/verwaltung';
    private const ABSCHNITT = '🎗 Mitgliedschaft';

    /**
     * Die Rechte der Gast-Gruppe, wie database/schema.sql sie seedet. Die
     * Gruppe ist geteilter Zustand der ganzen Suite - sie muss auch nach einem
     * Fehlschlag wieder so dastehen.
     *
     * @var array<string, array<int, string>>
     */
    private const GAST_RECHTE = [
        'horses' => ['view'],
        'contacts' => ['view'],
    ];

    /**
     * Ob der Kern beim Teststart die Spalte `contacts.membership_status` noch
     * führt. Seit Framework#395 gibt es sie nicht mehr; die Übernahme prüft
     * trotzdem den Fall einer Installation, die sie noch hat. tearDown()
     * stellt den Ausgangszustand wieder her - die Datenbank ist geteilter
     * Zustand der ganzen Suite.
     */
    private bool $kernSpalteVorher = false;

    protected function setUp(): void {
        parent::setUp();
        $this->kernSpalteVorher = $this->kernSpalteDa();
    }

    protected function tearDown(): void {
        $jetzt = $this->kernSpalteDa();
        if ($this->kernSpalteVorher && !$jetzt) {
            $this->db()->exec('ALTER TABLE `contacts` ADD COLUMN `membership_status` VARCHAR(100) NULL DEFAULT NULL');
        } elseif (!$this->kernSpalteVorher && $jetzt) {
            $this->db()->exec('ALTER TABLE `contacts` DROP COLUMN `membership_status`');
        }
        parent::tearDown();
    }

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // 1. Die Kontakte entstehen VOR der Aktivierung, mit einem Wert im
        //    Freitextfeld des Kerns. Genau das ist die Ausgangslage, für die
        //    dieses Addon gebaut ist - ein Bestand, den jemand über Jahre
        //    unterschiedlich gepflegt hat.
        $klar = $this->kontaktMitBestandswert($admin, "MSKlar-{$unique}", 'Mitglied');
        $variante = $this->kontaktMitBestandswert($admin, "MSVariante-{$unique}", 'nicht-mitglied');
        $unklar = $this->kontaktMitBestandswert($admin, "MSUnklar-{$unique}", 'Nichtmitglied NO');
        // Nur Leerraum (Audit N32): TRIM() hätte den Tab durchgelassen, die
        // Zeile wäre offen gewesen und auf der Verwaltungsseite unsichtbar.
        $leerraum = $this->kontaktMitBestandswert($admin, "MSLeerraum-{$unique}", "\t");

        $this->aktivieren($admin, true);

        $this->assertNull(
            $this->zeile($leerraum),
            'Ein Wert aus nur Leerraum hat keinen Inhalt und darf nicht als offen übernommen werden.'
        );

        // 2. Übernahme: abgebildet, was sich ohne Raten abbilden lässt.
        $this->assertZeile($klar, 'mitglied', false, 'Mitglied', false);
        $this->assertZeile($variante, 'nichtmitglied', false, 'nicht-mitglied', false);

        // Der Prüfstein: 'Nichtmitglied NO' enthält sichtbar das Wort
        // 'Nichtmitglied', das 'NO' ist aber ein Länderkürzel (siehe
        // database/schema.sql im Kern). Abbilden hiesse raten.
        $this->assertZeile($unklar, 'keine_angabe', false, 'Nichtmitglied NO', true);

        // 3. Und zwar KEIN Kontakt ist durch die Übernahme öffentlich geworden -
        //    im Kern war die Angabe bedingungslos öffentlich, hier ist sie es
        //    erst nach einer Entscheidung.
        $this->assertSame(
            0,
            (int) $this->db()->query(
                'SELECT COUNT(*) FROM `plugin_mitgliedsstatus_kontakt` WHERE oeffentlich = 1'
            )->fetchColumn(),
            'Die Übernahme darf nichts öffentlich schalten.'
        );

        // 4. Dashboard-Kachel (admin.dashboard_tiles) und Verwaltungsseite.
        $this->assertStringContainsString(self::VERWALTUNG, $admin->get('/admin')->body);

        $verwaltung = $admin->get(self::VERWALTUNG);
        $this->assertSame(200, $verwaltung->statusCode);
        $this->assertStringContainsString('Übernahme der Bestandswerte', $verwaltung->body);
        $this->assertStringContainsString(
            'Nichtmitglied NO',
            $verwaltung->body,
            'Der nicht abbildbare Wortlaut gehört zur Nacharbeit auf die Verwaltungsseite.'
        );

        $gast = $this->newClient();
        $gastGruppe = $this->findBuiltinGroupId($admin, 'Gast');

        try {
            // 5. Ausgangslage: Recht da, Freigabe nicht - kein Abschnitt.
            $this->setGroupPermissions($admin, $gastGruppe, array_merge(self::GAST_RECHTE, [
                'mitgliedsstatus' => ['view'],
            ]));
            $seite = $gast->get('/kontakt?id=' . $klar);
            $this->assertSame(200, $seite->statusCode);
            $this->assertStringNotContainsString(
                self::ABSCHNITT,
                $seite->body,
                'Ohne Freigabe je Kontakt darf die Angabe nicht erscheinen - auch nicht mit dem Recht.'
            );

            // 6. Freigabe setzen (über das echte Formular im Kontaktbereich).
            $this->statusSpeichern($admin, $klar, 'mitglied', true);

            $sichtbar = $gast->get('/kontakt?id=' . $klar);
            $this->assertStringContainsString(
                self::ABSCHNITT,
                $sichtbar->body,
                'Vorbedingung: Mit Recht UND Freigabe gehört die Angabe auf die Seite - ohne diesen '
                    . 'Schritt bewiesen die Fälle davor und danach nichts.'
            );

            // Genau einmal. Der Kern löst person.detail_sections und
            // station.detail_sections bis v0.9.0 kaskadierend als Alias aus;
            // ein Addon, das sie zusätzlich registriert, bekommt seit
            // Framework#336 denselben Datensatz mehrfach.
            $this->assertSame(
                1,
                substr_count($sichtbar->body, self::ABSCHNITT),
                'Der Abschnitt steht mehrfach auf der Seite - vermutlich sind die person.*/station.*-Aliasse '
                    . 'zusätzlich registriert.'
            );

            // 7. Andere Richtung: Freigabe bleibt, Recht wird entzogen.
            $this->setGroupPermissions($admin, $gastGruppe, self::GAST_RECHTE);
            $ohneRecht = $gast->get('/kontakt?id=' . $klar);
            $this->assertSame(200, $ohneRecht->statusCode, 'Die Kontaktseite selbst bleibt erreichbar.');
            $this->assertStringNotContainsString(
                self::ABSCHNITT,
                $ohneRecht->body,
                'Ohne mitgliedsstatus.view darf die Angabe nicht erscheinen - auch nicht mit Freigabe.'
            );

            // 8. Freigabe wieder zurücknehmen, Recht wieder geben: erneut nichts.
            $this->setGroupPermissions($admin, $gastGruppe, array_merge(self::GAST_RECHTE, [
                'mitgliedsstatus' => ['view'],
            ]));
            $this->statusSpeichern($admin, $klar, 'mitglied', false);
            $this->assertStringNotContainsString(self::ABSCHNITT, $gast->get('/kontakt?id=' . $klar)->body);
        } finally {
            $this->setGroupPermissions($admin, $gastGruppe, self::GAST_RECHTE);
        }

        // 9. Nacharbeit: Ein Mensch entscheidet, was die Übernahme nicht
        //    geraten hat - für ALLE Kontakte mit exakt diesem Wortlaut.
        $zuordnung = $admin->post(self::VERWALTUNG . '/zuordnen', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'wortlaut' => 'Nichtmitglied NO',
            'status' => 'nichtmitglied',
        ]);
        $this->assertSame(self::VERWALTUNG . '?ms=zugeordnet', $zuordnung->location());
        // Der Wortlaut bleibt als Herkunftsnachweis stehen, `offen` fällt weg.
        $this->assertZeile($unklar, 'nichtmitglied', false, 'Nichtmitglied NO', false);
        $this->assertStringNotContainsString(
            'Nichtmitglied NO',
            $admin->get(self::VERWALTUNG)->body,
            'Ein zugeordneter Wortlaut gehört nicht mehr in die Liste der offenen.'
        );

        // 10. CiviCRM: Basis-URL, Kennung, Link. Und die Gegenprobe, dass eine
        //     unsinnige Basis-URL abgelehnt wird - der Wert landet in einem
        //     href auf einer Admin-Seite.
        $abgelehnt = $admin->post(self::VERWALTUNG . '/civicrm-url', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'basis_url' => 'javascript:alert(1)',
        ]);
        $this->assertSame(self::VERWALTUNG . '?ms=url-ungueltig', $abgelehnt->location());

        $gesetzt = $admin->post(self::VERWALTUNG . '/civicrm-url', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'basis_url' => 'https://crm.example.test/',
        ]);
        $this->assertSame(self::VERWALTUNG . '?ms=gespeichert', $gesetzt->location());

        $ungueltigeKennung = $admin->post('/plugin/mitgliedsstatus/kontakt/civicrm', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'kontakt_id' => (string) $klar,
            'civicrm_contact_id' => '4711x',
        ]);
        $this->assertSame(
            '/admin/contacts/edit?id=' . $klar . '&ms=civicrm-ungueltig',
            $ungueltigeKennung->location(),
            '(int)"4711x" wäre 4711 - eine Kennung muss eine Zahl SEIN, nicht sich zu einer machen lassen.'
        );

        $kennungGesetzt = $admin->post('/plugin/mitgliedsstatus/kontakt/civicrm', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'kontakt_id' => (string) $klar,
            'civicrm_contact_id' => '4711',
        ]);
        $this->assertSame('/admin/contacts/edit?id=' . $klar . '&ms=civicrm', $kennungGesetzt->location());

        $formular = $admin->get('/admin/contacts/edit?id=' . $klar);
        $this->assertStringContainsString(
            'https://crm.example.test/civicrm/contact/view?reset=1&amp;cid=4711',
            $formular->body,
            'Der Link in die CiviCRM-Instanz fehlt im Bearbeitungsformular.'
        );

        // Die Kennung eines Menschen in einem fremden System gehört nie nach
        // draussen - auch nicht, wenn der Kontakt öffentlich freigegeben ist.
        $this->statusSpeichern($admin, $klar, 'mitglied', true);
        $oeffentlich = $this->newClient()->get('/kontakt?id=' . $klar);
        // CSRF-Tokens anderer Addons auf derselben Seite (etwa das Formular
        // von kontaktanfrage) sind zufälliges Hex und enthalten ab und zu
        // "4711" - sie sind keine Ausgabe der Kennung.
        $sichtbar = (string) preg_replace('/name="csrf_token" value="[0-9a-f]*"/', '', $oeffentlich->body);
        $this->assertStringNotContainsString('4711', $sichtbar);
        $this->assertStringNotContainsString('crm.example.test', $oeffentlich->body);

        // 11. Protokoll (Framework#352). Gegengeprüft ist der Test, indem der
        //     Aufruf in Status-/Verknüpfungspfad einmal entfernt wurde - dann
        //     fehlen genau diese Zeilen.
        $eintraege = $this->protokollAktionen();
        $this->assertContains('Bestandswerte übernommen', $eintraege);
        $this->assertContains('Mitgliedsstatus gesetzt', $eintraege);
        $this->assertContains('Bestandswortlaut zugeordnet', $eintraege);
        $this->assertContains('CiviCRM-Zuordnung gesetzt', $eintraege);

        // Und nichts Personenbezogenes darin: weder der Bestandswortlaut noch
        // die CiviCRM-Kennung. `audit_logs` kennt keine Löschfrist.
        $details = (string) $this->db()->query(
            "SELECT GROUP_CONCAT(COALESCE(details, '')) FROM audit_logs WHERE category = 'mitgliedsstatus'"
        )->fetchColumn();
        $this->assertStringNotContainsString('Nichtmitglied NO', $details);
        $this->assertStringNotContainsString('4711', $details);

        // 12. Die Freitextspalte des Kerns: leeren und byte-identisch zurück.
        //
        //     Vorher ein Fall, der nach der Übernahme von Hand geändert wurde -
        //     und zwar nur in der Schreibweise. Die Kollation der Spalte
        //     (utf8mb4_unicode_ci) hielte ihn für unverändert; der Vergleich
        //     muss byte-genau sein, sonst räumt der Knopf eine Änderung weg,
        //     die niemand gesichert hat.
        $this->db()->prepare('UPDATE contacts SET membership_status = ? WHERE id = ?')
            ->execute(['NICHT-MITGLIED', $variante]);

        $leeren = $admin->post(self::VERWALTUNG . '/kern-freitext', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'aktion' => 'leeren',
        ]);
        $this->assertSame(self::VERWALTUNG . '?ms=geleert', $leeren->location());
        $this->assertNull($this->kernFreitext($klar));
        $this->assertNull($this->kernFreitext($unklar));
        $this->assertSame(
            'NICHT-MITGLIED',
            $this->kernFreitext($variante),
            'Ein nach der Übernahme von Hand geänderter Wert darf nicht geleert werden - auch dann nicht, '
                . 'wenn er sich nur in der Schreibweise von der Sicherung unterscheidet.'
        );

        $zurueck = $admin->post(self::VERWALTUNG . '/kern-freitext', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'aktion' => 'wiederherstellen',
        ]);
        $this->assertSame(self::VERWALTUNG . '?ms=wiederhergestellt', $zurueck->location());
        $this->assertSame('Mitglied', $this->kernFreitext($klar));
        $this->assertSame(
            'Nichtmitglied NO',
            $this->kernFreitext($unklar),
            'Der Rückweg muss Zeichen für Zeichen zurückgeben, was da stand - sonst wäre die Übernahme '
                . 'eine Einbahnstrasse.'
        );

        // 13. Fail-closed ohne Anmeldung: Die schreibenden Routen sind keine
        //     öffentlichen Endpunkte.
        $anonym = $this->newClient()->post('/plugin/mitgliedsstatus/kontakt/status', [
            'csrf_token' => 'egal',
            'kontakt_id' => (string) $klar,
            'status' => 'mitglied',
        ]);
        // POSITIV pruefen, nicht negativ: Ein assertNotSame() auf die
        // Erfolgs-Adresse ist auch dann erfuellt, wenn checkAuth() im
        // Konstruktor ersatzlos fehlt - der Aufruf liefe dann in den
        // CSRF-Check dahinter, der mit 403 und OHNE Location antwortet, und
        // null ist nun einmal ungleich der Erfolgs-Adresse. Der Test waere
        // gruen geblieben, obwohl die Route gar keinen Anmeldeschutz mehr
        // haette. Deshalb wird hier festgenagelt, WAS herauskommen muss.
        $this->assertSame(302, $anonym->statusCode, 'Ohne Anmeldung muss die Route auf die Anmeldung leiten.');
        $this->assertSame('/login', $anonym->location(), 'Ohne Anmeldung darf hier nichts gespeichert werden.');

        // 14. Fail-closed ohne `mitgliedsstatus.manage`: Die Verwaltungsseite
        //     ist für einen Redakteur ohne dieses Recht nicht erreichbar.
        $editor = $this->createAndLoginEditor(
            $admin,
            "msredakteur{$unique}",
            "msredakteur-{$unique}@example.test"
        );
        $this->assertSame(
            403,
            $editor->get(self::VERWALTUNG)->statusCode,
            'Ohne mitgliedsstatus.manage darf die Verwaltungsseite nicht antworten.'
        );
    }

    /**
     * Der Marker - in beide Richtungen geprüft.
     *
     * WARUM DIE ZWEITE RICHTUNG DAZUGEHÖRT: Ein Test, der nur zeigt "nach der
     * zweiten Aktivierung steht der Wert noch da", ist auch dann grün, wenn
     * die Übernahme gar nichts täte. Erst der Lauf OHNE Marker beweist, dass
     * sie überhaupt läuft - und damit, dass der Marker etwas verhindert.
     *
     * Der geprüfte Schaden ist konkret: Ein Mensch hat 'Nichtmitglied NO'
     * entschieden. Ein zweiter Übernahmelauf setzt genau das auf den
     * Altstand zurück, aus dem die Frage kam.
     */
    public function testUebernahmeLaeuftNurEinmal(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $this->aktivieren($admin, true);

        // Ein Kontakt, der NACH der Übernahme entsteht - er hat deshalb keine
        // Zeile im Addon, obwohl das Freitextfeld des Kerns befüllt ist.
        $spaet = $this->kontaktMitBestandswert($admin, "MSSpaet-{$unique}", 'Mitglied');
        $this->assertNull($this->zeile($spaet), 'Vorbedingung: für diesen Kontakt gibt es noch keine Zeile.');

        // Und ein Kontakt mit einem unklaren Wortlaut, den ein Mensch von Hand
        // entscheidet.
        $entschieden = $this->kontaktMitBestandswert($admin, "MSEntschieden-{$unique}", 'Nichtmitglied NO');
        $this->statusSpeichern($admin, $entschieden, 'nichtmitglied', false);
        $this->assertZeile($entschieden, 'nichtmitglied', false, '', false);

        // (a) Mit Marker: Aus- und wieder Einschalten ruft install() erneut
        //     auf - die Übernahme läuft trotzdem nicht.
        $markerVorher = $this->marker();
        $this->assertNotNull($markerVorher, 'Nach der ersten Aktivierung muss der Marker stehen.');

        $this->aktivieren($admin, false);
        $this->aktivieren($admin, true);

        $this->assertNull(
            $this->zeile($spaet),
            'Mit gesetztem Marker darf die Übernahme nicht erneut laufen.'
        );
        $this->assertSame($markerVorher, $this->marker(), 'Der Marker darf sich dabei nicht ändern.');

        // (b) Gegenprobe: Marker weg, dieselbe Aus-/Einschaltfolge - jetzt
        //     läuft sie, und sie überschreibt die Entscheidung des Menschen.
        //     Genau davor schützt der Marker.
        $this->markerLoeschen();
        $this->aktivieren($admin, false);
        $this->aktivieren($admin, true);

        $this->assertNotNull(
            $this->zeile($spaet),
            'Ohne Marker MUSS die Übernahme erneut laufen - sonst prüft Fall (a) nichts.'
        );
        $this->assertZeile($spaet, 'mitglied', false, 'Mitglied', false);
        $this->assertZeile(
            $entschieden,
            'keine_angabe',
            false,
            'Nichtmitglied NO',
            true
        );
        $this->assertNotNull($this->marker(), 'Der zweite Lauf setzt den Marker wieder.');
    }

    /**
     * Der Kern führt die Spalte nicht mehr (Framework#395). Die Übernahme hat
     * dann dauerhaft nichts zu tun und MUSS das festhalten - sonst suchte
     * später jemand nach einer Übernahme, die nie kommen kann. Und der
     * Knopf für die Freitextspalte darf nicht auf eine fehlende Spalte
     * schreiben.
     */
    public function testOhneKernSpalteSchliesstDieUebernahmeAb(): void {
        $admin = $this->authenticatedClient();

        $this->aktivieren($admin, true);
        $this->aktivieren($admin, false);
        $this->markerLoeschen();
        if ($this->kernSpalteDa()) {
            $this->db()->exec('ALTER TABLE `contacts` DROP COLUMN `membership_status`');
        }

        $this->aktivieren($admin, true);

        $marker = json_decode((string) $this->marker(), true);
        $this->assertIsArray($marker, 'Auch ohne Spalte muss die Übernahme ihren Marker setzen.');
        $this->assertSame('keine-spalte', $marker['grund'] ?? null);
        $this->assertSame(0, $marker['gesamt'] ?? null);

        $antwort = $admin->post(self::VERWALTUNG . '/kern-freitext', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'aktion' => 'leeren',
        ]);
        $this->assertSame(self::VERWALTUNG . '?ms=keine-spalte', $antwort->location());

        $seite = $admin->get(self::VERWALTUNG);
        $this->assertSame(200, $seite->statusCode);
    }

    /**
     * Audit N32: Anzeige (GROUP BY) und Zuordnen (WHERE) wenden dieselbe
     * Randleerraum-Regel an. Vorher gruppierte die Liste roh und das Zuordnen
     * verglich die gekürzte Eingabe roh - ' X' und 'X\t' standen in der
     * Liste, liessen sich aber nie zuordnen.
     *
     * Die Gross/Klein-Variante sichert die Annahme ab, dass REGEXP_REPLACE die
     * Kollation der Spalte behält: Sie muss weiter mit in dieselbe Gruppe
     * fallen und mit zugeordnet werden.
     */
    public function testZuordnenFasstWortlauteMitRandleerraumZusammen(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $this->aktivieren($admin, true);

        // Eindeutiger Wortlaut: Andere Tests lassen 'Nichtmitglied NO' offen
        // stehen, die Zählung hier soll nur die eigenen Zeilen sehen.
        $wortlaut = "Nichtmitglied NO-{$unique}";
        $varianten = [
            ' ' . $wortlaut,
            $wortlaut . "\t",
            $wortlaut . "\r\n",
            mb_strtolower($wortlaut, 'UTF-8'),
        ];

        $ids = [];
        foreach ($varianten as $i => $altwert) {
            $id = $this->createContact($admin, "MSRand{$i}-{$unique}");
            $this->db()->prepare(
                "INSERT INTO `plugin_mitgliedsstatus_kontakt` (contact_id, status, oeffentlich, altwert, offen, geaendert_von)
                 VALUES (?, 'keine_angabe', 0, ?, 1, 'Test')"
            )->execute([$id, $altwert]);
            $ids[$id] = $altwert;
        }

        $seite = $admin->get(self::VERWALTUNG)->body;
        $muster = '/<strong>' . preg_quote(htmlspecialchars($wortlaut, ENT_QUOTES, 'UTF-8'), '/')
            . '<\/strong> <span[^>]*>\((\d+) Kontakte\)/iu';
        $this->assertSame(
            1,
            preg_match_all($muster, $seite, $treffer),
            'Die vier Varianten müssen als EINE Zeile erscheinen.'
        );
        $this->assertSame('4', $treffer[1][0], 'Die Zeile muss alle vier Kontakte zählen.');

        preg_match_all('/name="wortlaut" value="([^"]*)"/u', $seite, $felder);
        $eigene = array_values(array_filter(
            $felder[1],
            static fn(string $v): bool => mb_stripos($v, $unique) !== false
        ));
        $this->assertCount(1, $eigene);
        $this->assertSame(
            trim($eigene[0]),
            $eigene[0],
            'Das hidden-Feld darf keinen Randleerraum tragen - sonst verändert ihn der Browser unterwegs.'
        );

        $antwort = $admin->post(self::VERWALTUNG . '/zuordnen', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'wortlaut' => $wortlaut,
            'status' => 'nichtmitglied',
        ]);
        $this->assertSame(self::VERWALTUNG . '?ms=zugeordnet', $antwort->location());

        foreach ($ids as $id => $altwert) {
            $zeile = $this->zeile($id);
            $this->assertNotNull($zeile);
            $this->assertSame(0, (int) $zeile['offen'], 'Variante ' . json_encode($altwert) . ' ist noch offen.');
            $this->assertSame('nichtmitglied', (string) $zeile['status']);

            $hex = $this->db()->prepare('SELECT HEX(altwert) FROM `plugin_mitgliedsstatus_kontakt` WHERE contact_id = ?');
            $hex->execute([$id]);
            $this->assertSame(
                strtoupper(bin2hex($altwert)),
                (string) $hex->fetchColumn(),
                'Der gesicherte Wortlaut muss byte-genau erhalten bleiben.'
            );
        }
    }

    /**
     * Audit N78: Direkter Sprung aus v0.7. `contacts` hatte die Spalte nie,
     * die Werte stehen nur im stillgelegten Altbestand `persons_pre_contacts`,
     * und der Kern-Schritt 395 hat keinen Marker gesetzt. Dann ist der
     * Altbestand die Quelle.
     *
     * Ausgenommen: reiner Leerraum, IDs ohne Kontakt (gelöscht/zusammengeführt)
     * und vom Kern anonymisierte Kontakte.
     */
    public function testUebernimmtAusV07Altbestand(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $f = $this->sprungFixture($admin, $unique);

        $marker395 = $this->marker395();
        try {
            $this->marker395Setzen(null);
            $this->altbestandAnlegen($f['altbestand']);

            $this->aktivieren($admin, true);

            $marker = json_decode((string) $this->marker(), true);
            $this->assertIsArray($marker);
            $this->assertSame('persons_pre_contacts', $marker['quelle'] ?? null, 'Der Bericht muss die Quelle nennen.');
            $this->assertArrayNotHasKey('grund', $marker);
            $this->assertSame(2, $marker['gesamt'] ?? null);
            $this->assertSame(1, $marker['zugeordnet'] ?? null);
            $this->assertSame(1, $marker['offen'] ?? null);
            $this->assertSame(0, $marker['bestand'] ?? null);

            $this->assertZeile($f['a'], 'mitglied', false, 'Mitglied', false);
            $this->assertZeile($f['b'], 'keine_angabe', false, 'Nichtmitglied NO', true);
            $this->assertNull($this->zeile($f['c']), 'Ein anonymisierter Kontakt darf nichts zurückbekommen.');
            $this->assertNull($this->zeile($f['d']), 'Reiner Leerraum ist kein Wortlaut.');
            $this->assertSame(
                0,
                (int) $this->db()->query(
                    'SELECT COUNT(*) FROM `plugin_mitgliedsstatus_kontakt` WHERE contact_id = ' . $f['fremd']
                )->fetchColumn(),
                'Eine ID ohne Kontakt darf keine Zeile erzeugen.'
            );

            $seite = $admin->get(self::VERWALTUNG)->body;
            $this->assertStringContainsString('stillgelegten Altbestand der v0.7', $seite);
            $this->assertStringContainsString('Nichtmitglied NO', $seite, 'Der offene Wortlaut gehört in die Liste.');

            $this->assertContains('Bestandswerte übernommen', $this->protokollAktionen());
        } finally {
            $this->altbestandEntfernen();
            $this->marker395Setzen($marker395);
        }
    }

    /**
     * Gegenprobe zu testUebernimmtAusV07Altbestand(): Steht der 395-Marker,
     * lief die Instanz über 0.8/0.9 und hatte die Spalte in `contacts`.
     * `persons_pre_contacts` ist dann der Stand der #336-Übernahme und
     * womöglich überholt - nichts davon darf stumm zurückkommen.
     */
    public function testAltbestandNachSchritt395WirdNichtUebernommen(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $f = $this->sprungFixture($admin, $unique);

        $marker395 = $this->marker395();
        try {
            $this->marker395Setzen(gmdate('c'));
            $this->altbestandAnlegen($f['altbestand']);

            $this->aktivieren($admin, true);

            $marker = json_decode((string) $this->marker(), true);
            $this->assertIsArray($marker);
            $this->assertSame('keine-spalte', $marker['grund'] ?? null);
            $this->assertArrayNotHasKey('quelle', $marker);
            $this->assertNull($this->zeile($f['a']));
            $this->assertNull($this->zeile($f['b']));
        } finally {
            $this->altbestandEntfernen();
            $this->marker395Setzen($marker395);
        }
    }

    /**
     * Wer nach dem Sprung noch 1.0.0 installiert hatte, trägt den Marker
     * "keine-spalte" ohne `quelle`. 1.1.0 holt die Übernahme dann EINMAL nach
     * - sonst liefe der Kern-Hinweis "auf ≥ 1.1.0 aktualisieren" ins Leere.
     * Dabei wird nur eingefügt: Eine von Hand gepflegte Zeile bleibt.
     *
     * Danach blockiert der Marker mit `quelle` jedes weitere Nachholen
     * (testUebernahmeLaeuftNurEinmal für die zweite Quelle).
     */
    public function testKeineSpalteMarkerAus100WirdAusAltbestandNachgeholt(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $f = $this->sprungFixture($admin, $unique);

        $marker395 = $this->marker395();
        try {
            $this->marker395Setzen(null);
            $this->altbestandAnlegen($f['altbestand']);

            // Der Zustand nach 1.0.0: Marker "keine-spalte" ohne quelle, und
            // A inzwischen von Hand gepflegt.
            $this->db()->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('plugin_mitgliedsstatus_uebernahme', ?)"
            )->execute([json_encode(
                ['zeitpunkt' => date('c'), 'gesamt' => 0, 'zugeordnet' => 0, 'offen' => 0, 'grund' => 'keine-spalte']
            )]);
            $this->db()->prepare(
                "INSERT INTO `plugin_mitgliedsstatus_kontakt` (contact_id, status, oeffentlich, offen, geaendert_von)
                 VALUES (?, 'nichtmitglied', 1, 0, 'Hand')"
            )->execute([$f['a']]);

            $this->aktivieren($admin, true);

            $this->assertZeile($f['a'], 'nichtmitglied', true, '', false);
            $this->assertZeile($f['b'], 'keine_angabe', false, 'Nichtmitglied NO', true);

            $marker = json_decode((string) $this->marker(), true);
            $this->assertIsArray($marker);
            $this->assertSame('persons_pre_contacts', $marker['quelle'] ?? null);
            $this->assertSame(1, $marker['bestand'] ?? null);
            $this->assertSame(2, $marker['gesamt'] ?? null);
            $this->assertSame(1, $marker['offen'] ?? null);
            $this->assertSame(0, $marker['zugeordnet'] ?? null);

            // Nur einmal: B von Hand entscheiden, A's Zeile entfernen. Ein
            // erneuter Lauf würde A wieder einfügen - der Marker mit quelle
            // muss das verhindern, und B bleibt, wie entschieden.
            $this->statusSpeichern($admin, $f['b'], 'nichtmitglied', false);
            $this->db()->prepare('DELETE FROM `plugin_mitgliedsstatus_kontakt` WHERE contact_id = ?')
                ->execute([$f['a']]);
            $markerVorher = $this->marker();

            $this->aktivieren($admin, false);
            $this->aktivieren($admin, true);

            $this->assertNull($this->zeile($f['a']), 'Mit quelle im Marker darf nichts nachgeholt werden.');
            $this->assertZeile($f['b'], 'nichtmitglied', false, 'Nichtmitglied NO', false);
            $this->assertSame($markerVorher, $this->marker());
        } finally {
            $this->altbestandEntfernen();
            $this->marker395Setzen($marker395);
        }
    }

    /**
     * Kern-Hooks contact.merged, contact.anonymized, contact.erased
     * (Framework#474 Audit M33, Framework#476 Audit N45).
     *
     * Der Fremdschlüssel mit CASCADE greift nur beim endgültigen Löschen.
     * Beim Anonymisieren blieb die CiviCRM-Zuordnung am Datensatz - über das
     * Fremdsystem war der „anonymisierte“ Mensch wieder zu finden. Beim
     * Zusammenführen blieben Status und Zuordnung an der Quelle im Papierkorb
     * und gingen beim Leeren verloren.
     *
     *  1. Ziel ohne Angaben: Status und Zuordnung kommen von der Quelle, die
     *     öffentliche Freigabe NICHT.
     *  2. Ziel mit eigenen Angaben: Das Ziel gewinnt, nichts wird
     *     überschrieben, das Protokoll nennt den Konflikt.
     *  3. Anonymisieren: Status und Zuordnung sind weg.
     *  4. Endgültig löschen (DSGVO): nichts bleibt liegen.
     */
    public function testKontaktereignisseDesKernsZiehenStatusUndCiviCrmNach(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $this->aktivieren($admin, true);
        $db = $this->db();

        $civi = static function (int $kontaktId) use ($db): ?int {
            $stmt = $db->prepare('SELECT civicrm_contact_id FROM `plugin_mitgliedsstatus_civicrm` WHERE contact_id = ?');
            $stmt->execute([$kontaktId]);
            $wert = $stmt->fetchColumn();
            return $wert === false ? null : (int) $wert;
        };
        $civiSetzen = static function (int $kontaktId, int $kennung) use ($db): void {
            $db->prepare("INSERT INTO `plugin_mitgliedsstatus_civicrm` (contact_id, civicrm_contact_id, geaendert_von) VALUES (?, ?, 'Test')")
                ->execute([$kontaktId, $kennung]);
        };
        $zusammenfuehren = function (int $quelle, int $ziel) use ($admin): void {
            $antwort = $admin->post('/admin/contacts/merge', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'source_id' => (string) $quelle,
                'target_id' => (string) $ziel,
            ]);
            $this->assertStringStartsWith('/admin/contacts?success=merged', (string) $antwort->location(), $antwort->body);
        };
        $protokoll = $db->prepare(
            "SELECT action, details FROM audit_logs WHERE category = 'mitgliedsstatus' AND details LIKE ? ORDER BY id"
        );

        // 1. Ziel ohne Angaben.
        $quelle = $this->createContact($admin, "MSQuelle-{$unique}");
        $ziel = $this->createContact($admin, "MSZiel-{$unique}");
        $this->statusSpeichern($admin, $quelle, 'mitglied', true);
        $civiSetzen($quelle, 4711);

        $zusammenfuehren($quelle, $ziel);
        $this->assertZeile($ziel, 'mitglied', false, '', false);
        $this->assertSame(4711, $civi($ziel), 'Die CiviCRM-Zuordnung wandert zum behaltenen Kontakt.');
        $this->assertNull($civi($quelle), 'Eine CiviCRM-Kennung gehört zu genau einem Kontakt.');
        $protokoll->execute(["Kontakt #{$quelle} -> #{$ziel} - %"]);
        $zeilen = $protokoll->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $zeilen);
        $this->assertStringContainsString('Mitgliedsstatus: uebernommen; CiviCRM-Zuordnung: uebernommen', $zeilen[0]['details']);
        $this->assertStringNotContainsString('4711', $zeilen[0]['details'], 'Die CiviCRM-Kennung gehört nie ins Protokoll.');

        // 2. Ziel mit eigenen Angaben: Das Ziel gewinnt.
        $quelle2 = $this->createContact($admin, "MSQuelle2-{$unique}");
        $ziel2 = $this->createContact($admin, "MSZiel2-{$unique}");
        $this->statusSpeichern($admin, $quelle2, 'mitglied', false);
        $this->statusSpeichern($admin, $ziel2, 'nichtmitglied', true);
        $civiSetzen($quelle2, 43);
        $civiSetzen($ziel2, 42);

        $zusammenfuehren($quelle2, $ziel2);
        $this->assertZeile($ziel2, 'nichtmitglied', true, '', false);
        $this->assertSame(42, $civi($ziel2));
        $protokoll->execute(["Kontakt #{$quelle2} -> #{$ziel2} - %"]);
        $zeilen = $protokoll->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $zeilen);
        $this->assertStringContainsString('Mitgliedsstatus: konflikt; CiviCRM-Zuordnung: konflikt', $zeilen[0]['details']);

        // 3. Anonymisieren: kein CASCADE, das Addon räumt selbst.
        $anon = $admin->post('/admin/gdpr/anonymize-person', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'person_id' => (string) $ziel,
            'request_id' => '0',
        ]);
        $this->assertSame("/admin/gdpr?success=anonymized&person_id={$ziel}", $anon->location(), $anon->body);
        $this->assertNull($this->zeile($ziel), 'Nach der Anonymisierung darf kein Mitgliedsstatus am Kontakt hängen.');
        $this->assertNull($civi($ziel), 'Nach der Anonymisierung darf keine CiviCRM-Zuordnung am Kontakt hängen.');
        $protokoll->execute(["Kontakt #{$ziel} - Anlass: anonymisiert;%"]);
        $this->assertSame(
            ['Kontakt anonymisiert: Mitgliedsstatus und CiviCRM-Zuordnung entfernt'],
            array_column($protokoll->fetchAll(PDO::FETCH_ASSOC), 'action')
        );

        // 4. Endgültig löschen (DSGVO).
        $loeschen = $admin->post('/admin/gdpr/delete-person', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'person_id' => (string) $ziel2,
            'request_id' => '0',
        ]);
        $this->assertSame("/admin/gdpr?success=deleted&person_id={$ziel2}", $loeschen->location(), $loeschen->body);
        $this->assertNull($this->zeile($ziel2));
        $this->assertNull($civi($ziel2));
    }

    // ------------------------------------------------------------------
    // Helfer
    // ------------------------------------------------------------------

    private function db(): PDO {
        return Database::getInstance();
    }

    /**
     * Ein Kontakt mit einem BESTANDSWERT im Freitextfeld des Kerns - die
     * Ausgangslage, für die dieses Addon gebaut ist.
     *
     * Der Wert geht direkt in die Spalte, nicht durch das Formular. Seit
     * Framework#349 nimmt der Kern `membership_status` nicht mehr entgegen,
     * seit Framework#395 gibt es die Spalte gar nicht mehr. Nachgestellt wird
     * eine Installation, die sie noch führt - der Wert steht in der Tabelle,
     * weil ihn jemand vor dem Update eingetragen hat. Fehlt die Spalte, legt
     * dieser Helfer sie an; tearDown() nimmt sie wieder weg.
     */
    private function kontaktMitBestandswert(HttpClient $admin, string $name, string $wert): int {
        $id = $this->createContact($admin, $name);

        if (!$this->kernSpalteDa()) {
            $this->db()->exec('ALTER TABLE `contacts` ADD COLUMN `membership_status` VARCHAR(100) NULL DEFAULT NULL');
        }

        $this->db()->prepare('UPDATE contacts SET membership_status = ? WHERE id = ?')
            ->execute([$wert, $id]);
        $this->assertSame($wert, $this->kernFreitext($id), "Bestandswert fuer '{$name}' wurde nicht gesetzt.");

        return $id;
    }

    private function aktivieren(HttpClient $admin, bool $an): void {
        $antwort = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => $an ? '1' : '0',
        ]);
        $this->assertSame(
            '/admin/plugins?success=1',
            $antwort->location(),
            ($an ? 'Aktivieren' : 'Deaktivieren') . " von '" . self::SLUG . "' fehlgeschlagen, Body: {$antwort->body}"
        );
    }

    private function statusSpeichern(HttpClient $admin, int $kontaktId, string $status, bool $oeffentlich): void {
        $felder = [
            'csrf_token' => $this->currentCsrfToken($admin),
            'kontakt_id' => (string) $kontaktId,
            'status' => $status,
        ];
        if ($oeffentlich) {
            $felder['oeffentlich'] = '1';
        }

        $antwort = $admin->post('/plugin/mitgliedsstatus/kontakt/status', $felder);
        $this->assertSame(
            '/admin/contacts/edit?id=' . $kontaktId . '&ms=status',
            $antwort->location(),
            "Speichern des Mitgliedsstatus fehlgeschlagen, Body: {$antwort->body}"
        );
    }

    /** @return array<string, mixed>|null */
    private function zeile(int $kontaktId): ?array {
        $stmt = $this->db()->prepare(
            'SELECT status, oeffentlich, altwert, offen FROM `plugin_mitgliedsstatus_kontakt` WHERE contact_id = ?'
        );
        $stmt->execute([$kontaktId]);
        $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($zeile) ? $zeile : null;
    }

    private function assertZeile(
        int $kontaktId,
        string $status,
        bool $oeffentlich,
        string $altwert,
        bool $offen
    ): void {
        $zeile = $this->zeile($kontaktId);
        $this->assertNotNull($zeile, "Für Kontakt #{$kontaktId} fehlt die Zeile im Addon.");
        $this->assertSame($status, (string) $zeile['status'], "Status von Kontakt #{$kontaktId}");
        $this->assertSame($oeffentlich ? 1 : 0, (int) $zeile['oeffentlich'], "Freigabe von Kontakt #{$kontaktId}");
        $this->assertSame($altwert, (string) ($zeile['altwert'] ?? ''), "Bestandswortlaut von Kontakt #{$kontaktId}");
        $this->assertSame($offen ? 1 : 0, (int) $zeile['offen'], "Offen-Kennzeichen von Kontakt #{$kontaktId}");
    }

    /**
     * Der Sprungzustand aus v0.7: Addon aus, kein Marker, `contacts` ohne
     * Spalte. Dazu fünf Altbestandszeilen:
     *  a 'Mitglied', b 'Nichtmitglied NO', c anonymisiert, d nur Tab,
     *  fremd: eine ID ohne Kontakt.
     *
     * @return array{a:int, b:int, c:int, d:int, fremd:int, altbestand: array<int, string>}
     */
    private function sprungFixture(HttpClient $admin, string $unique): array {
        $this->aktivieren($admin, true);
        $this->aktivieren($admin, false);
        $this->markerLoeschen();
        if ($this->kernSpalteDa()) {
            $this->db()->exec('ALTER TABLE `contacts` DROP COLUMN `membership_status`');
        }

        $a = $this->createContact($admin, "MSAltA-{$unique}");
        $b = $this->createContact($admin, "MSAltB-{$unique}");
        $c = $this->createContact($admin, "MSAltC-{$unique}");
        $d = $this->createContact($admin, "MSAltD-{$unique}");
        // Exakt der Wortlaut aus GdprController::anonymizePerson() im Kern.
        $this->db()->prepare('UPDATE contacts SET name = ? WHERE id = ?')
            ->execute(['Anonymisierte Person (#' . $c . ')', $c]);
        $fremd = (int) $this->db()->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM contacts')->fetchColumn();

        return [
            'a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'fremd' => $fremd,
            'altbestand' => [
                $a => 'Mitglied',
                $b => 'Nichtmitglied NO',
                $c => 'Mitglied',
                $d => "\t",
                $fremd => 'Mitglied',
            ],
        ];
    }

    /** @param array<int, string> $werte ID => membership_status */
    private function altbestandAnlegen(array $werte): void {
        $this->assertSame(
            0,
            $this->db()->query("SHOW TABLES LIKE 'persons_pre_contacts'")->rowCount(),
            'Vorbedingung: Die Testinstanz hat keinen eigenen Altbestand.'
        );
        $this->db()->exec(
            'CREATE TABLE `persons_pre_contacts` (
                `id` INT NOT NULL PRIMARY KEY,
                `name` VARCHAR(100) NULL,
                `membership_status` VARCHAR(100) NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $einfuegen = $this->db()->prepare(
            'INSERT INTO `persons_pre_contacts` (id, name, membership_status) VALUES (?, ?, ?)'
        );
        foreach ($werte as $id => $wert) {
            $einfuegen->execute([$id, "Alt #{$id}", $wert]);
        }
    }

    private function altbestandEntfernen(): void {
        $this->db()->exec('DROP TABLE IF EXISTS `persons_pre_contacts`');
    }

    private function marker395(): ?string {
        $stmt = $this->db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute(['migration_395_membership_status_faellt']);
        $wert = $stmt->fetchColumn();
        return $wert === false ? null : (string) $wert;
    }

    private function marker395Setzen(?string $wert): void {
        $this->db()->prepare('DELETE FROM settings WHERE setting_key = ?')
            ->execute(['migration_395_membership_status_faellt']);
        if ($wert !== null) {
            $this->db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')
                ->execute(['migration_395_membership_status_faellt', $wert]);
        }
    }

    private function marker(): ?string {
        $stmt = $this->db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute(['plugin_mitgliedsstatus_uebernahme']);
        $wert = $stmt->fetchColumn();
        return $wert === false ? null : (string) $wert;
    }

    private function markerLoeschen(): void {
        $this->db()->prepare('DELETE FROM settings WHERE setting_key = ?')
            ->execute(['plugin_mitgliedsstatus_uebernahme']);
    }

    private function kernSpalteDa(): bool {
        $stmt = $this->db()->query("SHOW COLUMNS FROM `contacts` LIKE 'membership_status'");
        return $stmt !== false && $stmt->fetch() !== false;
    }

    private function kernFreitext(int $kontaktId): ?string {
        $stmt = $this->db()->prepare('SELECT membership_status FROM contacts WHERE id = ?');
        $stmt->execute([$kontaktId]);
        $wert = $stmt->fetchColumn();
        return ($wert === false || $wert === null) ? null : (string) $wert;
    }

    /** @return array<int, string> */
    private function protokollAktionen(): array {
        $spalten = $this->db()
            ->query("SELECT DISTINCT action FROM audit_logs WHERE category = 'mitgliedsstatus'")
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', is_array($spalten) ? $spalten : []);
    }
}
