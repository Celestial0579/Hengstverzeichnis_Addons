<?php
// tests/Functional/DatenregisterDeinstallationTest.php

namespace Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Audit M30: „Deinstallieren → Daten löschen“ entfernt die Tabellen von
 * verkaufsboerse, zuchtschau-ergebnisse, titel-praemierungen und
 * statistik-dashboard tatsächlich.
 *
 * Bis zu diesem Fix trug keines dieser Addons ein Datenregister (`owns`) in
 * seiner plugin.json. Die Rückfrageseite des Kerns meldete deshalb
 * „rückstandsfrei“, das Protokoll „Daten gelöscht“ - und die Tabellen samt
 * Inserenten-E-Mails und Richternamen blieben stehen und waren nach erneuter
 * Aktivierung wieder da.
 *
 * Gegen den gepinnten Kern, über dessen echten Admin-Weg. gesundheitstests
 * (Tabelle UND Ablage) prüft GesundheitstestsPluginTest, die Alttabelle von
 * statistik-dashboard StatistikDashboardPluginTest.
 */
class DatenregisterDeinstallationTest extends FunctionalTestCase {

    use DeinstallationHelper;
    use HorseListHelper;

    /**
     * @return array<string, array{0: string, 1: string[]}>
     */
    public static function addonProvider(): array {
        return [
            'verkaufsboerse' => ['verkaufsboerse', ['plugin_verkaufsboerse_listings']],
            // Kindtabelle zuerst: Ihr Fremdschlüssel verhinderte sonst das
            // DROP der Ergebnistabelle (der Kern löscht ohne FOREIGN_KEY_CHECKS=0).
            'zuchtschau-ergebnisse' => ['zuchtschau-ergebnisse', ['plugin_zuchtschau_teilwertungen', 'plugin_zuchtschau_ergebnisse']],
            'titel-praemierungen' => ['titel-praemierungen', ['plugin_titel_praemierungen']],
            'statistik-dashboard' => ['statistik-dashboard', ['plugin_statistik_dashboard_views', 'plugin_statistik_dashboard_meta']],
        ];
    }

    /**
     * @param string[] $tabellen
     */
    #[DataProvider('addonProvider')]
    public function testDatenLoeschenEntferntDieTabellenDesAddons(string $slug, array $tabellen): void {
        $admin = $this->authenticatedClient();
        $this->aktivieren($admin, $slug);

        $unique = uniqid();
        $horseId = $this->createHorse($admin, "Datenregister-{$slug}-{$unique}", ['status' => 'active']);
        $this->zeileAnlegen($slug, $horseId, $unique);

        foreach ($tabellen as $tabelle) {
            $this->assertTrue($this->tabelleVorhanden($tabelle), "Voraussetzung: {$tabelle} existiert nach der Aktivierung.");
        }

        try {
            $rueckfrage = $this->deinstallierenMitDaten($admin, $slug);

            // Die Rückfrage nennt, was verschwindet, statt „rückstandsfrei“.
            foreach ($tabellen as $tabelle) {
                $this->assertStringContainsString("<code>{$tabelle}</code>", $rueckfrage->body);
            }
            $this->assertStringNotContainsString('rückstandsfrei', $rueckfrage->body);

            foreach ($tabellen as $tabelle) {
                $this->assertFalse($this->tabelleVorhanden($tabelle), "{$tabelle} muss mit „Daten löschen“ verschwinden.");
            }

            $protokoll = $this->deinstallationsProtokoll($slug);
            foreach ($tabellen as $tabelle) {
                $this->assertStringContainsString("Tabelle {$tabelle} entfernt.", $protokoll);
            }
            $this->assertStringNotContainsString('WARNUNG: Tabelle', $protokoll, $protokoll);
            $this->assertStringNotContainsString('NICHT gelöscht', $protokoll, $protokoll);
        } finally {
            $this->aktivieren($admin, $slug);
        }

        // Nach erneuter Aktivierung: frische, leere Tabellen - der alte
        // Bestand kommt nicht wieder.
        foreach ($tabellen as $tabelle) {
            $this->assertTrue($this->tabelleVorhanden($tabelle), "install() muss {$tabelle} neu anlegen.");
            $anzahl = (int) \App\Database::getInstance()->query("SELECT COUNT(*) FROM `{$tabelle}`")->fetchColumn();
            $this->assertSame(0, $anzahl, "{$tabelle} muss nach der Vollöschung leer sein.");
        }
    }

    /** Je Addon eine Zeile per DB, bei zuchtschau Ergebnis plus Teilwertung. */
    private function zeileAnlegen(string $slug, int $horseId, string $unique): void {
        $db = \App\Database::getInstance();
        switch ($slug) {
            case 'verkaufsboerse':
                $db->prepare(
                    'INSERT INTO plugin_verkaufsboerse_listings (horse_id, contact_email) VALUES (?, ?)'
                )->execute([$horseId, "datenregister-{$unique}@example.test"]);
                break;
            case 'zuchtschau-ergebnisse':
                $db->prepare(
                    'INSERT INTO plugin_zuchtschau_ergebnisse (horse_id, event_name, judge) VALUES (?, ?, ?)'
                )->execute([$horseId, "Körung {$unique}", "Richter {$unique}"]);
                $db->prepare(
                    'INSERT INTO plugin_zuchtschau_teilwertungen (ergebnis_id, bezeichnung) VALUES (?, ?)'
                )->execute([(int) $db->lastInsertId(), 'Dressur']);
                break;
            case 'titel-praemierungen':
                $db->prepare(
                    "INSERT INTO plugin_titel_praemierungen (horse_id, art, bezeichnung) VALUES (?, 'titel', ?)"
                )->execute([$horseId, "Titel {$unique}"]);
                break;
            case 'statistik-dashboard':
                $db->prepare(
                    'INSERT INTO plugin_statistik_dashboard_views (horse_id, views) VALUES (?, 5)
                     ON DUPLICATE KEY UPDATE views = 5'
                )->execute([$horseId]);
                break;
        }
    }
}
