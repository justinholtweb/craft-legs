<?php

namespace justinholtweb\legs\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\legs\Plugin;
use yii\web\Response;

/**
 * Feeds the table picker that CKEditor and Redactor share.
 */
class EmbedsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionTables(): Response
    {
        $tables = [];

        foreach (Plugin::getInstance()->tables->getAllTables() as $table) {
            $tables[] = [
                'id' => $table->id,
                'handle' => $table->handle,
                'title' => $table->title,
                'size' => Craft::t('legs', '{rows} × {cols}', ['rows' => $table->rowCount, 'cols' => $table->colCount]),
                'embedCode' => $table->getEmbedCode(),
                'cpEditUrl' => $table->getCpEditUrl(),
            ];
        }

        return $this->asJson([
            'tables' => $tables,
            'newTableUrl' => \craft\helpers\UrlHelper::cpUrl('legs/tables/new'),
            'isPro' => Plugin::getInstance()->isPro(),
            // Labelled here rather than in the JS so the picker carries no dictionary of its own.
            'options' => [
                ['name' => 'compact', 'label' => Craft::t('legs', 'Compact'), 'type' => 'toggle'],
                ['name' => 'striped', 'label' => Craft::t('legs', 'Striped rows'), 'type' => 'toggle', 'default' => true],
                ['name' => 'bordered', 'label' => Craft::t('legs', 'Cell borders'), 'type' => 'toggle', 'default' => true],
                ['name' => 'sortable', 'label' => Craft::t('legs', 'Sortable'), 'type' => 'toggle', 'default' => true],
                ['name' => 'searchable', 'label' => Craft::t('legs', 'Searchable'), 'type' => 'toggle', 'pro' => true],
                ['name' => 'paginate', 'label' => Craft::t('legs', 'Paginate'), 'type' => 'toggle', 'pro' => true],
                ['name' => 'perPage', 'label' => Craft::t('legs', 'Rows per page'), 'type' => 'number', 'pro' => true],
            ],
            'labels' => [
                'insert' => Craft::t('legs', 'Insert'),
                'options' => Craft::t('legs', 'Options for this embed'),
                'back' => Craft::t('legs', 'Back'),
                'inherit' => Craft::t('legs', 'Anything left alone follows the table’s own settings.'),
            ],
        ]);
    }
}
