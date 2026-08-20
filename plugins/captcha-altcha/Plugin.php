<?php
// captcha-altcha/Plugin.php
//
// Addon für Hengstverzeichnis_Framework, Teil von
// Celestial0579/Hengstverzeichnis_Addons#133: ein Rechennachweis im Browser
// (Proof of Work) nach dem ALTCHA-Verfahren - SELBST GEHOSTET.
//
// WAS DIESES ADDON VON DEN BEIDEN ANDEREN UNTERSCHEIDET.
//
// captcha-turnstile und captcha-hcaptcha binden einen Drittanbieter ein:
// Skript aus fremder Herkunft, IP-Adresse des Besuchers beim Anbieter,
// Drittlandbezug, Eintrag in der Datenschutzerklärung, Lockerung der
// Content-Security-Policy. Dieses Addon tut nichts davon:
//
//   * kein fremdes Skript - das JavaScript steht im Formularfragment selbst
//     und arbeitet ausschliesslich im Browser des Besuchers,
//   * keine ausgehende Verbindung - geprüft wird auf diesem Server,
//   * keine Schlüssel und kein Geheimnis - es gibt keinen Anbieter, bei dem
//     man sich anmelden müsste,
//   * keine Änderung an der Content-Security-Policy - `script-src 'self'
//     'unsafe-inline'` deckt das Fragment bereits ab.
//
// Damit ist es die einzige der drei Erweiterungen, die auch für das
// DSGVO-Portal in Frage kommt (siehe die Begründung in
// App\Security\Captcha, weshalb der Kern dort bewusst keinen Drittanbieter
// vorsieht) - allerdings mit einer Einschränkung, die weiter unten steht und
// nicht überlesen werden sollte.
//
// WIE DER NACHWEIS FUNKTIONIERT.
//
// Der Server würfelt eine Zahl `n` zwischen 0 und einer Obergrenze, würfelt
// dazu ein zufälliges `salt` und veröffentlicht NUR `salt` und
// `sha256(salt . n)`. Der Browser probiert 0, 1, 2, … durch, bis er dieselbe
// Prüfsumme herausbekommt, und schickt das gefundene `n` mit. Es gibt keine
// Abkürzung: Wer die Zahl haben will, muss im Mittel die halbe Obergrenze
// durchrechnen. Für einen Besucher ist das ein Wimpernschlag; für eine
// Spam-Maschine, die tausende Formulare pro Minute abschickt, ist es der
// Kostenfaktor, um den es geht.
//
// WARUM DIE AUFGABE IN DER SITZUNG LIEGT UND NICHT SIGNIERT MITGEGEBEN WIRD.
// Das ALTCHA-Verfahren signiert die Aufgabe üblicherweise mit einem HMAC und
// gibt sie dem Browser mit; der Server braucht dann keinen Zustand, muss aber
// gegen das Wiederverwenden einer einmal gelösten Aufgabe eine Liste
// benutzter Aufgaben führen (mit allem, was dazugehört: Tabelle, Index,
// Aufräumlauf) - und ein Geheimnis für den HMAC verwahren. Hier liegt die
// Aufgabe stattdessen in der Sitzung des Besuchers, genau wie beim
// eingebauten Schutz des Kerns. Das erledigt drei Dinge auf einmal:
// Einmalverwendung ist geschenkt (abholen() entfernt die Aufgabe, immer),
// es gibt keine Tabelle aufzuräumen, und es gibt kein Geheimnis, das
// auslaufen könnte. Der Preis ist, dass das Formular in derselben Sitzung
// abgeschickt werden muss, in der es geladen wurde - was es ohnehin tut.
//
// DIE EINSCHRÄNKUNG: OHNE JAVASCRIPT GEHT DER NACHWEIS NICHT.
//
// Der Rechennachweis läuft im Browser. Wer JavaScript abgeschaltet hat oder
// einen Browser ohne `crypto.subtle` benutzt (das gibt es auf unverschlüsselt
// ausgelieferten Seiten - SubtleCrypto verlangt einen sicheren Kontext),
// bekommt kein Ergebnis und käme durch das Formular nicht mehr durch. Auf
// dem DSGVO-Portal wäre das besonders unangenehm: Dort machen Betroffene
// ihre Rechte aus Art. 15/17 DSGVO geltend, und eine technische Hürde, die
// sie aussperrt, ist etwas anderes als ein unbequemes Kontaktformular.
//
// Deshalb gibt es den RÜCKFALL (Einstellung "Rückfall ohne JavaScript",
// standardmässig an): Ist er aktiv, steht im `<noscript>`-Bereich zusätzlich
// die Rechenaufgabe des Kerns, und wer den Nachweis nicht liefern kann,
// beantwortet sie. Das ist ehrlich zu benennen: Der Schutz ist dann so stark
// wie die schwächere der beiden Hürden, also so stark wie der eingebaute
// Schutz des Kerns - nicht stärker. Wer das nicht will, schaltet den Rückfall
// ab und nimmt in Kauf, Besucher ohne JavaScript auszusperren. Die
// Verwaltungsseite sagt beides.
//
// DER AUFBAU IST FÜR ALLE DREI ANBIETER-ADDONS DERSELBE (Addons#133 nennt es
// "ein Muster, dreimal angewandt"): Plugin meldet den Slug, Widget liefert
// das Formularfragment, Aufgabe stellt und prüft den Nachweis (bei den
// anderen beiden heisst die Klasse "Anbieter" und ruft eine fremde URL),
// Konfiguration hält die Einstellungen, VerwaltungController ist die
// Oberfläche dafür.
//
// Installation (lokal im Framework-Repo):
//   cp -r captcha-altcha plugins/captcha-altcha
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren und
// unter Admin -> Systemeinstellungen als Spam-Schutz auswählen. Anders als
// bei den beiden Drittanbieter-Addons ist der Anbieter SOFORT wählbar - es
// gibt keine Schlüssel zu hinterlegen.

