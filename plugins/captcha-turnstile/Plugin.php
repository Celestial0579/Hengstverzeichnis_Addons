<?php
// captcha-turnstile/Plugin.php
//
// Addon für Hengstverzeichnis_Framework, Teil von
// Celestial0579/Hengstverzeichnis_Addons#133: Cloudflare Turnstile als
// wählbarer Anbieter für die drei captcha.*-Hooks des Kerns.
//
// WARUM DER KERN DAS NICHT SELBST TUT - und warum dieses Addon es dem
// Betreiber trotzdem sagen muss.
//
// App\Security\Captcha begründet ausführlich, weshalb der Kern bewusst KEIN
// Drittanbieter-CAPTCHA mitbringt: Sein einziger eingebauter Einsatzort war
// das DSGVO-Portal, also genau die Stelle, an der Betroffene ihre Rechte aus
// Art. 15/17 DSGVO geltend machen. Ausgerechnet dort die IP-Adresse und einen
// Browser-Fingerabdruck an einen weiteren Empfänger in einem Drittland zu
// senden, wäre kaum zu rechtfertigen.
//
// Dieses Addon macht es technisch möglich - es hebt die Begründung nicht auf.
// Daraus folgen drei Dinge, die hier fest eingebaut sind und nicht als
// Beiwerk gelten:
//
//  1. In der Anbieterauswahl steht nicht "Turnstile", sondern
//     "Cloudflare Turnstile (Drittanbieter: Cloudflare, Inc., USA)". Wer
//     wählt, soll beim Wählen sehen, was er wählt - nicht erst in der README.
//  2. Die Verwaltungsseite dieses Addons nennt oben, welche Daten wohin
//     gehen, welche Rechtsgrundlage der Betreiber braucht und was in seine
//     Datenschutzerklärung muss.
//  3. Unter dem Widget selbst steht ein Hinweis für den BESUCHER, samt Link
//     auf die Datenschutzerklärung von Cloudflare. Ein Drittdienst, der
//     unbeschriftet in einem Formular sitzt, ist der Normalfall im Web und
//     genau deshalb hier nicht der Normalfall.
//
// EMPFEHLUNG, DIE IN DER SOFTWARE STEHT UND NICHT NUR IN DER DOKUMENTATION:
// Für den Kontext `dsgvo` bleibt die eingebaute Rechenaufgabe die richtige
// Wahl. Die Verwaltungsseite sagt das, wenn dieses Addon dort aktiv ist.
// Seit Framework#351 lässt sich der Anbieter je Formular wählen; ein
// Betreiber kann Turnstile also für die Kontakt- und Deckanfragen nutzen und
// das DSGVO-Portal davon ausnehmen.
//
// DER AUFBAU IST FÜR ALLE DREI ANBIETER-ADDONS DERSELBE (Addons#133 nennt es
// "ein Muster, dreimal angewandt"): Plugin meldet den Slug, Widget liefert
// das Formularfragment, Anbieter prüft die Antwort serverseitig,
// Konfiguration hält Schlüssel und Geheimnis, VerwaltungController ist die
// Oberfläche dafür. Wer hier etwas ändert, sieht in captcha-hcaptcha und
// captcha-altcha nach, ob es dort genauso gilt.
//
// Installation (lokal im Framework-Repo):
//   cp -r captcha-turnstile plugins/captcha-turnstile
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren,
// unter Admin -> Turnstile-Schlüssel (Kachel im Dashboard) Site-Key und
// Secret hinterlegen und erst DANACH unter Admin -> Systemeinstellungen als
// Spam-Schutz auswählen. Vorher erscheint der Anbieter dort gar nicht.

namespace Plugin\CaptchaTurnstile;

use App\Controllers\BaseController;
use App\Controllers\SetupController;
use App\Database;
use App\Permission\GroupMembership;
use App\Plugin\HookManager;
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;
use App\Router;
use App\Security\Captcha;
use App\Security\Crypto;
use PDO;

class Plugin {

    /**
     * Der eigene Slug. Er ist zugleich der Anbietername in der Auswahl
     * (`captcha.providers`), das Berechtigungsmodul und die Protokoll-
     * Kategorie (Framework#352) - drei Stellen, an denen dieselbe Zeichenkette
     * sonst einzeln abgeschrieben stünde.
     */
    public const SLUG = 'captcha-turnstile';

    /** Der Slug, unter dem der Betreiber diesen Anbieter auswählt. */
    public const ANBIETER = self::SLUG;

    /** Berechtigungsmodul dieses Addons. */
    public const MODUL = self::SLUG;

