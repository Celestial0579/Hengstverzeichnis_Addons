<?php
// tests/Functional/PersonStationHelper.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * Legt Personen und Deckstationen über die echten Admin-Endpunkte an und
 * liefert ihre IDs zurück - das Pendant zu HorseListHelper für die beiden
 * Addons, die an Personen und Stationen hängen (zucht-suche, kontaktanfrage).
 *
 * Beide Datensatzarten sind ab Werk UNVERÖFFENTLICHT
 * (persons.is_published / breeding_stations.is_published DEFAULT 0), und
 * beide öffentlichen Detailseiten zeigen nur veröffentlichte Datensätze
 * (Kern-#121/#122). Die Helfer setzen `is_published => '1'` deshalb als
 * Vorgabe; wer die Zugriffskontrolle prüfen will, überschreibt das
 * ausdrücklich mit '0'.
 */
trait PersonStationHelper {

    /**
     * @param array<string, string> $extra Zusätzliche POST-Felder
     *   (z. B. is_breeder, city, country, email, contact_public).
     */
    private function createPerson(HttpClient $admin, string $name, array $extra = []): int {
        $form = $admin->get('/admin/persons/create');
        $response = $admin->post('/admin/persons/store', array_merge([
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'is_published' => '1',
        ], $extra));
        $this->assertSame(
            '/admin/persons?success=created',
            $response->location(),
            "Anlegen der Person '{$name}' fehlgeschlagen, Body: {$response->body}"
        );

        return $this->findRowIdByName($admin, '/admin/persons', $name, 'Person');
    }

    /**
     * @param array<string, string> $extra Zusätzliche POST-Felder.
     */
    private function createStation(HttpClient $admin, string $name, array $extra = []): int {
        $form = $admin->get('/admin/breeding-stations/create');
        $response = $admin->post('/admin/breeding-stations/store', array_merge([
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'is_published' => '1',
        ], $extra));
        $this->assertSame(
            '/admin/breeding-stations?success=created',
            $response->location(),
            "Anlegen der Deckstation '{$name}' fehlgeschlagen, Body: {$response->body}"
        );

        return $this->findRowIdByName($admin, '/admin/breeding-stations', $name, 'Deckstation');
    }

    /**
     * Liest die ID aus der Verwaltungstabelle: Die Zeile mit
     * `<strong>Name</strong>` trägt die ID in ihrer ersten rein numerischen
     * Zelle. Gesucht wird über den Namensparameter der Liste, damit der
     * Treffer auch dann auf Seite 1 steht, wenn die Liste blättert.
     */
    private function findRowIdByName(HttpClient $admin, string $listUrl, string $name, string $gattung): int {
        $page = $admin->get($listUrl . '?search=' . urlencode($name));

        preg_match_all('/<tr[^>]*>((?:(?!<\/tr>).)*?)<\/tr>/s', $page->body, $rowMatches);
        foreach ($rowMatches[1] as $rowHtml) {
            if (!str_contains($rowHtml, '<strong>' . $name . '</strong>')) {
                continue;
            }
            preg_match('/<td[^>]*>(\d+)<\/td>/', $rowHtml, $idMatch);
            $this->assertNotEmpty($idMatch, "Zeile für {$gattung} '{$name}' enthält keine numerische ID-Zelle.");
            return (int) $idMatch[1];
        }

        $this->fail("Konnte ID von {$gattung} '{$name}' nicht aus {$listUrl} ermitteln. Body: {$page->body}");
    }
}
