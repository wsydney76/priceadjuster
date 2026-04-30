# Price Adjuster — Craft Commerce Plugin
A Craft CMS 5 plugin for **batch-scheduling future price changes** for Craft Commerce variants. Define price rules in JSON config files, stage them into the database, review and edit them in the Control Panel, then apply (or roll back) when the effective date arrives.

__In development - not even alpha__

## Requirements
- Craft CMS 5.x
- Craft Commerce 5.x
- PHP 8.4+

## Installation
The plugin is loaded from `/var/www/plugins/priceadjuster` (mounted via ddev) and symlinked into `vendor/` for autoloading. Install the DB table by running the install migration:
```bash
ddev exec php craft migrate/up --interactive=0
```

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
### Adjustment Object
```json
{ "type": "percentage", "value": 10 }
{ "type": "amount",     "value": 5  }
```
- **`percentage`** — multiplies the current price by `(1 + value/100)` and applies "friendly rounding" (`floor(result) + 0.95`)
- **`amount`** — adds `value` to the current price (can be negative), rounded to 2 decimal places
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
**Combined: raise all prices and also set a promotional price:**
```json
[
  {
    "effective_date": "2027-06-01",
    "criteria": { "productCategory": 4953 },
    "priceAdjustment": { "type": "percentage", "value": 5 },
    "promotionalPriceAdjustment": { "type": "percentage", "value": -15 }
  }
]
```
> **Note:** Multiple entries in one file can target the same `effective_date`. If the same variant appears in more than one entry for the same date, the **last entry wins**.

## CLI Commands
All commands use the plugin handle prefix `_priceadjuster`. Always append `--interactive=0` in ddev.
### `scheduler/preview` — Dry-run a rule
Prints all planned price changes without writing anything to the database.
```bash
ddev exec php craft _priceadjuster/scheduler/preview --rule=<name> --interactive=0
```
**Output columns:** `effective_date | SKU | title | old_price -> new_price [ | promo: old -> new ]`

### `scheduler/stage` — Stage planned changes
Calculates prices from the rule file and writes them to the schedule table. **Existing staged rows for the same variant + date are updated** if the price has changed, or skipped if unchanged. Already-applied rows are never touched.
```bash
ddev exec php craft _priceadjuster/scheduler/stage --rule=<name> --interactive=0
```
Re-run safely after editing the rule file — only changed rows are updated.

### `scheduler/apply` — Apply staged prices
Reads staged rows from the database and saves the new prices to Commerce variants. Marks each row as applied with a timestamp. After applying, all affected products are re-saved to refresh caches.
```bash
# Apply all staged rows for a specific date
ddev exec php craft _priceadjuster/scheduler/apply --date=2027-01-01 --interactive=0
# Apply all staged rows for a specific rule (regardless of date)
ddev exec php craft _priceadjuster/scheduler/apply --rule=<name> --interactive=0
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
ddev exec php craft _priceadjuster/scheduler/rollback --date=2027-01-01 --interactive=0
ddev exec php craft _priceadjuster/scheduler/rollback --rule=<name> --interactive=0
```
At least one of `--date` or `--rule` is required.

### `scheduler/delete` — Delete staged rows
Removes schedule rows from the database. If `--date` is omitted, **all rows are deleted** (with a confirmation prompt).
```bash
ddev exec php craft _priceadjuster/scheduler/delete --date=2027-01-01 --interactive=0
```

### `export/index` — Export schedule to CSV
Exports all staged rows for a given date to `<exportDirectory>/price-schedule-<date>.csv`. Columns: `SKU`, `Title`, `Rule`, `Effective Date`, `Applied At`, `Old Price`, `New Price`, `Old Promo Price`, `New Promo Price`. Delimiter: `;`.
```bash
ddev exec php craft _priceadjuster/export/index --date=2027-01-01 --interactive=0
```

### `import/index` — Import prices from CSV
Reads a CSV from `<importDirectory>/price-schedule-<date>.csv` and updates the staged `newPrice` / `newPromotionalPrice` for any rows where the value differs. Place a hand-edited copy of the exported file in `importDirectory` before running.
```bash
ddev exec php craft _priceadjuster/import/index --date=2027-01-01 --interactive=0
# Dry-run (print changes without saving)
ddev exec php craft _priceadjuster/import/index --date=2027-01-01 --dry-run=1 --interactive=0
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
ddev exec php craft _priceadjuster/scheduler/preview --rule=2027-01-01 --interactive=0
# 3. Stage to DB
ddev exec php craft _priceadjuster/scheduler/stage --rule=2027-01-01 --interactive=0
# 4. Export for review / spreadsheet editing (written to exportDirectory)
ddev exec php craft _priceadjuster/export/index --date=2027-01-01 --interactive=0
# 5. Copy edited file to importDirectory, then import changes
ddev exec php craft _priceadjuster/import/index --date=2027-01-01 --dry-run=1 --interactive=0
ddev exec php craft _priceadjuster/import/index --date=2027-01-01 --interactive=0
# 6. Final review in CP: Utilities → Price Schedule
# 7. Apply on the effective date
ddev exec php craft _priceadjuster/scheduler/apply --date=2027-01-01 --interactive=0
# 8. Roll back if something went wrong
ddev exec php craft _priceadjuster/scheduler/rollback --date=2027-01-01 --interactive=0
```
