# ADR 0016: List Table Fit

**Status**: Accepted — approved by the project owner on 2026-10-10 after the seven-list proof of concept; rolled out list by list

**Date**: 2026-10-10

**Deciders**: Project Owner

**Related**: [ADR 0014](0014-standard-list-table-experience.md), `app/Filament/Tables/TableFit.php`, `app/Filament/Concerns/PersistsTablePresentation.php`, `resources/css/filament/admin/surfaces.css` (`.ierp-table-fit`), `tests/Feature/Filament/TableFitTest.php`, `tests/Feature/Filament/PersistsTablePresentationTest.php`

## Context

A release QA sweep at a 1440px desktop found 60 list URLs whose table was wider than its card. The sticky trailing row-actions cell (ADR 0014 follow-up) keeps View/Edit and the next-step button reachable, but while a table is scrolled to its start the sticky cell covers whatever column sits under it — typically an amount or a date.

Measurement showed the width came from content, not column count:

- cell descriptions (payment status explanations, order blockers, invoice payment breakdowns) never wrapped;
- header labels never wrapped, so "Available customer deposit" set a 180px column over `AED 0.00` values;
- the favorite column was sized by its "Favorite" header or the "Summary" heading of summarised tables, not by its star;
- inline next-step buttons kept their full label at every width;
- several lists showed eight or nine columns by default.

Wrapping alone is not enough: when a table overflows anyway, the browser squeezes every wrappable cell to one word per line.

## Decision

A list opts in with `TableFit::apply($table)` and passes its record actions through `TableFit::rowActions([...])`.

### Column policy (`.ierp-table-fit`)

- Header labels wrap at word boundaries and do not set the column width.
- `wrap()` text columns (names, next action) keep their natural width up to 10rem, then wrap; cell descriptions do the same up to 11rem. `width: max-content` stops the browser from squeezing them below that.
- Identifiers, money, quantities, dates and status badges stay on one line and are never truncated. A long badge may still opt into wrapping with `ierp-ta-wrap-badge`.
- Horizontal cell padding is 0.5rem; the first and last columns keep the card's edge inset.
- The favorite header is drawn as a star and a summarised table's "Summary" heading as Σ; both keep their text for screen readers.

### Default columns

Each list shows the columns needed to identify a record and decide the next step by default, so it fits the 1104px table card of a 1440px desktop in English and Arabic. Secondary columns are `toggleable(isToggledHiddenByDefault: true)` and stay one click away in the column manager. Explanatory text that repeats for every row with the same state (payment status descriptions) moves into a tooltip.

Because Filament stores a user's layout on every visit and a stored toggle wins over the column's default, `PersistsTablePresentation` applies a changed default to toggles that were stored against the previous default. Toggles stored against the current default are the user's own choice and are kept.

### Row actions

- Workflow steps stay inline (as buttons where the list has a next-step button); View/Edit and utilities live in the ⋮ action group, and clicking a row opens the record.
- Below the `xl` breakpoint an icon row button collapses to its icon, with its label as tooltip and accessible name, so the sticky actions cell stays narrow on laptops and tablets.
- The sticky actions cell is unchanged: it never covers a column once the table is scrolled to its end, in LTR or RTL.

### Narrow screens

From `sm` up, tables that still do not fit scroll horizontally under the sticky actions cell (no breakpoint-based column hiding). Below `sm` (phones) the list uses Filament's `stackedOnMobile()`: each record is a card with label and value on one line and labelled action buttons. This amends the "tables scroll horizontally" rule of the dashboard design system for phones only.

## Consequences

- The owner accepted the proof-of-concept choices as they are: the default-hidden columns listed in `TableFit` tests, payment status explanations as tooltips, ticket View/Edit in the overflow menu, quotation steps as buttons, icon-only row buttons below `xl`, the Σ summary heading and stacked cards on phones.
- Proof of concept: Invoices, Payments, Quotations, Orders, Receivable Write-offs, Maintenance Requests and Tickets fit at 1440px in English and Arabic, scroll at 1024px and 768px without permanently hiding a column, and stack at 390px.
- Rollout is per list: adopting the policy means calling `TableFit`, wrapping name/text columns, and choosing which secondary columns start hidden. The choice of default columns is a product decision recorded per list in its tests.
- Users who saved a layout keep columns they deliberately showed or hid; columns they never touched follow the new defaults once.
- Dashboard table widgets adopt the policy through `BuildsDashboardTables::dashboardTable()`.
- A table reused on a narrower record sub-page (a product's variants, quantities or movements) drops or hides by default the columns that repeat the parent record, instead of scrolling.