namespace Plugin\CaptchaAltcha;

use App\Controllers\BaseController;
use App\Database;
use App\Permission\GroupMembership;
use App\Plugin\HookManager;
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;
use App\Router;
use App\Security\Captcha;
use PDO;

class Plugin {

    /**
     * Der eigene Slug. Er ist zugleich der Anbietername in der Auswahl
     * (`captcha.providers`), das Berechtigungsmodul und die Protokoll-
     * Kategorie (Framework#352).
     */
    public const SLUG = 'captcha-altcha';

    /** Der Slug, unter dem der Betreiber diesen Anbieter auswählt. */
    public const ANBIETER = self::SLUG;

    /** Berechtigungsmodul dieses Addons. */
    public const MODUL = self::SLUG;

    public function register(HookManager $hooks): void {
        $hooks->addFilter('captcha.providers', [$this, 'anbieterMelden']);
        $hooks->addFilter('captcha.render', [$this, 'widgetRendern']);
        $hooks->addFilter('captcha.verify', [$this, 'antwortPruefen']);
        $hooks->addFilter('admin.dashboard_tiles', [$this, 'dashboardKachel']);
    }

    /**
     * @return array<int, array{method:string, path:string, callback:array{0:class-string, 1:string}}>
     */
    public function routes(): array {
        return [
            ['method' => 'GET', 'path' => '/verwaltung', 'callback' => [VerwaltungController::class, 'index']],
            ['method' => 'POST', 'path' => '/verwaltung/speichern', 'callback' => [VerwaltungController::class, 'speichern']],
        ];
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [[
            'module' => self::MODUL,
            'action' => 'manage',
            'label' => 'Einstellungen verwalten',
            'module_label' => 'Spam-Schutz: ALTCHA (selbst gehostet)',
        ]];
    }

    /**
     * Meldet den Anbieter zur Auswahl an - IMMER.
     *
     * Die gemeinsame Regel der drei Addons aus Addons#133 lautet "ohne
     * Schlüssel keine Anmeldung im Anbieterverzeichnis". Sie hat einen Zweck:
     * Ein Anbieter, dem die Zugangsdaten fehlen, würde jede Prüfung ablehnen,
     * und der Betreiber merkte es erst an den Beschwerden seiner Besucher.
     *
     * Hier greift sie nicht, weil es nichts zu fehlen gibt: Dieses Addon hat
     * keine Zugangsdaten, keinen Anbieter und keine ausgehende Verbindung.
     * Es ist einsatzbereit, sobald es aktiviert ist - das ist der Punkt des
     * selbst gehosteten Verfahrens.
     *
     * @param array<string, string> $providers
     * @return array<string, string>
     */
    public function anbieterMelden(array $providers): array {
        $providers[self::ANBIETER] = 'ALTCHA-Rechennachweis (selbst gehostet, keine Übermittlung an Dritte)';
        return $providers;
    }

    /**
     * Liefert das Formularfragment - nur für den eigenen Slug.
     *
     * Alle drei captcha.*-Filter laufen für JEDEN Anbieter; ein Callback, der
     * `$anbieter` ignoriert, würde auch dann antworten, wenn der Betreiber
     * etwas anderes gewählt hat.
     */
    public function widgetRendern(string $html, string $anbieter, string $kontext): string {
        if ($anbieter !== self::ANBIETER) {
            return $html;
        }

        $aufgabe = Aufgabe::stellen(Konfiguration::obergrenze());
        if ($aufgabe === null) {
            // Ohne aktive Sitzung lässt sich die Aufgabe nicht hinterlegen -
            // also stellen wir auch keine. Kein Fragment zurückgeben heisst:
            // der Kern rendert seine eigene Aufgabe (Captcha::renderField()).
            return $html;
        }

        // Der Rückfall wird MIT dem Nachweis zusammen ausgegeben und nicht
        // erst nachträglich: Captcha::issue() muss beim Rendern laufen, sonst
        // liegt beim Absenden keine Aufgabe in der Sitzung.
        $rueckfallFrage = Konfiguration::rueckfallAktiv() ? Captcha::issue() : null;

        return Widget::fragment($aufgabe, $kontext, $rueckfallFrage);
    }

