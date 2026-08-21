<?php
// tests/Unit/EmbedWidgetCodeTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\EmbedWidget\EmbedCode;

require_once __DIR__ . '/../../plugins/embed-widget/Plugin.php';

/**
 * Prüft die reine Logik des Embed-Widgets (Addons#89).
 *
 * WARUM HIER UND NICHT ALS LEBENSZYKLUS-TEST: Das Addon deklariert
 * `core_compatibility: >=0.5.1`, weil es `layout_embed.php` und `FrameGuard`
 * aus Kern-#260 braucht. Der eingebundene Kern trägt aber noch
 * `CORE_VERSION 0.5.0` - der Versionsstring wird erst beim nächsten
 * Kern-Release nachgezogen. `PluginManager::incompatibilityReason()` weist
 * das Addon deshalb fail-closed ab, ein Functional-Test könnte es gar nicht
 * aktivieren.
 *
 * Das ist kein Grund, die Logik ungeprüft zu lassen: Sie hängt an keiner
 * Framework-Klasse und wird hier direkt geprüft. Der Lebenszyklus-Test
 * (Aktivieren, Seite aufrufen, Berechtigung) gehört nachgezogen, sobald der
 * Kern 0.5.1 ausweist.
 */
class EmbedWidgetCodeTest extends TestCase {

    /** Unbekannte und leere Parameter dürfen nicht in den Schnipsel wandern. */
    public function testOnlyKnownNonEmptyFiltersSurvive(): void {
        $filter = EmbedCode::filtersFrom([
            'q_breed' => 'Trakehner',
            'search' => '  ',
            'q_color' => '',
            'voellig_erfunden' => 'boese',
            'q_sex' => 'stallion',
        ]);

        $this->assertSame(['q_breed' => 'Trakehner', 'q_sex' => 'stallion'], $filter);
    }

    /** Das Geschlecht ist eine Auswahl, kein Freitext. */
    public function testSexIsAWhitelistNotFreeText(): void {
        $this->assertArrayNotHasKey('q_sex', EmbedCode::filtersFrom(['q_sex' => 'einhorn']));
        $this->assertArrayNotHasKey('q_sex', EmbedCode::filtersFrom(['q_sex' => '']));

        foreach (array_keys(EmbedCode::SEXES) as $sex) {
            $this->assertSame($sex, EmbedCode::filtersFrom(['q_sex' => $sex])['q_sex'] ?? null);
        }
    }

    /** Maße: alles außerhalb der Grenzen fällt auf den Standard zurück. */
    public function testSizesAreClampedAndFallBack(): void {
        $this->assertSame(800, EmbedCode::size('800', 100, 4000, 900));
        $this->assertSame(900, EmbedCode::size('99', 100, 4000, 900), 'unter dem Minimum');
        $this->assertSame(900, EmbedCode::size('4001', 100, 4000, 900), 'über dem Maximum');
        $this->assertSame(900, EmbedCode::size('', 100, 4000, 900), 'leer');
        $this->assertSame(900, EmbedCode::size('abc', 100, 4000, 900), 'keine Zahl');
        $this->assertSame(900, EmbedCode::size('-500', 100, 4000, 900), 'negativ');
        $this->assertSame(100, EmbedCode::size(100, 100, 4000, 900), 'Grenze eingeschlossen');
        $this->assertSame(4000, EmbedCode::size(4000, 100, 4000, 900), 'Grenze eingeschlossen');
    }

    /**
     * Die Adresse muss absolut sein und `embed=1` führen - ohne das rendert
     * der Kern das volle Layout mit Navigation und Fußzeile in den Rahmen.
     */
    public function testUrlIsAbsoluteAndCarriesTheEmbedFlag(): void {
        $url = EmbedCode::url('https://hengste.example', ['q_breed' => 'Trakehner']);

        $this->assertStringStartsWith('https://hengste.example/katalog?', $url);
        $this->assertStringContainsString('embed=1', $url);
        $this->assertStringContainsString('q_breed=Trakehner', $url);

        // Ein abschließender Schrägstrich in der Basis darf keinen doppelten erzeugen.
        $this->assertSame($url, EmbedCode::url('https://hengste.example/', ['q_breed' => 'Trakehner']));
    }

