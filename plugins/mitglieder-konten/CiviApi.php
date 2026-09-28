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
 *
 * WARUM DAS ZIEL GEPRUEFT WIRD (Audit M5). Der Schluessel geht als
 * Kopfzeile an die gespeicherte Basis-Adresse. Zeigt die auf ein internes
 * Ziel (Loopback, privates Netz, Cloud-Metadaten auf 169.254.169.254), wird
 * der Server zum Werkzeug fuer Anfragen ins eigene Netz, und die
 * Fehlermeldung verriete, was dort antwortet. Deshalb: nur https, der Host
 * wird VOR der Verbindung aufgeloest, jede aufgeloeste IP muss oeffentlich
 * sein, und genau die gepruefte IP wird per CURLOPT_RESOLVE festgenagelt -
 * sonst koennte ein zweiter DNS-Abruf zwischen Pruefung und Verbindung eine
 * andere Antwort liefern (DNS-Rebinding). Wer CiviCRM bewusst im eigenen
 * Netz betreibt, gibt den Host ueber die Umgebungsvariable
 * MITGLIEDER_KONTEN_INTERNE_HOSTS frei - nur der Serverbetreiber, nie ueber
 * die Oberflaeche.
 */
class CiviApi {

    /** Zeitgrenze je Aufruf. Ein Abgleichlauf darf nicht am Netz haengenbleiben. */
    public const TIMEOUT_SEKUNDEN = 20;

    /** Hoechstzahl Datensaetze je Abruf - CiviCRM liefert sonst alles auf einmal. */
    public const SEITENGROESSE = 500;

    /**
     * Hoechstzahl IDs je `IN`-Abfrage in statusNachId(). Eine Liste mit
     * 1.500 IDs in einem Aufruf sprengt je nach Server die Laenge des
     * POST-Felds bzw. die Parametergrenze der Datenbank.
     */
    public const ID_BLOCK = 200;

    /** Umgebungsvariable fuer die Ausnahme "CiviCRM im eigenen Netz" (M5). */
    public const ENV_INTERNE_HOSTS = 'MITGLIEDER_KONTEN_INTERNE_HOSTS';

    /** Die EINE Meldung fuer Netz- und Zielfehler - Details nur im Serverprotokoll (M5). */
    public const FEHLER_NETZ = 'CiviCRM ist nicht erreichbar oder die Adresse ist nicht zulässig.';
    public const FEHLER_ZUGANG = 'CiviCRM hat den Zugang abgelehnt - Schlüssel prüfen.';
    public const FEHLER_ANTWORT = 'CiviCRM lieferte keine auswertbare Antwort.';

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
     * Status bestimmter Mitgliedschaften, gezielt per ID (Audit N30).
     *
     * WARUM NICHT UEBER laufendeMitgliedschaften(). Der Tageslauf leitete
     * "beendet" frueher aus "fehlt in der Liste der laufenden" ab. Damit
     * sperrte jede leere oder gefilterte Antwort - ein Typfilter, eine
     * ACL-Einschraenkung, ein halb eingerichteter API-Benutzer - den ganzen
     * Bestand. Hier wird jede zugeordnete ID einzeln erfragt, OHNE Typfilter:
     * Der Filter "Mitgliedschaftsarten" gilt nur fuer die Anlage.
     *
     * Ergebnis je ID: true (laeuft), false (laeuft ausdruecklich nicht),
     * null (Zeile da, aber ohne auswertbares Statusfeld - etwa weil eine ACL
     * die Join-Spalte ausblendet; "unklar" ist NICHT "beendet"). IDs, die
     * CiviCRM gar nicht liefert, fehlen im Array.
     *
     * @param array<int, int> $ids
     * @return array<int, ?bool>
     */
    public function statusNachId(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        $gefragt = array_flip($ids);
        $ergebnis = [];

        foreach (array_chunk($ids, self::ID_BLOCK) as $block) {
            $zeilen = $this->hole('Membership', [
                'select' => ['id', 'status_id.is_current_member'],
                'where' => [['id', 'IN', $block]],
            ]);

            foreach ($zeilen as $z) {
                $id = (int)($z['id'] ?? 0);
                // Nur, wonach gefragt wurde - eine Antwort mit fremden IDs
                // darf nichts anderes beeinflussen.
                if (!isset($gefragt[$id])) {
                    continue;
                }
                $ergebnis[$id] = self::alsStatus($z['status_id.is_current_member'] ?? null);
            }
        }

        return $ergebnis;
    }

