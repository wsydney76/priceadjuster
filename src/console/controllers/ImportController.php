<?php
namespace wsydney76\priceadjuster\console\controllers;
use Craft;
use craft\helpers\Console;
use wsydney76\priceadjuster\PriceadjusterPlugin;
use wsydney76\priceadjuster\records\PriceSchedule;
use yii\console\Controller;
use yii\console\ExitCode;
class ImportController extends Controller
{
    /** @var string|null Date to import (YYYY-MM-DD) */
    public ?string $date = null;
    public bool $dryRun = false;
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['date', 'dryRun']);
    }
    /**
     * Import a CSV exported by the export command and update newPrice by SKU if changed.
     *
     * Usage:
     * php craft _priceadjuster/import/index --date=2027-01-01 [--dry-run]
     */
    public function actionIndex(): int
    {
        if (!$this->date) {
            $this->stderr("--date is required\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $importDir = PriceadjusterPlugin::getInstance()->getImportDirectory();
        $filename  = $importDir . "/price-schedule-{$this->date}.csv";
        if (!file_exists($filename)) {
            $this->stderr("File not found: $filename\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $fp = fopen($filename, 'r');
        if ($fp === false) {
            $this->stderr("Could not open file: $filename\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $header           = fgetcsv($fp, 0, ';');
        $skuIdx           = array_search('SKU', $header);
        $titleIdx         = array_search('Title', $header);
        $newPriceIdx      = array_search('New Price', $header);
        $newPromoPriceIdx = array_search('New Promo Price', $header);
        if ($skuIdx === false || $newPriceIdx === false) {
            $this->stderr("Invalid CSV format – expected columns SKU and New Price.\n", Console::FG_RED);
            fclose($fp);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $changed = 0;
        $skipped = 0;
        while (($row = fgetcsv($fp, 0, ';')) !== false) {
            $sku         = trim($row[$skuIdx]);
            $title       = $titleIdx !== false ? trim($row[$titleIdx]) : '';
            $csvNewPrice = round((float)$row[$newPriceIdx], 2);
            $csvNewPromoPrice = $newPromoPriceIdx !== false && $row[$newPromoPriceIdx] !== ''
                ? $this->zeroAsNull(round((float)$row[$newPromoPriceIdx], 2))
                : false;
            $record = PriceSchedule::find()
                ->where(['sku' => $sku, 'effectiveDate' => $this->date, 'appliedAt' => null])
                ->one();
            if (!$record) {
                $this->stdout("Not found (skipped): $sku\n", Console::FG_YELLOW);
                $skipped++;
                continue;
            }
            $currentPrice      = round((float)$record->newPrice, 2);
            $currentPromoPrice = $record->newPromotionalPrice !== null
                ? round((float)$record->newPromotionalPrice, 2)
                : null;
            $priceChanged = $currentPrice !== $csvNewPrice;
            $promoChanged = $csvNewPromoPrice !== false && $csvNewPromoPrice !== $currentPromoPrice;
            if (!$priceChanged && !$promoChanged) {
                $skipped++;
                continue;
            }
            $promoPart = '';
            if ($promoChanged) {
                $oldPromoStr = $currentPromoPrice !== null ? number_format($currentPromoPrice, 2, '.', '') : 'null';
                $newPromoStr = $csvNewPromoPrice !== null ? number_format($csvNewPromoPrice, 2, '.', '') : 'null';
                $promoPart = sprintf(' | promo: %s -> %s', $oldPromoStr, $newPromoStr);
            }
            $this->stdout(sprintf(
                "%s | %s | %s -> %s%s%s\n",
                $sku,
                $title,
                number_format($currentPrice, 2, '.', ''),
                number_format($csvNewPrice, 2, '.', ''),
                $promoPart,
                $this->dryRun ? ' [dry-run]' : ''
            ));
            if (!$this->dryRun) {
                if ($priceChanged) {
                    $record->newPrice = $csvNewPrice;
                }
                if ($promoChanged) {
                    $record->newPromotionalPrice = $csvNewPromoPrice;
                }
                if (!$record->save()) {
                    $this->stderr("Failed saving record for SKU $sku\n", Console::FG_RED);
                    print_r($record->getErrors());
                    continue;
                }
            }
            $changed++;
        }
        fclose($fp);
        $label = $this->dryRun ? 'Would update' : 'Updated';
        $this->stdout("\n$label $changed record(s), skipped $skipped.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
    private function zeroAsNull(?float $value): ?float
    {
        return ($value === null || $value === 0.0) ? null : $value;
    }
}
