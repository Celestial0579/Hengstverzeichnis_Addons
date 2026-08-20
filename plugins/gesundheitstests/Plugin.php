<?php
// gesundheitstests/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#15. Erfasst genetische
// Testergebnisse, Röntgenbefunde und Gesundheitszeugnisse strukturiert pro
// Pferd (statt unstrukturiert im freien description-Feld), inkl. optionalem
// Dokument-Upload. Gesundheitsdaten sind sensibel: jeder Eintrag ist
// standardmäßig NICHT öffentlich und muss explizit freigegeben werden
// (Opt-in), hochgeladene Dokumente liegen außerhalb des öffentlich
// erreichbaren Webroots und sind nur über eine zugriffsgeschützte
// Download-Route erreichbar.
//
// Installation (lokal im Framework-Repo):
//   cp -r gesundheitstests plugins/gesundheitstests
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Gesundheitstests -> Verwalten" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Gesundheitstests;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Router;
use App\Service\AuditLogger;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        // Kein ensureTable() mehr: Die Tabelle legt install() an, das der
        // PluginManager bei Aktivierung und nach jedem Addon-Update genau
        // einmal aufruft (Framework #75). Die frueher hier stehende Probe
        // ("SELECT 1 FROM ... LIMIT 1", sonst install() nachholen) war ein
        // Rueckfall fuer Kerne OHNE diesen Hook - den es laut der
        // core_compatibility-Untergrenze in plugin.json nicht mehr gibt.
        // Geblieben waere nur der Preis: eine zusaetzliche Abfrage pro Plugin
        // und Anfrage, bei sieben Addons also sieben Roundtrips, bevor die
        // erste Zeile der Seite steht.
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        // Seit #120 der einzige Pflegeweg: der Abschnitt im
        // Bearbeitungsformular des Pferdes. Die addoneigene Verwaltungsseite
        // und ihre Dashboard-Kachel sind entfallen - sie verlangten, dasselbe
        // Pferd über eine zweite Suche erneut herauszusuchen, obwohl man in
        // dessen Datensatz bereits steht. Die geschützte Download-Route
        // bleibt: Sie ist der einzige Weg zu den Dokumenten außerhalb des
        // Webroots.
        $hooks->addFilter('horse.edit_sections', [$this, 'addEditSection']);
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei der
     * Aktivierung und nach einem Addon-Update genau einmal auf - das
     * DDL-Statement läuft damit nicht mehr in jedem Request.
     */
    public function install(): void {
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_gesundheitstests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `test_type` VARCHAR(100) NOT NULL,
                `result_summary` TEXT NULL DEFAULT NULL,
                `file_name` VARCHAR(100) NULL DEFAULT NULL,
                `file_original_name` VARCHAR(255) NULL DEFAULT NULL,
                `file_mime` VARCHAR(100) NULL DEFAULT NULL,
                `is_public` TINYINT(1) NOT NULL DEFAULT 0,
                `issued_by` VARCHAR(150) NULL DEFAULT NULL,
                `issued_at` DATE NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    /**
     * Ablageverzeichnis für hochgeladene Dokumente: bewusst AUSSERHALB des
     * Webroots (public/), damit Dateien nie direkt per URL abrufbar sind,
     * sondern ausschließlich über die zugriffsgeschützte Download-Route.
     */
    public static function storageDir(): string {
        return dirname(__DIR__, 2) . '/storage/plugin_gesundheitstests';
    }

    /**
     * Filter-Beispiel: zeigt auf der öffentlichen Detailseite ausschließlich
     * die explizit als öffentlich markierten Einträge (Opt-in, Standard aus) -
     * Gesundheitsdaten erscheinen nie automatisch.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, test_type, result_summary, file_name, file_original_name, issued_by, issued_at
             FROM `plugin_gesundheitstests`
             WHERE horse_id = :id AND is_public = 1
             ORDER BY issued_at DESC, id DESC'
        );
        $stmt->execute(['id' => (int) $horse['id']]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($entries)) {
            return $sections;
        }

        $html = '<div style="margin-top:0.5rem;">';
        $html .= '<h3 style="margin-bottom:0.5rem;">🩺 DNA-/Gesundheitstests</h3>';
        $html .= '<table style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
            . '<th style="padding:0.4rem;">Test/Untersuchung</th><th style="padding:0.4rem;">Ergebnis</th>'
            . '<th style="padding:0.4rem;">Ausgestellt von</th><th style="padding:0.4rem;">Datum</th>'
            . '<th style="padding:0.4rem;">Dokument</th></tr></thead><tbody>';

        foreach ($entries as $row) {
            $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) $row['test_type'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['result_summary'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['issued_by'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['issued_at'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">';
            if (!empty($row['file_name'])) {
                $html .= '<a href="/plugin/gesundheitstests/download?id=' . (int) $row['id'] . '">'
                    . '📄 ' . htmlspecialchars((string) ($row['file_original_name'] ?: 'Dokument'), ENT_QUOTES, 'UTF-8') . '</a>';
            } else {
                $html .= '–';
            }
            $html .= '</td></tr>';
        }

        $html .= '</tbody></table></div>';

        $sections[] = $html;
        return $sections;
    }

    /**
     * Filter (#120, Framework#255): hängt die Dokumentpflege direkt in das
     * Admin-Bearbeitungsformular des Hengstes - wie zuvor schon bei
     * `titel-praemierungen` (#117) und `verkaufsboerse` (#119).
     *
     * Die `horse_id` ist hier durch die Seite bereits gegeben; die
     * Pferdeauswahl der entfallenen Verwaltungsseite (`horse_q` samt eigener
     * JSON-Suche) fällt damit ersatzlos weg (Addons#125). Geladen wird nur
     * noch, was zu diesem einen Pferd gehört.
     *
     * Zwei Dinge unterscheiden diesen Abschnitt von dem in #117:
     *
     * - **`enctype="multipart/form-data"`.** Der Abschnitt bringt sein eigenes
     *   Formular mit (der Hook setzt es ausserhalb des Kern-Formulars ab), es
     *   muss die Kodierung also selbst deklarieren - sonst käme der Upload als
     *   leeres $_FILES an, und zwar ohne Fehlermeldung. Dieselbe Falle wie bei
     *   der Galerie (#88).
     * - **`is_public` ist und bleibt ein Opt-in.** Gesundheitsdaten erscheinen
     *   nur, wenn der Eintrag ausdrücklich freigegeben ist; das Kästchen ist
     *   deshalb leer vorbelegt, und die Beschriftung sagt, was das Setzen
     *   bedeutet. Vorhandene Einträge lassen sich hier nicht nachträglich
     *   freigeben - dieselbe Beschränkung wie auf der alten Seite: Freigeben
     *   heißt neu erfassen, ein versehentlicher Klick in einer Liste kann
     *   Gesundheitsdaten also nicht öffentlich machen.
     */
    public function addEditSection(array $sections, array $horse): array {
        // Das Bearbeitungsformular verlangt horses.edit, diese Daten aber
        // gesundheitstests.manage. Ohne diese Prüfung sähe ein Redakteur ein
        // Formular, das beim Absenden 403 liefert. Fail-closed.
        if (!\App\Permission\GroupMembership::hasPermission(
            (int) ($_SESSION['user_id'] ?? 0), 'gesundheitstests', 'manage'
        )) {
            return $sections;
        }

        $horseId = (int) ($horse['id'] ?? 0);
        if ($horseId <= 0) {
            return $sections;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT id, test_type, result_summary, file_name, file_original_name, is_public, issued_by, issued_at
             FROM `plugin_gesundheitstests`
             WHERE horse_id = :id
             ORDER BY issued_at DESC, id DESC'
        );
        $stmt->execute(['id' => $horseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $html = '<h3 style="margin-top:0;">🩺 Gesundheitstests</h3>';

        if ($rows) {
            $html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:1rem;">';
            $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
                . '<th style="padding:0.4rem;">Test/Untersuchung</th><th style="padding:0.4rem;">Ergebnis</th>'
                . '<th style="padding:0.4rem;">Ausgestellt von</th><th style="padding:0.4rem;">Datum</th>'
                . '<th style="padding:0.4rem;">Öffentlich</th><th style="padding:0.4rem;">Dokument</th>'
                . '<th></th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
                $html .= '<td style="padding:0.4rem;">' . $esc($row['test_type']) . '</td>';
                $html .= '<td style="padding:0.4rem;">' . $esc($row['result_summary'] ?? '–') . '</td>';
                $html .= '<td style="padding:0.4rem;">' . $esc($row['issued_by'] ?? '–') . '</td>';
                $html .= '<td style="padding:0.4rem;">' . $esc($row['issued_at'] ?? '–') . '</td>';
                // Die Freigabe farblich ausgewiesen: Sie entscheidet darüber,
                // ob Gesundheitsdaten öffentlich sichtbar sind, und ist damit
                // die wichtigste Angabe der Zeile.
                $html .= '<td style="padding:0.4rem;">' . (!empty($row['is_public'])
                    ? '<span style="color:var(--warning-fg);font-weight:bold;">ja</span>'
                    : '<span style="color:var(--text-muted);">nein</span>') . '</td>';
                $html .= '<td style="padding:0.4rem;">';
                if (!empty($row['file_name'])) {
                    $html .= '<a href="/plugin/gesundheitstests/download?id=' . (int) $row['id'] . '">📄 '
                        . $esc($row['file_original_name'] ?: 'Dokument') . '</a>';
                } else {
                    $html .= '–';
                }
                $html .= '</td>';
                $html .= '<td style="padding:0.4rem;">'
                    . '<form method="POST" action="/plugin/gesundheitstests/verwaltung/delete" style="margin:0;"'
                    . ' onsubmit="return confirm(\'Eintrag (inkl. Dokument) wirklich löschen?\');">'
                    . '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">'
                    . '<input type="hidden" name="id" value="' . (int) $row['id'] . '">'
                    . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Löschen</button>'
                    . '</form></td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p style="color:var(--text-muted);">Für dieses Pferd ist noch kein Gesundheitstest erfasst.</p>';
        }

        // Eigenes Formular mit eigener POST-Route: Der Abschnitt steht
        // ausserhalb des Kern-Formulars (Framework#255), der Speichern-Knopf
        // oben speichert diese Felder also NICHT mit. Das enctype ist hier
        // NICHT optional - siehe Methodenkommentar.
        //
        // Die Feld-`id`s tragen das Präfix `gt_`: Das Kern-Formular auf
        // derselben Seite führt eigene Felder, und zwei gleiche id-Werte in
        // einem Dokument hängen das <label> an das falsche Feld. Die
        // `name`-Attribute bleiben unverändert - sie gelten je Formular und
        // sind der Vertrag mit store().
        $html .= '<h4 style="margin-bottom:0.5rem;">Weiteren Test erfassen</h4>';
        $html .= '<form method="POST" action="/plugin/gesundheitstests/verwaltung/store" enctype="multipart/form-data">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">';
        $html .= '<input type="hidden" name="horse_id" value="' . $horseId . '">';
        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">';
        $html .= '<div class="form-group"><label for="gt_test_type">Test-/Untersuchungsart</label>'
            . '<input type="text" name="test_type" id="gt_test_type" class="form-control" maxlength="100" required'
            . ' placeholder="z. B. DNA-Abstammungstest, Röntgen, Gesundheitszeugnis"></div>';
        $html .= '<div class="form-group"><label for="gt_issued_at">Ausgestellt am</label>'
            . '<input type="date" name="issued_at" id="gt_issued_at" class="form-control"></div>';
        $html .= '</div>';
        $html .= '<div class="form-group"><label for="gt_issued_by">Ausgestellt von</label>'
            . '<input type="text" name="issued_by" id="gt_issued_by" class="form-control" maxlength="150"'
            . ' placeholder="z. B. Labor, Tierklinik"></div>';
        $html .= '<div class="form-group"><label for="gt_result_summary">Ergebnis-Zusammenfassung</label>'
            . '<textarea name="result_summary" id="gt_result_summary" class="form-control" rows="3"></textarea></div>';
        $html .= '<div class="form-group"><label for="gt_document">Dokument (PDF oder Bild, max. 10 MB)</label>'
            . '<input type="file" name="document" id="gt_document" class="form-control" accept="application/pdf,image/jpeg,image/png,image/webp"></div>';
        $html .= '<p style="color:var(--text-muted);font-size:0.85rem;margin-top:0;">'
            . 'Hochgeladene Dokumente werden außerhalb des Webroots gespeichert und sind nur über die zugriffsgeschützte Download-Route erreichbar.</p>';
        $html .= '<div class="form-group"><label for="gt_is_public">'
            . '<input type="checkbox" name="is_public" id="gt_is_public" value="1"> '
            . 'Öffentlich sichtbar - der Eintrag erscheint dann samt Dokument auf der Pferdeseite für jeden Besucher'
            . '</label><br><span style="color:var(--text-muted);font-size:0.85rem;">'
            . 'Standard ist aus: Gesundheitsdaten erscheinen nie automatisch (Opt-in).</span></div>';
        // Beschriftung bewusst nicht "Speichern": Auf der Seite gibt es zwei
        // Knöpfe, und wer hier drückt, verliert ungespeicherte Stammdaten oben.
        $html .= '<p><button type="submit" class="btn">Test hinzufügen</button>'
            . ' <span style="color:var(--text-muted);font-size:0.85rem;">Änderungen an den Stammdaten oben bitte zuerst speichern.</span></p>';
        $html .= '</form>';

        $sections[] = $html;
        return $sections;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'gesundheitstests',
                'action' => 'manage',
                'label' => 'Verwalten',
                'module_label' => 'Gesundheitstests',
            ],
        ];
    }

    /**
     * Von den beiden früheren GET-Routen der Verwaltung bleibt keine (#120):
     * Die Seite ist in den Pferdeabschnitt gewandert, und die addoneigene
     * Pferdesuche (`/suche`) diente allein der Pferdeauswahl auf dieser Seite
     * - der Kern liefert sie seit Framework#341 unter /admin/horses/search
     * (Addons#125).
     *
     * `/download` bleibt: Die Dokumente liegen außerhalb des Webroots, und
     * diese Route ist der einzige Weg dorthin.
     *
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'POST', 'path' => '/verwaltung/store', 'callback' => [VerwaltungController::class, 'store']],
            ['method' => 'POST', 'path' => '/verwaltung/delete', 'callback' => [VerwaltungController::class, 'delete']],
            ['method' => 'GET', 'path' => '/download', 'callback' => [DownloadController::class, 'serve']],
        ];
    }
}

/**
 * Admin-Verwaltung der Test-/Gesundheitsdokumente. Zugriffsschutz über die
 * selbst registrierte Berechtigung "gesundheitstests.manage", analog zum
 * zuchtschau-ergebnisse-Muster.
 *
 * Seit #120 hat der Controller keine eigene Seite mehr: Beide Routen sind die
 * Ziele der Formulare im Abschnitt des Pferdeformulars
 * (Plugin::addEditSection). Der Name bleibt trotzdem `VerwaltungController` -
 * er steckt in den Pfaden `/verwaltung/store` und `/verwaltung/delete`, und
 * die umzubenennen hieße, die Formulare mitzuziehen, ohne dass sich dadurch
 * irgendetwas verbesserte.
 *
 * Mit der Seite entfallen sind: die Seitennummer-Auswertung samt Deckelung
 * (der Abschnitt zeigt die Einträge EINES Pferdes, da gibt es nichts zu
 * blättern) und die addoneigene Pferdesuche (Addons#125).
 */
class VerwaltungController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('gesundheitstests', 'manage');
    }

    public function store(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();

        // Die horse_id kommt seit #120 ausschließlich aus dem Aufrufkontext der
        // Pferdeseite (verstecktes Feld). Die frühere Auflösung eines
        // getippten Pferdenamens (`horse_q`) ist mit der Verwaltungsseite
        // entfallen - es gibt kein Textfeld mehr, das sie füllen könnte
        // (Addons#125).
        //
        // Existenz trotzdem prüfen: Ein erfundener Wert liefe sonst in den
        // FOREIGN-KEY-Fehler und damit in eine 500er-Seite, obwohl das schlicht
        // eine ungültige Eingabe ist.
        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;
        if ($horseId !== null && !self::pferdExistiert($db, $horseId)) {
            $horseId = null;
        }

        $testType = trim($_POST['test_type'] ?? '');

        if ($horseId && $testType !== '') {
            $upload = $this->handleDocumentUpload($_FILES['document'] ?? null);

            $stmt = $db->prepare(
                'INSERT INTO `plugin_gesundheitstests`
                    (horse_id, test_type, result_summary, file_name, file_original_name, file_mime, is_public, issued_by, issued_at)
                 VALUES (:horse_id, :test_type, :result_summary, :file_name, :file_original_name, :file_mime, :is_public, :issued_by, :issued_at)'
            );
            $istOeffentlich = !empty($_POST['is_public']);
            $stmt->execute([
                'horse_id' => $horseId,
                'test_type' => $testType,
                'result_summary' => trim($_POST['result_summary'] ?? '') ?: null,
                'file_name' => $upload['name'] ?? null,
                'file_original_name' => $upload['original'] ?? null,
                'file_mime' => $upload['mime'] ?? null,
                'is_public' => $istOeffentlich ? 1 : 0,
                'issued_by' => trim($_POST['issued_by'] ?? '') ?: null,
                'issued_at' => !empty($_POST['issued_at']) ? $_POST['issued_at'] : null,
            ]);

            // Protokoll (#134): Kategorie = Addon-Slug. Vermerkt wird der
            // Bezug (welcher Eintrag, welches Pferd), die Art der
            // Untersuchung und die Freigabe - das Opt-in entscheidet darüber,
            // ob Gesundheitsdaten öffentlich sichtbar werden, und gehört
            // deshalb in den Nachweis.
            //
            // NICHT hinein gehen: die Ergebnis-Zusammenfassung, der
            // Aussteller und der ursprüngliche Dateiname des Uploads. Das
            // Protokoll wird dauerhaft aufbewahrt, während die Gesundheitsdaten
            // selbst löschbar bleiben sollen - was hier landete, überlebte
            // genau die Löschung, um die es geht. Beim Dokument steht deshalb
            // der SELBST VERGEBENE Ablagename: Er benennt die Datei
            // eindeutig, ohne den (frei wählbaren, oft personenbezogenen)
            // Originalnamen mitzuschleppen.
            $eintragId = (int) $db->lastInsertId();
            AuditLogger::log(
                'Gesundheitstest-Eintrag angelegt',
                'gesundheitstests',
                "Eintrag #{$eintragId}, Pferd #{$horseId} (" . self::pferdeName($db, $horseId) . '), '
                    . "Art: {$testType}, öffentlich: " . ($istOeffentlich ? 'ja' : 'nein')
                    . ', Dokument: ' . ($upload['name'] ?? 'keins')
            );
        }

        $this->redirectBack($horseId);
    }

    public function delete(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        // Die horse_id entscheidet über den Rückweg (#120) und stammt deshalb
        // aus der gelesenen Zeile, nicht aus dem POST: Ein manipulierter Wert
        // schickte den Benutzer sonst in den Datensatz eines fremden Pferdes.
        $horseId = null;
        if ($id) {
            $db = Database::getInstance();
            // Testart und Pferd mitgelesen (#134): Nach dem DELETE ist beides
            // nicht mehr zu ermitteln, und "Dokument gelöscht" ohne Angabe,
            // welches und zu welchem Pferd, hilft niemandem. LEFT JOIN, damit
            // ein fehlendes Pferd die Zeile nicht verschwinden lässt.
            $stmt = $db->prepare(
                'SELECT g.file_name, g.test_type, g.is_public, g.horse_id, h.name AS horse_name
                 FROM `plugin_gesundheitstests` g
                 LEFT JOIN horses h ON h.id = g.horse_id
                 WHERE g.id = :id'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $horseId = (int) $row['horse_id'];
                $dateiVermerk = 'kein Dokument hinterlegt';
                if (!empty($row['file_name'])) {
                    // file_name ist ein selbst generierter basename ohne Pfadanteile
                    // (siehe handleDocumentUpload) - kein Traversal möglich.
                    $dateiName = basename((string) $row['file_name']);
                    $path = Plugin::storageDir() . '/' . $dateiName;
                    if (is_file($path)) {
                        @unlink($path);
                        // Nach dem unlink geprüft: Ein fehlgeschlagenes
                        // Entfernen darf nicht als "Dokument entfernt"
                        // protokolliert werden - sonst weist das Protokoll
                        // ausgerechnet die Datei als gelöscht aus, die noch
                        // in der Ablage liegt.
                        $dateiVermerk = is_file($path)
                            ? "Dokument {$dateiName} konnte NICHT entfernt werden"
                            : "Dokument {$dateiName} entfernt";
                    } else {
                        $dateiVermerk = "Dokument {$dateiName} war bereits nicht mehr in der Ablage";
                    }
                }

                $deleteStmt = $db->prepare('DELETE FROM `plugin_gesundheitstests` WHERE id = :id');
                $deleteStmt->execute(['id' => $id]);

                // Protokoll (#134): Der wichtigste Fall des ganzen Addons.
                // Gesundheitsdaten sind der heikelste Bestand im Verzeichnis,
                // und ihr Löschen verschwand bisher spurlos - das Protokoll
                // behauptete damit stillschweigend, es sei nichts geschehen.
                // Ohne Ergebnis-Zusammenfassung und ohne Originaldateiname
                // (siehe store()): Der Nachweis der Handlung darf nicht die
                // Inhalte konservieren, die gerade gelöscht wurden.
                AuditLogger::log(
                    'Gesundheitstest-Eintrag gelöscht',
                    'gesundheitstests',
                    "Eintrag #{$id}, Pferd #" . (int) $row['horse_id']
                        . ' (' . (string) ($row['horse_name'] ?? 'unbekannt') . '), '
                        . 'Art: ' . (string) $row['test_type']
                        . ', öffentlich: ' . (!empty($row['is_public']) ? 'ja' : 'nein')
                        . ', ' . $dateiVermerk
                );
            }
        }

        $this->redirectBack($horseId);
    }

    /** Gibt es dieses Pferd (und ist es nicht im Papierkorb)? */
    private static function pferdExistiert(PDO $db, int $horseId): bool {
        $stmt = $db->prepare('SELECT 1 FROM horses WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $horseId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Rückweg nach dem Speichern/Löschen. Bewusst KEINE übergebene URL,
     * sondern eine feste Adresse plus geprüfter Integer - eine mitgeschickte
     * Zieladresse wäre ein offener Redirect.
     *
     * Seit #120 gibt es dafür keinen Schalter mehr: Beide Formulare stehen im
     * Bearbeitungsformular des Pferdes, dorthin führt der Weg also immer
     * zurück. Nur wenn die horse_id nicht zu ermitteln war (POST von Hand,
     * Zeile inzwischen gelöscht), bleibt die Pferdeliste - die frühere
     * Verwaltungsseite gibt es nicht mehr, ein Verweis auf sie endete in 404.
     */
    private function redirectBack(?int $horseId): never {
        header('Location: ' . ($horseId !== null && $horseId > 0
            ? '/admin/horses/edit?id=' . $horseId
            : '/admin/horses'));
        exit;
    }

    /**
     * Name eines Pferdes für den Protokolleintrag (#134). Eine reine ID ist
     * im Protokoll wertlos, sobald das Pferd selbst gelöscht ist - der Name
     * bleibt lesbar. Fällt auf "unbekannt" zurück statt zu scheitern: Ein
     * Protokolleintrag darf nie die Ursache dafür sein, dass die eigentliche
     * Handlung abbricht.
     */
    private static function pferdeName(PDO $db, int $horseId): string {
        $stmt = $db->prepare('SELECT name FROM horses WHERE id = ?');
        $stmt->execute([$horseId]);
        $name = $stmt->fetchColumn();

        return $name !== false ? (string) $name : 'unbekannt';
    }

    /**
     * Gleiches Validierungsmuster wie HorseController::handleImageUpload() im
     * Kern (echte MIME-Prüfung per finfo statt Dateiendung, Zufallsname),
     * erweitert um PDF und mit Ablage außerhalb des Webroots.
     *
     * @return array{name:string, original:string, mime:string}|null
     */
    private function handleDocumentUpload(?array $file): ?array {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) === 0) {
            return null;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            return null;
        }

        $allowedMimeTypes = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!isset($allowedMimeTypes[$mime])) {
            return null;
        }

        $storageDir = Plugin::storageDir();
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0750, true);
        }

        $filename = 'gtest_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $allowedMimeTypes[$mime];
        if (!move_uploaded_file($file['tmp_name'], $storageDir . '/' . $filename)) {
            return null;
        }

        return [
            'name' => $filename,
            'original' => basename((string) $file['name']),
            'mime' => $mime,
        ];
    }
}

