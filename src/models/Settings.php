<?php
namespace wsydney76\priceadjuster\models;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
class Settings extends Model
{
    /**
     * Directory containing rule JSON files.
     * Supports environment variables (e.g. $PRICEADJUSTER_RULES_DIR).
     * Default: @root/config/priceadjuster/rules
     */
    public string $rulesDirectory = '@root/config/priceadjuster/rules';
    /**
     * Directory where exported CSV files are written.
     * Supports environment variables.
     * Default: @root/config/priceadjuster/exports
     */
    public string $exportDirectory = '@root/config/priceadjuster/exports';
    /**
     * Directory from which CSV files are read for import.
     * Supports environment variables.
     * Default: @root/config/priceadjuster/imports
     */
    public string $importDirectory = '@root/config/priceadjuster/imports';
    /**
     * Whether to re-save affected products after applying or rolling back prices.
     * Re-saving refreshes caches and triggers afterSave events (e.g. SetColorPriceJob).
     * Disable for large batches where product re-saves are not required.
     */
    public bool $resaveProducts = true;
    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['rulesDirectory', 'exportDirectory', 'importDirectory'],
            ],
        ];
    }
    public function rules(): array
    {
        return [
            [['rulesDirectory', 'exportDirectory', 'importDirectory'], 'required'],
        ];
    }
}
