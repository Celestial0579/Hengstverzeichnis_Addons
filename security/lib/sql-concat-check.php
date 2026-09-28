<?php
// sql-concat-check.php <datei.php> [<datei.php> ...]
//
// Tokenizer-basierte SQL-Pruefung fuer den statischen Plugin-Check
// (plugin-security-scan.sh). Ersetzt die frueheren zeilenweisen grep-Regeln,
// die nur doppelte Anfuehrungszeichen direkt vor dem Verkettungspunkt sahen,
// ->exec() bei Verkettung ausliessen und jeden mehrzeiligen Aufruf verpassten
// (Audit N36). Im Repo steht das SQL meist auf der Folgezeile, in einfachen
// Anfuehrungszeichen - genau diese Schreibweise fiel durch.
//
// GEPRUEFT wird das ERSTE Argument jedes Aufrufs ->query( / ?->query( /
// ::query( (ebenso prepare, exec; Gross-/Kleinschreibung egal). Drei Arten:
//
//   - "SQL mit Variable im Query-String (Interpolation)":
//     eine Variable in einem "..."- oder Heredoc-String.
//   - "SQL mit konkatenierter Variable":
//     das Argument enthaelt den Verkettungsoperator '.' und eine Variable.
//   - "SQL-String aus Variable zusammengesetzt (Funktionsaufruf/Ausdruck)":
//     eine Variable ohne '.', aber mehr als ein blosser Variablen-,
//     Eigenschafts- oder Array-Zugriff - sprintf(), implode(), strtr(),
//     Ternaer. Ohne diese Art waere sprintf der naheliegende Weg um die
//     Verkettungsregel herum.
//
// AUSGENOMMEN (eng gefasst, alles andere gehoert begruendet in die Allowlist):
//   (a) jede Aufrufkette, die auf ->quote( / ->quoteIdentifier( endet: die
//       Variablen der Empfaengerkette ($db, $this->db,
//       Database::getInstance()) UND alles in den Klammern;
//   (b) eine Variable direkt nach einem (int)- oder (float)-Cast.
//
// NICHT erfasst (bleibt Aufgabe von Semgrep): SQL, das vorab in einer
// Variable gebaut und dann als ->query($sql) uebergeben wird, und Aufbau per
// '.='. Das ist Datenfluss, den ein Token-Muster nicht sieht.
//
// Ausgabe je Treffer eine Zeile:  <datei>:<zeile>|<Titel>|<Fingerabdruck>
// Zeile = Zeile des Methodennamens. Fingerabdruck = Quelltext des ersten
// Arguments ohne Kommentare, jede Whitespace-Folge zu einem Leerzeichen
// zusammengefasst, UNGEKUERZT (die Allowlist vergleicht einen Teilstring
// daraus; eine Kuerzung haette unterscheidende Teile abgeschnitten). Der
// Fingerabdruck kann selbst '|' enthalten - der Aufrufer teilt nur an den
// ersten beiden.
//
// Exit 0 bei Erfolg, AUCH mit Treffern. Exit 1 nur, wenn eine Datei nicht
// lesbar ist oder keine Datei uebergeben wurde - der Aufrufer wertet das als
// Umgebungsfehler, nie als "keine Treffer".
//
// token_get_all laeuft bewusst OHNE TOKEN_PARSE: Plugins duerfen Syntax einer
// neueren PHP-Version enthalten, und ein aelteres lokales PHP soll dann nicht
// abbrechen. Syntaxfehler findet PluginManifestTest.

const TITEL_INTERPOLATION = 'SQL mit Variable im Query-String (Interpolation)';
const TITEL_KONKATENATION = 'SQL mit konkatenierter Variable';
const TITEL_ZUSAMMENGESETZT = 'SQL-String aus Variable zusammengesetzt (Funktionsaufruf/Ausdruck)';

// Erst ab PHP 8 vorhandene Token-Konstanten nur nutzen, wenn es sie gibt
// (PHP_BIN im Skript kann auch ein aelteres php8.x sein).
function tokenId(string $name): int {
    return defined($name) ? constant($name) : -1;
}

$T_NULLSAFE = tokenId('T_NULLSAFE_OBJECT_OPERATOR');
$T_NAME_Q = tokenId('T_NAME_QUALIFIED');
$T_NAME_FQ = tokenId('T_NAME_FULLY_QUALIFIED');
$T_NAME_REL = tokenId('T_NAME_RELATIVE');

function tid(mixed $tok): int|string {
    return is_array($tok) ? $tok[0] : $tok;
}

function ttext(mixed $tok): string {
    return is_array($tok) ? $tok[1] : $tok;
}

/**
 * Prueft eine Datei und liefert die Treffer als Ausgabezeilen.
 *
 * @return list<string>
 */
