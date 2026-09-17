<?php

namespace justinholtweb\legs\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\legs\models\Edition;
use justinholtweb\legs\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Hands a table to a visitor as a file.
 *
 * Anonymous by necessity — the tables this serves are on public pages — so the *table* carries
 * the permission: nothing here is served unless its author turned `downloadable` on. A handle is
 * short and guessable, and without that gate the route would be a way to read every table in the
 * library, embedded or not, draft or not.
 *
 * Everything it refuses, it refuses as a 404: a visitor who can tell "not downloadable" from
 * "no such table" can enumerate the library by the difference.
 */
class ExportController extends Controller
{
    public array|bool|int $allowAnonymous = true;

    /**
     * `/legs/export/prices/csv`, or the action path for a site that has turned the route off.
     *
     * @throws NotFoundHttpException
     * @throws Throwable
     */
    public function actionDownload(string $handle, string $format = 'csv'): Response
    {
        $this->requireSiteRequest();

        $plugin = Plugin::getInstance();
        // Site-aware by omission: the Spanish page downloads the Spanish content of a translated
        // table, the same grid the page itself rendered.
        $table = $plugin->tables->getTableByHandle($handle);

        if (!$table || !$table->getIsDownloadable() || !$this->allowsFormat($format)) {
            throw new NotFoundHttpException('Table not found');
        }

        $exporter = $plugin->exporter;

        return match ($format) {
            'json' => $this->send($exporter->toJson($table), $exporter->filename($table, 'json'), 'application/json'),
            'html' => $this->send($exporter->toHtml($table), $exporter->filename($table, 'html'), 'text/html'),
            'xlsx' => $this->send(
                $exporter->xlsxContents($table),
                $exporter->filename($table, 'xlsx'),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ),
            default => $this->send($exporter->toCsv($table->getData()), $exporter->filename($table, 'csv'), 'text/csv'),
        };
    }

    /**
     * Which formats this install can actually serve.
     *
     * An unknown format is not quietly served as CSV the way the CP's export is: the CP's caller
     * is a button Legs wrote, and this one is whatever a visitor typed.
     */
    private function allowsFormat(string $format): bool
    {
        return match ($format) {
            'csv', 'json', 'html' => true,
            'xlsx' => Edition::allowsXlsx(Plugin::getInstance()->isPro()),
            default => false,
        };
    }

    private function send(string $contents, string $filename, string $mimeType): Response
    {
        return Craft::$app->getResponse()->sendContentAsFile($contents, $filename, ['mimeType' => $mimeType]);
    }
}
