<?php
// tests/Functional/BeispielErweiterungspunktePluginTest.php

namespace Tests\Functional;

use Tests\Support\HttpClient;

/**
 * End-to-End-Test fuer plugins/beispiel-erweiterungspunkte (Addons#128) gegen
 * eine echte, per `php -S` gestartete Framework-Instanz.
 *
 * DIESER TEST HAT ZWEI AUFGABEN, UND DIE ZWEITE IST DIE WICHTIGERE:
 *
 * 1. Er prueft das Lehrbeispiel - dass jeder Abschnitt erscheint, jede Route
 *    laeuft und jede Rechtepruefung fail-closed ist.
 * 2. Er ist damit zugleich der einzige geschlossene Nachweis, dass die Hooks
 *    des KERNS tatsaechlich feuern. Bis v0.8 prueft das niemand als Ganzes:
 *    Jedes Addon testet die zwei, drei Hooks, an denen es haengt; ob
 *    `horse.restored` ueberhaupt noch ausgeloest wird, faellt nur auf, wenn
 *    zufaellig jemand ein Addon dafuer hat.
 *
 * Deshalb wird am Ende ausdruecklich geprueft, dass JEDER registrierte Hook
 * mindestens einmal im Ereignisbuch bzw. in einer sichtbaren Wirkung
 * aufgetaucht ist - eine Zusicherung, die ein Test je Einzelfall nicht gibt.
 *
 * Die Reihenfolge der Abschnitte ist bewusst der Lebenszyklus eines Pferdes:
 * anlegen, veroeffentlichen, in den Papierkorb, zurueck, endgueltig loeschen.
 * Ein Test, der das in Haeppchen ueber mehrere Methoden verteilte, koennte
 * genau die Uebergaenge nicht pruefen.
 */
class BeispielErweiterungspunktePluginTest extends FunctionalTestCase {

    use HorseListHelper;
    use PersonStationHelper;

    private const SLUG = 'beispiel-erweiterungspunkte';
    private const BASIS = '/plugin/beispiel-erweiterungspunkte';

    /** Muss zu Plugin::CAPTCHA_ANBIETER bzw. CAPTCHA_KONTEXT passen. */
    private const CAPTCHA_ANBIETER = 'beispiel-wortprobe';
    private const CAPTCHA_KONTEXT = 'beispiel-formular';

    public function testJederErweiterungspunktWirktSichtbar(): void {
        $admin = $this->authenticatedClient();
        $this->aktivieren($admin);

        $unique = substr(uniqid(), -8);

        // -------------------------------------------------------------
        // 1. Lebenszyklus: anlegen -> before_save + after_save
        // -------------------------------------------------------------
        $horseName = "BeispielHengst-{$unique}";
        $horseId = $this->createHorse($admin, $horseName, ['status' => 'active']);

        $buch = $this->ereignisbuch($admin);
        $this->assertStringContainsString(
            'horse.before_save',
            $buch,
            'horse.before_save muss beim Anlegen feuern.'
        );
        $this->assertStringContainsString('horse.after_save', $buch);
        $this->assertStringContainsString("Pferd #{$horseId}", $buch);

        // -------------------------------------------------------------
        // 2. horse.edit_sections samt eigener POST-Route
        // -------------------------------------------------------------
        $editForm = $admin->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $editForm->statusCode);
        $this->assertStringContainsString(
            'data-beispiel="horse-edit"',
            $editForm->body,
            'Der Abschnitt aus horse.edit_sections fehlt im Bearbeitungsformular.'
        );
        // Eigenes Formular mit eigener action - nicht das Kern-Formular.
        $this->assertStringContainsString('action="' . self::BASIS . '/notiz"', $editForm->body);

