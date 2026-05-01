<?php

namespace wsydney76\priceadjuster\console\controllers;

use craft\helpers\Console;
use wsydney76\priceadjuster\events\SchedulerResultEvent;
use wsydney76\priceadjuster\PriceadjusterPlugin;
use wsydney76\priceadjuster\services\SchedulerService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Console controller — thin wrapper around SchedulerService.
 * All business logic lives in PriceadjusterPlugin::getInstance()->scheduler.
 *
 * Registers an EVENT_RESULT listener on the service before each operation so
 * that output is streamed to the terminal as each variant/record is processed,
 * rather than being buffered and printed at the end.
 */
class SchedulerController extends Controller
{
    /** @var string|null Rule name (filename without .json) in config/priceadjuster/ */
    public ?string $rule = null;
    public ?string $date = null;
    public bool $resetPromotion = false;
    public bool $replace = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'rule',
            'date',
            'resetPromotion',
            'replace',
        ]);
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Preview price changes defined in a rule file.
     *
     * Usage:
     * php craft _priceadjuster/scheduler/preview --rule=2027-01-01
     */
    public function actionPreview(): int
    {
        if (!$this->requireRule()) {
            return ExitCode::USAGE;
        }

        try {
            $rows = PriceadjusterPlugin::getInstance()->scheduler->buildRows($this->rule);
        } catch (\RuntimeException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($rows as $row) {
            $this->stdout(sprintf(
                "%s | %s | %s | %.2f -> %.2f%s\n",
                $row['effectiveDate'],
                $row['sku'],
                $row['title'],
                $row['oldPrice'],
                $row['newPrice'],
                $this->formatPromoFromRow($row)
            ));
        }

        $this->stdout("\nTotal variants: " . count($rows) . "\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Stage future prices in price_schedule.
     *
     * Use --replace to delete all matching staged records before inserting.
     */
    public function actionStage(): int
    {
        if (!$this->requireRule()) {
            return ExitCode::USAGE;
        }

        $service = PriceadjusterPlugin::getInstance()->scheduler;

        try {
            $rows = $service->buildRows($this->rule);
        } catch (\RuntimeException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $handler = $this->onResult(function(SchedulerResultEvent $event): void {
            match ($event->status) {
                'skipped' => $this->stdout("Skipped (unchanged): {$event->result['row']['sku']} | {$event->result['row']['title']}\n", Console::FG_YELLOW),
                'error'   => $this->outputError($event),
                default   => $this->stdout("Staged {$event->message}{$this->formatPromoFromRow($event->result['row'])}\n"),
            };
        });

        $service->stageRows($rows, $this->rule, $this->replace, $this->date);
        $service->off(SchedulerService::EVENT_RESULT, $handler);

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
        $service = PriceadjusterPlugin::getInstance()->scheduler;
        $records = $service->getScheduleRecords(applied: false, date: $this->date, rule: $this->rule);

        if ($records === null) {
            $this->stderr("Either --date or --rule is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $handler = $this->onResult(function(SchedulerResultEvent $event): void {
            match ($event->status) {
                'error'   => $this->outputError($event),
                'skipped' => $this->stderr("Skipped ({$event->message})\n", Console::FG_RED),
                'resaved' => $this->stdout("{$event->message}\n", Console::FG_CYAN),
                default   => $this->stdout("{$event->message}{$this->formatPromoFromRecord($event->result['record'])}\n", Console::FG_GREEN),
            };
        });

        $service->applyRecords($records, $this->resetPromotion);
        $service->off(SchedulerService::EVENT_RESULT, $handler);

        return ExitCode::OK;
    }

    /**
     * Roll back applied prices for a date.
     */
    public function actionRollback(): int
    {
        $service = PriceadjusterPlugin::getInstance()->scheduler;
        $records = $service->getScheduleRecords(applied: true, date: $this->date, rule: $this->rule);

        if ($records === null) {
            $this->stderr("Either --date or --rule is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $handler = $this->onResult(function(SchedulerResultEvent $event): void {
            match ($event->status) {
                'error'   => $this->outputError($event),
                'skipped' => $this->stderr("Skipped ({$event->message})\n", Console::FG_RED),
                'resaved' => $this->stdout("{$event->message}\n", Console::FG_CYAN),
                default   => $this->stdout("{$event->message}{$this->formatPromoFromRecord($event->result['record'])}\n", Console::FG_GREEN),
            };
        });

        $service->rollbackRecords($records);
        $service->off(SchedulerService::EVENT_RESULT, $handler);

        return ExitCode::OK;
    }

    /**
     * Delete price schedule records for a given date/rule, or ALL records when no filter is provided.
     *
     * Usage:
     * php craft _priceadjuster/scheduler/delete --date=2027-01-01
     * php craft _priceadjuster/scheduler/delete --rule=2027-01-01
     * php craft _priceadjuster/scheduler/delete   # deletes everything
     */
    public function actionDelete(): int
    {
        $service = PriceadjusterPlugin::getInstance()->scheduler;
        $records = $service->getScheduleRecords(applied: null, date: $this->date, rule: $this->rule, requireFilter: false);

        $scope = match (true) {
            (bool)$this->date && (bool)$this->rule => "date {$this->date} + rule {$this->rule}",
            (bool)$this->date                       => "date {$this->date}",
            (bool)$this->rule                       => "rule {$this->rule}",
            default                                 => 'ALL records',
        };

        if (empty($records)) {
            $this->stdout("No records found for $scope.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout(sprintf("About to delete %d record(s) for %s.\n", count($records), $scope), Console::FG_YELLOW);
        if (!$this->confirm("Confirm deletion?")) {
            $this->stdout("Aborted.\n");
            return ExitCode::OK;
        }

        $deleted = 0;
        $handler = $this->onResult(function(SchedulerResultEvent $event) use (&$deleted): void {
            if ($event->status === 'error') {
                $this->stderr("{$event->message}\n", Console::FG_RED);
            } else {
                $deleted++;
            }
        });

        $service->deleteRecords($records);
        $service->off(SchedulerService::EVENT_RESULT, $handler);

        $this->stdout("Deleted $deleted record(s) for $scope.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Register a one-shot EVENT_RESULT listener on the scheduler service.
     * Returns the handler callable so it can be `off()`-ed after the operation.
     */
    private function onResult(\Closure $handler): \Closure
    {
        PriceadjusterPlugin::getInstance()->scheduler->on(SchedulerService::EVENT_RESULT, $handler);
        return $handler;
    }

    private function requireRule(): bool
    {
        if (!$this->rule) {
            $this->stderr("--rule is required\n", Console::FG_RED);
            return false;
        }
        return true;
    }

    private function outputError(SchedulerResultEvent $event): void
    {
        $this->stderr("{$event->message}\n", Console::FG_RED);
        if (!empty($event->result['errors'])) {
            print_r($event->result['errors']);
        }
    }

    private function formatPromoFromRow(array $row): string
    {
        if ($row['oldPromotionalPrice'] === null && $row['newPromotionalPrice'] === null) {
            return '';
        }
        $old = $row['oldPromotionalPrice'] !== null ? number_format($row['oldPromotionalPrice'], 2) : 'null';
        $new = $row['newPromotionalPrice'] !== null ? number_format($row['newPromotionalPrice'], 2) : 'null';
        return sprintf(' | promo: %s -> %s', $old, $new);
    }

    private function formatPromoFromRecord(mixed $record): string
    {
        if ($record->oldPromotionalPrice === null && $record->newPromotionalPrice === null) {
            return '';
        }
        $old = $record->oldPromotionalPrice !== null ? number_format((float)$record->oldPromotionalPrice, 2) : 'null';
        $new = $record->newPromotionalPrice !== null ? number_format((float)$record->newPromotionalPrice, 2) : 'null';
        return sprintf(' | promo: %s -> %s', $old, $new);
    }
}
