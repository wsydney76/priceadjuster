<?php
namespace wsydney76\priceadjuster\records;
use Craft;
use craft\db\ActiveRecord;
use DateTime;
use DateTimeZone;
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
 * @property array|null $ruleSnapshot
 * @property string $effectiveDate
 * @property string|null $appliedAt
 * @property array|null $updateHistory
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

    public function beforeSave($insert): bool
    {

            $userId = Craft::$app->getUser()->getId() ?? null;
            $tz = Craft::$app->getTimeZone();
            $timestamp = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d H:i:s');

            $history = $this->updateHistory ?? [];
            if (is_string($history)) {
                $history = json_decode($history, true) ?? [];
            }

            $history[] = [
                'userId' => $userId,
                'timestamp' => $timestamp,
                'newPrice' => $this->newPrice,
                'newPromotionalPrice' => $this->newPromotionalPrice,
            ];

            $this->updateHistory = $history;


        return parent::beforeSave($insert);
    }
}
