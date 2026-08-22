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
 * SEIT FRAMEWORK#339 IST DIE GALERIE KERNMODUL. Das Addon `galerie` und
 * seine Route `/plugin/galerie/bild` gibt es nicht mehr; die drei Faelle, die
 * hier deren Sichtbarkeitsregeln geprueft haben, stehen jetzt im Kern
 * (tests/Functional/HorseMediaTest.php dort). Die Regel dieses Tests bleibt
 * unveraendert und gilt weiter fuer jedes Addon, das Fotos rendert.
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
    private const SLUGS = ['merkliste', 'verkaufsboerse', 'qr-code'];

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

    /**
     * Die eigentliche Zusicherung: In einer öffentlichen Antwort steht kein
     * roher Speicherort. Geprüft wird die ABWESENHEIT - ein Test auf
     * „enthält /media/horse-image" bliebe grün, wenn daneben weiterhin ein
     * roher Pfad stünde, und genau das war der Zustand des Advisories.
     */
    private function assertKeinRoherUploadPfad(string $body, string $wo): void {
        $normalisiert = str_replace('\\/', '/', $body);

        $this->assertStringNotContainsString(
            '/uploads/horses/',
            $normalisiert,
            "{$wo}: Der rohe Speicherort des Kernfotos darf in einer oeffentlichen Antwort nicht vorkommen "
            . '(GHSA-xrrq-9j94-fr5g) - er macht den Dateinamen dauerhaft bekannt und ueberlebt jede Depublikation.'
        );
        // Bleibt stehen, obwohl es das Addon nicht mehr gibt: Auf einer
        // Bestandsinstallation koennen Zeilen und Dateien des abgeloesten
        // Addons noch herumliegen, bis die Uebernahme gelaufen ist - und in
        // eine oeffentliche Antwort gehoert ihr Pfad danach so wenig wie
        // vorher.
        $this->assertStringNotContainsString(
            '/uploads/plugin_galerie/',
            $normalisiert,
            "{$wo}: Der Speicherort der abgeloesten Galerie darf in einer oeffentlichen Antwort nicht "
            . 'vorkommen (GHSA-xrrq-9j94-fr5g).'
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

        // 4. Die oeffentliche Detailseite selbst - sie rendert seit
        //    Framework#339 die Medien im KERN, nicht mehr ueber ein Addon.
        //    Die Abwesenheitspruefung gilt hier weiter: Kein Addon darf auf
        //    dieser Seite einen rohen Upload-Pfad hinterlassen.
        $detail = $gast->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertKeinRoherUploadPfad($detail->body, '/horse');
    }
}
