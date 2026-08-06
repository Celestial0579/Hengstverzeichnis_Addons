# qr-code

Zeigt auf der öffentlichen Pferde-Detailseite einen aufklappbaren QR-Code,
der auf die Profil-URL verlinkt (z. B. zum Scannen von einem Aushang am
Stall), sowie eine eigene, druckfertige Aushang-Ansicht (Foto + Name +
großer QR-Code).

Löst [Celestial0579/Hengstverzeichnis_Addons#17](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/17).

## Installation

```bash
cp -r qr-code /pfad/zu/Hengstverzeichnis_Framework/plugins/qr-code
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.
Keine Berechtigung nötig - zeigt nur eine andere Aufbereitung der ohnehin
öffentlich einsehbaren Profil-URL.

## Warum keine neue Abhängigkeit?

Der QR-Code wird komplett clientseitig mit der bereits im Framework-Kern
vendorten Bibliothek `public/js/qrcode.js` gerendert - derselben, die für
die 2FA-Einrichtung genutzt wird (siehe `src/Views/2fa_setup.php`). Kein
externer QR-Code-Dienst, keine neue Composer-/npm-Abhängigkeit.

## Nutzung

1. Auf der öffentlichen Pferde-Detailseite auf "📱 QR-Code anzeigen"
   klicken, um den QR-Code direkt auf der Seite einzublenden.
2. Über "🖨️ Aushang drucken" (öffnet in neuem Tab) eine druckfertige Ansicht
   mit Foto, Name und großem QR-Code öffnen und über die Browser-
   Druckfunktion ausdrucken.
