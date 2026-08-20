<?php
// zuchtschau-ergebnisse/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#14. Der Kern-Status eines Pferdes
// (`active` = "Aktiv (Gekört)") ist nur ein binäres Flag - dieses Addon
// ergänzt strukturierte Ergebnisse einzelner Zuchtschauen/Körungen
// (Veranstaltung, Note, Richter, Platzierung, Kommentar) und zeigt sie
// chronologisch auf der öffentlichen Pferde-Detailseite.
//
// Installation (lokal im Framework-Repo):
//   cp -r zuchtschau-ergebnisse plugins/zuchtschau-ergebnisse
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// der gewünschten Gruppe unter /admin/groups die Berechtigung
// "Zuchtschau-Ergebnisse -> Verwalten" zuweisen.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\ZuchtschauErgebnisse;

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
        // Seit #124 der einzige Pflegeweg: der Abschnitt im
        // Bearbeitungsformular des Pferdes. Die addoneigene Ergebnisseite ist
        // entfallen - sie verlangte, dasselbe Pferd über eine zweite Auswahl
        // erneut herauszusuchen, obwohl man in dessen Datensatz bereits steht.
        $hooks->addFilter('horse.edit_sections', [$this, 'addEditSection']);
    }

    /**
     * Filter (#124, Framework#255): hängt die Ergebnispflege SAMT
     * Teilwertungen direkt in das Admin-Bearbeitungsformular des Hengstes -
     * wie zuvor schon bei `titel-praemierungen` (#117), `verkaufsboerse`
     * (#119) und `gesundheitstests` (#120).
     *
     * Der aufwendigste der fünf Fälle, weil hier ZWEI Ebenen aneinander
     * hängen: Eine Teilwertung gehört zu einem Ergebnis, das erst gespeichert
     * sein muß. Der Ablauf ist deshalb: Ergebnis anlegen (Formular unten) ->
     * Rückweg auf dieselbe Seite -> Teilwertungen im Block dieses Ergebnisses
     * erfassen. Damit der Benutzer dabei nicht die Stelle verliert, führen
     * alle Rückwege der Teilwertungs-Routen einen Anker auf den Block des
     * Ergebnisses mit (`#zs-ergebnis-<id>`, siehe
     * ErgebnisseController::redirectBack()) - das ist das
     * `zurueck=pferd`-Muster aus #88, um die `ergebnis_id` erweitert.
     *
     * Wie auf der entfallenen Seite trägt der Abschnitt anlegen und löschen,
     * kein Ändern: Das ist der Umfang, den store() und storeTeilwertung()
     * kennen; ein Änderungsweg wäre eine neue Fähigkeit, kein Nachzug.
     */
    public function addEditSection(array $sections, array $horse): array {
        // Das Bearbeitungsformular verlangt horses.edit, diese Daten aber
        // zuchtschau-ergebnisse.manage. Ohne diese Prüfung sähe ein Redakteur
        // ein Formular, das beim Absenden 403 liefert. Fail-closed.
        if (!\App\Permission\GroupMembership::hasPermission(
            (int) ($_SESSION['user_id'] ?? 0), 'zuchtschau-ergebnisse', 'manage'
        )) {
            return $sections;
        }

        $horseId = (int) ($horse['id'] ?? 0);
        if ($horseId <= 0) {
            return $sections;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id, event_name, event_date, category, score, judge, placement, `comment`
             FROM `plugin_zuchtschau_ergebnisse`
             WHERE horse_id = :id
             ORDER BY event_date DESC, id DESC'
        );
        $stmt->execute(['id' => $horseId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Teilwertungen (#82) der angezeigten Ergebnisse in EINEM Zug laden
        // und nach Ergebnis gruppieren - erspart eine Query je Ergebniszeile.
        // Eine Obergrenze braucht es hier nicht mehr: Der Abschnitt zeigt die
        // Ergebnisse EINES Pferdes, nicht mehr den gesamten Bestand - genau
        // deshalb hatte die alte Seite eine (HORSE_OPTION_LIMIT/RESULT_LIMIT).
        $teilwertungenByErgebnis = [];
        $ergebnisIds = array_map(static fn (array $r): int => (int) $r['id'], $results);
        if ($ergebnisIds !== []) {
            $platzhalter = implode(',', array_fill(0, count($ergebnisIds), '?'));
            $twStmt = $db->prepare(
                'SELECT id, ergebnis_id, bezeichnung, wertung, note, platzierung, distanz, zeit
                 FROM `plugin_zuchtschau_teilwertungen`
                 WHERE ergebnis_id IN (' . $platzhalter . ')
                 ORDER BY id ASC'
            );
            $twStmt->execute($ergebnisIds);
            foreach ($twStmt->fetchAll(PDO::FETCH_ASSOC) as $tw) {
                $teilwertungenByErgebnis[(int) $tw['ergebnis_id']][] = $tw;
            }
        }

        $csrfToken = Router::generateCsrfToken();
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // Nur Geometrie, keine Farben - die kommen aus den Theme-Variablen
        // (Addons#66). Der Block steht im Admin-Layout des Kerns.
        $html = '<style>'
            . '.zs-abschnitt-block{border:1px solid var(--border-color);border-radius:var(--border-radius, 4px);padding:0.75rem;margin-bottom:0.75rem;}'
            . '.zs-abschnitt-kopf{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:0.5rem;}'
            . '.zs-abschnitt-tw-form{display:grid;grid-template-columns:repeat(6,1fr) auto;gap:0.5rem;align-items:end;margin-top:0.5rem;}'
            . '</style>';
        $html .= '<h3 style="margin-top:0;">🏆 Zuchtschau-/Körungsergebnisse</h3>';

        foreach ($results as $row) {
            $ergebnisId = (int) $row['id'];
            $teilwertungen = $teilwertungenByErgebnis[$ergebnisId] ?? [];

            // Der Anker ist das Gegenstück zum Rückweg der Teilwertungs-Routen:
            // Nach dem Anlegen oder Löschen einer Teilwertung landet der
            // Benutzer wieder an genau diesem Block, nicht am Seitenanfang.
            $html .= '<div class="zs-abschnitt-block" id="zs-ergebnis-' . $ergebnisId . '">';

            $html .= '<div class="zs-abschnitt-kopf">';
            $html .= '<strong>' . $esc($row['event_name'])
                . ($row['event_date'] !== null ? ' (' . $esc($row['event_date']) . ')' : '') . '</strong>';
            $html .= '<form method="POST" action="/plugin/zuchtschau-ergebnisse/ergebnisse/delete" style="margin:0;"'
                . ' onsubmit="return confirm(\'Ergebnis wirklich löschen? Zugehörige Teilwertungen werden mitgelöscht.\');">'
                . '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">'
                . '<input type="hidden" name="id" value="' . $ergebnisId . '">'
                . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Ergebnis löschen</button>'
                . '</form>';
            $html .= '</div>';

            $html .= '<p style="margin:0 0 0.5rem 0;color:var(--text-muted);font-size:0.9em;">'
                . 'Kategorie: ' . $esc($row['category'] ?? '–')
                . ' · Note: ' . $esc($row['score'] ?? '–')
                . ' · Platzierung: ' . $esc($row['placement'] ?? '–')
                . ' · Richter: ' . $esc($row['judge'] ?? '–') . '</p>';
            if (!empty($row['comment'])) {
                $html .= '<p style="margin:0 0 0.5rem 0;">' . nl2br($esc($row['comment'])) . '</p>';
            }

            // Zweite Ebene: aufklappbar, aber offen, sobald es etwas zu sehen
            // gibt - sonst verschwände nach dem Anlegen einer Teilwertung
            // ausgerechnet das, was gerade entstanden ist.
            $html .= '<details' . ($teilwertungen ? ' open' : '') . '>';
            $html .= '<summary>Teilwertungen (' . count($teilwertungen) . ')</summary>';

            if ($teilwertungen) {
                $html .= '<table style="width:100%;border-collapse:collapse;font-size:0.9em;margin-top:0.5rem;">';
                $html .= '<thead><tr style="text-align:left;border-bottom:1px solid var(--border-color);">'
                    . '<th style="padding:0.2rem 0.4rem;">Bezeichnung</th><th style="padding:0.2rem 0.4rem;">Wertung</th>'
                    . '<th style="padding:0.2rem 0.4rem;">Note</th><th style="padding:0.2rem 0.4rem;">Platzierung</th>'
                    . '<th style="padding:0.2rem 0.4rem;">Distanz</th><th style="padding:0.2rem 0.4rem;">Zeit</th>'
                    . '<th></th></tr></thead><tbody>';
                foreach ($teilwertungen as $tw) {
                    $html .= '<tr>';
                    foreach (['bezeichnung', 'wertung', 'note', 'platzierung', 'distanz', 'zeit'] as $feld) {
                        $html .= '<td style="padding:0.2rem 0.4rem;">' . $esc($tw[$feld] ?? '–') . '</td>';
                    }
                    $html .= '<td style="padding:0.2rem 0.4rem;">'
                        . '<form method="POST" action="/plugin/zuchtschau-ergebnisse/ergebnisse/teilwertung/delete" style="margin:0;"'
                        . ' onsubmit="return confirm(\'Teilwertung wirklich löschen?\');">'
                        . '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">'
                        . '<input type="hidden" name="id" value="' . (int) $tw['id'] . '">'
                        . '<button type="submit" class="btn btn-secondary" style="color:var(--danger-fg);">Löschen</button>'
                        . '</form></td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }

            // Eigenes Formular je Ergebnis: Die ergebnis_id ist der Bezug der
            // zweiten Ebene und reist als verstecktes Feld mit. Aria-Labels
            // statt sichtbarer <label>, weil die Platzhalter die Spalten
            // bereits benennen und je Ergebnis sonst sechs id-Werte doppelt
            // im Dokument stünden.
            $html .= '<form method="POST" action="/plugin/zuchtschau-ergebnisse/ergebnisse/teilwertung/store" class="zs-abschnitt-tw-form">';
            $html .= '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">';
            $html .= '<input type="hidden" name="ergebnis_id" value="' . $ergebnisId . '">';
            $html .= '<input type="text" name="bezeichnung" class="form-control" placeholder="Bezeichnung" aria-label="Bezeichnung" maxlength="150" required>';
            $html .= '<input type="text" name="wertung" class="form-control" placeholder="Wertung" aria-label="Wertung" maxlength="100">';
            $html .= '<input type="text" name="note" class="form-control" placeholder="Note" aria-label="Note" maxlength="50">';
            $html .= '<input type="text" name="platzierung" class="form-control" placeholder="Platzierung" aria-label="Platzierung" maxlength="50">';
            $html .= '<input type="text" name="distanz" class="form-control" placeholder="Distanz" aria-label="Distanz" maxlength="50">';
            $html .= '<input type="text" name="zeit" class="form-control" placeholder="Zeit" aria-label="Zeit" maxlength="50">';
            $html .= '<button type="submit" class="btn">Teilwertung anlegen</button>';
            $html .= '</form>';

            $html .= '</details>';
            $html .= '</div>';
        }

        if (!$results) {
            $html .= '<p style="color:var(--text-muted);">Für dieses Pferd ist noch kein Ergebnis erfasst.'
                . ' Teilwertungen lassen sich erfassen, sobald das zugehörige Ergebnis gespeichert ist.</p>';
        }

        // Eigenes Formular mit eigener POST-Route: Der Abschnitt steht
        // ausserhalb des Kern-Formulars (Framework#255), der Speichern-Knopf
        // oben speichert diese Felder also NICHT mit.
        //
        // Die Feld-`id`s tragen das Präfix `zs_`: Das Kern-Formular auf
        // derselben Seite führt eigene Felder, und zwei gleiche id-Werte in
        // einem Dokument hängen das <label> an das falsche Feld. Die
        // `name`-Attribute bleiben unverändert - sie gelten je Formular und
        // sind der Vertrag mit store().
        $html .= '<h4 style="margin-bottom:0.5rem;">Weiteres Ergebnis erfassen</h4>';
        $html .= '<form method="POST" action="/plugin/zuchtschau-ergebnisse/ergebnisse/store">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $esc($csrfToken) . '">';
        $html .= '<input type="hidden" name="horse_id" value="' . $horseId . '">';
        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">';
        $html .= '<div class="form-group"><label for="zs_event_name">Veranstaltung</label>'
            . '<input type="text" name="event_name" id="zs_event_name" class="form-control" maxlength="150" required></div>';
        $html .= '<div class="form-group"><label for="zs_event_date">Datum</label>'
            . '<input type="date" name="event_date" id="zs_event_date" class="form-control"></div>';
        $html .= '</div>';
        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">';
        $html .= '<div class="form-group"><label for="zs_category">Kategorie</label>'
            . '<input type="text" name="category" id="zs_category" class="form-control" maxlength="100" placeholder="z. B. Körung, Zuchtschau"></div>';
        $html .= '<div class="form-group"><label for="zs_score">Note</label>'
            . '<input type="text" name="score" id="zs_score" class="form-control" maxlength="50"></div>';
        $html .= '</div>';
        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">';
        $html .= '<div class="form-group"><label for="zs_placement">Platzierung</label>'
            . '<input type="text" name="placement" id="zs_placement" class="form-control" maxlength="50"></div>';
        $html .= '<div class="form-group"><label for="zs_judge">Richter</label>'
            . '<input type="text" name="judge" id="zs_judge" class="form-control" maxlength="100"></div>';
        $html .= '</div>';
        $html .= '<div class="form-group"><label for="zs_comment">Kommentar</label>'
            . '<textarea name="comment" id="zs_comment" class="form-control" rows="3"></textarea></div>';
        // Beschriftung bewusst nicht "Speichern": Auf der Seite gibt es zwei
        // Knöpfe, und wer hier drückt, verliert ungespeicherte Stammdaten oben.
        $html .= '<p><button type="submit" class="btn">Ergebnis hinzufügen</button>'
            . ' <span style="color:var(--text-muted);font-size:0.85rem;">Änderungen an den Stammdaten oben bitte zuerst speichern.'
            . ' Teilwertungen werden anschließend am gespeicherten Ergebnis erfaßt.</span></p>';
        $html .= '</form>';

        $sections[] = $html;
        return $sections;
    }

    /**
     * Framework-Hook (#75): Der PluginManager ruft install() bei der
     * Aktivierung und nach einem Addon-Update auf - das DDL-Statement läuft
     * damit nicht mehr in jedem Request. install() muss idempotent sein
     * (CREATE TABLE IF NOT EXISTS), denn der Kern garantiert "mindestens
     * einmal nach Installation/Update", nicht "genau einmal" - so zieht ein
     * Addon-Update auf einer Bestandsinstallation die neue Kindtabelle nach,
     * ohne die vorhandene Ergebnistabelle anzufassen (#82).
     */
    public function install(): void {
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_zuchtschau_ergebnisse` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `event_name` VARCHAR(150) NOT NULL,
                `event_date` DATE NULL DEFAULT NULL,
                `category` VARCHAR(100) NULL DEFAULT NULL,
                `score` VARCHAR(50) NULL DEFAULT NULL,
                `judge` VARCHAR(100) NULL DEFAULT NULL,
                `placement` VARCHAR(50) NULL DEFAULT NULL,
                `comment` TEXT NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Kindtabelle für Teilwertungen (#82): mehrspaltige Einzelwertungen
        // (Dressur/Springen/Gelände, Zeiten, Distanzen ...) je Ergebnis. Alle
        // Fachspalten bewusst NULL-tolerant - die Altdaten aus v1/v2 sind
        // lückig, und eine Teilwertung mit nur Bezeichnung+Note ist gültig.
        // ON DELETE CASCADE: Teilwertungen existieren nie ohne ihr Ergebnis.
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS `plugin_zuchtschau_teilwertungen` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `ergebnis_id` INT NOT NULL,
                `bezeichnung` VARCHAR(150) NULL DEFAULT NULL,
                `wertung` VARCHAR(100) NULL DEFAULT NULL,
                `note` VARCHAR(50) NULL DEFAULT NULL,
                `platzierung` VARCHAR(50) NULL DEFAULT NULL,
                `distanz` VARCHAR(50) NULL DEFAULT NULL,
                `zeit` VARCHAR(50) NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`ergebnis_id`) REFERENCES `plugin_zuchtschau_ergebnisse`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    /**
     * Filter-Beispiel: hängt eine chronologische Liste der erfassten
     * Zuchtschau-/Körungsergebnisse des Pferdes an die öffentliche
     * Detailseite an. Zeigt nichts an, wenn noch keine Ergebnisse erfasst
     * sind (kein leerer Abschnitt).
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $horseId = (int) $horse['id'];

        $stmt = Database::getInstance()->prepare(
            'SELECT id, event_name, event_date, category, score, judge, placement, `comment`
             FROM `plugin_zuchtschau_ergebnisse`
             WHERE horse_id = :id
             ORDER BY event_date DESC, id DESC'
        );
        $stmt->execute(['id' => $horseId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($results)) {
            return $sections;
        }

        // Teilwertungen (#82) je Ergebnis nachladen. Bewusst EIN vorbereitetes
        // Statement, das pro Ergebnis ausgeführt wird - kein per Interpolation
        // zusammengesetztes IN(...): Die Detailseite zeigt wenige Ergebnisse,
        // und das SQL bleibt ein reines Literal (Prepared Statement).
        $twStmt = Database::getInstance()->prepare(
            'SELECT bezeichnung, wertung, note, platzierung, distanz, zeit
             FROM `plugin_zuchtschau_teilwertungen`
             WHERE ergebnis_id = :ergebnis_id
             ORDER BY id ASC'
        );

        $html = '<div style="margin-top:0.5rem;">';
        $html .= '<h3 style="margin-bottom:0.5rem;">🏆 Zuchtschau-/Körungsergebnisse</h3>';
        $html .= '<table style="width:100%;border-collapse:collapse;">';
        $html .= '<thead><tr style="text-align:left;border-bottom:2px solid var(--border-color);">'
            . '<th style="padding:0.4rem;">Veranstaltung</th><th style="padding:0.4rem;">Datum</th>'
            . '<th style="padding:0.4rem;">Kategorie</th><th style="padding:0.4rem;">Note</th>'
            . '<th style="padding:0.4rem;">Platzierung</th><th style="padding:0.4rem;">Richter</th></tr></thead><tbody>';

        foreach ($results as $row) {
            $html .= '<tr style="border-bottom:1px solid var(--border-color);">';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) $row['event_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['event_date'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['category'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['score'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['placement'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td style="padding:0.4rem;">' . htmlspecialchars((string) ($row['judge'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
            if (!empty($row['comment'])) {
                $html .= '<tr style="border-bottom:1px solid var(--border-color);"><td colspan="6" style="padding:0 0.4rem 0.5rem 0.4rem;color:var(--text-muted);font-size:0.9em;">'
                    . htmlspecialchars((string) $row['comment'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }

            // Teilwertungen unterhalb des jeweiligen Ergebnisses (#82) - nur
            // rendern, wenn welche erfasst sind (kein leerer Unterabschnitt).
            $twStmt->execute(['ergebnis_id' => (int) $row['id']]);
            $teilwertungen = $twStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($teilwertungen)) {
                $html .= '<tr style="border-bottom:1px solid var(--border-color);"><td colspan="6" style="padding:0 0.4rem 0.5rem 1.2rem;">';
                $html .= '<table style="width:100%;border-collapse:collapse;font-size:0.9em;">';
                $html .= '<thead><tr style="text-align:left;color:var(--text-muted);">'
                    . '<th style="padding:0.2rem 0.4rem;">Teilwertung</th><th style="padding:0.2rem 0.4rem;">Wertung</th>'
                    . '<th style="padding:0.2rem 0.4rem;">Note</th><th style="padding:0.2rem 0.4rem;">Platzierung</th>'
                    . '<th style="padding:0.2rem 0.4rem;">Distanz</th><th style="padding:0.2rem 0.4rem;">Zeit</th></tr></thead><tbody>';
                foreach ($teilwertungen as $tw) {
                    $html .= '<tr>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['bezeichnung'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['wertung'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['note'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['platzierung'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['distanz'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="padding:0.2rem 0.4rem;">' . htmlspecialchars((string) ($tw['zeit'] ?? '–'), ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table></td></tr>';
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
                'module' => 'zuchtschau-ergebnisse',
                'action' => 'manage',
                'label' => 'Verwalten',
                'module_label' => 'Zuchtschau-Ergebnisse',
            ],
        ];
    }

    /**
     * Nur noch schreibende Routen (#124): Die GET-Route führte auf die
     * entfallene Ergebnisseite. Was bleibt, sind die vier Ziele der Formulare
     * im Pferdeabschnitt - zwei je Ebene.
     *
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            [
                'method' => 'POST',
                'path' => '/ergebnisse/store',
                'callback' => [ErgebnisseController::class, 'store'],
            ],
            [
                'method' => 'POST',
                'path' => '/ergebnisse/delete',
                'callback' => [ErgebnisseController::class, 'delete'],
            ],
            [
                'method' => 'POST',
                'path' => '/ergebnisse/teilwertung/store',
                'callback' => [ErgebnisseController::class, 'storeTeilwertung'],
            ],
            [
                'method' => 'POST',
                'path' => '/ergebnisse/teilwertung/delete',
                'callback' => [ErgebnisseController::class, 'deleteTeilwertung'],
            ],
        ];
    }
}

/**
 * Admin-Verwaltung der Zuchtschau-/Körungsergebnisse. Zugriffsschutz über
 * die selbst registrierte Berechtigung "zuchtschau-ergebnisse.manage",
 * analog zum besucherstatistik-Muster (StatistikController).
 *
 * Seit #124 hat der Controller keine eigene Seite mehr: Alle vier Routen sind
 * die Ziele der Formulare im Abschnitt des Pferdeformulars
 * (Plugin::addEditSection). Mit der Seite entfallen sind ihre beiden
 * Obergrenzen (HORSE_OPTION_LIMIT, RESULT_LIMIT) - sie deckelten den
 * KOMPLETTEN Pferdebestand und ALLE Ergebnisse, und beides lädt der Abschnitt
 * gar nicht mehr: Er zeigt die Ergebnisse genau eines Pferdes.
 */
class ErgebnisseController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('zuchtschau-ergebnisse', 'manage');
    }

    public function store(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();

        // Die horse_id kommt seit #124 ausschließlich aus dem Aufrufkontext der
        // Pferdeseite (verstecktes Feld) - die <select>-Auswahl über den
        // gesamten Bestand ist mit der Ergebnisseite entfallen.
        //
        // Existenz trotzdem prüfen: Ein erfundener Wert liefe sonst in den
        // FOREIGN-KEY-Fehler und damit in eine 500er-Seite, obwohl das schlicht
        // eine ungültige Eingabe ist.
        $horseId = !empty($_POST['horse_id']) ? (int) $_POST['horse_id'] : null;
        if ($horseId !== null && !self::pferdExistiert($db, $horseId)) {
            $horseId = null;
        }
        $eventName = trim($_POST['event_name'] ?? '');

        if ($horseId && $eventName !== '') {
            $stmt = $db->prepare(
                'INSERT INTO `plugin_zuchtschau_ergebnisse`
                    (horse_id, event_name, event_date, category, score, judge, placement, `comment`)
                 VALUES (:horse_id, :event_name, :event_date, :category, :score, :judge, :placement, :comment)'
            );
            $eventDate = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
            $stmt->execute([
                'horse_id' => $horseId,
                'event_name' => $eventName,
                'event_date' => $eventDate,
                'category' => trim($_POST['category'] ?? '') ?: null,
                'score' => trim($_POST['score'] ?? '') ?: null,
                'judge' => trim($_POST['judge'] ?? '') ?: null,
                'placement' => trim($_POST['placement'] ?? '') ?: null,
                'comment' => trim($_POST['comment'] ?? '') ?: null,
            ]);

            // Protokoll (#134): Kategorie = Addon-Slug. Genannt werden
            // Datensatz und Bezug (welches Ergebnis, welches Pferd) sowie die
            // Veranstaltung - das ist es, woran ein Eintrag im Nachhinein
            // wiedererkannt wird.
            //
            // Draußen bleiben Richter und Kommentar: Der Richtername ist ein
            // Personenbezug, der Kommentar freier Text über eine Person oder
            // ein Pferd. Für den Nachweis der Handlung braucht es beides
            // nicht, und das Protokoll wird dauerhaft aufbewahrt.
            $ergebnisId = (int) $db->lastInsertId();
            AuditLogger::log(
                'Zuchtschau-Ergebnis angelegt',
                'zuchtschau-ergebnisse',
                "Ergebnis #{$ergebnisId}, Pferd #{$horseId} (" . self::pferdeName($db, $horseId) . '), '
                    . "Veranstaltung: {$eventName}, Datum: " . ($eventDate ?? 'ohne Angabe')
            );
        }

        $this->redirectBack($horseId);
    }

    public function delete(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        // Die horse_id entscheidet über den Rückweg (#124) und stammt deshalb
        // aus der gelesenen Zeile, nicht aus dem POST: Ein manipulierter Wert
        // schickte den Benutzer sonst in den Datensatz eines fremden Pferdes.
        $horseId = null;
        if ($id) {
            $db = Database::getInstance();

            // Vor dem Löschen gelesen (#134): Danach ist weder Veranstaltung
            // noch Pferd zu ermitteln, und die Zahl der mitgelöschten
            // Teilwertungen schon gar nicht - der CASCADE hinterlässt keine
            // Spur. LEFT JOIN, damit ein fehlendes Pferd die Zeile nicht
            // verschwinden lässt.
            $stmt = $db->prepare(
                'SELECT e.event_name, e.event_date, e.horse_id, h.name AS horse_name,
                        (SELECT COUNT(*) FROM `plugin_zuchtschau_teilwertungen` t WHERE t.ergebnis_id = e.id) AS tw_anzahl
                 FROM `plugin_zuchtschau_ergebnisse` e
                 LEFT JOIN horses h ON h.id = e.horse_id
                 WHERE e.id = :id'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $horseId = (int) $row['horse_id'];
            }

            // Zugehörige Teilwertungen räumt die Datenbank selbst ab
            // (FK ON DELETE CASCADE, siehe install()).
            $deleteStmt = $db->prepare('DELETE FROM `plugin_zuchtschau_ergebnisse` WHERE id = :id');
            $deleteStmt->execute(['id' => $id]);

            // Nur protokollieren, was tatsächlich gelöscht wurde: Ein POST auf
            // eine längst entfernte ID ist kein Löschvorgang und hätte im
            // Protokoll nichts zu suchen.
            if ($row) {
                AuditLogger::log(
                    'Zuchtschau-Ergebnis gelöscht',
                    'zuchtschau-ergebnisse',
                    "Ergebnis #{$id}, Pferd #" . (int) $row['horse_id']
                        . ' (' . (string) ($row['horse_name'] ?? 'unbekannt') . '), '
                        . 'Veranstaltung: ' . (string) $row['event_name']
                        . ', Datum: ' . (string) ($row['event_date'] ?? 'ohne Angabe')
                        . ', mitgelöschte Teilwertungen: ' . (int) $row['tw_anzahl']
                );
            }
        }

        // Ohne Anker: Den Block, auf den er zeigte, gibt es nicht mehr.
        $this->redirectBack($horseId);
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
     * Legt eine Teilwertung (#82) zu einem bestehenden Ergebnis an. Alle
     * Fachfelder außer der Bezeichnung sind optional (NULL-tolerant, wie die
     * lückigen Altdaten); die Bezeichnung ist im Admin-Formular Pflicht,
     * damit dort keine unbenennbaren Leerzeilen entstehen.
     */
    public function storeTeilwertung(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $db = Database::getInstance();
        $ergebnisId = !empty($_POST['ergebnis_id']) ? (int) $_POST['ergebnis_id'] : null;
        $bezeichnung = trim($_POST['bezeichnung'] ?? '');
        // Der Rückweg führt seit #124 auf das PFERD, nicht auf eine eigene
        // Seite - und die horse_id hängt am Elternergebnis, nicht am POST.
        // Dieselbe Abfrage, die die Existenz prüft, liefert sie mit.
        $horseId = null;

        if ($ergebnisId && $bezeichnung !== '') {
            // Existenz des Elternergebnisses vorab prüfen, statt den
            // FK-Fehler als 500er beim Benutzer landen zu lassen (z. B.
            // wenn das Ergebnis in einem zweiten Tab gelöscht wurde).
            $check = $db->prepare(
                'SELECT horse_id FROM `plugin_zuchtschau_ergebnisse` WHERE id = :id'
            );
            $check->execute(['id' => $ergebnisId]);
            $gefunden = $check->fetchColumn();

            if ($gefunden !== false) {
                $horseId = (int) $gefunden;
                $stmt = $db->prepare(
                    'INSERT INTO `plugin_zuchtschau_teilwertungen`
                        (ergebnis_id, bezeichnung, wertung, note, platzierung, distanz, zeit)
                     VALUES (:ergebnis_id, :bezeichnung, :wertung, :note, :platzierung, :distanz, :zeit)'
                );
                $stmt->execute([
                    'ergebnis_id' => $ergebnisId,
                    'bezeichnung' => $bezeichnung,
                    'wertung' => trim($_POST['wertung'] ?? '') ?: null,
                    'note' => trim($_POST['note'] ?? '') ?: null,
                    'platzierung' => trim($_POST['platzierung'] ?? '') ?: null,
                    'distanz' => trim($_POST['distanz'] ?? '') ?: null,
                    'zeit' => trim($_POST['zeit'] ?? '') ?: null,
                ]);

                // Protokoll (#134): Auch die Kindtabelle ist ein schreibender
                // Zugriff. Bezug ist hier das Elternergebnis - über das hängt
                // die Teilwertung am Pferd.
                $teilwertungId = (int) $db->lastInsertId();
                AuditLogger::log(
                    'Zuchtschau-Teilwertung angelegt',
                    'zuchtschau-ergebnisse',
                    "Teilwertung #{$teilwertungId} zu Ergebnis #{$ergebnisId}, Bezeichnung: {$bezeichnung}"
                );
            }
        }

        // Mit Anker: Der Block des Ergebnisses ist die Stelle, an der gerade
        // gearbeitet wurde - ohne ihn landete der Benutzer am Seitenanfang
        // eines langen Formulars und müßte sein Ergebnis wiederfinden.
        $this->redirectBack($horseId, $ergebnisId);
    }

    /**
     * Löscht eine einzelne Teilwertung (#82) - das Ergebnis selbst bleibt
     * bestehen (Gegenrichtung läuft über den FK-CASCADE, siehe delete()).
     */
    public function deleteTeilwertung(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $horseId = null;
        $ergebnisId = null;
        if ($id) {
            $db = Database::getInstance();

            // Vor dem Löschen gelesen (#134) - danach ist nicht mehr
            // festzustellen, zu welchem Ergebnis die Teilwertung gehörte. Der
            // JOIN holt zugleich die horse_id des Elternergebnisses: Sie ist
            // seit #124 der Rückweg, und dem POST wäre sie nicht zu glauben.
            $leseStmt = $db->prepare(
                'SELECT t.ergebnis_id, t.bezeichnung, e.horse_id
                 FROM `plugin_zuchtschau_teilwertungen` t
                 LEFT JOIN `plugin_zuchtschau_ergebnisse` e ON e.id = t.ergebnis_id
                 WHERE t.id = :id'
            );
            $leseStmt->execute(['id' => $id]);
            $row = $leseStmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $horseId = $row['horse_id'] !== null ? (int) $row['horse_id'] : null;
                $ergebnisId = (int) $row['ergebnis_id'];
            }

            $stmt = $db->prepare('DELETE FROM `plugin_zuchtschau_teilwertungen` WHERE id = :id');
            $stmt->execute(['id' => $id]);

            if ($row) {
                AuditLogger::log(
                    'Zuchtschau-Teilwertung gelöscht',
                    'zuchtschau-ergebnisse',
                    "Teilwertung #{$id} zu Ergebnis #" . (int) $row['ergebnis_id']
                        . ', Bezeichnung: ' . (string) ($row['bezeichnung'] ?? 'ohne Angabe')
                );
            }
        }

        // Mit Anker: Das Ergebnis steht noch, nur eine seiner Teilwertungen
        // ist weg - der Benutzer soll den Block wiedersehen, nicht suchen.
        $this->redirectBack($horseId, $ergebnisId);
    }

    /** Gibt es dieses Pferd (und ist es nicht im Papierkorb)? */
    private static function pferdExistiert(PDO $db, int $horseId): bool {
        $stmt = $db->prepare('SELECT 1 FROM horses WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $horseId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Rückweg nach jedem Schreibzugriff - beider Ebenen. Bewusst KEINE
     * übergebene URL, sondern eine feste Adresse plus geprüfte Integer: eine
     * mitgeschickte Zieladresse wäre ein offener Redirect.
     *
     * Seit #124 gibt es dafür keinen Schalter mehr: Alle Formulare stehen im
     * Bearbeitungsformular des Pferdes, dorthin führt der Weg also immer
     * zurück. Nur wenn die horse_id nicht zu ermitteln war (POST von Hand,
     * Zeile inzwischen gelöscht), bleibt die Pferdeliste - die frühere
     * Ergebnisseite gibt es nicht mehr, ein Verweis auf sie endete in 404.
     *
     * Die `ergebnis_id` wird als ANKER mitgeführt, nicht als Parameter: Sie
     * betrifft allein die Sprungmarke im Dokument, der Server braucht sie beim
     * erneuten Aufruf nicht. Das ist die zweite Ebene aus #124 - ohne sie
     * landete man nach jeder Teilwertung am Anfang eines langen Formulars.
     */
    private function redirectBack(?int $horseId, ?int $ergebnisId = null): never {
        if ($horseId === null || $horseId <= 0) {
            header('Location: /admin/horses');
            exit;
        }

        $anker = $ergebnisId !== null && $ergebnisId > 0 ? '#zs-ergebnis-' . $ergebnisId : '';
        header('Location: /admin/horses/edit?id=' . $horseId . $anker);
        exit;
    }
}
