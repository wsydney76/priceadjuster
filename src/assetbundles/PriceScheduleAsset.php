<?php

namespace wsydney76\priceadjuster\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class PriceScheduleAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@wsydney76/priceadjuster/web';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/price-schedule.js',
        ];

        parent::init();
    }
}

