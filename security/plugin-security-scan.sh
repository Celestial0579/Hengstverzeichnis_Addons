#!/usr/bin/env bash
#
# plugin-security-scan.sh — statischer Sicherheits-Check der Addon-Plugins.
#
# Schnell, ohne Infrastruktur, als Ergaenzung zum kali-DAST (run-addon-dast.sh):
# durchsucht jeden Plugin-PHP-Code nach hoch-konfidenten gefaehrlichen Mustern.
# Bewusst KEIN vollstaendiger SAST (dafuer taugt Semgrep besser) — hier nur
# Muster mit sehr geringer Fehlalarmquote, plus eine Liste der Stellen, die eine
# manuelle Sichtung verdienen (direkte Superglobal-Nutzung).
#
# Kommentare werden vor der Suche entfernt (lib/strip-comments.php ueber die
# PHP-CLI, Zeilennummern bleiben erhalten). Das ist wichtig: in Doc-Kommentaren
# steht z. B. `$var` in Backticks, was sonst als "Backtick-Shell" fehlgemeldet
# wuerde. Ohne PHP-CLI: Rueckfall auf das Entfernen reiner Kommentarzeilen.
#
# Ebenso zaehlen bei den Mustern nur echte Funktionsaufrufe: Methodenaufrufe wie
# Database::...->exec() sind PDO, NICHT die Shell-Funktion exec() — die Muster
# matchen daher nur, wenn NICHT '->' / '::' / '_' / ein Buchstabe davor steht.
#
# Exit: 0 = nichts Blockierendes, 2 = HIGH/CRIT-Funde, 1 = Aufruf-/Umgebungsfehler.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
PLUGINS="${1:-$REPO/plugins}"
ALLOW="$HERE/baseline/plugin-findings.allow"
STRIPPER="$HERE/lib/strip-comments.php"

[[ -d "$PLUGINS" ]] || { echo "plugins/-Verzeichnis nicht gefunden: $PLUGINS" >&2; exit 1; }

PHP_BIN=""
for c in php php8.3 php8.2 php8.1; do command -v "$c" >/dev/null 2>&1 && { PHP_BIN="$c"; break; }; done

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

if [[ -t 1 ]]; then
  R=$'\033[0;31m'; Y=$'\033[1;33m'; G=$'\033[0;32m'; D=$'\033[2m'; N=$'\033[0m'
else R=""; Y=""; G=""; D=""; N=""; fi

declare -A COUNT=( [CRIT]=0 [HIGH]=0 [MED]=0 [LOW]=0 [INFO]=0 [ACK]=0 )
blocking=0

allowed() {
  [[ -f "$ALLOW" ]] || return 1
  grep -vE '^\s*#|^\s*$' "$ALLOW" 2>/dev/null | grep -qF -- "$1"
}

finding() { # <SEV> <plugin> <ort> <titel>
  local sev="$1" plug="$2" loc="$3" title="$4"
  if [[ "$sev" =~ ^(CRIT|HIGH|MED)$ ]] && allowed "$plug|$title"; then
    COUNT[ACK]=$(( COUNT[ACK] + 1 )); return
  fi
  COUNT[$sev]=$(( ${COUNT[$sev]:-0} + 1 ))
  [[ "$sev" =~ ^(CRIT|HIGH)$ ]] && blocking=$(( blocking + 1 ))
  local col="$D"; case "$sev" in CRIT|HIGH) col="$R";; MED) col="$Y";; esac
  printf '   %s[%-4s]%s %s %s(%s)%s\n' "$col" "$sev" "$N" "$title" "$D" "$loc" "$N" >&2
}

# Kommentar-bereinigte Spiegelung eines Plugins unter $TMP/<plug>/… anlegen.
build_stripped() { # <plugindir> <plug>
  local dir="$1" plug="$2" rel dst
  while IFS= read -r f; do
    rel="${f#"$dir"/}"; dst="$TMP/$plug/$rel"
    mkdir -p "$(dirname "$dst")"
    if [[ -n "$PHP_BIN" ]] && "$PHP_BIN" "$STRIPPER" "$f" >"$dst" 2>/dev/null; then
      :
    else
      # Rueckfall: reine Kommentarzeilen leeren (Zeilennummern bleiben erhalten).
      sed -E 's#^[[:space:]]*(//|#|\*|/\*).*$##' "$f" >"$dst"
    fi
  done < <(find "$dir" -name '*.php' -type f)
}

# grep im bereinigten Spiegel; Pfade zurueck auf plugins/<plug>/… uebersetzen.
scan() { # <plug> <regex>
  local plug="$1" re="$2"
  grep -rnE "$re" "$TMP/$plug" --include='*.php' 2>/dev/null \
    | sed "s#$TMP/$plug/#plugins/$plug/#"
}
loc_of() { printf '%s' "$1" | cut -d: -f1-2; }

echo "== Statischer Plugin-Sicherheits-Check ==" >&2
[[ -n "$PHP_BIN" ]] && echo "Kommentar-Entfernung: $PHP_BIN + token_get_all" >&2 \
                     || echo "Kommentar-Entfernung: Rueckfall (Zeilenheuristik)" >&2
