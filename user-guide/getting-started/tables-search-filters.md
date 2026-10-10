---
title: Tables, Search & Filters
lastVerified: 2026-10-07
---

# Tables, Search & Filters

Most IERP operational screens begin with a list table. Learning the table behavior once makes the rest of the system easier.

<IerpScreenshot id="global-table" title="IERP list table" description="A typical resource list with preset views, search, filters, sortable headers, row actions and pagination." path="Module → Resource list" />

## Search

Use the table search box when you know a business identifier or name. Searchable fields vary by page.

Examples:

- quotation/order number;
- customer/supplier;
- item code;
- serial number;
- ticket number.

## Filters

Filters narrow the current work queue without changing records. A filter may be:

- single/multi-select;
- yes/no/ternary;
- date range;
- status;
- warehouse/team/assignee;
- exception-only filter such as low stock.

Clear filters when a record appears to be missing.

## Preset and saved views

Some list tables show preset tabs such as an operational status view, **Starred**, or user-saved views.

The view row is horizontally scrollable instead of wrapping. The menu at the end manages saved views where the feature is available.

## Sortable columns

Click a sortable header to change order. Numeric and date values remain tabular and aligned for scanning.

## Row actions

Row actions operate on one record. Common patterns include:

- View
- Edit
- workflow transition
- export/download
- cancel/delete where policy allows it.

An action can disappear or become unavailable when the record status or your permission no longer allows it.

## Bulk actions

Bulk actions are only available where the resource explicitly supports them. Treat bulk actions as high impact: inspect the selected records and confirmation text before execution.

## Pagination

Pagination changes only the visible slice of the table. It does not change filters or business state.

## Favorites

Where a favorite/star column is enabled, starring a record is a personal working aid and does not change its business status.

## Empty states

An empty table can mean:

- there are no records yet;
- current filters return none;
- your role cannot see records outside its scope;
- rollout/configuration prevents the workflow from producing records.

Check filters before assuming the feature is unused.
