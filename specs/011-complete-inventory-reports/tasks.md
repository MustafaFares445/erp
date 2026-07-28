# Tasks: Complete Inventory Reports and Exports

## Phase 1: Setup

- [x] T001 Inspect existing report, export, widget, permission, model, and test conventions
- [x] T002 Confirm Filament 5 table scoping and Laravel 13 chunk/private-download APIs

## Phase 2: Shared Report Queries

- [x] T003 Add typed report/export enums and permission matrix
- [x] T004 Implement normalized filters and source-specific Eloquent builders
- [x] T005 Implement shared report headings and row projection
- [x] T006 Test all report queries, filters, pricing restrictions, and read-only behavior

## Phase 3: Filament Reporting Area

- [x] T007 Add permission-aware report tabs for every SRS source
- [x] T008 Add report-specific read-only columns and filters
- [x] T009 Link device rows to the existing authorized timeline
- [x] T010 Test report access, tab visibility, filtering, and absence of mutations

## Phase 4: Complete Private Exports

- [x] T011 Extend export request types and normalized filter persistence
- [x] T012 Generate every export from `InventoryReportService` in chunks
- [x] T013 Add composite pricing and import-result workbook sections
- [x] T014 Recheck report/source/pricing/export permissions for request, generation, and download
- [x] T015 Expand export request actions, type filters, and private download tests

## Phase 5: Damaged Stock Valuation

- [x] T016 Show damaged stock separately in stock reports and exports
- [x] T017 Exclude damaged and reserved stock from usable value in reports and the widget
- [x] T018 Test valuation with usable, reserved, damaged, and missing-cost stock

## Phase 6: Polish

- [x] T019 Update administrator labels and report/export navigation
- [x] T020 Run focused tests, Pint, and PHPStan
- [x] T021 Apply clean-code, test, security, and documentation guards
- [x] T022 Mark tasks complete and commit Phase 011 in focused slices