mapfile -t plugdirs < <(find "$PLUGINS" -mindepth 1 -maxdepth 1 -type d | sort)
echo "Plugins: ${#plugdirs[@]}" >&2

for dir in "${plugdirs[@]}"; do
  plug="$(basename "$dir")"
  build_stripped "$dir" "$plug"
  printf '\n%s── %s%s\n' "$D" "$plug" "$N" >&2
  had=0

  while IFS= read -r m; do [[ -z "$m" ]] && continue
    fn="$(printf '%s' "$m" | grep -oE '(eval|system|exec|passthru|shell_exec|popen|proc_open|assert)[[:space:]]*\(' | head -1)"
    finding HIGH "$plug" "$(loc_of "$m")" "Code-/Kommando-Ausfuehrung: ${fn%%(*}()"; had=1
  done < <(scan "$plug" '(^|[^>_:[:alnum:]])(eval|system|exec|passthru|shell_exec|popen|proc_open|assert)[[:space:]]*\(')

  while IFS= read -r m; do [[ -z "$m" ]] && continue
    finding HIGH "$plug" "$(loc_of "$m")" "Backtick-Shell-Ausfuehrung"; had=1
  done < <(scan "$plug" '`[^`]*\$')

  # Nach dem Schluesselwort MUSS Whitespace oder eine oeffnende Klammer
  # folgen. Ohne diese Bedingung traf das Muster jeden Methodennamen, der mit
  # "require"/"include" beginnt und irgendwo eine Variable im Argument hat -
  # etwa requireAdminForFullAccess(string $aktion) oder
  # $this->requirePermission($modul, $aktion). Ein Gate, das bei sauberem Code
  # ausschlaegt, wird umbenannt statt behoben; dann faellt beim naechsten Mal
  # der echte Fund nicht mehr auf.
  while IFS= read -r m; do [[ -z "$m" ]] && continue
    finding HIGH "$plug" "$(loc_of "$m")" "Dynamisches include/require mit Variable"; had=1
  done < <(scan "$plug" '(^|[^_[:alnum:]])(include|require)(_once)?([[:space:]]+|[[:space:]]*\()[^;]*\$')

  while IFS= read -r m; do [[ -z "$m" ]] && continue
    finding HIGH "$plug" "$(loc_of "$m")" "SQL mit Variable im Query-String (Interpolation)"; had=1
  done < <(scan "$plug" '(query|prepare|exec)[[:space:]]*\([[:space:]]*"[^"]*\$')

  while IFS= read -r m; do [[ -z "$m" ]] && continue
    finding HIGH "$plug" "$(loc_of "$m")" "SQL mit konkatenierter Variable"; had=1
  done < <(scan "$plug" '(query|prepare)[[:space:]]*\([^)]*"[[:space:]]*\.[[:space:]]*\$')

  while IFS= read -r m; do [[ -z "$m" ]] && continue
    finding HIGH "$plug" "$(loc_of "$m")" "Datei-Op mit Nutzereingabe (LFI/Traversal)"; had=1
  done < <(scan "$plug" '(file_get_contents|fopen|readfile|unlink|file_put_contents|include|require)[^;]*\$_(GET|POST|REQUEST|COOKIE)')

  while IFS= read -r m; do [[ -z "$m" ]] && continue
    finding HIGH "$plug" "$(loc_of "$m")" "Ausgabe von Nutzereingabe ohne Encoding (XSS)"; had=1
  done < <(scan "$plug" '(echo|print)[^;]*\$_(GET|POST|REQUEST|SERVER|COOKIE)')

  while IFS= read -r m; do [[ -z "$m" ]] && continue
    finding MED "$plug" "$(loc_of "$m")" "unserialize() — Eingabequelle pruefen"; had=1
  done < <(scan "$plug" '(^|[^_[:alnum:]])unserialize[[:space:]]*\(')

  sg="$(scan "$plug" '\$_(GET|POST|REQUEST|COOKIE)' | wc -l)"
  if [[ "$sg" -gt 0 ]]; then
    finding INFO "$plug" "$plug" "$sg Stelle(n) mit direkter Superglobal-Nutzung (Review)"; had=1
  fi

  [[ "$had" -eq 0 ]] && printf '   %s[PASS]%s keine auffaelligen Muster\n' "$G" "$N" >&2
done

printf '\n%s== Zusammenfassung ==%s\n' "$D" "$N" >&2
printf '  CRIT=%s HIGH=%s MED=%s LOW=%s INFO=%s (allowlisted=%s)\n' \
  "${COUNT[CRIT]}" "${COUNT[HIGH]}" "${COUNT[MED]}" "${COUNT[LOW]}" "${COUNT[INFO]}" "${COUNT[ACK]}" >&2

if [[ "$blocking" -gt 0 ]]; then
  printf '%s== FEHLGESCHLAGEN: %s blockierende (CRIT/HIGH) Funde ==%s\n' "$R" "$blocking" "$N" >&2
  exit 2
fi
printf '%s== bestanden: keine blockierenden Funde ==%s\n' "$G" "$N" >&2
exit 0
