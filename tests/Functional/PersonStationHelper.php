<?php
// tests/Functional/PersonStationHelper.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * Legt Kontakte über die echten Admin-Endpunkte an und liefert ihre IDs
 * zurück - das Pendant zu HorseListHelper für die Addons, die an Kontakten
 * hängen (zucht-suche, kontaktanfrage).
 *
 * Seit Framework#336 gibt es nur noch EINE Liste: `persons` und
 * `breeding_stations` sind zu `contacts` zusammengeführt, und die alten
 * Admin-Routen leiten nur mit GET dauerhaft um - `/admin/persons/store` und
 * `/admin/breeding-stations/store` sind POST und existieren schlicht nicht
 * mehr. Der Helfer schreibt deshalb nach `/admin/contacts/store`.
 *
 * createPerson() und createStation() bleiben als Aliasse stehen (Addons#122),
 * damit die Tests der übrigen Kontakt-Addons unverändert weiterlaufen; sie
 * legen beide denselben Datensatz an. Ein neuer Test benutzt createContact().
 *
 * Kontakte sind ab Werk UNVERÖFFENTLICHT (`contacts.is_published` DEFAULT 0),
 * und die öffentliche Detailseite zeigt nur veröffentlichte Datensätze
 * (Kern-#121/#122). Der Helfer setzt `is_published => '1'` deshalb als
 * Vorgabe; wer die Zugriffskontrolle prüfen will, überschreibt das
 * ausdrücklich mit '0'.
 *
 * `contact_public` bleibt bewusst auf dem Vorgabewert 0: Es ist seit dem
 * Zusammenlegen der einzige Schutz für E-Mail, Telefon und Anschrift (siehe
 * docs/kontaktliste-umstellung.md im Kern). Ein Helfer, der ihn stillschweigend
 * setzte, machte jeden Datenschutz-Test wertlos - wer die Freigabe braucht,
 * übergibt sie ausdrücklich.
 */
trait PersonStationHelper {

    /**
     * @param array<string, string> $extra Zusätzliche POST-Felder
     *   (z. B. is_breeder, city, country, email, contact_public).
     */
    private function createContact(HttpClient $admin, string $name, array $extra = []): int {
        $form = $admin->get('/admin/contacts/create');
        $response = $admin->post('/admin/contacts/store', array_merge([
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'name' => $name,
            'is_published' => '1',
        ], $extra));
        $this->assertSame(
            '/admin/contacts?success=created',
            $response->location(),
            "Anlegen des Kontakts '{$name}' fehlgeschlagen, Body: {$response->body}"
        );

        return $this->findRowIdByName($admin, '/admin/contacts', $name, 'Kontakt');
    }

    /**
     * Alias auf createContact() - die Unterscheidung Person/Station gibt es
     * seit Framework#336 nicht mehr.
     *
     * @param array<string, string> $extra Zusätzliche POST-Felder.
     */
    private function createPerson(HttpClient $admin, string $name, array $extra = []): int {
        return $this->createContact($admin, $name, $extra);
    }

    /**
     * Alias auf createContact(), siehe createPerson().
     *
     * @param array<string, string> $extra Zusätzliche POST-Felder.
     */
    private function createStation(HttpClient $admin, string $name, array $extra = []): int {
        return $this->createContact($admin, $name, $extra);
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
