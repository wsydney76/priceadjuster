<?php
namespace wsydney76\priceadjuster\controllers;
use Craft;
use craft\web\Controller;
use wsydney76\priceadjuster\PriceadjusterPlugin;
use yii\web\Response;
/**
 * Handles price-schedule update requests from the CP utility.
 * Business logic lives in PriceadjusterPlugin::getInstance()->scheduler.
 */
class PriceScheduleController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * Batch-update newPrice (and optionally newPromotionalPrice) for staged records.
     *
     * Expects POST body: updates[] = [{id, newPrice, newPromotionalPrice?}, ...]
     */
    public function actionBatchUpdate(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:price-schedule');
        $this->requirePostRequest();

        $updates = Craft::$app->getRequest()->getRequiredBodyParam('updates');
        $result  = PriceadjusterPlugin::getInstance()->scheduler->batchUpdateRecords($updates);

        $saved      = $result['saved'];
        $errors     = $result['errors']; // keyed by record ID

        if (!empty($errors)) {
            $errorCount = count($errors);
            $message = $saved > 0
                ? Craft::t('site', '{n,plural,=1{1 price}other{# prices}} changed, {e,plural,=1{1 error}other{# errors}}.', ['n' => $saved, 'e' => $errorCount])
                : Craft::t('site', '{e,plural,=1{1 error}other{# errors}}.', ['e' => $errorCount]);
            return $this->asFailure($message, ['errors' => $errors]);
        }

        $message = $saved === 0
            ? 'No prices changed.'
            : Craft::t('app', '{n,plural,=1{1 price changed}other{# prices changed}}.', ['n' => $saved]);
        return $this->asSuccess($message);
    }

    /**
     * Delete staged records by ID.
     *
     * Expects POST body: ids[] = [1, 2, ...]
     */
    public function actionDeleteSelected(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:price-schedule');
        $this->requirePostRequest();

        $ids    = (array)Craft::$app->getRequest()->getRequiredBodyParam('ids');
        $result = PriceadjusterPlugin::getInstance()->scheduler->deleteRecordsById($ids);

        return $this->buildResponse(
            saved: $result['deleted'],
            errors: $result['errors'],
            zeroMessage: 'No records deleted.',
            successMessage: Craft::t('site', '{n,plural,=1{1 record deleted}other{# records deleted}}.', ['n' => $result['deleted']]),
        );
    }

    /**
     * Delete all records for a given rule name.
     *
     * Expects POST body: rule = <ruleName>
     */
    public function actionDeleteByRule(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:price-schedule');
        $this->requirePostRequest();

        $rule   = Craft::$app->getRequest()->getRequiredBodyParam('rule');
        $result = PriceadjusterPlugin::getInstance()->scheduler->deleteRecordsByRule($rule);

        return $this->buildResponse(
            saved: $result['deleted'],
            errors: $result['errors'],
            zeroMessage: 'No records found for this rule.',
            successMessage: Craft::t('site', '{n,plural,=1{1 record deleted}other{# records deleted}}.', ['n' => $result['deleted']]),
        );
    }

    /**
     * Update effectiveDate for all pending records matching ruleName + old effective date.
     *
     * Expects POST body: rule, oldDate, newDate (all yyyy-mm-dd)
     */
    public function actionUpdateEffectiveDate(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:price-schedule');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $rule    = $request->getRequiredBodyParam('rule');
        $oldDate = $request->getRequiredBodyParam('oldDate');
        $newDate = $request->getRequiredBodyParam('newDate');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
            return $this->asFailure('Invalid date format. Expected yyyy-mm-dd.');
        }

        $result = PriceadjusterPlugin::getInstance()->scheduler->updateEffectiveDate($rule, $oldDate, $newDate);

        return $this->buildResponse(
            saved: $result['updated'],
            errors: $result['errors'],
            zeroMessage: 'No pending records found for this rule/date.',
            successMessage: Craft::t('site', '{n,plural,=1{1 record updated}other{# records updated}}.', ['n' => $result['updated']]),
        );
    }

    /**
     * Dry-run apply: simulate applyRecords() without writing to the DB.
     *
     * Expects POST body: rule, date
     */
    public function actionDryRunApply(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:price-schedule');
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $rule    = $request->getRequiredBodyParam('rule');
        $date    = $request->getRequiredBodyParam('date');

        $records = PriceadjusterPlugin::getInstance()->scheduler->getScheduleRecords(
            applied: false,
            date: $date,
            rule: $rule,
            requireFilter: true,
        );

        if (empty($records)) {
            return $this->asSuccess('No pending records found for this rule/date.', [
                'results' => [],
                'summary' => 'No pending records.',
            ]);
        }

        $results = PriceadjusterPlugin::getInstance()->scheduler->applyRecords($records, false, $rule, true);

        $output = [];
        foreach ($results as $item) {
            $record   = $item['record'] ?? null;
            $output[] = [
                'status'              => $item['status'],
                'message'             => $item['message'] ?? '',
                'title'               => $record ? $record->title : '',
                'sku'                 => $record ? $record->sku : '',
                'oldPrice'            => $record ? (float)$record->oldPrice : null,
                'newPrice'            => $record ? (float)$record->newPrice : null,
                'oldPromotionalPrice' => ($record && $record->oldPromotionalPrice !== null) ? (float)$record->oldPromotionalPrice : null,
                'newPromotionalPrice' => ($record && $record->newPromotionalPrice !== null) ? (float)$record->newPromotionalPrice : null,
            ];
        }

        $appliedCount = count(array_filter($results, fn($r) => $r['status'] === 'applied'));
        $skippedCount = count(array_filter($results, fn($r) => $r['status'] === 'skipped'));
        $errorCount   = count(array_filter($results, fn($r) => $r['status'] === 'error'));
        $summary      = "[DRY RUN] {$appliedCount} would be applied, {$skippedCount} skipped, {$errorCount} errors.";

        return $this->asSuccess($summary, ['results' => $output, 'summary' => $summary]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildResponse(int $saved, array $errors, string $zeroMessage, string $successMessage): Response
    {
        if (!empty($errors) && $saved === 0) {
            return $this->asFailure(implode(' ', $errors));
        }

        $message = $saved === 0 ? $zeroMessage : $successMessage;
        if (!empty($errors)) {
            $message .= ' Errors: ' . implode(' ', $errors);
        }

        return $this->asSuccess($message);
    }
}