    /**
     * Serverseitige Prüfung - nur für den eigenen Slug.
     *
     * DIE WICHTIGSTE ZEILE DIESES ADDONS ist das `return $urteil` beim fremden
     * Anbieter. Die Hook-Doku sagt "gibt null zurück, wenn es nicht zuständig
     * ist"; ein hartes `return null` würde aber das Urteil eines ANDEREN
     * Anbieter-Addons überschreiben, das vor uns in derselben Filterkette
     * geantwortet hat - und damit alle anderen aussperren. Den eingehenden
     * Wert durchzureichen ist dasselbe Verhalten (der Startwert IST null) und
     * zusätzlich verträglich mit mehreren installierten Anbietern.
     *
     * @param array<string, mixed> $input
     */
    public function antwortPruefen(?string $urteil, string $anbieter, string $kontext, array $input): ?string {
        if ($anbieter !== self::ANBIETER) {
            return $urteil;
        }

        // IMMER abholen, auch wenn gar keine Antwort kam: abholen() entfernt
        // die Aufgabe aus der Sitzung, und genau das ist die
        // Einmalverwendung. Bliebe sie liegen, taugte ein einmal gelöster
        // Nachweis für eine Serie von Absendungen.
        $aufgabe = Aufgabe::abholen();

        $roh = is_string($input[Widget::FELD] ?? null) ? trim($input[Widget::FELD]) : '';

        if ($roh === '') {
            if (!Konfiguration::rueckfallAktiv()) {
                // Kein Nachweis, kein Rückfall: nicht bestanden. Bewusst ein
                // Urteil und kein `null` - wir sind zuständig und wissen, dass
                // niemand geantwortet hat.
                return Captcha::WRONG;
            }

            // Der Rückfall ist die Rechenaufgabe des Kerns, die wir beim
            // Rendern mit ausgegeben haben. Sie prüft der Kern selbst - und
            // verbraucht dabei ebenfalls ihre Aufgabe (Einmalverwendung).
            return Captcha::verifyBuiltin(is_string($input['captcha'] ?? null) ? $input['captcha'] : null);
        }

        return Aufgabe::pruefen($aufgabe, $roh);
    }

    /**
     * Kachel im Admin-Dashboard - nur für Benutzer, die dieses Addon auch
     * verwalten dürfen. Fail-closed: Wer das Recht nicht hat, sieht den
     * Einstieg gar nicht erst, statt hinter der Kachel auf eine 403-Seite zu
     * laufen.
     *
     * Gemessen (Stand Kern 0.8.0-beta.1): Der Riegel greift heute nie, weil
     * `src/Views/admin_dashboard.php` den ganzen Systembereich samt
     * `$pluginTiles` in `if ($isAdmin)` einschliesst - ein Nicht-Admin sieht
     * ohnehin keine Kachel. Er bleibt trotzdem stehen, denn die Aussage
     * "diese Kachel gehört zu einem Recht" gehört zum Addon und nicht zu
     * einer Bedingung in einer fremden View, die sich ändern kann. Der
     * zugehörige Testfall verzichtet bewusst auf eine Zusicherung dazu:
     * Sie wäre immer erfüllt und hielte deshalb nichts fest.
     *
     * @param array<int, array{url:string, label:string, icon:string}> $tiles
     * @return array<int, array{url:string, label:string, icon:string}>
     */
    public function dashboardKachel(array $tiles): array {
        if (!Zugriff::darfVerwalten()) {
            return $tiles;
        }

        $tiles[] = [
            'url' => '/plugin/' . self::SLUG . '/verwaltung',
            'label' => 'ALTCHA-Einstellungen',
            'icon' => '🔒',
        ];
        return $tiles;
    }

    /**
     * Deinstallation (Framework#338). Das Register `owns` in der plugin.json
     * räumt die beiden eigenen `plugin_`-Einstellungen weg - deklarativ, damit
     * der Betreiber VOR dem Löschen sieht, was verschwindet.
     *
     * Hier bleibt genau das, was sich nicht deklarieren lässt: unsere eigenen
     * Zeilen in einer KERN-Tabelle. Steht dieses Addon in `captcha_provider`
     * oder in einem der formularbezogenen `captcha_provider_<kontext>`
     * (Framework#351), zeigt die Einstellung nach der Deinstallation auf einen
     * Anbieter, den es nicht mehr gibt.
     */
    public function uninstall(): void {
        try {
            $stmt = Database::getInstance()->prepare(
                "DELETE FROM settings WHERE setting_value = ? AND (setting_key = 'captcha_provider' OR setting_key LIKE 'captcha\\_provider\\_%')"
            );
            $stmt->execute([self::ANBIETER]);
            $betroffen = $stmt->rowCount();
        } catch (\Throwable $e) {
            PluginAudit::log(self::SLUG, 'Aufräumen bei Deinstallation fehlgeschlagen', 'Einstellung captcha_provider', $e->getMessage());
            return;
        }

        if ($betroffen > 0) {
            PluginAudit::log(
                self::SLUG,
                'Anbieterwahl zurückgesetzt',
                'Systemeinstellungen',
                $betroffen . ' Formular(e) standen auf diesem Anbieter und nutzen wieder den eingebauten Schutz.'
            );
        }
    }
}

