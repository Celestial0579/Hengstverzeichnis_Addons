<?php
// plugins/beispiel-erweiterungspunkte/Plugin.php
//
// LEHRBEISPIEL. NICHT FUER DEN PRODUKTIVBETRIEB.
//
// Loest Addons#128. Bis v0.7 fuehrte das Referenz-Addon des Kerns
// (docs/examples/demo-plugin/) drei Hooks vor, waehrend der Kern
// zweiundzwanzig ausloest - wer wissen wollte, wie `horse.restored` aussieht
// oder was `captcha.verify` zurueckgeben muss, fand nur eine Tabellenzeile in
// docs/plugin-development.md und kein laufendes Beispiel.
//
// Dieses Addon belegt JEDEN Erweiterungspunkt des Kerns mit einem SICHTBAREN
// Ergebnis. Das ist keine Kosmetik: Ein Beispiel, dessen Wirkung man nicht
// sehen kann, geht beim ersten Umbau still kaputt. Deshalb schreibt jeder Hook
// hier in ein Ereignisbuch (eigene Tabelle), das die Addon-Seite anzeigt - und
// deshalb prueft
// tests/Manifest/BeispielErweiterungspunkteAbdeckungTest.php die Hooks des
// Kerns gegen die hier registrierten. Kommt im Kern einer hinzu, wird der
// Testlauf rot, statt dass das Beispiel zwei Releases lang unbemerkt
// veraltet.
//
// AUFBAU
//   Plugin.php        diese Datei - Registrierung und alle Hook-Rueckrufe
//   Ereignisbuch.php  eigene Tabellen (Ereignisse, Notizen), Einstellung
//   Fragmente.php     die HTML-Bausteine der Abschnitts-Hooks
//   Seiten.php        die eigenen Routen (GET und POST) samt Rechtepruefung
//
// LESEREIHENFOLGE: register() unten nennt jeden Hook mit einem Satz dazu,
// wofuer man ihn nimmt und was die Falle daran ist. Die Rueckrufe selbst
// stehen darunter in derselben Reihenfolge.

namespace Plugin\BeispielErweiterungspunkte;

use App\Helper\MediaUrl;
use App\I18n\Translator;
use App\Permission\GroupMembership;
use App\Plugin\HookManager;
use App\Plugin\PluginAudit;
use App\Security\Captcha;
use App\Security\CaptchaContext;

require_once __DIR__ . '/Ereignisbuch.php';
require_once __DIR__ . '/Fragmente.php';
require_once __DIR__ . '/Seiten.php';

class Plugin {

    /** Der eigene Slug - Verzeichnisname, Manifest-Feld und Audit-Kategorie in einem. */
    public const SLUG = 'beispiel-erweiterungspunkte';

    /** Wurzel aller eigenen Routen. Der Kern erzwingt diesen Praefix ohnehin. */
    public const BASIS = '/plugin/beispiel-erweiterungspunkte';

    /** Eigenes Rechte-Modul (siehe permissions()). */
    public const MODUL = 'beispiel-erweiterungspunkte';

    /** Zusatzfunktion mit admin-konfigurierbarer Sichtbarkeit (Kern-#57). */
    public const FEATURE = 'beispiel-schaufenster';

    /** Slug des vorgefuehrten Spam-Schutz-Anbieters (captcha.providers). */
    public const CAPTCHA_ANBIETER = 'beispiel-wortprobe';

    /** Eigener Formular-Kontext (Kern-#351), angemeldet ueber captchaContexts(). */
    public const CAPTCHA_KONTEXT = 'beispiel-formular';

    /**
     * Das Loesungswort der vorgefuehrten Wortprobe. Ein echter Anbieter
     * fragte hier einen Fremddienst - siehe den Kommentar an captchaVerify().
     */
    public const CAPTCHA_LOESUNG = 'pferd';

    /**
     * ACTIONS: Hook-Name => Methode dieser Klasse.
     *
     * Die Liste steht als Konstante da und nicht als Folge von addAction()-
     * Zeilen, damit sie von aussen lesbar ist: Der Abdeckungstest vergleicht
     * genau diese Schluessel mit den Hooks, die der Kern tatsaechlich
     * ausloest. Eine Liste, die nur im Rumpf von register() existiert, muesste
     * er aus dem Quelltext raten - und raten ist genau die Bauart, die still
     * veraltet.
     *
     * WAS EINE ACTION IST: ein Ereignis, auf das man reagiert. Kein
     * Rueckgabewert, und - das ist die wichtigste Falle des ganzen Systems -
     * KEIN VETO. Der HookManager faengt jede Ausnahme je Aufruf ab; wer den
     * Kern-Ablauf abbrechen will, kann das ueber eine Action nicht. Fuer das
     * einzige Veto, das es gibt, siehe den Filter horse.publish_blockers.
     */
    public const AKTIONEN = [
        // Vor INSERT/UPDATE eines Pferdes. WOFUER: Nebenwirkungen vorbereiten,
        // den Vorzustand festhalten. FALLE: $horseId ist beim Anlegen null,
        // und blockieren kann man hier nichts (siehe oben).
        'horse.before_save' => 'onHorseBeforeSave',
        // Nach dem erfolgreichen Speichern, inklusive der Personen-Zuordnungen.
        // WOFUER: alles, was den tatsaechlichen Stand braucht. FALLE: laeuft
        // auch beim Anlegen ($isNew), nicht nur beim Aendern.
        'horse.after_save' => 'onHorseAfterSave',
        // Vor dem Papierkorb UND vor dem endgueltigen Loeschen. WOFUER: den
        // Datensatz ein letztes Mal lesen. FALLE: $permanent unterscheidet die
        // beiden Faelle - wer das ignoriert, raeumt beim Papierkorb ab, was
        // eine Wiederherstellung noch braucht.
        'horse.before_delete' => 'onHorseBeforeDelete',
        // Nach dem Verschieben in den Papierkorb. WOFUER: abhaengige eigene
        // Daten stilllegen. FALLE: der Fremdschluessel-CASCADE greift beim
        // Soft-Delete NICHT - eigene Zeilen bleiben stehen und muessen hier
        // deaktiviert werden.
        'horse.trashed' => 'onHorseTrashed',
        // Nach der Wiederherstellung. WOFUER: das Gegenstueck zu trashed.
        // FALLE: Wer trashed behandelt und restored vergisst, hat ein Addon
        // gebaut, das nach jedem Papierkorb-Ausflug still weniger kann.
        'horse.restored' => 'onHorseRestored',
        // Nach dem endgueltigen Loeschen. WOFUER: aufraeumen, was kein
        // CASCADE erfasst. FALLE: eigene Zeilen MIT Fremdschluessel sind hier
        // schon weg - wer sie noch braucht, liest sie in before_delete.
        'horse.deleted' => 'onHorseDeleted',
        // Nach Anlegen/Aendern eines Kontakts (Kern-#347). FALLE: der Kern
        // feuert zusaetzlich die alten Namen person.after_save und
        // station.after_save. Wer die auch registriert, zaehlt jede Aenderung
        // dreimal - siehe BEWUSST_NICHT_ABGEDECKT.
        'contact.after_save' => 'onContactAfterSave',
        // Beim Verschieben eines Kontakts in den Papierkorb. FALLE: der Name
        // sagt "deleted", gemeint ist der Papierkorb - die Lage ist die von
        // horse.trashed, nicht die von horse.deleted.
        'contact.deleted' => 'onContactDeleted',
    ];