function pruefeDatei(string $anzeigePfad, string $src): array {
    global $T_NULLSAFE, $T_NAME_Q, $T_NAME_FQ, $T_NAME_REL;

    $alle = token_get_all($src);

    // Zeilennummern fuer Ein-Zeichen-Token nachtragen: token_get_all liefert
    // sie nur fuer Array-Token.
    $zeile = 1;
    $zeilen = [];
    foreach ($alle as $i => $tok) {
        if (is_array($tok)) {
            $zeile = $tok[2];
        }
        $zeilen[$i] = $zeile;
        $zeile += substr_count(ttext($tok), "\n");
    }

    // Signifikante Token (ohne Whitespace/Kommentare) mit Index in $alle.
    $sig = [];
    foreach ($alle as $i => $tok) {
        $id = tid($tok);
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }
        $sig[] = $i;
    }

    $oeffner = ['(' => true, '[' => true, '{' => true];
    $treffer = [];
    $n = count($sig);

    for ($k = 0; $k + 2 < $n; $k++) {
        $op = tid($alle[$sig[$k]]);
        if ($op !== T_OBJECT_OPERATOR && $op !== $T_NULLSAFE && $op !== T_DOUBLE_COLON) {
            continue;
        }
        $name = $alle[$sig[$k + 1]];
        if (tid($name) !== T_STRING || !in_array(strtolower($name[1]), ['query', 'prepare', 'exec'], true)) {
            continue;
        }
        if (tid($alle[$sig[$k + 2]]) !== '(') {
            continue;
        }

        // Erstes Argument: bis ',' oder ')' auf Klammertiefe 0.
        $start = $k + 3;
        $tiefe = 0;
        $ende = $start; // exklusiv
        for ($j = $start; $j < $n; $j++) {
            $id = tid($alle[$sig[$j]]);
            if (isset($oeffner[$id]) || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES
                || (defined('T_ATTRIBUTE') && $id === T_ATTRIBUTE)) {
                $tiefe++;
            } elseif ($id === ')' || $id === ']' || $id === '}') {
                if ($tiefe === 0) {
                    break;
                }
                $tiefe--;
            } elseif ($id === ',' && $tiefe === 0) {
                break;
            }
        }
        $ende = $j;
        if ($ende <= $start) {
            continue; // leerer Aufruf
        }

        $arg = array_slice($sig, $start, $ende - $start); // Indizes in $alle
        $titel = klassifiziere($alle, $arg);
        if ($titel === null) {
            continue;
        }

        // Fingerabdruck: Quelltext des Arguments (alle Token zwischen erstem
        // und letztem signifikanten), Kommentare zu Leerraum, Whitespace
        // zusammengefasst.
        $text = '';
        for ($i = $arg[0]; $i <= $arg[count($arg) - 1]; $i++) {
            $id = tid($alle[$i]);
            $text .= ($id === T_COMMENT || $id === T_DOC_COMMENT) ? ' ' : ttext($alle[$i]);
        }
        $fp = trim((string) preg_replace('/\s+/', ' ', $text));

        $treffer[] = $anzeigePfad . ':' . $zeilen[$sig[$k + 1]] . '|' . $titel . '|' . $fp;
    }

    return $treffer;
}

/**
 * @param array<int, mixed> $alle
 * @param list<int> $arg Indizes (in $alle) der signifikanten Token des Arguments
 */
