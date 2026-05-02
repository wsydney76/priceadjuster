<?php

namespace wsydney76\priceadjuster\services;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\Entry;
use wsydney76\priceadjuster\events\BuildRowsEvent;
use wsydney76\priceadjuster\events\SchedulerResultEvent;
use wsydney76\priceadjuster\PriceadjusterPlugin;
use wsydney76\priceadjuster\records\PriceSchedule;
use yii\base\Component;

/**
 * Scheduler Service — core price-schedule logic usable from console or web controllers.
 *
 * All methods return structured result arrays instead of doing I/O directly.
 * Each result item always contains at least a 'status' key.
 *
 * Listeners may subscribe to EVENT_RESULT to be notified in real time as each
 * individual result is recorded (useful for streaming console output).
 *
 * @method static SchedulerService getInstance()
 */
class SchedulerService extends Component
{
    public const EVENT_BUILD_ROWS = 'buildRows';

    /**
     * Fired once per result item inside stageRows(), applyRecords(), rollbackRecords()
     * and deleteRecords() — before those methods return the full result set.
     */
    public const EVENT_RESULT = 'result';

    /** Accumulator reset and populated by every operational method. */
    private array $currentResults = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Build price-change rows from a rule JSON file.
     *
     * @return array[] Each item: variantId, title, sku, oldPrice, newPrice,
     *                 oldPromotionalPrice, newPromotionalPrice, effectiveDate, ruleLabel
     */
    public function buildRows(string $rule): array
    {
        $rules = $this->loadRules($rule);
        $rowMap = [];

        foreach ($rules as $index => $ruleData) {
            $effectiveDate = $ruleData['effective_date'] ?? null;
            if (!$effectiveDate) {
                continue; // caller handles warnings
            }

            $criteria          = $ruleData['criteria'] ?? [];
            $variantCriteria   = $ruleData['variantCriteria'] ?? [];
            $adjustment        = $ruleData['priceAdjustment'] ?? [];
            $promotionalAdj    = $ruleData['promotionalPriceAdjustment'] ?? null;
            $ruleLabel         = $this->buildRuleLabel($ruleData, $index, $rule);

            $variants = $this->getVariantsForCriteria($criteria, $variantCriteria);

            foreach ($variants as $variant) {
                $oldPrice = (float)$variant->basePrice;
                $newPrice = !empty($adjustment)
                    ? $this->applyAdjustment($oldPrice, $adjustment)
                    : $oldPrice;

                if ($oldPrice === 0.0 || $newPrice === 0.0) {
                    continue;
                }

                $currentPromoPrice   = $variant->basePromotionalPrice !== null ? (float)$variant->basePromotionalPrice : null;
                $oldPromotionalPrice = $this->zeroAsNull($currentPromoPrice);

                $newPromotionalPrice = $promotionalAdj !== null
                    ? $this->zeroAsNull($this->applyAdjustment($newPrice, $promotionalAdj))
                    : null;

                $key = $variant->id . ':' . $effectiveDate;
                $rowMap[$key] = [
                    'variantId'           => $variant->id,
                    'title'               => $variant->owner->title . ' - ' . $variant->title,
                    'sku'                 => $variant->sku,
                    'oldPrice'            => $oldPrice,
                    'newPrice'            => $newPrice,
                    'oldPromotionalPrice' => $oldPromotionalPrice,
                    'newPromotionalPrice' => $newPromotionalPrice,
                    'effectiveDate'       => $effectiveDate,
                    'ruleLabel'           => $ruleLabel,
                ];
            }
        }

        $rows = array_values($rowMap);
        usort($rows, fn($a, $b) => strcmp($a['effectiveDate'] . $a['title'], $b['effectiveDate'] . $b['title']));

        if (!$this->hasEventHandlers(self::EVENT_BUILD_ROWS)) {
            return $rows;
        }

        $event = new BuildRowsEvent([
            'ruleName' => $rule,
            'rules'    => $rules,
            'rows'     => $rows,
        ]);
        $this->trigger(self::EVENT_BUILD_ROWS, $event);

        return $event->rows;
    }

