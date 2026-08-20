<?php
// tests/Functional/BildauslieferungTest.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * Hält die Regel fest, dass kein Addon Pferdefotos am geschützten Weg vorbei
 * ausliefert (GHSA-xrrq-9j94-fr5g).
 *
 * WORUM ES GING: Vier Addons gaben den rohen Spaltenwert `horses.image_url`
 * bzw. einen eigenen Pfad unter `public/uploads/` aus. Der Kern liefert
 * Pferdefotos ausschließlich über `/media/horse-image` aus und prüft dort je
 * Anfrage Sitzung, `horses.view` und `is_published`. Genau darauf beruht der
 * Schutz: Die Adresse trägt nur die Pferde-ID, der Dateiname gelangt nie in
 * eine öffentliche Seite. Sobald ein Addon ihn ausgibt, ist er dauerhaft
 * bekannt - und nach einer Depublikation (etwa nach einem Widerspruch nach
 * Art. 21 DSGVO) liefert die Route zwar 404, die Datei aber weiterhin ihren
 * Inhalt.
 *
 * WARUM DIESER TEST SO GEBAUT IST: Er prüft die **Abwesenheit** roher
 * `/uploads/`-Pfade in öffentlichen Antworten, nicht die Anwesenheit der
 * richtigen. Ein Test auf "enthält /media/horse-image" bliebe grün, wenn
 * daneben weiterhin ein roher Pfad stünde - und genau das war der Zustand,
 * den das Advisory beschreibt. Beide Richtungen zusammen sind die Aussage.
 */
class BildauslieferungTest extends FunctionalTestCase {

    use HorseListHelper;

    /** Alle Addons, die in diesem Test Fotos rendern. */
    private const SLUGS = ['merkliste', 'verkaufsboerse', 'qr-code', 'galerie'];

    /**
     * Angelegte Galerie-Medien (id => Dateiname), damit tearDown() sie wieder
     * abräumen kann.
     *
     * WARUM DAS SEIN MUSS: Die Testdatenbank ist über den gesamten
     * PHPUnit-Prozess hinweg geteilt, und die bestandsweite Medienverwaltung
     * des Galerie-Addons listet ALLE Medien `ORDER BY h.name`. GaleriePluginTest
     * greift dort das erste `name="id" value="…"` heraus, um es zu löschen -
     * bleiben Medien dieses Tests stehen, löscht er meines statt seines und
     * scheitert an einer Zusicherung, die mit dieser Datei nichts zu tun hat.
     * Genau das ist beim Bau passiert.
     *
     * @var array<int, string>
     */
    private array $angelegteMedien = [];

    protected function tearDown(): void {
        if ($this->angelegteMedien !== []) {
            $db = \App\Database::getInstance();
            $stmt = $db->prepare('DELETE FROM `plugin_galerie_media` WHERE id = ?');
            foreach ($this->angelegteMedien as $id => $dateiname) {
                $stmt->execute([$id]);
                $pfad = \FRAMEWORK_VENDOR_DIR . '/storage/plugin_galerie/' . $dateiname;
                if (is_file($pfad)) {
                    @unlink($pfad);
                }
            }
            $this->angelegteMedien = [];
        }

        parent::tearDown();
    }

