# Price Adjuster — Architecture

## Overview

**Price Adjuster** (`wsydney76/craft-priceadjuster`, handle `_priceadjuster`) is a Craft CMS 5 plugin for Craft Commerce that enables mass, rule-driven price scheduling. Prices are not changed immediately; instead they are _staged_ in a database table and _applied_ on demand (manually or via cron). The same staged records can be exported to CSV, manually adjusted, re-imported, and then applied.

**Requirements:** PHP ≥ 8.4, Craft CMS ^5.9, Craft Commerce 5, `nystudio107/craft-code-editor` ^1.0.29.

---

## Directory Map

```
src/
├── PriceadjusterPlugin.php          # Plugin bootstrap, component wiring, event handlers
├── models/
│   └── Settings.php                 # Plugin settings model
├── records/
│   └── PriceSchedule.php            # ActiveRecord for {{%_priceadjuster_records}}
├── migrations/
│   └── Install.php                  # Creates / drops the schedule table
├── services/
│   └── SchedulerService.php         # All business logic (the only service)
├── events/
│   ├── BuildRowsEvent.php           # Fired after buildRows(); rows are mutable
│   └── SchedulerResultEvent.php     # Fired per result item inside operational methods
├── console/controllers/
│   ├── SchedulerController.php      # CLI: preview / stage / apply / rollback / delete
│   ├── ExportController.php         # CLI: export staged records to CSV
│   └── ImportController.php         # CLI: import CSV and patch staged records
├── controllers/
│   ├── PriceScheduleController.php  # CP AJAX: batch-update, delete, stage, dry-run, date-update
│   └── RuleFileController.php       # CP form: save / duplicate / delete JSON rule files
├── utilities/
│   ├── PriceScheduleUtility.php     # Registers "Price Schedule" CP utility
│   └── RuleFileUtility.php          # Registers "Price Rule Files" CP utility
├── assetbundles/
│   └── PriceScheduleAsset.php       # Registers CSS + JS for the CP utilities
├── templates/
│   ├── settings.twig
│   └── utilities/
│       ├── price-schedule.twig
│       ├── rule-file.twig
│       └── _includes/               # Partial templates
└── web/
    ├── css/price-schedule.css
    └── js/price-schedule.js         # Handles AJAX calls to CP controller endpoints
```

---

## Plugin Bootstrap — `PriceadjusterPlugin`

**Namespace:** `wsydney76\priceadjuster\PriceadjusterPlugin`  
**Extends:** `craft\base\Plugin`

### Responsibilities
- Registers `SchedulerService` as the lazily-resolved component `$plugin->scheduler`.
- Switches the controller namespace to `console\controllers` for CLI requests.
- Registers the two CP utilities via `Utilities::EVENT_REGISTER_UTILITIES`.
- Exposes four directory-resolver helpers that parse Craft aliases and `.env` variables.

### Directory Helpers

| Method | Setting key | Default |
|--------|-------------|---------|
| `getRulesDirectory()` | `rulesDirectory` | `@root/config/priceadjuster/rules` |
| `getExportDirectory()` | `exportDirectory` | `@root/config/priceadjuster/exports` |
| `getImportDirectory()` | `importDirectory` | `@root/config/priceadjuster/imports` |
| `getLogDirectory()` | `logDirectory` | `@root/config/priceadjuster/logs` |

All four support environment-variable placeholders (e.g. `$MY_DIR`) via `craft\helpers\App::parseEnv()` and Craft aliases via `Craft::getAlias()`.

---

## Settings Model — `Settings`

**Namespace:** `wsydney76\priceadjuster\models\Settings`  
**Extends:** `craft\base\Model`

| Property | Type | Default | Purpose |
|----------|------|---------|---------|
| `rulesDirectory` | `string` | `@root/config/priceadjuster/rules` | Where rule JSON files live |
| `exportDirectory` | `string` | `@root/config/priceadjuster/exports` | CSV export destination |
| `importDirectory` | `string` | `@root/config/priceadjuster/imports` | CSV import source |
| `logDirectory` | `string` | `@root/config/priceadjuster/logs` | Operation log files |
| `resaveProducts` | `bool` | `true` | Re-save owning Products after apply/rollback |
| `friendlyPriceStrategy` | `mixed` | `null` (→ `x.95`) | Project-wide rounding default |

