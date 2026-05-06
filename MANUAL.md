# Price Adjuster — User Manual

This manual is for the people responsible for preparing and applying scheduled price changes in the Control Panel. No command-line knowledge is required for the day-to-day workflow.

---

## Overview

Price Adjuster lets you plan future price changes ahead of time, review and fine-tune them, and then push them live on the right date — all without touching a product one by one.

The process follows four steps:

```
Create / edit a rule file → Stage → Review & adjust → Apply
```

---

## Where to Find Everything

In the Craft CP, navigate to **Utilities** in the left sidebar. You will see two items:

| Utility | Purpose |
|---|---|
| **Price Rule Files** | Create and manage the rule files that describe which products to change and how |
| **Price Schedule** | Inspect staged price changes, fine-tune individual prices, and confirm what will go live |

---

## Step 1 — Create or Edit a Rule File

Go to **Utilities → Price Rule Files**.

The list shows every rule file that exists, with a quick summary of how many rules are inside it and how many database records it has already staged or applied.

### Create a new rule file

1. Click **+ Create New Rule File** (top of the page).
2. Enter a filename — for example `2027-spring-sale`. Use letters, numbers, hyphens and underscores. The `.json` extension is added for you.
3. Click **+ Add rule entry** to add the first rule.
4. Fill in the rule entry (see the field reference below).
5. Click **Save Rule File**.

### Edit an existing rule file

Click the filename in the list to open the editor. All existing rule entries are displayed as cards. Expand a card to edit it, reorder cards with the **▲ / ▼** buttons, or click **Remove** to delete a card.

Press **Ctrl+S** (or **Cmd+S** on Mac) or click **Save Rule File** at the bottom to save.

### Rule entry fields

Each card in the editor represents one pricing rule. The fields are:

| Field | Required | What to enter |
|---|---|---|
| **Effective Date** | ✓ | The date the price change should go live, in `YYYY-MM-DD` format (e.g. `2027-04-01`). Must be today or in the future. |
| **Label** | — | A short human-readable name shown in the schedule table and logs (e.g. `Spring Promo — Dresses`). |
| **Action** | — | Leave empty for a normal price rule. Select **Ignore** to exclude the matching products from an earlier rule on the same date. |
| **Criteria** | ✓ | A JSON filter that selects which products to target (see examples below). |
| **Variant Criteria** | — | An additional JSON filter to narrow down to specific variants within the matched products. |
| **Price Adjustment** | — | How to change the regular price. Select a type and enter a value. |
| **Promotional Price Adjustment** | — | Same controls, but for the promotional/sale price. |
| **Friendly Price Strategy** | — | How to round the result of a percentage increase. Leave empty to use the site default (usually `x.95`). |

#### Criteria — practical examples

The **Criteria** field accepts a JSON object. The most common patterns are:

