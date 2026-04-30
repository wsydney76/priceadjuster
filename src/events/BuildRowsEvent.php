<?php

namespace wsydney76\priceadjuster\events;

use yii\base\Event;

class BuildRowsEvent extends Event
{
    /** @var array Loaded rule definitions from the JSON file. */
    public array $rules = [];

    /** @var array Computed schedule rows; listeners may modify this payload. */
    public array $rows = [];
}

