<?php
namespace wsydney76\priceadjuster\console\controllers;
use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\helpers\Console;
use wsydney76\priceadjuster\events\BuildRowsEvent;
use wsydney76\priceadjuster\records\PriceSchedule;
use wsydney76\priceadjuster\PriceadjusterPlugin;
use yii\console\Controller;
use yii\console\ExitCode;
class SchedulerController extends Controller
{
    public const EVENT_BUILD_ROWS = 'buildRows';

    /** @var string|null Rule name (filename without .json) in config/priceadjuster/ */
    public ?string $rule = null;
    public ?string $date = null;
    public bool $resetPromotion = false;
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'rule',
            'date',
            'resetPromotion',
        ]);
    }
    /**
     * Preview price changes defined in a rule file.
     *
     * Usage:
     * php craft _priceadjuster/scheduler/preview --rule=2027-01-01
     */
    public function actionPreview(): int
    {
        $rows = $this->buildRows();
        foreach ($rows as $row) {
            $promoStr = '';
            if ($row['oldPromotionalPrice'] !== null || $row['newPromotionalPrice'] !== null) {
                $old = $row['oldPromotionalPrice'] !== null ? number_format($row['oldPromotionalPrice'], 2) : 'null';
                $new = $row['newPromotionalPrice'] !== null ? number_format($row['newPromotionalPrice'], 2) : 'null';
                $promoStr = sprintf(' | promo: %s -> %s', $old, $new);
            }
            $this->stdout(sprintf(
                "%s | %s | %s | %.2f -> %.2f%s\n",
                $row['effectiveDate'],
                $row['sku'],
                $row['title'],
                $row['oldPrice'],
                $row['newPrice'],
                $promoStr
            ));
        }
        $this->stdout("\nTotal variants: " . count($rows) . "\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
    /**
     * Stage future prices in price_schedule.
     */
    public function actionStage(): int
    {
        $rows = $this->buildRows();
        foreach ($rows as $row) {
            $effectiveDate = $row['effectiveDate'];
            $whereClause = [
                'variantId' => $row['variantId'],
                'effectiveDate' => $effectiveDate,
                'appliedAt' => null,
            ];
            $exists = PriceSchedule::find()->where($whereClause)->exists();
            if ($exists) {
                $record = PriceSchedule::find()->where($whereClause)->one();
                if (
                    (float)$record->newPrice === $row['newPrice'] &&
                    $record->newPromotionalPrice == $row['newPromotionalPrice']
                ) {
                    $this->stdout("Skipped (unchanged): {$row['sku']} | {$row['title']}\n", Console::FG_YELLOW);
                    continue;
                }
                $record->title               = $row['title'];
                $record->oldPrice            = $row['oldPrice'];
                $record->newPrice            = $row['newPrice'];
                $record->oldPromotionalPrice = $row['oldPromotionalPrice'];
                $record->newPromotionalPrice = $row['newPromotionalPrice'];
                $record->ruleLabel           = $row['ruleLabel'];
                $record->ruleName            = $this->rule;
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
                $record->ruleName            = $this->rule;
                $record->effectiveDate       = $effectiveDate;
            }
            if (!$record->save()) {
                $this->stderr("Failed staging variant {$row['variantId']}\n", Console::FG_RED);
                print_r($record->getErrors());
                continue;
            }
            $this->stdout("Staged {$row['sku']} | {$row['title']}: {$row['oldPrice']} -> {$row['newPrice']}\n");
        }
        return ExitCode::OK;
    }
    /**
     * Apply staged prices.
     *
     * Usage:
     * php craft _priceadjuster/scheduler/apply --date=2027-01-01
     */
    public function actionApply(): int
    {
        if (!$this->date && !$this->rule) {
            $this->stderr("Either --date or --rule is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $query = PriceSchedule::find()->where(['appliedAt' => null]);
        if ($this->date) {
            $query->andWhere(['effectiveDate' => $this->date]);
        }
        if ($this->rule) {
            $query->andWhere(['ruleName' => $this->rule]);
        }
        $records = $query->all();
        $productIds = [];
        foreach ($records as $record) {
            $variant = Variant::find()->id((int)$record->variantId)->status(null)->one();
            if (!$variant) {
                $this->stderr("Variant not found: {$record->variantId}\n", Console::FG_RED);
                continue;
            }
            $newPrice = (float)$record->newPrice;
            if ($newPrice === 0.0) {
                $this->stderr("Skipped (newPrice is 0): {$record->sku}\n", Console::FG_RED);
                continue;
            }
            $variant->basePrice = $newPrice;
            $newPromoPrice = ($this->resetPromotion || $record->newPromotionalPrice === null)
                ? null
                : $this->zeroAsNull((float)$record->newPromotionalPrice);
            $variant->basePromotionalPrice = $newPromoPrice;
            if (!Craft::$app->elements->saveElement($variant)) {
                $this->stderr("Failed saving variant {$variant->id}\n", Console::FG_RED);
                continue;
            }
            $record->appliedAt = Craft::$app->formatter->asDatetime('now', 'php:Y-m-d H:i:s');
            if (!$record->save()) {
                $this->stderr("Price applied, but failed marking schedule row as applied: {$record->id}\n", Console::FG_RED);
                print_r($record->getErrors());
                continue;
            }
            $promoStr = '';
            if ($record->oldPromotionalPrice !== null || $record->newPromotionalPrice !== null) {
                $old = $record->oldPromotionalPrice !== null ? number_format((float)$record->oldPromotionalPrice, 2) : 'null';
                $new = $record->newPromotionalPrice !== null ? number_format((float)$record->newPromotionalPrice, 2) : 'null';
                $promoStr = sprintf(' | promo: %s -> %s', $old, $new);
            }
            $this->stdout(sprintf("Applied %s: %.2f -> %.2f%s\n", $record->sku, (float)$record->oldPrice, (float)$record->newPrice, $promoStr), Console::FG_GREEN);
            $productIds[$variant->ownerId] = true;
        }
        if (PriceadjusterPlugin::getInstance()->getSettings()->resaveProducts) {
            $this->resaveProducts(array_keys($productIds));
        }
        return ExitCode::OK;
    }
    /**
     * Roll back applied prices for a date.
     */
    public function actionRollback(): int
    {
        if (!$this->date && !$this->rule) {
            $this->stderr("Either --date or --rule is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $query = PriceSchedule::find()->andWhere(['not', ['appliedAt' => null]]);
        if ($this->date) {
            $query->andWhere(['effectiveDate' => $this->date]);
        }
        if ($this->rule) {
            $query->andWhere(['ruleName' => $this->rule]);
        }
        $records = $query->all();
        $productIds = [];
        foreach ($records as $record) {
            $variant = Variant::find()->id((int)$record->variantId)->status(null)->one();
            if (!$variant) {
                $this->stderr("Variant not found: {$record->variantId}\n", Console::FG_RED);
                continue;
            }
            $oldPrice = (float)$record->oldPrice;
            if ($oldPrice === 0.0) {
                $this->stderr("Skipped (oldPrice is 0): {$record->sku}\n", Console::FG_RED);
                continue;
            }
            $variant->basePrice = $oldPrice;
            $variant->basePromotionalPrice = $record->oldPromotionalPrice !== null
                ? ((float)$record->oldPromotionalPrice ?: null)
                : null;
            if (!Craft::$app->elements->saveElement($variant)) {
                $this->stderr("Failed rolling back variant {$variant->id}\n", Console::FG_RED);
                continue;
            }
            $record->appliedAt = null;
            if (!$record->save()) {
                $this->stderr("Rolled back price, but failed updating schedule row: {$record->id}\n", Console::FG_RED);
                print_r($record->getErrors());
                continue;
            }
            $promoStr = '';
            if ($record->oldPromotionalPrice !== null || $record->newPromotionalPrice !== null) {
                $old = $record->oldPromotionalPrice !== null ? number_format((float)$record->oldPromotionalPrice, 2) : 'null';
                $new = $record->newPromotionalPrice !== null ? number_format((float)$record->newPromotionalPrice, 2) : 'null';
                $promoStr = sprintf(' | promo: %s -> %s', $new, $old);
            }
            $this->stdout(sprintf("Rolled back %s: %.2f -> %.2f%s\n", $record->sku, (float)$record->newPrice, (float)$record->oldPrice, $promoStr), Console::FG_GREEN);
            $productIds[$variant->ownerId] = true;
        }
        if (PriceadjusterPlugin::getInstance()->getSettings()->resaveProducts) {
            $this->resaveProducts(array_keys($productIds));
        }
        return ExitCode::OK;
    }
    /**
     * Delete all staged price records for a given date.
     *
     * Usage:
     * php craft _priceadjuster/scheduler/delete --date=2027-01-01
     */
    public function actionDelete(): int
    {
        $query = PriceSchedule::find();
        if ($this->date) {
            $query->where(['effectiveDate' => $this->date]);
            $scope = "date {$this->date}";
        } else {
            $scope = "ALL dates";
        }
        $records = $query->all();
        if (empty($records)) {
            $this->stdout("No records found for $scope.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }
        $this->stdout(sprintf("About to delete %d record(s) for %s.\n", count($records), $scope), Console::FG_YELLOW);
        if (!$this->confirm("Confirm deletion?")) {
            $this->stdout("Aborted.\n");
            return ExitCode::OK;
        }
        foreach ($records as $record) {
            if (!$record->delete()) {
                $this->stderr("Failed deleting record {$record->id} ({$record->sku})\n", Console::FG_RED);
            }
        }
        $this->stdout("Deleted " . count($records) . " record(s) for $scope.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------
    private function loadRules(): array
    {
        if (!$this->rule) {
            $this->stderr("--rule is required\n", Console::FG_RED);
            exit(ExitCode::USAGE);
        }
        $path = PriceadjusterPlugin::getInstance()->getRulesDirectory() . '/' . $this->rule . '.json';
        if (!file_exists($path)) {
            $this->stderr("Rule file not found: $path\n", Console::FG_RED);
            exit(ExitCode::UNSPECIFIED_ERROR);
        }
        $rules = json_decode(file_get_contents($path), true);
        if (!is_array($rules) || empty($rules)) {
            $this->stderr("Rule file is empty or invalid JSON: $path\n", Console::FG_RED);
            exit(ExitCode::UNSPECIFIED_ERROR);
        }
        return $rules;
    }
    private function buildRows(): array
    {
        $rules  = $this->loadRules();
        $rowMap = [];
        foreach ($rules as $index => $rule) {
            $effectiveDate = $rule['effective_date'] ?? null;
            if (!$effectiveDate) {
                $this->stdout("Rule #$index is missing 'effective_date' – skipping.\n", Console::FG_YELLOW);
                continue;
            }
            $criteria               = $rule['criteria'] ?? [];
            $variantCriteria        = $rule['variantCriteria'] ?? [];
            $adjustment             = $rule['priceAdjustment'] ?? [];
            $promotionalAdjustment  = $rule['promotionalPriceAdjustment'] ?? null;
            $ruleLabel  = $this->buildRuleLabel($rule, $index);
            $variants = $this->getVariantsForCriteria($criteria, $variantCriteria);
            if (empty($variants)) {
                $this->stdout("Rule #$index ($ruleLabel): no variants found – skipping.\n", Console::FG_YELLOW);
                continue;
            }
            foreach ($variants as $variant) {
                $oldPrice = (float)$variant->basePrice;
                $newPrice = !empty($adjustment)
                    ? $this->applyAdjustment($oldPrice, $adjustment)
                    : $oldPrice;
                if ($oldPrice === 0.0 || $newPrice === 0.0) {
                    $this->stdout("Skipped (price would be 0): {$variant->sku}\n", Console::FG_YELLOW);
                    continue;
                }
                $currentPromoPrice   = $variant->basePromotionalPrice !== null ? (float)$variant->basePromotionalPrice : null;
                $oldPromotionalPrice = $this->zeroAsNull($currentPromoPrice);
                if ($promotionalAdjustment !== null) {
                    $newPromotionalPrice = $this->zeroAsNull($this->applyAdjustment($newPrice, $promotionalAdjustment));
                } else {
                    $newPromotionalPrice = null;
                }
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
            'rules' => $rules,
            'rows' => $rows,
        ]);
        $this->trigger(self::EVENT_BUILD_ROWS, $event);

        return $event->rows;
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
    private function applyAdjustment(float $price, array $adjustment): float
    {
        $type  = $adjustment['type'] ?? '';
        $value = (float)($adjustment['value'] ?? 0);
        if ($type === 'percentage') {
            return $this->friendlyPrice($price * (1 + $value / 100));
        }
        if ($type === 'amount') {
            return round($price + $value, 2);
        }
        return $price;
    }
    private function friendlyPrice(float $price): float
    {
        return round(floor($price) + 0.95, 2);
    }
    private function zeroAsNull(?float $value): ?float
    {
        return ($value === null || $value === 0.0) ? null : $value;
    }
    private function resaveProducts(array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }
        $this->stdout(sprintf("\nResaving %d product(s)…\n", count($productIds)));
        $products = Product::find()->id($productIds)->status(null)->all();
        foreach ($products as $product) {
            if (!Craft::$app->elements->saveElement($product)) {
                $this->stderr("Failed resaving product {$product->id} ({$product->title})\n", Console::FG_RED);
            } else {
                $this->stdout("Resaved product: {$product->title}\n", Console::FG_GREEN);
            }
        }
    }
    private function buildRuleLabel(array $rule, int $index): string
    {
        $effectiveDate = $rule['effective_date'] ?? '?';
        $adjustment    = $rule['priceAdjustment'] ?? [];
        $type          = $adjustment['type'] ?? '';
        $value         = $adjustment['value'] ?? 0;
        $criteria      = $rule['criteria'] ?? [];
        $criteriaStr = implode(', ', array_map(
            fn($k, $v) => "$k:" . (is_array($v) ? implode('+', $v) : $v),
            array_keys($criteria),
            $criteria
        ));
        $adjustStr = match($type) {
            'percentage' => "+{$value}%",
            'amount'     => "+{$value}",
            default      => $type,
        };
        return sprintf('%s #%d [%s] %s eff. %s', $this->rule, $index + 1, $criteriaStr, $adjustStr, $effectiveDate);
    }
}
