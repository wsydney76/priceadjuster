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
 * @property string $ruleLabel
 * @property string $effectiveDate
 * @property string|null $appliedAt
 */
class PriceSchedule extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%_priceadjuster_records}}';
    }
}
