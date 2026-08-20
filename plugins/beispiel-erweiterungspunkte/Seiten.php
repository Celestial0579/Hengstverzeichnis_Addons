<?php
// plugins/beispiel-erweiterungspunkte/Seiten.php
//
// Die eigenen Routen des Lehrbeispiels (Addons#128) - GET wie POST.
//
// DIE WICHTIGSTE REGEL DIESER DATEI: Zugriffsschutz fuer Plugin-Routen ist
// Aufgabe des Plugins. Route-Handler laufen durch denselben Router wie
// Kern-Routen, aber NICHT automatisch durch checkAuth()/requireAdmin(). Wer
// das vergisst, hat eine oeffentlich erreichbare Verwaltungsseite gebaut, und
// es faellt niemandem auf, weil sie ja "im Adminbereich verlinkt" ist.
//
// Jede Klasse hier erbt deshalb von App\Controllers\BaseController und bringt
// ihre Pruefung im Konstruktor mit - genau wie ein Kern-Controller. Die drei
// Bauarten, die es gibt, sind alle einmal vertreten:
//
//   Ereignisbuchseite  angemeldet + eigene Berechtigung
//   Notizablage        angemeldet + eigene Berechtigung, dazu CSRF je POST
//   Schaufenster       OEFFENTLICH, aber ueber die Zusatzfunktion gesteuert
//   Probeformular      OEFFENTLICH, mit Spam-Schutz ueber den eigenen Kontext
//
// Und noch eine Eigenheit, die man leicht uebersieht: Der Kern stellt jeder
// Route zwingend `/plugin/<slug>` voran. Ein Addon kann dadurch nie eine
// Kern-Route ueberschreiben - `'path' => '/admin/horses'` wuerde zu
// `/plugin/beispiel-erweiterungspunkte/admin/horses`.

namespace Plugin\BeispielErweiterungspunkte;

use App\Controllers\BaseController;
use App\Permission\FeatureGate;
use App\Plugin\PluginAudit;
use App\Plugin\PluginPage;
use App\Router;
use App\Security\Captcha;

/**
 * Die Verwaltungsseite: Abdeckungstafel, Ereignisbuch, eigene Einstellung.
 *
 * Erreichbar ueber die Dashboard-Kachel - und die Kachel erscheint nur, wenn
 * dieselbe Berechtigung vorliegt, die hier geprueft wird (siehe
 * Plugin::dashboardKachel). Eine Kachel, die 403 liefert, sieht fuer den
 * Benutzer wie ein Defekt aus.
 */
class Ereignisbuchseite extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission(Plugin::MODUL, 'view');
    }

    public function zeigen(): void {
        $inhalt = '<div class="card"><h1 style="margin-top:0;">🧪 Beispiel: Erweiterungspunkte</h1>'
            . '<p>Dieses Addon ist ein <strong>Lehrbeispiel</strong> und geh&ouml;rt nicht auf eine '
            . 'Produktivinstanz. Es belegt jeden Erweiterungspunkt des Kerns mit einem sichtbaren '
            . 'Ergebnis; die Tafel unten zeigt, welcher Hook bei Ihnen schon gefeuert hat.</p>'
            . '<p><a class="btn btn-secondary" href="' . Plugin::BASIS . '/probeformular">'
            . 'Probeformular (Spam-Schutz) &ouml;ffnen</a></p></div>';

        $inhalt .= Fragmente::abdeckungstafel(Ereignisbuch::haeufigkeiten());

        // Die eigene Einstellung darf nur aendern, wer das Recht dazu hat -
        // das Formular erscheint sonst gar nicht erst (fail-closed).
        if (Plugin::darf(Plugin::MODUL, 'notiz')) {
            $inhalt .= Fragmente::sperrwortFormular(Ereignisbuch::sperrwort());
        }

        $inhalt .= Fragmente::ereignisliste(Ereignisbuch::letzte());

        PluginPage::render('Beispiel: Erweiterungspunkte', $inhalt);
    }
}

/**
 * Die POST-Ziele der beiden Abschnitte in den Bearbeitungsformularen und der
 * eigenen Einstellung.
 *
 * Alle drei pruefen CSRF SELBST. Der Kern prueft es fuer seine eigenen
 * Formulare; ein Addon-Formular ist keines davon.
 */
