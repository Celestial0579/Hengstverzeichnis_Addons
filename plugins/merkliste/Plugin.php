<?php
// merkliste/Plugin.php
//
// Addon für Hengstverzeichnis_Framework: löst
// Celestial0579/Hengstverzeichnis_Addons#19. Besucher ohne Account können
// sich beim Durchstöbern des Katalogs Favoriten merken - rein clientseitig
// (Pferde-IDs im localStorage des Browsers, kein Account, keine
// Server-Session, keine Cookies). Die eigene Seite /plugin/merkliste löst
// die gespeicherten IDs über eine schreibgeschützte JSON-API zu
// Name/Bild/Link auf.
//
// Ursprünglich war der "Merken"-Button nur auf der Detailseite geplant
// (Phase 1 der Hooks) - der Kern stellt inzwischen auch den
// catalog.card_sections-Filter bereit (#97), daher erscheint der Button
// zusätzlich direkt auf den Katalogkarten.
//
// Installation (lokal im Framework-Repo):
//   cp -r merkliste plugins/merkliste
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.
// Keine Berechtigung nötig - die API gibt ausschließlich Daten aus, die
// über den öffentlichen Katalog ohnehin sichtbar sind.
//
// Siehe docs/plugin-development.md im Framework-Repo für die vollständige
// Hook-/Routen-/Berechtigungs-Referenz.

namespace Plugin\Merkliste;

use App\Controllers\BaseController;
use App\Database;
use App\Plugin\HookManager;
use PDO;

class Plugin {

    public function register(HookManager $hooks): void {
        $hooks->addFilter('horse.detail_sections', [$this, 'addDetailSection']);
        $hooks->addFilter('catalog.card_sections', [$this, 'addCardSection']);
    }

    /**
     * Gemeinsames, mehrfach einbindbares Button-Snippet. Das Skript ist per
     * window-Guard idempotent - auf einer Katalogseite mit vielen Karten wird
     * es zwar mehrfach ausgegeben, definiert die Helfer aber nur einmal.
     * Ein MutationObserver hält die Button-Beschriftungen auch nach
     * AJAX-Nachladen des Katalogs (public_catalog_cards.php) korrekt.
     */
    private function buttonHtml(int $horseId, bool $compact): string {
        $style = $compact
            ? 'padding:0.25rem 0.6rem;font-size:0.8em;'
            : 'padding:0.5rem 1rem;';

        $html = '<button type="button" data-hv-merkliste="' . $horseId . '" '
            . 'onclick="hvMerklisteToggle(this)" '
            . 'style="' . $style . 'margin-top:0.5rem;border:1px solid var(--warning-fg);background:var(--info-soft-bg);border-radius:4px;cursor:pointer;">'
            . '☆ Merken</button>';

        if (!$compact) {
            // Als App-Schaltfläche statt nackter Browser-Link (#49): die Klassen
            // kommen aus dem Framework-CSS, das im Detailseiten-Kontext geladen
            // ist - damit stimmen auch die Theme-Farben im Darkmode (#48).
            $html .= ' <a href="/plugin/merkliste" class="btn btn-secondary" style="margin-left:0.5rem;padding:0.5rem 1rem;">Zur Merkliste</a>';
        }

        $html .= '<script>
            if (!window.hvMerkliste) {
                window.hvMerkliste = {
                    read: function () {
                        try {
                            var raw = JSON.parse(localStorage.getItem("hv_merkliste") || "[]");
                            return Array.isArray(raw) ? raw.map(Number).filter(function (n) { return n > 0; }) : [];
                        } catch (e) { return []; }
                    },
                    write: function (ids) {
                        localStorage.setItem("hv_merkliste", JSON.stringify(ids));
                    },
                    syncButtons: function () {
                        var ids = window.hvMerkliste.read();
                        document.querySelectorAll("[data-hv-merkliste]").forEach(function (btn) {
                            var saved = ids.indexOf(parseInt(btn.getAttribute("data-hv-merkliste"), 10)) !== -1;
                            var next = saved ? "★ Gemerkt" : "☆ Merken";
                            // Nur schreiben, wenn sich der Text tatsaechlich aendert:
                            // Jede textContent-Zuweisung ist selbst eine DOM-Mutation,
                            // die den MutationObserver unten erneut ausloest. Ohne diesen
                            // Guard synchronisiert sich der Observer endlos selbst (100% CPU,
                            // die Seite friert ein), sobald ueberhaupt Merken-Buttons im DOM
                            // sind - also auf jedem befuellten Katalog und jeder Detailseite.
                            if (btn.textContent !== next) { btn.textContent = next; }
                        });
                        window.hvMerkliste.ensureCatalogEntry(ids);
                    },
                    // Genau EIN "Zur Merkliste"-Einstieg auf der Katalogseite (#49):
                    // neben dem Trefferzahl-Badge, den es nur dort gibt - die
                    // Detailseite hat ihren eigenen Link. Einfuegen ist idempotent
                    // (ID-Guard), der Zaehler-Text folgt demselben textContent-
                    // Guard wie syncButtons (sonst Observer-Endlosschleife, #47).
                    ensureCatalogEntry: function (ids) {
                        var badge = document.getElementById("hit-count-badge");
                        if (!badge) { return; }
                        var entry = document.getElementById("hv-merkliste-entry");
                        if (!entry) {
                            entry = document.createElement("a");
                            entry.id = "hv-merkliste-entry";
                            entry.href = "/plugin/merkliste";
                            entry.className = "btn btn-secondary";
                            entry.style.cssText = "padding:0.3rem 0.8rem;font-size:0.9rem;";
                            badge.parentNode.insertBefore(entry, badge);
                        }
                        var next = "★ Merkliste (" + ids.length + ")";
                        if (entry.textContent !== next) { entry.textContent = next; }
                    }
                };
                window.hvMerklisteToggle = function (btn) {
                    var id = parseInt(btn.getAttribute("data-hv-merkliste"), 10);
                    var ids = window.hvMerkliste.read();
                    var pos = ids.indexOf(id);
                    if (pos === -1) { ids.push(id); } else { ids.splice(pos, 1); }
                    window.hvMerkliste.write(ids);
                    window.hvMerkliste.syncButtons();
                };
                document.addEventListener("DOMContentLoaded", window.hvMerkliste.syncButtons);
                new MutationObserver(function () { window.hvMerkliste.syncButtons(); })
                    .observe(document.documentElement, { childList: true, subtree: true });
            }
        </script>';

        return $html;
    }