```json
{ "productCategory": 4953 }
```
Target all products in a specific category (use the category's numeric ID).

```json
{ "productCategory": "productCategory:mini-dresses,evening-dresses" }
```
Target products in specific categories by slug (the plugin resolves slugs to IDs automatically).

```json
{ "id": [19827, 21153] }
```
Target specific products by ID.

> **Tip:** The Criteria and Variant Criteria fields include a JSON code editor with syntax highlighting. Paste valid JSON — save errors are shown if the syntax is wrong.

#### Price Adjustment types

| Type | What it does | Value to enter |
|---|---|---|
| **Percentage** | Multiplies the current price by `(1 + value/100)`, then applies friendly rounding | e.g. `10` for +10%, `-5` for −5% |
| **Amount** | Adds a fixed amount to the current price (can be negative) | e.g. `5` for +€5, `-2.50` for −€2.50 |
| **Reset** | Clears the price entirely (sets it to empty) | No value needed |

#### Friendly Price Strategy

When a percentage adjustment is used, the raw result is rounded to a "friendly" price. The available strategies are:

| Value | Example (raw 49.73) |
|---|---|
| `x.99` | 49.99 |
| `x.95` *(default)* | 49.95 |
| `x.90` | 49.90 |
| `round` | 50.00 |
| `ceil` | 50.00 |
| `floor` | 49.00 |
| `exact` | 49.73 (no rounding) |

Leave the field empty to use the project-wide default (normally `x.95`).

---

### Multiple rules in one file

A single rule file can contain multiple rule entries — for example, different categories with different percentage increases, or a promo start on one date and a promo reset on another:

- Rules are processed top to bottom.
- If two rules affect the same variant on the same date, the **last one wins**.
- Use the **▲ / ▼** buttons to control the order.

#### Example: set a promo, then reset it later

Add two rule entries to the same file:

| | Entry 1 | Entry 2 |
|---|---|---|
| Effective Date | `2027-04-01` | `2027-05-01` |
| Label | `Spring promo start` | `Spring promo end` |
| Criteria | `{"productCategory": "productCategory:dresses"}` | `{"productCategory": "productCategory:dresses"}` |
| Promotional Price Adjustment | `Percentage, -10` | `Reset` |

Stage the file once — both dates land in the schedule table. Each date is applied independently when the time comes.

#### Example: broad rule with exceptions (Ignore)

Add three rule entries:

1. Effective Date `2027-04-01`, Criteria for the whole category, Promotional Price Adjustment `Percentage, -15`
2. Effective Date `2027-05-01`, same Criteria, Promotional Price Adjustment `Reset`
3. Effective Date `2027-04-01`, Criteria narrowed to the products you want to **exclude**, Action `Ignore`

The ignore entry removes the matching products from the first rule without affecting anything else.

---

## Step 2 — Stage the Rule

Staging writes the planned price changes to the database. Product prices are **not changed yet** — staging only prepares the records for review.

### From the Rule File list

In **Utilities → Price Rule Files**, find the rule file and click the **Actions** button in its row:

| Action | What it does |
|---|---|
| **Stage** | Calculates all price changes and writes them to the schedule. Already-staged rows that are unchanged are skipped; changed rows are updated; already-applied rows are never touched. Safe to run multiple times after editing the rule file. |
| **Stage + Replace** | Deletes all existing *pending* records for this rule first, then stages everything fresh. Use this when you want a clean slate (e.g. after restructuring the rule file significantly). A confirmation dialog appears before anything is deleted. |

A progress notification confirms how many rows were staged, updated, or skipped.

> **Note:** A file marker (⊗) next to the filename means the JSON is invalid. Fix the file in the editor before staging.

---

## Step 3 — Review and Adjust in the Price Schedule

Go to **Utilities → Price Schedule**.

### Rule index

The landing page lists every rule that has records staged in the database, together with a count of pending (not yet applied) and applied rows. Effective dates are shown as small badges under the rule name — click one to jump directly to that date.

### Rule detail view

Click a rule name (or a date badge) to open the detail view.

Records are grouped by effective date. Each date group has two sections:

#### Pending records (not yet applied)

These are shown in an editable table. You can:

- **Edit New Price** — type a different price directly in the input field.
- **Edit New Promo Price** — type a different promotional price, or leave empty to clear it.
- **Filter by title** — type part of a product name and click **Filter by title** to select matching rows. Click **Clear filter** to reset.
- **Select rows** — use the checkboxes to mark individual rows. **Select all** marks every row in the date group.
- **Delete selected** — removes the checked rows from the database. Only the records are removed; the rule file is not changed.
- **Save `<date>`** — saves all edited prices for this date group via AJAX (no page reload). A confirmation message appears.
- **Update effective date** — type a new date in `YYYY-MM-DD` format and click the button to move all *pending* records for this date to the new date.
- **Dry-run Apply** — simulates the apply step for this date group and shows the result inline (no prices are changed). Use this as a final sanity check before going live.

> **Tip:** Click a product title to open that product's edit page in a new browser tab on the Variants tab — useful for cross-referencing current live prices.

If a price was edited since it was first staged, the original staged value is shown in small grey text below the input for reference.

#### Applied records

Once a date has been applied, those records are shown in a collapsed read-only section. Click the summary line to expand it and see the full table including the timestamp when each price was applied.

---

## Step 4 — Apply the Price Changes

Applying writes the new prices to the actual Commerce variants and marks each record as done.

> **Important:** This step changes live product prices. Always run a **Dry-run Apply** first to confirm what will change.

Applying is currently done via the CLI by your developer or a scheduled task. You can monitor the result in the Price Schedule utility: once a date is applied, its records move from the pending table into the collapsed applied section with the applied timestamp.

If something goes wrong after applying, a rollback can restore the original prices — ask your developer to run the rollback command.

---

## Common Tasks

### I need to change one price before it goes live

1. Go to **Utilities → Price Schedule**, open the rule, find the product row.
2. Edit the **New Price** (or **New Promo Price**) field inline.
3. Click **Save `<date>`**.

### I need to remove a product from a scheduled price change

1. Go to **Utilities → Price Schedule**, open the rule.
2. Check the checkbox next to the product row.
3. Click **Delete selected**.

### I made a big structural change to the rule file and want to start fresh

1. Edit and save the rule file in **Utilities → Price Rule Files**.
2. Click **Actions → Stage + Replace** for that file.
3. Confirm the dialog. All pending records for the rule are replaced with the newly computed ones.

### I want to see the current state of all staged prices for a specific date

Click the date badge for that date in the **Price Schedule** rule index, or open the rule detail view and use the date filter bar at the top.

### I want to check what would actually be applied without changing anything

In the rule detail view, click **Dry-run Apply** for the relevant date group. The result is shown inline below the table.

### I need to copy a rule file to use as a template for next season

In **Utilities → Price Rule Files**, click **Actions → Duplicate** next to the file you want to copy, enter a new filename, and confirm. The copy appears in the list immediately and can be edited independently.

### I need to delete a rule file that is no longer needed

In **Utilities → Price Rule Files**, click **Actions → Delete File**. This removes the file from disk but **does not** delete any database records. If you also want to remove the staged records, click **Actions → Delete Records** first (or after).

---

## Tips and Reminders

- **Effective dates must be in the future** when a rule file is staged. A rule file with a past date will fail to stage — update the date in the editor first.
- **Promotional price must be lower than the regular price.** The system enforces this and will reject records where the promotional price is equal to or higher than the regular price.
- **Applying is irreversible without a rollback.** The original prices are stored in the schedule record and can be restored, but it requires a developer to run the rollback command.
- **Staging is non-destructive.** You can re-stage a rule file as many times as you like after making edits — unchanged rows are skipped, changed rows are updated, and applied rows are never touched.
- **One file, multiple dates.** A single rule file can span several effective dates. Stage it once; each date is managed and applied independently.

