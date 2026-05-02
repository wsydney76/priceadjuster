# Price Adjuster — Craft Commerce Plugin
A Craft CMS 5 plugin for **batch-scheduling future price changes** for Craft Commerce variants. Define price rules in JSON config files, stage them into the database, review and edit them in the Control Panel, then apply (or roll back) when the effective date arrives.


__Disclaimer: This plugin is in early development and should not be used in production yet. The API is likely to change without deprecation, and there may be bugs or missing features. Concept, code and this Readme file mostly AI generated using Claude Sonnet 4.6.__

## Requirements
- Craft CMS 5.x
- Craft Commerce 5.x
- PHP 8.4+

## Installation


Update `composer.json` file in your project root so that it includes the following:

```json
{
  "require": {
    "wsydney76/craft-priceadjuster": "dev-main"
  },
  "minimum-stability": "dev",
  "prefer-stable": true,
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/wsydney76/priceadjuster"
    }
  ]
}
```

Run `composer update` to install the plugin.

Then, install the plugin via the Craft CMS Control Panel, or run `craft plugin/install _priceadjuster`.

## How It Works
1. **Author a rule file** — a JSON file in the configured `rulesDirectory` describing which products/variants to target and how to adjust their prices.
2. **Preview** — dry-run the rule to see what would change.
3. **Stage** — write the planned changes to the DB (no prices change yet).
4. **Review & edit** — use the **Price Schedule** CP utility to inspect, fine-tune individual prices, or delete rows before applying.
5. **Apply** — write the new prices to Commerce variants on (or after) the effective date.
6. **Roll back** — restore original prices if needed.

