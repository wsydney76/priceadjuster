<?php
namespace wsydney76\priceadjuster\console\controllers;
use craft\helpers\Console;
use wsydney76\priceadjuster\PriceadjusterPlugin;
use wsydney76\priceadjuster\records\PriceSchedule;
use yii\console\Controller;
use yii\console\ExitCode;
class ExportController extends Controller
{
    /** @var string|null Date to export (YYYY-MM-DD or 'today') */
    public ?string $date = null;
    /** @var string|null Rule name (filename without .json) */
    public ?string $rule = null;
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['date', 'rule']);
    }
    /**
     * Export staged price schedule for a date and/or rule to CSV.
     *
     * Usage:
     * php craft _priceadjuster/export/index --date=2027-01-01
     * php craft _priceadjuster/export/index --rule=2027-01-01
     * php craft _priceadjuster/export/index --date=2027-01-01 --rule=2027-01-01
     */
    public function actionIndex(): int
    {
        $date = $this->resolveDate();
        $service = PriceadjusterPlugin::getInstance()->scheduler;
        $records = $service->getScheduleRecords(applied: null, date: $date, rule: $this->rule);
        if ($records === null) {
            $this->stderr("Either --date or --rule is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if (empty($records)) {
            $this->stderr("No schedule records found for the given filter(s).\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }
        // Sort by title for consistent output
        usort($records, fn(PriceSchedule $a, PriceSchedule $b) => strcmp((string)$a->title, (string)$b->title));
        $exportDir = PriceadjusterPlugin::getInstance()->getExportDirectory();
        $suffix = match (true) {
            (bool)$date && (bool)$this->rule => "{$date}-{$this->rule}",
            (bool)$date                       => $date,
            default                           => $this->rule,
        };
        $filename = $exportDir . "/price-schedule-{$suffix}.csv";
        if (!is_dir($exportDir) && !mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
            $this->stderr("Could not create export directory: $exportDir\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

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
    /**
     * Resolve the --date option, substituting 'today' with the current date (yyyy-mm-dd).
     */
    private function resolveDate(): ?string
    {
        if ($this->date === 'today') {
            return date('Y-m-d');
        }
        return $this->date;
    }
}