/**
 * Wer darf dieses Addon verwalten? Eine einzige Antwort für die Kachel (die
 * nur prüft) und den Controller (der erzwingt), damit beide nicht
 * auseinanderlaufen können.
 *
 * Bewusst über App\Permission\GroupMembership statt über BaseController:
 * Der Hook `admin.dashboard_tiles` läuft ohne Controller-Instanz, und
 * hasPermission() ist dort `protected`.
 */
final class Zugriff {

    private function __construct() {}

    public static function darfVerwalten(): bool {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        if ($userId === null) {
            return false;
        }

        if (GroupMembership::isAdmin($userId)) {
            return true;
        }

        return GroupMembership::groupsHavePermission(
            GroupMembership::groupIds($userId),
            Plugin::MODUL,
            'manage'
        );
    }
}

/**
 * Die zwei Einstellungen dieses Addons.
 *
 * Sie liegen in der Kern-Tabelle `settings` unter Namen mit dem Pflichtpräfix
 * `plugin_` (Framework#338) und stehen deshalb im Register `owns` der
 * plugin.json - der Betreiber sieht beim Deinstallieren, was verschwindet.
 *
 * Ein Geheimnis gibt es hier NICHT, und das ist keine Auslassung: Es gibt
 * keinen Anbieter, gegen den man sich ausweisen müsste, und die Aufgabe
 * liegt in der Sitzung statt signiert im Formular (siehe Dateikopf). Damit
 * gibt es auch nichts, das je in einer Antwort auftauchen könnte.
 */
final class Konfiguration {

    public const SCHLUESSEL_AUFWAND = 'plugin_captcha_altcha_aufwand';
    public const SCHLUESSEL_RUECKFALL = 'plugin_captcha_altcha_fallback';

    /**
     * Wie viele Prüfsummen ein Browser im ungünstigsten Fall rechnen muss.
     * Im Mittel ist es die Hälfte davon.
     *
     * "mittel" ist der Standard: rund 50.000 SHA-256-Berechnungen im Mittel,
     * auf gängiger Hardware deutlich unter einer Sekunde. "hoch" ist für
     * Instanzen gedacht, die tatsächlich unter Beschuss stehen - es kostet
     * jeden ehrlichen Besucher spürbar Zeit, und alte Mobilgeräte kostet es
     * mehr als neue. Wer die Stufe erhöht, sollte wissen, dass die Rechnung
     * auf dem Gerät des Besuchers stattfindet, nicht auf dem Server.
     */
    public const STUFEN = [
        'niedrig' => 20000,
        'mittel' => 100000,
        'hoch' => 400000,
    ];

    public const STUFE_STANDARD = 'mittel';

    /** @var array<string, string>|null Request-Cache */
    private static ?array $cache = null;

    private function __construct() {}

    /**
     * Genau die eigenen Schlüssel, nicht die ganze Tabelle: `captcha.render`
     * läuft auf jedem geschützten Formular.
     *
     * @return array<string, string>
     */
    private static function alle(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, ?)'
            );
            $stmt->execute([self::SCHLUESSEL_AUFWAND, self::SCHLUESSEL_RUECKFALL]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            self::$cache = is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            // Ohne Einstellungen gelten die Standardwerte. Das ist hier
            // gefahrlos: Der Standard ist eine gestellte Aufgabe, nicht
            // "keine Aufgabe".
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function stufe(): string {
        $wert = trim((string) (self::alle()[self::SCHLUESSEL_AUFWAND] ?? ''));
        return isset(self::STUFEN[$wert]) ? $wert : self::STUFE_STANDARD;
    }

    public static function obergrenze(): int {
        return self::STUFEN[self::stufe()];
    }

    /**
     * Standard ist AN. Begründung im Dateikopf: Ein abgeschalteter Rückfall
     * sperrt Besucher ohne JavaScript aus, und auf dem DSGVO-Portal wären das
     * Betroffene, die ihre Rechte geltend machen wollen. Wer den stärkeren
     * Schutz braucht, schaltet ihn bewusst ab - nicht umgekehrt.
     */
    public static function rueckfallAktiv(): bool {
        $wert = trim((string) (self::alle()[self::SCHLUESSEL_RUECKFALL] ?? ''));
        return $wert === '' ? true : $wert === '1';
    }

    public static function setzen(string $schluessel, string $wert): void {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?'
        );
        $stmt->execute([$schluessel, $wert, $wert]);
        self::$cache = null;
    }
}

