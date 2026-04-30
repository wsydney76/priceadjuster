<?php
namespace wsydney76\priceadjuster\controllers;
use Craft;
use craft\web\Controller;
use wsydney76\priceadjuster\records\PriceSchedule;
use yii\web\Response;
/**
 * Handles price-schedule update requests from the CP utility.
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
        $saved = 0;
        $errors = [];
        foreach ($updates as $update) {
            $id = (int)($update['id'] ?? 0);
            $newPrice = isset($update['newPrice']) ? round((float)$update['newPrice'], 2) : null;
            if (!$id || $newPrice === null || $newPrice <= 0) {
                continue;
            }
            $newPromoRaw = $update['newPromotionalPrice'] ?? null;
            $newPromotionalPrice = ($newPromoRaw !== null && $newPromoRaw !== '')
                ? round((float)$newPromoRaw, 2)
                : null;
            /** @var PriceSchedule|null $record */
            $record = PriceSchedule::findOne($id);
            if (!$record) {
                $errors[] = "Record #{$id} not found.";
                continue;
            }
            $priceChanged = round((float)$record->newPrice, 2) !== $newPrice;
            $storedPromo = ($record->newPromotionalPrice !== null && $record->newPromotionalPrice !== '')
                ? round((float)$record->newPromotionalPrice, 2)
                : null;
            $promoChanged = $storedPromo !== $newPromotionalPrice;
            if (!$priceChanged && !$promoChanged) {
                continue;
            }
            $record->newPrice = $newPrice;
            $record->newPromotionalPrice = $newPromotionalPrice;
            if ($record->save()) {
                $saved++;
            } else {
                $errors[] = "Failed saving record #{$id}: " . implode(', ', $record->getFirstErrors());
            }
        }
        if (!empty($errors) && $saved === 0) {
            return $this->asFailure(implode(' ', $errors));
        }
        $message = $saved === 0
            ? 'No prices changed.'
            : Craft::t('app', '{n,plural,=1{1 price changed}other{# prices changed}}.', ['n' => $saved]);
        if (!empty($errors)) {
            $message .= ' Errors: ' . implode(' ', $errors);
        }
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
        $this->requirePostRequest();
        $ids = (array)(Craft::$app->getRequest()->getRequiredBodyParam('ids'));
        $deleted = 0;
        $errors = [];
        foreach ($ids as $id) {
            $record = PriceSchedule::findOne((int)$id);
            if (!$record) {
                $errors[] = "Record #{$id} not found.";
                continue;
            }
            if ($record->delete()) {
                $deleted++;
            } else {
                $errors[] = "Failed deleting record #{$id}.";
            }
        }
        if (!empty($errors) && $deleted === 0) {
            return $this->asFailure(implode(' ', $errors));
        }
        $message = $deleted === 0
            ? 'No records deleted.'
            : Craft::t('site', '{n,plural,=1{1 record deleted}other{# records deleted}}.', ['n' => $deleted]);
        if (!empty($errors)) {
            $message .= ' Errors: ' . implode(' ', $errors);
        }
        return $this->asSuccess($message);
    }
}
