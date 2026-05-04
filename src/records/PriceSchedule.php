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
     * Built-in validation rules — catch obvious data problems before any write.
     *
     * Custom business rules (e.g. per-rule price caps) belong in an
     * EVENT_BEFORE_VALIDATE listener attached to this class.
     */
    public function rules(): array
    {
        return [
            // Required fields
            [['variantId', 'oldPrice', 'newPrice', 'effectiveDate', 'ruleName'], 'required'],

            // Price fields must be positive numbers
            [['oldPrice', 'newPrice'], 'number', 'min' => 0.01,
                'message' => '{attribute} must be a positive number.'],

            // Promotional price, when set, must be positive and strictly less than newPrice
            ['newPromotionalPrice', 'number', 'min' => 0.01,
                'when'    => fn($model) => $model->newPromotionalPrice !== null,
                'message' => 'New promotional price must be a positive number.'],
            ['newPromotionalPrice', 'compare',
                'compareAttribute' => 'newPrice',
                'operator'         => '<',
                'type'             => 'number',
                'when'             => fn($model) => $model->newPromotionalPrice !== null,
                'message'          => 'New promotional price must be lower than the new price.'],

            // effectiveDate must be a valid calendar date in yyyy-mm-dd format
            ['effectiveDate', 'date', 'format' => 'php:Y-m-d',
                'message' => 'Effective date must be a valid date in yyyy-mm-dd format.'],
        ];
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

    /**
     * Returns the updateHistory as a decoded array (safe to call from Twig).
     * The first entry contains the originally staged prices; subsequent entries are manual edits.
     *
     * @return array<int, array{userId: int|null, timestamp: string, newPrice: string|float|null, newPromotionalPrice: string|float|null}>
     */
    public function getUpdateHistoryDecoded(): array
    {
        $history = $this->updateHistory;
        if (is_string($history)) {
            $history = json_decode($history, true) ?? [];
        }
        return is_array($history) ? $history : [];
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
