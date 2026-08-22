<?php
// sprache-cs/Plugin.php
//
// Sprach-Addon: Tschechisch (Čeština). Loest Framework#344 fuer diese Sprache.
//
// WARUM HIER KEIN CODE STEHT. Der Kern erkennt ein Verzeichnis `lang/core/`
// im Plugin-Ordner von selbst und meldet jede darin liegende `<code>.php` als
// zusaetzliche Sprache fuer die Kern-Domaene an
// (App\Plugin\PluginManager, App\I18n\Translator::registerCoreLocale()) -
// Konvention statt Manifest-Pflicht, genauso wie beim `lang/`-Verzeichnis
// eines Addons fuer seine eigenen Texte.
//
// Diese Datei gibt es, weil das Manifest eine `entry` verlangt und der
// PluginManager sie einbindet. Sie registriert bewusst nichts: keine Hooks,
// keine Routen, keine Berechtigungen, keine Tabellen. Ein Sprach-Addon, das
// mehr taete, waere kein Sprach-Addon mehr.
//
// DEN ANZEIGENAMEN LIEFERT DER KERN, nicht dieses Addon
// (Translator::knownLocales()). Sonst erfaende jede Sprache ihre eigene
// Schreibweise, und im Umschalter staende einmal "Čeština" und einmal
// "Tschechisch".
//
// Installation (lokal im Framework-Repo):
//   cp -r sprache-cs plugins/sprache-cs
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.

namespace Plugin\SpracheCs;

class Plugin {
}