    /**
     * FILTER: Hook-Name => Methode dieser Klasse.
     *
     * WAS EIN FILTER IST: Der Kern reicht einen Wert herein und nimmt den
     * zurueckgegebenen weiter. Wer nichts beitragen will, gibt den
     * hereingereichten Wert UNVERAENDERT zurueck - niemals null, niemals ein
     * frisches leeres Array: Damit wuerde man die Beitraege der zuvor
     * geladenen Addons wegwerfen.
     */
    public const FILTER = [
        // Oeffentliche Pferde-Detailseite. WOFUER: eigene Angaben zum Pferd.
        // FALLE: $horse ist OEFFENTLICH GEFILTERT - ein fehlendes Feld ist
        // kein Fehler, sondern die Zusicherung, dass es nicht gezeigt werden
        // darf (siehe detailAbschnitt()).
        'horse.detail_sections' => 'detailAbschnitt',
        // Admin-Bearbeitungsformular eines Pferdes. WOFUER: eigene Angaben am
        // Pferd pflegen, ohne eigene Verwaltungsseite mit Pferdesuche.
        // FALLE: steht AUSSERHALB des Kern-Formulars - eigenes <form>, eigene
        // Route, eigene Rechtepruefung, und der Knopf heisst nicht
        // "Speichern".
        'horse.edit_sections' => 'bearbeitenAbschnitt',
        // Das einzige Veto des Systems (Kern-#335). WOFUER: verhindern, dass
        // ein Pferd oeffentlich wird, solange etwas fehlt. FALLE: es blockiert
        // NUR die Veroeffentlichung, nicht das Speichern - und der Startwert
        // ist die leere Liste, damit ein abgestuerztes Addon nichts sperrt.
        'horse.publish_blockers' => 'veroeffentlichungsEinwaende',
        // Addon-Filter fuer die Pferdesuche (Kern-#346). WOFUER: eigene
        // Kriterien in Katalog und Pferdeliste. FALLE: null heisst "nichts
        // beizutragen", [] heisst "keine Treffer" - beides gleichzusetzen ist
        // der Fehler, den der Hook ausdruecklich vermeidet.
        'horse.search_ids' => 'sucheEinschraenken',
        // Jede Karte im oeffentlichen Katalog. WOFUER: ein Abzeichen an der
        // Karte. FALLE: $horse ist hier SCHMALER als auf der Detailseite, und
        // der Filter laeuft bis zu 24-mal je Seitenaufruf - keine Abfrage je
        // Karte.
        'catalog.card_sections' => 'katalogAbschnitt',
        // Oeffentliche Kontaktseite (/kontakt?id=). WOFUER: eigene Angaben zum
        // Kontakt. FALLE: email/phone/Anschrift fehlen im Payload, solange der
        // Datensatz sie nicht ueber contact_public freigibt - und nachladen
        // per eigener Abfrage ist genau das, was der Filter verhindern soll.
        'contact.detail_sections' => 'kontaktAbschnitt',
        // Admin-Bearbeitungsformular eines Kontakts. WOFUER: eigene Angaben am
        // Kontakt, ohne dass der Kern eine Spalte dafuer mitbringt. FALLE wie
        // bei horse.edit_sections: eigenes Formular, eigene Route.
        'contact.edit_sections' => 'kontaktBearbeitenAbschnitt',
        // Startseite oberhalb der Pferdeliste (Kern-#356). WOFUER: bewerben.
        // FALLE: die Startseite ist die meistbesuchte Seite - was hier teuer
        // ist, ist ueberall teuer.
        'home.sections_top' => 'startseiteOben',
        // Startseite unterhalb der Pferdeliste. WOFUER: nachreichen statt
        // bewerben. Zwei Einhaengepunkte gibt es, damit nicht beide Absichten
        // um dieselbe Stelle streiten.
        'home.sections_bottom' => 'startseiteUnten',
        // Admin-Dashboard. WOFUER: der Einstieg in die eigene Verwaltung.
        // FALLE: eine Kachel auf eine Seite, die dem Benutzer 403 liefert, ist
        // schlechter als keine Kachel - deshalb fail-closed pruefen.
        'admin.dashboard_tiles' => 'dashboardKachel',
        // Oeffentliche Navigation, auf JEDER Seite. WOFUER: ein eigener
        // Einstieg. FALLE: hier wird KEIN HTML zurueckgegeben, sondern Daten,
        // die der Kern prueft (App\Helper\NavItems) - ein falscher Eintrag
        // verschwindet still.
        'layout.nav_items' => 'navigationsEintrag',
        // Spam-Schutz: Anbieterliste. WOFUER: einen Fremdanbieter nachruesten.
        // FALLE: 'builtin' ist nicht ueberschreibbar, und der Filter laeuft bei
        // JEDER Pruefung, nicht nur in den Einstellungen.
        'captcha.providers' => 'captchaAnbieter',
        // Spam-Schutz: Formularfragment. FALLE: der eigene Slug ist zu
        // pruefen - der Filter laeuft fuer jeden Anbieter, auch fuer fremde.
        'captcha.render' => 'captchaRendern',
        // Spam-Schutz: Urteil. FALLE: nur OK/WRONG/EXPIRED/TOO_FAST gelten als
        // Antwort; alles andere - auch true - liest der Kern als "nicht
        // geantwortet" und prueft selbst.
        'captcha.verify' => 'captchaPruefen',
    ];

