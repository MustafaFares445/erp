# Feature Specification: Complete Inventory Reports and Exports

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-25

**Status**: Approved

**Input**: Complete the SRS reporting surface with shared read-only inventory queries, filtered private Excel exports, permission-aware pricing data, and damaged-stock-aware valuation.

## User Scenarios & Testing

### User Story 1 - Review Complete Inventory Reports (Priority: P1)

As an authorized administrator, I can open one reporting area and select catalog, stock, movement, device, expiry, supplier, price, pricing-control, and import reports.

**Independent Test**: Seed one record for every report source, select each report, apply its filters, and verify the table contains only the expected records.

**Acceptance Scenarios**:

1. **Given** report-view and source-view permissions, **When** a report is selected, **Then** its read-only table is available.
2. **Given** report-view without the source permission, **When** the report area is opened, **Then** the forbidden report is absent and its query is not exposed.
3. **Given** warehouse, date, supplier, country, product, status, or import-run filters, **When** they are applied, **Then** the table and later export use the same filtered query.

---

### User Story 2 - Export the Same Filtered Data Privately (Priority: P2)

As an administrator with export permission, I can request a background Excel export for each supported SRS report and download the completed file only through an authorized action.

**Independent Test**: Request each export with filters, run its queued job, inspect the workbook rows, and verify storage, permissions, and audit records.

**Acceptance Scenarios**:

1. **Given** a filtered report, **When** an export is requested, **Then** the export record preserves the normalized filters and the workbook matches the table query.
2. **Given** a large report, **When** generation runs, **Then** records are read in chunks rather than loaded as one collection.
3. **Given** a completed export in private storage, **When** a user lacking export, report, source, or required pricing permission requests it, **Then** download is refused.

---

### User Story 3 - Protect Pricing and Value Usable Stock Correctly (Priority: P3)

As a pricing-authorized administrator, I can review cost, price history, tiers, assignments, overrides, and supplier prices in their original currencies; other report viewers never receive sensitive pricing.

**Independent Test**: Compare the same report as users with and without pricing permission, and verify damaged stock is separate and excluded from usable value.

**Acceptance Scenarios**:

1. **Given** supplier references in different currencies, **When** the supplier report is viewed or exported, **Then** each price retains its original currency and no cross-currency rank or conversion is shown.
2. **Given** damaged stock, **When** the stock report, stock export, or stock-value widget is calculated, **Then** damaged quantity is separate and valuation uses available usable quantity only.
3. **Given** a user without pricing permission, **When** a quantity report is viewed or exported, **Then** cost and value fields are omitted.

### Edge Cases

- Empty report sources render an empty table and a header-only workbook.
- A report whose source record was soft-deleted does not leak it unless the existing source contract intentionally includes it.
- Date ranges reject or normalize an end date before a start date.
- Pricing access is rechecked when a queued export executes and when it is downloaded.
- Import runtime errors and row validation errors remain distinguishable in the import-results report.
- Device rows link to their existing read-only timeline without duplicating movement history.

## Requirements

### Functional Requirements

- **FR-001**: `InventoryReportService` MUST own the shared report queries and filter application used by Filament and exports.
- **FR-002**: The reporting area MUST expose read-only catalog, stock-level, movement, device, expiry-lot, supplier/country, price-history, pricing-tier, customer-assignment, floor-override, import-run, and import-row reports.
- **FR-003**: Device reports MUST show current status and location and link to the complete existing device timeline.
- **FR-004**: Supplier reports MUST show supplier, country, purchase price, and original currency without conversion or cross-currency ranking.
- **FR-005**: Pricing reports MUST include price history, tiers, customer assignments, and floor overrides.
- **FR-006**: Import reports MUST show run counters/status and row outcome, validation errors, runtime error, and affected entity identifiers.
- **FR-007**: Supported export record types MUST be `catalog`, `stock_levels`, `movements`, `devices`, `expiry_lots`, `supplier_comparison`, `price_history`, `pricing_tiers`, and `import_results`.
- **FR-008**: Every export MUST run through the existing queued job, read records in chunks, preserve normalized filters, and write to private storage.
- **FR-009**: Downloads MUST require `Export`, `ReportView`, the underlying source permission, and `PricingView` whenever sensitive pricing is present.
- **FR-010**: Report viewing MUST require `ReportView` plus the underlying source permission; cost, price, profit, supplier-price, and valuation data MUST additionally require `PricingView`.
- **FR-011**: Stock tables and exports MUST show damaged quantity separately.
- **FR-012**: Usable stock valuation and the stock-value widget MUST use available quantity, excluding damaged and reserved stock.
- **FR-013**: Reports and exports MUST remain read-only and MUST NOT mutate stock or source records.
- **FR-014**: Existing public routes, API contracts, dependencies, historical records, and localization scope MUST remain unchanged.

## Success Criteria

- **SC-001**: Every report and export returns exactly the records selected by the same normalized filters.
- **SC-002**: Every supported export produces a private workbook and an auditable export record.
- **SC-003**: Unauthorized report and download paths expose zero source or pricing data.
- **SC-004**: Damaged stock contributes zero to usable inventory valuation.
- **SC-005**: Focused tests, Pint, and PHPStan pass without baseline or dependency changes.

## Out of Scope

- Arabic translations and RTL.
- Public APIs.
- Currency conversion, exchange rates, accounting entries, or supplier ranking across currencies.
- New report-builder dependencies or public file URLs.
