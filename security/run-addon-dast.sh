#!/usr/bin/env bash
#
# run-addon-dast.sh — kali-DAST gegen einen Framework-Build MIT diesen Addons.
#
# Holt das Framework, kopiert alle plugins/ dieses Repos hinein und laesst den
# DAST-Gate des Frameworks (security/run-security-scan.sh) gegen den so
# entstehenden, addon-haltigen Build laufen: eine ephemere, isolierte Instanz
# wird gebaut, gescannt und wieder abgeraeumt. So faellt auf, wenn die Addons
# die Sicherheitslage der Auslieferung verschlechtern — etwa eine Plugin-Datei
# exponieren, den Docroot-Schutz aushebeln oder Header verlieren.
#
# GRENZE (bewusst, dokumentiert): Die Plugins werden NICHT aktiviert. Der Kern
# laedt nur ueber /admin/plugins freigegebene Plugins, und der Admin-Login
# erzwingt 2FA — das laesst sich in einem automatisierten Blackbox-Scan nicht
# sinnvoll nachstellen. Addon-*Routen* pruefen daher die PHPUnit-Functional-Tests
# dieses Repos (die aktivieren jedes Plugin in einer laufenden Instanz);
# dieser DAST deckt die *Deployment*-Sicht ab (Dateiexposition, Server-Haertung
# mit vorhandenen Addons). Der statische Code-Check: plugin-security-scan.sh.
#
# Framework-Quelle (Umgebung):
#   FRAMEWORK_DIR   lokaler Checkout (nutzt dessen committeten Stand, HEAD)
#   FRAMEWORK_REPO  sonst: Klon-URL (Default: offizielles Repo)
#   FRAMEWORK_REF   Branch/Tag fuer den Klon (Default: main)
#
# Alle weiteren Argumente werden an security/run-security-scan.sh durchgereicht
# (z. B. --only, --strict, --runner). Beispiel:
#   security/run-addon-dast.sh --only exposed-paths,content-discovery
#
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
: "${FRAMEWORK_REPO:=https://github.com/Celestial0579/Hengstverzeichnis_Framework.git}"
: "${FRAMEWORK_REF:=main}"

log() { printf '==> %s\n' "$*" >&2; }
die() { printf 'run-addon-dast: %s\n' "$*" >&2; exit 1; }

command -v git >/dev/null 2>&1 || die "git nicht gefunden."
[[ -d "$REPO/plugins" ]] || die "plugins/-Verzeichnis nicht gefunden: $REPO/plugins"

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
FW="$TMP/framework"; mkdir -p "$FW"

if [[ -n "${FRAMEWORK_DIR:-}" ]]; then
  [[ -d "$FRAMEWORK_DIR" ]] || die "FRAMEWORK_DIR existiert nicht: $FRAMEWORK_DIR"
  log "Framework aus lokalem Checkout (HEAD): $FRAMEWORK_DIR"
  if git -C "$FRAMEWORK_DIR" rev-parse --git-dir >/dev/null 2>&1; then
    git -C "$FRAMEWORK_DIR" archive --format=tar HEAD | tar -x -C "$FW" \
      || die "git archive des Frameworks fehlgeschlagen."
  else
    cp -a "$FRAMEWORK_DIR/." "$FW/" || die "Kopieren des Frameworks fehlgeschlagen."
    rm -rf "$FW/.git" "$FW/vendor" 2>/dev/null || true
  fi
else
  log "Klone Framework $FRAMEWORK_REPO (Ref: $FRAMEWORK_REF) …"
  timeout 180 git -c 'credential.helper=!gh auth git-credential' \
    clone --depth 1 --branch "$FRAMEWORK_REF" "$FRAMEWORK_REPO" "$FW" 2>&1 | tail -5 >&2 \
    || die "Klonen des Frameworks fehlgeschlagen."
fi

SCAN="$FW/security/run-security-scan.sh"
[[ -f "$SCAN" ]] || die "Framework-Harness fehlt ($SCAN). Benoetigt einen Framework-Stand MIT security/ (PR 'kali-sicherheitstests')."

log "Kopiere $(find "$REPO/plugins" -mindepth 1 -maxdepth 1 -type d | wc -l) Addon(s) in den Framework-Build …"
mkdir -p "$FW/plugins"
cp -a "$REPO/plugins/." "$FW/plugins/" || die "Kopieren der Addons fehlgeschlagen."

chmod +x "$SCAN" "$FW"/security/checks/*.sh 2>/dev/null || true
log "Starte Framework-DAST gegen den addon-haltigen Build …"
"$SCAN" "$@"
