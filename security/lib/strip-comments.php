<?php
// strip-comments.php <datei>
//
// Gibt den PHP-Quelltext aus, in dem alle Kommentare (// # /* */ /** */) durch
// Leerraum ersetzt sind — Zeilennummern und Zeilenanzahl bleiben erhalten.
// So findet der statische Plugin-Check gefaehrliche Muster nur im echten Code
// und nicht in Doc-Kommentaren (wo z. B. `$var` in Backticks steht und sonst
// als "Backtick-Shell" fehlgemeldet wuerde).
//
// Zeichenkettten-Literale bleiben erhalten, weil der Check dort z. B. SQL mit
// interpolierten Variablen erkennen will.
//
// AUSNAHME: Backticks INNERHALB von Zeichenketten werden durch ein Leerzeichen
// ersetzt. Grund: In SQL sind Backticks Bezeichner-Anfuehrungszeichen
// (SELECT `spalte` FROM `tabelle`), in PHP dagegen der Shell-Ausfuehrungs-
// operator. Die Regel des Scanners sucht per Regex nach Backticks und meldete
// deshalb jede SQL-Abfrage mit gequoteten Bezeichnern als
// "Backtick-Shell-Ausfuehrung" - beim Release v0.8.0-beta waren das sieben
// blockierende HIGH-Funde, alle falsch.
//
// Der Tokenizer kann die beiden Faelle sauber trennen: Ein echter
// Shell-Backtick ist ein EIGENSTAENDIGES Zeichen-Token, einer in einer
// Zeichenkette gehoert zum String-Token. Nur die zweite Sorte wird hier
// neutralisiert; ein `ls $dir` bleibt vollstaendig stehen und wird weiterhin
// gemeldet.
//
// Die Interpolation bleibt erkennbar: Ersetzt wird nur das Anfuehrungs-
// zeichen, nicht das Dollar-Zeichen.

if ($argc < 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Nutzung: strip-comments.php <datei.php>\n");
    exit(1);
}

$src = file_get_contents($argv[1]);
if ($src === false) { exit(1); }

$tokens = token_get_all($src);
$out = '';
foreach ($tokens as $tok) {
    if (is_array($tok)) {
        [$id, $text] = $tok;
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            // Kommentar durch Leerraum ersetzen, Zeilenumbrueche behalten,
            // damit die Zeilennummern stabil bleiben.
            $out .= preg_replace('/[^\n]/', ' ', $text);
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
            // Backticks in Zeichenketten sind SQL-Bezeichner, keine Shell -
            // siehe Kopfkommentar. Laenge und Zeilenumbrueche bleiben.
            $out .= str_replace('`', ' ', $text);
        } else {
            $out .= $text;
        }
    } else {
        $out .= $tok;
    }
}
echo $out;
