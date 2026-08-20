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
        // `contact_email` ist Pflicht (VARCHAR NOT NULL). Fehlt es, entsteht
        // gar kein Inserat - die Boersenseite ist dann leer, und die
        // Abwesenheitspruefung unten waere gruen, ohne irgendetwas geprueft zu
        // haben. Genau dieser Fall ist beim Bau dieses Tests aufgetreten und
        // erst durch die Gegenprobe aufgefallen; deshalb steht darunter eine
        // ausdrueckliche Wirksamkeitspruefung.
        $verwaltung = $admin->get('/plugin/verkaufsboerse/verwaltung');
        $this->assertSame(200, $verwaltung->statusCode);
        $admin->post('/plugin/verkaufsboerse/verwaltung/store', [
            'csrf_token' => $verwaltung->formField('csrf_token') ?? '',
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
}