    /**
     * Filter-Beispiel: "Merken"-Button auf der öffentlichen Detailseite.
     */
    public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
        $sections[] = '<div style="margin-top:0.5rem;">' . $this->buttonHtml((int) $horse['id'], false) . '</div>';
        return $sections;
    }

    /**
     * Filter-Beispiel: kompakter "Merken"-Button auf jeder Katalogkarte
     * (catalog.card_sections, #97).
     */
    public function addCardSection(array $sections, array $horse): array {
        $sections[] = $this->buttonHtml((int) $horse['id'], true);
        return $sections;
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET', 'path' => '/', 'callback' => [MerklisteController::class, 'show']],
            ['method' => 'GET', 'path' => '/api', 'callback' => [MerklisteController::class, 'api']],
        ];
    }
}

/**
 * Öffentliche Merklisten-Seite samt schreibgeschützter JSON-API. Beide
 * Routen sind bewusst anonym erreichbar - die API löst nur IDs zu Daten auf,
 * die der öffentliche Katalog ohnehin zeigt, und unterliegt demselben Gating
 * (horses.view der Gast-Gruppe, nur veröffentlichte, nicht gelöschte
 * Pferde).
 */
class MerklisteController extends BaseController {

    private const MAX_IDS = 100;

    public function show(): void {
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Meine Merkliste</title>';
        echo '<link rel="stylesheet" href="/css/style.css">';
        echo <<<'HTML'
        <script>
        // Theme-Bootstrap wie im Framework-Layout (dort ausführlich begründet):
        // synchron im <head>, damit data-theme vor dem ersten Rendern steht.
        (function () {
            var stored = localStorage.getItem('theme');
            if (stored === 'dark' || stored === 'light') {
                document.documentElement.setAttribute('data-theme', stored);
            }
        })();
        </script>
        HTML;
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<style>
            body{font-family:sans-serif;padding:2rem;max-width:900px;margin:0 auto;background:var(--bg-color);}
            .card{display:flex;gap:1rem;padding:1rem;border-bottom:1px solid var(--border-color);align-items:center;}
            .card img{width:80px;height:80px;object-fit:cover;border-radius:6px;}
            .card h2{margin:0 0 0.3rem 0;font-size:1.05rem;}
            .remove{color:var(--danger-fg);background:none;border:none;cursor:pointer;padding:0.3rem 0.6rem;}
            #leer{color:var(--text-muted);}
        </style></head><body>';
        echo '<h1>⭐ Meine Merkliste</h1>';
        echo '<p style="color:var(--text-muted);font-size:0.9em;">Die Merkliste wird nur in diesem Browser gespeichert (localStorage) - ohne Account, ohne Server-Speicherung.</p>';
        echo '<div id="liste"></div>';
        echo '<p id="leer" style="display:none;">Noch keine Pferde gemerkt. Im <a href="/katalog">Katalog</a> stöbern und "☆ Merken" klicken.</p>';

        echo '<script>
            (function () {
                function readIds() {
                    try {
                        var raw = JSON.parse(localStorage.getItem("hv_merkliste") || "[]");
                        return Array.isArray(raw) ? raw.map(Number).filter(function (n) { return n > 0; }) : [];
                    } catch (e) { return []; }
                }

                function render(horses) {
                    var liste = document.getElementById("liste");
                    var leer = document.getElementById("leer");
                    liste.textContent = "";
                    if (!horses.length) {
                        leer.style.display = "block";
                        return;
                    }
                    leer.style.display = "none";
                    horses.forEach(function (horse) {
                        var card = document.createElement("div");
                        card.className = "card";

                        if (horse.image_url) {
                            var img = document.createElement("img");
                            img.src = horse.image_url;
                            img.alt = "";
                            card.appendChild(img);
                        }

                        var info = document.createElement("div");
                        var h2 = document.createElement("h2");
                        var link = document.createElement("a");
                        link.href = horse.url;
                        link.textContent = horse.name;
                        h2.appendChild(link);
                        info.appendChild(h2);
                        if (horse.birth_year) {
                            var year = document.createElement("div");
                            year.textContent = "Geburtsjahr: " + horse.birth_year;
                            info.appendChild(year);
                        }
                        card.appendChild(info);

                        var remove = document.createElement("button");
                        remove.className = "remove";
                        remove.type = "button";
                        remove.textContent = "Entfernen";
                        remove.addEventListener("click", function () {
                            var ids = readIds().filter(function (id) { return id !== horse.id; });
                            localStorage.setItem("hv_merkliste", JSON.stringify(ids));
                            load();
                        });
                        card.appendChild(remove);

                        liste.appendChild(card);
                    });
                }

                function load() {
                    var ids = readIds();
                    if (!ids.length) {
                        render([]);
                        return;
                    }
                    fetch("/plugin/merkliste/api?ids=" + ids.join(","))
                        .then(function (res) { return res.json(); })
                        .then(render)
                        .catch(function () { render([]); });
                }

                load();
            })();
        </script>';
        echo '<p style="margin-top:2rem;"><a href="/katalog">Zurück zum Katalog</a></p>';
        echo '</body></html>';
    }

    /**
     * Schreibgeschützte JSON-API: löst die per JS aus dem localStorage
     * gelesenen IDs zu Name/Bild/Link auf. Gleiche Sichtbarkeitsregeln wie
     * der öffentliche Katalog; unbekannte, unveröffentlichte und gelöschte
     * IDs fehlen schlicht in der Antwort.
     */
    public function api(): void {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->hasPermission('horses', 'view')) {
            echo json_encode([]);
            exit;
        }

        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) ($_GET['ids'] ?? ''))
        ), static fn (int $id): bool => $id > 0));
        $ids = array_slice(array_unique($ids), 0, self::MAX_IDS);

        if (empty($ids)) {
            echo json_encode([]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT id, name, birth_year, image_url
             FROM horses
             WHERE id IN ({$placeholders}) AND deleted_at IS NULL AND is_published = 1"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // In der vom Besucher gemerkten Reihenfolge ausgeben.
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'birth_year' => $row['birth_year'] !== null ? (int) $row['birth_year'] : null,
                'image_url' => $row['image_url'] !== null ? (string) $row['image_url'] : null,
                'url' => '/hengst?id=' . (int) $row['id'],
            ];
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $result[] = $byId[$id];
            }
        }

        echo json_encode($result);
        exit;
    }
}
