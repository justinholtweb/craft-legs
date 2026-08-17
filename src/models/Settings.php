<?php

namespace justinholtweb\legs\models;

use Craft;
use craft\base\Model;

/**
 * Plugin settings.
 *
 * Nothing here is `required`: a required rule makes `savePluginSettings()` fail wholesale, so a
 * fresh install could not save *any* setting until that one field was filled in. Values are
 * validated for correctness when present instead.
 */
class Settings extends Model
{
    /** Whether Legs registers its own stylesheet when a table renders. */
    public bool $registerCss = true;

    /** Whether Legs registers the front-end runtime when a table needs one. */
    public bool $registerJs = true;

    /**
     * Whether cell content may contain HTML.
     *
     * On (the default) cells are purified on save and rendered as markup, so an author can put a
     * link or a `<strong>` in a cell. Off, cells are escaped on output and a cell that looks like
     * markup shows as text.
     */
    public bool $allowHtmlInCells = true;

    /** An HTML Purifier config file in `config/htmlpurifier/`, without the extension. */
    public ?string $purifierConfig = null;

    /** Defaults handed to every new table, as a {@see RenderOptions} array. */
    public array $defaultOptions = [];

    /** How often a query-backed table may refresh itself, in seconds. Pro. */
    public int $refreshInterval = 3600;

    /** Whether saving an element refreshes query-backed tables that could contain it. Pro. */
    public bool $refreshOnElementSave = true;

    public function defineRules(): array
    {
        return [
            [['registerCss', 'registerJs', 'allowHtmlInCells', 'refreshOnElementSave'], 'boolean'],
            [['refreshInterval'], 'integer', 'min' => 0],
            [['purifierConfig'], 'string'],
            [['defaultOptions'], 'safe'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'registerCss' => Craft::t('legs', 'Register stylesheet'),
            'registerJs' => Craft::t('legs', 'Register runtime'),
            'allowHtmlInCells' => Craft::t('legs', 'Allow HTML in cells'),
            'purifierConfig' => Craft::t('legs', 'HTML Purifier config'),
            'refreshInterval' => Craft::t('legs', 'Refresh interval'),
            'refreshOnElementSave' => Craft::t('legs', 'Refresh on element save'),
        ];
    }

    public function getDefaultRenderOptions(): RenderOptions
    {
        return RenderOptions::fromArray($this->defaultOptions ?: null);
    }
}
