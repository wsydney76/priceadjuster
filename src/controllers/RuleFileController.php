<?php

namespace wsydney76\priceadjuster\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use wsydney76\priceadjuster\PriceadjusterPlugin;
use yii\web\Response;

/**
 * Handles create / update / delete of JSON rule files on disk.
 * Action routes: _priceadjuster/rule-file/save, _priceadjuster/rule-file/delete
 */
class RuleFileController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * Save (create or overwrite) a rule JSON file.
     *
     * POST body:
     *   fileName        — bare name without .json extension (required)
     *   rules[][]       — rows from editableTableField
     */
    public function actionSave(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:price-rule-files');
        $this->requirePostRequest();

        $request  = Craft::$app->getRequest();
        $fileName = trim((string)$request->getRequiredBodyParam('fileName'));
        $rulesRaw = $request->getBodyParam('rules', []);

        // ── Validate file name ─────────────────────────────────────────────────
        if ($fileName === '' || !preg_match('/^[a-zA-Z0-9_\-.]+$/', $fileName)) {
            Craft::$app->getSession()->setError('Invalid file name. Use only letters, digits, hyphens, underscores, and dots.');
            return $this->redirect(Craft::$app->getRequest()->getReferrer()
                ?? UrlHelper::cpUrl('utilities/price-rule-files'));
        }

        $plugin   = PriceadjusterPlugin::getInstance();
        $rulesDir = $plugin->getRulesDirectory();

        if (!is_dir($rulesDir) && !mkdir($rulesDir, 0775, true)) {
            Craft::$app->getSession()->setError("Could not create rules directory: {$rulesDir}");
            return $this->redirect(UrlHelper::cpUrl('utilities/price-rule-files'));
        }

        // ── Build JSON structure from editable-table rows ──────────────────────
        $rules = [];
        foreach ((array)$rulesRaw as $row) {
            $effectiveDate = trim($row['effective_date'] ?? '');
            if ($effectiveDate === '') {
                continue; // skip blank rows
            }

            $entry = ['effective_date' => $effectiveDate];

            if (!empty($row['label'])) {
                $entry['label'] = $row['label'];
            }
            if (!empty($row['action'])) {
                $entry['action'] = $row['action'];
            }

            // criteria — JSON string → array
            $criteriaRaw = trim($row['criteria'] ?? '');
            if ($criteriaRaw !== '') {
                $decoded = json_decode($criteriaRaw, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $entry['criteria'] = $decoded;
                }
            }

            // variantCriteria — JSON string → array
            $vcRaw = trim($row['variantCriteria'] ?? '');
            if ($vcRaw !== '') {
                $decoded = json_decode($vcRaw, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $entry['variantCriteria'] = $decoded;
                }
            }

            // priceAdjustment
            $priceType = $row['priceType'] ?? '';
            if ($priceType !== '') {
                $adj = ['type' => $priceType];
                if ($priceType !== 'reset') {
                    $val = $row['priceValue'] ?? '';
                    if ($val !== '') {
                        $adj['value'] = (float)$val;
                    }
                }
                $entry['priceAdjustment'] = $adj;
            }

            // promotionalPriceAdjustment
            $promoType = $row['promoType'] ?? '';
            if ($promoType !== '') {
                $adj = ['type' => $promoType];
                if ($promoType !== 'reset') {
                    $val = $row['promoValue'] ?? '';
                    if ($val !== '') {
                        $adj['value'] = (float)$val;
                    }
                }
                $entry['promotionalPriceAdjustment'] = $adj;
            }

            // friendlyPriceStrategy
            $fps = trim($row['friendlyPriceStrategy'] ?? '');
            if ($fps !== '') {
                $entry['friendlyPriceStrategy'] = $fps;
            }

            $rules[] = $entry;
        }

        // ── Write file ─────────────────────────────────────────────────────────
        $filePath = $rulesDir . '/' . $fileName . '.json';
        $tmp      = $filePath . '.tmp';
        $json     = json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($tmp, $json) === false || !rename($tmp, $filePath)) {
            Craft::$app->getSession()->setError("Failed to write rule file: {$fileName}.json");
            return $this->redirect(UrlHelper::cpUrl('utilities/price-rule-files', ['file' => $fileName]));
        }

        Craft::$app->getSession()->setNotice("Rule file '{$fileName}.json' saved.");
        return $this->redirect(UrlHelper::cpUrl('utilities/price-rule-files', ['file' => $fileName]));
    }

    /**
     * Delete a rule JSON file from disk.
     *
     * POST body: fileName — bare name without .json
     */
    public function actionDelete(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:price-rule-files');
        $this->requirePostRequest();

        $fileName = trim((string)Craft::$app->getRequest()->getRequiredBodyParam('fileName'));

        if ($fileName === '' || !preg_match('/^[a-zA-Z0-9_\-.]+$/', $fileName)) {
            Craft::$app->getSession()->setError('Invalid file name.');
            return $this->redirect(UrlHelper::cpUrl('utilities/price-rule-files'));
        }

        $rulesDir = PriceadjusterPlugin::getInstance()->getRulesDirectory();
        $filePath = $rulesDir . '/' . $fileName . '.json';

        if (!file_exists($filePath)) {
            Craft::$app->getSession()->setError("File not found: {$fileName}.json");
            return $this->redirect(UrlHelper::cpUrl('utilities/price-rule-files'));
        }

        if (!unlink($filePath)) {
            Craft::$app->getSession()->setError("Failed to delete file: {$fileName}.json");
            return $this->redirect(UrlHelper::cpUrl('utilities/price-rule-files'));
        }

        Craft::$app->getSession()->setNotice("Rule file '{$fileName}.json' deleted.");
        return $this->redirect(UrlHelper::cpUrl('utilities/price-rule-files'));
    }
}



