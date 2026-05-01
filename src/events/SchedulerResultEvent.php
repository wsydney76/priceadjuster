<?php

namespace wsydney76\priceadjuster\events;

use yii\base\Event;

/**
 * Fired by SchedulerService each time a single operation result is recorded.
 *
 * Listeners (e.g. a console controller) can react immediately instead of
 * waiting for the full result set to be returned.
 */
class SchedulerResultEvent extends Event
{
    /**
     * Short operation outcome: staged|updated|skipped|applied|rolledBack|deleted|error
     */
    public string $status = '';

    /** Human-readable description of this result. */
    public string $message = '';

    /**
     * Full result payload; same array that is also collected in the return value
     * of the service method (keys vary by operation).
     */
    public array $result = [];
}

