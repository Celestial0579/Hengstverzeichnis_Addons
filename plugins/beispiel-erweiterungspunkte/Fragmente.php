<?php
// plugins/beispiel-erweiterungspunkte/Fragmente.php
//
// Die HTML-Bausteine des Lehrbeispiels (Addons#128).
//
// WARUM ALLE ABSCHNITTE HIER STEHEN UND NICHT IN Plugin.php: Die Hook-Datei
// soll man lesen koennen, ohne durch Markup zu waten. Der zweite Grund ist
// wichtiger - alle *_sections-Hooks geben einen HTML-String zurueck, der vom
// Kern UNESCAPED ausgegeben wird. Das Escaping ist damit vollstaendig Sache
// des Addons, und es ist leichter nachzuweisen, wenn es an einer Stelle
// gebuendelt ist.
//
// ZWEI REGELN, DIE HIER OHNE AUSNAHME GELTEN:
//
// 1. Jeder Fremdwert geht durch e(). Bei den Admin-Abschnitten wiegt das
//    SCHWERER als auf der oeffentlichen Seite, nicht leichter: Ein XSS hinter
//    dem Login trifft Redakteure und Administratoren mit vollen Rechten.
// 2. Farben, Radien und Schrift kommen ausschliesslich aus den
//    Theme-Variablen des Kerns (var(--text-muted), var(--border-radius), ...).
//    Rohe Hex-Werte brechen den Darkmode oder die Markenfarbe des Betreibers -
//    das war die Ursache der Theming-Drift, die dieses Repo drei Runden
//    gekostet hat (Addons#66), und der statische Lint unter
//    tests/Manifest/PluginThemingLintTest.php faengt sie seither ab.

namespace Plugin\BeispielErweiterungspunkte;

use App\Router;

final class Fragmente {

    /** Gemeinsamer Rahmen aller Abschnitte - erkennbar und wiedererkennbar. */
    private const KASTEN = 'margin-top:1.5rem;padding:1rem;'
        . 'border:1px dashed var(--border-color);border-radius:var(--border-radius);'
        . 'background:var(--surface-muted);';

    private function __construct() {}

