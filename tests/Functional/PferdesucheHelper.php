<?php
// tests/Functional/PferdesucheHelper.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * Gemeinsamer Helfer für die Addons, die ihre Pferdesuche mit Addons#125 an
 * den Kern abgegeben haben.
 *
 * WOZU EIN HELFER. Bis v0.7 brachten sieben Addons je eine eigene Pferdesuche
 * mit - dieselbe JSON-Route, dieselbe Datalist, derselbe JS-Block. Sieben
 * Kopien bedeuteten sieben Stellen, an denen Deckelung, Rechteprüfung und das
 * Maskieren der LIKE-Platzhalter richtig sein mussten; zwei von ihnen
 * (farbvererbung, inzuchtkoeffizient) maskierten `%` und `_` bis zuletzt
 * nicht. Genau dieselbe Doppelung noch einmal in den Tests aufzubauen wäre
 * derselbe Fehler eine Ebene höher: Die Prüfung, ob ein Addon das gemeinsame
 * Feld korrekt einbaut, steht deshalb EINMAL hier.
 *
 * Geprüft wird ausschließlich die Addon-Seite - Markup und der Wegfall der
 * eigenen Route. Ob der Kern-Endpunkt selbst richtig antwortet (Deckel,
 * Maskierung, `horses.view`), ist Sache des Kerns und wird dort geprüft;
 * dieses Repo stellt nur sicher, dass es ihn benutzt und nicht daneben eine
 * achte Kopie stehen lässt.
 */
trait PferdesucheHelper {

    /**
     * Das gemeinsame Suchfeld des Kerns (Framework#341) samt seinem Ziel.
     *
     * Beide Hälften gehören zusammen und werden deshalb zusammen geprüft: Ein
     * `hv-pferdesuche`-Feld ohne das über `data-ziel` benannte Element füllt
     * nichts, und /js/horse-search.js meldet das nur in der Browser-Konsole -
     * ein Einbaufehler, der im Betrieb wie "die Suche findet nichts" aussieht.
     *
     * @param string $body HTML der Seite
     * @param string $ziel id des <select>, das das Feld befüllt
     * @param array<string, string> $daten erwartete data-Attribute des Suchfelds,
     *   z. B. ['data-rolle' => 'sire'] oder ['data-nur-mit-farbe' => '1']
     */
    private function assertGemeinsamePferdesuche(string $body, string $ziel, array $daten = []): void {
        $this->assertMatchesRegularExpression(
            '/<input[^>]*class="[^"]*hv-pferdesuche[^"]*"[^>]*data-ziel="' . preg_quote($ziel, '/') . '"/',
            $body,
            "Das Suchfeld für '{$ziel}' muss das gemeinsame Feld des Kerns sein (class=\"hv-pferdesuche\", Addons#125)."
        );

        $this->assertMatchesRegularExpression(
            '/<select[^>]*id="' . preg_quote($ziel, '/') . '"/',
            $body,
            "Zu 'data-ziel=\"{$ziel}\"' fehlt das <select>, das /js/horse-search.js befüllen soll."
        );

        $this->assertStringContainsString(
            '<script src="/js/horse-search.js"></script>',
            $body,
            'Ohne das Skript des Kerns bleibt das Suchfeld ein gewöhnliches Textfeld (Framework#341).'
        );

        if ($daten !== []) {
            // Reihenfolgeunabhängig: Erst das eine <input>-Tag herausschneiden,
            // dann darin prüfen. Ein Regex über das ganze Dokument träfe sonst
            // ein data-Attribut eines NACHFOLGENDEN Feldes mit - bei zwei
            // Suchfeldern auf einer Seite (Vater/Mutter) genau der Fall, in dem
            // die Prüfung wertlos wäre.
            $treffer = [];
            $gefunden = preg_match(
                '/<input[^>]*data-ziel="' . preg_quote($ziel, '/') . '"[^>]*>/',
                $body,
                $treffer
            );
            $this->assertSame(1, $gefunden, "Suchfeld für '{$ziel}' nicht gefunden.");

            foreach ($daten as $attribut => $wert) {
                $this->assertStringContainsString(
                    $attribut . '="' . $wert . '"',
                    $treffer[0],
                    "Das Suchfeld für '{$ziel}' muss '{$attribut}=\"{$wert}\"' an den Kern-Endpunkt durchreichen."
                );
            }
        }

        // Und ausdrücklich nicht mehr: die frühere addoneigene Bauart. Eine
        // <datalist> gibt nur Text zurück, weshalb jede Kopie die ID über
        // einen "[#id]"-Anhang aus dem Anzeigetext zurückgewinnen musste.
        $this->assertStringNotContainsString(
            '<datalist',
            $body,
            'Die addoneigene <datalist>-Pferdesuche ist mit Addons#125 entfallen.'
        );
    }

    /**
     * Die addoneigene Suchroute ist weg - und zwar wirklich weg, nicht nur
     * ungenutzt. Eine Route, die niemand mehr aufruft, aber weiterhin
     * antwortet, ist genau die Stelle, an der eine Korrektur am gemeinsamen
     * Endpunkt später nicht ankommt (Addons#125).
     */
    private function assertEigeneSuchrouteEntfallen(HttpClient $client, string $route): void {
        $this->assertSame(
            404,
            $client->get($route . '?q=Test')->statusCode,
            "Die addoneigene Pferdesuche '{$route}' ist mit Addons#125 entfallen - der Kern liefert sie unter /admin/horses/search."
        );
    }
}
