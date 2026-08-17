<?php

namespace justinholtweb\legs\richtext;

use Craft;
use craft\ckeditor\helpers\CkeditorConfig;
use craft\ckeditor\Plugin as CkeditorPlugin;
use justinholtweb\legs\web\assets\ckeditor\LegsCkeditorAsset;

/**
 * Teaches CKEditor how to insert a Legs embed.
 *
 * Editing only. Rendering is Craft's ref-tag parsing, which knows nothing about CKEditor — so
 * this integration can be absent, broken or disabled and existing embeds keep working.
 */
class CkeditorIntegration
{
    public function register(): void
    {
        // CkeditorConfig arrived with the ESM/import-map rewrite of the CKEditor plugin. On the
        // older DLL-based versions the package format is different enough that half-registering
        // would break the editor rather than merely lack a button — so on those, do nothing and
        // leave authors to type the ref tag or use Redactor.
        if (!class_exists(CkeditorPlugin::class) || !class_exists(CkeditorConfig::class)) {
            return;
        }

        CkeditorPlugin::registerCkeditorPackage(LegsCkeditorAsset::class, 'index.js');

        // Register the plugin and its toolbar item directly rather than letting the asset bundle
        // do it, because the asset bundle does it too late for the field settings screen — see
        // the note on LegsCkeditorAsset::$pluginNames.
        CkeditorConfig::registerPackage(LegsCkeditorAsset::NAMESPACE, [
            'plugins' => [LegsCkeditorAsset::PLUGIN_NAME],
            'toolbarItems' => [LegsCkeditorAsset::TOOLBAR_ITEM],
        ]);

        // …and add the import-map entry ourselves.
        //
        // The CKEditor plugin adds one for every registered package, but it does that in its own
        // `init()` — and plugins initialise in handle order, so `ckeditor` has already been and
        // gone by the time `legs` gets to register anything. Without this the editor loads a
        // script that imports a bare specifier nothing has mapped, and the *whole field* fails
        // to boot, not just our button.
        $view = Craft::$app->getView();
        $assetManager = $view->getAssetManager();
        $bundle = $assetManager->getBundle(LegsCkeditorAsset::class);

        if ($bundle instanceof LegsCkeditorAsset) {
            // With a timestamp, deliberately. The published directory's hash is derived from its
            // path and its *directory* mtime, and editing a file inside a directory does not
            // change that — so without `?v=`, the module URL stays identical while its contents
            // change, and every browser that has already imported it keeps running the old copy.
            // An ES module is cached far more stubbornly than a script tag; this is the only
            // thing that busts it.
            $view->registerJsImport(LegsCkeditorAsset::NAMESPACE, $assetManager->getAssetUrl($bundle, 'index.js', true));
        }
    }
}
