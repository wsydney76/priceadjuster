# AGENTS.md

## Big Picture
- This is a Craft CMS 5 plugin (`composer.json`) with handle `_priceadjuster`; entrypoint is `src/PriceadjusterPlugin.php`.
- Keep business logic in `src/services/SchedulerService.php`; CLI and CP controllers are thin wrappers around this service.
- Data is persisted in `src/records/PriceSchedule.php` (`{{%_priceadjuster_records}}`), created by `src/migrations/Install.php`.

## Core Flow (Rule -> Stage -> Apply)
- Rule files live at `<rulesDirectory>/<rule>.json`; they are loaded/validated by `SchedulerService::loadRules()`.
- `buildRows()` computes unsaved schedule rows and deduplicates by `variantId:effectiveDate` (last rule entry wins).
- `stageRows()` upserts only pending rows (`appliedAt = null`), with optional replace mode.
- `applyRecords()` writes `Variant` prices, stamps `appliedAt`, and optionally re-saves owning `Product`s.
- `rollbackRecords()` restores original prices and clears `appliedAt`.

## Conventions Specific to This Codebase
- Service methods return structured result arrays (`status`, `record`, `message`) and emit `SchedulerService::EVENT_RESULT` per item.
- `loadRules()` is strict: `effective_date` is required, must be `YYYY-MM-DD`, valid, and not in the past.
- Price behavior details:
  - `zeroAsNull()` normalizes zero to `null` in promo-related paths.
  - `callback` adjustments and friendly rounding accept callables (`Class::method` supported via resolver).
  - Criteria shorthand like `section:slug1,slug2` is resolved to Entry IDs in `resolveSlugReferences()`.

## Integration Points
- Extension hook before staging/preview output: `SchedulerService::EVENT_BUILD_ROWS` (`src/events/BuildRowsEvent.php`) with mutable `$rows`.
- Real-time operation stream: `SchedulerService::EVENT_RESULT` (`src/events/SchedulerResultEvent.php`).
- CP utility stack:
  - server: `src/utilities/PriceScheduleUtility.php`, `src/controllers/PriceScheduleController.php`
  - UI: `src/templates/utilities/*`, `src/web/js/price-schedule.js`
- JS action endpoints to keep in sync with controller routes:
  - `_priceadjuster/price-schedule/batch-update`
  - `_priceadjuster/price-schedule/delete-selected`
  - `_priceadjuster/price-schedule/delete-by-rule`
  - `_priceadjuster/price-schedule/update-effective-date`

## Developer Workflow (See `README.md`)
- Typical sequence: `scheduler/preview` -> `scheduler/stage` -> `export/index` -> `import/index` -> `scheduler/apply`.
- `--dry-run=1` exists for apply/rollback/import and is expected before destructive writes.
- Operation logs are written to `<logDirectory>/<rule>-<action>-<timestamp>.log`.

## Safe-Change Checklist
- If schema/row shape changes, update migration + record + service mapping + CSV import/export + Twig + JS together.
- Preserve the "controllers call service" boundary to avoid CLI/CP behavior drift.
