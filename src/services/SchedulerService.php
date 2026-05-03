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
use function floor;

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
            $strategy          = $ruleData['friendlyPriceStrategy'] ?? null;

            $variants = $this->getVariantsForCriteria($criteria, $variantCriteria);

            foreach ($variants as $variant) {
                $oldPrice = (float)$variant->basePrice;
                $newPrice = !empty($adjustment)
                    ? $this->applyAdjustment($oldPrice, $adjustment, $strategy, $variant)
                    : $oldPrice;

                if ($oldPrice === 0.0 || $newPrice === 0.0) {
                    continue;
                }

                $currentPromoPrice   = $variant->basePromotionalPrice !== null ? (float)$variant->basePromotionalPrice : null;
                $oldPromotionalPrice = $this->zeroAsNull($currentPromoPrice);

                $newPromotionalPrice = $promotionalAdj !== null
                    ? $this->zeroAsNull($this->applyAdjustment($newPrice, $promotionalAdj, $strategy, $variant))
                    : null;

                $record                      = new PriceSchedule();
                $record->variantId           = $variant->id;
                $record->title               = $variant->owner->title . ' - ' . $variant->title;
                $record->sku                 = $variant->sku;
                $record->oldPrice            = $oldPrice;
                $record->newPrice            = $newPrice;
                $record->oldPromotionalPrice = $oldPromotionalPrice;
                $record->newPromotionalPrice = $newPromotionalPrice;
                $record->effectiveDate       = $effectiveDate;
                $record->ruleLabel           = $ruleLabel;
                $record->ruleName            = $rule;
                $record->ruleIndex           = $index;

                $key          = $variant->id . ':' . $effectiveDate;
                $rowMap[$key] = $record;
            }
        }

        /** @var PriceSchedule[] $rows */
        $rows = array_values($rowMap);
        usort($rows, fn($a, $b) => strcmp($a->effectiveDate . $a->title, $b->effectiveDate . $b->title));

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
     * @param  PriceSchedule[] $rows    Output of buildRows() — unsaved PriceSchedule records
     * @param  string          $rule    Rule name (used for --replace scope filter)
     * @param  bool            $replace Delete all matching un-applied records first
     * @param  string|null     $date    Limit --replace scope to this effective date
     * @return array[]  Each item: status (staged|updated|skipped|error), record, message
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

        foreach ($rows as $incoming) {
            $whereClause = [
                'variantId'     => $incoming->variantId,
                'effectiveDate' => $incoming->effectiveDate,
                'appliedAt'     => null,
            ];
            $exists = PriceSchedule::find()->where($whereClause)->exists();

            if ($exists) {
                $record = PriceSchedule::find()->where($whereClause)->one();
                if (
                    (float)$record->newPrice === (float)$incoming->newPrice &&
                    $record->newPromotionalPrice == $incoming->newPromotionalPrice
                ) {
                    $this->addResult(['status' => 'skipped', 'record' => $record, 'message' => 'Unchanged: ' . $record->getMessageString()]);
                    continue;
                }
                $record->title               = $incoming->title;
                $record->oldPrice            = $incoming->oldPrice;
                $record->newPrice            = $incoming->newPrice;
                $record->oldPromotionalPrice = $incoming->oldPromotionalPrice;
                $record->newPromotionalPrice = $incoming->newPromotionalPrice;
                $record->ruleLabel           = $incoming->ruleLabel;
                $record->ruleName            = $incoming->ruleName;
                $record->ruleIndex           = $incoming->ruleIndex;
                $savedStatus = 'updated';
            } else {
                $record      = $incoming;
                $savedStatus = 'staged';
            }

            if (!$record->save()) {
                $this->addResult([
                    'status'  => 'error',
                    'record'  => $record,
                    'message' => 'Failed staging: ' . $record->getMessageString(),
                    'errors'  => $record->getErrors(),
                ]);
                continue;
            }

            $this->addResult(['status' => $savedStatus, 'record' => $record, 'message' => $record->getMessageString()]);
        }

        return $this->currentResults;
    }

    /**
     * Apply staged price-schedule records to Commerce variants.
     *
     * @param  PriceSchedule[] $records
     * @param  bool            $resetPromotion  Force promotional price to null on apply
     * @param  string|null     $ruleName        Used for the log file name
     * @param  bool            $dryRun          When true, only output messages without writing to the DB
     * @return array[]  Each item: status (applied|skipped|error), record, message
     */
    public function applyRecords(array $records, bool $resetPromotion = false, ?string $ruleName = null, bool $dryRun = false): array
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

            if ($dryRun) {
                $this->addResult(['status' => 'applied', 'record' => $record, 'message' => '[DRY RUN] Would apply: ' . $record->getMessageString()]);
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

            $this->addResult(['status' => 'applied', 'record' => $record, 'message' => 'Applied: ' . $record->getMessageString()]);
            $productIds[$variant->ownerId] = true;
        }

        if (!$dryRun && PriceadjusterPlugin::getInstance()->getSettings()->resaveProducts) {
            $this->resaveProducts(array_keys($productIds));
        }

        if (!$dryRun) {
            $this->writeLog($ruleName ?? 'unknown', 'apply', $this->currentResults);
        }

        return $this->currentResults;
    }

    /**
     * Roll back applied price-schedule records.
     *
     * @param  PriceSchedule[] $records
     * @param  string|null     $ruleName  Used for the log file name
     * @param  bool            $dryRun    When true, only output messages without writing to the DB
     * @return array[]  Each item: status (rolledBack|skipped|error), record, message
     */
    public function rollbackRecords(array $records, ?string $ruleName = null, bool $dryRun = false): array
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

            if ($dryRun) {
                $this->addResult(['status' => 'rolledBack', 'record' => $record, 'message' => '[DRY RUN] Would roll back: ' . $record->getMessageString()]);
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

            $this->addResult(['status' => 'rolledBack', 'record' => $record, 'message' => 'Rolled back: ' . $record->getMessageString()]);
            $productIds[$variant->ownerId] = true;
        }

        if (!$dryRun && PriceadjusterPlugin::getInstance()->getSettings()->resaveProducts) {
            $this->resaveProducts(array_keys($productIds));
        }

        if (!$dryRun) {
            $this->writeLog($ruleName ?? 'unknown', 'rollback', $this->currentResults);
        }

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
                $this->addResult(['status' => 'error', 'record' => $record, 'message' => 'Failed deleting: ' . $record->getMessageString()]);
            } else {
                $this->addResult(['status' => 'deleted', 'record' => $record, 'message' => 'Deleted: ' . $record->getMessageString()]);
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

    /**
     * Apply a price adjustment using an optional friendly-price strategy.
     *
     * Adjustment types:
     *  - `percentage` — multiplies price by (1 + value/100) then applies friendly-price rounding
     *  - `amount`     — adds value to price (may be negative), rounded to 2 dp
     *  - `reset`      — returns null (clears the price)
     *  - `callback`   — delegates entirely to a callable; `value` must be a static-method string
     *                   or (when set programmatically) any PHP callable.
     *                   Callable signature: `function(float $price, ?Variant $variant): ?float`
     *                   Returning null clears the price, identical to `reset`.
     *
     * @param  string|callable|null $strategy  Friendly-price strategy, used only for `percentage` type.
     * @param  Variant|null         $variant   Current variant; passed to callable strategies/callbacks.
     */
    public function applyAdjustment(float $price, array $adjustment, string|callable|null $strategy = null, ?Variant $variant = null): ?float
    {
        $type = $adjustment['type'] ?? '';
        if ($type === 'reset') {
            return null;
        }
        if ($type === 'callback') {
            $ref = $adjustment['value'] ?? null;
            if (!$ref) {
                throw new \InvalidArgumentException('Adjustment type "callback" requires a non-empty "value" key.');
            }
            $callable = $this->resolveCallable($ref);
            $result   = $callable($price, $variant);
            return $result !== null ? $this->zeroAsNull((float)$result) : null;
        }
        $value = (float)($adjustment['value'] ?? 0);
        if ($type === 'percentage') {
            return $this->friendlyPrice($price * (1 + $value / 100), $strategy, $variant);
        }
        if ($type === 'amount') {
            return round($price + $value, 2);
        }
        return $price;
    }

    /**
     * Apply friendly rounding using the given strategy.
     *
     * Resolution order:
     *  1. `$strategy` argument (from JSON rule `friendlyPriceStrategy` key, or passed directly)
     *  2. Plugin setting `friendlyPriceStrategy` (project-wide default)
     *  3. Hard-coded fallback: `x.95`
     *
     * @param  string|callable|null $strategy  Named strategy, `'Class::method'` string, or any PHP callable.
     * @param  Variant|null         $variant   Passed through to callable strategies.
     */
    public function friendlyPrice(float $price, string|callable|null $strategy = null, ?Variant $variant = null): float
    {
        return $this->resolveFriendlyPrice($price, $strategy, $variant);
    }

    /**
     * Resolve and apply a friendly-price rounding strategy.
     *
     * Named strategies:
     * | Strategy | Formula                     | Example (raw 49.73) |
     * |----------|-----------------------------|---------------------|
     * | `x.99`   | `floor(price) + 0.99`       | 49.99               |
     * | `x.95`   | `floor(price) + 0.95`       | 49.95  *(default)*  |
     * | `x.90`   | `floor(price) + 0.90`       | 49.90               |
     * | `round`  | `round(price, 0)`           | 50.00               |
     * | `ceil`   | `ceil(price)`               | 50.00               |
     * | `floor`  | `floor(price)`              | 49.00               |
     * | `exact`  | no rounding                 | 49.73               |
     *
     * Callable strategies (PHP config / programmatic use only):
     * - Any PHP callable with signature `function(float $price): float`
     * - Static-method string: `'MyNamespace\Helpers\PriceHelper::myMethod'`
     *
     * @param  string|callable|null $strategy  Overrides the plugin setting when non-null.
     */
    private function resolveFriendlyPrice(float $price, string|callable|null $strategy, ?Variant $variant): float
    {
        // Argument takes precedence; fall back to plugin setting; then hard-coded default.
        $resolved = $strategy
            ?? PriceadjusterPlugin::getInstance()->getSettings()->friendlyPriceStrategy
            ?? 'x.95';

        // PHP callable (closure, [$object, 'method'], or ['Class', 'method'] array form)
        // or static-method string — delegate to shared resolver.
        if (!is_string($resolved) || str_contains($resolved, '::')) {
            return (float)$this->resolveCallable($resolved)($price, $variant);
        }

        return match ($resolved) {
            'x.99'  => round(floor($price) + 0.99, 2),
            'x.90'  => round(floor($price) + 0.90, 2),
            'round' => (float)round($price, 0),
            'ceil'  => (float)ceil($price),
            'floor' => (float)floor($price),
            'exact' => $price,
            default => round(floor($price) + 0.95, 2), // 'x.95' and any unknown value
        };
    }

    /**
     * Resolve a static-method string or PHP callable into an actual callable.
     *
     * Accepts:
     *  - Any PHP callable that is not a plain string (closure, array form, invokable object)
     *  - A `'Namespace\ClassName::methodName'` static-method string
     *
     * @throws \InvalidArgumentException if the reference cannot be resolved.
     */
    private function resolveCallable(string|callable $ref): callable
    {
        // Already a non-string PHP callable (closure, [$obj, 'method'], etc.)
        if (!is_string($ref)) {
            if (!is_callable($ref)) {
                throw new \InvalidArgumentException('Provided value is not callable.');
            }
            return $ref;
        }

        // Static-method string: 'Namespace\ClassName::methodName'
        if (str_contains($ref, '::')) {
            [$class, $method] = explode('::', $ref, 2);
            if (class_exists($class) && method_exists($class, $method)) {
                return [$class, $method];
            }
            throw new \InvalidArgumentException("Callback '{$ref}' could not be resolved (class or method not found).");
        }

        throw new \InvalidArgumentException("Cannot resolve '{$ref}' as a callable. Use 'ClassName::method' format.");
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
        if (!empty($rule['label'])) {
            return $rule['label'];
        }

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