/**
 * Zugriffsgeschützte Auslieferung hochgeladener Dokumente. Öffentlich sind
 * ausschließlich Dokumente von als öffentlich markierten Einträgen zu
 * veröffentlichten Pferden - und auch nur, wenn die Gast-Gruppe Pferde sehen
 * darf (identisches Gating wie die Detailseite im Kern). Alle übrigen
 * Dokumente erfordern die Verwaltungs-Berechtigung; unbekannte, gelöschte und
 * nicht zugängliche IDs liefern eine identische 404, damit die Route kein
 * Existenz-Orakel wird.
 */
class DownloadController extends BaseController {

    public function serve(): void {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        $entry = null;
        if ($id) {
            $stmt = Database::getInstance()->prepare(
                'SELECT g.file_name, g.file_original_name, g.file_mime, g.is_public, h.is_published
                 FROM `plugin_gesundheitstests` g
                 JOIN horses h ON h.id = g.horse_id AND h.deleted_at IS NULL
                 WHERE g.id = ?'
            );
            $stmt->execute([$id]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $publiclyVisible = $entry
            && !empty($entry['is_public'])
            && !empty($entry['is_published'])
            && $this->hasPermission('horses', 'view');

        // Der Verwaltungs-Zweig ist bewusst an eine angemeldete Sitzung
        // gebunden (Framework#218): Die Gast-Gruppe `public` ist im Kern frei
        // editierbar - ein Rechte-Fehlgriff dort (etwa "Berechtigungen
        // kopieren von Administrator" schreibt `gesundheitstests.manage` in
        // die Gast-Gruppe) darf diese Route für Anonyme nie öffnen. Für
        // Besucher ohne Sitzung zählt deshalb ausschließlich der
        // Opt-in-Pfad $publiclyVisible.
        // ... und die Sitzung muss dieselbe Prüfung bestehen wie überall
        // sonst im Backend. `isset($_SESSION['user_id'])` allein fragt nur, ob
        // irgendwann einmal jemand angemeldet war: Eine Sitzung, deren Konto
        // gelöscht wurde, deren Passwort anderswo geändert wurde (#113,
        // session_version), die von einem anderen User-Agent kommt oder die
        // längst abgelaufen ist, flöge über checkAuth() überall hinaus - hier
        // aber lieferte sie weiterhin Gesundheitsdokumente aus.
        //
        // checkAuth() wird nur betreten, wenn der öffentliche Opt-in-Pfad NICHT
        // greift: Es leitet Sitzungslose auf /login um, und das wäre für einen
        // anonymen Abruf eines freigegebenen Dokuments die falsche Antwort.
        $managerAccess = false;
        if (!$publiclyVisible && isset($_SESSION['user_id'])) {
            $this->checkAuth();
            $managerAccess = $this->hasPermission('gesundheitstests', 'manage');
        }

        if (!$entry || empty($entry['file_name'])
            || (!$publiclyVisible && !$managerAccess)) {
            $this->renderNotFound('Dokument nicht gefunden.');
        }

        $path = Plugin::storageDir() . '/' . basename((string) $entry['file_name']);
        if (!is_file($path)) {
            $this->renderNotFound('Dokument nicht gefunden.');
        }

        $originalName = (string) ($entry['file_original_name'] ?: 'dokument');
        // Nur ASCII-sicheren Dateinamen im Header verwenden.
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'dokument';

        header('Content-Type: ' . ($entry['file_mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
