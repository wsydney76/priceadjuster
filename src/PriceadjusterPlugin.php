<?php
namespace wsydney76\priceadjuster;
use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use craft\services\Utilities;
use wsydney76\priceadjuster\models\Settings;
use wsydney76\priceadjuster\services\SchedulerService;
use wsydney76\priceadjuster\utilities\PriceScheduleUtility;
use yii\base\Event;
/**
 * Price Adjuster plugin
 *
 * @method static PriceadjusterPlugin getInstance()
 * @method Settings getSettings()
 * @property-read SchedulerService $scheduler
 */
class PriceadjusterPlugin extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public static function config(): array
    {
        return [
            'components' => [
                'scheduler' => SchedulerService::class,
            ],
        ];
    }
    public function init(): void
    {
        parent::init();
        if (Craft::$app->request->isConsoleRequest) {
            $this->controllerNamespace = 'wsydney76\\priceadjuster\\console\\controllers';
        }
        $this->attachEventHandlers();
        Craft::$app->onInit(function() {
            // ...
        });
    }
    // -------------------------------------------------------------------------
    // Directory helpers — parse env vars and resolve Craft aliases
    // -------------------------------------------------------------------------
    public function getRulesDirectory(): string
    {
        return Craft::getAlias(App::parseEnv($this->getSettings()->rulesDirectory));
    }
    public function getExportDirectory(): string
    {
        return Craft::getAlias(App::parseEnv($this->getSettings()->exportDirectory));
    }
    public function getImportDirectory(): string
    {
        return Craft::getAlias(App::parseEnv($this->getSettings()->importDirectory));
    }
    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('_priceadjuster/settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }
    // -------------------------------------------------------------------------
    // Events
    // -------------------------------------------------------------------------
    private function attachEventHandlers(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = PriceScheduleUtility::class;
            }
        );
    }
}