function klassifiziere(array $alle, array $arg): ?string {
    global $T_NULLSAFE, $T_NAME_Q, $T_NAME_FQ, $T_NAME_REL;

    $m = count($arg);
    $ausgenommen = array_fill(0, $m, false);

    // (a) Aufrufketten, die auf ->quote( / ->quoteIdentifier( enden.
    for ($p = 0; $p + 2 < $m; $p++) {
        $id = tid($alle[$arg[$p]]);
        if ($id !== T_OBJECT_OPERATOR && $id !== $T_NULLSAFE) {
            continue;
        }
        $methode = $alle[$arg[$p + 1]];
        if (tid($methode) !== T_STRING
            || !in_array(strtolower($methode[1]), ['quote', 'quoteidentifier'], true)
            || tid($alle[$arg[$p + 2]]) !== '(') {
            continue;
        }
        // Klammerinhalt bis zur passenden ')' ausnehmen.
        $tiefe = 0;
        for ($q = $p + 2; $q < $m; $q++) {
            $t = tid($alle[$arg[$q]]);
            if ($t === '(' || $t === '[' || $t === '{' || $t === T_CURLY_OPEN || $t === T_DOLLAR_OPEN_CURLY_BRACES) {
                $tiefe++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                $tiefe--;
            }
            $ausgenommen[$q] = true;
            if ($tiefe === 0) {
                break;
            }
        }
        // Empfaengerkette rueckwaerts: Variablen, Namen, ->, ::, und
        // geklammerte Gruppen (getInstance(), $dbs['x']).
        $kette = [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR, $T_NULLSAFE, T_DOUBLE_COLON,
                  T_STATIC, $T_NAME_Q, $T_NAME_FQ, $T_NAME_REL];
        $q = $p - 1;
        while ($q >= 0) {
            $t = tid($alle[$arg[$q]]);
            if ($t === ')' || $t === ']') {
                $auf = $t === ')' ? '(' : '[';
                $tiefe = 0;
                while ($q >= 0) {
                    $u = tid($alle[$arg[$q]]);
                    if ($u === ')' || $u === ']') {
                        $tiefe++;
                    } elseif ($u === '(' || $u === '[') {
                        $tiefe--;
                    }
                    $ausgenommen[$q] = true;
                    if ($tiefe === 0) {
                        break;
                    }
                    $q--;
                }
                $q--;
                continue;
            }
            if (!in_array($t, $kette, true)) {
                break;
            }
            $ausgenommen[$q] = true;
            $q--;
        }
    }

    // (b) Variable direkt nach (int)/(float).
    for ($p = 0; $p + 1 < $m; $p++) {
        $id = tid($alle[$arg[$p]]);
        if (($id === T_INT_CAST || $id === T_DOUBLE_CAST) && tid($alle[$arg[$p + 1]]) === T_VARIABLE) {
            $ausgenommen[$p + 1] = true;
        }
    }

    $interpoliert = false;
    $hatPunkt = false;
    $hatVariable = false;
    $imString = false;   // innerhalb "..."
    $imHeredoc = false;  // innerhalb <<<X ... X
    for ($p = 0; $p < $m; $p++) {
        $id = tid($alle[$arg[$p]]);
        if ($id === '"') {
            $imString = !$imString;
            continue;
        }
        if ($id === T_START_HEREDOC) {
            $imHeredoc = true;
            continue;
        }
        if ($id === T_END_HEREDOC) {
            $imHeredoc = false;
            continue;
        }
        $inString = $imString || $imHeredoc;
        if ($id === '.' && !$inString) {
            $hatPunkt = true;
        }
        if ($inString && $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $interpoliert = true;
        }
        if ($id === T_VARIABLE && !$ausgenommen[$p]) {
            $hatVariable = true;
            if ($inString) {
                $interpoliert = true;
            }
        }
    }

    if ($interpoliert) {
        return TITEL_INTERPOLATION;
    }
    if (!$hatVariable) {
        return null;
    }
    if ($hatPunkt) {
        return TITEL_KONKATENATION;
    }
    if (istReinerZugriff($alle, $arg)) {
        return null;
    }
    return TITEL_ZUSAMMENGESETZT;
}

/**
 * Blosser Variablen-, Eigenschafts-, statischer oder Array-Zugriff:
 * $sql, $this->sql, $this?->sql, $q['sql'], $q[$i]['sql'], self::$sql.
 *
 * @param array<int, mixed> $alle
 * @param list<int> $arg
 */
function istReinerZugriff(array $alle, array $arg): bool {
    global $T_NULLSAFE, $T_NAME_Q, $T_NAME_FQ;

    $m = count($arg);
    $p = 0;
    $erst = tid($alle[$arg[0]]);
    if ($erst === T_VARIABLE) {
        $p = 1;
    } elseif (in_array($erst, [T_STRING, T_STATIC, $T_NAME_Q, $T_NAME_FQ], true)
        && $m >= 3 && tid($alle[$arg[1]]) === T_DOUBLE_COLON && tid($alle[$arg[2]]) === T_VARIABLE) {
        $p = 3;
    } else {
        return false;
    }

    while ($p < $m) {
        $id = tid($alle[$arg[$p]]);
        if (($id === T_OBJECT_OPERATOR || $id === $T_NULLSAFE)
            && $p + 1 < $m && tid($alle[$arg[$p + 1]]) === T_STRING) {
            $p += 2;
            continue;
        }
        if ($id === '[') {
            $tiefe = 0;
            for (; $p < $m; $p++) {
                $t = tid($alle[$arg[$p]]);
                if ($t === '[') {
                    $tiefe++;
                } elseif ($t === ']') {
                    $tiefe--;
                    if ($tiefe === 0) {
                        break;
                    }
                } elseif ($t === '(') {
                    return false; // Aufruf im Index: kein blosser Zugriff
                }
            }
            $p++;
            continue;
        }
        return false;
    }
    return true;
}

if ($argc < 2) {
    fwrite(STDERR, "Nutzung: sql-concat-check.php <datei.php> [<datei.php> ...]\n");
    exit(1);
}

$fehler = false;
$ausgabe = [];
foreach (array_slice($argv, 1) as $datei) {
    $src = is_file($datei) && is_readable($datei) ? @file_get_contents($datei) : false;
    if ($src === false) {
        fwrite(STDERR, "sql-concat-check: Datei nicht lesbar: {$datei}\n");
        $fehler = true;
        continue;
    }
    foreach (pruefeDatei($datei, $src) as $zeile) {
        $ausgabe[] = $zeile;
    }
}

if ($fehler) {
    exit(1);
}
foreach ($ausgabe as $zeile) {
    echo $zeile, "\n";
}
exit(0);
