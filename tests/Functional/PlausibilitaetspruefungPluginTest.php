<?php
// tests/Functional/PlausibilitaetspruefungPluginTest.php

namespace Tests\Functional;

use App\Database;
use PDO;
use Tests\Support\HttpClient;

/**
 * End-to-End-Test für plugins/plausibilitaetspruefung (Addons#114) gegen eine
 * echte, per `php -S` gestartete Hengstverzeichnis_Framework-Instanz.
 *
 * WARUM DIESER TEST DIE WIDERSPRÜCHE PER SQL ANLEGT UND NICHT ÜBER DAS
 * FORMULAR. Weil er sie über das Formular gar nicht anlegen KANN: Der Kern
 * weist einen jüngeren Elternteil (pedigreeContradiction, #298) und einen
 * Zeitraum nach dem Todesjahr (personPeriodAfterDeath, #334) beim Speichern
 * ab. Genau das ist die Lage, für die es dieses Addon gibt - der Altbestand
 * ist nie durch dieses Formular gelaufen. Der direkte DB-Zugriff aus dem
 * PHPUnit-Prozess ist derselbe, den FunctionalTestCase für den
 * TOTP-Replay-Schutz nutzt (DB_*-Konstanten, siehe tests/bootstrap.php).
 */
class PlausibilitaetspruefungPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'plausibilitaetspruefung';
    private const BERICHT = '/plugin/plausibilitaetspruefung/bericht';
    private const ABHAKEN = '/plugin/plausibilitaetspruefung/abhaken';
    private const ZURUECKNEHMEN = '/plugin/plausibilitaetspruefung/zuruecknehmen';

    public function testVollstaendigerAblauf(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        // ------------------------------------------------------------------
        // 1. Aktivieren. Schlägt hier die Kompatibilitätsprüfung des Manifests
        //    fehl, kommt das Addon gar nicht erst zum Laufen - deshalb steht
        //    die Zusicherung am Anfang und nicht als Nebenbefund.
        // ------------------------------------------------------------------
        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Plausibilitätsprüfung', $pluginsPage->body);
        $this->assertStringNotContainsString(
            'Inkompatibel',
            $this->pluginZeile($pluginsPage->body),
            'Das Manifest passt nicht zur laufenden Kern-Version.'
        );

        $toggle = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $toggle->location());

        // ------------------------------------------------------------------
        // 2. Kachel und Bericht sind da (Admin hat serverseitig alle Rechte).
        // ------------------------------------------------------------------
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString(self::BERICHT, $dashboard->body, 'Dashboard-Kachel fehlt.');

        $bericht = $admin->get(self::BERICHT);
        $this->assertSame(200, $bericht->statusCode);
        $this->assertStringContainsString('Plausibilitätsprüfung', $bericht->body);
        // Jede Regel bekommt eine Karte, auch wenn sie gerade nichts findet -
        // eine Liste, die nur zeigt, was gerade brennt, sagt nicht, wonach
        // überhaupt gesucht wird.
        $this->assertStringContainsString('Elternteil jünger als das Fohlen', $bericht->body);
        $this->assertStringContainsString('Keine Lebensnummer erfasst', $bericht->body);

        // ------------------------------------------------------------------
        // 3. Ein sauberes Pferd lässt sich veröffentlichen - die Gegenprobe
        //    zuerst, sonst bewiese Schritt 5 nur, dass die Route nicht tut.
        // ------------------------------------------------------------------
        $sauberId = $this->createHorse($admin, "PPSauber-{$unique}", [
            'birth_year' => '2012',
            'ueln' => "DE-PP-{$unique}",
            'sex' => 'stallion',
            'is_published' => '0',
        ]);
        $this->veroeffentlichen($admin, $sauberId);
        $this->assertSame(1, $this->istVeroeffentlicht($sauberId), 'Ein Datensatz ohne Widerspruch muss durchgehen.');

        // ------------------------------------------------------------------
        // 4. Der Widerspruch: ein Vater, der jünger ist als sein Fohlen.
        //    Über das Formular nicht anlegbar (siehe Klassen-Docblock).
        // ------------------------------------------------------------------
        $vaterId = $this->createHorse($admin, "PPVater-{$unique}", [
            'birth_year' => '2015',
            'sex' => 'stallion',
            'ueln' => "DE-PPV-{$unique}",
            'is_published' => '0',
        ]);
        $fohlenId = $this->createHorse($admin, "PPFohlen-{$unique}", [
            'birth_year' => '2010',
            'sex' => 'mare',
            'ueln' => "DE-PPF-{$unique}",
            'is_published' => '0',
        ]);
        $this->db()->prepare('UPDATE horses SET sire_id = ? WHERE id = ?')->execute([$vaterId, $fohlenId]);

        // ------------------------------------------------------------------
        // 5. Das Veto (Kern-#335): Der Datensatz bleibt, das Häkchen fällt.
        // ------------------------------------------------------------------
        $this->veroeffentlichen($admin, $fohlenId);
        $this->assertSame(
            0,
            $this->istVeroeffentlicht($fohlenId),
            'Ein Elternteil, der jünger ist als das Fohlen, darf nicht öffentlich werden.'
        );
        $this->assertNotNull(
            $this->pferdSpalte($fohlenId, 'name'),
            'Das Veto gilt der Veröffentlichung, nicht dem Datensatz - der bleibt bestehen.'
        );

        // ------------------------------------------------------------------
        // 6. Der Befund steht am Datensatz. Ohne diesen Abschnitt erführe der
        //    Bearbeiter nirgends, WARUM sein Häkchen wieder wegfiel.
        // ------------------------------------------------------------------
        $form = $admin->get('/admin/horses/edit?id=' . $fohlenId);
        $this->assertSame(200, $form->statusCode);
        $this->assertStringContainsString('Plausibilitätsprüfung', $form->body);
        $this->assertStringContainsString('Elternteil jünger als das Fohlen', $form->body);
        $this->assertStringContainsString(self::ABHAKEN, $form->body);

        // Und im Bericht, mit Sprung ins Bearbeitungsformular.
        $bericht = $admin->get(self::BERICHT);
        $this->assertStringContainsString("PPFohlen-{$unique}", $bericht->body);
        $this->assertStringContainsString('/admin/horses/edit?id=' . $fohlenId, $bericht->body);

        // ------------------------------------------------------------------
        // 7. Abhaken ohne Begründung: passiert nichts. Ein Häkchen ohne Grund
        //    ist genau das, was aus einer Prüfliste eine leere Liste macht.
        // ------------------------------------------------------------------
        $csrf = $form->formField('csrf_token') ?? '';
        $ohneGrund = $admin->post(self::ABHAKEN, [
            'csrf_token' => $csrf,
            'horse_id' => (string) $fohlenId,
            'regel' => 'eltern-juenger',
            'begruendung' => '   ',
        ]);
        $this->assertSame(self::BERICHT . '?plaus=begruendung-fehlt', $ohneGrund->location());
        $this->assertSame(0, $this->anzahlAusnahmen($fohlenId));

        // ------------------------------------------------------------------
        // 8. Abhaken mit Begründung - aus dem Pferdeformular heraus, also mit
        //    Rückweg auf das Pferd.
        // ------------------------------------------------------------------
        $begruendung = "Geburtsjahr des Vaters ist laut Papieren falsch erfasst, Korrektur beim Verband angefragt ({$unique}).";
        $abgehakt = $admin->post(self::ABHAKEN, [
            'csrf_token' => $csrf,
            'horse_id' => (string) $fohlenId,
            'regel' => 'eltern-juenger',
            'begruendung' => $begruendung,
            'zurueck' => '/admin/horses/edit?id=' . $fohlenId,
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $fohlenId . '&plaus=abgehakt', $abgehakt->location());
        $this->assertSame(1, $this->anzahlAusnahmen($fohlenId));

        // Die Begründung bleibt am Datensatz sichtbar (#114).
        $formNachAbhaken = $admin->get('/admin/horses/edit?id=' . $fohlenId);
        $this->assertStringContainsString('Abgehakt', $formNachAbhaken->body);
        $this->assertStringContainsString(htmlspecialchars($begruendung, ENT_QUOTES, 'UTF-8'), $formNachAbhaken->body);

        // ------------------------------------------------------------------
        // 9. Und jetzt geht die Veröffentlichung durch - das ist der Sinn des
        //    Abhakens.
        // ------------------------------------------------------------------
        $this->veroeffentlichen($admin, $fohlenId);
        $this->assertSame(
            1,
            $this->istVeroeffentlicht($fohlenId),
            'Eine abgehakte Regel darf die Veröffentlichung nicht mehr blockieren.'
        );

        // ------------------------------------------------------------------
        // 10. Zurücknehmen: der Fall zählt wieder als offen und blockiert
        //     wieder.
        // ------------------------------------------------------------------
        $zurueck = $admin->post(self::ZURUECKNEHMEN, [
            'csrf_token' => $csrf,
            'horse_id' => (string) $fohlenId,
            'regel' => 'eltern-juenger',
        ]);
        $this->assertSame(self::BERICHT . '?plaus=zurueckgenommen', $zurueck->location());
        $this->assertSame(0, $this->anzahlAusnahmen($fohlenId));

        $this->depublizieren($admin, $fohlenId);
        $this->veroeffentlichen($admin, $fohlenId);
        $this->assertSame(0, $this->istVeroeffentlicht($fohlenId));

        // ------------------------------------------------------------------
        // 11. Das Protokoll (Kern-#352): Abhaken und Zurücknehmen sind die
        //     einzigen Aktionen dieses Addons mit Aussenwirkung - beide
        //     müssen unter dem eigenen Slug auffindbar sein.
        // ------------------------------------------------------------------
        $protokoll = $this->protokollEintraege($fohlenId);
        $this->assertContains('Plausibilitätsfund als geprüft abgehakt', $protokoll);
        $this->assertContains('Abhaken eines Plausibilitätsfunds zurückgenommen', $protokoll);
    }

    /**
     * Zweiter Widerspruch, der eine ganz andere Abfrageform benutzt: Join auf
     * horse_persons mit GROUP BY. Am selben Datensatz hängt zusätzlich ein
     * Hinweis - und der darf die Veröffentlichung ausdrücklich NICHT
     * verhindern. Genau diese Trennung ist die Entscheidung aus #114.
     */
    public function testZeitraumNachTodesjahrBlockiertOffenerZeitraumNicht(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();

        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $pferdId = $this->createHorse($admin, "PPTot-{$unique}", [
            'birth_year' => '1990',
            'death_year' => '2000',
            'sex' => 'mare',
            'ueln' => "DE-PPT-{$unique}",
            'is_published' => '0',
        ]);

        $db = $this->db();
        $kontakt = $db->prepare('INSERT INTO contacts (name, is_published) VALUES (?, 1)');
        $kontakt->execute(["PPHalter-{$unique}"]);
        $kontaktId = (int) $db->lastInsertId();

        // Zeile 1: Zeitraum, der NACH dem Todesjahr beginnt -> blockierend.
        // Zeile 2: offener Zeitraum bei verstorbenem Pferd -> nur Hinweis.
        $zuordnung = $db->prepare(
            'INSERT INTO horse_persons (horse_id, contact_id, role, from_year, until_year) VALUES (?, ?, ?, ?, ?)'
        );
        $zuordnung->execute([$pferdId, $kontaktId, 'keeper', 2005, 2007]);
        $zuordnung->execute([$pferdId, $kontaktId, 'owner', 1995, null]);

        $this->veroeffentlichen($admin, $pferdId);
        $this->assertSame(
            0,
            $this->istVeroeffentlicht($pferdId),
            'Ein Zuordnungszeitraum nach dem Todesjahr ist eine unmögliche Aussage und blockiert.'
        );

        $form = $admin->get('/admin/horses/edit?id=' . $pferdId);
        $this->assertStringContainsString('Zuordnungszeitraum nach dem Todesjahr', $form->body);
        $this->assertStringContainsString('Verstorbenes Pferd mit offenem Zuordnungszeitraum', $form->body);

        // Nur die blockierende Regel abhaken - der Hinweis bleibt offen und
        // darf die Veröffentlichung trotzdem nicht aufhalten.
        $admin->post(self::ABHAKEN, [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'horse_id' => (string) $pferdId,
            'regel' => 'zeitraum-nach-tod',
            'begruendung' => 'Zeitraum stammt aus der Altmigration und wird nach Rücksprache mit dem Halter geklärt.',
        ]);

        $this->veroeffentlichen($admin, $pferdId);
        $this->assertSame(
            1,
            $this->istVeroeffentlicht($pferdId),
            'Ein Hinweis darf die Veröffentlichung nie verhindern - das unterscheidet ihn vom Blocker.'
        );
    }

    /**
     * Fail-closed: Ohne das eigene Recht gibt es den Bericht-Bereich nicht -
     * und zwar als 403, nicht als leere Seite.
     */
    public function testBerichtVerlangtDasEigeneRecht(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();
        $editor = $this->createAndLoginEditor(
            $admin,
            "plauseditor{$unique}",
            "plaus-{$unique}@example.com"
        );

        $this->assertSame(403, $editor->get(self::BERICHT)->statusCode);
        // editorCsrfToken() holt das Token vom Dashboard, das jeder
        // angemeldeten Sitzung offensteht. currentCsrfToken() nutzt
        // /admin/users/create - dort bekaeme dieser Benutzer 403, das Token
        // waere leer, und der POST scheiterte am CSRF-Check STATT an der
        // Rechtepruefung. Der Test waere gruen, ohne sie je erreicht zu haben
        // (Framework#377).
        $this->assertSame(403, $editor->post(self::ABHAKEN, [
            'csrf_token' => $this->editorCsrfToken($editor),
            'horse_id' => '1',
            'regel' => 'eltern-juenger',
            'begruendung' => 'Sollte gar nicht erst ankommen.',
        ])->statusCode);
    }

    /**
     * Lesen berechtigt NICHT zum Abhaken (#143).
     *
     * Das Addon bringt zwei getrennte Rechte mit: `bericht` oeffnet die Liste,
     * `abhaken` hebt eine Veroeffentlichungssperre auf - ein als Blocker
     * eingestufter Fund verhindert sonst, dass ein Pferd oeffentlich wird.
     * Geprueft wurde bisher nur ein Benutzer, der BEIDE nicht hat; die
     * Trennlinie dazwischen hielt keine Zusicherung. Faellt die zweite
     * Pruefung bei einem Umbau weg - naheliegend, weil der Konstruktor schon
     * requirePermission aufruft und sie wie eine Dopplung aussieht -, duerfte
     * jeder Leser Blocker abraeumen.
     *
     * Die Ansicht blendet den Knopf zwar aus, aber ein direkter POST kommt
     * ohne Knopf aus.
     */
    public function testLesenBerechtigtNichtZumAbhaken(): void {
        $admin = $this->authenticatedClient();
        $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);

        $unique = uniqid();
        $gruppe = $this->createCustomGroup($admin, "Nur Bericht {$unique}");
        $this->setGroupPermissions($admin, $gruppe, self::EDITOR_DEFAULT_PERMISSIONS + [
            // Bewusst OHNE 'abhaken' - das ist der ganze Punkt.
            self::SLUG => ['bericht'],
        ]);
        $leser = $this->createAndLoginEditor(
            $admin,
            "plausleser{$unique}",
            "plausleser-{$unique}@example.com",
            [$gruppe]
        );

        // Der Bericht ist erreichbar - sonst prueft der POST unten die falsche
        // Huerde.
        $bericht = $leser->get(self::BERICHT);
        $this->assertSame(200, $bericht->statusCode, 'Mit dem Recht bericht muss die Liste offenstehen');

        // Das Token MUSS von einer Seite kommen, die dieser Benutzer sehen
        // darf. currentCsrfToken() der Basisklasse holt es von
        // /admin/users/create - dort bekaeme ein Redakteur 403 und damit ein
        // LEERES Token; der POST scheiterte dann am CSRF-Check, und der Test
        // waere gruen, ohne die Rechtepruefung je erreicht zu haben.
        $tokenSeite = $leser->get('/admin/contacts/create');
        $this->assertSame(200, $tokenSeite->statusCode, 'Die Token-Quelle muss fuer diesen Benutzer erreichbar sein');
        $token = $tokenSeite->formField('csrf_token') ?? '';
        $this->assertNotSame('', $token, 'Ohne gueltiges Token prueft der POST nur den CSRF-Zweig');

        $vorher = $this->ausnahmenAnzahl();

        foreach ([self::ABHAKEN, self::ZURUECKNEHMEN] as $route) {
            $antwort = $leser->post($route, [
                'csrf_token' => $token,
                'horse_id' => '1',
                'regel' => 'eltern-juenger',
                'begruendung' => 'darf nicht ankommen',
            ]);
            $this->assertSame(403, $antwort->statusCode, "Erwartet wurde 403 fuer {$route}, Body: {$antwort->body}");
        }

        $this->assertSame($vorher, $this->ausnahmenAnzahl(), 'Ein abgelehnter Aufruf darf nichts geschrieben haben');
    }

    // ----------------------------------------------------------------------
    // Helfer
    // ----------------------------------------------------------------------

    /**
     * Zahl der abgehakten Faelle.
     *
     * DER TABELLENNAME IST `plugin_plausibilitaet_ausnahmen`, nicht
     * `plugin_plausibilitaetspruefung_ausnahmen`. Hier stand zuerst der lange
     * Name samt try/catch, das den daraus folgenden SQL-Fehler in ein
     * `return 0` verwandelte - die Zusicherung "es wurde nichts geschrieben"
     * war damit inhaltsleer und haette auch dann gegolten, wenn das Addon
     * munter INSERTs abgesetzt haette.
     *
     * Kein try/catch mehr: Ein Fehler beim Zaehlen ist kein Ergebnis 0,
     * sondern ein kaputter Test, und der soll auffallen.
     */
    private function ausnahmenAnzahl(): int {
        return (int) $this->db()->query(
            'SELECT COUNT(*) FROM `plugin_plausibilitaet_ausnahmen`'
        )->fetchColumn();
    }

    private function createCustomGroup(\Tests\Support\HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $response = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        preg_match('/group=(\d+)/', (string) $response->location(), $matches);
        $this->assertNotEmpty($matches, "Konnte neue Gruppen-ID nicht ermitteln, Body: {$response->body}");
        return (int) $matches[1];
    }

    private function db(): PDO {
        return Database::getInstance();
    }

    private function veroeffentlichen(HttpClient $admin, int $horseId): void {
        $admin->post('/admin/horses/publish', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'ids' => [(string) $horseId],
            'publish' => '1',
        ]);
    }

    private function depublizieren(HttpClient $admin, int $horseId): void {
        $admin->post('/admin/horses/publish', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'ids' => [(string) $horseId],
        ]);
    }

    private function istVeroeffentlicht(int $horseId): int {
        return (int) $this->pferdSpalte($horseId, 'is_published');
    }

    private function pferdSpalte(int $horseId, string $spalte): mixed {
        // Spaltenname aus einer festen Weißliste, nie aus einer Eingabe.
        $erlaubt = ['is_published' => 1, 'name' => 1];
        $this->assertArrayHasKey($spalte, $erlaubt);

        $stmt = $this->db()->prepare("SELECT `{$spalte}` FROM horses WHERE id = ?");
        $stmt->execute([$horseId]);
        return $stmt->fetchColumn();
    }

    private function anzahlAusnahmen(int $horseId): int {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM `plugin_plausibilitaet_ausnahmen` WHERE horse_id = ?'
        );
        $stmt->execute([$horseId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, string> */
    private function protokollEintraege(int $horseId): array {
        $stmt = $this->db()->prepare(
            'SELECT action FROM audit_logs WHERE category = ? AND details LIKE ?'
        );
        // PluginAudit::log() setzt Bezug und Details mit ' - ' zusammen; die
        // Suche endet deshalb am Trenner. Ohne ihn träfe "Pferd #12" auch
        // "Pferd #120" - in einer geteilten Testdatenbank ein echter Fehlschluss.
        $stmt->execute([self::SLUG, 'Pferd #' . $horseId . ' - %']);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Der Tabellenabschnitt der Plugin-Übersicht, der zu diesem Addon gehört -
     * damit "Inkompatibel" aus der Zeile eines FREMDEN Addons den Test nicht
     * fälschlich rot färbt.
     */
    private function pluginZeile(string $body): string {
        if (!preg_match_all('/<tr[^>]*>((?:(?!<\/tr>).)*?)<\/tr>/s', $body, $treffer)) {
            return '';
        }
        foreach ($treffer[1] as $zeile) {
            if (str_contains($zeile, self::SLUG)) {
                return $zeile;
            }
        }
        return '';
    }
}