All four directory settings use `EnvAttributeParserBehavior` so values ending in an env-var token are automatically resolved on read.

---

## Data Layer

### Table: `{{%_priceadjuster_records}}`

Created/dropped by `src/migrations/Install.php`.

| Column | Type | Required | Notes |
|--------|------|----------|-------|
| `id` | PK | yes | Auto-increment |
| `effectiveDate` | DATE | yes | The scheduled price-change date |
| `variantId` | INT | yes | Commerce `Variant` element ID |
| `title` | VARCHAR | yes | "Product title - Variant title" snapshot |
| `sku` | VARCHAR | no | Variant SKU snapshot |
| `oldPrice` | DECIMAL(14,4) | yes | Price at staging time |
| `newPrice` | DECIMAL(14,4) | yes | Computed target price |
| `oldPromotionalPrice` | DECIMAL(14,4) | no | Promotional price at staging time |
| `newPromotionalPrice` | DECIMAL(14,4) | no | Computed target promotional price |
| `ruleName` | VARCHAR | yes | Bare rule filename (without `.json`) |
| `ruleIndex` | INT | yes | Zero-based index of the rule entry in the file |
| `ruleLabel` | VARCHAR | yes | Human-readable label (auto-generated or from JSON `label` key) |
| `ruleSnapshot` | JSON | no | Full rule entry copied at staging time |
| `updateHistory` | JSON | no | Array of edit history entries (see below) |
| `appliedAt` | DATETIME | no | `null` = pending; non-null = applied |
| `dateCreated` | DATETIME | yes | Craft standard |
| `dateUpdated` | DATETIME | yes | Craft standard |
| `uid` | CHAR(36) | yes | Craft standard |

**Indexes:** `(variantId, effectiveDate)` and `(effectiveDate, appliedAt)`.

#### Record lifecycle states

```
[staged]  appliedAt = null   → record is pending
[applied] appliedAt = <ts>   → prices have been written to Commerce
```
A rollback clears `appliedAt` back to `null`, returning the record to the staged state.

### Record — `PriceSchedule`

**Namespace:** `wsydney76\priceadjuster\records\PriceSchedule`  
**Extends:** `craft\db\ActiveRecord`

#### Validation rules (enforced on every `save()`)
- `variantId`, `oldPrice`, `newPrice`, `effectiveDate`, `ruleName` are required.
- `oldPrice` and `newPrice` must be `≥ 0.01`.
- `newPromotionalPrice`, when set, must be `≥ 0.01` **and** `< newPrice`.
- `effectiveDate` must match `Y-m-d` format.

#### `beforeSave($insert)`
On every save (insert **or** update), a new entry is appended to the JSON `updateHistory` column:
```json
{
  "userId": 42,
  "timestamp": "2027-01-01 09:00:00",
  "newPrice": "99.95",
  "newPromotionalPrice": null
}
```
The first entry reflects the originally staged prices; each subsequent entry represents a manual edit.

#### Key methods

| Method | Returns | Notes |
|--------|---------|-------|
| `getMessageString()` | `string` | `"ruleName #idx \| date \| title \| sku \| old -> new \| promo: old -> new"` |
| `getUpdateHistoryDecoded()` | `array[]` | Safely decodes `updateHistory` JSON column |

---

## Core Service — `SchedulerService`

**Namespace:** `wsydney76\priceadjuster\services\SchedulerService`  
**Extends:** `yii\base\Component`  
**Access:** `PriceadjusterPlugin::getInstance()->scheduler`

This is the only service and holds all business logic. Console and web controllers are thin wrappers that call into it.

### Events