    /** true/false nur bei eindeutigem Wert, sonst null ("unklar"). */
    private static function alsStatus(mixed $wert): ?bool {
        if ($wert === true || $wert === 1 || $wert === '1' || $wert === 'true') {
            return true;
        }
        if ($wert === false || $wert === 0 || $wert === '0' || $wert === 'false') {
            return false;
        }

        return null;
    }

    /**
     * Ist diese IP ein zulaessiges Ziel - also global erreichbar und nicht
     * privat, Loopback, Link-Local, CGNAT, Multicast oder reserviert (M5)?
     *
     * FILTER_FLAG_GLOBAL_RANGE deckt RFC 6890 ab. Dazu kommen die Formen, in
     * denen eine IPv4 in einer IPv6 steckt: IPv4-mapped (::ffff:0:0/96),
     * NAT64 (64:ff9b::/96 - nach RFC 6890 "global", fuehrt aber ueber den
     * NAT64-Uebersetzer zur eingebetteten IPv4) und 6to4 (2002::/16). Die
     * eingebettete IPv4 wird herausgeloest und selbst geprueft; ist sie
     * nicht oeffentlich, ist es die IPv6 auch nicht. Multicast und das
     * lokale NAT64-Praefix 64:ff9b:1::/48 laesst der Filter durch, deshalb
     * ausdruecklich.
     */
    public static function ipIstOeffentlich(string $ip): bool {
        $ip = trim($ip);
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }

