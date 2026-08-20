<?php
// WrightCoi.php - gemeinsamer Rechenkern für Wright's Inzuchtkoeffizienten (COI).
//
// ACHTUNG: Diese Datei gehört keinem einzelnen Addon. Sie wird von MEHREREN
// Addons ZEICHENGLEICH ausgeliefert (Addons#123). Wer sie ändert, ändert sie in
// allen Addon-Verzeichnissen, die sie mitbringen - dafür genügt ein `cp`;
// tests/Unit/CoiGemeinsameFassungTest.php vergleicht die ausgelieferten Kopien
// byteweise und wird bei der kleinsten Abweichung rot.
//
// Warum mitgeliefert und nicht einmal zentral im Repo abgelegt:
//
//   1. Addons sind EINZELN installierbar - per `cp -r plugins/<slug>` oder über
//      den Addon-Store des Kerns, der immer genau ein Addon-Verzeichnis
//      ausrollt. `anpaarungs-empfehlung` muss ohne `inzuchtkoeffizient` laufen
//      und umgekehrt; eine Datei außerhalb des Addon-Verzeichnisses käme beim
//      Installieren gar nicht mit.
//   2. Ein Symlink auf eine gemeinsame Datei scheidet aus: Der Addon-Installer
//      des Kerns (App\Service\GithubAddonRepository::verifyExtractedTreeIsSafe())
//      verwirft ein entpacktes Paket, sobald es IRGENDEINEN Symlink enthält -
//      das Addon ließe sich dann überhaupt nicht mehr installieren.
//   3. Eine Manifest-Abhängigkeit ("braucht Addon X") kennt der Kern nicht;
//      PluginManager::validateManifest() prüft nur slug/name/version und die
//      beiden Kern-Versionsgrenzen.
//
// Warum die Doppelung damit dennoch beendet ist - und das ist der Punkt von
// #123: Es gibt nur noch EINE Klasse unter EINEM vollqualifizierten Namen. Der
// class_exists()-Wächter in den Plugin.php-Dateien lädt sie genau einmal;
// welche der mitgelieferten Kopien dabei zuerst zum Zug kommt, ist gleichgültig,
// weil danach ALLE beteiligten Addons durch denselben Code rechnen. Selbst bei
// gemischt installierten Addon-Ständen kann die Detailseite also nicht mehr
// einen anderen Prozentwert zeigen als die Sortierung der Anpaarungs-Empfehlung.
// Vorher waren es zwei getrennte Klassen (CoiCalculator, CoiEstimator), die nur
// zufällig zeichengleich waren - und schon einmal auseinandergelaufen sind:
// dem Estimator fehlte Wrights Pfadregel, er lieferte systematisch höhere Werte.

namespace Hengstverzeichnis\Addons\Shared;

/**
 * Wright'scher Inzuchtkoeffizient aus den beiden Eltern-Teilbäumen: reine
 * Rechen-Logik auf einfachen Arrays, unabhängig von HTTP, Controller und
 * Datenbank.
 *
 * `fromParentTrees($sire, $dam)` liefert die Verwandtschaft (Kinship) der
 * beiden ELTERN - und die ist per Definition der COI ihres Nachkommen.
 * "Vollgeschwister als Eltern -> 0,25" heißt also: Das Fohlen zweier
 * Vollgeschwister hat einen COI von 25 %.
 *
 * Erwartete Baumform ist die von App\Service\PedigreeBuilder::build(): je
 * Knoten 'id', optional 'is_placeholder', 'sire', 'dam'. Der kantenbasierte
 * AncestorTreeBuilder der Anpaarungs-Empfehlung (Addons#69) liefert bewusst
 * dieselbe Form und ist per Unit-Test gegen den Kern festgenagelt.
 *
 * Beachte die Tiefensemantik (Addons#72): build() zählt die WURZEL als
 * Generation 1. Ein Baum "Tiefe 6" mit dem FOHLEN als Wurzel reicht je
 * Elternteil nur fünf Ahnengenerationen weit. Aufrufer bauen deshalb je
 * Elternteil einen EIGENEN Baum mit dem Elternteil als Wurzel.
 *
 * Verwendet die im Zuchtwesen übliche Näherungsformel
 * F = Σ (0,5)^(n1+n2+1) über alle gemeinsamen Vorfahren, wobei n1/n2 die
 * Anzahl der Generationsschritte vom jeweiligen Elternteil zum gemeinsamen
 * Vorfahren sind. Wrights Pfadregel verlangt dabei, dass in einem Pfad kein
 * Individuum mehr als einmal vorkommt - unterhalb eines gemeinsamen Vorfahren
 * wird daher nicht weitergesammelt, denn dessen eigene Ahnen sind nur durch ihn
 * hindurch erreichbar und stecken korrekt ausschließlich im Term (1+F_A).
 * Dieser Term selbst wird bewusst nicht rekursiv nachberechnet - das würde bei
 * jedem Aufruf zusätzliche, potenziell exponentiell viele
 * PedigreeBuilder-Abfragen auslösen (kein Caching, siehe
 * docs/plugin-development.md im Framework-Repo). Für die verfügbare Tiefe
 * (max. 6-8 Generationen) ist die dadurch entstehende geringe Unterschätzung in
 * der Praxis vernachlässigbar.
 *
 * `final`, damit sich eine abweichende Variante nicht über eine Unterklasse
 * wieder einschleicht - genau die Fehlerklasse, die #123 beendet. Die Altnamen
 * CoiCalculator/CoiEstimator zeigen per class_alias() hierher.
 */