| Constant | When fired | Event class |
|----------|-----------|-------------|
| `EVENT_BUILD_ROWS` | After `buildRows()` computes rows, before returning | `BuildRowsEvent` |
| `EVENT_RESULT` | Once per result item inside `stageRows()`, `applyRecords()`, `rollbackRecords()`, `deleteRecords()` | `SchedulerResultEvent` |

`EVENT_BUILD_ROWS` has a mutable `$rows` array — listeners may add, remove, or modify schedule rows before staging.

`EVENT_RESULT` allows real-time streaming; console controllers register a listener before each operation and remove it afterward.

### Public API

#### `loadRules(string $rule): array`
Loads and strictly validates `<rulesDirectory>/<rule>.json`.

Validation checks:
1. File must exist.
2. File must decode to a non-empty JSON array.
3. Every entry must have `effective_date` matching `YYYY-MM-DD`.
4. The date must be a valid calendar date.
5. The date must not be in the past.

After validation, `resolveSlugReferences()` is called to expand `"section:slug1,slug2"` shorthand into arrays of Entry IDs.

Throws `\RuntimeException` on any failure.

---

#### `buildRows(string $rule): array`
Builds an array of **unsaved** `PriceSchedule` record objects.

**Algorithm:**
1. Call `loadRules($rule)`.
2. Iterate each rule entry in order.
3. For `action: "ignore"` entries: remove any already-accumulated row for the matching `variantId:effectiveDate` key.
4. For all other entries:
   - Resolve matching Commerce `Product` elements via `criteria` (product-level query criteria).
   - Then resolve their `Variant` elements via `variantCriteria`.
   - For each variant, compute `newPrice` using `applyAdjustment()`.
   - Skip variants where either `oldPrice` or `newPrice` equals zero.
   - Compute optional `newPromotionalPrice`.
   - Build a `PriceSchedule` record and store it in a `variantId:effectiveDate` keyed map (last entry wins = deduplication).
5. Convert the map to an array, sort by `effectiveDate + title`.
6. Fire `EVENT_BUILD_ROWS` if there are listeners; return `$event->rows`.

**Returns:** `PriceSchedule[]` (unsaved, ready for `stageRows()`).

---

#### `stageRows(array $rows, string $rule, bool $replace = false, ?string $date = null): array`
Persists computed rows into the database.

- If `$replace = true`: deletes all existing pending (unapplied) records for the given `$rule` (optionally scoped to `$date`) first.
- For each incoming row:
  - If a pending record already exists for the same `variantId + effectiveDate`:
    - If price/promo unchanged → emit `skipped`.
    - If changed → update fields, emit `updated`.
  - Otherwise → insert as new, emit `staged`.
- On save failure → emit `error`.

**Returns:** `array[]` — each item has `status`, `record`, `message` (and optionally `errors`).

---

#### `applyRecords(array $records, bool $resetPromotion = false, ?string $ruleName = null, bool $dryRun = false): array`
Writes staged prices to Commerce Variant elements.

- If `$dryRun = true`: validates records and emits results without writing.
- For each record:
  - Loads the Variant by `variantId` (status-agnostic query).
  - Skips if `newPrice === 0.0`.
  - Sets `variant->basePrice` and `variant->basePromotionalPrice`.
  - Saves the element via `Craft::$app->elements->saveElement()`.
  - Stamps `appliedAt` on the schedule record and saves it.
- After all records: optionally re-saves owning Products if `resaveProducts` is enabled.
- Writes a log file to `<logDirectory>/<ruleName>-apply-<timestamp>.log`.

**Statuses emitted:** `applied`, `skipped`, `error`, `resaved`.

---

#### `rollbackRecords(array $records, ?string $ruleName = null, bool $dryRun = false): array`
Restores the `oldPrice` / `oldPromotionalPrice` from staged records back to Commerce Variants.

- Mirror of `applyRecords()`.
- On success, clears `appliedAt` on the schedule record.
- Writes a `<ruleName>-rollback-<timestamp>.log` file.

**Statuses emitted:** `rolledBack`, `skipped`, `error`, `resaved`.

