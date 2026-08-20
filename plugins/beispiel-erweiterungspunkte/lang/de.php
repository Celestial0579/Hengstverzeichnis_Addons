<?php
// plugins/beispiel-erweiterungspunkte/lang/de.php
//
// Uebersetzungen dieses Addons (Kern-#48). Es braucht dafuer KEINE
// Manifest-Angabe: Der Kern registriert lang/<locale>.php automatisch unter
// dem Slug des Addons als eigene Domain. Dadurch hat jedes Addon seinen
// eigenen flachen Schluesselraum und kann weder Kern-Schluessel noch die
// eines anderen Addons ueberschreiben ("wer zuerst registriert, gewinnt").
//
// Fehlt ein Schluessel, gibt Translator::t() den SCHLUESSEL zurueck, nie eine
// leere Zeichenkette - fehlende Uebersetzungen fallen beim Ausprobieren sofort
// optisch auf, statt lautlos zu verschwinden.
//
// Platzhalter in geschweiften Klammern: {name}.

return [
    // Navigation und Dashboard
    'nav_label' => 'Beispiel-Schaufenster',
    'kachel_label' => 'Beispiel: Erweiterungspunkte',

    // horse.detail_sections
    'detail_ueberschrift' => 'Beispiel-Addon: Abschnitt auf der Pferdeseite',
    'detail_ohne_notiz' => 'Für dieses Pferd ist keine Beispiel-Notiz hinterlegt.',
    'detail_station' => 'Deckstation laut öffentlich freigegebenen Daten: {station}',
    'detail_pedigree' => 'Der Abstammungsbaum wird vom Kern bereits berechnet mitgeliefert (Wurzel: {name}).',

    // contact.detail_sections
    'kontakt_ueberschrift' => 'Beispiel-Addon: Abschnitt auf der Kontaktseite',
    'kontakt_email_frei' => 'Dieser Kontakt hat seine Zustelldaten öffentlich freigegeben (contact_public).',
    'kontakt_email_gesperrt' => 'Dieser Kontakt hat seine Zustelldaten nicht freigegeben - E-Mail, Telefon und Anschrift fehlen im Hook-Payload und dürfen auch nicht nachgeladen werden.',
    'kontakt_pferde' => 'Verknüpft als Züchter mit {zuechter} Pferd(en), als Deckstation mit {station}.',

    // home.sections_top / home.sections_bottom
    'home_oben_ueberschrift' => 'Beispiel-Addon: Abschnitt oben auf der Startseite',
    'home_oben_hinweis' => 'Das Foto kommt über App\\Helper\\MediaUrl, nicht über den rohen Spaltenwert image_url.',
    'home_unten' => 'Beispiel-Addon: {anzahl} Hook-Ereignis(se) aufgezeichnet.',

    // horse.edit_sections
    'edit_ueberschrift' => 'Beispiel-Notiz zu diesem Pferd',
    'edit_hinweis' => 'Eigenes Formular mit eigener Route - der Speichern-Knopf des Kerns oben speichert dieses Feld NICHT mit. Stammdaten-Änderungen also zuerst oben speichern. Leeres Feld löscht die Notiz.',
    'edit_feld' => 'Notiz (erscheint öffentlich auf der Pferdeseite)',
    'edit_knopf' => 'Beispiel-Notiz übernehmen',

    // contact.edit_sections
    'kontakt_edit_ueberschrift' => 'Beispiel-Notiz zu diesem Kontakt',
    'kontakt_edit_hinweis' => 'Der Kern bringt für diese Angabe keine Spalte mit - das Addon führt sie in einer eigenen Tabelle. Bisher {anzahl} Hook-Ereignis(se) zu diesem Kontakt.',
    'kontakt_edit_feld' => 'Interne Notiz',
    'kontakt_edit_knopf' => 'Kontakt-Notiz übernehmen',

    // horse.publish_blockers
    'veto_grund' => 'Beispiel-Addon: Die Notiz dieses Pferdes enthält das Sperrwort "{wort}". Der Datensatz wurde gespeichert, aber nicht veröffentlicht.',

    // captcha.*
    'captcha_anbieter' => 'Beispiel: Wortprobe (nur zum Vorführen, KEIN Spam-Schutz)',
    'captcha_frage' => 'Welches Tier verwaltet dieses Verzeichnis? (Antwort: Pferd)',
    'captcha_hinweis' => 'Diese Probe ist absichtlich trivial - sie zeigt nur die Form eines eigenen Anbieters.',

    // Eigene Seiten
    'tafel_ueberschrift' => 'Abdeckung: welcher Hook hat schon gefeuert?',
    'tafel_spalte_anzahl' => 'Ausgelöst',
    'buch_ueberschrift' => 'Ereignisbuch (neueste zuerst)',
    'buch_leer' => 'Noch kein Ereignis aufgezeichnet. Legen Sie ein Pferd an oder öffnen Sie die Startseite.',
    'buch_spalte_zeit' => 'Zeitpunkt',
    'buch_spalte_bezug' => 'Bezug',
    'buch_spalte_notiz' => 'Anmerkung',
    'sperrwort_ueberschrift' => 'Sperrwort für das Veröffentlichungs-Veto',
    'sperrwort_hinweis' => 'Enthält die Beispiel-Notiz eines Pferdes dieses Wort, verhindert horse.publish_blockers die Veröffentlichung - gespeichert wird trotzdem.',
    'sperrwort_feld' => 'Sperrwort',
    'sperrwort_knopf' => 'Sperrwort übernehmen',
    'probe_ueberschrift' => 'Probeformular',
    'probe_text' => 'Dieses Formular speichert nichts. Es führt nur vor, wie ein Addon einen eigenen Formular-Kontext anmeldet und einen eigenen Spam-Schutz-Anbieter bereitstellt.',
    'probe_knopf' => 'Absenden',
];