/**
 * Die Aufgabe: stellen, abholen, prüfen.
 *
 * Der Zustand liegt in der Sitzung des Besuchers - warum, steht im
 * Dateikopf. Aus dieser Wahl folgt die Einmalverwendung: abholen() entfernt
 * die Aufgabe IMMER, auch bei Erfolg und auch, wenn gar keine Antwort kam.
 */
final class Aufgabe {

    /** Schlüssel der laufenden Aufgabe in der Sitzung - mit Addon-Präfix, damit er niemandem in die Quere kommt. */
    private const SESSION_KEY = 'plugin_captcha_altcha_challenge';

    /** Der einzige unterstützte Algorithmus; das Feld steht im Nachweis, damit er dem ALTCHA-Format entspricht. */
    public const ALGORITHMUS = 'SHA-256';

    private function __construct() {}

    /**
     * Stellt eine neue Aufgabe und liefert das, was der Browser davon sehen
     * darf: Salt, Prüfsumme und Obergrenze - NICHT die Zahl.
     *
     * @return array{algorithm:string, challenge:string, salt:string, maxnumber:int}|null
     */
    public static function stellen(int $obergrenze): ?array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $salt = bin2hex(random_bytes(12));
        $zahl = random_int(0, $obergrenze);
        $pruefsumme = hash('sha256', $salt . $zahl);

        $_SESSION[self::SESSION_KEY] = [
            'salt' => $salt,
            'zahl' => $zahl,
            'challenge' => $pruefsumme,
            'max' => $obergrenze,
            'gestellt' => time(),
        ];

        return [
            'algorithm' => self::ALGORITHMUS,
            'challenge' => $pruefsumme,
            'salt' => $salt,
            'maxnumber' => $obergrenze,
        ];
    }

    /**
     * Holt die laufende Aufgabe und VERBRAUCHT sie dabei.
     *
     * @return array<string, mixed>|null
     */
    public static function abholen(): ?array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $aufgabe = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        return is_array($aufgabe) ? $aufgabe : null;
    }

    /**
     * Prüft den eingereichten Nachweis.
     *
     * KEINE MINDEST-AUSFÜLLZEIT. Der eingebaute Schutz des Kerns weist
     * Formulare ab, die schneller als drei Sekunden zurückkommen - dort muss
     * ein Mensch eine Aufgabe lesen und tippen, unter drei Sekunden war es
     * keiner. Hier tippt niemand: Der Browser rechnet, und wie lange er
     * braucht, hängt am Gerät. Eine Mindestzeit würde schnelle Rechner
     * bestrafen, ohne einen Angreifer aufzuhalten - dessen Kosten stehen im
     * Rechennachweis selbst.
     *
     * @param array<string, mixed>|null $aufgabe
     */
    public static function pruefen(?array $aufgabe, string $roh): string {
        if ($aufgabe === null || !isset($aufgabe['challenge'], $aufgabe['salt'], $aufgabe['max'], $aufgabe['gestellt'])) {
            return Captcha::EXPIRED;
        }

        // Dieselbe Gültigkeitsdauer wie beim eingebauten Schutz - eine
        // eigene Zahl wäre eine zweite Wahrheit über dasselbe Formular.
        if ((time() - (int) $aufgabe['gestellt']) > Captcha::TTL_SECONDS) {
            return Captcha::EXPIRED;
        }

        $json = base64_decode($roh, true);
        if (!is_string($json) || $json === '') {
            return Captcha::WRONG;
        }

        $daten = json_decode($json, true);
        if (!is_array($daten)) {
            return Captcha::WRONG;
        }

        // Salt und Prüfsumme müssen die aus der Sitzung sein. Der Vergleich
        // läuft über hash_equals, obwohl beide Werte öffentlich sind - es
        // kostet nichts und niemand muss sich später fragen, ob hier eine
        // Zeitmessung etwas verrät.
        if (!hash_equals((string) $aufgabe['challenge'], (string) ($daten['challenge'] ?? ''))) {
            return Captcha::WRONG;
        }
        if (!hash_equals((string) $aufgabe['salt'], (string) ($daten['salt'] ?? ''))) {
            return Captcha::WRONG;
        }

        $zahl = filter_var($daten['number'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($zahl) || $zahl < 0 || $zahl > (int) $aufgabe['max']) {
            return Captcha::WRONG;
        }

        // Der eigentliche Nachweis. Nachgerechnet wird die Prüfsumme, nicht
        // bloss die Zahl mit der gemerkten verglichen: So steht die Prüfung
        // auch dann noch richtig da, wenn die Aufgabe eines Tages signiert
        // statt in der Sitzung geführt wird.
        if (!hash_equals((string) $aufgabe['challenge'], hash('sha256', (string) $aufgabe['salt'] . $zahl))) {
            return Captcha::WRONG;
        }

        return Captcha::OK;
    }
}