---

#### `getScheduleRecords(?bool $applied, ?string $date, ?string $rule, bool $requireFilter = true): ?array`
Flexible record fetcher used by apply, rollback, delete, and export.

| `$applied` | Behaviour |
|-----------|-----------|
| `true` | Only applied records |
| `false` | Only pending (unapplied) records |
| `null` | Both |

Returns `null` (not an empty array) when `$requireFilter = true` and neither `$date` nor `$rule` is given — callers use this as a signal to print a usage error.

---

#### `batchUpdateRecords(array $updates): array`
Updates `newPrice` and optionally `newPromotionalPrice` on existing staged records from a raw POST array.

- Skips unchanged records silently.
- **Returns:** `['saved' => int, 'errors' => string[]]`.

---

#### `deleteRecordsById(array $ids): array`
Deletes records by PK. **Returns:** `['deleted' => int, 'errors' => string[]]`.

---

#### `deleteRecords(array $records): array`
Deletes an array of `PriceSchedule` records, emitting `EVENT_RESULT` per item.
**Returns:** `array[]` result set.

---

#### `deleteRecordsByRule(string $rule): array`
Deletes all schedule records (any state) for the given rule name.
**Returns:** `['deleted' => int, 'errors' => string[]]`.

---

#### `updateEffectiveDate(string $rule, string $oldDate, string $newDate): array`
Moves all pending records for `$rule + $oldDate` to a new effective date.
**Returns:** `['updated' => int, 'errors' => string[]]`.

---

### Price Helpers (public, reusable)

#### `applyAdjustment(float $price, array $adjustment, string|callable|null $strategy, ?Variant $variant): ?float`

| `adjustment['type']` | Behaviour |
|---------------------|----------|
| `percentage` | `price × (1 + value/100)`, then apply friendly-price rounding |
| `amount` | `round(price + value, 2)` (may be negative offset) |
| `reset` | Returns `null` (clears the price) |
| `callback` | Delegates to `resolveCallable($adjustment['value'])($price, $variant)` |

---

#### `friendlyPrice(float $price, string|callable|null $strategy, ?Variant $variant): float`
Public proxy to `resolveFriendlyPrice()`. Strategy resolution order:
1. The `$strategy` argument (from `friendlyPriceStrategy` JSON key).
2. Plugin setting `friendlyPriceStrategy`.
3. Hard-coded fallback: `x.95`.

**Named strategies:**

| Strategy | Formula | Raw 49.73 → |
|----------|---------|------------|
| `x.99` | `floor(price) + 0.99` | 49.99 |
| `x.95` *(default)* | `floor(price) + 0.95` | 49.95 |
| `x.90` | `floor(price) + 0.90` | 49.90 |
| `round` | `round(price, 0)` | 50.00 |
| `ceil` | `ceil(price)` | 50.00 |
| `floor` | `floor(price)` | 49.00 |
| `exact` | no rounding | 49.73 |
| `ClassName::method` | static-method callable | custom |

---

#### `zeroAsNull(?float $value): ?float`
Normalises `0.0` → `null`. Used in promotional price paths to distinguish "no promo price" from a zero price.

---

### Private Helpers

| Method | Purpose |
|--------|---------|
| `resolveSlugReferences(array &$rules)` | Expands `"section:slug1,slug2"` in `criteria`/`variantCriteria` to `Entry::find()->ids()` arrays |
| `getVariantsForCriteria(array $criteria, array $variantCriteria)` | Executes a chained `Product::find()` + `Variant::find()` query |
| `resolveFriendlyPrice(float, string\|callable\|null, ?Variant)` | Strategy dispatch (see above) |
| `resolveCallable(string\|callable $ref)` | Resolves a `'Class::method'` string or any PHP callable; throws on failure |
| `buildRuleLabel(array, int, string)` | Auto-generates a human label if none is in the JSON |
| `addResult(array $result)` | Appends to `$currentResults` and fires `EVENT_RESULT` |
| `resaveProducts(array $productIds)` | Re-saves Commerce Product elements and emits `resaved` results |
| `writeLog(string $ruleName, string $action, array $results)` | Writes `[status] message` lines to `<logDir>/<rule>-<action>-<ts>.log` |