class Notizablage extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    /**
     * Notiz am Pferd - aus dem Abschnitt in /admin/horses/edit.
     *
     * Geprueft wird `horses.beispielnotiz`, NICHT `horses.edit`: Das
     * Bearbeitungsformular des Kerns steht hinter horses.edit, diese Daten
     * gehoeren aber dem Addon. Genau deshalb bringt der Abschnitt sein eigenes
     * Formular mit - ueber den Speichern-Knopf des Kerns liefe die Pruefung
     * nur gegen horses.edit.
     */
    public function speichern(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungueltig oder abgelaufen.');
        }
        $this->requirePermission('horses', 'beispielnotiz');

        $horseId = (int)($_POST['horse_id'] ?? 0);
        if ($horseId <= 0) {
            $this->zurueck('/admin/horses');
        }

        $ergebnis = Ereignisbuch::notizSetzen(
            Ereignisbuch::TYP_PFERD,
            $horseId,
            is_string($_POST['notiz'] ?? null) ? $_POST['notiz'] : ''
        );

        // JEDE SCHREIBENDE AKTION EINES ADDONS GEHOERT INS PROTOKOLL
        // (Kern-#352). PluginAudit::log() statt AuditLogger::log(), weil es
        // drei Entscheidungen abnimmt: Die Kategorie ist der eigene Slug (der
        // Filter unter /admin/logs speist sich aus SELECT DISTINCT category),
        // der Slug wird geprueft (ein Addon kann nicht unter fremdem Namen
        // protokollieren), und der Bezug ist ein eigenes Argument - man wird
        // danach gefragt, statt daran denken zu muessen.
        //
        // Und was NICHT hineingehoert: personenbezogene Inhalte. audit_logs
        // wird von keiner Loeschfrist erfasst.
        PluginAudit::log(
            Plugin::SLUG,
            'Beispiel-Notiz ' . $ergebnis,
            'Pferd #' . $horseId
        );

        $this->zurueck('/admin/horses/edit?id=' . $horseId);
    }

    /** Notiz am Kontakt - aus dem Abschnitt in /admin/contacts/edit. */
    public function kontaktnotiz(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungueltig oder abgelaufen.');
        }
        $this->requirePermission(Plugin::MODUL, 'notiz');

        $contactId = (int)($_POST['contact_id'] ?? 0);
        if ($contactId <= 0) {
            $this->zurueck('/admin/contacts');
        }

        $ergebnis = Ereignisbuch::notizSetzen(
            Ereignisbuch::TYP_KONTAKT,
            $contactId,
            is_string($_POST['notiz'] ?? null) ? $_POST['notiz'] : ''
        );

        PluginAudit::log(
            Plugin::SLUG,
            'Beispiel-Notiz am Kontakt ' . $ergebnis,
            // Nur die Kennung, nie der Name: Der Name eines Kontakts ist ein
            // personenbezogenes Datum, und dieses Protokoll ueberlebt jede
            // DSGVO-Loeschung.
            'Kontakt #' . $contactId
        );

        $this->zurueck('/admin/contacts/edit?id=' . $contactId);
    }

    /**
     * Die eigene Einstellung `plugin_beispiel_sperrwort` - der Wert, den
     * horse.publish_blockers gegen die Notiz haelt.
     *
     * Der Schluessel traegt den Pflicht-Praefix `plugin_`; nur damit darf er
     * im owns-Register der plugin.json stehen und wird bei der Deinstallation
     * mitgezaehlt und mitentfernt (Kern-#338).
     */
    public function sperrwortSetzen(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungueltig oder abgelaufen.');
        }
        $this->requirePermission(Plugin::MODUL, 'notiz');

        $wort = Ereignisbuch::sperrwortSetzen(
            is_string($_POST['sperrwort'] ?? null) ? $_POST['sperrwort'] : ''
        );

        PluginAudit::log(
            Plugin::SLUG,
            'Sperrwort geaendert',
            'Einstellung ' . Ereignisbuch::SETTING_SPERRWORT,
            'neuer Wert: ' . $wort
        );

        $this->zurueck(Plugin::BASIS . '/ereignisbuch');
    }

    private function zurueck(string $ziel): void {
        header('Location: ' . $ziel);
        exit;
    }
}