    /**
     * Stage rows into the price-schedule table.
     *
     * @param  array[]  $rows    Output of buildRows()
     * @param  string   $rule    Rule name (used for ruleName column)
     * @param  bool     $replace Delete all matching un-applied records first
     * @param  string|null $date  Limit --replace scope to this effective date
     * @return array[]  Each item: status (staged|updated|skipped|error), row, message
     */
    public function stageRows(array $rows, string $rule, bool $replace = false, ?string $date = null): array
    {
        $this->currentResults = [];

        if ($replace) {
            $existing = $this->getScheduleRecords(applied: null, date: $date, rule: $rule, requireFilter: false);
            foreach ($existing as $record) {
                $record->delete();
            }
        }

        foreach ($rows as $row) {
            $effectiveDate = $row['effectiveDate'];
            $whereClause   = [
                'variantId'     => $row['variantId'],
                'effectiveDate' => $effectiveDate,
                'appliedAt'     => null,
            ];
            $exists = PriceSchedule::find()->where($whereClause)->exists();

            if ($exists) {
                $record = PriceSchedule::find()->where($whereClause)->one();
                if (
                    (float)$record->newPrice === $row['newPrice'] &&
                    $record->newPromotionalPrice == $row['newPromotionalPrice']
                ) {
                    $this->addResult(['status' => 'skipped', 'row' => $row, 'message' => "Unchanged: {$row['sku']}"]);
                    continue;
                }
                $record->title               = $row['title'];
                $record->oldPrice            = $row['oldPrice'];
                $record->newPrice            = $row['newPrice'];
                $record->oldPromotionalPrice = $row['oldPromotionalPrice'];
                $record->newPromotionalPrice = $row['newPromotionalPrice'];
                $record->ruleLabel           = $row['ruleLabel'];
                $record->ruleName            = $rule;
                $savedStatus = 'updated';
            } else {
                $record = new PriceSchedule();
                $record->variantId           = $row['variantId'];
                $record->title               = $row['title'];
                $record->sku                 = $row['sku'];
                $record->oldPrice            = $row['oldPrice'];
                $record->newPrice            = $row['newPrice'];
                $record->oldPromotionalPrice = $row['oldPromotionalPrice'];
                $record->newPromotionalPrice = $row['newPromotionalPrice'];
                $record->ruleLabel           = $row['ruleLabel'];
                $record->ruleName            = $rule;
                $record->effectiveDate       = $effectiveDate;
                $savedStatus = 'staged';
            }

            if (!$record->save()) {
                $this->addResult([
                    'status'  => 'error',
                    'row'     => $row,
                    'message' => "Failed staging variant {$row['variantId']}",
                    'errors'  => $record->getErrors(),
                ]);
                continue;
            }

            $this->addResult(['status' => $savedStatus, 'row' => $row, 'message' => "{$row['sku']} | {$row['title']}: {$row['oldPrice']} -> {$row['newPrice']}"]);
        }

        return $this->currentResults;
    }