    /**
     * Hooks, die der Kern ausloest und dieses Beispiel BEWUSST nicht belegt,
     * je mit dem Grund. Der Abdeckungstest liest diese Liste - ein Hook ohne
     * Eintrag hier und ohne Rueckruf oben macht ihn rot.
     *
     * Eine Ausnahmeliste ohne Begruendung waere ein Freibrief; deshalb steht
     * der Grund im Wert und der Test besteht darauf, dass er nicht leer ist.
     *
     * @var array<string, string>
     */
    public const BEWUSST_NICHT_ABGEDECKT = [
        'person.detail_sections' => 'Alias von contact.detail_sections, feuert seit v0.8 zusaetzlich und kaskadierend auf demselben Ergebnis. Wer beide registriert, bekommt seinen Abschnitt doppelt auf derselben Seite - seit Kern-#336 gibt es nur noch einen Datensatz. Entfaellt in v0.9.0.',
        'station.detail_sections' => 'Alias von contact.detail_sections, siehe person.detail_sections.',
        'person.edit_sections' => 'Alias von contact.edit_sections, kaskadierend - doppelte Registrierung ergaebe zwei Formulare im selben Bearbeitungsdialog. Entfaellt in v0.9.0.',
        'station.edit_sections' => 'Alias von contact.edit_sections, siehe person.edit_sections.',
        'person.after_save' => 'Alias von contact.after_save - der Kern feuert alle drei Namen mit denselben Argumenten. Wer sie zusaetzlich registriert, zaehlt jede Aenderung dreifach. Entfaellt in v0.9.0.',
        'station.after_save' => 'Alias von contact.after_save, siehe person.after_save.',
        'person.deleted' => 'Alias von contact.deleted, siehe person.after_save. Entfaellt in v0.9.0.',
        'station.deleted' => 'Alias von contact.deleted, siehe person.after_save.',
    ];

    /**
     * Einstiegspunkt. Wird vom Kern nur aufgerufen, wenn ein Administrator
     * das Addon unter /admin/plugins aktiviert hat.
     *
     * register() laeuft im Bootstrap JEDES Requests. Hier gehoert deshalb
     * nichts hinein, was Arbeit kostet - kein DDL (das steht in install()),
     * keine Abfrage, kein Dateizugriff. Nur Registrierungen.
     */
    public function register(HookManager $hooks): void {
        foreach (self::AKTIONEN as $hook => $methode) {
            $hooks->addAction($hook, [$this, $methode]);
        }
        foreach (self::FILTER as $hook => $methode) {
            $hooks->addFilter($hook, [$this, $methode]);
        }
    }

    // -----------------------------------------------------------------
    // Lebenszyklus des Kerns: install() und uninstall()
    // -----------------------------------------------------------------

    /**
     * Einmalige Einrichtung (Kern-Addons#75). Der Kern ruft das bei jeder
     * (Re-)Aktivierung und nach jedem eingespielten Addon-Update auf - die
     * Zusicherung lautet "mindestens einmal", nicht "genau einmal". Deshalb
     * muss alles hier idempotent sein.
     *
     * Beide Tabellen tragen den Pflicht-Praefix `plugin_`: Ohne ihn wiese der
     * Kern sie im owns-Register der plugin.json ab, und die Deinstallation
     * liesse sie liegen (Kern-#338).
     */
    public function install(): void {
        Ereignisbuch::schemaAnlegen();
    }

    /**
     * Deinstallation (Kern-#338). WICHTIG ZU VERSTEHEN: Die eigenen Tabellen
     * und die eigene Einstellung raeumt der Kern selbst weg - sie stehen im
     * "owns"-Abschnitt der plugin.json, und genau deshalb kann er dem
     * Betreiber VOR dem Loeschen sagen, wie viele Datensaetze verschwaenden.
     *
     * Hier steht nur, was sich NICHT deklarieren laesst: eigene Zeilen in
     * KERN-Tabellen. Dieses Addon hinterlaesst zwei davon in `settings`, und
     * beide hat es nicht selbst geschrieben - der Kern legt sie an, sobald ein
     * Administrator die Sichtbarkeit der Zusatzfunktion waehlt bzw. diesem
     * Formular-Kontext einen Spam-Schutz-Anbieter zuweist. Sie beginnen nicht
     * mit `plugin_` und duerfen deshalb gar nicht im owns-Register stehen;
     * ohne diese Methode blieben sie fuer immer liegen.
     */
    public function uninstall(): void {
        $entfernt = Ereignisbuch::kernEinstellungenAufraeumen([
            'feature_visibility__' . self::FEATURE,
            CaptchaContext::settingKey(self::CAPTCHA_KONTEXT),
        ]);

        PluginAudit::log(
            self::SLUG,
            'Deinstallation aufgeraeumt',
            'Kern-Einstellungen',
            $entfernt . ' Zeile(n) in `settings` entfernt, die sich nicht deklarieren lassen'
        );
    }

    // -----------------------------------------------------------------
    // Actions: reagieren, nicht blockieren
    // -----------------------------------------------------------------

    /**
     * Vor dem Speichern eines Pferdes.
     *
     * $horseId ist beim ANLEGEN null - wer hier eine Zeile mit Fremdschluessel
     * auf das Pferd schreiben will, kann das noch gar nicht, das Pferd
     * existiert nicht. Dafuer gibt es horse.after_save.
     *
     * Und noch einmal, weil es die haeufigste Fehlannahme ist: Eine Ausnahme
     * hier bricht den Speichervorgang NICHT ab. Der HookManager faengt sie ab
     * und protokolliert sie. Blockierende Validierung ist Sache des Kerns.
     *
     * @param array<string, mixed> $postData
     */
    public function onHorseBeforeSave(?int $horseId, array $postData): void {
        Ereignisbuch::notieren(
            'horse.before_save',
            $horseId === null ? 'Pferd (neu)' : 'Pferd #' . $horseId,
            // Bewusst nur der Name, nicht der ganze POST: Was ins Ereignisbuch
            // wandert, wandert in eine Tabelle, die niemand mehr aufraeumt.
            'Name laut Formular: ' . Ereignisbuch::kurz((string)($postData['name'] ?? ''))
        );
    }

