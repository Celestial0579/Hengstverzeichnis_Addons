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
 * Die Deinstallation lässt ein Addon entdeckt, aber deaktiviert. Wer sie
 * aufruft, aktiviert das Addon danach wieder (aktivieren()), damit die
 * übrigen Tests der Suite es aktiv vorfinden - install() legt die Tabellen
 * dabei leer neu an.
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
        $this->assertSame(
            '/admin/plugins?uninstalled=' . urlencode($slug),
            $antwort->location(),
            "Deinstallation von '{$slug}' nicht durchgelaufen. Body: {$antwort->body}"
        );

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
