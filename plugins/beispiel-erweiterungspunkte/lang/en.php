<?php
// plugins/beispiel-erweiterungspunkte/lang/en.php
//
// English counterpart of lang/de.php. Same flat key space, same placeholders -
// see the German file for why this needs no manifest entry.

return [
    'nav_label' => 'Example showcase',
    'kachel_label' => 'Example: extension points',

    'detail_ueberschrift' => 'Example add-on: section on the horse page',
    'detail_ohne_notiz' => 'No example note stored for this horse.',
    'detail_station' => 'Breeding station according to publicly released data: {station}',
    'detail_pedigree' => 'The core hands the pedigree tree in already built (root: {name}).',

    'kontakt_ueberschrift' => 'Example add-on: section on the contact page',
    'kontakt_email_frei' => 'This contact released its delivery details publicly (contact_public).',
    'kontakt_email_gesperrt' => 'This contact did not release its delivery details - e-mail, phone and address are absent from the hook payload and must not be fetched separately.',
    'kontakt_pferde' => 'Linked as breeder to {zuechter} horse(s), as breeding station to {station}.',

    'home_oben_ueberschrift' => 'Example add-on: section at the top of the home page',
    'home_oben_hinweis' => 'The photo comes from App\\Helper\\MediaUrl, not from the raw image_url column.',
    'home_unten' => 'Example add-on: {anzahl} hook event(s) recorded.',

    'edit_ueberschrift' => 'Example note for this horse',
    'edit_hinweis' => 'Own form with its own route - the core save button above does NOT store this field. Save master data first. An empty field deletes the note.',
    'edit_feld' => 'Note (shown publicly on the horse page)',
    'edit_knopf' => 'Apply example note',

    'kontakt_edit_ueberschrift' => 'Example note for this contact',
    'kontakt_edit_hinweis' => 'The core has no column for this - the add-on keeps it in its own table. {anzahl} hook event(s) recorded for this contact so far.',
    'kontakt_edit_feld' => 'Internal note',
    'kontakt_edit_knopf' => 'Apply contact note',

    'veto_grund' => 'Example add-on: the note of this horse contains the blocking word "{wort}". The record was saved but not published.',

    'captcha_anbieter' => 'Example: word check (demonstration only, NOT spam protection)',
    'captcha_frage' => 'Which animal does this directory manage? (answer: Pferd)',
    'captcha_hinweis' => 'This check is deliberately trivial - it only shows the shape of a custom provider.',

    'tafel_ueberschrift' => 'Coverage: which hook has fired yet?',
    'tafel_spalte_anzahl' => 'Fired',
    'buch_ueberschrift' => 'Event log (newest first)',
    'buch_leer' => 'No event recorded yet. Create a horse or open the home page.',
    'buch_spalte_zeit' => 'Time',
    'buch_spalte_bezug' => 'Subject',
    'buch_spalte_notiz' => 'Remark',
    'sperrwort_ueberschrift' => 'Blocking word for the publication veto',
    'sperrwort_hinweis' => 'If a horse note contains this word, horse.publish_blockers prevents publication - the record is still saved.',
    'sperrwort_feld' => 'Blocking word',
    'sperrwort_knopf' => 'Apply blocking word',
    'probe_ueberschrift' => 'Sample form',
    'probe_text' => 'This form stores nothing. It only demonstrates how an add-on registers its own form context and provides its own spam protection provider.',
    'probe_knopf' => 'Submit',
];