        if (strlen($bin) === 16) {
            $eingebettet = null;
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
                $eingebettet = substr($bin, 12, 4);                     // ::ffff:a.b.c.d
            } elseif (str_starts_with($bin, "\x00\x64\xff\x9b" . str_repeat("\0", 8))) {
                $eingebettet = substr($bin, 12, 4);                     // 64:ff9b::a.b.c.d
            } elseif (str_starts_with($bin, "\x20\x02")) {
                $eingebettet = substr($bin, 2, 4);                      // 2002:aabb:ccdd::
            }
            if ($eingebettet !== null && !self::ipIstOeffentlich((string)inet_ntop($eingebettet))) {
                return false;
            }
            if ($bin[0] === "\xff") {
                return false;                                           // ff00::/8 Multicast
            }
            if (str_starts_with($bin, "\x00\x64\xff\x9b\x00\x01")) {
                return false;                                           // 64:ff9b:1::/48 lokales NAT64
            }
        } elseif ((ord($bin[0]) & 0xF0) === 0xE0) {
            return false;                                               // 224.0.0.0/4 Multicast
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * Prueft das Ziel einer Anfrage und liefert, woran die Verbindung
     * festgenagelt wird (M5). Reine Funktion - die Aufloesung kommt als
     * Parameter, damit sie sich ohne Netz pruefen laesst.
     *
     * @param callable(string): array<int, string> $aufloesen Host -> IPs
     * @param array<int, string> $interneHosts Ausnahmen aus der Umgebung (exakt, klein)
     * @return array{host:string, port:int, ips:list<string>}
     * @throws CiviApiFehler mit generischer Meldung; Einzelheiten in detail()
     */
    public static function zielPruefen(string $basis, callable $aufloesen, array $interneHosts = []): array {
        $teile = parse_url(trim($basis));
        if (!is_array($teile) || strtolower((string)($teile['scheme'] ?? '')) !== 'https') {
            throw new CiviApiFehler(self::FEHLER_NETZ, 'Ziel abgelehnt: nur https ist zulaessig.');
        }
        if (isset($teile['user']) || isset($teile['pass'])) {
            throw new CiviApiFehler(self::FEHLER_NETZ, 'Ziel abgelehnt: Zugangsdaten in der URL.');
        }
        if (($teile['query'] ?? '') !== '' || ($teile['fragment'] ?? '') !== '') {
            throw new CiviApiFehler(self::FEHLER_NETZ, 'Ziel abgelehnt: Query oder Fragment in der Basis-Adresse.');
        }

        $host = strtolower(rtrim((string)($teile['host'] ?? ''), '.'));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            throw new CiviApiFehler(self::FEHLER_NETZ, 'Ziel abgelehnt: kein Host.');
        }
        $port = (int)($teile['port'] ?? 443);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $ips = array_values(array_filter(
                array_map('strval', (array)$aufloesen($host)),
                static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
            ));
        }
        if ($ips === []) {
            throw new CiviApiFehler(self::FEHLER_NETZ, "Ziel abgelehnt: {$host} loest nicht auf.");
        }

        $intern = in_array($host, array_map(static fn($h): string => strtolower(trim((string)$h)), $interneHosts), true);
        if (!$intern) {
            // JEDE Adresse muss bestehen, nicht nur die erste: Bei einer
            // gemischten Antwort entschiede sonst die Reihenfolge.
            foreach ($ips as $ip) {
                if (!self::ipIstOeffentlich($ip)) {
                    throw new CiviApiFehler(self::FEHLER_NETZ, "Ziel abgelehnt: {$host} loest auf nicht-oeffentliche Adresse {$ip} auf.");
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /** @return array<int, string> Hostnamen aus MITGLIEDER_KONTEN_INTERNE_HOSTS */
    public static function interneHosts(): array {
        $roh = getenv(self::ENV_INTERNE_HOSTS);
        if (!is_string($roh) || trim($roh) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(string $h): string => strtolower(rtrim(trim($h), '.')),
            explode(',', $roh)
        )));
    }

    /**
     * A- und AAAA-Eintraege eines Hosts. IPv4 zuerst - daran wird die
     * Verbindung festgenagelt, und IPv4 ist auf Webservern verlaesslicher
     * erreichbar. In Tests ueberschrieben.
     *
     * @return array<int, string>
     */
    protected function aufloesen(string $host): array {
        $ips = @gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($aaaa) ? $aaaa : [] as $eintrag) {
            if (isset($eintrag['ipv6']) && is_string($eintrag['ipv6'])) {
                $ips[] = $eintrag['ipv6'];
            }
        }

        return array_values(array_unique($ips));
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
                throw new CiviApiFehler(self::FEHLER_ANTWORT, 'Antwort ohne "values".');
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

        // M5: erst pruefen und aufloesen, dann verbinden - und zwar genau mit
        // der geprueften Adresse.
        $ziel = self::zielPruefen($this->basis, fn(string $host): array => $this->aufloesen($host), self::interneHosts());
        $ip = $ziel['ips'][0];
        $festgenagelt = sprintf(
            '%s:%d:%s',
            $ziel['host'],
            $ziel['port'],
            str_contains($ip, ':') ? '[' . $ip . ']' : $ip
        );

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
            // M5: Verbunden wird mit der oben geprueften IP, nicht mit dem,
            // was ein zweiter DNS-Abruf liefert. Das Zertifikat wird weiter
            // gegen den Hostnamen geprueft. EINSCHRAENKUNG: Setzt die
            // Umgebung https_proxy/HTTPS_PROXY, loest der Proxy auf und
            // CURLOPT_RESOLVE greift nicht - dann ist der Egress-Filter des
            // Proxys die Grenze (siehe README).
            CURLOPT_RESOLVE => [$festgenagelt],
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
        ]);

        $roh = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $netzfehler = curl_error($ch);
        unset($ch);

        // M5: Die Oberflaeche erfaehrt nur die generische Meldung. Ein
        // curl-Text wie "Connection refused" oder ein Statuscode eines
        // internen Dienstes waere eine Auskunft ueber das interne Netz.
        if ($roh === false) {
            throw new CiviApiFehler(self::FEHLER_NETZ, 'Keine Verbindung zu ' . $ziel['host'] . ': ' . $netzfehler);
        }
        if ($status === 401 || $status === 403) {
            throw new CiviApiFehler(self::FEHLER_ZUGANG, "HTTP {$status} von {$ziel['host']}");
        }
        if ($status !== 200) {
            throw new CiviApiFehler(self::FEHLER_ANTWORT, "HTTP {$status} von {$ziel['host']}");
        }

        $daten = json_decode((string)$roh, true);
        if (!is_array($daten)) {
            throw new CiviApiFehler(self::FEHLER_ANTWORT, 'Antwort ist kein JSON-Objekt.');
        }

        return $daten;
    }
}

/**
 * Eigene Ausnahme, damit der Aufrufer einen Netz- oder Konfigurationsfehler
 * von einem Programmfehler unterscheiden kann - und ihn dem Benutzer nennen,
 * statt ihn in einen leeren Ergebnisbaum zu verwandeln.
 */
class CiviApiFehler extends \RuntimeException {

    /**
     * @param string $message Generisch - das sieht die Oberflaeche und das Protokoll.
     * @param string $detail Einzelheiten (curl-Text, Status, abgelehnte IP) - NUR fuers
     *                       Serverprotokoll, nie mit dem Schluessel (M5).
     */
    public function __construct(string $message, private readonly string $detail = '') {
        parent::__construct($message);
    }

    public function detail(): string {
        return $this->detail;
    }

    /** Schreibt die Einzelheiten ins Serverprotokoll - nur, wenn es welche gibt. */
    public function protokollieren(): void {
        if ($this->detail !== '') {
            error_log('[mitglieder-konten] ' . $this->getMessage() . ' ' . $this->detail);
        }
    }
}
