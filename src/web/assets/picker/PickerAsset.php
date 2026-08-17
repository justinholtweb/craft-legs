<?php

namespace justinholtweb\legs\web\assets\picker;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The table picker, shared by every editor integration.
 *
 * One picker rather than one per editor: the choice an author is making — which table — is the
 * same one whatever is doing the asking, and a second implementation would drift.
 */
class PickerAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'legs-picker.js',
    ];

    public $css = [
        'legs-picker.css',
    ];
}