    /**
     * Apply staged price-schedule records to Commerce variants.
     *
     * @param  PriceSchedule[] $records
     * @param  bool            $resetPromotion  Force promotional price to null on apply
     * @param  string|null     $ruleName        Used for the log file name
     * @return array[]  Each item: status (applied|skipped|error), record, message
     */
    public function applyRecords(array $records, bool $resetPromotion = false, ?string $ruleName = null): array
    {
        $this->currentResults = [];
        $productIds = [];

        foreach ($records as $record) {
            $variant = Variant::find()->id((int)$record->variantId)->status(null)->one();
            if (!$variant) {
                $this->addResult(['status' => 'error', 'record' => $record, 'message' => "Variant not found: {$record->variantId}"]);
                continue;
            }

            $newPrice = (float)$record->newPrice;
            if ($newPrice === 0.0) {
                $this->addResult(['status' => 'skipped', 'record' => $record, 'message' => "newPrice is 0: {$record->sku}"]);
                continue;
            }

            $variant->basePrice = $newPrice;
            $newPromoPrice      = ($resetPromotion || $record->newPromotionalPrice === null)
                ? null
                : $this->zeroAsNull((float)$record->newPromotionalPrice);
            $variant->basePromotionalPrice = $newPromoPrice;

            if (!Craft::$app->elements->saveElement($variant)) {
                $this->addResult(['status' => 'error', 'record' => $record, 'message' => "Failed saving variant {$variant->id}"]);
                continue;
            }

            $record->appliedAt = Craft::$app->formatter->asDatetime('now', 'php:Y-m-d H:i:s');
            if (!$record->save()) {
                $this->addResult([
                    'status'  => 'error',
                    'record'  => $record,
                    'message' => "Price applied but failed marking schedule row: {$record->id}",
                    'errors'  => $record->getErrors(),
                ]);
                continue;
            }

            $this->addResult(['status' => 'applied', 'record' => $record, 'message' => sprintf("Applied %s: %.2f -> %.2f", $record->sku, (float)$record->oldPrice, $newPrice) . $this->promoSuffixFromRecord($record)]);
            $productIds[$variant->ownerId] = true;
        }

        if (PriceadjusterPlugin::getInstance()->getSettings()->resaveProducts) {
            $this->resaveProducts(array_keys($productIds));
        }

        $this->writeLog($ruleName ?? 'unknown', 'apply', $this->currentResults);

        return $this->currentResults;
    }

    /**
     * Roll back applied price-schedule records.
     *
     * @param  PriceSchedule[] $records
     * @param  string|null     $ruleName  Used for the log file name
     * @return array[]  Each item: status (rolledBack|skipped|error), record, message
     */
    public function rollbackRecords(array $records, ?string $ruleName = null): array
    {
        $this->currentResults = [];
        $productIds = [];

        foreach ($records as $record) {
            $variant = Variant::find()->id((int)$record->variantId)->status(null)->one();
            if (!$variant) {
                $this->addResult(['status' => 'error', 'record' => $record, 'message' => "Variant not found: {$record->variantId}"]);
                continue;
            }

            $oldPrice = (float)$record->oldPrice;
            if ($oldPrice === 0.0) {
                $this->addResult(['status' => 'skipped', 'record' => $record, 'message' => "oldPrice is 0: {$record->sku}"]);
                continue;
            }

            $variant->basePrice            = $oldPrice;
            $variant->basePromotionalPrice = $record->oldPromotionalPrice !== null
                ? ((float)$record->oldPromotionalPrice ?: null)
                : null;

            if (!Craft::$app->elements->saveElement($variant)) {
                $this->addResult(['status' => 'error', 'record' => $record, 'message' => "Failed rolling back variant {$variant->id}"]);
                continue;
            }

            $record->appliedAt = null;
            if (!$record->save()) {
                $this->addResult([
                    'status'  => 'error',
                    'record'  => $record,
                    'message' => "Rolled back price but failed updating schedule row: {$record->id}",
                    'errors'  => $record->getErrors(),
                ]);
                continue;
            }

            $this->addResult(['status' => 'rolledBack', 'record' => $record, 'message' => sprintf("Rolled back %s: %.2f -> %.2f", $record->sku, (float)$record->newPrice, $oldPrice) . $this->promoSuffixFromRecord($record)]);
            $productIds[$variant->ownerId] = true;
        }

        if (PriceadjusterPlugin::getInstance()->getSettings()->resaveProducts) {
            $this->resaveProducts(array_keys($productIds));
        }

        $this->writeLog($ruleName ?? 'unknown', 'rollback', $this->currentResults);

        return $this->currentResults;
    }

