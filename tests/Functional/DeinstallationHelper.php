<?php
// tests/Functional/DeinstallationHelper.php

namespace Tests\Functional;

use Tests\Support\HttpClient;
use Tests\Support\HttpResponse;

/**
 * Gemeinsamer Helfer für „Deinstallieren → Daten löschen“ über den echten
 * Admin-Weg des Kerns (Framework#338): Rückfrageseite, POST mit abgetipptem
 * Slug, danach erneute Aktivierung. Audit M27/M30/N26.
 *
 * Seit dem Kern-Fix zu Audit N62 (Framework f9921d4) entfernt die Deinstallation auch den
 * Addon-Code (plugins/<slug>) und den Verwaltungseintrag. Der Helfer prüft
 * das und legt den Code danach wieder ab, wie ein Betreiber, der das Addon
 * neu hochlädt. Wer ihn aufruft, aktiviert das Addon danach wieder
 * (aktivieren()), damit die übrigen Tests der Suite es aktiv vorfinden -
 * install() legt die Tabellen dabei leer neu an.
 */
trait DeinstallationHelper {

    /**
     * Ruft die Rückfrageseite auf, bestätigt „Daten löschen“ und liefert die
     * Rückfrageseite (für Aussagen über ihre Vorschau) zurück.
     */
    private function deinstallierenMitDaten(HttpClient $admin, string $slug): HttpResponse {
        $rueckfrage = $admin->get('/admin/plugins/uninstall?slug=' . urlencode($slug));
        $this->assertSame(200, $rueckfrage->statusCode, "Rückfrageseite für '{$slug}' nicht erreichbar.");

        $antwort = $admin->post('/admin/plugins/uninstall', [
            'csrf_token' => $rueckfrage->formField('csrf_token') ?? '',
            'slug' => $slug,
            'daten' => 'loeschen',
            'bestaetigung' => $slug,
        ]);

        // Addon-Code sofort wieder ablegen, auch wenn eine Zusicherung unten
        // scheitert: Sonst fehlte das Addon allen folgenden Tests der Suite.
        $code = \FRAMEWORK_VENDOR_DIR . '/plugins/' . $slug;
        $codeEntfernt = !is_dir($code);
        \copyDirectoryRecursive(\ADDON_PLUGINS_DIR . '/' . $slug, $code);

        $this->assertSame(
            '/admin/plugins?uninstalled=' . urlencode($slug),
            $antwort->location(),
            "Deinstallation von '{$slug}' nicht durchgelaufen. Body: {$antwort->body}"
        );
        $this->assertTrue($codeEntfernt, "Die Deinstallation muss den Addon-Code plugins/{$slug} entfernen (Kern-Audit N62).");

        return $rueckfrage;
    }

    /** Aktiviert ein Addon über den echten Admin-Endpunkt (ruft install()). */
    private function aktivieren(HttpClient $admin, string $slug): void {
        $antwort = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => $slug,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $antwort->location(), "Aktivieren von '{$slug}': {$antwort->body}");
    }

    private function tabelleVorhanden(string $name): bool {
        $stmt = \App\Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :name'
        );
        $stmt->execute(['name' => $name]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Das Protokoll der letzten Deinstallation mit Datenlöschung dieses Addons
     * aus dem Audit-Log (die Anzeige nach dem Redirect fehlt im Kern noch).
     */
    private function deinstallationsProtokoll(string $slug): string {
        $stmt = \App\Database::getInstance()->prepare(
            "SELECT details FROM audit_logs
             WHERE action = 'Addon deinstalliert (Daten gelöscht)' AND details LIKE ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['Slug: ' . $slug . ' -%']);
        $details = $stmt->fetchColumn();
        $this->assertNotFalse($details, "Kein Deinstallationsprotokoll für '{$slug}' im Audit-Log.");
        return (string) $details;
    }
}
