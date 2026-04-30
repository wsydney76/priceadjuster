<?php
namespace wsydney76\priceadjuster\console\controllers;
use Craft;
use craft\helpers\Console;
use wsydney76\priceadjuster\PriceadjusterPlugin;
use wsydney76\priceadjuster\records\PriceSchedule;
use yii\console\Controller;
use yii\console\ExitCode;
class ExportController extends Controller
{
    /** @var string|null Date to export (YYYY-MM-DD) */
    public ?string $date = null;
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['date']);
    }
    /**
     * Export staged price schedule for a date to CSV.
     *
     * Usage:
     * php craft _priceadjuster/export/index --date=2027-01-01
     */
    public function actionIndex(): int
    {
        if (!$this->date) {
            $this->stderr("--date is required\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $records = PriceSchedule::find()
            ->where(['effectiveDate' => $this->date])
            ->orderBy(['title' => SORT_ASC])
            ->all();
        if (empty($records)) {
            $this->stderr("No schedule records found for date: {$this->date}\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }
        $exportDir = PriceadjusterPlugin::getInstance()->getExportDirectory();
        $filename  = $exportDir . "/price-schedule-{$this->date}.csv";
        $fp = fopen($filename, 'w');
        if ($fp === false) {
            $this->stderr("Could not open file for writing: $filename\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        fputcsv($fp, ['SKU', 'Title', 'Rule', 'Effective Date', 'Applied At', 'Old Price', 'New Price', 'Old Promo Price', 'New Promo Price'], ';');
        foreach ($records as $record) {
            fputcsv($fp, [
                $record->sku,
                $record->title,
                $record->ruleLabel,
                $record->effectiveDate,
                $record->appliedAt ?? '',
                number_format((float)$record->oldPrice, 2, '.', ''),
                number_format((float)$record->newPrice, 2, '.', ''),
                $record->oldPromotionalPrice !== null ? number_format((float)$record->oldPromotionalPrice, 2, '.', '') : '',
                $record->newPromotionalPrice !== null ? number_format((float)$record->newPromotionalPrice, 2, '.', '') : '',
            ], ';');
        }
        fclose($fp);
        $this->stdout("Exported " . count($records) . " records to $filename\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