    /**
     * Batch-update newPrice/newPromotionalPrice on staged records from raw input (e.g. POST body).
     *
     * @param  array[] $updates  Each item: id, newPrice, newPromotionalPrice (optional)
     * @return array{saved: int, errors: string[]}
     */
    public function batchUpdateRecords(array $updates): array
    {
        $saved  = 0;
        $errors = [];

        foreach ($updates as $update) {
            $id       = (int)($update['id'] ?? 0);
            $newPrice = isset($update['newPrice']) ? round((float)$update['newPrice'], 2) : null;

            if (!$id || $newPrice === null || $newPrice <= 0) {
                continue;
            }

            $newPromoRaw         = $update['newPromotionalPrice'] ?? null;
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
            $storedPromo  = ($record->newPromotionalPrice !== null && $record->newPromotionalPrice !== '')
                ? round((float)$record->newPromotionalPrice, 2)
                : null;
            $promoChanged = $storedPromo !== $newPromotionalPrice;

            if (!$priceChanged && !$promoChanged) {
                continue;
            }

            $record->newPrice            = $newPrice;
            $record->newPromotionalPrice = $newPromotionalPrice;

            if ($record->save()) {
                $saved++;
            } else {
                $errors[] = "Failed saving record #{$id}: " . implode(', ', $record->getFirstErrors());
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * Delete staged records by primary key.
     *
     * @param  int[] $ids
     * @return array{deleted: int, errors: string[]}
     */
    public function deleteRecordsById(array $ids): array
    {
        $deleted = 0;
        $errors  = [];

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

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Update effectiveDate for all pending records matching ruleName + old effectiveDate.
     *
     * @return array{updated: int, errors: string[]}
     */
    public function updateEffectiveDate(string $rule, string $oldDate, string $newDate): array
    {
        $updated = 0;
        $errors  = [];

        $records = PriceSchedule::find()
            ->where(['ruleName' => $rule, 'effectiveDate' => $oldDate, 'appliedAt' => null])
            ->all();

        foreach ($records as $record) {
            $record->effectiveDate = $newDate;
            if ($record->save()) {
                $updated++;
            } else {
                $errors[] = "Failed updating record #{$record->id}: " . implode(', ', $record->getFirstErrors());
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Delete all price-schedule records for a given rule name.
     *
     * @return array{deleted: int, errors: string[]}
     */
    public function deleteRecordsByRule(string $rule): array
    {
        $deleted = 0;
        $errors  = [];

        $records = PriceSchedule::find()->where(['ruleName' => $rule])->all();
        foreach ($records as $record) {
            if ($record->delete()) {
                $deleted++;
            } else {
                $errors[] = "Failed deleting record #{$record->id}.";
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Delete price-schedule records.
     *
     * @param  PriceSchedule[] $records
     * @return array[]  Each item: status (deleted|error), record, message
     */
    public function deleteRecords(array $records): array
    {
        $this->currentResults = [];
        foreach ($records as $record) {
            if (!$record->delete()) {
                $this->addResult(['status' => 'error', 'record' => $record, 'message' => "Failed deleting record {$record->id} ({$record->sku})"]);
            } else {
                $this->addResult(['status' => 'deleted', 'record' => $record, 'message' => "Deleted {$record->sku}"]);
            }
        }
        return $this->currentResults;
    }

    /**
     * Retrieve schedule records filtered by applied status and optional date/rule.
     *
     * @param  bool|null  $applied        true = only applied; false = only staged; null = both
     * @param  string|null $date
     * @param  string|null $rule
     * @param  bool       $requireFilter  when true, returns null if neither date nor rule is set
     * @return PriceSchedule[]|null  null only when $requireFilter is true and no filter was provided
     */
    public function getScheduleRecords(?bool $applied, ?string $date, ?string $rule, bool $requireFilter = true): ?array
    {
        if ($requireFilter && !$date && !$rule) {
            return null;
        }

        $query = PriceSchedule::find();

        if ($applied === true) {
            $query->andWhere(['not', ['appliedAt' => null]]);
        } elseif ($applied === false) {
            $query->andWhere(['appliedAt' => null]);
        }

        if ($date) {
            $query->andWhere(['effectiveDate' => $date]);
        }

        if ($rule) {
            $query->andWhere(['ruleName' => $rule]);
        }

        return $query->all();
    }

    /**
     * Load and validate a rule JSON file.
     *
     * @throws \RuntimeException if file is missing or invalid
     */
    public function loadRules(string $rule): array
    {
        $path = PriceadjusterPlugin::getInstance()->getRulesDirectory() . '/' . $rule . '.json';
        if (!file_exists($path)) {
            throw new \RuntimeException("Rule file not found: $path");
        }
        $rules = json_decode(file_get_contents($path), true);
        if (!is_array($rules) || empty($rules)) {
            throw new \RuntimeException("Rule file is empty or invalid JSON: $path");
        }
        $today = new \DateTimeImmutable('today');
        foreach ($rules as $index => $ruleEntry) {
            $effectiveDate = $ruleEntry['effective_date'] ?? null;
            $ruleNum = $index + 1;

            if (!$effectiveDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
                throw new \RuntimeException(sprintf(
                    "Rule #%d in '%s' has an invalid or missing effective_date '%s'. Expected format: yyyy-mm-dd.",
                    $ruleNum, $rule, $effectiveDate ?? ''
                ));
            }

            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveDate);
            if (!$date || $date->format('Y-m-d') !== $effectiveDate) {
                throw new \RuntimeException(sprintf(
                    "Rule #%d in '%s' has an invalid calendar date: '%s'.",
                    $ruleNum, $rule, $effectiveDate
                ));
            }

            if ($date < $today) {
                throw new \RuntimeException(sprintf(
                    "Rule #%d in '%s' has an effective_date in the past: '%s'.",
                    $ruleNum, $rule, $effectiveDate
                ));
            }
        }

        $this->resolveSlugReferences($rules);
        return $rules;
    }

    // -------------------------------------------------------------------------
    // Price helpers (public so web controllers / tests can reuse them)
    // -------------------------------------------------------------------------

    public function applyAdjustment(float $price, array $adjustment): ?float
    {
        $type = $adjustment['type'] ?? '';
        if ($type === 'reset') {
            return null;
        }
        $value = (float)($adjustment['value'] ?? 0);
        if ($type === 'percentage') {
            return $this->friendlyPrice($price * (1 + $value / 100));
        }
        if ($type === 'amount') {
            return round($price + $value, 2);
        }
        return $price;
    }

    public function friendlyPrice(float $price): float
    {
        return round(floor($price) + 0.95, 2);
    }

    public function zeroAsNull(?float $value): ?float
    {
        return ($value === null || $value === 0.0) ? null : $value;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve criteria values in the format "<section>:<slug1>,<slug2>" to arrays of element IDs.
     */
    private function resolveSlugReferences(array &$rules): void
    {
        foreach ($rules as &$rule) {
            foreach (['criteria', 'variantCriteria'] as $key) {
                if (empty($rule[$key]) || !is_array($rule[$key])) {
                    continue;
                }
                foreach ($rule[$key] as $field => &$value) {
                    if (!is_string($value) || !str_contains($value, ':')) {
                        continue;
                    }
                    [$section, $slugList] = explode(':', $value, 2);
                    $slugs = array_filter(array_map('trim', explode(',', $slugList)));
                    if (empty($slugs)) {
                        continue;
                    }
                    $ids = Entry::find()->section($section)->slug($slugs)->ids();
                    if (!empty($ids)) {
                        $value = $ids;
                    }
                }
            }
        }
    }

    private function getVariantsForCriteria(array $criteria, array $variantCriteria = []): array
    {
        $query = Product::find()->status(null);
        foreach ($criteria as $key => $value) {
            $query->$key($value);
        }

        $productIds = $query->ids();
        if (empty($productIds)) {
            return [];
        }
        $variantQuery = Variant::find()->ownerId($productIds)->status(null);
        foreach ($variantCriteria as $key => $value) {
            $variantQuery->$key($value);
        }
        return $variantQuery->all();
    }

    private function resaveProducts(array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }
        $products = Product::find()->id($productIds)->status(null)->all();
        foreach ($products as $product) {
            if (!Craft::$app->elements->saveElement($product)) {
                $this->addResult(['status' => 'error', 'message' => "Failed resaving product {$product->id} ({$product->title})"]);
            } else {
                $this->addResult(['status' => 'resaved', 'message' => "Resaved product: {$product->title}"]);
            }
        }
    }

    private function buildRuleLabel(array $rule, int $index, string $ruleName): string
    {
        $effectiveDate = $rule['effective_date'] ?? '?';
        $adjustment    = $rule['priceAdjustment'] ?? [];
        $type          = $adjustment['type'] ?? '';
        $value         = $adjustment['value'] ?? 0;
        $criteria      = $rule['criteria'] ?? [];
        $criteriaStr   = implode(', ', array_map(
            fn($k, $v) => "$k:" . (is_array($v) ? implode('+', $v) : $v),
            array_keys($criteria),
            $criteria
        ));
        $adjustStr = match ($type) {
            'percentage' => "+{$value}%",
            'amount'     => "+{$value}",
            default      => $type,
        };
        return sprintf('%s #%d [%s] %s eff. %s', $ruleName, $index + 1, $criteriaStr, $adjustStr, $effectiveDate);
    }

    /**
     * Append one result item to the current accumulator and fire EVENT_RESULT
     * so that listeners (e.g. a console controller) can react immediately.
     */
    private function addResult(array $result): void
    {
        $this->currentResults[] = $result;

        if ($this->hasEventHandlers(self::EVENT_RESULT)) {
            $event          = new SchedulerResultEvent();
            $event->status  = $result['status'];
            $event->message = $result['message'] ?? '';
            $event->result  = $result;
            $this->trigger(self::EVENT_RESULT, $event);
        }
    }

    private function promoSuffixFromRecord(PriceSchedule $record): string
    {
        if ($record->oldPromotionalPrice === null && $record->newPromotionalPrice === null) {
            return '';
        }
        $old = $record->oldPromotionalPrice !== null ? number_format((float)$record->oldPromotionalPrice, 2) : 'null';
        $new = $record->newPromotionalPrice !== null ? number_format((float)$record->newPromotionalPrice, 2) : 'null';
        return sprintf(' | promo: %s -> %s', $old, $new);
    }

    /**
     * Write all result messages to a log file.
     *
     * File: <logDirectory>/<ruleName>-<action>-<yyyy-mm-dd hh-mm>.log
     */
    private function writeLog(string $ruleName, string $action, array $results): void
    {
        try {
            $dir = PriceadjusterPlugin::getInstance()->getLogDirectory();
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $timestamp = (new \DateTimeImmutable())->format('Y-m-d-H-i');
            $filename  = $dir . '/' . $ruleName . '-' . $action . '-' . $timestamp . '.log';
            $lines     = [];
            foreach ($results as $result) {
                $status  = $result['status'] ?? '';
                $message = $result['message'] ?? '';
                $lines[] = '[' . $status . '] ' . $message;
                if (!empty($result['errors'])) {
                    foreach ((array)$result['errors'] as $field => $fieldErrors) {
                        foreach ((array)$fieldErrors as $err) {
                            $lines[] = '  error: ' . $err;
                        }
                    }
                }
            }
            file_put_contents($filename, implode(PHP_EOL, $lines) . PHP_EOL);
        } catch (\Throwable $e) {
            Craft::error('PriceAdjuster: could not write log — ' . $e->getMessage(), __METHOD__);
        }
    }
}

