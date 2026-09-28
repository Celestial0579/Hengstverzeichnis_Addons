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
 * Vorfahren sind.
 *
 * Wrights Pfadregel: Ein Pfad Vater -> … -> A -> … -> Mutter darf kein
 * Individuum mehr als einmal enthalten. Geprüft wird das am PAAR: Die beiden
 * Halbpfade (Vater -> A und Mutter -> A) dürfen sich nur in A selbst
 * schneiden. Ahnen von A, die nur durch A hindurch erreichbar sind, fallen
 * damit heraus (ihr Beitrag steckt im Term 1+F_A). Ahnen von A, die auf der
 * anderen Seite auf einem EIGENEN Weg erreichbar sind, zählen dagegen mit.
 * Bis Revision 2 endete jeder Pfad am ersten gemeinsamen Vorfahren - das
 * schnitt genau diese Pfade ab (Audit M29). Gegenbeispiele:
 *
 *  - Linienzucht auf B und dessen Vater A, beidseitig: Vater = B × X,
 *    Mutter = B × Y, Y hat Vater A. Richtig 0,15625, bisher 0,125.
 *  - A nur hinter einem anderen gemeinsamen Vorfahren B erreichbar, auf der
 *    Gegenseite aber direkt: richtig 0,0625, bisher 0,03125.
 *
 * Der Term 1+F_A selbst wird bewusst nicht rekursiv nachberechnet - das würde bei
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

    /**
     * Revisionsmerkmal des Rechenkerns. Seit Revision 2 gilt Wrights
     * Pfadregel vollständig (Audit M29); eine ältere Fassung hat die
     * Konstante nicht. Die Plugin.php-Dateien prüfen sie nach dem
     * class_exists()-Wächter: Der PluginManager lädt die Addons alphabetisch,
     * bei beiden aktiven Addons rechnet also immer die Kopie aus
     * anpaarungs-empfehlung - ein Update nur von inzuchtkoeffizient bliebe
     * sonst ohne jeden Hinweis wirkungslos.
     */
    public const REVISION = 2;

    /**
     * Aufwand: Je Seite gibt es höchstens 2^Tiefe - 1 Pfade (255 bei Tiefe
     * 8), gesammelt in EINEM Durchlauf ohne Abbruch. Verglichen werden nur
     * Pfadpaare zu demselben Vorfahren. Im Review gemessen: 200 Kandidaten bei
     * Tiefe 8 in einer geschlossenen Population (2-8 Tiere je Generation)
     * 0,25-0,6 s statt vorher 0,02 s, bei realistischer Population (30 je
     * Generation) 0,08 s. Ein Deckel ist daher nicht nötig.
     */
    public static function fromParentTrees(?array $sireTree, ?array $damTree): float {
        $sirePaths = [];
        self::collectAncestors($sireTree, [], $sirePaths);
        $damPaths = [];
        self::collectAncestors($damTree, [], $damPaths);

        $sum = 0.0;
        foreach ($sirePaths as $ancestorId => $pathsFromSire) {
            if (!isset($damPaths[$ancestorId])) {
                continue;
            }
            foreach ($pathsFromSire as $p1) {
                foreach ($damPaths[$ancestorId] as $p2) {
                    // Wrights Pfadregel am Paar: Die beiden Halbpfade teilen
                    // nur den gemeinsamen Vorfahren selbst (s. Klassenkommentar).
                    if (count(array_intersect_key($p1, $p2)) !== 1) {
                        continue;
                    }
                    $n1 = count($p1) - 1;
                    $n2 = count($p2) - 1;
                    $sum += (0.5 ** ($n1 + $n2 + 1));
                }
            }
        }

        return $sum;
    }

    /**
     * Sammelt für jeden erreichbaren, echten (nicht-Platzhalter) Vorfahren im
     * Teilbaum ALLE Pfade vom übergebenen Elternteil zu ihm, jeweils als
     * ID-Menge (Elternteil und Vorfahre eingeschlossen). Die Schrittzahl ist
     * count($pfad) - 1. Ein Pferd kann über mehrere Abstammungspfade
     * auftreten - jeder Pfad wird einzeln geführt, denn ob ein Paar zählt,
     * entscheidet erst der Vergleich mit der Gegenseite (fromParentTrees()).
     *
     * Platzhalter (unveröffentlichte oder unbekannte Vorfahren) tragen keine
     * Identität und dürfen deshalb nie als gemeinsamer Vorfahre gelten - sonst
     * entstünde aus zwei "Unbekannt"-Knoten eine Verwandtschaft, und der
     * öffentliche Stammbaum ließe Rückschlüsse auf ausgeblendete Pferde zu.
     *
     * Kein Abbruch an gemeinsamen Vorfahren mehr (bis Revision 2 per
     * `$stopAt`, Audit M29): Welche Ahnen eines gemeinsamen Vorfahren
     * mitzählen, hängt davon ab, ob die Gegenseite sie auf eigenem Weg
     * erreicht - das entscheidet die Schnittmengenprüfung am Paar.
     *
     * Defensiver Zyklusschutz: Ein Pfad enthält kein Individuum doppelt.
     * PedigreeBuilder verhindert Zyklen schon selbst (#131), eine fremde
     * Baumquelle vielleicht nicht.
     *
     * @param array<int|string, true> $pathIds IDs auf dem Pfad bis hierher
     * @param array<int|string, list<array<int|string, true>>> &$map Vorfahren-ID => Liste der Pfade
     */
    private static function collectAncestors(?array $node, array $pathIds, array &$map): void {
        if ($node === null || empty($node['id']) || !empty($node['is_placeholder'])) {
            return;
        }

        $id = $node['id'];
        if (isset($pathIds[$id])) {
            return;
        }

        $pathIds[$id] = true;
        $map[$id][] = $pathIds;

        self::collectAncestors($node['sire'] ?? null, $pathIds, $map);
        self::collectAncestors($node['dam'] ?? null, $pathIds, $map);
    }
}
