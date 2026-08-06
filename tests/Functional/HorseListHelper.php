<?php
// tests/Functional/HorseListHelper.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * Gemeinsamer Helfer für Funktionstests, die ein Pferd über die Admin-Route
 * anlegen und anschließend dessen ID aus /admin/horses ermitteln müssen.
 *
 * Wichtig: /admin/horses listet ALLE Pferde `ORDER BY name ASC`
 * (HorseController::index() im Framework-Repo) - da die zugrunde liegende
 * Testdatenbank über den gesamten PHPUnit-Prozess (und damit auch andere
 * Testklassen dieses Addon-Repos) hinweg geteilt wird, kann zum Zeitpunkt der
 * Abfrage bereits eine größere, alphabetisch gemischte Menge an Testpferden
 * aus anderen Testklassen vorhanden sein. Ein einfacher "erstes <td>-Digit im
 * gesamten Dokument, gefolgt irgendwann vom gesuchten Namen"-Regex (ohne
 * Zeilen-Begrenzung) würde daher zuverlässig die ID eines FALSCHEN,
 * alphabetisch früher gelisteten Pferds liefern, sobald mindestens ein
 * weiteres Testpferd mit alphabetisch früherem Namen in der DB existiert.
 * findHorseIdByName() grenzt die Suche deshalb explizit auf einzelne
 * `<tr>`-Zeilen ein, bevor innerhalb der passenden Zeile nach der ID-Zelle
 * gesucht wird.
 */
trait HorseListHelper {

    /**
     * @param array<string, string> $extra Zusätzliche POST-Felder (z. B. sire_id, status, breeding_station).
     */
    private function createHorse(HttpClient $admin, string $name, array $extra = []): int {
        $createForm = $admin->get('/admin/horses/create');
        $createResponse = $admin->post('/admin/horses/store', array_merge([
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => $name,
        ], $extra));
        $this->assertSame(
            '/admin/horses?success=created',
            $createResponse->location(),
            "Anlegen von '{$name}' fehlgeschlagen, Body: {$createResponse->body}"
        );

        return $this->findHorseIdByName($admin, $name);
    }

    private function findHorseIdByName(HttpClient $admin, string $name): int {
        $page = $admin->get('/admin/horses');

        preg_match_all('/<tr[^>]*>((?:(?!<\/tr>).)*?)<\/tr>/s', $page->body, $rowMatches);
        foreach ($rowMatches[1] as $rowHtml) {
            if (!str_contains($rowHtml, '<strong>' . $name . '</strong>')) {
                continue;
            }
            preg_match('/<td[^>]*>(\d+)<\/td>/', $rowHtml, $idMatch);
            $this->assertNotEmpty($idMatch, "Zeile für Pferd '{$name}' enthält keine numerische ID-Zelle.");
            return (int) $idMatch[1];
        }

        $this->fail("Konnte ID von Pferd '{$name}' nicht aus /admin/horses ermitteln. Body: {$page->body}");
    }
}