    private function aktiviere(HttpClient $admin, string $slug): void {
        $antwort = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => $slug,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $antwort->location(), "Addon {$slug} liess sich nicht aktivieren.");
    }

    /**
     * Setzt `horses.image_url` direkt. Über das Formular ginge das nur per
     * echtem Upload; für die Frage, WIE der Wert später ausgegeben wird, ist
     * seine Herkunft ohne Belang.
     */
    private function setzeFoto(int $horseId, string $dateiname): void {
        $stmt = \App\Database::getInstance()->prepare('UPDATE horses SET image_url = ? WHERE id = ?');
        $stmt->execute(['/uploads/horses/' . $dateiname, $horseId]);
    }

    /** Legt ein Galerie-Bild samt Datei in der geschützten Ablage an. */
    private function legeGalerieBildAn(int $horseId, string $unique): int {
        $ablage = \FRAMEWORK_VENDOR_DIR . '/storage/plugin_galerie';
        if (!is_dir($ablage)) {
            mkdir($ablage, 0750, true);
        }
        // Kleinstes gültiges PNG (1x1, transparent) - der Inhalt muss zur
        // Endung passen, damit die Auslieferung ihn nicht als Fremdtyp abweist.
        $dateiname = "galerie_functional_{$unique}.png";
        file_put_contents($ablage . '/' . $dateiname, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        $db = \App\Database::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO `plugin_galerie_media` (horse_id, type, file_path, caption, sort_order)
             VALUES (?, 'image', ?, ?, 0)"
        );
        $stmt->execute([$horseId, $dateiname, "Bild {$unique}"]);

        $mediaId = (int) $db->lastInsertId();
        $this->angelegteMedien[$mediaId] = $dateiname;

        return $mediaId;
    }

    /**
     * Die eigentliche Zusicherung: In einer öffentlich abrufbaren Antwort
     * steht kein Pfad, unter dem eine Datei am Anwendungscode vorbei liegt.
     *
     * WARUM NORMALISIERT WIRD: `json_encode()` maskiert Schrägstriche
     * standardmäßig, im JSON steht also `\/uploads\/horses\/`. Ein Test, der
     * stumpf auf `/uploads/horses/` prüft, wäre in genau der Antwort blind
     * gewesen, um die es hier geht - er hätte gemeldet, alles sei in Ordnung,
     * während der rohe Pfad danebensteht. Erst normalisieren, dann prüfen.
     */
    private function assertKeinRoherUploadPfad(string $body, string $wo): void {
        $normalisiert = str_replace('\\/', '/', $body);

        $this->assertStringNotContainsString(
            '/uploads/horses/',
            $normalisiert,
            "{$wo}: Der rohe Speicherort des Kernfotos darf in einer oeffentlichen Antwort nicht vorkommen "
            . '(GHSA-xrrq-9j94-fr5g) - er macht den Dateinamen dauerhaft bekannt und ueberlebt jede Depublikation.'
        );
        $this->assertStringNotContainsString(
            '/uploads/plugin_galerie/',
            $normalisiert,
            "{$wo}: Galeriebilder liegen ausserhalb des Webroots und sind ausschliesslich ueber "
            . '/plugin/galerie/bild erreichbar (GHSA-xrrq-9j94-fr5g).'
        );
    }

    public function testAddonsLiefernFotosNurUeberDieGeschuetztenRouten(): void {
        $admin = $this->authenticatedClient();
        foreach (self::SLUGS as $slug) {
            $this->aktiviere($admin, $slug);
        }

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "BildTestPferd-{$unique}", ['status' => 'active']);
        $this->setzeFoto($horseId, "foto_{$unique}.jpg");

        $gast = $this->newClient();

        // 1. merkliste: Die JSON-Antwort ist der Ort, an dem der Wert steht -
        //    das JS setzt ihn unbesehen als img.src.
        $json = $gast->get('/plugin/merkliste/api?ids=' . $horseId);
        $this->assertSame(200, $json->statusCode);
        $this->assertKeinRoherUploadPfad($json->body, 'merkliste /api');

        // Den dekodierten Wert prüfen, nicht die Zeichenkette: Das JS setzt
        // genau diesen Wert als img.src.
        $eintraege = json_decode($json->body, true);
        $this->assertIsArray($eintraege);
        $this->assertNotEmpty($eintraege, 'Das gemerkte Pferd muss in der Antwort stehen.');
        $this->assertSame(
            '/media/horse-image?id=' . $horseId,
            $eintraege[0]['image_url'] ?? null,
            'merkliste /api: Die geschuetzte Adresse gehoert schon in das JSON, nicht erst in das JS.'
        );

        // 2. verkaufsboerse: Inserat anlegen, dann die oeffentliche Boerse.
        //
        // Das Inserat entsteht seit Addons#119 im Abschnitt des
        // Pferdeformulars; die addoneigene Verwaltungsseite ist entfallen. Die
        // POST-Route ist dieselbe geblieben, nur der Weg zum Formular ist ein
        // anderer.
        //
        // `contact_email` ist Pflicht (VARCHAR NOT NULL). Fehlt es, entsteht
        // gar kein Inserat - die Boersenseite ist dann leer, und die
        // Abwesenheitspruefung unten waere gruen, ohne irgendetwas geprueft zu
        // haben. Genau dieser Fall ist beim Bau dieses Tests aufgetreten und
        // erst durch die Gegenprobe aufgefallen; deshalb steht darunter eine
        // ausdrueckliche Wirksamkeitspruefung.
        $pferdeformular = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $pferdeformular->statusCode);
        $admin->post('/plugin/verkaufsboerse/verwaltung/store', [
            'csrf_token' => $pferdeformular->formField('csrf_token') ?? '',
            'horse_id' => (string) $horseId,
            'price' => '1234',
            'description' => "Inserat {$unique}",
            'contact_email' => "verkauf-{$unique}@example.org",
        ]);
        $boerse = $gast->get('/plugin/verkaufsboerse/liste');
        $this->assertSame(200, $boerse->statusCode);
        $this->assertStringContainsString(
            "BildTestPferd-{$unique}",
            $boerse->body,
            'Das Inserat muss auf der Boerse stehen - sonst prueft die Abwesenheitspruefung eine leere Seite.'
        );
        $this->assertKeinRoherUploadPfad($boerse->body, 'verkaufsboerse /liste');

        // 3. qr-code: Der Aushang ist ein eigenstaendiges Dokument und wurde
        //    beim Umbau auf die geschuetzte Route leicht uebersehen.
        $aushang = $gast->get('/plugin/qr-code/aushang?id=' . $horseId);
        $this->assertSame(200, $aushang->statusCode);
        $this->assertKeinRoherUploadPfad($aushang->body, 'qr-code /aushang');

        // 4. galerie: oeffentliche Detailseite des Pferdes.
        $mediaId = $this->legeGalerieBildAn($horseId, $unique);
        $detail = $gast->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertKeinRoherUploadPfad($detail->body, '/horse (Galerie-Abschnitt)');
        $this->assertStringContainsString(
            '/plugin/galerie/bild?id=' . $mediaId,
            $detail->body,
            'Der Galerie-Abschnitt muss ueber die geschuetzte Route verweisen.'
        );
    }

    /**
     * Die neue Galerie-Route muss dieselben Sichtbarkeitsregeln durchsetzen
     * wie der Kern für das Hauptfoto. Ohne diesen Test wäre der Umzug aus dem
     * Webroot nur eine Verschiebung: Die Datei läge woanders, wäre aber
     * weiterhin für jeden abrufbar.
     */
    public function testGalerieRouteFolgtDerVeroeffentlichung(): void {
        $admin = $this->authenticatedClient();
        $this->aktiviere($admin, 'galerie');

        $unique = uniqid();

        // Veroeffentlichtes Pferd: Bild wird ausgeliefert.
        $sichtbarId = $this->createHorse($admin, "GalerieOeffentlich-{$unique}", ['status' => 'active']);
        $sichtbaresMedium = $this->legeGalerieBildAn($sichtbarId, "oeff_{$unique}");

        $gast = $this->newClient();
        $antwort = $gast->get('/plugin/galerie/bild?id=' . $sichtbaresMedium);
        $this->assertSame(200, $antwort->statusCode, 'Das Bild eines veroeffentlichten Pferdes muss fuer Gaeste abrufbar sein.');
        $this->assertSame('image/png', $antwort->header('Content-Type'));
        $this->assertStringContainsString(
            'nosniff',
            (string) $antwort->header('X-Content-Type-Options'),
            'Ohne nosniff darf der Browser den Typ raten.'
        );
        $this->assertSame(
            'same-origin',
            $antwort->header('Cross-Origin-Resource-Policy'),
            'Der Einbettungsschutz des Kerns muss auch fuer Galeriebilder gelten.'
        );

        // Unveroeffentlichtes Pferd: 404 fuer Gaeste - das ist der Fall, den
        // das Advisory beschreibt.
        $verborgenId = $this->createHorse($admin, "GalerieVerborgen-{$unique}", ['is_published' => '0']);
        $verborgenesMedium = $this->legeGalerieBildAn($verborgenId, "verb_{$unique}");

        $verweigert = $gast->get('/plugin/galerie/bild?id=' . $verborgenesMedium);
        $this->assertSame(
            404,
            $verweigert->statusCode,
            'Das Bild eines unveroeffentlichten Pferdes darf fuer Gaeste nicht abrufbar sein (GHSA-xrrq-9j94-fr5g).'
        );

        // Unbekannte ID liefert dieselbe Antwort - die Route ist kein
        // Existenz-Orakel.
        $unbekannt = $gast->get('/plugin/galerie/bild?id=999999');
        $this->assertSame(404, $unbekannt->statusCode);
    }

    /**
     * Bedingte Anfragen an die Galerie-Route (#142).
     *
     * Die Route uebernahm die Sicherheitsregeln des Kerns wortgetreu, aber
     * keine seiner Entlastungen: kein ETag, kein Last-Modified, keine
     * 304-Behandlung. Eine Verwaltungsseite mit 50 Vorschaubildern holte
     * deshalb bei jedem Neuladen 50 Bilder vollstaendig - und fuer
     * unveroeffentlichte Pferde gilt `no-store`, dort half auch die
     * Jahresfrist nicht.
     */
    public function testGalerieRouteBeantwortetBedingteAnfragenMit304(): void {
        $admin = $this->authenticatedClient();
        $this->aktiviere($admin, 'galerie');
        $unique = uniqid();

        $pferdId = $this->createHorse($admin, "GalerieETag-{$unique}", ['status' => 'active']);
        $mediumId = $this->legeGalerieBildAn($pferdId, "etag_{$unique}");

        $gast = $this->newClient();
        $erste = $gast->get('/plugin/galerie/bild?id=' . $mediumId);

        $this->assertSame(200, $erste->statusCode);
        $etag = (string) $erste->header('ETag');
        $this->assertNotSame('', $etag, 'Ohne ETag kann der Browser gar nicht bedingt nachfragen.');
        $this->assertNotSame('', (string) $erste->header('Last-Modified'));

        $zweite = $gast->get('/plugin/galerie/bild?id=' . $mediumId, ['If-None-Match' => $etag]);
        $this->assertSame(304, $zweite->statusCode, 'Ein unveraendertes Bild muss mit 304 beantwortet werden.');
        $this->assertSame('', trim($zweite->body), 'Eine 304 darf keinen Rumpf haben.');

        // Und der Fall, der am meisten davon hat: ein unveroeffentlichtes
        // Pferd. Dort gilt `no-store`, der Browser darf also nichts ablegen -
        // er fragt aber trotzdem bedingt nach, und 304 spart die Uebertragung.
        $verborgenId = $this->createHorse($admin, "GalerieETagVerborgen-{$unique}", ['is_published' => '0']);
        $verborgenesMedium = $this->legeGalerieBildAn($verborgenId, "etagverb_{$unique}");

        $adminAntwort = $admin->get('/plugin/galerie/bild?id=' . $verborgenesMedium);
        $this->assertSame(200, $adminAntwort->statusCode);
        $this->assertStringContainsString('no-store', (string) $adminAntwort->header('Cache-Control'));

        $adminZweite = $admin->get(
            '/plugin/galerie/bild?id=' . $verborgenesMedium,
            ['If-None-Match' => (string) $adminAntwort->header('ETag')]
        );
        $this->assertSame(304, $adminZweite->statusCode);
    }

    /**
     * Die Sitzungssperre wird freigegeben, bevor die Datei gelesen wird (#142).
     *
     * WARUM DAS HIER AM QUELLTEXT UND NICHT UEBER HTTP GEPRUEFT WIRD: Die
     * Wirkung - 50 Vorschaubilder laufen parallel statt hintereinander - ist
     * in dieser Testumgebung grundsaetzlich nicht messbar. Die Functional-Suite
     * faehrt den PHP-eigenen Entwicklungsserver, und der bearbeitet ohnehin
     * nur EINE Anfrage zur Zeit; er serialisiert also unabhaengig davon, ob
     * PHP die Sitzungsdatei sperrt. Eine Zeitmessung waere hier kein Nachweis,
     * sondern eine Zufallszahl.
     *
     * Geprueft wird deshalb die Zusicherung selbst: dass der Aufruf da ist und
     * VOR der Auslieferung steht. Dasselbe Muster wie beim Signatur-Waechter
     * des Kerns (HorseSearchSqlSafetyTest) - eine Regel, die sich nur am Code
     * festhalten laesst, wird am Code festgehalten.
     */
    public function testGalerieGibtDieSitzungssperreVorDemAusliefernFrei(): void {
        $quelle = (string) file_get_contents(__DIR__ . '/../../plugins/galerie/Plugin.php');

        $anfang = strpos($quelle, 'public function serve(): void {');
        $this->assertNotFalse($anfang, 'BildController::serve() nicht gefunden - wurde umbenannt?');

        $freigabe = strpos($quelle, 'session_write_close();', $anfang);
        $ausliefern = strpos($quelle, 'readfile($pfad);', $anfang);

        $this->assertNotFalse($freigabe, 'serve() muss session_write_close() aufrufen - sonst laufen die Bildanfragen seriell.');
        $this->assertNotFalse($ausliefern, 'readfile() nicht gefunden.');
        $this->assertLessThan(
            $ausliefern,
            $freigabe,
            'session_write_close() muss VOR dem Ausliefern stehen, sonst bleibt die Sperre waehrend der Uebertragung bestehen.'
        );
    }
}
