<?php

namespace justinholtweb\legs\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\legs\elements\db\TableQuery;
use justinholtweb\legs\models\RenderOptions;
use justinholtweb\legs\models\TableData;
use justinholtweb\legs\Plugin;
use justinholtweb\legs\records\TableContentRecord;
use justinholtweb\legs\records\TableRecord;
use Twig\Markup;
use yii\base\InvalidConfigException;

/**
 * A table in the library.
 *
 * Being a real element buys the index, search, permissions, relations, restore-from-trash — and,
 * the reason it is an element rather than a row in a settings screen, **reference tags**.
 * `craft\htmlfield\HtmlFieldData` parses ref tags over every rich-text value, so
 * `{legs:my-prices:render}` renders this table inside CKEditor content, Redactor content, or any
 * other HTML field, with no template changes and no per-editor rendering code.
 *
 * Not localized: `isLocalized()` is left false, so a table lives once on the primary site and
 * resolves from every site (Craft forces the query to the primary site itself). One grid, one
 * source of truth — which is the whole point of a central library.
 *
 * @property-read TableData $data
 * @property-read RenderOptions $options
 */
class Table extends Element
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_QUERY = 'query';

    public const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_IMPORT, self::SOURCE_QUERY];

    /** One grid, copied to every site. */
    public const TRANSLATION_NONE = 'none';

    /** A grid per site, edited independently. */
    public const TRANSLATION_SITE = 'site';

    public const TRANSLATION_METHODS = [self::TRANSLATION_NONE, self::TRANSLATION_SITE];

    public ?string $handle = null;
    public ?string $caption = null;
    public ?string $description = null;
    public string $source = self::SOURCE_MANUAL;
    public string $translationMethod = self::TRANSLATION_NONE;
    public int $rowCount = 0;
    public int $colCount = 0;
    public ?DateTime $lastRefreshedAt = null;

    private ?TableData $_data = null;
    private ?RenderOptions $_options = null;
    private ?array $_sourceConfig = null;

    public static function displayName(): string
    {
        return Craft::t('legs', 'Table');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('legs', 'table');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('legs', 'Tables');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('legs', 'tables');
    }

    public static function refHandle(): ?string
    {
        return 'legs';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return false;
    }

    /**
     * Tables are localized — but every table exists on every site, whether or not its content is
     * translated.
     *
     * That is the whole point: an embed on the Spanish page has to be able to *find* the table,
     * and a table that only exists on the primary site cannot be found from anywhere else.
     * Whether the words differ per site is a separate question, answered by
     * {@see self::getIsTranslatable()}.
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    public function getSupportedSites(): array
    {
        return Craft::$app->getSites()->getAllSiteIds();
    }

    public function getIsTranslatable(): bool
    {
        return $this->translationMethod === self::TRANSLATION_SITE;
    }

    public static function trackChanges(): bool
    {
        return true;
    }

    public static function find(): ElementQueryInterface
    {
        return new TableQuery(static::class);
    }

    // Data
    // -------------------------------------------------------------------------

    /**
     * @param TableData|array|string|null $value Whatever the caller has — the element query hands
     * over raw JSON, the editor hands over a decoded array, and importers hand over a model.
     */
    public function setData(mixed $value): void
    {
        $this->_data = match (true) {
            $value instanceof TableData => $value,
            is_array($value) => TableData::fromArray($value),
            is_string($value) => TableData::fromJson($value),
            default => null,
        };
    }

    public function getData(): TableData
    {
        return $this->_data ??= TableData::fromArray(null);
    }

    /**
     * @param RenderOptions|array|string|null $value
     */
    public function setOptions(mixed $value): void
    {
        $this->_options = match (true) {
            $value instanceof RenderOptions => $value,
            is_array($value) => RenderOptions::fromArray($value),
            is_string($value) => RenderOptions::fromJson($value),
            default => null,
        };
    }

    public function getOptions(): RenderOptions
    {
        return $this->_options ??= Plugin::getInstance()->getSettings()->getDefaultRenderOptions();
    }

    /**
     * @param array|string|null $value
     */
    public function setSourceConfig(mixed $value): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        $this->_sourceConfig = is_array($value) ? $value : null;
    }

    public function getSourceConfig(): ?array
    {
        return $this->_sourceConfig;
    }

    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Renders the table.
     *
     * @param array $options Per-call overrides, for templates that want one table to behave
     * differently. Embeds cannot pass these — a ref tag has nowhere to put them — which is why
     * the table's own options are the ones that matter.
     */
    public function render(array $options = []): Markup
    {
        return Plugin::getInstance()->renderer->render($this, $options);
    }

    /**
     * The property `{legs:my-prices:render}` resolves.
     *
     * Craft splices the result in raw, so this is the entire rich-text integration: whatever
     * editor produced the content, the tag renders the same table the same way.
     */
    public function getRender(): Markup
    {
        return $this->render();
    }

    /**
     * Whether the front end may hand this table to a visitor as a file.
     *
     * Two conditions, both the author's: the table has to be enabled, and its `downloadable`
     * option has to be on. Nothing about the *request* can grant it — see
     * {@see \justinholtweb\legs\controllers\ExportController}.
     */
    public function getIsDownloadable(): bool
    {
        return $this->getOptions()->downloadable && $this->enabled && $this->getEnabledForSite();
    }

    /**
     * The tag an author copies out of the CP to embed this table.
     *
     * @param array<string, mixed> $overrides Presentation options for this embed only.
     */
    public function getEmbedCode(array $overrides = []): string
    {
        $ref = $this->handle ?: $this->id;

        if (!$overrides) {
            return sprintf('{legs:%s:render}', $ref);
        }

        return sprintf('{legs:%s:render(%s)}', $ref, RenderOptions::toEmbedOptions($overrides));
    }

    /**
     * Lets a reference tag carry presentation options: `{legs:prices:render(compact,perPage=10)}`.
     *
     * Craft resolves a reference tag by reading the named property off the element, and its
     * pattern is loose enough to allow parentheses — so an embed can say something about *this*
     * appearance without a second mechanism, a second template tag, or anything for the editor
     * integrations to store beyond the tag they already write.
     *
     * `render` on its own is not handled here: Yii finds {@see self::getRender()} first.
     */
    public function __get($name)
    {
        $overrides = $this->parseRenderCall((string)$name);

        return $overrides !== null ? $this->render($overrides) : parent::__get($name);
    }

    public function __isset($name): bool
    {
        return $this->parseRenderCall((string)$name) !== null || parent::__isset($name);
    }

    /**
     * @return array<string, mixed>|null The overrides, or null if this is not a render call.
     */
    private function parseRenderCall(string $name): ?array
    {
        if (!preg_match('/^render\((.*)\)$/s', $name, $matches)) {
            return null;
        }

        return RenderOptions::parseEmbedOptions($matches[1]);
    }

    // Element plumbing
    // -------------------------------------------------------------------------

    public function getRef(): ?string
    {
        return $this->handle;
    }

    public function getUiLabel(): string
    {
        return $this->title ?: Craft::t('legs', 'Untitled table');
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("legs/tables/$this->id");
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('legs/tables');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        // Dashes are allowed on purpose: `{legs:price-list:render}` parses fine — Craft's ref
        // pattern is far looser than its handle pattern — and reads better than `priceList`.
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => Craft::t('legs', 'Handles must start with a letter and contain only letters, numbers, dashes and underscores.')];
        $rules[] = [['handle'], 'required'];
        $rules[] = [['handle'], 'validateHandleIsFree'];
        $rules[] = [['caption', 'description'], 'string'];
        $rules[] = [['source'], 'in', 'range' => self::SOURCES];
        $rules[] = [['translationMethod'], 'in', 'range' => self::TRANSLATION_METHODS];
        $rules[] = [['rowCount', 'colCount'], 'integer'];

        return $rules;
    }

    /**
     * Handles have to be unique among *live* tables, not among rows.
     *
     * A UniqueValidator over `legs_tables` would be the obvious rule and is the wrong one: a
     * deleted table keeps its row until garbage collection, so its handle would stay taken
     * forever, and an author would be told "already taken" by a table they cannot see anywhere.
     * Element queries exclude trashed elements, so asking one is the same question an author is
     * asking. {@see self::afterRestore()} handles the other side of the trade.
     */
    public function validateHandleIsFree(string $attribute): void
    {
        if (!$this->handle) {
            return;
        }

        if (Plugin::getInstance()->tables->handleIsTaken($this->handle, $this->id)) {
            $this->addError($attribute, Craft::t('legs', 'Another table is already using the handle “{handle}”.', [
                'handle' => $this->handle,
            ]));
        }
    }

    /**
     * Frees the handle when a table goes to the trash.
     *
     * Craft's delete is a soft delete, so the row — and its handle — outlive the table an author
     * can see. Without this, deleting "prices" and immediately recreating it fails on a unique
     * index pointing at something invisible. The handle is parked under a suffix instead, and
     * {@see self::afterRestore()} claims it back.
     */
    public function afterDelete(): void
    {
        if ($this->handle && $this->id) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%legs_tables}}', ['handle' => $this->parkedHandle()], ['id' => $this->id])
                ->execute();
        }

        parent::afterDelete();
    }

    /**
     * Takes the handle back out of the trash, or a variation of it if the name has been reused
     * meanwhile — a table that cannot come back is worse than one that comes back as `prices-2`.
     */
    public function afterRestore(): void
    {
        if ($this->id) {
            $handle = preg_replace('/--trashed-\d+$/', '', (string)$this->handle) ?: 'table';
            $tables = Plugin::getInstance()->tables;

            if ($tables->handleIsTaken($handle, $this->id)) {
                $handle = $tables->uniqueHandle($handle, $this->id);
            }

            $this->handle = $handle;
            Craft::$app->getDb()->createCommand()
                ->update('{{%legs_tables}}', ['handle' => $handle], ['id' => $this->id])
                ->execute();
        }

        parent::afterRestore();
    }

    private function parkedHandle(): string
    {
        return substr((string)$this->handle, 0, 200) . '--trashed-' . $this->id;
    }

    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            'handle' => Craft::t('legs', 'Handle'),
            'caption' => Craft::t('legs', 'Caption'),
        ]);
    }

    public function beforeSave(bool $isNew): bool
    {
        if (!$this->handle && $this->title) {
            $this->handle = Plugin::getInstance()->tables->uniqueHandle($this->title, $this->id);
        }

        $data = $this->getData()->normalize();
        $this->rowCount = $data->getRowCount();
        $this->colCount = $data->getColCount();

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $this->saveGlobalRow($isNew);

            if ($this->getIsTranslatable()) {
                $this->saveContentRow($this->siteId);
            } else {
                // One grid, copied everywhere — written from here rather than left to
                // propagation, because a propagated element carries the *target* site's content
                // (Craft loads the existing site element), so it has nothing to copy from.
                foreach ($this->getSupportedSites() as $siteId) {
                    $this->saveContentRow(is_array($siteId) ? $siteId['siteId'] : $siteId);
                }
            }
        } elseif (!$this->contentRowExists($this->siteId)) {
            // Craft is giving this table a presence on a site it did not have one on. A fresh
            // translation starts as a copy of whatever is on the element right now, which is
            // what an author expects to open and edit.
            $this->saveContentRow($this->siteId);
        }

        parent::afterSave($isNew);
    }

    private function saveGlobalRow(bool $isNew): void
    {
        $record = $isNew ? new TableRecord() : TableRecord::findOne($this->id);

        if (!$record) {
            // A restored element, or one whose row went missing — write it back rather than
            // failing the save and leaving the element without its grid.
            $record = new TableRecord();
            $isNew = true;
        }

        if ($isNew) {
            $record->id = $this->id;
        }

        $record->handle = $this->handle;
        $record->options = $this->getOptions()->toJson();
        $record->source = $this->source;
        $record->sourceConfig = $this->_sourceConfig ? json_encode($this->_sourceConfig) : null;
        $record->translationMethod = $this->translationMethod;
        $record->rowCount = $this->rowCount;
        $record->colCount = $this->colCount;
        $record->save(false);
    }

    private function saveContentRow(int $siteId): void
    {
        $record = TableContentRecord::findOne(['id' => $this->id, 'siteId' => $siteId]);

        if (!$record) {
            $record = new TableContentRecord();
            $record->id = $this->id;
            $record->siteId = $siteId;
        }

        $record->caption = $this->caption;
        $record->description = $this->description;
        $record->data = $this->getData()->toJson();
        $record->lastRefreshedAt = Db::prepareDateForDb($this->lastRefreshedAt);
        $record->save(false);
    }

    private function contentRowExists(int $siteId): bool
    {
        return TableContentRecord::find()->where(['id' => $this->id, 'siteId' => $siteId])->exists();
    }

    /**
     * Every cell, so a search for a value inside a table finds the table that holds it.
     */
    protected function searchKeywords(string $attribute): string
    {
        if ($attribute === 'cells') {
            $words = [];
            foreach ($this->getData()->cells as $row) {
                foreach ($row as $cell) {
                    $words[] = strip_tags($cell);
                }
            }

            return implode(' ', $words);
        }

        return parent::searchKeywords($attribute);
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'handle', 'caption', 'description', 'cells'];
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('legs', 'All tables'),
                'defaultSort' => ['title', 'asc'],
            ],
            ['heading' => Craft::t('legs', 'Built from')],
            [
                'key' => 'source:manual',
                'label' => Craft::t('legs', 'Hand-edited'),
                'criteria' => ['source' => self::SOURCE_MANUAL],
            ],
            [
                'key' => 'source:import',
                'label' => Craft::t('legs', 'Imported'),
                'criteria' => ['source' => self::SOURCE_IMPORT],
            ],
            [
                'key' => 'source:query',
                'label' => Craft::t('legs', 'Element query'),
                'criteria' => ['source' => self::SOURCE_QUERY],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'handle' => ['label' => Craft::t('legs', 'Handle')],
            'size' => ['label' => Craft::t('legs', 'Size')],
            'embedCode' => ['label' => Craft::t('legs', 'Embed')],
            'source' => ['label' => Craft::t('legs', 'Built from')],
            'dateUpdated' => ['label' => Craft::t('app', 'Last Updated')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['handle', 'size', 'embedCode', 'dateUpdated'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'legs_tables.handle' => Craft::t('legs', 'Handle'),
            'legs_tables.rowCount' => Craft::t('legs', 'Rows'),
            'dateUpdated' => Craft::t('app', 'Last Updated'),
            'dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'handle' => Html::tag('code', Html::encode((string)$this->handle)),
            // Read off the loaded grid rather than the denormalised counts, so a translated
            // table shows *this* site's shape rather than the one it was created with.
            'size' => Craft::t('legs', '{rows} × {cols}', [
                'rows' => $this->getData()->getRowCount(),
                'cols' => $this->getData()->getColCount(),
            ]),
            'embedCode' => Cp::renderTemplate('_includes/forms/copytextbtn.twig', [
                'class' => ['code', 'small', 'light'],
                'value' => $this->getEmbedCode(),
            ]),
            'source' => match ($this->source) {
                self::SOURCE_QUERY => Craft::t('legs', 'Element query'),
                self::SOURCE_IMPORT => Craft::t('legs', 'Imported'),
                default => Craft::t('legs', 'Hand-edited'),
            },
            default => parent::attributeHtml($attribute),
        };
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(\craft\elements\User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(\craft\elements\User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDuplicate(\craft\elements\User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDelete(\craft\elements\User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE);
    }

    public function canCreateDrafts(\craft\elements\User $user): bool
    {
        return false;
    }

    /**
     * @throws InvalidConfigException
     */
    public function getPlugin(): Plugin
    {
        $plugin = Plugin::getInstance();

        if (!$plugin) {
            throw new InvalidConfigException('Legs is not installed.');
        }

        return $plugin;
    }
}
