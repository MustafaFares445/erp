# Feature Specification: Global Search, Device Tracking, and Expiry

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-25

**Status**: Approved

**Input**: Complete catalog global search, add read-only serialized-device tracking with current location and full history, standardize serialized statuses and movement links, and add a nearest-expiry lot screen.

## User Scenarios & Testing

### User Story 1 - Search the Complete Catalog (Priority: P1)

As an inventory viewer, I can find products and variants from global search using names, identifiers, supplier data, manufacturer, and country.

**Why this priority**: Search is the entry point to operational product and device records.

**Independent Test**: Search each required field and verify the matching product or variant links to an authorized view page.

**Acceptance Scenarios**:

1. **Given** catalog records, **When** a viewer searches English or Arabic name, SKU, barcode, brand, category, supplier, supplier item number, or manufacturer, **Then** matching view-page results are returned.
2. **Given** a supplier country code, **When** a viewer searches its ISO code or localized English/Arabic country name, **Then** the related product and variant are returned.
3. **Given** a user without catalog-view permission, **When** global search runs, **Then** catalog results are unavailable.

---

### User Story 2 - Track a Serialized Device (Priority: P2)

As a stock viewer, I can search a device by Serial, IoT, SKU, or product name and open one read-only page showing its status, current warehouse, receipt, and ordered movement history.

**Why this priority**: Per-device traceability is a core missing SRS workflow.

**Independent Test**: Receive, transfer, and adjust a device and verify its page shows every linked movement in order.

**Acceptance Scenarios**:

1. **Given** a received serialized unit, **When** its page is opened, **Then** current identity, status, warehouse, receipt, and receipt event are shown.
2. **Given** new serialized receipt, transfer, or adjustment movements, **When** they are recorded, **Then** each movement references the serialized-unit identifier.
3. **Given** a historical unit without a serialized receipt movement, **When** its history is loaded, **Then** a synthetic initial receipt event is derived from its receipt item without modifying history.

---

### User Story 3 - Review Lots by Expiry (Priority: P3)

As a stock viewer, I can review lots ordered by nearest expiry with days remaining, usable lot quantity, state, and warehouse/product filters.

**Why this priority**: The existing expiry alerts do not provide an operational work queue.

**Independent Test**: Create expired, expiring, healthy, and undated lots and verify ordering, derived states, quantities, and filters.

**Acceptance Scenarios**:

1. **Given** dated lots, **When** the lot list opens, **Then** dated lots are sorted ascending by expiry and undated legacy lots appear last.
2. **Given** the configured alert horizon, **When** states are derived, **Then** lots are classified expired, expiring, healthy, or no-expiry.
3. **Given** warehouse, product, or expiry-state filters, **When** applied, **Then** only matching lots are shown.

### Edge Cases

- Soft-deleted catalog records do not leak through country-name search.
- Country matching tolerates case and diacritics and supports ISO alpha-2 codes.
- Unknown legacy serialized status strings are normalized to `Unknown`.
- A serialized adjustment must target the same variant and use a one-unit stock difference.
- Synthetic receipt events are displayed only when no serialized receipt movement exists.
- Undated legacy lots remain visible but sort after dated lots.

## Requirements

### Functional Requirements

- **FR-001**: Products and variants MUST have authorized read-only view pages used by global-search results.
- **FR-002**: Product and variant global search MUST cover English/Arabic names, SKU, barcode, brand, category, supplier, supplier item number, manufacturer, country code, and localized country name.
- **FR-003**: Localized country matching MUST use installed runtime capabilities and MUST NOT add a Composer dependency.
- **FR-004**: A read-only serialized-unit resource MUST search Serial, IoT, SKU, variant name, and product name.
- **FR-005**: The device detail page MUST show current status, warehouse, associated receipt, and a chronological timeline.
- **FR-006**: Serialized-unit status MUST use one backed enum in receiving, import, transfer, adjustment, and later damage workflows.
- **FR-007**: Every new serialized receipt, transfer, and adjustment movement MUST set `serialized_inventory_unit_id`.
- **FR-008**: Successful serialized receipt confirmation MUST create one quantity-one movement per device.
- **FR-009**: Serialized adjustments MUST validate device, variant, location, current status, and one-unit direction before changing status/location.
- **FR-010**: Historical movements MUST remain immutable; missing receipt history MUST be represented by a synthetic read-only timeline event.
- **FR-011**: A read-only lot resource MUST default to nearest-expiry order and show days remaining, available quantity, and expiry state.
- **FR-012**: Lot filters MUST cover warehouse, product, and expired/expiring/healthy/no-expiry states.
- **FR-013**: Device, lot, and related results MUST require `StockView`; catalog results MUST require `CatalogView`.
- **FR-014**: The feature MUST remain internal to the administrator panel with no public API or dependency changes.

## Success Criteria

- **SC-001**: Every required SRS search field returns the correct authorized catalog result and a valid destination.
- **SC-002**: 100% of new serialized stock movements reference the affected device.
- **SC-003**: A device timeline preserves chronological receipt, transfer, and adjustment events without altering historical rows.
- **SC-004**: Expiry ordering and filters classify all dated lots consistently with the configured horizon.
- **SC-005**: Unauthorized users cannot access device, lot, or catalog search destinations.

## Assumptions

- PHP's installed `intl` extension and ICU region data replace the unavailable Symfony Intl package without changing dependencies.
- Arabic interface localization and RTL remain out of scope; Arabic catalog and country search values remain in scope.
- Damage, recovery, and disposal statuses are declared now and receive their workflows in Phase 010.
- StockView is the shared authorization boundary for serialized devices and lots.