    /**
     * Origins, die in der Content-Security-Policy freigegeben sein müssen
     * (Kern-Konstante CAPTCHA_DOMAINS, ausgewertet in
     * App\Security\ContentSecurityPolicy).
     *
     * Turnstile lädt sein Skript von challenges.cloudflare.com und rendert
     * die Aufgabe von dort in einem IFRAME - script-src, frame-src und
     * connect-src betreffen also alle dieselbe Herkunft. Fehlt sie, bleibt
     * das Widget LAUTLOS leer: keine Fehlermeldung im Formular, nur eine
     * Meldung in der Browser-Konsole, die niemand sieht. Genau daran
     * scheitern solche Addons erfahrungsgemäß, deshalb prüft die
     * Verwaltungsseite es und bietet die Freischaltung als Knopf an.
     */
    public const CSP_ORIGINS = ['https://challenges.cloudflare.com'];

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
            ['method' => 'POST', 'path' => '/verwaltung/csp', 'callback' => [VerwaltungController::class, 'cspFreischalten']],
        ];
    }

    /**
     * @return array<int, array{module:string, action:string, label:string, module_label:string}>
     */
    public function permissions(): array {
        return [[
            'module' => self::MODUL,
            'action' => 'manage',
            'label' => 'Schlüssel verwalten',
            'module_label' => 'Spam-Schutz: Cloudflare Turnstile',
        ]];
    }

    /**
     * Meldet den Anbieter zur Auswahl an - ABER NUR MIT SCHLÜSSELN.
     *
     * Ohne Site-Key und Secret verweigerte jede Prüfung zuverlässig ihren
     * Dienst. Ein Anbieter, der garantiert nichts durchlässt, gehört nicht in
     * eine Auswahlliste: Der Betreiber wählt ihn, bemerkt nichts, und erst
     * Besucher merken, dass das Formular unbenutzbar ist. Fail-closed heisst
     * hier "gar nicht erst anbieten", nicht "anbieten und dann scheitern".
     *
     * Der Anzeigename nennt den Empfänger. Siehe der Dateikopf: Die
     * Entscheidung fällt in diesem Aufklappmenü, also muss sie dort
     * informiert getroffen werden können.
     *
     * @param array<string, string> $providers
     * @return array<string, string>
     */
    public function anbieterMelden(array $providers): array {
        if (!Konfiguration::einsatzbereit()) {
            return $providers;
        }

        $providers[self::ANBIETER] = 'Cloudflare Turnstile (Drittanbieter: Cloudflare, Inc., USA)';
        return $providers;
    }

    /**
     * Liefert das Formularfragment - nur für den eigenen Slug.
     *
     * Alle drei captcha.*-Filter laufen für JEDEN Anbieter; ein Callback, der
     * `$anbieter` ignoriert, würde auch dann antworten, wenn der Betreiber
     * etwas anderes gewählt hat.
     *
     * Zurückgegeben wird im Zweifel der EINGEHENDE Wert, nicht ein leerer
     * String: Der Filter ist eine Kette, und was ein anderes Addon bereits
     * gebaut hat, gehört nicht von uns gelöscht.
     */
    public function widgetRendern(string $html, string $anbieter, string $kontext): string {
        if ($anbieter !== self::ANBIETER) {
            return $html;
        }

        $siteKey = Konfiguration::siteKey();
        if ($siteKey === '') {
            // Wir sind gewählt, können aber nichts rendern. Kein Fragment
            // zurückgeben heisst: der Kern rendert seine eigene Aufgabe
            // (siehe Captcha::renderField()). Das Formular bleibt benutzbar.
            return $html;
        }

        return Widget::fragment($siteKey, $kontext);
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
     * Der zweite Grundsatz aus App\Security\Captcha::verify(): Ein Urteil nur
     * dann, wenn wir wirklich eines haben. Können wir nicht prüfen (kein
     * Secret, Secret nicht entschlüsselbar), geben wir KEINES ab - dann prüft
     * der Kern mit seiner eigenen Aufgabe, statt dass hier versehentlich ein
     * OK entsteht.
     *
     * @param array<string, mixed> $input
     */
    public function antwortPruefen(?string $urteil, string $anbieter, string $kontext, array $input): ?string {
        if ($anbieter !== self::ANBIETER) {
            return $urteil;
        }

        $token = is_string($input[Widget::FELD] ?? null) ? trim($input[Widget::FELD]) : '';
        if ($token === '' || strlen($token) > self::TOKEN_MAXLAENGE) {
            // Niemand hat das Widget gelöst (oder jemand schickt Müll). Das
            // ist ein Urteil, das wir ohne Netzaufruf fällen können - und wir
            // fällen es, statt Cloudflare mit leeren Anfragen zu belasten.
            return Captcha::WRONG;
        }

        $secret = Konfiguration::secret();
        if ($secret === null) {
            // Zuständig, aber prüfunfähig: Schlüssel wurde zwischen Anzeige
            // und Absenden entfernt, oder APP_KEY hat sich geändert und das
            // gespeicherte Geheimnis lässt sich nicht mehr entschlüsseln.
            // Kein Urteil - der Kern übernimmt.
            Konfiguration::fehlerMerken('Kein entschlüsselbares Secret hinterlegt - es wurde nicht beim Anbieter geprüft.');
            PluginAudit::log(
                self::SLUG,
                'Prüfung nicht möglich',
                'Formular ' . $kontext,
                'Kein entschlüsselbares Secret hinterlegt - der eingebaute Schutz des Kerns hat übernommen.'
            );
            return $urteil;
        }

        return Anbieter::pruefen($secret, $token, $kontext);
    }

    /** Turnstile-Token sind rund 500 Zeichen lang; alles darüber ist kein Token. */
    private const TOKEN_MAXLAENGE = 4096;

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
            'label' => 'Turnstile-Schlüssel',
            'icon' => '🛡️',
        ];
        return $tiles;
    }

    /**
     * Deinstallation (Framework#338). Das Register `owns` in der plugin.json
     * räumt die drei eigenen `plugin_`-Einstellungen weg - deklarativ, damit
     * der Betreiber VOR dem Löschen sieht, was verschwindet.
     *
     * Hier bleibt genau das, was sich nicht deklarieren lässt: unsere eigenen
     * Zeilen in einer KERN-Tabelle. Steht dieses Addon in `captcha_provider`
     * oder in einem der formularbezogenen `captcha_provider_<kontext>`
     * (Framework#351), zeigt die Einstellung nach der Deinstallation auf einen
     * Anbieter, den es nicht mehr gibt. Captcha::activeProvider() fällt dann
     * zwar auf den eingebauten zurück - aber in der Datenbank stünde
     * dauerhaft ein toter Name, und im Aufklappmenü stünde nichts Ausgewähltes
     * mehr. Wir räumen ihn weg.
     */
    public function uninstall(): void {
        try {
            $stmt = Database::getInstance()->prepare(
                "DELETE FROM settings WHERE setting_value = ? AND (setting_key = 'captcha_provider' OR setting_key LIKE 'captcha\\_provider\\_%')"
            );
            $stmt->execute([self::ANBIETER]);
            $betroffen = $stmt->rowCount();
        } catch (\Throwable $e) {
            // Ein fehlgeschlagenes Aufräumen darf die Deinstallation nicht
            // abbrechen - der Kern fängt es ohnehin ab. Protokolliert wird es
            // trotzdem, sonst bliebe die tote Anbieterwahl unbemerkt stehen.
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
 * hasPermission() ist dort `protected`. GroupMembership ist genau der Ort,
 * an dem der Kern diese Prüfung für Aufrufer ohne Controller vorhält
 * (siehe BaseController::hasPermission(), das ebenfalls dorthin delegiert).
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
 * Schlüssel und Geheimnis dieses Addons.
 *
 * WO SIE LIEGEN: in der Kern-Tabelle `settings`, unter Namen mit dem
 * Pflichtpräfix `plugin_` (Framework#338). Bewusst keine eigene Tabelle - es
 * sind drei Werte, und das Register `owns` kann Einstellungen genauso
 * aufzählen und löschen wie Tabellen. Eine eigene Tabelle wäre eine
 * Migration, ein install()-Hook und ein Aufräumpfad mehr, für nichts.
 *
 * WIE DAS GEHEIMNIS LIEGT: verschlüsselt mit App\Security\Crypto
 * (AES-256-GCM, Schlüssel aus APP_KEY) - derselbe Weg, den der Kern für das
 * SMTP-Passwort und die TOTP-Secrets nimmt. Wer Lesezugriff auf die
 * Datenbank hat, hat damit noch nicht das Secret.
 *
 * OB ES JE IN EINER ANTWORT AUFTAUCHEN KANN: nein, und das ist hier
 * ausbuchstabiert, weil es die Frage ist, die man einem CAPTCHA-Addon
 * stellen muss.
 *   - secret() ist der EINZIGE Entschlüsseler und hat genau einen Aufrufer:
 *     Plugin::antwortPruefen(), das den Wert direkt an den ausgehenden
 *     HTTP-Aufruf reicht.
 *   - Die Verwaltungsseite gibt den Wert nie aus. Sie zeigt "hinterlegt" oder
 *     "nicht hinterlegt"; das Eingabefeld ist immer leer, ein leeres Feld
 *     heisst "unverändert lassen". Wer das Secret ersetzen will, tippt es neu -
 *     wer es sehen will, kann es hier nicht.
 *   - Ins Protokoll geht es nie: PluginAudit bekommt Fehlercodes des
 *     Anbieters, nie das Geheimnis und nie das Besucher-Token.
 *   - In `plugin_captcha_turnstile_letzter_fehler` steht nur ein Satz für den
 *     Betreiber, keine Anfragedaten.
 */
final class Konfiguration {

    public const SCHLUESSEL_SITE = 'plugin_captcha_turnstile_site_key';
    public const SCHLUESSEL_SECRET = 'plugin_captcha_turnstile_secret';
    public const SCHLUESSEL_FEHLER = 'plugin_captcha_turnstile_letzter_fehler';

    /** Site-Keys und Secrets der gängigen Anbieter sind kurze ASCII-Kennungen. */
    public const MUSTER_SITE = '/^[A-Za-z0-9_\-]{6,120}$/';
    public const MUSTER_SECRET = '/^[A-Za-z0-9_\-]{6,200}$/';

    /** @var array<string, string>|null Request-Cache */
    private static ?array $cache = null;

    private function __construct() {}

    /**
     * Genau die drei eigenen Schlüssel, nicht die ganze Tabelle: Der Filter
     * `captcha.providers` läuft bei JEDEM Aufbau der Anbieterliste und bei
     * jeder Prüfung.
     *
     * Ein Fehlschlag ist kein Grund, die Seite mitzureissen - ohne
     * Einstellungen gelten wir als nicht einsatzbereit, und das ist der
     * strengere Fall.
     *
     * @return array<string, string>
     */
    private static function alle(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, ?, ?)'
            );
            $stmt->execute([self::SCHLUESSEL_SITE, self::SCHLUESSEL_SECRET, self::SCHLUESSEL_FEHLER]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            self::$cache = is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function siteKey(): string {
        return trim((string) (self::alle()[self::SCHLUESSEL_SITE] ?? ''));
    }

    /** Ist ein Geheimnis hinterlegt? Beantwortet die Frage OHNE es zu entschlüsseln. */
    public static function secretGesetzt(): bool {
        return trim((string) (self::alle()[self::SCHLUESSEL_SECRET] ?? '')) !== '';
    }

    /**
     * Das entschlüsselte Geheimnis - nur für den ausgehenden Prüfaufruf.
     * Niemals in eine Antwort, ein Protokoll oder eine Ausgabe geben.
     */
    public static function secret(): ?string {
        $roh = trim((string) (self::alle()[self::SCHLUESSEL_SECRET] ?? ''));
        if ($roh === '') {
            return null;
        }

        try {
            $klar = Crypto::decrypt($roh);
        } catch (\Throwable $e) {
            // Crypto::getKey() wirft ohne APP_KEY (fail-closed, siehe dort).
            return null;
        }

        return is_string($klar) && $klar !== '' ? $klar : null;
    }

    /**
     * Erst mit Site-Key UND Secret ist der Anbieter benutzbar - siehe
     * Plugin::anbieterMelden().
     */
    public static function einsatzbereit(): bool {
        return self::siteKey() !== '' && self::secretGesetzt();
    }

    public static function letzterFehler(): string {
        return trim((string) (self::alle()[self::SCHLUESSEL_FEHLER] ?? ''));
    }

    public static function setzen(string $schluessel, string $wert): void {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?'
        );
        $stmt->execute([$schluessel, $wert, $wert]);
        self::$cache = null;
    }

    public static function loeschen(string $schluessel): void {
        $stmt = Database::getInstance()->prepare('DELETE FROM settings WHERE setting_key = ?');
        $stmt->execute([$schluessel]);
        self::$cache = null;
    }

    /**
     * Hinterlegt den letzten Betriebsfehler, damit die Verwaltungsseite ihn
     * zeigen kann.
     *
     * Der Grund ist Erfahrung mit genau dieser Sorte Addon: Ein falsches
     * Secret oder eine gesperrte ausgehende Verbindung äussert sich für den
     * Betreiber als "das Formular geht nicht mehr" - ohne Hinweis, wo er
     * suchen soll. Geschrieben wird nur bei Konfigurations- und Netzfehlern,
     * nicht bei jeder nicht bestandenen Prüfung; sonst wäre es ein
     * Schreibvorgang je Spam-Versuch.
     */
    public static function fehlerMerken(string $text): void {
        try {
            self::setzen(self::SCHLUESSEL_FEHLER, date('Y-m-d H:i') . ' - ' . $text);
        } catch (\Throwable $e) {
            // Diagnose-Komfort, kein Betriebszweck: Scheitert das Merken,
            // läuft die Prüfung trotzdem zu Ende.
        }
    }

    /**
     * Welche der nötigen Origins fehlen in der Content-Security-Policy?
     *
     * @return array<int, string>
     */
    public static function cspFehlend(): array {
        $erlaubt = defined('CAPTCHA_DOMAINS')
            ? array_filter(array_map('trim', explode(',', (string) constant('CAPTCHA_DOMAINS'))))
            : [];

        return array_values(array_diff(Plugin::CSP_ORIGINS, $erlaubt));
    }

    /** Wird CAPTCHA_DOMAINS per Umgebungsvariable gesetzt, hat die Vorrang vor db_config.php. */
    public static function cspAusUmgebung(): bool {
        return getenv('CAPTCHA_DOMAINS') !== false;
    }
}

/**
 * Das Formularfragment.
 *
 * Es wird vom Kern UNESCAPED in ein bestehendes Formular eingesetzt - alles
 * Dynamische escapen wir deshalb selbst. Dynamisch ist hier genau eines: der
 * Site-Key.
 *
 * Das Anbieterskript steht IM FRAGMENT und nicht im Layout. Das ist der
 * Unterschied zwischen "ein Drittskript auf den geschützten Formularen" und
 * "ein Drittskript auf jeder Seite des Verzeichnisses": renderField() läuft
 * nur dort, wo tatsächlich ein geschütztes Formular steht.
 */
final class Widget {

    /** Feldname, unter dem Turnstile seine Antwort in das Formular schreibt. */
    public const FELD = 'cf-turnstile-response';

    private const SKRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private function __construct() {}

    public static function fragment(string $siteKey, string $kontext): string {
        $id = 'hv-turnstile-' . preg_replace('/[^a-z0-9_-]/', '', strtolower($kontext));
        $idAttr = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');

        return '<div class="form-group">'
            . '<label>Sicherheitsprüfung</label>'
            . '<div id="' . $idAttr . '" class="cf-turnstile"'
            . ' data-sitekey="' . htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-theme="auto" data-language="auto"></div>'
            . '<script src="' . self::SKRIPT . '" async defer></script>'
            . '<noscript><p style="color: var(--warning-fg, #8a6d3b);">'
            . 'Diese Sicherheitsprüfung benötigt JavaScript. Bitte aktivieren Sie es für diese Seite '
            . 'oder wenden Sie sich direkt an den Betreiber.</p></noscript>'
            . '<small class="form-hint">'
            . 'Geschützt durch Cloudflare Turnstile. Dabei werden Ihre IP-Adresse und technische Angaben '
            . 'Ihres Browsers an Cloudflare, Inc. (USA) übermittelt - '
            . '<a href="https://www.cloudflare.com/privacypolicy/" target="_blank" rel="noopener noreferrer">Datenschutzerklärung von Cloudflare</a>.'
            . '</small>'
            . '</div>';
    }
}

/**
 * Der serverseitige Prüfaufruf gegen Cloudflare.
 *
 * FAIL-CLOSED BEI NETZFEHLERN. Antwortet Cloudflare nicht, ist das Ergebnis
 * "nicht bestanden" - nicht "durchgewunken". Der Kern fällt zwar auf seine
 * eigene Aufgabe zurück, wenn GAR KEIN Urteil kommt; darauf darf sich ein
 * Anbieter-Addon aber nicht verlassen, denn wir haben hier durchaus ein
 * Urteil: Die Antwort des Besuchers ist ungeprüft, also gilt sie nicht.
 *
 * KEIN `remoteip`. Cloudflare erlaubt, die IP des Besuchers mitzuschicken;
 * wir tun es nicht. Cloudflare sieht sie ohnehin, weil das Widget aus dem
 * Browser des Besuchers lädt - eine zweite, vom Server ausgehende
 * Übermittlung derselben Adresse bringt für die Erkennung praktisch nichts
 * und wäre eine weitere Verarbeitung, die der Betreiber begründen müsste.
 */
final class Anbieter {

    private const URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const VERBINDUNGS_TIMEOUT = 4;
    private const GESAMT_TIMEOUT = 8;

    /**
     * Fehlercodes, die eine ABGELAUFENE oder BEREITS BENUTZTE Antwort meinen.
     * Sie bekommen `EXPIRED` statt `WRONG`, weil der Kern dafür die Meldung
     * "die Aufgabe ist abgelaufen, bitte neu lösen" zeigt - und genau das ist
     * hier passiert. "Falsch beantwortet" wäre eine unwahre Auskunft an
     * jemanden, der alles richtig gemacht hat.
     */
    private const ABGELAUFEN = ['timeout-or-duplicate'];

    /**
     * Fehlercodes, die auf einen Fehler des BETREIBERS zeigen, nicht des
     * Besuchers. Sie werden protokolliert und auf der Verwaltungsseite
     * angezeigt, denn sie bedeuten: ab jetzt kommt niemand mehr durch dieses
     * Formular, und niemand erfährt warum.
     */
    private const KONFIGURATIONSFEHLER = [
        'invalid-input-secret',
        'missing-input-secret',
        'bad-request',
        'internal-error',
    ];

    private function __construct() {}

    public static function pruefen(string $secret, string $token, string $kontext): string {
        $antwort = self::abfragen($secret, $token);

        if ($antwort === null) {
            Konfiguration::fehlerMerken('Cloudflare war nicht erreichbar - Eingaben wurden abgewiesen (fail-closed).');
            PluginAudit::log(
                Plugin::SLUG,
                'Prüfung beim Anbieter fehlgeschlagen',
                'Formular ' . $kontext,
                'Cloudflare nicht erreichbar - die Eingabe wurde abgewiesen, nicht durchgelassen.'
            );
            return Captcha::WRONG;
        }

        if (($antwort['success'] ?? null) === true) {
            return Captcha::OK;
        }

        $codes = [];
        foreach ((array) ($antwort['error-codes'] ?? []) as $code) {
            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        if (array_intersect($codes, self::KONFIGURATIONSFEHLER) !== []) {
            $liste = implode(', ', array_intersect($codes, self::KONFIGURATIONSFEHLER));
            Konfiguration::fehlerMerken('Cloudflare meldet ' . $liste . ' - bitte Site-Key und Secret prüfen.');
            PluginAudit::log(
                Plugin::SLUG,
                'Anbieter meldet Konfigurationsfehler',
                'Formular ' . $kontext,
                'Fehlercodes: ' . $liste . ' - solange das gilt, besteht niemand diese Prüfung.'
            );
        }

        if (array_intersect($codes, self::ABGELAUFEN) !== []) {
            return Captcha::EXPIRED;
        }

        return Captcha::WRONG;
    }

    /**
     * Führt den POST aus und liefert die dekodierte Antwort - oder null, wenn
     * keine brauchbare Antwort zustande kam.
     *
     * curl, wenn vorhanden, sonst ein Stream-Kontext: Der Kern setzt keine
     * curl-Erweiterung voraus (siehe die Abhängigkeitsfreiheit in
     * docs/development.md), und ein Anbieter-Addon, das auf einer Instanz
     * ohne curl gar nicht prüfen kann, wäre eine unangenehme Überraschung.
     *
     * @return array<string, mixed>|null
     */
    private static function abfragen(string $secret, string $token): ?array {
        // KEIN remoteip - siehe Klassenkommentar.
        $daten = http_build_query(['secret' => $secret, 'response' => $token]);

        $roh = function_exists('curl_init')
            ? self::perCurl($daten)
            : self::perStream($daten);

        if ($roh === null || $roh === '') {
            return null;
        }

        $json = json_decode($roh, true);
        return is_array($json) ? $json : null;
    }

    private static function perCurl(string $daten): ?string {
        $ch = curl_init(self::URL);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $daten,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::VERBINDUNGS_TIMEOUT,
            CURLOPT_TIMEOUT => self::GESAMT_TIMEOUT,
            // Zertifikatsprüfung bleibt an. Sie abzuschalten wäre genau der
            // Fehler, der die ganze Prüfung wertlos macht: Wer die Antwort
            // fälschen kann, lässt sich beliebig durch.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $status !== 200) {
            return null;
        }

        return $body;
    }

    private static function perStream(string $daten): ?string {
        $kontext = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $daten,
            'timeout' => self::GESAMT_TIMEOUT,
            'ignore_errors' => true,
        ], 'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]]);

        $body = @file_get_contents(self::URL, false, $kontext);
        return is_string($body) && $body !== '' ? $body : null;
    }
}

/**
 * Die Verwaltungsseite: Schlüssel hinterlegen, Zustand sehen, CSP freischalten.
 *
 * Sie ist der Ort, an dem dieses Addon dem Betreiber SAGT, was er tut, wenn
 * er es einsetzt - siehe den Dateikopf. Der Datenschutz-Kasten steht deshalb
 * ganz oben und nicht am Ende.
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
        $inhalt .= $this->datenschutzKarte();
        $inhalt .= $this->schluesselKarte($csrf);
        $inhalt .= $this->cspKarte($csrf);

        PluginPage::render('Cloudflare Turnstile', $inhalt);
    }

    public function speichern(): void {
        $this->pruefeCsrf();

        $siteRoh = is_string($_POST['site_key'] ?? null) ? trim($_POST['site_key']) : '';
        $secretRoh = is_string($_POST['secret'] ?? null) ? trim($_POST['secret']) : '';
        $secretLoeschen = ($_POST['secret_loeschen'] ?? '') === '1';

        if ($siteRoh !== '' && preg_match(Konfiguration::MUSTER_SITE, $siteRoh) !== 1) {
            $this->zurueck('site-ungueltig');
        }
        if ($secretRoh !== '' && preg_match(Konfiguration::MUSTER_SECRET, $secretRoh) !== 1) {
            $this->zurueck('secret-ungueltig');
        }

        if ($siteRoh === '') {
            Konfiguration::loeschen(Konfiguration::SCHLUESSEL_SITE);
        } else {
            Konfiguration::setzen(Konfiguration::SCHLUESSEL_SITE, $siteRoh);
        }

        // Ein LEERES Secret-Feld heisst "unverändert lassen", nicht "löschen".
        // Anders ginge es nicht: Die Seite zeigt den gespeicherten Wert nicht
        // an (siehe Konfiguration), das Feld ist also bei jedem Aufruf leer.
        // Zum Entfernen gibt es das ausdrückliche Häkchen.
        if ($secretLoeschen) {
            Konfiguration::loeschen(Konfiguration::SCHLUESSEL_SECRET);
        } elseif ($secretRoh !== '') {
            try {
                Konfiguration::setzen(Konfiguration::SCHLUESSEL_SECRET, Crypto::encrypt($secretRoh));
            } catch (\Throwable $e) {
                // Crypto::getKey() ist fail-closed ohne APP_KEY. Ein
                // unverschlüsselt gespeichertes Secret wäre die schlechtere
                // Antwort darauf.
                $this->zurueck('kein-appkey');
            }
        }

        // Ein neuer Versuch verdient einen sauberen Zustand: Der alte
        // Fehlertext bezieht sich auf die alten Schlüssel.
        Konfiguration::loeschen(Konfiguration::SCHLUESSEL_FEHLER);

        // Protokolliert wird, DASS sich die Schlüssel geändert haben - nie
        // welche (Framework#352: keine Geheimnisse, keine personenbezogenen
        // Inhalte im dauerhaft gespeicherten Protokoll).
        PluginAudit::log(
            Plugin::SLUG,
            'Zugangsdaten geändert',
            'Spam-Schutz Cloudflare Turnstile',
            'Site-Key ' . ($siteRoh === '' ? 'entfernt' : 'gesetzt')
            . ', Secret ' . ($secretLoeschen ? 'entfernt' : ($secretRoh !== '' ? 'ersetzt' : 'unverändert'))
        );

        $this->zurueck('gespeichert');
    }

    /**
     * Trägt die nötigen Origins in `captcha_domains` (config/db_config.php)
     * nach - denselben Weg, den der Kern für die Tracking-Domains nimmt
     * (AdminController -> SetupController::writeDbConfigValue()).
     *
     * WARUM ALS KNOPF UND NICHT ALS ANLEITUNG: Weil eine fehlende
     * CSP-Freigabe das Widget LAUTLOS leer lässt. Der Betreiber sieht ein
     * Formular ohne Sicherheitsprüfung, der Besucher kommt nicht durch, und
     * niemand kommt auf die Content-Security-Policy. Eine Anleitung in der
     * README hilft dem nicht, der nicht weiss, dass er sie braucht.
     *
     * BESTEHENDE EINTRÄGE BLEIBEN. Sind mehrere Anbieter-Addons installiert,
     * stehen dort schon fremde Origins - überschreiben statt ergänzen würde
     * das andere Widget abschalten.
     */
    public function cspFreischalten(): void {
        $this->pruefeCsrf();

        if (Konfiguration::cspAusUmgebung()) {
            $this->zurueck('csp-umgebung');
        }

        $vorhanden = defined('CAPTCHA_DOMAINS')
            ? array_filter(array_map('trim', explode(',', (string) constant('CAPTCHA_DOMAINS'))))
            : [];

        $neu = array_values(array_unique(array_merge($vorhanden, Plugin::CSP_ORIGINS)));
        sort($neu);

        if (!SetupController::writeDbConfigValue('captcha_domains', implode(',', $neu))) {
            $this->zurueck('csp-schreibfehler');
        }

        PluginAudit::log(
            Plugin::SLUG,
            'Content-Security-Policy erweitert',
            'config/db_config.php',
            'captcha_domains: ' . implode(', ', $neu)
        );

        $this->zurueck('csp-gesetzt');
    }

    private function datenschutzKarte(): string {
        return '<div class="card">'
            . '<h1>🛡️ Cloudflare Turnstile</h1>'
            . '<p style="color: var(--text-muted);">Turnstile ersetzt die eingebaute Rechenaufgabe des Kerns '
            . 'auf den öffentlichen Formularen, für die Sie es unter <strong>Admin → Systemeinstellungen</strong> '
            . 'auswählen.</p>'
            . '<div style="background: var(--surface-muted); border-left: 4px solid var(--warning-fg, #8a6d3b); '
            . 'padding: 0.9rem; border-radius: var(--border-radius, 4px); margin-top: 1rem;">'
            . '<p style="margin: 0 0 0.5rem 0;"><strong>Was Sie damit tun</strong></p>'
            . '<ul style="margin: 0 0 0.5rem 1.2rem;">'
            . '<li>Jeder Besucher eines geschützten Formulars lädt ein Skript von '
            . '<code>challenges.cloudflare.com</code>. Dabei erhält <strong>Cloudflare, Inc. (USA)</strong> '
            . 'seine IP-Adresse und technische Angaben seines Browsers.</li>'
            . '<li>Das braucht eine Rechtsgrundlage (in aller Regel Art. 6 Abs. 1 lit. f DSGVO, berechtigtes '
            . 'Interesse an der Abwehr von Spam) und gehört <strong>in Ihre Datenschutzerklärung</strong> - '
            . 'mit Empfänger, Zweck und dem Hinweis auf die Übermittlung in ein Drittland.</li>'
            . '<li><strong>Nicht empfohlen für das DSGVO-Portal</strong> (<code>/dsgvo</code>): Das ist die '
            . 'Stelle, an der Betroffene ihre Rechte aus Art. 15/17 DSGVO geltend machen. Ausgerechnet dort '
            . 'ihre IP-Adresse an einen weiteren Empfänger zu senden, ist schwer zu begründen. Lassen Sie '
            . 'dieses Formular auf der eingebauten Rechenaufgabe und nutzen Sie Turnstile für Kontakt- und '
            . 'Deckanfragen. Wer ganz ohne Drittanbieter auskommen will, nimmt das Addon '
            . '<code>captcha-altcha</code>.</li>'
            . '</ul>'
            . '</div>'
            . '</div>';
    }

    private function schluesselKarte(string $csrf): string {
        $siteKey = Konfiguration::siteKey();
        $secretDa = Konfiguration::secretGesetzt();
        $fehler = Konfiguration::letzterFehler();

        $html = '<div class="card" style="margin-top: 1.5rem;">';
        $html .= '<h2>Schlüssel</h2>';

        if (!Konfiguration::einsatzbereit()) {
            $html .= '<p style="color: var(--warning-fg, #8a6d3b); background: var(--surface-muted); '
                . 'padding: 0.6rem; border-radius: var(--border-radius, 4px);">'
                . 'Solange Site-Key <em>und</em> Secret fehlen, erscheint Turnstile <strong>nicht</strong> in der '
                . 'Anbieterauswahl unter Admin → Systemeinstellungen. Das ist Absicht: Ein Anbieter ohne '
                . 'Schlüssel würde jede Prüfung ablehnen, und niemand käme mehr durch das Formular.</p>';
        }

        if ($fehler !== '') {
            $html .= '<p style="color: var(--danger-fg, #a94442); background: var(--surface-muted); '
                . 'padding: 0.6rem; border-radius: var(--border-radius, 4px);">'
                . '<strong>Letzter Betriebsfehler:</strong> ' . htmlspecialchars($fehler, ENT_QUOTES, 'UTF-8')
                . '</p>';
        }

        $html .= '<form method="POST" action="/plugin/' . Plugin::SLUG . '/verwaltung/speichern" autocomplete="off">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $csrf . '">';

        $html .= '<div class="form-group"><label for="ct-site">Site-Key (öffentlich, steht im HTML)</label>'
            . '<input class="form-control" type="text" id="ct-site" name="site_key" maxlength="120"'
            . ' value="' . htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8') . '" autocomplete="off">'
            . '<span class="form-hint">Aus dem Cloudflare-Dashboard, Bereich Turnstile. Leeres Feld = Schlüssel entfernen.</span></div>';

        $html .= '<div class="form-group"><label for="ct-secret">Secret (geheim)</label>'
            . '<input class="form-control" type="password" id="ct-secret" name="secret" maxlength="200"'
            . ' value="" autocomplete="new-password">'
            . '<span class="form-hint">Aktuell: <strong>'
            . ($secretDa ? 'hinterlegt (verschlüsselt gespeichert)' : 'nicht hinterlegt')
            . '</strong>. Das gespeicherte Secret wird hier <em>nie</em> angezeigt - ein leeres Feld lässt es '
            . 'unverändert. Zum Ersetzen neu eintippen, zum Entfernen das Häkchen unten setzen.</span></div>';

        $html .= '<div class="form-group"><label>'
            . '<input type="checkbox" name="secret_loeschen" value="1"> Gespeichertes Secret entfernen</label></div>';

        $html .= '<button type="submit" class="btn">Schlüssel speichern</button>';
        $html .= '</form></div>';

        return $html;
    }

    private function cspKarte(string $csrf): string {
        $fehlend = Konfiguration::cspFehlend();

        $html = '<div class="card" style="margin-top: 1.5rem;">';
        $html .= '<h2>Content-Security-Policy</h2>';
        $html .= '<p style="color: var(--text-muted);">Das Widget lädt Skript und Rahmen von einer fremden '
            . 'Herkunft. Steht sie nicht in <code>captcha_domains</code>, blockiert der Browser beides '
            . '<strong>ohne sichtbare Meldung</strong> - im Formular ist dann einfach nichts zu sehen.</p>';

        if ($fehlend === []) {
            $html .= '<p style="color: var(--success-fg, #3c763d);">✅ Alle nötigen Origins sind freigegeben: <code>'
                . htmlspecialchars(implode(', ', Plugin::CSP_ORIGINS), ENT_QUOTES, 'UTF-8') . '</code></p>';
            return $html . '</div>';
        }

        $html .= '<p style="color: var(--warning-fg, #8a6d3b);">⚠️ Es fehlt: <code>'
            . htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8') . '</code></p>';

        if (Konfiguration::cspAusUmgebung()) {
            $html .= '<p>Auf dieser Instanz wird <code>CAPTCHA_DOMAINS</code> über eine Umgebungsvariable '
                . 'gesetzt; sie hat Vorrang vor <code>config/db_config.php</code>. Ergänzen Sie die Origins '
                . 'dort und starten Sie den Dienst neu:</p>'
                . '<pre style="overflow-x:auto;"><code>CAPTCHA_DOMAINS=' . htmlspecialchars(implode(',', Plugin::CSP_ORIGINS), ENT_QUOTES, 'UTF-8')
                . '</code></pre>';
            return $html . '</div>';
        }

        $html .= '<form method="POST" action="/plugin/' . Plugin::SLUG . '/verwaltung/csp">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<button type="submit" class="btn">Origins jetzt freischalten</button>'
            . '<span class="form-hint" style="display:block;margin-top:0.4rem;">Schreibt '
            . '<code>captcha_domains</code> in <code>config/db_config.php</code>. Bereits eingetragene '
            . 'Origins anderer Addons bleiben erhalten.</span>'
            . '</form>';

        return $html . '</div>';
    }

    /** Rückmeldungen aus dem Query-Parameter - feste Texte, keine Fremdeingabe. */
    private static function meldung(): string {
        $texte = [
            'gespeichert' => ['Schlüssel gespeichert.', 'success'],
            'site-ungueltig' => ['Der Site-Key hat kein gültiges Format (6-120 Zeichen, Buchstaben, Ziffern, - und _).', 'danger'],
            'secret-ungueltig' => ['Das Secret hat kein gültiges Format (6-200 Zeichen, Buchstaben, Ziffern, - und _).', 'danger'],
            'kein-appkey' => ['Das Secret konnte nicht verschlüsselt werden - auf dieser Instanz fehlt ein gültiger APP_KEY. Unverschlüsselt wird es bewusst nicht gespeichert.', 'danger'],
            'csp-gesetzt' => ['Die Origins stehen jetzt in der Content-Security-Policy. Sie wirken ab der nächsten Seitenanfrage.', 'success'],
            'csp-schreibfehler' => ['config/db_config.php ist nicht beschreibbar - bitte die Origins von Hand eintragen.', 'danger'],
            'csp-umgebung' => ['CAPTCHA_DOMAINS wird über eine Umgebungsvariable gesetzt und hat Vorrang; bitte dort ergänzen.', 'danger'],
        ];

        $status = is_string($_GET['ct'] ?? null) ? $_GET['ct'] : '';
        if (!isset($texte[$status])) {
            return '';
        }

        [$text, $art] = $texte[$status];
        // Dieselbe Bauart wie in den uebrigen Addons: Der Kern bringt keine
        // .alert-Klasse mit, die Farben kommen aus den Theme-Variablen.
        return '<div class="card" style="color:var(--' . $art . '-fg);background:var(--' . $art . '-soft-bg);">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    private function pruefeCsrf(): void {
        if (!Router::verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }
    }

    private function zurueck(string $status): never {
        header('Location: /plugin/' . Plugin::SLUG . '/verwaltung?ct=' . $status);
        exit;
    }
}