        $notiz = "Schaufenster-Notiz-{$unique}";
        $antwort = $admin->post(self::BASIS . '/notiz', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'horse_id' => (string)$horseId,
            'notiz' => $notiz,
        ]);
        $this->assertSame('/admin/horses/edit?id=' . $horseId, $antwort->location());

        // Protokollierung (Kern-#352): Kategorie ist der Slug des Addons,
        // nicht "general" - genau das nimmt PluginAudit::log() ab.
        $protokoll = $admin->get('/admin/logs?category=' . self::SLUG);
        $this->assertSame(200, $protokoll->statusCode);
        $this->assertStringContainsString(
            'Beispiel-Notiz gesetzt',
            $protokoll->body,
            'Eine schreibende Addon-Aktion muss im Audit-Log unter dem eigenen Slug stehen.'
        );

        // -------------------------------------------------------------
        // 3. Oeffentliche Ausgabe: Detailseite, Katalog, Startseite, Navigation
        // -------------------------------------------------------------

        // Dem Pferd ein Foto geben. OHNE DAS prueft die Zusicherung weiter
        // unten ("nie ueber /uploads/") gar nichts: createHorse() setzt
        // horses.image_url nie, das Schaufenster rendert dann ueberhaupt kein
        // Bild, und assertStringNotContainsString('/uploads/horses/') waere
        // eine Aussage ueber eine Seite ohne Bilder. Der Aufruf von
        // MediaUrl::horseImage() im Addon liesse sich durch den rohen
        // Spaltenwert ersetzen - also genau durch die "FALLE", vor der der
        // Kommentar in Fragmente.php warnt -, ohne dass dieser Test rot wird.
        $dateiname = 'beispiel_' . uniqid() . '.jpg';
        \App\Database::getInstance()
            ->prepare('UPDATE horses SET image_url = ? WHERE id = ?')
            ->execute(['/uploads/horses/' . $dateiname, $horseId]);

        $gast = $this->newClient();

        $detail = $gast->get('/horse?id=' . $horseId);
        $this->assertSame(200, $detail->statusCode);
        $this->assertStringContainsString('data-beispiel="horse-detail"', $detail->body);
        $this->assertStringContainsString($notiz, $detail->body);

        $katalog = $gast->get('/katalog');
        $this->assertStringContainsString(
            'data-beispiel="katalog"',
            $katalog->body,
            'catalog.card_sections muss ein Abzeichen an der Karte erzeugen.'
        );

        $start = $gast->get('/');
        $this->assertStringContainsString('data-beispiel="home-top"', $start->body, 'home.sections_top fehlt.');
        $this->assertStringContainsString('data-beispiel="home-bottom"', $start->body, 'home.sections_bottom fehlt.');

        // layout.nav_items liefert DATEN, die der Kern prueft - ein falscher
        // Eintrag verschwaende still. Dass er da ist, ist deshalb der Beleg,
        // dass Pfad und Beschriftung die Pruefung bestehen.
        $this->assertStringContainsString(
            self::BASIS . '/schaufenster',
            $start->body,
            'layout.nav_items: Der Menuepunkt fehlt - hat NavItems::sanitize() ihn verworfen?'
        );

        // Das Foto laeuft ueber die geschuetzte Route, nie ueber /uploads/.
        // Beide Richtungen: Ohne die positive Zusicherung waere die negative
        // wieder inhaltsleer, sobald das Schaufenster kein Bild mehr rendert.
        $this->assertStringContainsString(
            '/media/horse-image?id=' . $horseId,
            $start->body,
            'Das Schaufenster muss das Foto ueber die geschuetzte Route einbinden - sonst prueft die Zeile darunter nichts.'
        );
        $this->assertStringNotContainsString('/uploads/horses/', $start->body);

        // -------------------------------------------------------------
        // 4. horse.search_ids - null gegen leere Liste
        // -------------------------------------------------------------
        $treffer = $gast->get('/katalog?beispiel_notiz=' . urlencode($unique));
        $this->assertStringContainsString(
            $horseName,
            $treffer->body,
            'horse.search_ids muss auf die Pferde mit passender Notiz einschraenken.'
        );

        $leer = $gast->get('/katalog?beispiel_notiz=' . urlencode('gibtesnicht-' . $unique));
        $this->assertStringNotContainsString(
            $horseName,
            $leer->body,
            'Eine leere Trefferliste ([]) darf NICHT wie "nichts beizutragen" (null) wirken.'
        );

        // -------------------------------------------------------------
        // 5. horse.publish_blockers - Veto gegen das VEROEFFENTLICHEN,
        //    nicht gegen das Speichern
        // -------------------------------------------------------------
        $gesperrt = "SPERRE Grund-{$unique}";
        $admin->post(self::BASIS . '/notiz', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'horse_id' => (string)$horseId,
            'notiz' => $gesperrt,
        ]);

        $updateForm = $admin->get('/admin/horses/edit?id=' . $horseId);
        $update = $admin->post('/admin/horses/update', [
            'csrf_token' => $updateForm->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => $horseName,
            'is_published' => '1',
        ]);
        $this->assertSame(
            '/admin/horses?success=updated_not_published',
            $update->location(),
            'Das Addon-Veto muss die Veroeffentlichung verhindern - und den Datensatz trotzdem speichern.'
        );

        // Gespeichert, aber nicht oeffentlich: die Arbeit geht nicht verloren.
        $this->assertSame(404, $gast->get('/horse?id=' . $horseId)->statusCode);
        $this->assertStringContainsString(
            $gesperrt,
            $admin->get('/admin/horses/edit?id=' . $horseId)->body,
            'Der Datensatz muss gespeichert sein, nur das Haekchen faellt.'
        );

        // Veto aufheben und wieder veroeffentlichen.
        $admin->post(self::BASIS . '/notiz', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'horse_id' => (string)$horseId,
            'notiz' => $notiz,
        ]);
        $updateForm = $admin->get('/admin/horses/edit?id=' . $horseId);
        $update = $admin->post('/admin/horses/update', [
            'csrf_token' => $updateForm->formField('csrf_token') ?? '',
            'id' => (string)$horseId,
            'name' => $horseName,
            'is_published' => '1',
        ]);
        $this->assertSame('/admin/horses?success=updated', $update->location());
        $this->assertSame(200, $gast->get('/horse?id=' . $horseId)->statusCode);

        // -------------------------------------------------------------
        // 6. Kontakt-Hooks
        // -------------------------------------------------------------
        $contactName = "BeispielKontakt-{$unique}";
        $contactId = $this->createContact($admin, $contactName);

        $buch = $this->ereignisbuch($admin);
        $this->assertStringContainsString('contact.after_save', $buch);
        $this->assertStringContainsString("Kontakt #{$contactId}", $buch);

        $contactForm = $admin->get('/admin/contacts/edit?id=' . $contactId);
        $this->assertStringContainsString(
            'data-beispiel="contact-edit"',
            $contactForm->body,
            'Der Abschnitt aus contact.edit_sections fehlt.'
        );

        $admin->post(self::BASIS . '/kontaktnotiz', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'contact_id' => (string)$contactId,
            'notiz' => "Kontaktvermerk-{$unique}",
        ]);

        $kontaktSeite = $gast->get('/kontakt?id=' . $contactId);
        $this->assertSame(200, $kontaktSeite->statusCode);
        $this->assertStringContainsString(
            'data-beispiel="contact-detail"',
            $kontaktSeite->body,
            'Der Abschnitt aus contact.detail_sections fehlt.'
        );
        // Der Kontakt hat contact_public NICHT gesetzt - der Abschnitt muss
        // das melden und darf keine Zustelldaten zeigen.
        $this->assertStringContainsString('nicht freigegeben', $kontaktSeite->body);

        // Und ausdruecklich NICHT doppelt: Der Kern feuert person./station.*
        // als Alias hinterher; ein Addon, das beide registriert, bekaeme
        // seinen Abschnitt mehrfach auf derselben Seite.
        $this->assertSame(
            1,
            substr_count($kontaktSeite->body, 'data-beispiel="contact-detail"'),
            'Der Kontakt-Abschnitt darf genau einmal erscheinen - sonst sind die person./station.-Aliasse mitregistriert.'
        );

        $loeschen = $admin->post('/admin/contacts/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$contactId,
        ]);
        $this->assertNotNull($loeschen->location());
        $this->assertStringContainsString(
            'contact.deleted',
            $this->ereignisbuch($admin),
            'contact.deleted muss beim Verschieben in den Papierkorb feuern.'
        );

        // -------------------------------------------------------------
        // 7. Papierkorb: before_delete -> trashed -> restored -> deleted
        // -------------------------------------------------------------
        $admin->post('/admin/horses/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$horseId,
        ]);
        $buch = $this->ereignisbuch($admin);
        $this->assertStringContainsString('horse.before_delete', $buch);
        $this->assertStringContainsString('horse.trashed', $buch);
        $this->assertStringContainsString(
            'stillgelegt',
            $buch,
            'Beim Soft-Delete greift kein CASCADE - eigene Daten muessen stillgelegt werden.'
        );

        // Stillgelegt heisst oeffentlich unsichtbar, aber nicht geloescht.
        $wiederher = $admin->post('/admin/trash/restore', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'type' => 'horse',
            'id' => (string)$horseId,
        ]);
        $this->assertSame('/admin/trash?success=restored', $wiederher->location());
        $this->assertStringContainsString(
            'horse.restored',
            $this->ereignisbuch($admin),
            'horse.restored muss nach der Wiederherstellung feuern.'
        );
        $this->assertStringContainsString(
            $notiz,
            $admin->get('/admin/horses/edit?id=' . $horseId)->body,
            'Die stillgelegte Notiz muss nach dem Wiederherstellen zurueck sein.'
        );

        // Endgueltig: erst in den Papierkorb, dann loeschen (Admin darf sofort).
        $admin->post('/admin/horses/delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$horseId,
        ]);
        $endgueltig = $admin->post('/admin/trash/permanent-delete', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'type' => 'horse',
            'id' => (string)$horseId,
        ]);
        $this->assertNotNull($endgueltig->location());

        $buch = $this->ereignisbuch($admin);
        $this->assertStringContainsString('horse.deleted', $buch);
        $this->assertStringContainsString(
            'endgueltig - letzte Gelegenheit zu lesen',
            $buch,
            'horse.before_delete muss mit $permanent = true erneut feuern.'
        );

        // -------------------------------------------------------------
        // 8. Spam-Schutz: Anbieter, Kontext, Fragment, Urteil
        // -------------------------------------------------------------
        $systemSettings = $admin->get('/admin/system-settings');
        $this->assertStringContainsString(
            self::CAPTCHA_ANBIETER,
            $systemSettings->body,
            'captcha.providers: Der eigene Anbieter muss in den Systemeinstellungen waehlbar sein.'
        );
        $this->assertStringContainsString(
            self::CAPTCHA_KONTEXT,
            $systemSettings->body,
            'captchaContexts(): Der eigene Formular-Kontext muss dort erscheinen.'
        );

        // Ohne Zuweisung gilt der eingebaute Anbieter - der Kern prueft selbst.
        $vorher = $gast->get(self::BASIS . '/probeformular');
        $this->assertSame(200, $vorher->statusCode);
        $this->assertStringNotContainsString(
            'data-beispiel="captcha"',
            $vorher->body,
            'Solange der Betreiber nichts zuweist, rendert der Kern seine eigene Aufgabe.'
        );

        $this->einstellungSetzen(
            'captcha_provider_' . self::CAPTCHA_KONTEXT,
            self::CAPTCHA_ANBIETER
        );

        $formular = $gast->get(self::BASIS . '/probeformular');
        $this->assertStringContainsString(
            'data-beispiel="captcha"',
            $formular->body,
            'captcha.render: Nach der Zuweisung muss das eigene Fragment erscheinen.'
        );

        $falsch = $gast->post(self::BASIS . '/probeformular', [
            'csrf_token' => $formular->formField('csrf_token') ?? '',
            'beispiel_wort' => 'esel',
        ]);
        $this->assertStringContainsString('Abgelehnt', $falsch->body, 'captcha.verify muss WRONG liefern.');

        $formular = $gast->get(self::BASIS . '/probeformular');
        $richtig = $gast->post(self::BASIS . '/probeformular', [
            'csrf_token' => $formular->formField('csrf_token') ?? '',
            'beispiel_wort' => 'Pferd',
        ]);
        $this->assertStringContainsString('Angenommen', $richtig->body, 'captcha.verify muss OK liefern.');

        // -------------------------------------------------------------
        // 9. admin.dashboard_tiles und die Zusatzfunktion (#57)
        // -------------------------------------------------------------
        $this->assertStringContainsString(
            self::BASIS . '/ereignisbuch',
            $admin->get('/admin')->body,
            'admin.dashboard_tiles: Die Kachel fehlt im Dashboard.'
        );

        // FeatureGate ist fail-closed: Vorgabe `members`, also fuer Gaeste zu.
        $this->assertSame(
            403,
            $gast->get(self::BASIS . '/schaufenster')->statusCode,
            'Eine Zusatzfunktion mit default_visibility=members darf Gaesten nicht offenstehen.'
        );

        $this->einstellungSetzen('feature_visibility__beispiel-schaufenster', 'public');
        $this->assertSame(
            200,
            $gast->get(self::BASIS . '/schaufenster')->statusCode,
            'Nach dem Umschalten auf "public" muss die Seite oeffentlich sein.'
        );

        // -------------------------------------------------------------
        // 10. DER ABSCHLUSS: jeder registrierte Hook hat gefeuert
        // -------------------------------------------------------------
        $this->assertJederHookHatGefeuert($admin);
    }

    /**
     * Fail-closed: Wer das eigene Recht nicht hat, sieht den Abschnitt gar
     * nicht - und kommt auch ueber die Route nicht heran.
     *
     * Das ist kein Detail. Ein Abschnitt, der erscheint und beim Absenden 403
     * liefert, ist die haeufigste Form, in der ein Addon seine Rechtepruefung
     * halb macht: Sie sitzt an der Route, aber nicht an der Anzeige.
     */
    public function testOhneEigenesRechtErscheintDerAbschnittGarNicht(): void {
        $admin = $this->authenticatedClient();
        $this->aktivieren($admin);

        $unique = substr(uniqid(), -8);
        $horseId = $this->createHorse($admin, "BeispielRechte-{$unique}");

        // Die eingebaute Editor-Gruppe hat horses.edit, aber nicht
        // horses.beispielnotiz - das Addon registriert die Aktion nur, es
        // weist sie niemandem zu (fail-closed wie jede andere).
        $editorGruppe = $this->findBuiltinGroupId($admin, 'Editor');
        $editor = $this->createAndLoginEditor(
            $admin,
            "beispieleditor{$unique}",
            "beispiel-editor-{$unique}@example.com",
            [$editorGruppe]
        );

        $form = $editor->get('/admin/horses/edit?id=' . $horseId);
        $this->assertSame(200, $form->statusCode, 'Der Editor darf das Pferd bearbeiten.');
        $this->assertStringNotContainsString(
            'data-beispiel="horse-edit"',
            $form->body,
            'Ohne horses.beispielnotiz darf der Addon-Abschnitt gar nicht erst erscheinen.'
        );

        // editorCsrfToken() statt currentCsrfToken(): Letzteres holt das Token
        // von /admin/users/create, das dieser Benutzer nicht sehen darf - es
        // waere leer, und der POST scheiterte am CSRF-Check STATT an der
        // Rechtepruefung. Der Test waere gruen, ohne die Zusicherung je
        // erreicht zu haben (Framework#377).
        $versuch = $editor->post(self::BASIS . '/notiz', [
            'csrf_token' => $this->editorCsrfToken($editor),
            'horse_id' => (string)$horseId,
            'notiz' => 'darf nicht durchkommen',
        ]);
        $this->assertSame(
            403,
            $versuch->statusCode,
            'Die Route muss dieselbe Berechtigung verlangen wie die Anzeige.'
        );

        // Und die Verwaltungsseite ebenfalls.
        $this->assertSame(403, $editor->get(self::BASIS . '/ereignisbuch')->statusCode);
    }

    // -----------------------------------------------------------------

    /**
     * Das Addon wird nach jedem Test wieder abgeschaltet.
     *
     * WARUM DAS HIER NOETIG IST UND BEI ANDEREN ADDONS NICHT: Die Aktivierung
     * steht in der Datenbank, und alle Testklassen dieser Suite teilen sich
     * eine. Dieses Addon haengt an JEDEM Erweiterungspunkt - es ergaenzt die
     * oeffentliche Navigation, die Startseite, jede Katalogkarte und jede
     * Detailseite. Bliebe es an, liefen die folgenden Testklassen gegen
     * Seiten, die anders aussehen als die, die sie pruefen. Besonders die
     * Navigation ist eng: Der Kern nimmt ueber ALLE Addons hinweg hoechstens
     * fuenf ergaenzte Menuepunkte, danach faellt still einer weg.
     */
    protected function tearDown(): void {
        try {
            $admin = $this->authenticatedClient();
            $admin->post('/admin/plugins/toggle', [
                'csrf_token' => $this->currentCsrfToken($admin),
                'slug' => self::SLUG,
                'enable' => '0',
            ]);
        } catch (\Throwable $e) {
            // Ein fehlgeschlagenes Aufraeumen darf das Ergebnis des Tests
            // nicht ueberschreiben - der eigentliche Fehler stuende sonst
            // hinter einem Folgefehler.
        }

        parent::tearDown();
    }

    private function aktivieren(HttpClient $admin): void {
        $seite = $admin->get('/admin/plugins');
        $this->assertSame(200, $seite->statusCode);
        $this->assertStringContainsString(
            self::SLUG,
            $seite->body,
            'Das Addon sollte unter /admin/plugins entdeckt sein - wurde es nach '
            . 'vendor/hengstverzeichnis/framework/plugins kopiert?'
        );

        $antwort = $admin->post('/admin/plugins/toggle', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'slug' => self::SLUG,
            'enable' => '1',
        ]);
        $this->assertSame('/admin/plugins?success=1', $antwort->location());
    }

    /** Der Inhalt der eigenen Verwaltungsseite - dort steht das Ereignisbuch. */
    private function ereignisbuch(HttpClient $admin): string {
        $seite = $admin->get(self::BASIS . '/ereignisbuch');
        $this->assertSame(200, $seite->statusCode, 'Die Verwaltungsseite des Addons muss erreichbar sein.');
        return $seite->body;
    }

    /**
     * Schreibt eine Einstellung direkt in die Datenbank.
     *
     * Bewusst nicht ueber POST /admin/system-settings: Der Endpunkt schreibt
     * das gesamte Formular und setzte dabei Werte zurueck, die andere
     * Testklassen dieser Suite in derselben Datenbank brauchen. Geprueft wird
     * hier das Verhalten des Addons, nicht die Einstellungsmaske des Kerns -
     * die hat ihre eigenen Tests im Framework-Repo.
     */
    private function einstellungSetzen(string $schluessel, string $wert): void {
        $stmt = \App\Database::getInstance()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$schluessel, $wert]);
    }

    /**
     * Der geschlossene Nachweis: Jeder Hook, den das Beispiel registriert, hat
     * im Lauf dieses Tests mindestens einmal gefeuert.
     *
     * Die Abdeckungstafel der Verwaltungsseite fuehrt je Hook eine Zeile mit
     * data-hook und der Zahl der Ausloesungen. Die Filter, die nichts
     * aufzeichnen (sie erzeugen Ausgabe statt Ereignissen), sind oben einzeln
     * an ihrer sichtbaren Wirkung geprueft - deshalb steht hier eine
     * Positivliste: Was nicht ins Ereignisbuch schreibt, wird nicht daran
     * gemessen.
     */
    private function assertJederHookHatGefeuert(HttpClient $admin): void {
        $tafel = $this->ereignisbuch($admin);

        $erwartet = [
            'horse.before_save',
            'horse.after_save',
            'horse.before_delete',
            'horse.trashed',
            'horse.restored',
            'horse.deleted',
            'contact.after_save',
            'contact.deleted',
        ];

        foreach ($erwartet as $hook) {
            $this->assertMatchesRegularExpression(
                '/data-hook="' . preg_quote($hook, '/') . '".*?<td style="text-align:right;">([1-9]\d*)<\/td>/s',
                $tafel,
                "Der Hook '{$hook}' hat in diesem Testlauf nie gefeuert. Entweder loest der Kern ihn "
                . 'nicht mehr aus, oder das Beispiel haengt nicht mehr daran.'
            );
        }
    }
}
