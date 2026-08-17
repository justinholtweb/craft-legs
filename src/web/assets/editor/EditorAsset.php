<?php

namespace justinholtweb\legs\web\assets\editor;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The grid editor, shared by the table screen and by inline fields — one editor, one set of
 * keyboard shortcuts, one paste implementation.
 */
class EditorAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'legs-editor.js',
    ];

    public $css = [
        'legs-editor.css',
    ];
}
