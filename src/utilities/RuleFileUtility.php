<?php

namespace wsydney76\priceadjuster\utilities;

use Craft;
use craft\base\Utility;
use wsydney76\priceadjuster\assetbundles\PriceScheduleAsset;
use wsydney76\priceadjuster\PriceadjusterPlugin;
use wsydney76\priceadjuster\records\PriceSchedule;
use yii\db\Expression;

/**
 * Rule File utility — lists, creates, edits, and deletes JSON rule files.
 */
class RuleFileUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Price Rule Files';
    }

    public static function id(): string
    {
        return 'price-rule-files';
    }

    public static function icon(): ?string
    {
        return 'file';
    }

    public static function contentHtml(): string
    {
        $request  = Craft::$app->getRequest();
        $editFile = $request->getParam('file');

        Craft::$app->getView()->registerAssetBundle(PriceScheduleAsset::class);

        $plugin   = PriceadjusterPlugin::getInstance();
        $rulesDir = $plugin->getRulesDirectory();

        // ── List view ──────────────────────────────────────────────────────────
        if ($editFile === null) {
            $files = [];
            if (is_dir($rulesDir)) {
                foreach (glob($rulesDir . '/*.json') ?: [] as $path) {
                    $name    = basename($path, '.json');
                    $decoded = null;
                    try {
                        $decoded = json_decode(file_get_contents($path), true);
                    } catch (\Throwable) {
                        // ignore parse errors in list view
                    }
                    $files[] = [
                        'name'       => $name,
                        'entryCount' => is_array($decoded) ? count($decoded) : '?',
                        'modified'   => filemtime($path),
                        'parseError' => !is_array($decoded),
                    ];
                }
            }
            usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

            // ── DB record counts per rule ──────────────────────────────────────
            $dbRows = PriceSchedule::find()
                ->select([
                    'ruleName',
                    new Expression('COUNT(*) as total'),
                    new Expression('SUM(appliedAt IS NOT NULL) as applied'),
                ])
                ->groupBy(['ruleName'])
                ->asArray()
                ->all();

            $recordCounts = [];
            foreach ($dbRows as $row) {
                $recordCounts[$row['ruleName']] = [
                    'total'   => (int)$row['total'],
                    'applied' => (int)$row['applied'],
                ];
            }

            return Craft::$app->getView()->renderTemplate(
                '_priceadjuster/utilities/rule-file.twig',
                [
                    'files'        => $files,
                    'rulesDir'     => $rulesDir,
                    'editFile'     => null,
                    'fileName'     => null,
                    'isNew'        => false,
                    'tableRows'    => [],
                    'recordCounts' => $recordCounts,
                ]
            );
        }

        // ── Edit / create view ─────────────────────────────────────────────────
        $isNew    = ($editFile === 'new');
        $fileName = $isNew ? '' : $editFile;
        $rules    = [];
        $parseError = null;

        if (!$isNew && $fileName !== '') {
            $path = $rulesDir . '/' . $fileName . '.json';
            if (file_exists($path)) {
                try {
                    $decoded = json_decode(file_get_contents($path), true);
                    if (is_array($decoded)) {
                        $rules = $decoded;
                    } else {
                        $parseError = 'File contains invalid JSON.';
                    }
                } catch (\Throwable $e) {
                    $parseError = 'Error reading file: ' . $e->getMessage();
                }
            }
        }

        // Map rules to editableTableField rows
        $tableRows = array_map(
            fn($rule) => [
                'effective_date'        => $rule['effective_date'] ?? '',
                'label'                 => $rule['label'] ?? '',
                'action'                => $rule['action'] ?? '',
                'criteria'              => !empty($rule['criteria'])
                    ? json_encode($rule['criteria'], JSON_UNESCAPED_UNICODE)
                    : '',
                'variantCriteria'       => !empty($rule['variantCriteria'])
                    ? json_encode($rule['variantCriteria'], JSON_UNESCAPED_UNICODE)
                    : '',
                'priceType'             => $rule['priceAdjustment']['type'] ?? '',
                'priceValue'            => isset($rule['priceAdjustment']['value'])
                    ? (string)$rule['priceAdjustment']['value']
                    : '',
                'promoType'             => $rule['promotionalPriceAdjustment']['type'] ?? '',
                'promoValue'            => isset($rule['promotionalPriceAdjustment']['value'])
                    ? (string)$rule['promotionalPriceAdjustment']['value']
                    : '',
                'friendlyPriceStrategy' => $rule['friendlyPriceStrategy'] ?? '',
            ],
            $rules
        );

        return Craft::$app->getView()->renderTemplate(
            '_priceadjuster/utilities/rule-file.twig',
            [
                'files'      => null,
                'rulesDir'   => $rulesDir,
                'editFile'   => $editFile,
                'fileName'   => $fileName,
                'isNew'      => $isNew,
                'tableRows'  => $tableRows,
                'parseError' => $parseError,
            ]
        );
    }
}