    /**
     * Nach dem Speichern - der erste Zeitpunkt, zu dem der tatsaechliche Stand
     * befragbar ist (die Zuordnungen in horse_persons entstehen erst nach dem
     * INSERT des Pferdes).
     *
     * Hier steht auch der Protokolleintrag: Das Addon selbst schreibt an
     * dieser Stelle nichts Fachliches, aber es fuehrt vor, wie eine
     * schreibende Aktion protokolliert wird (Kern-#352) - Kategorie ist der
     * eigene Slug, der Bezug ein eigenes Argument.
     *
     * @param array<string, mixed> $postData
     */
    public function onHorseAfterSave(int $horseId, array $postData, bool $isNew): void {
        Ereignisbuch::notieren(
            'horse.after_save',
            'Pferd #' . $horseId,
            $isNew ? 'neu angelegt' : 'aktualisiert'
        );
    }

    /**
     * Vor dem Papierkorb ($permanent === false) UND vor dem endgueltigen
     * Loeschen ($permanent === true). Beim zweiten Fall ist es die letzte
     * Gelegenheit, den Datensatz zu lesen.
     *
     * Die Unterscheidung ist der ganze Sinn des Parameters: Wer beim
     * Papierkorb schon abraeumt, macht die Wiederherstellung wertlos.
     *
     * @param array<string, mixed> $horse
     */
    public function onHorseBeforeDelete(int $horseId, array $horse, bool $permanent): void {
        Ereignisbuch::notieren(
            'horse.before_delete',
            'Pferd #' . $horseId,
            $permanent
                ? 'endgueltig - letzte Gelegenheit zu lesen: ' . Ereignisbuch::kurz((string)($horse['name'] ?? ''))
                : 'Papierkorb - eigene Daten bleiben stehen, nur stilllegen'
        );
    }

    /**
     * Nach dem Soft-Delete. Hier werden eigene Daten STILLGELEGT, nicht
     * geloescht: Der Fremdschluessel-CASCADE greift beim Soft-Delete nicht,
     * und ein wiederhergestelltes Pferd soll seine Notiz zurueckbekommen.
     *
     * @param array<string, mixed> $horse
     */
    public function onHorseTrashed(int $horseId, array $horse): void {
        $betroffen = Ereignisbuch::notizStilllegen(Ereignisbuch::TYP_PFERD, $horseId, true);
        Ereignisbuch::notieren(
            'horse.trashed',
            'Pferd #' . $horseId,
            $betroffen . ' eigene Notiz(en) stillgelegt (CASCADE greift beim Soft-Delete nicht)'
        );
    }

    /**
     * Das Gegenstueck. Ohne diesen Rueckruf haette ein Addon, das trashed
     * behandelt, nach jedem Papierkorb-Ausflug still weniger Daten.
     *
     * @param array<string, mixed> $horse
     */
    public function onHorseRestored(int $horseId, array $horse): void {
        $betroffen = Ereignisbuch::notizStilllegen(Ereignisbuch::TYP_PFERD, $horseId, false);
        Ereignisbuch::notieren(
            'horse.restored',
            'Pferd #' . $horseId,
            $betroffen . ' eigene Notiz(en) wieder in Betrieb'
        );
    }

    /**
     * Nach dem endgueltigen Loeschen. Zeilen mit Fremdschluessel und
     * ON DELETE CASCADE sind jetzt bereits weg - dieses Addon fuehrt seine
     * Notizen ohne Fremdschluessel (siehe Ereignisbuch::schemaAnlegen()) und
     * muss deshalb selbst aufraeumen. Genau das ist der Fall, fuer den es den
     * Hook gibt.
     *
     * @param array<string, mixed> $horse
     */
    public function onHorseDeleted(int $horseId, array $horse): void {
        $geloescht = Ereignisbuch::notizLoeschen(Ereignisbuch::TYP_PFERD, $horseId);
        Ereignisbuch::notieren(
            'horse.deleted',
            'Pferd #' . $horseId,
            $geloescht . ' eigene Notiz(en) mitgeloescht'
        );
    }

    /**
     * Kontakt angelegt oder geaendert (Kern-#347).
     *
     * NUR contact.after_save ist registriert. Der Kern feuert zusaetzlich
     * person.after_save und station.after_save mit denselben Argumenten, damit
     * Addons aus der 0.7-Linie weiterlaufen. Seit persons und
     * breeding_stations eine Tabelle sind, bekaeme ein Addon, das alle drei
     * registriert, jede Aenderung dreimal - siehe BEWUSST_NICHT_ABGEDECKT.
     *
     * @param array<string, mixed> $postData
     */
    public function onContactAfterSave(int $contactId, array $postData, bool $isNew): void {
        Ereignisbuch::notieren(
            'contact.after_save',
            'Kontakt #' . $contactId,
            // KEIN Name, keine Adresse, keine E-Mail: Kontaktdaten sind
            // personenbezogen, und diese Tabelle erfasst keine Loeschfrist.
            $isNew ? 'neu angelegt' : 'aktualisiert'
        );
    }

    /**
     * Kontakt in den Papierkorb verschoben. Der Name sagt "deleted", die Lage
     * ist die von horse.trashed - der Datensatz ist noch da, der CASCADE hat
     * noch nicht gegriffen.
     *
     * @param array<string, mixed> $contact
     */
    public function onContactDeleted(int $contactId, array $contact): void {
        // Dieselbe Behandlung wie bei horse.trashed, und aus demselben Grund:
        // Der Datensatz ist noch da, der CASCADE hat nicht gegriffen, eigene
        // Daten werden stillgelegt statt geloescht.
        $betroffen = Ereignisbuch::notizStilllegen(Ereignisbuch::TYP_KONTAKT, $contactId, true);

        Ereignisbuch::notieren(
            'contact.deleted',
            'Kontakt #' . $contactId,
            'in den Papierkorb verschoben - ' . $betroffen . ' eigene Notiz(en) stillgelegt'
        );
    }

    // -----------------------------------------------------------------
    // Filter: Werte hereinnehmen, veraendert zurueckgeben
    // -----------------------------------------------------------------