---

## Events

### `BuildRowsEvent`
**Class:** `wsydney76\priceadjuster\events\BuildRowsEvent`

| Property | Type | Description |
|----------|------|-------------|
| `ruleName` | `?string` | The rule name passed as argument |
| `rules` | `array` | The full parsed rule definitions |
| `rows` | `array` | **Mutable.** Computed `PriceSchedule[]` rows; may be edited by listeners |

### `SchedulerResultEvent`
**Class:** `wsydney76\priceadjuster\events\SchedulerResultEvent`

| Property | Type | Description |
|----------|------|-------------|
| `status` | `string` | `staged`, `updated`, `skipped`, `applied`, `rolledBack`, `deleted`, `resaved`, or `error` |
| `message` | `string` | Human-readable result string |
| `result` | `array` | Full result payload (includes `record` and optionally `errors`) |

---

## Rule File Format (JSON)

Rule files are JSON arrays. Each element is a _rule entry_:

```json
[
  {
    "effective_date": "2027-06-01",
    "label": "Summer Sale",
    "action": "ignore",
    "criteria": {
      "type": "clothing",
      "relatedTo": "section:summer-collection,beach-wear"
    },
    "variantCriteria": {
      "sku": "SHIRT-*"
    },
    "priceAdjustment": {
      "type": "percentage",
      "value": 10
    },
    "promotionalPriceAdjustment": {
      "type": "reset"
    },
    "friendlyPriceStrategy": "x.99"
  }
]
```

| Key | Required | Description |
|-----|----------|-------------|
| `effective_date` | **yes** | `YYYY-MM-DD`; must be today or future |
| `label` | no | Human label; auto-generated if absent |
| `action` | no | `"ignore"` removes already-accumulated rows for matched variants on the same date |
| `criteria` | no | Craft Commerce `Product::find()` chain; supports `section:slug1,slug2` shorthand |
| `variantCriteria` | no | `Variant::find()` chain applied within matched products |
| `priceAdjustment` | no | `{type, value}` — see adjustment types |
| `promotionalPriceAdjustment` | no | Same shape as `priceAdjustment` |
| `friendlyPriceStrategy` | no | Per-entry override of the rounding strategy |

Multiple entries in the same file create independent scheduling blocks. If two entries match the same `variantId + effectiveDate`, the **last one** wins (last-write-wins deduplication in `buildRows()`).

---

## Console Controllers

Controller namespace (CLI): `wsydney76\priceadjuster\console\controllers`

### `SchedulerController`
Command prefix: `_priceadjuster/scheduler/`

All actions stream results in real time by registering a temporary `EVENT_RESULT` listener before the service call and removing it immediately after.

| Action | Options | Description |
|--------|---------|-------------|
| `preview` | `--rule` | Build rows and print them; no DB write |
| `stage` | `--rule`, `--replace`, `--date` | Build rows and upsert into DB |
| `apply` | `--date`, `--rule`, `--reset-promotion`, `--dry-run` | Apply pending records to Commerce |
| `rollback` | `--date`, `--rule`, `--dry-run` | Restore prices from applied records |
| `delete` | `--date`, `--rule` | Delete records (confirms interactively; no filter = all) |

`--date=today` is an alias for the current date in all actions.

### `ExportController`
Command prefix: `_priceadjuster/export/`

| Action | Options | Description |
|--------|---------|-------------|
| `index` | `--date`, `--rule` | Export matching records to `<exportDir>/price-schedule-<suffix>.csv` |

CSV columns: `SKU; Title; Rule; Effective Date; Applied At; Old Price; New Price; Old Promo Price; New Promo Price` (semicolon delimiter).

### `ImportController`
Command prefix: `_priceadjuster/import/`

