<?php

namespace justinholtweb\legs;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\FileHelper;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use HTMLPurifier_Config;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\fields\TableField;
use justinholtweb\legs\models\Settings;
use justinholtweb\legs\services\Exporter;
use justinholtweb\legs\services\Formulas;
use justinholtweb\legs\services\Importer;
use justinholtweb\legs\services\QuerySource;
use justinholtweb\legs\services\Renderer;
use justinholtweb\legs\services\Tables;
use justinholtweb\legs\twig\Extension;
use justinholtweb\legs\twig\LegsVariable;
use yii\base\Event;

/**
 * Legs — build a table once, use it anywhere.
 *
 * @property-read Tables $tables
 * @property-read Renderer $renderer
 * @property-read Formulas $formulas
 * @property-read Importer $importer
 * @property-read Exporter $exporter
 * @property-read QuerySource $querySource
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'legs:viewTables';
    public const PERMISSION_MANAGE = 'legs:manageTables';
    public const PERMISSION_DELETE = 'legs:deleteTables';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'legs';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'tables' => Tables::class,
                'renderer' => Renderer::class,
                'formulas' => Formulas::class,
                'importer' => Importer::class,
                'exporter' => Exporter::class,
                'querySource' => QuerySource::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerFieldTypes();
        $this->registerCpRoutes();
        $this->registerSiteRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerRichTextIntegrations();
        $this->querySource->register();
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('legs', 'Legs');

        $item['subnav'] = [
            'tables' => [
                'label' => Craft::t('legs', 'Tables'),
                'url' => 'legs/tables',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('legs', 'Settings'),
                'url' => 'settings/plugins/legs',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('legs/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'purifierConfigs' => $this->purifierConfigOptions(),
        ]);
    }

    /**
     * The HTML Purifier config cell content is cleaned with.
     *
     * Table cells are a rich-text surface like any other — an author can put a link or a
     * `<strong>` in one — so they get the same treatment Craft's own rich-text fields get,
     * including the site's own `config/htmlpurifier/` file if it has named one.
     */
    public function purifierConfig(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->autoFinalize = false;

        $options = [
            'Attr.AllowedFrameTargets' => ['_blank'],
            'Attr.EnableID' => true,
            'HTML.SafeIframe' => true,
        ];

        $file = $this->getSettings()->purifierConfig;

        if ($file) {
            $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier' . DIRECTORY_SEPARATOR . $file . '.json';

            if (is_file($path)) {
                $decoded = json_decode(file_get_contents($path), true);

                if (is_array($decoded)) {
                    $options = $decoded;
                }
            }
        }

        foreach ($options as $option => $value) {
            $config->set($option, $value);
        }

        return $config;
    }

    /** @return array<string, string> */
    public function purifierConfigOptions(): array
    {
        $options = ['' => Craft::t('legs', 'Default')];
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier';

        if (is_dir($path)) {
            foreach (FileHelper::findFiles($path, ['only' => ['*.json'], 'recursive' => false]) as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $options[$name] = $name;
            }
        }

        return $options;
    }

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Table::class;
        });
    }

    private function registerFieldTypes(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = TableField::class;
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'legs' => 'legs/tables/index',
                'legs/tables' => 'legs/tables/index',
                'legs/tables/new' => 'legs/tables/edit',
                'legs/tables/<tableId:\d+>' => 'legs/tables/edit',
            ];
        });
    }

    /**
     * The front-end download route, `legs/export/<handle>/<format>` unless the site moved it.
     *
     * Registered for everyone, because the gate is the table's own `downloadable` option rather
     * than the existence of the route — a site with no downloadable tables has a route that
     * 404s, which is what it would have anyway. A blank `exportPath` setting registers nothing.
     */
    private function registerSiteRoutes(): void
    {
        $path = $this->getSettings()->normalizedExportPath();

        if ($path === null) {
            return;
        }

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) use ($path) {
            $event->rules += [
                "$path/<handle:[\w\-]+>" => 'legs/export/download',
                "$path/<handle:[\w\-]+>/<format:(csv|json|html|xlsx)>" => 'legs/export/download',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('legs', 'Legs'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('legs', 'View tables'),
                        'nested' => [
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('legs', 'Create and edit tables'),
                            ],
                            self::PERMISSION_DELETE => [
                                'label' => Craft::t('legs', 'Delete tables'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('legs', LegsVariable::class);
        });

        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    /**
     * Editing affordances only.
     *
     * Rendering inside rich text is done by Craft's own ref-tag parsing — see
     * {@see Table::getRender()} — so these integrations exist purely to help an author write the
     * tag without typing it, and their absence costs nothing but convenience.
     */
    private function registerRichTextIntegrations(): void
    {
        // Purification happens on save, which can be a console request (a resave, a migration,
        // an element API write), so this one is not CP-only.
        (new richtext\PurifierSupport())->register();

        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        (new richtext\CkeditorIntegration())->register();
        (new richtext\RedactorIntegration())->register();
    }
}