    /**
     * Abschnitt auf der oeffentlichen Pferde-Detailseite.
     *
     * $horse ist OEFFENTLICH GEFILTERT (Kern-#121/#122): Ein fehlendes Feld
     * ist kein Fehler, sondern die Zusicherung, dass diese Angabe oeffentlich
     * nicht gezeigt werden darf. Deshalb wird hier das FELD geprueft, das
     * gebraucht wird - nicht die Verknuepfung $horse['breeding_station_id'],
     * die auch dann gesetzt ist, wenn die Station unveroeffentlicht ist. An
     * genau dieser Verwechslung ist in diesem Repo schon ein Addon still
     * gebrochen.
     *
     * Der Rueckgabewert wird UNESCAPED ausgegeben - das Escaping ist Sache
     * dieses Addons, siehe Fragmente.
     *
     * @param array<int, string> $sections
     * @param array<string, mixed> $horse
     * @param array<int, array<string, mixed>> $horsePersons
     * @param array<string, mixed>|null $pedigree
     * @return array<int, string>
     */
    public function detailAbschnitt(array $sections, array $horse, array $horsePersons, ?array $pedigree = null): array {
        $horseId = (int)($horse['id'] ?? 0);
        if ($horseId <= 0) {
            return $sections;
        }

        $sections[] = Fragmente::pferdeDetail(
            Ereignisbuch::notiz(Ereignisbuch::TYP_PFERD, $horseId),
            // Nur zur Veranschaulichung, dass der Baum schon berechnet
            // mitgeliefert wird und ein Addon ihn nicht erneut bauen muss.
            $pedigree,
            // Und der Beleg fuer den Datenvertrag: geprueft wird das Feld,
            // nicht die Verknuepfung.
            !empty($horse['station_name']) ? (string)$horse['station_name'] : null
        );

        return $sections;
    }

    /**
     * Abschnitt im Admin-Bearbeitungsformular eines Pferdes.
     *
     * Drei Dinge sind hier anders als auf der oeffentlichen Seite:
     *
     * 1. $horse ist der ROHE Datensatz aus "SELECT * FROM horses" - ohne
     *    Sichtbarkeitsfilter, ohne station_*-Felder, und auch fuer ein Pferd
     *    im Papierkorb. Die Pruefungen von oben gelten hier NICHT.
     * 2. Der Abschnitt steht AUSSERHALB des Kern-Formulars (verschachtelte
     *    <form> waeren ungueltiges HTML). Also eigenes Formular, eigene
     *    POST-Route, eigene Rechtepruefung - der Speichern-Knopf des Kerns
     *    speichert hier nichts mit.
     * 3. Weil es dadurch zwei Knoepfe auf einer Seite gibt, heisst der eigene
     *    nicht "Speichern", sondern nennt die Handlung.
     *
     * FAIL-CLOSED: Das Bearbeitungsformular verlangt horses.edit, diese Daten
     * aber horses.beispielnotiz. Ohne die Pruefung saehe ein Redakteur ein
     * Formular, das beim Absenden 403 liefert.
     *
     * @param array<int, string> $sections
     * @param array<string, mixed> $horse
     * @return array<int, string>
     */
    public function bearbeitenAbschnitt(array $sections, array $horse): array {
        if (!self::darf('horses', 'beispielnotiz')) {
            return $sections;
        }

        $horseId = (int)($horse['id'] ?? 0);
        if ($horseId <= 0) {
            return $sections;
        }

        $sections[] = Fragmente::pferdeBearbeiten(
            $horseId,
            Ereignisbuch::notiz(Ereignisbuch::TYP_PFERD, $horseId, true)
        );
        return $sections;
    }

    /**
     * Das einzige Veto des Systems (Kern-#335) - und es gilt ausschliesslich
     * dem VEROEFFENTLICHEN, nicht dem Speichern. Die Arbeit geht nie
     * verloren, es faellt nur das Haekchen.
     *
     * Der Kern startet mit der leeren Liste. Stuerzt ein Addon ab, verschluckt
     * der HookManager die Ausnahme und behaelt den vorherigen Wert - "keine
     * Einwaende". Das ist die richtige Richtung: Ein abgestuerztes Addon darf
     * keine Veroeffentlichung blockieren, denn niemand koennte den Grund
     * beheben.
     *
     * Vorgefuehrt wird das an einem Sperrwort in der eigenen Notiz. Ein echtes
     * Addon prueft hier seine Fachbedingung - "Gesundheitstest fehlt",
     * "Deckgebuehr nicht hinterlegt".
     *
     * @param array<int, string> $blockers
     * @param array<string, mixed> $horse
     * @return array<int, string>
     */
    public function veroeffentlichungsEinwaende(array $blockers, int $horseId, array $horse): array {
        $notiz = Ereignisbuch::notiz(Ereignisbuch::TYP_PFERD, $horseId, true);
        $sperrwort = Ereignisbuch::sperrwort();

        if ($notiz !== null && $sperrwort !== '' && stripos($notiz, $sperrwort) !== false) {
            // Menschenlesbar und handlungsleitend - der Text landet
            // unveraendert vor den Augen des Bearbeiters.
            $blockers[] = Translator::t('veto_grund', ['wort' => $sperrwort], self::SLUG);
        }

        return $blockers;
    }

    /**
     * Addon-Filter fuer die Pferdesuche (Kern-#346), wirksam im oeffentlichen
     * Katalog wie in der Admin-Pferdeliste.
     *
     * DIE UNTERSCHEIDUNG null / [] IST DER GANZE PUNKT:
     *   null  = "ich habe nichts beizutragen"  -> keine Einschraenkung
     *   []    = "keine Treffer"                -> es wird nichts gefunden
     * Beides gleichzusetzen hiesse, dass ein Addon "nichts passt" nicht sagen
     * kann - der Benutzer bekaeme den vollen Bestand angezeigt, obwohl sein
     * Filter etwas anderes meint.
     *
     * Der hereingereichte Wert wird respektiert: Hat ein zuvor geladenes
     * Addon schon eingeschraenkt, wird geschnitten statt ueberschrieben.
     *
     * $nurOeffentlich sagt, ob die Anfrage aus dem oeffentlichen Katalog
     * kommt. Wer eigene Daten mit Sichtbarkeitsregeln fuehrt, muss das
     * auswerten - dieses Addon fuehrt nur Notizen ohne eigene Sichtbarkeit.
     *
     * @param array<int, int>|null $ids
     * @param array<string, mixed> $request
     * @return array<int, int>|null
     */
    public function sucheEinschraenken(?array $ids, array $request, bool $nurOeffentlich): ?array {
        $begriff = trim((string)($request['beispiel_notiz'] ?? ''));
        if ($begriff === '') {
            return $ids; // nichts beizutragen - und NICHT [] zurueckgeben.
        }

        $eigene = Ereignisbuch::pferdeMitNotiz($begriff);
        if ($ids === null) {
            return $eigene;
        }

        // Schnittmenge: Der Beitrag eines frueher geladenen Addons bleibt
        // erhalten. array_values(), damit die Liste wieder luckenlos ist.
        return array_values(array_intersect($ids, $eigene));
    }

