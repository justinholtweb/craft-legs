<?php

namespace justinholtweb\legs\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\Cp;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\models\Edition;
use justinholtweb\legs\models\RenderOptions;
use justinholtweb\legs\models\TableData;
use justinholtweb\legs\Plugin;
use justinholtweb\legs\web\assets\editor\EditorAsset;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The table screens.
 *
 * A hand-written edit screen rather than Craft's element editor: a spreadsheet needs the width
 * of the page and a toolbar of its own, and the autosave machinery has nothing useful to do with
 * a grid that is posted as one JSON document.
 */
class TablesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('legs/tables/_index', [
            'canCreate' => $plugin->tables->canCreate() && Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
            'remaining' => $plugin->tables->remaining(),
            'maxTables' => Edition::maxTables($plugin->isPro()),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionEdit(?int $tableId = null, ?string $site = null, ?Table $table = null): Response
    {
        $plugin = Plugin::getInstance();
        $sitesService = Craft::$app->getSites();
        $editableSites = $sitesService->getEditableSites();

        if ($site !== null) {
            $currentSite = $sitesService->getSiteByHandle($site);

            if (!$currentSite) {
                throw new NotFoundHttpException('Site not found');
            }

            // Asked for a site they cannot edit — say so, rather than quietly showing another.
            if (!in_array($currentSite->id, array_map(fn($s) => $s->id, $editableSites), true)) {
                throw new ForbiddenHttpException('You are not permitted to edit content in this site.');
            }
        } else {
            $currentSite = $sitesService->getCurrentSite();
        }

        if ($table === null) {
            if ($tableId !== null) {
                $table = $plugin->tables->getTableById($tableId, $currentSite->id);

                if (!$table) {
                    throw new NotFoundHttpException('Table not found');
                }

                $plugin->tables->pointAtSite($table, $currentSite->id);
            } else {
                $this->requirePermission(Plugin::PERMISSION_MANAGE);

                if (!$plugin->tables->canCreate()) {
                    throw new ForbiddenHttpException(Craft::t('legs', 'Legs Lite holds up to {max} tables.', [
                        'max' => Edition::LITE_MAX_TABLES,
                    ]));
                }

                $table = new Table();
                $table->siteId = $currentSite->id;
                $table->setData(TableData::fromRows([['', '', ''], ['', '', ''], ['', '', '']], 1));
                $table->setOptions($plugin->getSettings()->getDefaultRenderOptions());
            }
        }

        Craft::$app->getView()->registerAssetBundle(EditorAsset::class);

        return $this->renderTemplate('legs/tables/_edit', [
            'table' => $table,
            'isNew' => !$table->id,
            'isPro' => $plugin->isPro(),
            'elementTypes' => $this->elementTypeOptions(),
            'canDelete' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_DELETE),
            'currentSite' => $currentSite,
            'showSites' => Craft::$app->getIsMultiSite() && count($editableSites) > 1,
            'siteMenuItems' => Cp::siteMenuItems($editableSites, $currentSite),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $tableId = $request->getBodyParam('tableId');
        $siteId = (int)$request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;

        if ($tableId) {
            // Loaded *in the posted site*, so the save writes that site's content row and leaves
            // the others alone.
            $table = $plugin->tables->getTableById((int)$tableId, $siteId);

            if (!$table) {
                throw new NotFoundHttpException('Table not found');
            }

            // The lookup falls back to another site when this one has no row yet — fine for
            // reading, dangerous for writing: without this the save would land on whichever site
            // answered, quietly overwriting *that* site's grid with this one's edits.
            $plugin->tables->pointAtSite($table, $siteId);
        } else {
            $table = new Table();
            $table->siteId = $siteId;
        }

        $table->title = $request->getBodyParam('title');
        $table->handle = $request->getBodyParam('handle') ?: null;
        $table->caption = $request->getBodyParam('caption');
        $table->description = $request->getBodyParam('description');
        $table->source = $request->getBodyParam('source', Table::SOURCE_MANUAL);
        $table->translationMethod = $request->getBodyParam('translationMethod', Table::TRANSLATION_NONE);

        $data = $request->getBodyParam('data');

        if (is_string($data)) {
            $table->setData($data);
        } elseif (is_array($data)) {
            $table->setData($data);
        }

        $table->setOptions($this->postedOptions($request->getBodyParam('options', []), $plugin->isPro()));

        if ($table->source === Table::SOURCE_QUERY) {
            if (!Edition::allowsQueryTables($plugin->isPro())) {
                $table->source = Table::SOURCE_MANUAL;
                $table->addError('source', Craft::t('legs', 'Tables built from an element query are a Pro feature.'));
            } else {
                $table->setSourceConfig($this->postedSourceConfig($request->getBodyParam('sourceConfig', [])));
                $table->setData($plugin->querySource->build($table->getSourceConfig() ?? [], $table->siteId));
            }
        }

        if ($table->hasErrors() || !$plugin->tables->saveTable($table)) {
            return $this->asModelFailure(
                $table,
                Craft::t('legs', 'Couldn’t save table.'),
                'table',
                routeParams: ['table' => $table],
            );
        }

        return $this->asModelSuccess($table, Craft::t('legs', 'Table saved.'), 'table');
    }

    /**
     * @throws Throwable
     */
    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        $table = Plugin::getInstance()->tables->getTableById((int)Craft::$app->getRequest()->getRequiredBodyParam('tableId'));

        if (!$table) {
            throw new NotFoundHttpException('Table not found');
        }

        Plugin::getInstance()->tables->deleteTable($table);

        return $this->asSuccess(Craft::t('legs', 'Table deleted.'), redirect: 'legs/tables');
    }

    /**
     * @throws Throwable
     */
    public function actionDuplicate(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();

        if (!$plugin->tables->canCreate()) {
            Craft::$app->getSession()->setError(Craft::t('legs', 'Legs Lite holds up to {max} tables.', ['max' => Edition::LITE_MAX_TABLES]));

            return $this->redirect('legs/tables');
        }

        $table = $plugin->tables->getTableById((int)Craft::$app->getRequest()->getRequiredBodyParam('tableId'));

        if (!$table) {
            throw new NotFoundHttpException('Table not found');
        }

        $copy = $plugin->tables->duplicate($table);

        return $this->asSuccess(Craft::t('legs', 'Table duplicated.'), redirect: "legs/tables/{$copy->id}");
    }

    /**
     * Replaces a table's grid from an uploaded file.
     */
    public function actionImport(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $siteId = (int)$request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;
        $table = $plugin->tables->getTableById((int)$request->getRequiredBodyParam('tableId'), $siteId);

        if (!$table) {
            throw new NotFoundHttpException('Table not found');
        }

        $plugin->tables->pointAtSite($table, $siteId);

        $file = UploadedFile::getInstanceByName('file');

        if (!$file) {
            return $this->asFailure(Craft::t('legs', 'No file was uploaded.'));
        }

        try {
            $data = $plugin->importer->fromFile($file->tempName, $file->name, (int)$request->getBodyParam('headerRows', 1));
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        $table->setData($data);
        $table->source = Table::SOURCE_IMPORT;

        if (!$plugin->tables->saveTable($table)) {
            return $this->asModelFailure($table, Craft::t('legs', 'Couldn’t save table.'), 'table');
        }

        return $this->asSuccess(
            Craft::t('legs', 'Imported {rows} rows.', ['rows' => $data->getRowCount()]),
            redirect: "legs/tables/{$table->id}",
        );
    }

    public function actionExport(int $tableId, string $format = 'csv'): Response
    {
        $plugin = Plugin::getInstance();
        $table = $plugin->tables->getTableById($tableId);

        if (!$table) {
            throw new NotFoundHttpException('Table not found');
        }

        $exporter = $plugin->exporter;

        return match ($format) {
            'json' => $this->asDownload($exporter->toJson($table), $exporter->filename($table, 'json'), 'application/json'),
            'html' => $this->asDownload($exporter->toHtml($table), $exporter->filename($table, 'html'), 'text/html'),
            'xlsx' => $this->xlsxDownload($table),
            default => $this->asDownload($exporter->toCsv($table->getData()), $exporter->filename($table, 'csv'), 'text/csv'),
        };
    }

    /**
     * @throws Throwable
     */
    public function actionRefresh(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $siteId = (int)$request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id;
        $table = $plugin->tables->getTableById((int)$request->getRequiredBodyParam('tableId'), $siteId);

        if (!$table) {
            throw new NotFoundHttpException('Table not found');
        }

        $plugin->tables->pointAtSite($table, $siteId);

        if (!Edition::allowsQueryTables($plugin->isPro())) {
            throw new ForbiddenHttpException(Craft::t('legs', 'Tables built from an element query are a Pro feature.'));
        }

        $plugin->querySource->refresh($table);

        return $this->asSuccess(Craft::t('legs', 'Table refreshed.'), redirect: "legs/tables/{$table->id}");
    }

    private function asDownload(string $contents, string $filename, string $mimeType): Response
    {
        return Craft::$app->getResponse()->sendContentAsFile($contents, $filename, ['mimeType' => $mimeType]);
    }

    /**
     * @throws Throwable
     */
    private function xlsxDownload(Table $table): Response
    {
        $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid('legs', true) . '.xlsx';

        try {
            Plugin::getInstance()->exporter->toXlsx($table, $path);

            return $this->asDownload(file_get_contents($path), Plugin::getInstance()->exporter->filename($table, 'xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        } finally {
            if (is_file($path)) {
                FileHelper::unlink($path);
            }
        }
    }

    /**
     * Turns posted checkboxes into options, refusing anything this edition cannot serve.
     */
    private function postedOptions(array $posted, bool $isPro): RenderOptions
    {
        $options = RenderOptions::fromArray($posted);

        return Edition::applyTo($options, $isPro);
    }

    private function postedSourceConfig(array $posted): array
    {
        $columns = [];

        foreach ($posted['columns'] ?? [] as $column) {
            if (!is_array($column) || trim((string)($column['value'] ?? '')) === '') {
                continue;
            }

            $columns[] = [
                'label' => trim((string)($column['label'] ?? '')) ?: $column['value'],
                'type' => $column['type'] ?? 'attribute',
                'value' => trim((string)$column['value']),
            ];
        }

        $criteria = $posted['criteria'] ?? [];

        if (is_string($criteria)) {
            $decoded = json_decode($criteria, true);
            $criteria = is_array($decoded) ? $decoded : [];
        }

        return [
            'elementType' => $posted['elementType'] ?? \craft\elements\Entry::class,
            'criteria' => $criteria,
            'columns' => $columns,
            'includeHeader' => (bool)($posted['includeHeader'] ?? true),
        ];
    }

    /** @return array<string, string> */
    private function elementTypeOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $class) {
            if ($class === Table::class) {
                continue;
            }

            /** @var string|\craft\base\ElementInterface $class */
            $options[$class] = $class::pluralDisplayName();
        }

        asort($options);

        return $options;
    }
}