| Action | Options | Description |
|--------|---------|-------------|
| `index` | `--date`, `--rule`, `--dry-run` | Read CSV and patch `newPrice`/`newPromotionalPrice` on matching staged records by SKU |

Matches by SKU + effective date (from CSV column, falling back to `--date`) + optional rule name. Skips unchanged records silently. Validates before writing when `--dry-run`.

---

## CP (Web) Controllers

Controller namespace (web): `wsydney76\priceadjuster\controllers`

All actions require a CP request, a POST request, and the relevant utility permission.

### `PriceScheduleController`
Routes under `_priceadjuster/price-schedule/`

| Route | Permission | Description |
|-------|-----------|-------------|
| `batch-update` | `utility:price-schedule` | Update `newPrice`/`newPromotionalPrice` for staged records; POST `updates[]` |
| `delete-selected` | `utility:price-schedule` | Delete records by ID array; POST `ids[]` |
| `delete-by-rule` | `utility:price-schedule` | Delete all records for a rule; POST `rule` |
| `stage-by-rule` | `utility:price-schedule` | Build + stage rows for a rule from the CP; POST `rule`, `replace` |
| `update-effective-date` | `utility:price-schedule` | Move pending records to a new date; POST `rule`, `oldDate`, `newDate` |
| `dry-run-apply` | `utility:price-schedule` | Simulate apply and return per-record results; POST `rule`, `date` |

All return Craft's standard JSON `asSuccess()` / `asFailure()` responses.

### `RuleFileController`
Routes under `_priceadjuster/rule-file/`

| Route | Permission | Description |
|-------|-----------|-------------|
| `save` | `utility:price-rule-files` | Create or overwrite a JSON rule file from editable-table POST data |
| `duplicate` | `utility:price-rule-files` | Copy a rule file under a new name |
| `delete` | `utility:price-rule-files` | Delete a rule file from disk |

- File names are validated against `/^[a-zA-Z0-9_\-.]+$/`.
- `actionSave()` builds the JSON structure from the editable-table form fields (`effective_date`, `label`, `action`, `criteria` (JSON string), `variantCriteria` (JSON string), `priceType`, `priceValue`, `promoType`, `promoValue`, `friendlyPriceStrategy`) and writes atomically via a `.tmp` file + `rename()`.
- Redirects back to the Rule Files utility on success/failure.

---

## CP Utilities

Both utilities are registered in `PriceadjusterPlugin::attachEventHandlers()` via `Utilities::EVENT_REGISTER_UTILITIES`.

### `PriceScheduleUtility` (id: `price-schedule`)
**Template:** `_priceadjuster/utilities/price-schedule.twig`

Two display modes driven by the `rule` query param:

| Mode | `?rule=` | What is shown |
|------|---------|---------------|
| List view | absent | Summary table: rule names, total/pending/applied counts, date sub-links |
| Detail view | `<ruleName>` | All records for that rule, grouped by `effectiveDate`, optionally filtered by `?date=`. Includes product edit URL links and inline price editing. |

Registers `PriceScheduleAsset` (CSS + JS).

### `RuleFileUtility` (id: `price-rule-files`)
**Template:** `_priceadjuster/utilities/rule-file.twig`

Two display modes driven by the `file` query param:

| Mode | `?file=` | What is shown |
|------|---------|---------------|
| List view | absent | Table of all `.json` files in `rulesDirectory` with entry counts, DB record counts, modification time |
| Edit/create view | `<fileName>` or `new` | Editable-table form backed by Craft's `editableTableField` |

---

## Front-End Assets

**Bundle:** `PriceScheduleAsset`  
**Source path:** `@wsydney76/priceadjuster/web`  
**Depends on:** `craft\web\assets\cp\CpAsset`

Files:
- `css/price-schedule.css` — CP utility styling.
- `js/price-schedule.js` — AJAX calls to the CP controller endpoints. Must be kept in sync with the action routes listed above.

---

## Typical Developer Workflow