/**
 * Das Formularfragment samt Rechenskript.
 *
 * Es wird vom Kern UNESCAPED in ein bestehendes Formular eingesetzt - alles
 * Dynamische escapen wir deshalb selbst. Dynamisch sind hier die Aufgabe
 * (als JSON in einem data-Attribut) und die Element-Kennung.
 *
 * WARUM DAS SKRIPT INLINE STEHT UND NICHT ALS EIGENE ROUTE AUSGELIEFERT WIRD:
 * Die Content-Security-Policy des Kerns erlaubt `script-src 'self'
 * 'unsafe-inline'`. Inline kostet also keine Lockerung, eine eigene Route
 * auch nicht - aber sie kostet einen zusätzlichen Request auf jedem
 * geschützten Formular und eine Datei, die mit dem Fragment
 * auseinanderlaufen kann. Bei rund sechzig Zeilen ist das kein guter Tausch.
 */
final class Widget {

    /** Feldname, unter dem der Nachweis im Formular landet. */
    public const FELD = 'altcha_payload';

    private function __construct() {}

    /**
     * @param array{algorithm:string, challenge:string, salt:string, maxnumber:int} $aufgabe
     * @param string|null $rueckfallFrage Aufgabentext des Kerns, oder null wenn der Rückfall abgeschaltet ist
     */
    public static function fragment(array $aufgabe, string $kontext, ?string $rueckfallFrage): string {
        $id = 'hv-altcha-' . preg_replace('/[^a-z0-9_-]/', '', strtolower($kontext));
        $idAttr = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $idJs = json_encode($id, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $aufgabeAttr = htmlspecialchars(
            json_encode($aufgabe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        );

        $html = '<div class="form-group">'
            . '<label>Sicherheitsprüfung</label>'
            . '<div id="' . $idAttr . '" data-hv-altcha="' . $aufgabeAttr . '"'
            . ' style="background: var(--surface-muted); border-radius: var(--border-radius, 4px); padding: 0.6rem;">'
            . '<span id="' . $idAttr . '-status">Sicherheitsprüfung wird vorbereitet …</span>'
            . '</div>'
            . '<input type="hidden" id="' . $idAttr . '-feld" name="' . self::FELD . '" value="">';

        if ($rueckfallFrage !== null) {
            // Der Rückfall steht in <noscript>: Wer JavaScript hat, sieht ihn
            // nicht und wird nicht verwirrt; wer keines hat, bekommt die
            // Rechenaufgabe des Kerns. Der Feldname ist der des Kerns
            // ("captcha"), weil Captcha::verifyBuiltin() genau den liest.
            $html .= '<noscript>'
                . '<p style="margin-top:0.6rem;">Ihr Browser führt kein JavaScript aus. '
                . 'Bitte lösen Sie stattdessen diese Aufgabe:</p>'
                . '<div style="margin-bottom: 0.5rem; font-size: 1.1rem;"><strong>'
                . htmlspecialchars($rueckfallFrage, ENT_QUOTES, 'UTF-8') . '</strong> =</div>'
                . '<input type="text" name="captcha" class="form-control" inputmode="numeric"'
                . ' autocomplete="off" maxlength="2" style="max-width: 8rem;">'
                . '</noscript>';
        }

        $html .= '<small class="form-hint">'
            . 'Diese Prüfung läuft vollständig in Ihrem Browser und auf diesem Server. '
            . 'Es werden <strong>keine Daten an Dritte übermittelt</strong> und keine Cookies zusätzlich gesetzt.'
            . '</small>';

        $html .= '<script>' . self::skript($idJs) . '</script>';

        return $html . '</div>';
    }

    /**
     * Das Rechenskript. Bewusst ohne Pfeilfunktionen und ohne optionale
     * Verkettung geschrieben - es läuft auf den Geräten der Besucher, und
     * ein Syntaxfehler auf einem älteren Browser hiesse dort: kein Nachweis,
     * kein Absenden.
     *
     * `await` in der Schleife ist Absicht: SubtleCrypto liefert
     * Versprechungen. Alle 500 Runden wird zusätzlich über setTimeout an den
     * Browser zurückgegeben, sonst käme er zwischen zwei Prüfsummen nie zum
     * Zeichnen und die Seite wirkte eingefroren.
     *
     * Der Absende-Knopf ist gesperrt, solange gerechnet wird. Ohne das
     * schickt ein schneller Besucher das Formular ab, bevor der Nachweis im
     * Feld steht - und bekommt "nicht bestanden", ohne etwas falsch gemacht
     * zu haben.
     */
    private static function skript(string $idJs): string {
        return <<<JS
(function () {
    var id = {$idJs};
    var wurzel = document.getElementById(id);
    var feld = document.getElementById(id + '-feld');
    var status = document.getElementById(id + '-status');
    if (!wurzel || !feld || !status) { return; }

    var aufgabe;
    try { aufgabe = JSON.parse(wurzel.getAttribute('data-hv-altcha')); } catch (e) { return; }
    if (!aufgabe || !aufgabe.salt || !aufgabe.challenge) { return; }

    if (!window.crypto || !window.crypto.subtle || !window.TextEncoder) {
        status.textContent = 'Dieser Browser kann die automatische Prüfung nicht ausführen.';
        return;
    }

    var form = wurzel.form || (wurzel.closest ? wurzel.closest('form') : null);
    var knoepfe = form ? form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])') : [];
    var i;
    for (i = 0; i < knoepfe.length; i++) { knoepfe[i].disabled = true; }
    function freigeben() {
        var j;
        for (j = 0; j < knoepfe.length; j++) { knoepfe[j].disabled = false; }
    }

    var enc = new TextEncoder();
    function hex(puffer) {
        var bytes = new Uint8Array(puffer), s = '', k;
        for (k = 0; k < bytes.length; k++) { s += ('0' + bytes[k].toString(16)).slice(-2); }
        return s;
    }

    status.textContent = 'Sicherheitsprüfung läuft …';

    (async function () {
        var n;
        for (n = 0; n <= aufgabe.maxnumber; n++) {
            var summe = hex(await crypto.subtle.digest('SHA-256', enc.encode(aufgabe.salt + n)));
            if (summe === aufgabe.challenge) {
                feld.value = btoa(JSON.stringify({
                    algorithm: aufgabe.algorithm,
                    challenge: aufgabe.challenge,
                    number: n,
                    salt: aufgabe.salt
                }));
                status.textContent = 'Sicherheitsprüfung bestanden.';
                freigeben();
                return;
            }
            if ((n % 500) === 0) {
                status.textContent = 'Sicherheitsprüfung läuft … ' + Math.round((n / aufgabe.maxnumber) * 100) + ' %';
                await new Promise(function (weiter) { setTimeout(weiter, 0); });
            }
        }
        status.textContent = 'Die Sicherheitsprüfung konnte nicht abgeschlossen werden. Bitte laden Sie die Seite neu.';
        freigeben();
    })().catch(function () {
        status.textContent = 'Die Sicherheitsprüfung konnte nicht abgeschlossen werden. Bitte laden Sie die Seite neu.';
        freigeben();
    });
})();
JS;
    }
}