    /** Sonderzeichen im Filter müssen als Parameter kodiert werden. */
    public function testFilterValuesAreUrlEncoded(): void {
        $url = EmbedCode::url('https://hengste.example', EmbedCode::filtersFrom([
            'q_station' => 'Gestüt Meier & Söhne',
        ]));

        $this->assertStringNotContainsString(' ', $url);
        $this->assertStringNotContainsString('&Söhne', $url);
        $this->assertStringContainsString('q_station=', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $zurueck);
        $this->assertSame('Gestüt Meier & Söhne', $zurueck['q_station'] ?? null, 'muss verlustfrei zurückkommen');
    }

    /**
     * Der Schnipsel geht an Dritte und landet in deren HTML. Ein Wert, der
     * aus dem Attribut ausbrechen kann, wäre eine XSS-Lücke auf einer FREMDEN
     * Seite - eingeschleppt durch uns.
     */
    public function testSnippetEscapesTheUrlIntoTheAttribute(): void {
        $boese = EmbedCode::url('https://hengste.example', EmbedCode::filtersFrom([
            'q_breed' => '"><script>alert(1)</script>',
        ]));
        $schnipsel = EmbedCode::snippet($boese, 100, 900, true);

        $this->assertStringNotContainsString('<script>', $schnipsel);

        // Auf "><" zu prüfen wäre falsch: Das steht legitim im schließenden
        // `"></iframe>`. Beweiskräftig ist, dass die gefährliche Folge
        // ausschließlich prozentkodiert vorkommt.
        $this->assertStringContainsString('%22%3E%3Cscript%3E', $schnipsel, 'muss URL-kodiert sein');
        $this->assertSame(
            1,
            substr_count($schnipsel, '<iframe'),
            'Genau ein iframe - kein aus dem Attribut ausgebrochenes Markup.'
        );

        // UND der Fall, den die Zeilen oben NICHT abdecken: eine rohe
        // Adresse, die nicht durch url() gelaufen ist.
        //
        // url() baut mit http_build_query() und prozentkodiert `"`, `<` und
        // `>` bereits vollstaendig - der an snippet() uebergebene Wert enthielt
        // also gar kein gefaehrliches Zeichen mehr. Die Maskierung IN
        // snippet() liess sich damit ersatzlos streichen, ohne dass dieser
        // Test rot wurde. snippet() ist aber public und nimmt eine beliebige
        // Zeichenkette entgegen; genau dafuer ist die Maskierung da.
        $roh = EmbedCode::snippet('https://hengste.example/katalog?embed=1" onload="alert(1)', 100, 900, true);

        $this->assertStringNotContainsString(
            'onload="alert(1)"',
            $roh,
            'Ein roher Wert darf nicht als zweites Attribut aus dem src-Attribut ausbrechen.'
        );
        $this->assertStringContainsString('&quot;', $roh, 'Das Anfuehrungszeichen muss maskiert im Attribut stehen.');
        $this->assertSame(1, substr_count($roh, '<iframe'));
        $this->assertSame(
            1,
            substr_count($schnipsel, '</iframe>'),
            'Genau ein schließendes Tag.'
        );

        // Gegenprobe: Ein Anführungszeichen im Attributwert würde das Attribut
        // beenden. Zwischen src=" und dem nächsten " darf nichts stehen, was
        // ein neues Attribut oder Tag eröffnet.
        preg_match('/src="([^"]*)"/', $schnipsel, $treffer);
        $this->assertNotEmpty($treffer, 'src-Attribut muss sauber begrenzt sein');
        $this->assertStringNotContainsString('<', $treffer[1]);
        $this->assertStringNotContainsString('>', $treffer[1]);
    }

    /** Volle Breite vs. feste Breite unterscheiden sich genau im Stil. */
    public function testFullWidthAndFixedWidthDifferOnlyInStyle(): void {
        $url = EmbedCode::url('https://hengste.example', []);

        $voll = EmbedCode::snippet($url, 640, 900, true);
        $fest = EmbedCode::snippet($url, 640, 900, false);

        $this->assertStringContainsString('width:100%', $voll);
        $this->assertStringNotContainsString('width:640px', $voll);

        $this->assertStringContainsString('width:640px', $fest);
        $this->assertStringContainsString('max-width:100%', $fest, 'auch fest darf nicht überlaufen');

        foreach ([$voll, $fest] as $schnipsel) {
            $this->assertStringContainsString('height:900px', $schnipsel);
            $this->assertStringContainsString('loading="lazy"', $schnipsel);
            $this->assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $schnipsel);
            $this->assertStringContainsString('title="Hengstkatalog"', $schnipsel, 'Barrierefreiheit: iframe braucht einen Titel');
        }
    }
}
