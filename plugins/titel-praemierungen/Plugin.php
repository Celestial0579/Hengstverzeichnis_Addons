<?php
// titel-praemierungen/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#81. Der v2-Altbestand führt je
// Pferd `titel[]`, `praemierungen[]` und `erfolge[]` als Listen - in Gen 3
// landeten diese Werte mangels Gegenstück bisher als Textblöcke in
// `horses.description` (nicht filterbar, nicht einzeln pflegbar). Dieses
// Addon ergänzt strukturierte Auszeichnungen (Art, Bezeichnung, Jahr,
// Kommentar) je Pferd und zeigt sie auf der öffentlichen Detailseite an;
// das Migrationstool kann die Altdaten damit strukturiert einspielen.
//
// Installation (lokal im Framework-Repo):
//   cp -r titel-praemierungen plugins/titel-praemierungen
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Titel & Prämierungen -> Verwalten" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\TitelPraemierungen;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use App\Router;
use PDO;

class Plugin {

    /**
     * Anzeige-Beschriftungen je Art - zugleich die Whitelist für store():
     * Nur diese drei Schlüssel erreichen das ENUM in der Datenbank, ein
     * beliebiger POST-Wert würde sonst (je nach SQL-Mode) still zu ''
     * verstümmelt statt abgelehnt.
     */
    public const ART_LABELS = [
        'titel' => 'Titel',
        'praemierung' => 'Prämierung',
        'erfolg' => 'Erfolg',
    ];

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
        // Seit #117 der einzige Pflegeweg: der Abschnitt im
        // Bearbeitungsformular des Pferdes. Die addoneigene Verwaltungsseite
        // und ihre Dashboard-Kachel sind entfallen - sie verlangten, dasselbe
        // Pferd über eine zweite Suche erneut herauszusuchen, obwohl man in
        // dessen Datensatz bereits steht.
        $hooks->addFilter('horse.edit_sections', [$this, 'addEditSection']);
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei der
     * Aktivierung und nach einem Addon-Update genau einmal auf - das
     * DDL-Statement läuft damit nicht mehr in jedem Request.
     */
    public function install(): void {
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_titel_praemierungen` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `art` ENUM(\'titel\',\'praemierung\',\'erfolg\') NOT NULL,
                `bezeichnung` VARCHAR(200) NOT NULL,
                `jahr` SMALLINT UNSIGNED NULL DEFAULT NULL,
                `kommentar` TEXT NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    /**
     * Filter (#87, Framework#255): hängt die Erfassung direkt in das
     * Admin-Bearbeitungsformular des Hengstes.
     *
     * Das ist der eigentliche Fix für #87: Im Pferdekontext ist die horse_id
     * durch die Seite bereits gegeben - die Auswahl über den gesamten Bestand
     * entfällt ersatzlos, und geladen wird nur noch, was zu diesem einen Pferd
     * gehört.
     *
     * Seit #117 ist dieser Abschnitt der EINZIGE Pflegeweg - die
     * Verwaltungsseite ist entfallen. Damit dabei keine Fähigkeit verloren
     * geht, kann hier jeder vorhandene Eintrag auch geändert werden (Art,
     * Bezeichnung, Jahr, Kommentar); die alte Seite konnte nur anlegen und
     * löschen. Das Pferd selbst ist bewusst nicht änderbar: Es kommt aus dem
     * Aufrufkontext, und eine Auszeichnung im Datensatz von Pferd A auf Pferd
     * B umzuhängen wäre nicht Pflege, sondern eine Verwechslungsquelle -
     * dafür ist Löschen und Neuanlegen der ehrlichere Weg.
     *
     * Auf einem Kern ohne den Hook erscheint der Abschnitt nicht; die
     * core_compatibility-Untergrenze in plugin.json schließt solche Kerne aus.
     */
    public function addEditSection(array $sections, array $horse): array {
        // Das Bearbeitungsformular verlangt horses.edit, diese Daten aber
        // titel-praemierungen.manage. Ohne diese Prüfung sähe ein Redakteur ein
        // Formular, das beim Absenden 403 liefert. Fail-closed.
        if (!\App\Permission\GroupMembership::hasPermission(
            (int) ($_SESSION['user_id'] ?? 0), 'titel-praemierungen', 'manage'
        )) {
            return $sections;
        }

        $horseId = (int) ($horse['id'] ?? 0);
        if ($horseId <= 0) {
            return $sections;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT id, art, bezeichnung, jahr, kommentar
             FROM `plugin_titel_praemierungen`
             WHERE horse_id = :id
             ORDER BY FIELD(art, \'titel\', \'praemierung\', \'erfolg\'), jahr DESC, id DESC'
        );
        $stmt->execute(['id' => $horseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $csrfToken = Router::generateCsrfToken();
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $html = '<h3 style="margin-top:0;">🏅 Titel &amp; Prämierungen</h3>';

        if ($rows) {
            // Je Eintrag ein eigener Block statt einer Tabellenzeile (#117):
            // Ändern verlangt Eingabefelder, und ein Formular darf nicht über
            // mehrere Tabellenzellen hinweg stehen. Änderungs- und
            // Löschformular sind zwei getrennte Formulare - HTML kennt keine
            // verschachtelten.
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $html .= '<div style="border:1px solid var(--border-color);border-radius:var(--border-radius, 4px);padding:0.75rem;margin-bottom:0.75rem;">';

                $html .= '<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:0.5rem;">';
                $html .= '<strong>' . $esc(Plugin::ART_LABELS[$row['art']] ?? $row['art'])
                    . ': ' . $esc($row['bezeichnung'])
                    . ($row['jahr'] !== null ? ' (' . (int) $row['jahr'] . ')' : '') . '</strong>';
                $html .= '<form method="POST" action="/plugin/titel-praemierungen/auszeichnungen/delete" style="margin:0;"'
                    . ' onsubmit="return confirm(\'Auszeichnung wirklich löschen?\');">'
                    . '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">'
                    . '<input type="hidden" name="id" value="' . $id . '">'
                    . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Löschen</button>'
                    . '</form>';
                $html .= '</div>';

                // Änderungsformular. Die horse_id reist NICHT mit: update()
                // liest sie aus der Zeile, damit ein manipulierter POST eine
                // Auszeichnung nicht an ein fremdes Pferd hängen kann.
                $html .= '<form method="POST" action="/plugin/titel-praemierungen/auszeichnungen/update" style="margin:0;">';
                $html .= '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">';
                $html .= '<input type="hidden" name="id" value="' . $id . '">';
                $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">';
                $html .= '<div class="form-group"><label for="tp_art_' . $id . '">Art</label>'
                    . '<select name="art" id="tp_art_' . $id . '" class="form-control" required>';
                foreach (Plugin::ART_LABELS as $value => $label) {
                    $html .= '<option value="' . $esc($value) . '"'
                        . ($row['art'] === $value ? ' selected' : '') . '>' . $esc($label) . '</option>';
                }
                $html .= '</select></div>';
                $html .= '<div class="form-group"><label for="tp_jahr_' . $id . '">Jahr</label>'
                    . '<input type="number" name="jahr" id="tp_jahr_' . $id . '" class="form-control" min="1900" max="2155"'
                    . ' value="' . ($row['jahr'] !== null ? (int) $row['jahr'] : '') . '"></div>';
                $html .= '</div>';
                $html .= '<div class="form-group"><label for="tp_bezeichnung_' . $id . '">Bezeichnung</label>'
                    . '<input type="text" name="bezeichnung" id="tp_bezeichnung_' . $id . '" class="form-control" maxlength="200" required'
                    . ' value="' . $esc($row['bezeichnung']) . '"></div>';
                $html .= '<div class="form-group"><label for="tp_kommentar_' . $id . '">Kommentar</label>'
                    . '<textarea name="kommentar" id="tp_kommentar_' . $id . '" class="form-control" rows="2">'
                    . $esc($row['kommentar'] ?? '') . '</textarea></div>';
                // Wie beim Anlegen bewusst nicht "Speichern": Der Knopf oben
                // speichert die Stammdaten, dieser nur diese Auszeichnung.
                $html .= '<p style="margin:0;"><button type="submit" class="btn btn-secondary">Änderung speichern</button></p>';
                $html .= '</form>';

                $html .= '</div>';
            }
        } else {
            $html .= '<p style="color:var(--text-muted);">Für dieses Pferd ist noch keine Auszeichnung erfasst.</p>';
        }

        // Eigenes Formular mit eigener POST-Route: Der Abschnitt steht
        // ausserhalb des Kern-Formulars (Framework#255), der Speichern-Knopf
        // oben speichert diese Felder also NICHT mit.
        //
        // Ein Feld `zurueck` gibt es seit #117 nicht mehr: Es unterschied den
        // Rückweg zur Pferdeseite von dem zur Verwaltungsseite, und die gibt
        // es nicht mehr. Der Rückweg steht damit fest, siehe redirectBack().
        $html .= '<h4 style="margin-bottom:0.5rem;">Weitere Auszeichnung erfassen</h4>';
        $html .= '<form method="POST" action="/plugin/titel-praemierungen/auszeichnungen/store">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">';
        $html .= '<input type="hidden" name="horse_id" value="' . $horseId . '">';
        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">';
        $html .= '<div class="form-group"><label for="tp_art">Art</label>'
            . '<select name="art" id="tp_art" class="form-control" required>';
        foreach (Plugin::ART_LABELS as $value => $label) {
            $html .= '<option value="' . $esc($value) . '">' . $esc($label) . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="form-group"><label for="tp_jahr">Jahr</label>'
            . '<input type="number" name="jahr" id="tp_jahr" class="form-control" min="1900" max="2155" placeholder="z. B. 2019"></div>';
        $html .= '</div>';
        $html .= '<div class="form-group"><label for="tp_bezeichnung">Bezeichnung</label>'
            . '<input type="text" name="bezeichnung" id="tp_bezeichnung" class="form-control" maxlength="200" required'
            . ' placeholder="z. B. Elitehengst, Bundeschampion"></div>';
        $html .= '<div class="form-group"><label for="tp_kommentar">Kommentar</label>'
            . '<textarea name="kommentar" id="tp_kommentar" class="form-control" rows="2"></textarea></div>';
        // Beschriftung bewusst nicht "Speichern": Auf der Seite gibt es zwei
        // Knöpfe, und wer hier drückt, verliert ungespeicherte Stammdaten oben.
        $html .= '<p><button type="submit" class="btn">Auszeichnung hinzufügen</button>'
            . ' <span style="color:var(--text-muted);font-size:0.85rem;">Änderungen an den Stammdaten oben bitte zuerst speichern.</span></p>';
        $html .= '</form>';

        $sections[] = $html;
        return $sections;
    }

    /**
     * Filter: hängt die erfassten Auszeichnungen des Pferdes an die
     * öffentliche Detailseite an - gruppiert nach Art (Titel zuerst),
     * innerhalb der Art neueste Jahre oben. Zeigt nichts an, wenn noch
     * keine Auszeichnung erfasst ist (kein leerer Abschnitt).
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $horseId = (int) $horse['id'];

        $stmt = Database::getInstance()->prepare(
            'SELECT art, bezeichnung, jahr, kommentar
             FROM `plugin_titel_praemierungen`
             WHERE horse_id = :id
             ORDER BY FIELD(art, \'titel\', \'praemierung\', \'erfolg\'), jahr DESC, id DESC'
        );
        $stmt->execute(['id' => $horseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return $sections;
        }

        $html = '<div style="margin-top:0.5rem;">';
        $html .= '<h3 style="margin-bottom:0.5rem;">🏅 Titel &amp; Prämierungen</h3>';
        $html .= '<table style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
            . '<th style="padding:0.4rem;">Art</th><th style="padding:0.4rem;">Bezeichnung</th>'
            . '<th style="padding:0.4rem;">Jahr</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $artLabel = self::ART_LABELS[$row['art']] ?? (string) $row['art'];
            $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars($artLabel, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) $row['bezeichnung'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['jahr'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
            if (!empty($row['kommentar'])) {
                $html .= '<tr style="border-bottom:1px solid var(--border-color);"><td colspan="3" style="padding:0 0.4rem 0.5rem 0.4rem;color:var(--text-muted);font-size:0.9em;">'
                    . htmlspecialchars((string) $row['kommentar'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
        }

        $html .= '</tbody></table></div>';

        $sections[] = $html;
        return $sections;
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'titel-praemierungen',
                'action' => 'manage',
                'label' => 'Verwalten',
                'module_label' => 'Titel & Prämierungen',
            ],
        ];
    }

    /**
     * Nur noch schreibende Routen (#117): Die beiden GET-Routen führten auf
     * die entfallene Verwaltungsseite und deren AJAX-Pferdesuche. Was bleibt,
     * sind die Ziele der Formulare im Pferdeabschnitt.
     *
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            [
                'method' => 'POST',
                'path' => '/auszeichnungen/store',
                'callback' => [AuszeichnungenController::class, 'store'],
            ],
            [
                'method' => 'POST',
                'path' => '/auszeichnungen/update',
                'callback' => [AuszeichnungenController::class, 'update'],
            ],
            [
                'method' => 'POST',
                'path' => '/auszeichnungen/delete',
                'callback' => [AuszeichnungenController::class, 'delete'],
            ],
        ];
    }
}

/**
 * Admin-Verwaltung der Titel/Prämierungen/Erfolge. Zugriffsschutz über die
 * selbst registrierte Berechtigung "titel-praemierungen.manage", analog zum
 * zuchtschau-ergebnisse-Muster (ErgebnisseController).
 */
class AuszeichnungenController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('titel-praemierungen', 'manage');
    }

    public function store(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();
        // Die horse_id kommt seit #117 ausschließlich aus dem Aufrufkontext der
        // Pferdeseite (verstecktes Feld). Die frühere Auflösung eines
        // getippten Pferdenamens (`horse_q`) ist mit der Verwaltungsseite
        // entfallen - es gibt kein Textfeld mehr, das sie füllen könnte.
        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;
        // Existenz prüfen, statt es dem Fremdschlüssel zu überlassen: Ein
        // erfundener Wert liefe sonst in eine PDOException und damit in eine
        // 500er-Seite, obwohl das schlicht eine ungültige Eingabe ist.
        if ($horseId !== null && !self::pferdExistiert($db, $horseId)) {
            $horseId = null;
        }
        $art = trim($_POST['art'] ?? '');
        $bezeichnung = trim($_POST['bezeichnung'] ?? '');

        $jahr = self::jahrAusEingabe($_POST['jahr'] ?? null);

        if ($horseId && $bezeichnung !== '' && isset(Plugin::ART_LABELS[$art])) {
            $stmt = $db->prepare(
                'INSERT INTO `plugin_titel_praemierungen` (horse_id, art, bezeichnung, jahr, kommentar)
                 VALUES (:horse_id, :art, :bezeichnung, :jahr, :kommentar)'
            );
            $stmt->execute([
                'horse_id' => $horseId,
                'art' => $art,
                'bezeichnung' => $bezeichnung,
                'jahr' => $jahr,
                'kommentar' => trim($_POST['kommentar'] ?? '') ?: null,
            ]);
        }

        $this->redirectBack($horseId);
    }

    /**
     * Ändert eine vorhandene Auszeichnung (#117).
     *
     * Das konnte bis dahin weder die Verwaltungsseite noch der Pferdeabschnitt:
     * Wer sich vertippt hatte, musste löschen und neu erfassen. Mit dem Wegfall
     * der Verwaltungsseite wäre der Pferdeabschnitt der einzige Pflegeweg
     * geblieben - also gehört das Ändern dort hinein, sonst nähme #117 unterm
     * Strich eine Fähigkeit weg, statt nur einen doppelten Weg zu schließen.
     *
     * Die horse_id stammt aus der Zeile, nicht aus dem POST: Sie entscheidet
     * über den Rückweg, und ein manipulierter Wert würde die Auszeichnung
     * sonst im Datensatz eines fremden Pferdes anzeigen lassen.
     */
    public function update(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        if ($id === null) {
            $this->redirectBack(null);
        }

        $stmt = $db->prepare('SELECT horse_id FROM `plugin_titel_praemierungen` WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $found = $stmt->fetchColumn();
        $horseId = $found !== false ? (int) $found : null;
        if ($horseId === null) {
            $this->redirectBack(null);
        }

        $art = trim($_POST['art'] ?? '');
        $bezeichnung = trim($_POST['bezeichnung'] ?? '');
        $jahr = self::jahrAusEingabe($_POST['jahr'] ?? null);

        // Dieselbe Whitelist und dieselbe Pflichtangabe wie beim Anlegen -
        // eine Änderung darf keine Zeile hinterlassen, die store() so nie
        // angelegt hätte. Unvollständiges wird still verworfen, der Rückweg
        // führt auf das Formular mit den unveränderten Werten.
        if ($bezeichnung !== '' && isset(Plugin::ART_LABELS[$art])) {
            $stmt = $db->prepare(
                'UPDATE `plugin_titel_praemierungen`
                    SET art = :art, bezeichnung = :bezeichnung, jahr = :jahr, kommentar = :kommentar
                  WHERE id = :id'
            );
            $stmt->execute([
                'art' => $art,
                'bezeichnung' => $bezeichnung,
                'jahr' => $jahr,
                'kommentar' => trim($_POST['kommentar'] ?? '') ?: null,
                'id' => $id,
            ]);
        }

        $this->redirectBack($horseId);
    }

    public function delete(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

        // Die horse_id VOR dem DELETE aus der Zeile lesen - danach ist sie
        // nicht mehr zu holen, und dem POST allein ist sie nicht zu glauben.
        $horseId = null;
        if ($id) {
            $stmt = $db->prepare('SELECT horse_id FROM `plugin_titel_praemierungen` WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $found = $stmt->fetchColumn();
            $horseId = $found !== false ? (int) $found : null;

            $stmt = $db->prepare('DELETE FROM `plugin_titel_praemierungen` WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        $this->redirectBack($horseId);
    }

    /**
     * Jahr nur als plausibler vierstelliger Wert - alles andere wird bewusst
     * zu NULL (das Feld ist optional, kein Grund zum Abbruch). Seit #117 an
     * zwei Stellen gebraucht (store() und update()), deshalb hier.
     */
    private static function jahrAusEingabe(mixed $eingabe): ?int {
        if ($eingabe === null) {
            return null;
        }
        $wert = trim((string) $eingabe);
        return preg_match('/^\d{4}$/', $wert) ? (int) $wert : null;
    }

    /** Gibt es dieses Pferd (und ist es nicht im Papierkorb)? */
    private static function pferdExistiert(PDO $db, int $horseId): bool {
        $stmt = $db->prepare('SELECT 1 FROM horses WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $horseId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Rückweg nach dem Speichern/Ändern/Löschen. Bewusst KEINE übergebene
     * URL, sondern eine feste Adresse plus geprüfter Integer - eine
     * mitgeschickte Zieladresse wäre ein offener Redirect.
     *
     * Seit #117 gibt es dafür keinen Schalter mehr: Alle drei Formulare
     * stehen im Bearbeitungsformular des Pferdes, dorthin führt der Weg also
     * immer zurück. Nur wenn die horse_id nicht zu ermitteln war (POST von
     * Hand, Zeile inzwischen gelöscht), bleibt die Pferdeliste - die frühere
     * Verwaltungsseite gibt es nicht mehr, ein Verweis auf sie endete in 404.
     */
    private function redirectBack(?int $horseId): never {
        header('Location: ' . ($horseId !== null && $horseId > 0
            ? '/admin/horses/edit?id=' . $horseId
            : '/admin/horses'));
        exit;
    }
}
