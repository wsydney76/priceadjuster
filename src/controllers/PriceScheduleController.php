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
        $this->requirePostRequest();

        $updates = Craft::$app->getRequest()->getRequiredBodyParam('updates');
        $result  = PriceadjusterPlugin::getInstance()->scheduler->batchUpdateRecords($updates);

        return $this->buildResponse(
            saved: $result['saved'],
            errors: $result['errors'],
            zeroMessage: 'No prices changed.',
            successMessage: Craft::t('app', '{n,plural,=1{1 price changed}other{# prices changed}}.', ['n' => $result['saved']]),
        );
    }

    /**
     * Delete staged records by ID.
     *
     * Expects POST body: ids[] = [1, 2, ...]
     */
    public function actionDeleteSelected(): Response
    {
        $this->requireCpRequest();
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
