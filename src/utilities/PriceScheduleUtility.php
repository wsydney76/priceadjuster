<?php
namespace wsydney76\priceadjuster\utilities;
use Craft;
use craft\base\Utility;
use craft\commerce\elements\Variant;
use wsydney76\priceadjuster\records\PriceSchedule;
use yii\db\Expression;
/**
 * Price Schedule utility — shows all staged price changes and allows editing newPrice.
 */
class PriceScheduleUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Price Schedule';
    }
    public static function id(): string
    {
        return 'price-schedule';
    }
    public static function icon(): ?string
    {
        return 'calendar';
    }
    public static function contentHtml(): string
    {
        $request = Craft::$app->getRequest();
        $rule = $request->getParam('rule');
        // No rule selected — list distinct rule names from DB
        if ($rule === null || $rule === '') {
            $rows = PriceSchedule::find()
                ->select(['ruleName', new Expression('COUNT(*) as total'), new Expression('SUM(appliedAt IS NULL) as pending'), new Expression('SUM(appliedAt IS NOT NULL) as applied')])
                ->groupBy(['ruleName'])
                ->orderBy(['ruleName' => SORT_ASC])
                ->asArray()
                ->all();
            return Craft::$app->getView()->renderTemplate(
                '_priceadjuster/utilities/price-schedule.twig',
                [
                    'ruleList' => $rows,
                    'grouped' => null,
                    'productEditUrls' => [],
                    'currentRule' => null,
                ]
            );
        }
        // Rule selected — show filtered records
        $records = PriceSchedule::find()
            ->where(['ruleName' => $rule])
            ->orderBy(['effectiveDate' => SORT_ASC, 'title' => SORT_ASC])
            ->all();
        $variantIds = array_unique(array_filter(array_map(fn($r) => (int)$r->variantId, $records)));
        $productEditUrls = [];
        if (!empty($variantIds)) {
            $variants = Variant::find()->id($variantIds)->status(null)->all();
            foreach ($variants as $variant) {
                $productEditUrls[(int)$variant->id] = $variant->owner?->getCpEditUrl();
            }
        }
        // Group by effectiveDate
        $grouped = [];
        foreach ($records as $record) {
            $grouped[$record->effectiveDate][] = $record;
        }
        return Craft::$app->getView()->renderTemplate(
            '_priceadjuster/utilities/price-schedule.twig',
            [
                'ruleList' => null,
                'grouped' => $grouped,
                'productEditUrls' => $productEditUrls,
                'currentRule' => $rule,
            ]
        );
    }
}