/**
 * Die Verwaltungsseite: Aufwand und Rückfall einstellen, und die
 * Einschränkung aus dem Dateikopf da hinschreiben, wo der Betreiber sie
 * liest.
 */
class VerwaltungController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission(Plugin::MODUL, 'manage');
    }

    public function index(): void {
        $csrf = htmlspecialchars(Router::generateCsrfToken(), ENT_QUOTES, 'UTF-8');

        $inhalt = self::meldung();
        $inhalt .= $this->einordnungsKarte();
        $inhalt .= $this->einstellungsKarte($csrf);

        PluginPage::render('ALTCHA (selbst gehostet)', $inhalt);
    }

    public function speichern(): void {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $stufe = is_string($_POST['aufwand'] ?? null) ? trim($_POST['aufwand']) : '';
        if (!isset(Konfiguration::STUFEN[$stufe])) {
            // Nur bekannte Stufen werden übernommen - eine unbekannte Angabe
            // aus einem manipulierten Formular würde sonst als Obergrenze
            // durchschlagen.
            $this->zurueck('stufe-ungueltig');
        }

        $rueckfall = ($_POST['rueckfall'] ?? '') === '1' ? '1' : '0';

        Konfiguration::setzen(Konfiguration::SCHLUESSEL_AUFWAND, $stufe);
        Konfiguration::setzen(Konfiguration::SCHLUESSEL_RUECKFALL, $rueckfall);

        PluginAudit::log(
            Plugin::SLUG,
            'Einstellungen geändert',
            'Spam-Schutz ALTCHA',
            'Aufwand: ' . $stufe . ', Rückfall ohne JavaScript: ' . ($rueckfall === '1' ? 'an' : 'aus')
        );

        $this->zurueck('gespeichert');
    }

    private function einordnungsKarte(): string {
        return '<div class="card">'
            . '<h1>🔒 ALTCHA-Rechennachweis (selbst gehostet)</h1>'
            . '<p style="color: var(--text-muted);">Der Browser des Besuchers löst eine kleine Rechenaufgabe, '
            . 'deren Ergebnis dieser Server nachrechnet. Es gibt keinen Anbieter, keine Schlüssel und keine '
            . 'ausgehende Verbindung.</p>'
            . '<div style="background: var(--surface-muted); border-left: 4px solid var(--success-fg, #3c763d); '
            . 'padding: 0.9rem; border-radius: var(--border-radius, 4px); margin-top: 1rem;">'
            . '<p style="margin: 0 0 0.5rem 0;"><strong>Datenschutz</strong></p>'
            . '<ul style="margin: 0 0 0.5rem 1.2rem;">'
            . '<li>Es werden <strong>keine Daten an Dritte übermittelt</strong> - weder IP-Adresse noch '
            . 'Browser-Angaben verlassen diese Installation.</li>'
            . '<li>Keine Einwilligung nötig, kein zusätzlicher Empfänger in der Datenschutzerklärung, kein '
            . 'Drittlandbezug.</li>'
            . '<li>Keine Lockerung der Content-Security-Policy nötig - anders als bei '
            . '<code>captcha-turnstile</code> und <code>captcha-hcaptcha</code> gibt es hier nichts '
            . 'freizuschalten.</li>'
            . '<li>Damit ist dies die einzige der drei Erweiterungen, die auch für das <strong>DSGVO-Portal</strong> '
            . 'in Frage kommt.</li>'
            . '</ul>'
            . '</div>'
            . '<div style="background: var(--surface-muted); border-left: 4px solid var(--warning-fg, #8a6d3b); '
            . 'padding: 0.9rem; border-radius: var(--border-radius, 4px); margin-top: 1rem;">'
            . '<p style="margin: 0 0 0.5rem 0;"><strong>Die Einschränkung, die Sie kennen müssen</strong></p>'
            . '<p style="margin: 0;">Der Rechennachweis läuft im Browser. Ohne JavaScript - oder auf einer '
            . 'unverschlüsselt ausgelieferten Seite, wo <code>crypto.subtle</code> nicht zur Verfügung steht - '
            . 'kommt kein Nachweis zustande. Der <em>Rückfall</em> unten löst das: Ist er an, steht für diese '
            . 'Besucher die eingebaute Rechenaufgabe des Kerns im Formular. Der Schutz ist dann so stark wie '
            . 'die schwächere der beiden Hürden, also so stark wie der eingebaute Schutz - nicht stärker. '
            . 'Schalten Sie ihn nur ab, wenn Sie bewusst in Kauf nehmen, Besucher ohne JavaScript '
            . 'auszusperren.</p>'
            . '</div>'
            . '</div>';
    }

    private function einstellungsKarte(string $csrf): string {
        $stufe = Konfiguration::stufe();
        $rueckfall = Konfiguration::rueckfallAktiv();

        $html = '<div class="card" style="margin-top: 1.5rem;">';
        $html .= '<h2>Einstellungen</h2>';
        $html .= '<form method="POST" action="/plugin/' . Plugin::SLUG . '/verwaltung/speichern">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrf . '">';

        $html .= '<div class="form-group"><label for="ca-aufwand">Rechenaufwand</label>'
            . '<select class="form-control" id="ca-aufwand" name="aufwand">';
        $beschriftung = [
            'niedrig' => 'niedrig - unauffällig auch auf alten Geräten',
            'mittel' => 'mittel - Standard, meist deutlich unter einer Sekunde',
            'hoch' => 'hoch - für Installationen unter tatsächlichem Beschuss',
        ];
        foreach (Konfiguration::STUFEN as $name => $obergrenze) {
            $html .= '<option value="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"'
                . ($name === $stufe ? ' selected' : '') . '>'
                . htmlspecialchars($beschriftung[$name] ?? $name, ENT_QUOTES, 'UTF-8')
                . ' (bis ' . number_format($obergrenze, 0, ',', '.') . ' Prüfsummen)'
                . '</option>';
        }
        $html .= '</select>'
            . '<span class="form-hint">Gerechnet wird auf dem Gerät des Besuchers, nicht auf dem Server. '
            . 'Im Mittel ist es die Hälfte der genannten Zahl.</span></div>';

        $html .= '<div class="form-group"><label>'
            . '<input type="checkbox" name="rueckfall" value="1"' . ($rueckfall ? ' checked' : '') . '> '
            . 'Rückfall ohne JavaScript (empfohlen)</label>'
            . '<span class="form-hint">Zeigt Besuchern ohne JavaScript die eingebaute Rechenaufgabe des Kerns. '
            . 'Siehe den Kasten oben - das senkt den Schutz auf das Niveau des eingebauten Schutzes, hält das '
            . 'Formular aber für alle erreichbar.</span></div>';

        $html .= '<button type="submit" class="btn">Einstellungen speichern</button>';
        $html .= '</form></div>';

        return $html;
    }

    /** Rückmeldungen aus dem Query-Parameter - feste Texte, keine Fremdeingabe. */
    private static function meldung(): string {
        $texte = [
            'gespeichert' => ['Einstellungen gespeichert.', 'success'],
            'stufe-ungueltig' => ['Unbekannte Aufwandstufe - es wurde nichts geändert.', 'danger'],
        ];

        $status = is_string($_GET['ca'] ?? null) ? $_GET['ca'] : '';
        if (!isset($texte[$status])) {
            return '';
        }

        [$text, $art] = $texte[$status];
        // Dieselbe Bauart wie in den uebrigen Addons: Der Kern bringt keine
        // .alert-Klasse mit, die Farben kommen aus den Theme-Variablen.
        return '<div class="card" style="color:var(--' . $art . '-fg);background:var(--' . $art . '-soft-bg);">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    private function zurueck(string $status): never {
        header('Location: /plugin/' . Plugin::SLUG . '/verwaltung?ca=' . $status);
        exit;
    }
}