```
1. Author / edit a rule file
   CP: Price Rule Files utility  OR  edit JSON directly on disk

2. Preview computed rows (no DB write)
   CLI: php craft _priceadjuster/scheduler/preview --rule=<name>

3. Stage rows into DB
   CLI: php craft _priceadjuster/scheduler/stage --rule=<name> [--replace]
   CP:  "Stage" button in Price Rule Files utility

4. [Optional] Export to CSV for manual price review
   CLI: php craft _priceadjuster/export/index --rule=<name>

5. [Optional] Edit prices in the CSV, then import
   CLI: php craft _priceadjuster/import/index --rule=<name> [--dry-run]
   CP:  Inline price editing in Price Schedule utility

6. Dry-run apply to verify
   CLI: php craft _priceadjuster/scheduler/apply --rule=<name> --date=<date> --dry-run
   CP:  "Dry Run Apply" button in Price Schedule utility

7. Apply prices
   CLI: php craft _priceadjuster/scheduler/apply --date=<date> [--rule=<name>]

8. [If needed] Rollback
   CLI: php craft _priceadjuster/scheduler/rollback --date=<date> [--rule=<name>]
```

---

## Extension Points for Third-Party Code

### Intercept computed rows before staging

```php
use wsydney76\priceadjuster\events\BuildRowsEvent;
use wsydney76\priceadjuster\services\SchedulerService;
use wsydney76\priceadjuster\PriceadjusterPlugin;

Event::on(
    SchedulerService::class,
    SchedulerService::EVENT_BUILD_ROWS,
    function (BuildRowsEvent $event) {
        // Filter or append to $event->rows before they are staged
        $event->rows = array_filter($event->rows, fn($row) => $row->newPrice < 500);
    }
);
```

### Subscribe to real-time operation results

```php
PriceadjusterPlugin::getInstance()->scheduler->on(
    SchedulerService::EVENT_RESULT,
    function (SchedulerResultEvent $event) {
        // Log or stream each individual result
        Craft::info("[{$event->status}] {$event->message}", 'my-plugin');
    }
);
```

### Custom friendly-price strategy

In `config/_priceadjuster.php`:

```php
return [
    'friendlyPriceStrategy' => function (float $price): float {
        // e.g. always round to nearest 5
        return round($price / 5) * 5;
    },
];
```

Or reference a static method by string (works in JSON rules too):

```php
'friendlyPriceStrategy' => 'MyPlugin\Helpers\PriceHelper::roundToFive',
```

### Custom callback adjustment in a rule

```json
{
  "effective_date": "2027-06-01",
  "priceAdjustment": {
    "type": "callback",
    "value": "MyPlugin\\Helpers\\PriceHelper::computePrice"
  }
}
```

The callback receives `(float $price, ?Variant $variant)` and must return `?float`.

---

## Data Flow Diagram

```
Rule JSON file
      │
      ▼
loadRules()          ← validates effective_date, resolves slug references
      │
      ▼
buildRows()          ← queries Products + Variants, applies adjustments, deduplicates
      │
      ├─ EVENT_BUILD_ROWS  ← listeners may modify the row set
      ▼
PriceSchedule[]      ← unsaved ActiveRecord objects
      │
      ▼
stageRows()          ← upserts into {{%_priceadjuster_records}}
      │              ← emits EVENT_RESULT (staged|updated|skipped|error) per row
      ▼
{{%_priceadjuster_records}} (appliedAt = null)
      │
      ├──── export/index   → CSV file
      │         │
      │    import/index   ← edits newPrice/newPromotionalPrice in DB
      │
      ▼
applyRecords()       ← writes basePrice/basePromotionalPrice to Variant elements
      │              ← stamps appliedAt, optionally re-saves Products
      │              ← emits EVENT_RESULT (applied|skipped|error|resaved)
      │              ← writes log file
      ▼
{{%_priceadjuster_records}} (appliedAt = <timestamp>)
      │
      ▼ (if needed)
rollbackRecords()    ← restores oldPrice, clears appliedAt
                     ← emits EVENT_RESULT, writes log file
```

