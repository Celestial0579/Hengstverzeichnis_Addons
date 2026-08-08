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
        } else {
            $out .= $text;
        }
    } else {
        $out .= $tok;
    }
}
echo $out;