    /**
     * Abzeichen an jeder Katalogkarte.
     *
     * ZWEI FALLEN AUF EINMAL:
     * 1. $horse ist hier SCHMALER als auf der Detailseite - die Katalog-Query
     *    liefert eine feste Spaltenteilmenge, description und die
     *    station_*-Kontaktfelder fehlen.
     * 2. Der Filter laeuft je Karte, bis zu 24-mal pro Seitenaufruf. Eine
     *    eigene Abfrage hier waeren 24 Abfragen. Deshalb laedt Ereignisbuch
     *    die Notizen EINMAL pro Request und beantwortet die Frage danach aus
     *    dem Gedaechtnis.
     *
     * @param array<int, string> $sections
     * @param array<string, mixed> $horse
     * @return array<int, string>
     */
    public function katalogAbschnitt(array $sections, array $horse): array {
        $horseId = (int)($horse['id'] ?? 0);
        if ($horseId <= 0) {
            return $sections;
        }

        $notiz = Ereignisbuch::pferdeNotizAusGedaechtnis($horseId);
        if ($notiz === null) {
            return $sections;
        }

        $sections[] = Fragmente::katalogAbzeichen($notiz);
        return $sections;
    }

    /**
     * Abschnitt auf der oeffentlichen Kontaktseite (/kontakt?id=).
     *
     * $contact enthaelt NUR die oeffentlichen Spalten. email, phone, mobile
     * und die Anschrift sind ausschliesslich dann gesetzt, wenn der Datensatz
     * sie ueber contact_public freigibt - sonst FEHLEN DIE SCHLUESSEL GANZ.
     * contact_info fehlt immer.
     *
     * Und das Wichtigste: Was hier fehlt, darf ein Addon auch nicht per
     * eigener Abfrage nachladen. Der Filter ist keine Huerde, die man umgeht,
     * sondern die Datenschutzgrenze selbst.
     *
     * @param array<int, string> $sections
     * @param array<string, mixed> $contact
     * @param array<string, array<int, array<string, mixed>>> $horsesByRole
     * @param array<int, array<string, mixed>> $stationHorses
     * @return array<int, string>
     */
    public function kontaktAbschnitt(array $sections, array $contact, array $horsesByRole = [], array $stationHorses = []): array {
        $contactId = (int)($contact['id'] ?? 0);
        if ($contactId <= 0) {
            return $sections;
        }

        $sections[] = Fragmente::kontaktDetail(
            // array_key_exists statt isset: Ein freigegebenes, aber leeres
            // Feld ist etwas anderes als ein gesperrtes.
            array_key_exists('email', $contact),
            count($horsesByRole['breeder'] ?? []),
            count($stationHorses)
        );

        return $sections;
    }

    /**
     * Abschnitt im Admin-Bearbeitungsformular eines Kontakts.
     *
     * Damit kann ein Addon eigene Angaben am Kontakt pflegen, OHNE dass der
     * Kern dafuer eine Spalte mitbringt - der uebliche Fall ist ein Opt-out.
     * Gerendert wird ausserhalb des Kern-Formulars, also gelten dieselben
     * Regeln wie bei horse.edit_sections.
     *
     * @param array<int, string> $sections
     * @param array<string, mixed> $contact
     * @return array<int, string>
     */
    public function kontaktBearbeitenAbschnitt(array $sections, array $contact): array {
        $contactId = (int)($contact['id'] ?? 0);
        if ($contactId <= 0) {
            return $sections;
        }

        if (!self::darf(self::MODUL, 'notiz')) {
            // Fail-closed wie beim Pferdeabschnitt: lieber gar kein Formular
            // als eines, das beim Absenden 403 liefert.
            return $sections;
        }

        $sections[] = Fragmente::kontaktBearbeiten(
            $contactId,
            Ereignisbuch::notiz(Ereignisbuch::TYP_KONTAKT, $contactId, true),
            Ereignisbuch::anzahlFuer('Kontakt #' . $contactId)
        );
        return $sections;
    }

    /**
     * Startseite, oberhalb der Pferdeliste (Kern-#356).
     *
     * $featuredHorses sind die drei Pferde, die der Kern ohnehin schon geladen
     * hat - sie werden hier weiterverwendet statt erneut abgefragt. Die
     * Startseite ist die meistbesuchte Seite des Verzeichnisses; was hier
     * teuer ist, ist ueberall teuer.
     *
     * FALLE, die dieses Repo schon Arbeit gekostet hat: Das Pferdefoto wird
     * ueber App\Helper\MediaUrl gebildet, nicht ueber den rohen Spaltenwert
     * image_url. Der rohe Wert funktioniert - aber am Anwendungscode vorbei
     * und damit ohne den Einbettungsschutz.
     *
     * @param array<int, string> $sections
     * @param array<int, array<string, mixed>> $featuredHorses
     * @return array<int, string>
     */
    public function startseiteOben(array $sections, array $featuredHorses): array {
        $pferd = $featuredHorses[0] ?? null;
        if (!is_array($pferd)) {
            return $sections;
        }

        $sections[] = Fragmente::startseiteOben(
            (int)($pferd['id'] ?? 0),
            (string)($pferd['name'] ?? ''),
            MediaUrl::horseImage($pferd)
        );

        return $sections;
    }

    /**
     * Startseite, unterhalb der Pferdeliste. Zwei Einhaengepunkte gibt es,
     * damit "bewerben" (oben) und "nachreichen" (unten) nicht um dieselbe
     * Stelle streiten und die Reihenfolge nicht davon abhaengt, welches Addon
     * zuerst geladen wurde.
     *
     * @param array<int, string> $sections
     * @param array<int, array<string, mixed>> $featuredHorses
     * @return array<int, string>
     */
    public function startseiteUnten(array $sections, array $featuredHorses): array {
        $sections[] = Fragmente::startseiteUnten(Ereignisbuch::anzahlGesamt());
        return $sections;
    }

    /**
     * Kachel im Admin-Dashboard.
     *
     * FAIL-CLOSED: Die Kachel fuehrt auf das Ereignisbuch, und das verlangt
     * beispiel-erweiterungspunkte.view. Eine Kachel, die dem Benutzer 403
     * liefert, ist schlechter als keine - sie sieht aus wie ein Defekt.
     *
     * @param array<int, array{url:string, label:string, icon:string}> $tiles
     * @return array<int, array{url:string, label:string, icon:string}>
     */
    public function dashboardKachel(array $tiles): array {
        if (!self::darf(self::MODUL, 'view')) {
            return $tiles;
        }

        $tiles[] = [
            'url' => self::BASIS . '/ereignisbuch',
            'label' => Translator::t('kachel_label', [], self::SLUG),
            'icon' => '🧪',
        ];
        return $tiles;
    }

