<?php

namespace wsydney76\priceadjuster\events;

use yii\base\Event;

class BuildRowsEvent extends Event
{
    /** @var string|null The rule name (filename without .json) passed via --rule. */
    public ?string $ruleName = null;

    /** @var array Loaded rule definitions from the JSON file. */
    public array $rules = [];

    /** @var array Computed schedule rows; listeners may modify this payload. */
    public array $rows = [];
}