final class WrightCoi {

    public static function fromParentTrees(?array $sireTree, ?array $damTree): float {
        // Erster Durchlauf ohne Abbruch: bestimmt die Menge der IDs, die in
        // beiden Teilbäumen vorkommen (gemeinsame Vorfahren).
        $sireAll = [];
        self::collectAncestors($sireTree, 0, $sireAll);
        $damAll = [];
        self::collectAncestors($damTree, 0, $damAll);
        $common = array_intersect_key($sireAll, $damAll);

        // Zweiter Durchlauf: Pfade enden am jeweils ersten gemeinsamen
        // Vorfahren (Wrights Pfadregel, s. Klassenkommentar).
        $sireOccurrences = [];
        self::collectAncestors($sireTree, 0, $sireOccurrences, $common);

        $damOccurrences = [];
        self::collectAncestors($damTree, 0, $damOccurrences, $common);

        $sum = 0.0;
        foreach ($sireOccurrences as $ancestorId => $linksFromSire) {
            if (!isset($damOccurrences[$ancestorId])) {
                continue;
            }
            foreach ($linksFromSire as $n1) {
                foreach ($damOccurrences[$ancestorId] as $n2) {
                    $sum += (0.5 ** ($n1 + $n2 + 1));
                }
            }
        }

        return $sum;
    }

    /**
     * Sammelt für jeden erreichbaren, echten (nicht-Platzhalter) Vorfahren im
     * Teilbaum die Anzahl an Generationsschritten ("Links") vom übergebenen
     * Elternteil aus. Ein Pferd kann mehrfach mit unterschiedlicher
     * Schrittzahl auftreten (mehrere Abstammungspfade) - alle Vorkommen
     * fließen einzeln in die Summe ein, das ist im Pfad-Koeffizienten-Verfahren
     * so vorgesehen.
     *
     * Platzhalter (unveröffentlichte oder unbekannte Vorfahren) tragen keine
     * Identität und dürfen deshalb nie als gemeinsamer Vorfahre gelten - sonst
     * entstünde aus zwei "Unbekannt"-Knoten eine Verwandtschaft, und der
     * öffentliche Stammbaum ließe Rückschlüsse auf ausgeblendete Pferde zu.
     *
     * Ist `$stopAt` gesetzt (Menge gemeinsamer Vorfahren-IDs), endet die
     * Rekursion an jedem darin enthaltenen Knoten: seine eigenen Ahnen dürfen
     * nach Wrights Pfadregel nicht als weitere "gemeinsame Vorfahren" gezählt
     * werden, da jeder Pfad zu ihnen den bereits gezählten Vorfahren erneut
     * enthielte.
     *
     * @param array<int, list<int>> &$map Vorfahren-ID => Liste der Schrittzahlen
     * @param array<int, mixed> $stopAt IDs, an denen die Rekursion endet
     */
    private static function collectAncestors(?array $node, int $links, array &$map, array $stopAt = []): void {
        if ($node === null || empty($node['id']) || !empty($node['is_placeholder'])) {
            return;
        }

        $map[$node['id']][] = $links;

        if (isset($stopAt[$node['id']])) {
            return;
        }

        self::collectAncestors($node['sire'] ?? null, $links + 1, $map, $stopAt);
        self::collectAncestors($node['dam'] ?? null, $links + 1, $map, $stopAt);
    }
}