    /**
     * Eintrag in der oeffentlichen Navigation - auf JEDER Seite sichtbar.
     *
     * HIER WIRD KEIN HTML ZURUECKGEGEBEN. Anders als bei allen
     * *_sections-Hooks liefert ein Addon Daten, die der Kern prueft
     * (App\Helper\NavItems::sanitize): Die url muss ein seiteneigener
     * ABSOLUTER Pfad sein - `javascript:`, fremde Hosts, protokollrelative
     * Adressen wie `//fremd.example`, relative Pfade und `..` fliegen raus.
     * Das label wird auf 40 Zeichen gekuerzt, ein fehlendes icon wird zu 🧩,
     * und ueber alle Addons hinweg sind hoechstens fuenf Eintraege erlaubt.
     *
     * DIE FALLE: Ein verworfener Eintrag verschwindet STILL. Er ist ein
     * Programmierfehler des Addons, kein Zustand, ueber den ein Besucher
     * etwas erfahren muesste. Wer seinen Menuepunkt nicht sieht, prueft
     * zuerst, ob der Pfad mit '/' beginnt.
     *
     * @param array<int, array{url:string, label:string, icon:string}> $items
     * @return array<int, array{url:string, label:string, icon:string}>
     */
    public function navigationsEintrag(array $items): array {
        $items[] = [
            'url' => self::BASIS . '/schaufenster',
            'label' => Translator::t('nav_label', [], self::SLUG),
            'icon' => '🧪',
        ];
        return $items;
    }

    /**
     * Meldet einen zusaetzlichen Spam-Schutz-Anbieter an.
     *
     * Der eingebaute Anbieter `builtin` ist immer enthalten und laesst sich
     * NICHT ueberschreiben - er ist der Rueckfallweg, den ein fehlerhaftes
     * Addon nicht unbrauchbar machen koennen soll.
     *
     * Dieser Filter laeuft nicht nur beim Aufbau der Auswahlliste in den
     * Systemeinstellungen, sondern bei JEDER Pruefung. Er muss deshalb billig
     * bleiben: kein Netzaufruf, keine Abfrage.
     *
     * @param array<string, string> $providers
     * @return array<string, string>
     */
    public function captchaAnbieter(array $providers): array {
        $providers[self::CAPTCHA_ANBIETER] = Translator::t('captcha_anbieter', [], self::SLUG);
        return $providers;
    }

    /**
     * Das Formularfragment des eigenen Anbieters.
     *
     * DER EIGENE SLUG IST ZU PRUEFEN. Der Filter laeuft fuer jeden Anbieter;
     * ein Rueckruf, der $provider ignoriert, antwortete auch dann, wenn der
     * Betreiber einen anderen gewaehlt hat - und ueberschriebe dessen Widget.
     *
     * Zurueckgegeben wird ein FRAGMENT, das in das bestehende Formular
     * eingesetzt wird. Ein Addon kann keine vorgeschaltete Pruefseite und
     * keinen zweiten Schritt erzwingen.
     *
     * Braucht ein echter Anbieter ein externes Skript oder ein <iframe>,
     * reicht das Rendern nicht: Die Content-Security-Policy des Kerns kennt
     * `default-src 'self'` und kein `frame-src`, der Browser blockiert das
     * Widget sonst lautlos. Die Origins gehoeren in die Konfiguration.
     */
    public function captchaRendern(string $html, string $provider, string $context): string {
        if ($provider !== self::CAPTCHA_ANBIETER) {
            return $html; // nicht zustaendig - fremden Beitrag durchreichen.
        }

        return Fragmente::captchaFeld($context);
    }

    /**
     * Das Urteil des eigenen Anbieters.
     *
     * NUR VIER WERTE GELTEN ALS ANTWORT: Captcha::OK, WRONG, EXPIRED,
     * TOO_FAST. Alles andere - auch `true` - liest der Kern als "nicht
     * geantwortet" und prueft dann selbst mit seiner eingebauten Aufgabe.
     *
     * null zurueckzugeben ist deshalb kein Notbehelf, sondern die richtige
     * Antwort auf "nicht zustaendig". Und es ist zugleich der Grund, warum das
     * Ganze sicher ist: Der HookManager verschluckt eine Ausnahme im Rueckruf
     * und behaelt den vorherigen Wert - ein abgestuerztes Addon liefert damit
     * null und niemals versehentlich ein OK.
     *
     * Ein echter Anbieter fragte hier seinen Fremddienst. Diese Wortprobe
     * taugt ausdruecklich nicht als Spam-Schutz; sie zeigt nur die Form.
     *
     * @param array<string, mixed> $input
     */
    public function captchaPruefen(?string $verdict, string $provider, string $context, array $input): ?string {
        if ($provider !== self::CAPTCHA_ANBIETER) {
            return $verdict; // nicht zustaendig - fremdes Urteil durchreichen.
        }

        $antwort = strtolower(trim((string)($input['beispiel_wort'] ?? '')));
        return $antwort === self::CAPTCHA_LOESUNG ? Captcha::OK : Captcha::WRONG;
    }

    // -----------------------------------------------------------------
    // Erweiterungspunkte, die keine Hooks sind
    // -----------------------------------------------------------------

