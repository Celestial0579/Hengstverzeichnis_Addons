<?php
// tests/Functional/PferdDesTagesPluginTest.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * End-to-End-Test für plugins/pferd-des-tages gegen eine echte, per `php -S`
 * gestartete Hengstverzeichnis_Framework-Instanz (siehe tests/bootstrap.php
 * und Tests\Functional\FunctionalTestCase).
 *
 * Festgenagelt werden die vier Zusicherungen, an denen dieses Addon hängt
 * (Addons#135) - jede einzelne fällt lautlos aus, wenn sie bricht:
 *
 *  1. **Ein Tag, ein Pferd.** Zwei Aufrufe derselben Startseite zeigen
 *     dasselbe Pferd. Ein `ORDER BY RAND()` je Request bestünde jeden
 *     Sichttest und fiele genau hier durch.
 *  2. **Kein Treffer heisst kein Kasten.** Greifen die Kriterien nicht, steht
 *     auf der Startseite gar nichts - kein leerer Rahmen.
 *  3. **Nicht mehr veröffentlicht heisst weg, sofort.** Auch mitten am Tag,
 *     auch bei einer redaktionellen Vorgabe.
 *  4. **Fail-closed.** Ohne `horses.view` für die Gast-Gruppe zeigt die
 *     Startseite kein Pferd; ohne `pferd-des-tages.manage` gibt es weder
 *     Kachel noch Verwaltungsseite noch eine wirksame POST-Route.
 *
 * Dazu die Fotoregel aus GHSA-xrrq-9j94-fr5g: Das Bild kommt über
 * `/media/horse-image`, der rohe `/uploads/`-Pfad taucht nirgends auf.
 */
class PferdDesTagesPluginTest extends FunctionalTestCase {

    use HorseListHelper;

    private const SLUG = 'pferd-des-tages';
    private const SEITE = '/plugin/pferd-des-tages/verwaltung';

    /**
     * Gast-Standardrechte für das Wiederherstellen nach dem Fail-closed-Test.
     *
     * Bewusst NICHT self::GUEST_DEFAULT_PERMISSIONS aus dem Kern-Testfall -
     * dieselbe Begründung wie in ZuchtSuchePluginTest: Die Konstante dort
     * nennt teilweise noch Module aus der Zeit vor Framework#336.
     *
     * @var array<string, array<int, string>>
     */
    private const GAST_STANDARD = [
        'horses' => ['view'],
        'contacts' => ['view'],
    ];

    public function testFullPluginLifecycle(): void {
        $admin = $this->authenticatedClient();

        $pluginsPage = $admin->get('/admin/plugins');
        $this->assertSame(200, $pluginsPage->statusCode);
        $this->assertStringContainsString('Pferd des Tages', $pluginsPage->body);

        $toggleResponse = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame(
            '/admin/plugins?success=1',
            $toggleResponse->location(),
            "Addon liess sich nicht aktivieren, Body: {$toggleResponse->body}"
        );

        // 1. Kachel und Verwaltungsseite stehen dem Administrator offen.
        $dashboard = $admin->get('/admin');
        $this->assertStringContainsString('href="' . self::SEITE . '"', $dashboard->body);

        $verwaltung = $admin->get(self::SEITE);
        $this->assertSame(200, $verwaltung->statusCode);
        $csrf = $verwaltung->formField('csrf_token') ?? '';
        $this->assertNotSame('', $csrf, 'Die Verwaltungsseite muss ein CSRF-Token führen.');

        // Zwei veröffentlichte Pferde mit einer im Bestand einmaligen Farbe -
        // die Testdatenbank wird über die ganze Suite geteilt, und ohne ein
        // solches Merkmal zöge das Addon irgendein fremdes Testpferd.
        $unique = uniqid();
        $farbe = "PdTFarbe-{$unique}";
        $nameA = "PdTAlpha-{$unique}";
        $nameB = "PdTBeta-{$unique}";
        $nameVersteckt = "PdTVersteckt-{$unique}";

        $idA = $this->createHorse($admin, $nameA, ['color' => $farbe, 'birth_year' => '2015']);
        $idB = $this->createHorse($admin, $nameB, ['color' => $farbe, 'birth_year' => '2016']);
        // Unveröffentlicht - darf nie erscheinen, auch nicht als Vorgabe.
        $idVersteckt = $this->createHorse($admin, $nameVersteckt, [
            'color' => $farbe,
            'is_published' => '0',
        ]);

        // 2. Die Grundeinstellung verlangt ein Foto. Ohne Foto gibt es also
        //    nichts zu zeigen - und genau das ist der Fall "kein Treffer heisst
        //    kein Kasten", bevor er künstlich hergestellt werden muss.
        $this->kriterienSetzen($admin, $csrf, ['farbe' => $farbe, 'nur_mit_foto' => '1']);
        $this->neuWaehlen($admin, $csrf);

        $besucher = $this->newClient();
        $ohneFoto = $besucher->get('/');
        $this->assertSame(200, $ohneFoto->statusCode);
        $this->assertNull(
            $this->kasten($ohneFoto->body),
            'Ohne Treffer gehoert auf die Startseite gar nichts - kein leerer Rahmen (#135).'
        );

        // 3. Foto nachtragen (direkt in der Spalte, wie BildauslieferungTest:
        //    über das Formular ginge das nur per echtem Upload, und für die
        //    Frage, WIE der Wert ausgegeben wird, ist die Herkunft egal).
        $this->setzeFoto($idA, "pdt_a_{$unique}.jpg");
        $this->setzeFoto($idB, "pdt_b_{$unique}.jpg");
        $this->neuWaehlen($admin, $csrf);

        $ersterKasten = $this->kasten($besucher->get('/')->body);
        $this->assertNotNull($ersterKasten, 'Mit Foto und passender Farbe muss ein Pferd des Tages erscheinen.');
        $gezeigt = $this->welchesPferd($ersterKasten, [$nameA => $idA, $nameB => $idB]);
        $this->assertStringNotContainsString(
            $nameVersteckt,
            $ersterKasten,
            'Ein unveroeffentlichtes Pferd darf nie Pferd des Tages werden.'
        );

        // Die Fotoregel: über /media/horse-image, nie über den rohen
        // Speicherort (GHSA-xrrq-9j94-fr5g).
        $this->assertStringContainsString('/media/horse-image?id=' . $gezeigt['id'], $ersterKasten);
        $this->assertStringNotContainsString(
            '/uploads/horses/',
            str_replace('\\/', '/', $ersterKasten),
            'Der rohe Speicherort des Fotos gehoert in keine oeffentliche Antwort (GHSA-xrrq-9j94-fr5g).'
        );

        // 4. DER Punkt des Addons: derselbe Tag, dasselbe Pferd. Ein
        //    ORDER BY RAND() je Aufruf faellt hier durch - und zwar auch fuer
        //    einen zweiten, frischen Besucher ohne gemeinsame Sitzung.
        $this->assertStringContainsString(
            $gezeigt['name'],
            (string) $this->kasten($besucher->get('/')->body),
            'Ein zweiter Aufruf muss dasselbe Pferd zeigen - sonst ist es ein Zufallspferd (#135).'
        );
        $this->assertStringContainsString(
            $gezeigt['name'],
            (string) $this->kasten($this->newClient()->get('/')->body),
            'Am selben Tag muss JEDER Besucher dasselbe Pferd sehen (#135).'
        );

        $andere = $gezeigt['name'] === $nameA
            ? ['name' => $nameB, 'id' => $idB]
            : ['name' => $nameA, 'id' => $idA];

        // 4b. Schonfrist: Wer gestern dran war, kommt nicht sofort wieder -
        //     solange es eine Alternative gibt. Die Wahl von "gestern" wird
        //     direkt in der Tabelle hinterlegt; ueber HTTP liesse sich ein
        //     vergangener Tag nicht herstellen, ohne die Uhr zu stellen.
        $gestern = date('Y-m-d', (int) strtotime('-1 day'));
        $vorgestern = date('Y-m-d', (int) strtotime('-2 days'));

        $this->merkeWahl($gestern, $gezeigt['id']);
        $this->kriterienSetzen($admin, $csrf, [
            'farbe' => $farbe,
            'nur_mit_foto' => '1',
            'schonfrist_tage' => '30',
        ]);
        $this->neuWaehlen($admin, $csrf);

        $mitSchonfrist = (string) $this->kasten($this->newClient()->get('/')->body);
        $this->assertStringContainsString(
            $andere['name'],
            $mitSchonfrist,
            'Innerhalb der Schonfrist muss das andere Pferd drankommen (#135).'
        );
        $this->assertStringNotContainsString($gezeigt['name'], $mitSchonfrist);

        // Und der zweite Teil der Regel, der leicht vergessen wird: Sind ALLE
        // Kandidaten innerhalb der Frist schon drangewesen, gilt sie fuer
        // diesen Tag nicht - sonst verschwaende der Kasten bei einem kleinen
        // Bestand nach wenigen Tagen von der Startseite.
        $this->merkeWahl($vorgestern, $andere['id']);
        $this->neuWaehlen($admin, $csrf);
        $this->assertNotNull(
            $this->kasten($this->newClient()->get('/')->body),
            'Eine erschoepfte Auswahl darf den Kasten nicht abschalten (#135).'
        );

        // Zurueck auf den Ausgangsstand fuer die folgenden Schritte.
        $this->loescheWahl($gestern);
        $this->loescheWahl($vorgestern);
        $this->kriterienSetzen($admin, $csrf, ['farbe' => $farbe, 'nur_mit_foto' => '1']);
        $this->neuWaehlen($admin, $csrf);
        $this->assertStringContainsString(
            $gezeigt['name'],
            (string) $this->kasten($this->newClient()->get('/')->body),
            'Ohne Schonfrist muss dieselbe Ableitung aus dem Datum wieder dasselbe Pferd liefern.'
        );

        // 5. Ausschlussliste: Wer heute dransteht, verschwindet sofort - das
        //    Ausnehmen verwirft die heutige Wahl mit.
        $ausschluss = $admin->post(self::SEITE . '/ausschluss', [
            'csrf_token' => $csrf,
            'horse_id' => (string) $gezeigt['id'],
            'grund' => 'Testausnahme',
        ]);
        $this->assertSame(self::SEITE . '?meldung=ausgeschlossen', $ausschluss->location());

        $nachAusschluss = (string) $this->kasten($this->newClient()->get('/')->body);
        $this->assertStringNotContainsString(
            $gezeigt['name'],
            $nachAusschluss,
            'Ein ausgenommenes Pferd muss noch heute aus dem Kasten verschwinden.'
        );
        $this->assertStringContainsString($andere['name'], $nachAusschluss);

        // 6. Beide ausgenommen: kein Kandidat, also kein Kasten - und zwar
        //    ohne Fehlermeldung und ohne leeren Rahmen.
        $admin->post(self::SEITE . '/ausschluss', [
            'csrf_token' => $csrf,
            'horse_id' => (string) $andere['id'],
        ]);
        $leer = $this->newClient()->get('/');
        $this->assertSame(200, $leer->statusCode);
        $this->assertNull(
            $this->kasten($leer->body),
            'Sind alle Kandidaten ausgenommen, gehoert auf die Startseite kein Kasten (#135).'
        );

        // 7. Ausnahmen wieder aufheben.
        foreach ([$gezeigt['id'], $andere['id']] as $id) {
            $weg = $admin->post(self::SEITE . '/ausschluss/entfernen', [
                'csrf_token' => $csrf,
                'horse_id' => (string) $id,
            ]);
            $this->assertSame(self::SEITE . '?meldung=ausschluss-weg', $weg->location());
        }

        // 8. Redaktionelle Vorgabe schlaegt die Kriterien. Dafuer werden die
        //    Kriterien zuerst auf eine Menge gestellt, die garantiert leer ist.
        $this->kriterienSetzen($admin, $csrf, ['farbe' => "PdTNiemals-{$unique}"]);
        $this->neuWaehlen($admin, $csrf);
        $this->assertNull($this->kasten($this->newClient()->get('/')->body));

        $heute = date('Y-m-d');
        $vorgabe = $admin->post(self::SEITE . '/vorgabe', [
            'csrf_token' => $csrf,
            'datum' => $heute,
            'horse_id' => (string) $idA,
        ]);
        $this->assertSame(self::SEITE . '?meldung=vorgabe-gesetzt', $vorgabe->location());

        $mitVorgabe = $this->kasten($this->newClient()->get('/')->body);
        $this->assertNotNull($mitVorgabe);
        $this->assertStringContainsString(
            $nameA,
            $mitVorgabe,
            'Eine Vorgabe gilt unabhaengig von den Kriterien (#135).'
        );

        // 8b. Ein ungueltiges Datum wird abgewiesen, nicht stillschweigend
        //     zurechtgebogen - der 31. Februar passt auf das Muster.
        $krumm = $admin->post(self::SEITE . '/vorgabe', [
            'csrf_token' => $csrf,
            'datum' => '2026-02-31',
            'horse_id' => (string) $idA,
        ]);
        $this->assertSame(self::SEITE . '?meldung=kein-datum', $krumm->location());

        // 9. Depublizieren wirkt sofort - auch gegen eine Vorgabe. Sonst
        //    stuende das Pferd bis Mitternacht weiter auf der Startseite,
        //    waehrend der Katalog es bereits verbirgt.
        $this->veroeffentlichung($admin, $idA, false);
        $this->assertNull(
            $this->kasten($this->newClient()->get('/')->body),
            'Ein depubliziertes Pferd darf nicht bis Mitternacht weiter auf der Startseite stehen (#135).'
        );

        $this->veroeffentlichung($admin, $idA, true);
        $this->assertStringContainsString(
            $nameA,
            (string) $this->kasten($this->newClient()->get('/')->body),
            'Nach dem Wiederveroeffentlichen gilt die Vorgabe unveraendert weiter.'
        );

        // 10. Fail-closed gegenueber der Katalogseite: Ohne horses.view fuer
        //     die Gast-Gruppe zeigt die Startseite kein Pferd des Tages.
        $gastGruppe = $this->findBuiltinGroupId($admin, 'Gast');
        $this->setGroupPermissions($admin, $gastGruppe, ['contacts' => ['view']]);
        try {
            $ohneSichtrecht = $this->newClient()->get('/');
            $this->assertSame(200, $ohneSichtrecht->statusCode);
            $this->assertNull(
                $this->kasten($ohneSichtrecht->body),
                'Ohne horses.view darf die Startseite kein Pferd hervorheben (#135).'
            );
            $this->assertStringNotContainsString($nameA, $ohneSichtrecht->body);
        } finally {
            $this->setGroupPermissions($admin, $gastGruppe, self::GAST_STANDARD);
        }

        // 11. Protokoll (Kern-#352): Die schreibenden Aktionen stehen unter
        //     dem eigenen Slug im Audit-Log - nicht unter "general" und nicht
        //     unter plugin:unbekannt.
        $protokoll = $admin->get('/admin/logs?category=' . self::SLUG);
        $this->assertSame(200, $protokoll->statusCode);
        $this->assertStringContainsString('Pferd dauerhaft ausgenommen', $protokoll->body);
        $this->assertStringContainsString('Pferd des Tages redaktionell vorgegeben', $protokoll->body);
        $this->assertStringNotContainsString('plugin:unbekannt', $protokoll->body);

        // 12. Fail-closed gegenueber der Berechtigung: Ein Redakteur ohne
        //     pferd-des-tages.manage sieht weder Kachel noch Seite, und seine
        //     POSTs laufen ins Leere.
        $editorGruppe = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "pdttester{$unique}",
            "pferd-des-tages-{$unique}@example.com",
            [$editorGruppe]
        );

        $editorDashboard = $editor->get('/admin');
        $this->assertStringNotContainsString(
            'href="' . self::SEITE . '"',
            $editorDashboard->body,
            'Ohne pferd-des-tages.manage darf keine Kachel erscheinen, die in ein 403 fuehrt.'
        );
        $this->assertSame(403, $editor->get(self::SEITE)->statusCode);
        $this->assertSame(403, $editor->post(self::SEITE . '/kriterien', [
            'csrf_token' => $editorDashboard->formField('csrf_token') ?? '',
            'farbe' => 'egal',
        ])->statusCode);

        // Mit der Berechtigung steht die Seite offen - die Rechtepruefung
        // haengt an ihr, nicht an der Admin-Rolle.
        $this->setGroupPermissions($admin, $editorGruppe, self::EDITOR_DEFAULT_PERMISSIONS + [
            self::SLUG => ['manage'],
        ]);
        $editorSeite = $editor->get(self::SEITE);
        $this->assertSame(200, $editorSeite->statusCode);
        $this->assertStringContainsString('Kriterien', $editorSeite->body);

        // 13. Aufraeumen. Die Datenbank ist ueber die ganze Suite geteilt, und
        //     das Addon bleibt aktiviert - ein hier stehengelassenes Pferd des
        //     Tages taeuchte in jeder spaeteren Testklasse auf der Startseite
        //     auf. Die Vorgabe fuer heute wird deshalb geloest; die Kriterien
        //     stehen seit Schritt 8 auf einer Farbe, die es nicht gibt, also
        //     waehlt das Addon danach nichts mehr.
        $this->assertSame(
            self::SEITE . '?meldung=vorgabe-weg',
            $admin->post(self::SEITE . '/vorgabe/entfernen', [
                'csrf_token' => $csrf,
                'datum' => $heute,
            ])->location()
        );
        $this->assertNull(
            $this->kasten($this->newClient()->get('/')->body),
            'Nach dem Aufraeumen darf dieser Testlauf keiner spaeteren Testklasse ein Pferd hinterlassen.'
        );
        $this->assertGreaterThan(0, $idVersteckt);
    }

    // ------------------------------------------------------------------
    // Helfer
    // ------------------------------------------------------------------

    /**
     * Kriterien setzen. Nicht gesetzte Felder bleiben leer und bedeuten
     * "egal" - genau wie im Formular.
     *
     * @param array<string, string> $felder
     */
    private function kriterienSetzen(HttpClient $admin, string $csrf, array $felder): void {
        $antwort = $admin->post(self::SEITE . '/kriterien', array_merge([
            'csrf_token' => $csrf,
            // Ohne diese Angabe zaehlt die Schonfrist, und der zweite
            // Durchgang dieses Tests faende sein Pferd nicht wieder.
            'schonfrist_tage' => '0',
        ], $felder));

        $this->assertSame(
            self::SEITE . '?meldung=gespeichert',
            $antwort->location(),
            "Kriterien liessen sich nicht speichern, Body: {$antwort->body}"
        );
    }

    /**
     * Die heutige Wahl verwerfen. Das Speichern der Kriterien tut das bewusst
     * NICHT - eine laufende Tageswahl gilt fuer den ganzen Tag.
     */
    private function neuWaehlen(HttpClient $admin, string $csrf): void {
        $antwort = $admin->post(self::SEITE . '/neu-waehlen', ['csrf_token' => $csrf]);
        $this->assertSame(self::SEITE . '?meldung=neu-gewaehlt', $antwort->location());
    }

    /**
     * Das Fragment dieses Addons aus einer Startseiten-Antwort schneiden -
     * oder `null`, wenn kein Kasten da ist.
     *
     * NOETIG, WEIL DIE STARTSEITE SELBST PFERDE ZEIGT: Der Kern listet die
     * drei zuletzt angelegten veroeffentlichten Pferde, und das sind in einem
     * frischen Testlauf genau die Pferde dieses Tests. Eine Pruefung gegen den
     * ganzen Body faende sie also immer - und meldete gruen, egal was dieses
     * Addon tut.
     */
    private function kasten(string $body): ?string {
        $marke = '🐴 Pferd des Tages';
        $pos = strpos($body, $marke);
        if ($pos === false) {
            return null;
        }

        $start = strrpos(substr($body, 0, $pos), '<section');
        $ende = strpos($body, '</section>', $pos);
        $this->assertNotFalse($start, 'Der Kasten muss in einem <section>-Element stehen.');
        $this->assertNotFalse($ende, 'Der Kasten muss geschlossen werden.');

        return substr($body, (int) $start, (int) $ende - (int) $start + strlen('</section>'));
    }

    /**
     * Welches der beiden Pferde steht heute da? Die Wahl wird aus dem Datum
     * abgeleitet, ist also nicht vorherzusagen - wohl aber stabil, und genau
     * das prueft der Test danach.
     *
     * @param array<string, int> $kandidaten Name => ID
     * @return array{name: string, id: int}
     */
    private function welchesPferd(string $kastenHtml, array $kandidaten): array {
        $treffer = [];
        foreach ($kandidaten as $name => $id) {
            if (str_contains($kastenHtml, $name)) {
                $treffer[] = ['name' => $name, 'id' => $id];
            }
        }

        $this->assertCount(
            1,
            $treffer,
            'Im Kasten darf genau EIN Pferd stehen, gefunden: ' . count($treffer)
        );

        return $treffer[0];
    }

    /**
     * Setzt `horses.image_url` direkt - wie in BildauslieferungTest. Ueber das
     * Formular ginge das nur per echtem Upload; fuer die Frage, ob das
     * Kriterium "nur mit Foto" greift und wie der Wert ausgegeben wird, ist
     * die Herkunft ohne Belang.
     */
    private function setzeFoto(int $horseId, string $dateiname): void {
        $stmt = \App\Database::getInstance()->prepare('UPDATE horses SET image_url = ? WHERE id = ?');
        $stmt->execute(['/uploads/horses/' . $dateiname, $horseId]);
    }

    /**
     * Eine Wahl fuer einen vergangenen Tag hinterlegen - der einzige Weg, die
     * Schonfrist zu pruefen, ohne die Uhr des Testrechners zu stellen.
     */
    private function merkeWahl(string $datum, int $horseId): void {
        $stmt = \App\Database::getInstance()->prepare(
            'INSERT INTO `plugin_pferd_des_tages_wahl` (datum, horse_id, fest) VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE horse_id = VALUES(horse_id)'
        );
        $stmt->execute([$datum, $horseId]);
    }

    private function loescheWahl(string $datum): void {
        $stmt = \App\Database::getInstance()->prepare(
            'DELETE FROM `plugin_pferd_des_tages_wahl` WHERE datum = ?'
        );
        $stmt->execute([$datum]);
    }

    /** Veroeffentlichen bzw. Depublizieren ueber die Kern-Route. */
    private function veroeffentlichung(HttpClient $admin, int $horseId, bool $veroeffentlichen): void {
        $liste = $admin->get('/admin/horses');
        $daten = [
            'csrf_token' => $liste->formField('csrf_token') ?? '',
            'ids' => [(string) $horseId],
        ];
        if ($veroeffentlichen) {
            $daten['publish'] = '1';
        }

        $antwort = $admin->post('/admin/horses/publish', $daten);
        $this->assertContains(
            $antwort->statusCode,
            [302, 303],
            "Veroeffentlichungswechsel fuer Pferd {$horseId} fehlgeschlagen, Body: {$antwort->body}"
        );
    }
}
