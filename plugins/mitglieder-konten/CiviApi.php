<?php
// mitglieder-konten/CiviApi.php

namespace Plugin\MitgliederKonten;

/**
 * Der schmale Zugang zu CiviCRM (APIv4) - lesend, und nur fuer zwei Fragen:
 * welche Mitgliedschaften laufen, und zu welchem Kontakt gehoeren sie.
 *
 * WARUM SO WENIG. Addons#131 legt ausdruecklich fest: kein Datenabgleich.
 * CiviCRM ist die Quelle dafuer, WER ein Konto bekommt und unter welcher
 * Nummer - nichts weiter. Ein Client, der mehr kann, wird irgendwann fuer
 * mehr benutzt; deshalb kann dieser nur `Membership.get` und `Contact.get`,
 * und beides nur lesend.
 *
 * WARUM DER VERSAND ABGRENZBAR IST. Die eigentliche HTTP-Fahrt steckt in
 * einer einzigen, ueberschreibbaren Methode (`sende`). Ohne das waere das
 * Addon nur gegen eine echte CiviCRM-Instanz pruefbar - und die gibt es auf
 * einem Testlaeufer nicht. Die Tests setzen dort eine Attrappe ein und
 * pruefen alles andere: Aufbau der Anfrage, Auswertung der Antwort,
 * Fehlerverhalten.
 */
class CiviApi {

    /** Zeitgrenze je Aufruf. Ein Abgleichlauf darf nicht am Netz haengenbleiben. */
    public const TIMEOUT_SEKUNDEN = 20;

    /** Hoechstzahl Datensaetze je Abruf - CiviCRM liefert sonst alles auf einmal. */
    public const SEITENGROESSE = 500;

    public function __construct(
        private readonly string $basis,
        private readonly string $apiKey
    ) {}

    public function eingerichtet(): bool {
        return $this->basis !== '' && $this->apiKey !== '';
    }

    /**
     * Laufende Mitgliedschaften.
     *
     * "Laufend" heisst: Der Status ist einer der als *aktiv* gefuehrten
     * (`status_id.is_current_member = true`). Das ist CiviCRMs eigene
     * Auskunft darueber, wer Mitglied IST - nachzubauen ("end_date in der
     * Zukunft") waere geraten: Es gibt Status ohne Enddatum, Kulanzfristen
     * und beendete Mitgliedschaften mit Enddatum in der Zukunft.
     *
     * @param array<int, int> $typIds Leer = alle Mitgliedschaftsarten
     * @return array<int, array{membership_id:int, contact_id:int, email:string, name:string}>
     */
    public function laufendeMitgliedschaften(array $typIds = []): array {
        $where = [['status_id.is_current_member', '=', true]];
        if ($typIds !== []) {
            $where[] = ['membership_type_id', 'IN', array_values($typIds)];
        }

        $zeilen = $this->hole('Membership', [
            'select' => ['id', 'contact_id', 'contact_id.display_name', 'contact_id.email_primary.email'],
            'where' => $where,
        ]);

        $ergebnis = [];
        foreach ($zeilen as $z) {
            $mitgliedschaftId = (int)($z['id'] ?? 0);
            $kontaktId = (int)($z['contact_id'] ?? 0);
            if ($mitgliedschaftId <= 0 || $kontaktId <= 0) {
                continue;
            }
            $ergebnis[] = [
                'membership_id' => $mitgliedschaftId,
                'contact_id' => $kontaktId,
                'name' => trim((string)($z['contact_id.display_name'] ?? '')),
                'email' => trim((string)($z['contact_id.email_primary.email'] ?? '')),
            ];
        }

        return $ergebnis;
    }

    /**
     * Ein Aufruf gegen APIv4, seitenweise bis alles da ist.
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function hole(string $entitaet, array $params): array {
        $alle = [];
        $offset = 0;

        do {
            $params['limit'] = self::SEITENGROESSE;
            $params['offset'] = $offset;

            $antwort = $this->sende($entitaet, 'get', $params);
            $werte = $antwort['values'] ?? null;
            if (!is_array($werte)) {
                throw new CiviApiFehler('Antwort ohne "values" - Adresse oder Schluessel pruefen.');
            }

            foreach ($werte as $zeile) {
                if (is_array($zeile)) {
                    $alle[] = $zeile;
                }
            }

            $offset += self::SEITENGROESSE;
            // Weniger als eine volle Seite heisst: Das war die letzte.
        } while (count($werte) === self::SEITENGROESSE);

        return $alle;
    }

    /**
     * Die eine Stelle, die wirklich ins Netz geht. In Tests ueberschrieben.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function sende(string $entitaet, string $aktion, array $params): array {
        if (!$this->eingerichtet()) {
            throw new CiviApiFehler('CiviCRM-Zugang ist nicht eingerichtet.');
        }

        $url = rtrim($this->basis, '/') . '/civicrm/ajax/api4/' . rawurlencode($entitaet) . '/' . rawurlencode($aktion);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['params' => json_encode($params, JSON_THROW_ON_ERROR)]),
            CURLOPT_TIMEOUT => self::TIMEOUT_SEKUNDEN,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Der Schluessel geht als Kopfzeile, nicht als Parameter: Eine
            // URL landet in Zugriffsprotokollen, eine Kopfzeile nicht.
            CURLOPT_HTTPHEADER => [
                'X-Civi-Auth: Bearer ' . $this->apiKey,
                'X-Requested-With: XMLHttpRequest',
            ],
            // Ausdruecklich gesetzt, nicht dem Standard ueberlassen: Ein
            // Zertifikatsfehler soll ein Fehler sein.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $roh = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $netzfehler = curl_error($ch);
        curl_close($ch);

        if ($roh === false) {
            throw new CiviApiFehler('Keine Verbindung zu CiviCRM: ' . $netzfehler);
        }
        if ($status !== 200) {
            throw new CiviApiFehler("CiviCRM antwortete mit HTTP {$status}.");
        }

        $daten = json_decode((string)$roh, true);
        if (!is_array($daten)) {
            throw new CiviApiFehler('CiviCRM lieferte keine auswertbare Antwort.');
        }

        return $daten;
    }
}

/**
 * Eigene Ausnahme, damit der Aufrufer einen Netz- oder Konfigurationsfehler
 * von einem Programmfehler unterscheiden kann - und ihn dem Benutzer nennen,
 * statt ihn in einen leeren Ergebnisbaum zu verwandeln.
 */
class CiviApiFehler extends \RuntimeException {}