/**
 * Eine OEFFENTLICHE Seite, deren Sichtbarkeit der Betreiber umschaltet
 * (Kern-#57).
 *
 * Bewusst KEIN checkAuth(): Ob ein anonymer Besucher sie sehen darf,
 * entscheidet allein die vom Administrator gewaehlte Sichtbarkeit bzw. die
 * Leseberechtigung der Gruppe des angemeldeten Benutzers.
 *
 * FeatureGate::isVisible() ist fail-closed: unbekannte Funktionen sind nie
 * sichtbar, ohne Anmeldung gibt es bei "members" keinen Zugriff, und ein
 * DB-Fehler fuehrt zu "nicht sichtbar".
 */
class Schaufenster extends BaseController {

    public function zeigen(): void {
        if (!FeatureGate::isVisible(Plugin::FEATURE, $this->settings)) {
            $this->renderForbidden('Diese Zusatzfunktion ist Mitgliedern mit entsprechender Leseberechtigung vorbehalten.');
        }

        $inhalt = '<div class="card"><h1 style="margin-top:0;">🧪 Schaufenster</h1>'
            . '<p>Diese Seite ist sichtbar, weil die Zusatzfunktion entweder &ouml;ffentlich '
            . 'geschaltet ist oder Ihre Gruppe die Leseberechtigung besitzt. Solange der '
            . 'Administrator nichts w&auml;hlt, gilt <code>members</code> - fail-closed.</p>'
            . '<p>Die Seite l&auml;uft im Haupt-Layout des Kerns (App\\Plugin\\PluginPage): '
            . 'Header, Navigation, Fu&szlig;zeile, Theme-Umschalter und die admin-konfigurierten '
            . 'Markenfarben kommen von dort. Ein Addon liefert nur das Inhaltsfragment.</p>'
            . '<p><a class="btn btn-secondary" href="' . Plugin::BASIS . '/probeformular">'
            . 'Zum Probeformular</a></p></div>';

        $inhalt .= Fragmente::abdeckungstafel(Ereignisbuch::haeufigkeiten());

        PluginPage::render('Beispiel: Schaufenster', $inhalt);
    }
}

/**
 * Ein oeffentliches Formular mit eigenem Spam-Schutz-Kontext (Kern-#351).
 *
 * Das Formular tut absichtlich nichts weiter, als das Urteil anzuzeigen: Es
 * fuehrt die drei captcha.*-Hooks vor, ohne dass dafuer Besucherdaten
 * gespeichert werden muessten.
 *
 * WICHTIG AN Captcha::verify(): Antwortet kein Addon - weil es deaktiviert,
 * abgestuerzt oder gar nicht zustaendig ist -, prueft der Kern mit seiner
 * eingebauten Rechenaufgabe. Ein Addon darf sich deshalb nie darauf verlassen,
 * dass sein Widget die einzige Huerde ist; umgekehrt laesst ein kaputtes Addon
 * das Formular weder ungeschuetzt noch sperrt es Besucher aus.
 */
class Probeformular extends BaseController {

    public function zeigen(): void {
        $this->ausgeben(null);
    }

    public function pruefen(): void {
        if (!Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungueltig oder abgelaufen.');
        }

        // Der Kontext ist der eigene, ueber Plugin::captchaContexts()
        // angemeldete. Ein NICHT angemeldeter Kontext schaltet den Schutz
        // nicht ab - er zwingt auf den eingebauten Anbieter zurueck.
        $urteil = Captcha::verify($this->settings, Plugin::CAPTCHA_KONTEXT, $_POST);

        $this->ausgeben($urteil === Captcha::OK
            ? 'Angenommen: Der Spam-Schutz hat zugestimmt (' . $urteil . ').'
            : 'Abgelehnt: ' . $urteil . '.');
    }

    private function ausgeben(?string $meldung): void {
        $fragment = Captcha::renderField($this->settings, Plugin::CAPTCHA_KONTEXT);

        PluginPage::render(
            'Beispiel: Probeformular',
            Fragmente::probeformular($fragment, $meldung)
        );
    }
}
