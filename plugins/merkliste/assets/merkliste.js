// merkliste/assets/merkliste.js
//
// Clientseitige Logik der Merkliste (Addons#73): Wird als statisches Asset
// über GET /plugin/merkliste/assets.js ausgeliefert (Cache-Control: public,
// max-age=86400) statt als Inline-Block je Katalogkarte - vorher standen
// 24 identische 3,8-KB-Skriptblöcke in jeder Katalogseite (~92 KB Ballast,
// im AJAX-Pfad JSON-escaped noch mehr). Plugin::buttonHtml() bindet nur noch
// einmal je Request ein <script src=... defer> ein.
//
// Das Skript ist idempotent (window-Guard): Auch wenn es doppelt geladen
// würde, definieren sich die Helfer nur einmal.
(function () {
    "use strict";

    if (window.hvMerkliste) {
        return;
    }

    window.hvMerkliste = {
        read: function () {
            try {
                var raw = JSON.parse(localStorage.getItem("hv_merkliste") || "[]");
                return Array.isArray(raw) ? raw.map(Number).filter(function (n) { return n > 0; }) : [];
            } catch (e) {
                return [];
            }
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
                // sind - also auf jedem befuellten Katalog (#47).
                if (btn.textContent !== next) {
                    btn.textContent = next;
                }
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
            if (!badge) {
                return;
            }
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
            if (entry.textContent !== next) {
                entry.textContent = next;
            }
        }
    };

    window.hvMerklisteToggle = function (btn) {
        var id = parseInt(btn.getAttribute("data-hv-merkliste"), 10);
        var ids = window.hvMerkliste.read();
        var pos = ids.indexOf(id);
        if (pos === -1) {
            ids.push(id);
        } else {
            ids.splice(pos, 1);
        }
        window.hvMerkliste.write(ids);
        window.hvMerkliste.syncButtons();
    };

    function init() {
        window.hvMerkliste.syncButtons();

        // Buttons nach AJAX-Nachladen des Katalogs synchron halten
        // (public_catalog_cards.php ersetzt grid.innerHTML). Beobachtet wird
        // GEZIELT der Karten-Container #catalog-grid statt wie frueher
        // das gesamte Dokument - sonst laeuft syncButtons (localStorage
        // + JSON.parse + querySelectorAll ueber das ganze Dokument) bei JEDER
        // DOM-Mutation irgendwo auf der Seite (#73). Ohne Container (z. B.
        // Detailseite, dort gibt es kein AJAX-Nachladen) gibt es bewusst
        // keinen Observer.
        var grid = document.getElementById("catalog-grid");
        if (grid) {
            new MutationObserver(function () { window.hvMerkliste.syncButtons(); })
                .observe(grid, { childList: true, subtree: true });
        }
    }

    // Mit `defer` laeuft das Skript nach dem DOM-Parsen, aber vor
    // DOMContentLoaded (readyState "interactive") - init() kann dann sofort
    // laufen. Der Listener-Zweig deckt nur den theoretischen Fall ab, dass
    // das Skript doch waehrend des Parsens ausgefuehrt wird.
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
