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

        $this->aktivieren($admin, true);

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
        $this->assertStringNotContainsString('4711', $oeffentlich->body);
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
     * Framework#349 nimmt der Kern `membership_status` nicht mehr entgegen:
     * Ein POST mit dem Feld läuft durch, die Spalte bleibt NULL, und die
     * Übernahme fände nichts vor. Genau so sieht aber eine Installation aus,
     * die von v0.8 kommt - der Wert steht in der Tabelle, weil ihn jemand vor
     * dem Update eingetragen hat, und die Spalte bleibt bis zum Release nach
     * v0.9.0 stehen, damit diese Übernahme sie noch lesen kann.
     */
    private function kontaktMitBestandswert(HttpClient $admin, string $name, string $wert): int {
        $id = $this->createContact($admin, $name);

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
