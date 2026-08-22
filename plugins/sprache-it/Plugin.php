<?php
// sprache-it/Plugin.php
//
// Sprach-Addon: Italienisch (Italiano). Loest Framework#344 fuer diese Sprache.
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
// Schreibweise, und im Umschalter staende einmal "Italiano" und einmal
// "Italienisch".
//
// Installation (lokal im Framework-Repo):
//   cp -r sprache-it plugins/sprache-it
// Danach unter Admin -> Plugins verwalten (/admin/plugins) aktivieren.

namespace Plugin\SpracheIt;

class Plugin {
}
