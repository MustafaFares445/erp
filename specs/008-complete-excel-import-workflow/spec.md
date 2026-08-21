# Feature Specification: Complete Excel Import Workflow

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-25

**Status**: Approved

**Input**: Complete the private Excel catalog import so active attributes and inventory identity data are validated, valid rows can be applied despite invalid rows, stock is received through the inventory service, and every row has a durable outcome.

## User Scenarios & Testing

### User Story 1 - Prepare and Validate a Complete Workbook (Priority: P1)

As an inventory administrator, I can download a template containing warehouse, quantity, and active attribute columns, upload a workbook, and review validation results without changing catalog or stock data.

**Why this priority**: Reliable validation is the boundary that prevents malformed imports from mutating inventory.

**Independent Test**: Parse a workbook containing catalog-only, serialized, lot, and invalid rows and verify each row is classified without creating catalog or inventory records.

**Acceptance Scenarios**:

1. **Given** active product attributes, **When** the template is generated, **Then** it includes `warehouse_code`, `quantity`, and one `attribute_{code}` column per active attribute.
2. **Given** a row without both warehouse and quantity, **When** it is parsed, **Then** it is treated as catalog-only.
3. **Given** identity or lot data without warehouse and quantity, **When** it is parsed, **Then** it is rejected with field-specific reasons.
4. **Given** an inactive or unknown attribute or select value, **When** it is parsed, **Then** it is rejected without creating values.

---

### User Story 2 - Apply Valid Rows Independently (Priority: P2)

As an inventory administrator, I can confirm a run containing errors and have all valid rows applied once while invalid or runtime-failed rows remain rejected with explicit reasons.

**Why this priority**: Large operational imports must not be blocked by unrelated bad rows or silently duplicate work on retries.

**Independent Test**: Confirm a mixed run twice and verify successful catalog and receipt groups are applied exactly once, failed groups are isolated, and final counters remain stable.

**Acceptance Scenarios**:

1. **Given** a ready run containing valid and invalid rows, **When** confirmation is requested, **Then** a background apply job starts and processes only valid unapplied rows.
2. **Given** receipt rows for different warehouse and supplier groups, **When** one group fails, **Then** other groups remain committed and the run finishes as confirmed with errors.
3. **Given** an already applied row, **When** the apply job is retried, **Then** the row and its inventory effects are not applied again.

---

### User Story 3 - Receive Identities, Lots, Expiry, and Attributes (Priority: P3)

As an inventory administrator, I can import serialized devices, IoT identifiers, lots, expiry dates, and variant attributes and see the created entity identifiers in the row result.

**Why this priority**: These fields are the missing SRS data that make imported inventory operationally traceable.

**Independent Test**: Apply valid serialized and lot rows and verify attributes, receipts, receipt items, devices, lots, balances, and movements are persisted only through existing domain services.

**Acceptance Scenarios**:

1. **Given** a serialized row, **When** it is applied, **Then** quantity must be `1` and one serialized unit with its Serial and optional IoT identity is attached to the receipt item.
2. **Given** an expiry-tracked row, **When** it is applied, **Then** expiry is required and the confirmed receipt creates the lot.
3. **Given** an active text attribute, **When** a new value differs only by case from an existing value, **Then** the existing value is reused.
4. **Given** a successfully applied row, **When** its result is reviewed, **Then** the affected product, variant, receipt, receipt-item, device, and lot identifiers are available as applicable.

### Edge Cases

- An empty workbook or a workbook missing required base columns is invalid.
- Quantity must be a positive decimal, and serialized quantity must equal one.
- Warehouse and quantity must be supplied together.
- Inventory rows require an active warehouse; supplier codes resolve only active suppliers for receipt grouping.
- Duplicate Serial or IoT identities fail their group without rolling back other groups.
- A worker crash after a group commit can safely retry because applied rows retain durable results.
- A parse or apply job failure leaves the run in a terminal failed state and records its failure.

## Requirements

### Functional Requirements

- **FR-001**: The generated template MUST include the existing catalog columns, `warehouse_code`, `quantity`, and dynamic `attribute_{code}` columns for active attributes.
- **FR-002**: Parsing MUST be queued and MUST transition the run through `Queued`, `Parsing`, and exactly one of `Ready`, `ReadyWithErrors`, or `Invalid`.
- **FR-003**: Applying MUST be queued and MUST transition through `Applying` and exactly one of `Confirmed`, `ConfirmedWithErrors`, or `Failed`.
- **FR-004**: Rows with neither warehouse nor quantity MUST remain catalog-only; either field without the other MUST be invalid.
- **FR-005**: Identity, lot, or expiry fields MUST require inventory context.
- **FR-006**: Serialized rows MUST use quantity one and provide a Serial identity when serial tracking is enabled.
- **FR-007**: Expiry-tracked rows MUST provide a valid expiry date; lot rows MAY use any positive quantity.
- **FR-008**: Dynamic attribute codes MUST resolve active attributes; select values MUST already exist and be active; text values MUST be reused or created case-insensitively during apply.
- **FR-009**: Confirmation MUST be permitted for `Ready` and `ReadyWithErrors` runs and MUST process only valid, unapplied rows.
- **FR-010**: Catalog-only rows MAY be applied independently; inventory rows MUST be grouped by warehouse and supplier and each group MUST use an independent database transaction.
- **FR-011**: Inventory effects MUST be produced through `InventoryReceivingService`; the importer MUST NOT write balances, lots, or movements directly.
- **FR-012**: Row application MUST be idempotent and MUST persist row status, runtime error, operation type, and affected entity identifiers.
- **FR-013**: Run counters MUST distinguish created, updated, applied, and rejected outcomes.
- **FR-014**: Templates, uploaded workbooks, and generated row/final reports MUST remain on private storage and downloads MUST require import authorization.
- **FR-015**: Existing `invalid` runs MUST migrate to `ReadyWithErrors` when they have valid rows and `Invalid` otherwise.
- **FR-016**: The feature MUST remain internal to the System Administrator panel and MUST NOT change public routes or dependencies.

### Key Entities

- **Inventory Import Run**: Queued workflow aggregate containing file path, state, counters, actor, timestamps, and terminal failure details.
- **Inventory Import Item**: One workbook row containing normalized payload, validation/runtime errors, idempotency state, operation type, and affected identifiers.
- **Receipt Group**: Valid inventory rows sharing warehouse and supplier, applied in one independent inventory transaction.
- **Product Attribute Value**: Active select value or case-insensitively reused/created text value assigned to the imported variant.

## Success Criteria

### Measurable Outcomes

- **SC-001**: A mixed workbook applies 100% of valid rows while retaining an explicit reason for every invalid or runtime-failed row.
- **SC-002**: Retrying any apply job creates zero duplicate catalog, receipt, stock, movement, lot, Serial, or IoT records.
- **SC-003**: Every imported stock quantity has a confirmed receipt and matching movement produced by the receiving service.
- **SC-004**: Final run counters equal the persisted row outcomes for all terminal runs.
- **SC-005**: No import artifact is publicly accessible or downloadable without authorization.

## Assumptions

- One spreadsheet row represents one serialized device.
- A supplier is optional; inventory rows without one form a warehouse-only receipt group.
- Supplier codes reference existing active suppliers during receipt grouping; catalog reference creation retains existing behavior.
- English administrator labels are sufficient; Arabic localization and RTL are out of scope.
- Queue workers are available for parsing and applying.
- No public API or Composer dependency change is required.