    /**
     * Eigene Routen. Der Kern stellt ZWINGEND `/plugin/<slug>` voran - ein
     * Addon kann dadurch nie eine Kern-Route ueberschreiben oder sich als
     * Kernfunktion ausgeben.
     *
     * Callbacks sind [KlassenName::class, 'methode'] - der KLASSENNAME als
     * String, keine Objekt-Instanz. Der Router erzeugt pro Request eine
     * frische Instanz; [$this, 'methode'] wuerde deshalb NICHT die hier
     * registrierte Instanz treffen.
     *
     * ZUGRIFFSSCHUTZ IST SACHE DES ADDONS. Route-Handler laufen nicht
     * automatisch durch checkAuth()/requireAdmin() - siehe Seiten.php, wo
     * jede Klasse ihre Pruefung im Konstruktor mitbringt.
     *
     * @return array<int, array{method:string, path:string, callback:array}>
     */
    public function routes(): array {
        return [
            // Verwaltung: verlangt Anmeldung und das eigene Leserecht.
            ['method' => 'GET', 'path' => '/ereignisbuch', 'callback' => [Ereignisbuchseite::class, 'zeigen']],
            // POST aus dem Abschnitt im Pferde-Bearbeitungsformular.
            ['method' => 'POST', 'path' => '/notiz', 'callback' => [Notizablage::class, 'speichern']],
            // POST aus dem Abschnitt im Kontakt-Bearbeitungsformular.
            ['method' => 'POST', 'path' => '/kontaktnotiz', 'callback' => [Notizablage::class, 'kontaktnotiz']],
            // POST fuer die eigene Einstellung (owns.settings).
            ['method' => 'POST', 'path' => '/sperrwort', 'callback' => [Notizablage::class, 'sperrwortSetzen']],
            // Oeffentlich, aber ueber die Zusatzfunktion sichtbarkeitsgesteuert.
            ['method' => 'GET', 'path' => '/schaufenster', 'callback' => [Schaufenster::class, 'zeigen']],
            // Oeffentliches Formular - fuehrt den eigenen Captcha-Kontext vor.
            ['method' => 'GET', 'path' => '/probeformular', 'callback' => [Probeformular::class, 'zeigen']],
            ['method' => 'POST', 'path' => '/probeformular', 'callback' => [Probeformular::class, 'pruefen']],
        ];
    }

    /**
     * Eigene Berechtigungen (Kern-#66). Beide Bauarten sind hier vertreten:
     *
     * 1. Eine neue AKTION an einem bestehenden KERN-Modul. Erscheint als
     *    zusaetzliches Haekchen unter "Pferde" in /admin/groups, ohne dass der
     *    Kern angefasst werden muesste.
     * 2. Ein eigenes MODUL. Dafuer ist module_label noetig, weil das Modul
     *    noch nicht existiert.
     *
     * Zu 2.: Registriert wird ausdruecklich `view` - jedes Modul bekommt view
     * und publish ohnehin automatisch, ein Addon MUSS sie nicht anmelden. Wer
     * eine eigene Beschriftung will, meldet sie trotzdem an; das greift nur,
     * solange niemand zuvor dieselbe Kombination registriert hat ("wer zuerst
     * registriert, gewinnt"). Genau diese Leitplanke verhindert, dass ein
     * Addon die Bedeutung einer bestehenden Berechtigung umdefiniert.
     *
     * DIE REGISTRIERUNG SCHALTET NICHTS FREI. Sie sorgt nur dafuer, dass ein
     * Administrator die Aktion in der Matrix sieht. Standard ist: keiner
     * Gruppe zugewiesen - fail-closed. Durchgesetzt wird sie vom Addon selbst.
     *
     * @return array<int, array<string, string>>
     */
    public function permissions(): array {
        return [
            [
                'module' => 'horses',
                'action' => 'beispielnotiz',
                'label' => 'Beispiel-Notiz pflegen',
            ],
            [
                'module' => self::MODUL,
                'action' => 'view',
                'label' => 'Ereignisbuch einsehen',
                'module_label' => 'Beispiel: Erweiterungspunkte',
            ],
            [
                // Und eine eigene AKTION am eigenen Modul - die dritte
                // Bauart. module_label steht hier nicht mehr: Das Modul gibt
                // es dann bereits, und der Kern ignoriert die Angabe.
                'module' => self::MODUL,
                'action' => 'notiz',
                'label' => 'Beispiel-Notizen und Sperrwort pflegen',
            ],
        ];
    }

    /**
     * Zusatzfunktion mit admin-konfigurierbarer Sichtbarkeit (Kern-#57).
     *
     * Der Betreiber waehlt unter /admin/system-settings zwischen "Oeffentlich"
     * und "Nur fuer Gruppen mit Leseberechtigung". Solange er nichts waehlt,
     * gilt default_visibility - und der Standard ist bewusst `members`:
     * fail-closed, damit eine neue Funktion nicht ungefragt oeffentlich
     * erscheint.
     *
     * Durchgesetzt wird das vom Addon in der eigenen Route ueber
     * App\Permission\FeatureGate::isVisible() - siehe Schaufenster.
     *
     * @return array<int, array{key:string, label:string, default_visibility:string}>
     */
    public function features(): array {
        return [
            [
                'key' => self::FEATURE,
                'label' => 'Beispiel: Schaufenster-Seite',
                'default_visibility' => 'members',
            ],
        ];
    }

    /**
     * Meldet den eigenen Formular-Kontext an (Kern-#351).
     *
     * WOZU: Captcha::renderField()/verify() nehmen seit jeher einen $context
     * entgegen, aber bis v0.8 gab es nur 'dsgvo' und keinen Weg fuer ein
     * Addon, einen eigenen anzumelden. Die oeffentlichen Formulare dieses
     * Systems liegen ueberwiegend in Addons - genau die, die Spam bekommen.
     *
     * Mit dem Eintrag bekommt der Betreiber je Formular eine eigene
     * Anbieterwahl in den Systemeinstellungen. Ohne Eintrag gilt die globale
     * Wahl.
     *
     * FAIL-CLOSED, ABER RICHTIG HERUM: Ein NICHT angemeldeter Kontext schaltet
     * den Schutz nicht ab - er zwingt auf den eingebauten Anbieter zurueck und
     * wird protokolliert. Ein Tippfehler macht ein Formular hoechstens
     * strenger, nie ungeschuetzter.
     *
     * @return array<string, string>
     */
    public function captchaContexts(): array {
        return [
            self::CAPTCHA_KONTEXT => 'Beispiel-Addon: Probeformular',
        ];
    }

    // -----------------------------------------------------------------

    /**
     * Dieselbe Pruefung wie BaseController::hasPermission(), nur ausserhalb
     * eines Controllers: Hook-Rueckrufe bekommen keine Controller-Instanz, und
     * GroupMembership ist der dokumentierte Weg dafuer (fail-closed bei
     * fehlender Zeile oder DB-Fehler).
     */
    public static function darf(string $modul, string $aktion): bool {
        // Die Sitzung fuehrt die Kennung nicht zwingend als int; ohne
        // Anmeldung ist sie gar nicht da. null heisst "Gast", und fuer den
        // entscheidet die Gast-Gruppe - nicht ein stillschweigendes 0.
        $kennung = $_SESSION['user_id'] ?? null;

        return GroupMembership::hasPermission(
            $kennung === null ? null : (int)$kennung,
            $modul,
            $aktion
        );
    }
}