    /** Kurzform fuer htmlspecialchars mit den richtigen Vorgaben. */
    public static function e(?string $wert): string {
        return htmlspecialchars((string)$wert, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // -----------------------------------------------------------------
    // Oeffentliche Abschnitte
    // -----------------------------------------------------------------

    /**
     * horse.detail_sections
     *
     * @param array<string, mixed>|null $pedigree Der bereits berechnete
     *   6-Generationen-Baum. Er wird HEREINGEREICHT - ein Addon, das eine
     *   Abstammungsangabe braucht, muss ihn nicht erneut bauen. Wer eine
     *   groessere Tiefe braucht, nimmt App\Service\PedigreeBuilder::build()
     *   und uebergibt dort $publishedOnly = true, sonst leaken
     *   unveroeffentlichte Vorfahren.
     */
    public static function pferdeDetail(?string $notiz, ?array $pedigree, ?string $stationName): string {
        $html = '<div data-beispiel="horse-detail" style="' . self::KASTEN . '">'
            . '<h3 style="margin-top:0;">🧪 ' . self::e(t('detail_ueberschrift')) . '</h3>';

        $html .= $notiz === null
            ? '<p style="color:var(--text-muted);">' . self::e(t('detail_ohne_notiz')) . '</p>'
            : '<p data-beispiel="notiz">' . self::e($notiz) . '</p>';

        // Der Beleg fuer den Datenvertrag der oeffentlichen Hooks: Geprueft
        // wurde in Plugin::detailAbschnitt() das FELD station_name, nicht die
        // Verknuepfung breeding_station_id. Die ist auch dann gesetzt, wenn
        // die Station unveroeffentlicht, geloescht oder fuer Gaeste gesperrt
        // ist - dann sind alle station_*-Felder null.
        if ($stationName !== null) {
            $html .= '<p style="color:var(--text-muted);font-size:0.9em;">'
                . self::e(t('detail_station', ['station' => $stationName])) . '</p>';
        }

        if ($pedigree !== null) {
            $html .= '<p style="color:var(--text-muted);font-size:0.9em;">'
                . self::e(t('detail_pedigree', ['name' => (string)($pedigree['name'] ?? '?')]))
                . '</p>';
        }

        return $html . '</div>';
    }

    /**
     * catalog.card_sections - bewusst winzig. Der Filter laeuft je Karte, bis
     * zu 24-mal pro Seitenaufruf; was hier steht, steht 24-mal auf der Seite.
     */
    public static function katalogAbzeichen(string $notiz): string {
        return '<p data-beispiel="katalog" style="margin:0.35rem 0;font-size:0.85em;color:var(--text-muted);">'
            . '🧪 ' . self::e(Ereignisbuch::kurz($notiz, 60)) . '</p>';
    }

    /**
     * contact.detail_sections
     *
     * Zeigt ausdruecklich KEINE Kontaktdaten an, sondern nur, OB der Datensatz
     * sie freigegeben hat. Genau das ist die Lehre: email/phone/Anschrift
     * fehlen im Payload, solange contact_public nicht gesetzt ist - und ein
     * Addon darf sie dann auch nicht per eigener Abfrage nachladen.
     */
    public static function kontaktDetail(bool $emailFreigegeben, int $alsZuechter, int $alsStation): string {
        return '<div data-beispiel="contact-detail" style="' . self::KASTEN . '">'
            . '<h3 style="margin-top:0;">🧪 ' . self::e(t('kontakt_ueberschrift')) . '</h3>'
            . '<p>' . self::e(t(
                $emailFreigegeben ? 'kontakt_email_frei' : 'kontakt_email_gesperrt'
            )) . '</p>'
            . '<p style="color:var(--text-muted);font-size:0.9em;">'
            . self::e(t('kontakt_pferde', [
                'zuechter' => (string)$alsZuechter,
                'station' => (string)$alsStation,
            ]))
            . '</p></div>';
    }

    /** home.sections_top - mit Foto ueber App\Helper\MediaUrl, nie ueber image_url. */
    public static function startseiteOben(int $horseId, string $name, ?string $bildUrl): string {
        $html = '<div data-beispiel="home-top" style="' . self::KASTEN . '">'
            . '<h2 style="margin-top:0;">🧪 ' . self::e(t('home_oben_ueberschrift')) . '</h2>';

        if ($bildUrl !== null) {
            // FALLE: Hier stuende beinahe $horse['image_url']. Der rohe
            // Spaltenwert funktioniert - aber am Anwendungscode vorbei und
            // damit ohne den Einbettungsschutz des Kerns.
            $html .= '<img src="' . self::e($bildUrl) . '" alt="' . self::e($name) . '"'
                . ' style="max-width:180px;height:auto;border-radius:var(--border-radius);">';
        }

        return $html
            . '<p><a href="/horse?id=' . $horseId . '">' . self::e($name) . '</a></p>'
            . '<p style="color:var(--text-muted);font-size:0.9em;">' . self::e(t('home_oben_hinweis')) . '</p>'
            . '</div>';
    }

    /** home.sections_bottom */
    public static function startseiteUnten(int $ereignisse): string {
        return '<div data-beispiel="home-bottom" style="' . self::KASTEN . '">'
            . '<p style="margin:0;">🧪 '
            . self::e(t('home_unten', ['anzahl' => (string)$ereignisse]))
            . ' <a href="' . Plugin::BASIS . '/schaufenster">' . self::e(t('nav_label')) . '</a></p>'
            . '</div>';
    }

    // -----------------------------------------------------------------
    // Admin-Abschnitte: eigenes Formular, eigene Route, eigener Knopf
    // -----------------------------------------------------------------

    /**
     * horse.edit_sections
     *
     * DREI DINGE, DIE MAN HIER SEHEN SOLL:
     *
     * 1. Ein EIGENES <form> mit eigener action. Verschachtelte <form> sind
     *    ungueltiges HTML, deshalb rendert der Kern diesen Abschnitt
     *    ausserhalb seines Formulars - und deshalb speichert sein
     *    Speichern-Knopf hier nichts mit.
     * 2. Der eigene Knopf heisst NICHT "Speichern", sondern nennt die
     *    Handlung. Auf der Seite gibt es zwei Knoepfe; wer oben die Stammdaten
     *    aendert und dann unten diesen drueckt, verliert die
     *    Stammdaten-Aenderung. Der Hinweis darauf steht direkt daneben.
     * 3. Das eigene CSRF-Token. Die POST-Route des Addons prueft es selbst -
     *    Route-Handler laufen nicht automatisch durch die Kern-Pruefungen.
     *
     * Und eine vierte Falle, die dieses Repo mehrfach gekostet hat, auch wenn
     * sie hier nicht zuschlaegt: Nimmt das eigene Formular DATEIEN entgegen,
     * braucht es enctype="multipart/form-data". Ohne das kommt $_FILES leer
     * an, und der Fehler sieht aus wie "die Datei ist zu gross".
     */
    public static function pferdeBearbeiten(int $horseId, ?string $notiz): string {
        $token = Router::generateCsrfToken();

        return '<div class="card" data-beispiel="horse-edit" style="margin-top:1.5rem;">'
            . '<h3 style="margin-top:0;">🧪 ' . self::e(t('edit_ueberschrift')) . '</h3>'
            . '<p style="color:var(--text-muted);font-size:0.9em;">' . self::e(t('edit_hinweis')) . '</p>'
            . '<form method="POST" action="' . Plugin::BASIS . '/notiz">'
            . '<input type="hidden" name="csrf_token" value="' . self::e($token) . '">'
            . '<input type="hidden" name="horse_id" value="' . $horseId . '">'
            . '<div class="form-group">'
            . '<label for="beispiel_notiz_' . $horseId . '">' . self::e(t('edit_feld')) . '</label>'
            . '<input type="text" class="form-control" maxlength="255"'
            . ' id="beispiel_notiz_' . $horseId . '" name="notiz" value="' . self::e($notiz) . '">'
            . '</div>'
            . '<button type="submit" class="btn">' . self::e(t('edit_knopf')) . '</button>'
            . '</form></div>';
    }

    /**
     * contact.edit_sections - dieselbe Bauart wie beim Pferd.
     *
     * Der Nutzen dieses Hooks ist genau der: Ein Addon pflegt eine eigene
     * Angabe am Kontakt, OHNE dass der Kern dafuer eine Spalte mitbringen
     * muesste. Der uebliche Fall in diesem Repo ist ein Opt-out gegen
     * Kontaktanfragen.
     */
    public static function kontaktBearbeiten(int $contactId, ?string $notiz, int $ereignisse): string {
        $token = Router::generateCsrfToken();

        return '<div class="card" data-beispiel="contact-edit" style="margin-top:1.5rem;">'
            . '<h3 style="margin-top:0;">🧪 ' . self::e(t('kontakt_edit_ueberschrift')) . '</h3>'
            . '<p style="color:var(--text-muted);font-size:0.9em;">'
            . self::e(t('kontakt_edit_hinweis', ['anzahl' => (string)$ereignisse])) . '</p>'
            . '<form method="POST" action="' . Plugin::BASIS . '/kontaktnotiz">'
            . '<input type="hidden" name="csrf_token" value="' . self::e($token) . '">'
            . '<input type="hidden" name="contact_id" value="' . $contactId . '">'
            . '<div class="form-group">'
            . '<label for="beispiel_knotiz_' . $contactId . '">' . self::e(t('kontakt_edit_feld')) . '</label>'
            . '<input type="text" class="form-control" maxlength="255"'
            . ' id="beispiel_knotiz_' . $contactId . '" name="notiz" value="' . self::e($notiz) . '">'
            . '</div>'
            . '<button type="submit" class="btn">' . self::e(t('kontakt_edit_knopf')) . '</button>'
            . '</form></div>';
    }

    // -----------------------------------------------------------------
    // Spam-Schutz
    // -----------------------------------------------------------------

    /**
     * captcha.render - ein FRAGMENT, das in das bestehende Formular
     * eingesetzt wird. Kein eigenes <form>, kein zweiter Schritt: Der Besucher
     * fuellt ein Formular aus und schickt es in einem Zug ab.
     *
     * $context wird nur angezeigt, damit man beim Ausprobieren sieht, dass der
     * Anbieter je Formular waehlbar ist (Kern-#351).
     */
    public static function captchaFeld(string $context): string {
        return '<div class="form-group" data-beispiel="captcha" data-kontext="' . self::e($context) . '">'
            . '<label for="beispiel_wort">' . self::e(t('captcha_frage')) . '</label>'
            . '<input type="text" class="form-control" id="beispiel_wort" name="beispiel_wort"'
            . ' autocomplete="off" maxlength="40" required style="max-width:16rem;">'
            . '<small class="form-hint">' . self::e(t('captcha_hinweis')) . '</small>'
            . '</div>';
    }

    // -----------------------------------------------------------------
    // Bausteine der eigenen Seiten
    // -----------------------------------------------------------------

    /**
     * Die Abdeckungstafel: jeder registrierte Hook mit der Zahl seiner
     * bisherigen Ausloesungen. Wer das Addon ausprobiert, sieht hier, was er
     * noch nicht angefasst hat.
     *
     * @param array<string, int> $haeufigkeiten
     */
    public static function abdeckungstafel(array $haeufigkeiten): string {
        $html = '<div class="card"><h2 style="margin-top:0;">' . self::e(t('tafel_ueberschrift')) . '</h2>'
            . '<div style="overflow-x:auto;"><table class="table"><thead><tr>'
            . '<th>Hook</th><th>Art</th><th style="text-align:right;">' . self::e(t('tafel_spalte_anzahl')) . '</th>'
            . '</tr></thead><tbody>';

        foreach (Plugin::AKTIONEN as $hook => $_methode) {
            $html .= self::tafelZeile($hook, 'Action', $haeufigkeiten[$hook] ?? 0);
        }
        foreach (Plugin::FILTER as $hook => $_methode) {
            $html .= self::tafelZeile($hook, 'Filter', $haeufigkeiten[$hook] ?? 0);
        }

        return $html . '</tbody></table></div></div>';
    }

    private static function tafelZeile(string $hook, string $art, int $anzahl): string {
        return '<tr data-hook="' . self::e($hook) . '">'
            . '<td><code>' . self::e($hook) . '</code></td>'
            . '<td>' . self::e($art) . '</td>'
            . '<td style="text-align:right;">' . $anzahl . '</td></tr>';
    }

    /**
     * Das Ereignisbuch selbst.
     *
     * @param array<int, array<string, mixed>> $eintraege
     */
    public static function ereignisliste(array $eintraege): string {
        if ($eintraege === []) {
            return '<div class="card"><p style="margin:0;color:var(--text-muted);">'
                . self::e(t('buch_leer')) . '</p></div>';
        }

        $html = '<div class="card"><h2 style="margin-top:0;">' . self::e(t('buch_ueberschrift')) . '</h2>'
            . '<div style="overflow-x:auto;"><table class="table"><thead><tr>'
            . '<th>' . self::e(t('buch_spalte_zeit')) . '</th><th>Hook</th>'
            . '<th>' . self::e(t('buch_spalte_bezug')) . '</th><th>' . self::e(t('buch_spalte_notiz')) . '</th>'
            . '</tr></thead><tbody>';

        foreach ($eintraege as $zeile) {
            $html .= '<tr>'
                . '<td>' . self::e((string)($zeile['created_at'] ?? '')) . '</td>'
                . '<td><code>' . self::e((string)($zeile['hook'] ?? '')) . '</code></td>'
                . '<td>' . self::e((string)($zeile['bezug'] ?? '')) . '</td>'
                . '<td>' . self::e((string)($zeile['notiz'] ?? '')) . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table></div></div>';
    }

    /** Das Formular fuer die eigene Einstellung (owns.settings). */
    public static function sperrwortFormular(string $sperrwort): string {
        $token = Router::generateCsrfToken();

        return '<div class="card"><h2 style="margin-top:0;">' . self::e(t('sperrwort_ueberschrift')) . '</h2>'
            . '<p style="color:var(--text-muted);">' . self::e(t('sperrwort_hinweis')) . '</p>'
            . '<form method="POST" action="' . Plugin::BASIS . '/sperrwort">'
            . '<input type="hidden" name="csrf_token" value="' . self::e($token) . '">'
            . '<div class="form-group">'
            . '<label for="beispiel_sperrwort">' . self::e(t('sperrwort_feld')) . '</label>'
            . '<input type="text" class="form-control" id="beispiel_sperrwort" name="sperrwort"'
            . ' maxlength="40" value="' . self::e($sperrwort) . '" style="max-width:20rem;">'
            . '</div>'
            . '<button type="submit" class="btn">' . self::e(t('sperrwort_knopf')) . '</button>'
            . '</form></div>';
    }

    /** Das oeffentliche Probeformular hinter dem eigenen Captcha-Kontext. */
    public static function probeformular(string $captchaFragment, ?string $meldung): string {
        $token = Router::generateCsrfToken();

        $html = '<div class="card"><h1 style="margin-top:0;">🧪 ' . self::e(t('probe_ueberschrift')) . '</h1>'
            . '<p>' . self::e(t('probe_text')) . '</p>';

        if ($meldung !== null) {
            $html .= '<p data-beispiel="probe-ergebnis" style="font-weight:600;">' . self::e($meldung) . '</p>';
        }

        return $html
            . '<form method="POST" action="' . Plugin::BASIS . '/probeformular">'
            . '<input type="hidden" name="csrf_token" value="' . self::e($token) . '">'
            . $captchaFragment
            . '<button type="submit" class="btn">' . self::e(t('probe_knopf')) . '</button>'
            . '</form></div>';
    }
}

/**
 * Kurzform fuer App\I18n\Translator::t() mit der eigenen Domain.
 *
 * DOMAIN = SLUG. Der Kern registriert lang/<locale>.php eines Addons
 * automatisch unter dessen Slug - dadurch hat jedes Addon seinen eigenen
 * flachen Schluesselraum und kann keine Kern-Schluessel ueberschreiben. Fehlt
 * ein Schluessel, gibt Translator::t() den Schluessel selbst zurueck, nie eine
 * leere Zeichenkette: Fehlende Uebersetzungen fallen beim Testen sofort
 * optisch auf, statt lautlos zu verschwinden.
 *
 * @param array<string, string> $platzhalter
 */
function t(string $schluessel, array $platzhalter = []): string {
    return \App\I18n\Translator::t($schluessel, $platzhalter, Plugin::SLUG);
}
