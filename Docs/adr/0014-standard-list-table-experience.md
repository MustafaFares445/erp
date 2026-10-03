# ADR 0014: Standard List-Table Experience

**Status**: Accepted

**Date**: 2026-10-03

**Deciders**: Project Owner

**Related**: `app/Providers/AppServiceProvider.php` (`configureTableDefaults()`), `app/Filament/Concerns/HasTableViewTabs.php`, `app/Filament/Concerns/HasSavedTableViews.php`, `app/Filament/Tables/Columns/FavoriteColumn.php`, `app/Filament/Tables/Filters/TableQueryBuilder.php`, `tests/Feature/Filament/ListTableExperienceTest.php`

## Context

The admin panel has around 90 Filament resources. Their list tables were built one by one: filters sat in a dropdown, column visibility depended on each table, saved views lived in a separate header "Views" menu, and there was no way to star a record.

The project owner asked for the list layout used by AureusERP's purchase lists:

- a view tab bar inside the table card;
- Group by, search, a filter funnel with an active-count badge, and a column manager in the toolbar;
- a slide-over filter panel built from rules ("Add rule", operator, value, AND/OR);
- a per-user star column with a "Starred" view.

Only layout and behaviour are copied. IERP keeps its own amber brand colours, light and dark modes, and RTL Arabic.

## Decision

### Global defaults (every resource and relation-manager table)

`AppServiceProvider::configureTableDefaults()` applies the following to every table. Dashboard table widgets are excluded and only lose the column manager.

- Every labelled column is toggleable, and columns can be reordered live from the column manager.
  - A table with an unlabelled column (for example a logo or thumbnail) keeps toggles but is not reorderable, because Filament rejects reordering unlabelled columns.
  - Give such columns a label to make the table reorderable.
- Filters open in a slide-over with an explicit "Apply filters" step (deferred filters).
- Page sizes are 10, 25, 50 and 100, with 10 as the default.

A table's own explicit calls still override these defaults.

### Filters: rules plus quick filters

Main document lists put a `TableQueryBuilder` rule list first in the slide-over. Its constraints cover plain field comparisons: status, reference, party, amounts and dates.

Domain toggles stay as ordinary filters below it, because a rule cannot express them simply. Examples are "ready to send", "overdue", "accounting issues" and "trashed".

A plain `SelectFilter` that a constraint now covers is removed, so the same field is not offered twice.

Constraint options are a UI convenience, not authorization. Table queries must stay scoped to what the user may see.

### One tab bar for presets, Starred and saved views

List pages using `HasTableViewTabs` render one tab bar in the table header, in this order:

1. the page's `getTabs()` presets (the first is the default);
2. "Starred", added automatically for `Favoritable` models;
3. saved views.

The "⋮" menu at the end of the bar holds save, update, set default, "show views in tab bar" and delete.

Which saved views appear in the bar:

- The user's own saved views appear unless the user hides them.
- Views shared by other users appear only after the user chooses to show them.
- Both choices are stored as `table_view_preferences.is_favorite`.

Loading a saved view still restores tab, filters, grouping, search, sort and page size. Filters missing from an older saved view start from their defaults.

### Favorites

`record_favorites` is a polymorphic, per-user table that cascades on user delete.

Models opt in with `Favoritable` + `HasFavorites`, and lists add `FavoriteColumn`. The starred flag is loaded in the same query as the rows (an `is_favorited` exists-subquery), so the column costs no query per row.

Favorites are for main document lists only, not for settings or lookup tables.

## Consequences

- New list tables get the column manager, slide-over filters and page sizes without extra code.
- A main document list adopts the full experience by adding:
  - `HasTableViewTabs` + `PersistsTablePresentation` on its list page, with an "all" preset first;
  - `FavoriteColumn` + `Favoritable` on the table and model;
  - `groups()` and `TableQueryBuilder` on the table.
- Saved views that stored a removed `SelectFilter` key lose that filter when loaded. Unknown filter keys are already ignored.
- Rollout is incremental. The purchasing RFQ and purchase-order lists adopted the full experience first, followed by the lists that already had saved views: Customers, Invoices, Operations (all and typed), Maintenance Requests, Stock Levels, Tickets and Visits. Stock Levels is a lookup list, so it has no favorites.
- Saved views that stored a removed filter are converted by `SavedTableViewFilterMigrator` (run from a data migration): select, relationship, boolean, text and date-range filters become equivalent query-builder rules, so they keep filtering after the upgrade. Views that never stored those keys are untouched.
- A relationship rule stores its picked ids under `settings.value` (an array), while a multiple select rule uses `settings.values`. A rule written with the wrong key fails validation and is silently ignored.
