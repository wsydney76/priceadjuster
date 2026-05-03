<?php
namespace wsydney76\priceadjuster\records;
use craft\db\ActiveRecord;
/**
 * @property int $id
 * @property int $variantId
 * @property string $title
 * @property string|null $sku
 * @property string|float $oldPrice
 * @property string|float $newPrice
 * @property string|float|null $oldPromotionalPrice
 * @property string|float|null $newPromotionalPrice
 * @property string $ruleName
 * @property int|null $ruleIndex
 * @property string $ruleLabel
 * @property string|null $ruleSnapshot
 * @property string $effectiveDate
 * @property string|null $appliedAt
 */
class PriceSchedule extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%_priceadjuster_records}}';
    }

    /**
     * Return a human-readable message string for this record.
     *
     * Format: RuleName | date | Title | SKU | oldPrice -> newPrice [| oldPromo -> newPromo]
     */
    public function getMessageString(): string
    {
        $fmt = fn(mixed $v): string => $v !== null ? number_format((float)$v, 2) : 'null';

        return sprintf(
            '%s #%s | %s | %s | %s | %s -> %s | promo: %s -> %s',
            $this->ruleName,
            (string)$this->ruleIndex,
            $this->effectiveDate,
            $this->title,
            $this->sku,
            $fmt($this->oldPrice),
            $fmt($this->newPrice),
            $fmt($this->oldPromotionalPrice),
            $fmt($this->newPromotionalPrice),
        );
    }
}
