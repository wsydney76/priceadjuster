<?php
namespace wsydney76\priceadjuster\utilities;
use Craft;
use craft\base\Utility;
use craft\commerce\elements\Variant;
use wsydney76\priceadjuster\assetbundles\PriceScheduleAsset;
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
        $date = $request->getParam('date');

        Craft::$app->getView()->registerAssetBundle(PriceScheduleAsset::class);

        // No rule selected — list distinct rule names from DB
        if ($rule === null || $rule === '') {
            $rows = PriceSchedule::find()
                ->select(['ruleName', new Expression('COUNT(*) as total'), new Expression('SUM(appliedAt IS NULL) as pending'), new Expression('SUM(appliedAt IS NOT NULL) as applied')])
                ->groupBy(['ruleName'])
                ->orderBy(['ruleName' => SORT_ASC])
                ->asArray()
                ->all();

            // Also fetch distinct dates per rule for sub-links
            $dateRows = PriceSchedule::find()
                ->select(['ruleName', 'effectiveDate', new Expression('COUNT(*) as total'), new Expression('SUM(appliedAt IS NULL) as pending'), new Expression('SUM(appliedAt IS NOT NULL) as applied')])
                ->groupBy(['ruleName', 'effectiveDate'])
                ->orderBy(['ruleName' => SORT_ASC, 'effectiveDate' => SORT_ASC])
                ->asArray()
                ->all();

            $datesByRule = [];
            foreach ($dateRows as $dr) {
                $datesByRule[$dr['ruleName']][] = $dr;
            }

            return Craft::$app->getView()->renderTemplate(
                '_priceadjuster/utilities/price-schedule.twig',
                [
                    'ruleList' => $rows,
                    'datesByRule' => $datesByRule,
                    'grouped' => null,
                    'productEditUrls' => [],
                    'currentRule' => null,
                    'currentDate' => null,
                    'availableDates' => [],
                ]
            );
        }

        // Rule selected — fetch all records for this rule
        $allRecords = PriceSchedule::find()
            ->where(['ruleName' => $rule])
            ->orderBy(['effectiveDate' => SORT_ASC, 'title' => SORT_ASC])
            ->all();

        // Collect all available dates for the filter bar
        $availableDates = array_values(array_unique(array_map(fn($r) => $r->effectiveDate, $allRecords)));

        // Optionally filter by date
        $records = ($date !== null && $date !== '')
            ? array_values(array_filter($allRecords, fn($r) => $r->effectiveDate === $date))
            : $allRecords;

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
                'datesByRule' => [],
                'grouped' => $grouped,
                'productEditUrls' => $productEditUrls,
                'currentRule' => $rule,
                'currentDate' => $date,
                'availableDates' => $availableDates,
            ]
        );
    }
}
