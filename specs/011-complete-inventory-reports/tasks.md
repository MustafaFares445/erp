# Tasks: Complete Inventory Reports and Exports

## Phase 1: Setup

- [x] T001 Inspect existing report, export, widget, permission, model, and test conventions
- [x] T002 Confirm Filament 5 table scoping and Laravel 13 chunk/private-download APIs

## Phase 2: Shared Report Queries

- [ ] T003 Add typed report/export enums and permission matrix
- [ ] T004 Implement normalized filters and source-specific Eloquent builders
- [ ] T005 Implement shared report headings and row projection
- [ ] T006 Test all report queries, filters, pricing restrictions, and read-only behavior

## Phase 3: Filament Reporting Area

- [ ] T007 Add permission-aware report tabs for every SRS source
- [ ] T008 Add report-specific read-only columns and filters
- [ ] T009 Link device rows to the existing authorized timeline
- [ ] T010 Test report access, tab visibility, filtering, and absence of mutations

## Phase 4: Complete Private Exports

- [ ] T011 Extend export request types and normalized filter persistence
- [ ] T012 Generate every export from `InventoryReportService` in chunks
- [ ] T013 Add composite pricing and import-result workbook sections
- [ ] T014 Recheck report/source/pricing/export permissions for request, generation, and download
- [ ] T015 Expand export request actions, type filters, and private download tests

## Phase 5: Damaged Stock Valuation

- [ ] T016 Show damaged stock separately in stock reports and exports
- [ ] T017 Exclude damaged and reserved stock from usable value in reports and the widget
- [ ] T018 Test valuation with usable, reserved, damaged, and missing-cost stock

## Phase 6: Polish

- [ ] T019 Update administrator labels and report/export navigation
- [ ] T020 Run focused tests, Pint, and PHPStan
- [ ] T021 Apply clean-code, test, security, and documentation guards
- [ ] T022 Mark tasks complete and commit Phase 011 in focused slices