## Configuration
### Settings overview
| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `rulesDirectory` | string | `@root/config/priceadjuster/rules` | Directory containing rule JSON files |
| `exportDirectory` | string | `@root/config/priceadjuster/exports` | Directory where exported CSV files are written |
| `importDirectory` | string | `@root/config/priceadjuster/imports` | Directory from which CSV files are read for import |
| `resaveProducts` | bool | `true` | Re-save affected products after `apply`/`rollback` to refresh caches and trigger `afterSave` events |
| `friendlyPriceStrategy` | `string\|callable\|null` | `null` | Project-wide default rounding strategy for `percentage` adjustments. `null` falls back to `x.95`. See [Friendly-Price Rounding](#friendly-price-rounding). |

All three values accept **Craft aliases** (`@root`, `@webroot`, …) and **environment variable** references (`$MY_VAR`). Values are resolved at runtime via `App::parseEnv()` followed by `Craft::getAlias()`.
Export and import directories are intentionally separate so the original auto-generated export is never overwritten by a hand-edited import file.

### Via CP

Navigate to **Settings → Price Adjuster**. The fields use Craft's autosuggest input, which offers alias and env-var completion as you type.

### Via config file (recommended)
Create `config/_priceadjuster.php` in your Craft project root and reference environment variables from `.env`:
```php
<?php
// config/_priceadjuster.php
return [
    'rulesDirectory'  => '$PRICEADJUSTER_RULES_DIR',
    'exportDirectory' => '$PRICEADJUSTER_EXPORT_DIR',
    'importDirectory' => '$PRICEADJUSTER_IMPORT_DIR',
    'resaveProducts'  => true,
];
```
### `.env` entries
```dotenv
PRICEADJUSTER_RULES_DIR=/var/www/html/_priceadjuster/rules
PRICEADJUSTER_EXPORT_DIR=/var/www/html/_priceadjuster/export
PRICEADJUSTER_IMPORT_DIR=/var/www/html/_priceadjuster/import
```
> **Tip:** Use absolute container paths in `.env` (e.g. `/var/www/html/…` in ddev) or Craft aliases in `config/_priceadjuster.php` (e.g. `@root/…`). Do not mix both styles in the same value.

## Rule Files
Rule files live in `rulesDirectory` (default: `config/priceadjuster/rules/`). Pass the filename without `.json` to CLI commands via `--rule=<name>`.
Each file contains a JSON **array** of rule entries. Multiple entries can share the same or different effective dates.
### Rule Entry Schema
| Key | Type | Required | Description |
|-----|------|:--------:|-------------|
| `effective_date` | `string` (YYYY-MM-DD) | ✓ | The date prices should take effect |
| `criteria` | `object` | ✓ | Product query filters (any `Product::find()` method as key → value) |
| `variantCriteria` | `object` | — | Additional variant query filters (any `Variant::find()` method) |
| `priceAdjustment` | `object` | — | How to adjust `basePrice` (omit to leave price unchanged) |
| `promotionalPriceAdjustment` | `object` | — | How to adjust `basePromotionalPrice` (omit to leave promotional price unchanged) |

### Criteria examples
```json
{ "id": [19827, 21153] }
{ "productCategory": 4953 }
{ "productCategory": [4953,1234] }
{ "productCategory": ['not',1234] }
{ "hasVariant": { "color": 19846 } }
```

For convenience, criteria values for entries fields can be specified as either IDs or handles in the form `section:comma separated list of slugs`. The plugin will resolve handles to IDs at runtime before querying.

```json
{ "productCategory": "productCategory:mini-dresses,evening-dresses" }

```

### Adjustment Object
```json
{ "type": "percentage", "value": 10 }
{ "type": "amount",     "value": 5  }
{ "type": "reset" }
{ "type": "callback",   "value": "MyNamespace\\Helpers\\PriceHelper::computeNewPrice" }
```
- **`percentage`** — multiplies the current price by `(1 + value/100)` and applies friendly rounding (see [Friendly-Price Rounding](#friendly-price-rounding))
- **`amount`** — adds `value` to the current price (can be negative), rounded to 2 decimal places
- **`reset`** — clears the price (sets to `null`), effectively removing it from sale
- **`callback`** — delegates price calculation entirely to a callable; `value` is a static-method string (see [Adjustment Callbacks](#adjustment-callbacks))

### Adjustment Callbacks

The `callback` type hands full control of price computation to your own PHP method. It works for both `priceAdjustment` and `promotionalPriceAdjustment`.

**Callable signature:** `function(float $price, ?Variant $variant): ?float`
- Receives the **current** variant price and the **Variant element** (or `null` when called outside of `buildRows()`).
- Return a `float` for the new price, or `null` to clear the price (equivalent to `"type": "reset"`).
- The `Variant` object gives you access to SKU, custom fields, `ownerId`, etc. for conditional logic.

#### JSON rule usage

```json
[
  {
    "effective_date": "2027-01-01",
    "criteria": { "productCategory": 4953 },
    "priceAdjustment": {
      "type": "callback",
      "value": "MyNamespace\\Helpers\\PriceHelper::computeNewPrice"
    },
    "promotionalPriceAdjustment": {
      "type": "callback",
      "value": "MyNamespace\\Helpers\\PriceHelper::computeNewPromoPrice"
    }
  }
]
```

#### Example implementation

```php
namespace MyNamespace\Helpers;

use craft\commerce\elements\Variant;

class PriceHelper
{
    /**
     * Full adjustment callback — receives the current price and the Variant.
     * Return null to clear the price (same effect as "type": "reset").
     */
    public static function computeNewPrice(float $price, ?Variant $variant = null): ?float
    {
        // Example: raise by 8% then apply x.19 friendly rounding
        $raw = $price * 1.08;
        return round(floor($raw) + 0.19, 2);
    }

    public static function computeNewPromoPrice(float $price, ?Variant $variant = null): ?float
    {
        // Example: skip variants without a promotional price already set
        if ($variant && $variant->basePromotionalPrice === null) {
            return null;
        }
        // 15% off, rounded to .99
        return round(floor($price * 0.85) + 0.99, 2);
    }
}
```

> **Note:** The `value` must be a `'ClassName::method'` static-method string in JSON. Closures and array callables can only be used when `applyAdjustment()` is called programmatically.
> An unresolvable reference throws an `\InvalidArgumentException` immediately — there is no silent fallback.
>
> The `friendlyPriceStrategy` key on the same rule entry is **ignored** for `callback` type adjustments — the callback is expected to apply its own rounding.

### Friendly-Price Rounding

`percentage` adjustments pass their raw result through a **friendly-price rounding** step before storing the value. The strategy is resolved in this order:

1. `friendlyPriceStrategy` key on the **individual rule entry** (highest priority)
2. `friendlyPriceStrategy` plugin **setting** (project-wide default)
3. Hard-coded fallback: **`x.95`**

#### Available strategies

| Value   | Formula               | Example (raw 49.73) |
|---------|-----------------------|---------------------|
| `x.99`  | `floor(price) + 0.99` | 49.99               |
| `x.95`  | `floor(price) + 0.95` | 49.95 *(default)*   |
| `x.90`  | `floor(price) + 0.90` | 49.90               |
| `round` | `round(price, 0)`     | 50.00               |
| `ceil`  | `ceil(price)`         | 50.00               |
| `floor` | `floor(price)`        | 49.00               |
| `exact` | no rounding           | 49.73               |

#### Callback strategy

For custom rounding logic you can point to a **static method** or supply any **PHP callable**. The callable must have the signature `function(float $price, ?Variant $variant): float`. The `Variant` is passed through from `buildRows()` and is available for conditional rounding logic (e.g. different endings per product type).

**Static-method string** — works in both the JSON rule file and in the PHP config file:

```json
{
  "effective_date": "2027-01-01",
  "friendlyPriceStrategy": "mynamespace\\helpers\\PriceHelper::applyFriendlyPrice",
  "criteria": { "productCategory": 4953 },
  "priceAdjustment": { "type": "percentage", "value": 10 }
}
```

```php
// config/_priceadjuster.php
return [
    'friendlyPriceStrategy' => 'mynamespace\helpers\PriceHelper::applyFriendlyPrice',
];
```

```php
// mynamespace/helpers/PriceHelper.php
namespace mynamespace\helpers;

class PriceHelper
{
    public static function applyFriendlyPrice(float $price): float
    {
        // Example: always end in .98
        return round(floor($price) + 0.98, 2);
    }
}
```

**Closure** — only available in the PHP config file (closures cannot be expressed in JSON):

```php
// config/_priceadjuster.php
return [
    'friendlyPriceStrategy' => function(float $price): float {
        return round(floor($price) + 0.98, 2);
    },
];
```

> **Note:** A static-method string that cannot be resolved (class or method does not exist) throws an `\InvalidArgumentException` so the misconfiguration is immediately visible rather than silently falling back.

#### Per-rule override in JSON

Add `friendlyPriceStrategy` to any rule entry to override the project default for that entry only:

```json
[
  {
    "effective_date": "2027-01-01",
    "friendlyPriceStrategy": "x.99",
    "criteria": { "productCategory": 4953 },
    "priceAdjustment": { "type": "percentage", "value": 10 }
  },
  {
    "effective_date": "2027-01-01",
    "friendlyPriceStrategy": "round",
    "criteria": { "productCategory": 4816 },
    "promotionalPriceAdjustment": { "type": "percentage", "value": -10 }
  }
]
```

The strategy applies to **both** `priceAdjustment` and `promotionalPriceAdjustment` within the same rule entry. `amount` and `reset` types are never affected.

#### Project-wide default via config file

```php
// config/_priceadjuster.php
return [
    'friendlyPriceStrategy' => 'x.99',
    // …other settings…
];
```

### Examples
**Raise by 10% all products in a category:**
```json
[
  {
    "effective_date": "2027-01-01",
    "criteria": { "productCategory": 4953 },
    "priceAdjustment": { "type": "percentage", "value": 10 }
  }
]
```
**Add a flat €5 surcharge to products in another category:**
```json
[
  {
    "effective_date": "2027-01-01",
    "criteria": { "productCategory": 4816 },
    "priceAdjustment": { "type": "amount", "value": 5 }
  }
]
```
**Set a promotional price (−10%) for specific variants by color, without touching the base price:**
```json
[
  {
    "effective_date": "2027-01-01",
    "criteria": { "id": [19827, 21153] },
    "variantCriteria": { "color": 19846 },
    "promotionalPriceAdjustment": { "type": "percentage", "value": -10 }
  }
]
```
**Combined: set a promotional price and reset it later:**
```json
[
  {
    "effective_date": "2027-01-01",
    "criteria": {
      "productCategory": "productCategory:mini-dresses,evening-dresses"
    },
    "promotionalPriceAdjustment": {
      "type": "percentage",
      "value": -10
    }
  },
  {
    "effective_date": "2027-02-01",
    "criteria": {
      "productCategory": "productCategory:mini-dresses,evening-dresses"
    },
    "promotionalPriceAdjustment": {
      "type": "reset"
    }
  }
]
```
> **Note:** Multiple entries in one file can target the same `effective_date`. If the same variant appears in more than one entry for the same date, the **last entry wins**.

## CLI Commands
All commands use the plugin handle prefix `_priceadjuster`.

### `scheduler/preview` — Dry-run a rule
Prints all planned price changes without writing anything to the database.
```bash
craft _priceadjuster/scheduler/preview --rule=<name>
```
**Output columns:** `effective_date | SKU | title | old_price -> new_price [ | promo: old -> new ]`

### `scheduler/stage` — Stage planned changes
Calculates prices from the rule file and writes them to the schedule table. **Existing staged rows for the same variant + date are updated** if the price has changed, or skipped if unchanged. Already-applied rows are never touched.

```bash
craft _priceadjuster/scheduler/stage --rule=<name>
```

Re-run safely after editing the rule file — only changed rows are updated.

Add a --replace option to clear all existing staged rows for the same rule before staging new ones (useful if you want to completely replace an existing schedule):

```bash
craft _priceadjuster/scheduler/stage --rule=<name> --replace
```


### `scheduler/apply` — Apply staged prices
Reads staged rows from the database and saves the new prices to Commerce variants. Marks each row as applied with a timestamp. After applying, all affected products are re-saved to refresh caches.
```bash
# Apply all staged rows for a specific date
craft _priceadjuster/scheduler/apply --date=2027-01-01
# Apply all staged rows for a specific rule (regardless of date)
craft _priceadjuster/scheduler/apply --rule=<name>
```
**Options:**
| Option | Description |
|--------|-------------|
| `--date=YYYY-MM-DD` | Filter by effective date |
| `--rule=<name>` | Filter by rule name |
| `--reset-promotion` | Clear `basePromotionalPrice` on all applied variants |
At least one of `--date` or `--rule` is required.

### `scheduler/rollback` — Roll back applied prices
Restores original prices for all rows that have been applied for the given date or rule. Clears the `appliedAt` timestamp so rows can be re-applied if needed.
```bash
craft _priceadjuster/scheduler/rollback --date=2027-01-01
craft _priceadjuster/scheduler/rollback --rule=<name>
```
At least one of `--date` or `--rule` is required.

### `scheduler/delete` — Delete staged rows
Removes schedule rows from the database. If `--date` is omitted, **all rows are deleted** (with a confirmation prompt).
```bash
craft _priceadjuster/scheduler/delete --date=2027-01-01
```

### `export/index` — Export schedule to CSV
Exports all staged rows for a given date to `<exportDirectory>/price-schedule-<date>.csv`. Columns: `SKU`, `Title`, `Rule`, `Effective Date`, `Applied At`, `Old Price`, `New Price`, `Old Promo Price`, `New Promo Price`. Delimiter: `;`.
```bash
craft _priceadjuster/export/index --date=2027-01-01
```

### `import/index` — Import prices from CSV
Reads a CSV from `<importDirectory>/price-schedule-<date>.csv` and updates the staged `newPrice` / `newPromotionalPrice` for any rows where the value differs. Place a hand-edited copy of the exported file in `importDirectory` before running.
```bash
craft _priceadjuster/import/index --date=2027-01-01
# Dry-run (print changes without saving)
craft _priceadjuster/import/index --date=2027-01-01 --dry-run=1
```
**Expected CSV columns:** `SKU`, `New Price`, `New Promo Price` (others are ignored on import).

## Extension Hook — `buildRows` Event
`SchedulerController::buildRows()` exposes an event hook so projects can adjust computed schedule rows before preview/stage uses them.

- Event name: `SchedulerController::EVENT_BUILD_ROWS`
- Event class: `wsydney76\priceadjuster\events\BuildRowsEvent`
- Triggered only when listeners are attached.

Event payload:
- `$event->rules` — loaded JSON rules array
- `$event->rows` — computed row array (mutable)

Each row contains:
`variantId`, `title`, `sku`, `oldPrice`, `newPrice`, `oldPromotionalPrice`, `newPromotionalPrice`, `effectiveDate`, `ruleLabel`.

Example listener (registered in your project bootstrap/module):

```php
<?php

use wsydney76\priceadjuster\console\controllers\SchedulerController;
use wsydney76\priceadjuster\events\BuildRowsEvent;
use yii\base\Event;

Event::on(
    SchedulerController::class,
    SchedulerController::EVENT_BUILD_ROWS,
    static function (BuildRowsEvent $event): void {
        // Example: remove rows where computed price did not actually change.
        $event->rows = array_values(array_filter(
            $event->rows,
            static fn(array $row): bool => (float)$row['oldPrice'] !== (float)$row['newPrice']
        ));
    }
);
```

Because rows are mutable, listeners can filter, re-order, or overwrite values before `buildRows()` returns.

## CP Utility — Price Schedule
Navigate to **Utilities → Price Schedule** in the Craft Control Panel.
### Rule Index
The landing page lists all rule names that have staged rows in the database, with counts of total / pending / applied rows. Click a rule name to drill into its detail view.
### Rule Detail View
Shows all staged rows for the selected rule, grouped by effective date.
**Pending rows** (not yet applied) are shown in an editable table:
- **New Price** — edit the planned base price directly in the table
- **New Promo Price** — edit the planned promotional price (leave empty to clear it)
- **Select checkbox** — mark rows for deletion
- **Save `<date>`** — batch-saves edited prices via AJAX (no page reload)
- **Delete selected** — removes the checked rows from the schedule
**Applied rows** are shown collapsed in a `<details>` block with a read-only summary.
Clicking a product title opens the product's CP edit page in a new tab (on the Variants tab), allowing quick cross-referencing.

## Typical Workflow
```bash
# 1. Author or update <rulesDirectory>/2027-01-01.json
# 2. Check what will change
craft _priceadjuster/scheduler/preview --rule=2027-01-01
# 3. Stage to DB
craft _priceadjuster/scheduler/stage --rule=2027-01-01
# 4. Export for review / spreadsheet editing (written to exportDirectory)
craft _priceadjuster/export/index --date=2027-01-01
# 5. Copy edited file to importDirectory, then import changes
craft _priceadjuster/import/index --date=2027-01-01 --dry-run=1
craft _priceadjuster/import/index --date=2027-01-01
# 6. Final review in CP: Utilities → Price Schedule
# 7. Apply on the effective date
craft _priceadjuster/scheduler/apply --date=2027-01-01
# 8. Roll back if something went wrong
craft _priceadjuster/scheduler/rollback --date=2027-01-01
```
