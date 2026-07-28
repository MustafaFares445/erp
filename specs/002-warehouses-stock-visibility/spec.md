# Feature Specification: Warehouses, Locations & Read-Only Stock Visibility

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-22

**Status**: Draft

**Input**: User description: "Read the second & third Phase (FI-1 — Warehouses and Locations; FI-2 — Stock Levels and Movements) from Docs/FILAMENT_INVENTORY_DASHBOARD_PLAN.md and create the next spec per GitHub Spec Kit best practices."

## Overview

This feature delivers the first two visible inventory screens on top of the trusted foundation built in the previous phase (FI-0). It has two tightly related parts:

- **Warehouse network setup (FI-1)** — the master data every other inventory record depends on: warehouses and the locations/bins inside them. This is deliberately *safe* work: it never changes a stock balance.
- **Read-only stock visibility (FI-2)** — accurate, view-only windows onto what is on hand, reserved, and available at each warehouse, plus the immutable history (the movement ledger) of every stock change. These screens *observe* stock; they never mutate it.

Combined, this phase gives administrators the ability to model their physical warehouse network and to see the truth about stock and its history — while structurally guaranteeing that none of these screens can alter a balance. Every actual stock change still happens only through the sanctioned adjustment and transfer flows delivered in later phases (FI-3, FI-4), each of which writes a movement and updates the balance together inside the trusted domain logic.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Set up and maintain the warehouse network (Priority: P1)

An administrator models the organization's physical storage: they create warehouses (each with a unique code and a name), record the locations or bins inside each warehouse, and activate or deactivate warehouses as the network changes. This master data is the anchor every stock record, movement, adjustment, and transfer references.

**Why this priority**: Nothing else in inventory can be located without warehouses. Stock levels, movements, adjustments, and transfers all reference a warehouse, so this master data must exist first. It is the minimum viable slice and delivers standalone value: an administrator can define the warehouse network even before any stock data is present.

**Independent Test**: Create a warehouse with a unique code, add one or more locations to it, deactivate it, and confirm all of this is persisted and listed — with no dependency on stock data existing.

**Acceptance Scenarios**:

1. **Given** an administrator permitted to manage warehouses, **When** they create a warehouse with a unique code and a name, **Then** it is saved and appears in the inventory area's warehouse list.
2. **Given** a warehouse code that already exists, **When** the administrator submits the form, **Then** validation fails with a field-level error and no duplicate record is created.
3. **Given** an existing warehouse, **When** the administrator adds a location/bin to it, **Then** the location is saved and listed under that warehouse.
4. **Given** a warehouse that has stock or movement records referencing it, **When** the administrator attempts to remove it, **Then** removal is blocked and deactivating the warehouse is offered instead.
5. **Given** a warehouse with no referencing stock or movement records, **When** the administrator removes it, **Then** it is removed reversibly (recoverable, not permanently destroyed).

---

### User Story 2 - See current stock on hand, reserved, and available (Priority: P2)

An administrator opens a read-only stock view and sees, per product variant and warehouse, how much is physically on hand, how much is reserved, and how much is therefore available — along with the reorder level and a clear flag when available stock has fallen to or below that reorder level. They can filter by warehouse, search by product or variant, and narrow the list to only low-stock items. There is no way to create, edit, or delete a stock balance from this screen.

**Why this priority**: Visibility into current stock is the primary day-to-day reason to open the dashboard, and it directly supports reordering decisions. It depends on the warehouse network (P1) and on backend stock data existing, so it follows warehouse setup.

**Independent Test**: With warehouses and stock data present, open the stock view, filter to a warehouse, apply the low-stock filter, and confirm the correct rows appear and are flagged — while confirming no create/edit/delete controls exist anywhere on the screen.

**Acceptance Scenarios**:

1. **Given** an administrator with permission to view stock, **When** they open the stock view, **Then** each row shows the product variant, warehouse, on-hand quantity, reserved quantity, available quantity, and reorder level.
2. **Given** a variant whose available quantity is at or below its reorder level, **When** the low-stock filter is applied, **Then** that row is listed and visibly flagged as low stock.
3. **Given** the stock view, **When** the administrator looks for any control to create, edit, or delete a stock balance, **Then** none exists — the screen is view-only.
4. **Given** a chosen warehouse and a product/variant search term, **When** the administrator applies them, **Then** the list narrows to matching rows for that warehouse.
5. **Given** the same product variant, **When** it is stored in two different warehouses, **Then** it appears as two separate rows, one per warehouse, with independent balances.

---

### User Story 3 - Review the immutable history of every stock change (Priority: P3)

An administrator opens the movement ledger and sees a read-only, chronological record of every stock change: what type it was (sale, return, adjustment, transfer, reservation), the signed quantity (clearly showing increases vs. decreases), the product variant and warehouse, who caused it, when, and a reference to the source document that produced it. They can filter by movement type, warehouse, variant, date range, and source type, and open any single movement to view its full detail and follow a read-only link to its source document. Nothing in this ledger can be created, edited, or deleted.

**Why this priority**: The ledger is the evidence and audit surface that makes the read-only stock numbers trustworthy, and it is where cross-module source documents (such as a delivery note) are safely referenced. It builds on stock visibility and is most valuable once movement-producing flows exist, hence third.

**Independent Test**: With movement records present, open the ledger, filter by type and date range, open one movement's detail, and confirm the source reference renders as a read-only link — while confirming no create/edit/delete controls exist.

**Acceptance Scenarios**:

1. **Given** an administrator with permission to view movements, **When** they open the movement ledger, **Then** each entry shows its date, product variant, warehouse, movement type, signed quantity, source reference, status, and who created it.
2. **Given** a movement that decreases stock and one that increases it, **When** they are listed, **Then** their quantities are visibly distinguishable as a decrease versus an increase.
3. **Given** a movement sourced from a document owned by another module (e.g., a delivery note or credit note), **When** the administrator opens the movement, **Then** the source is shown as a read-only link to that document and cannot be edited from the inventory area.
4. **Given** the movement ledger, **When** the administrator looks for any control to create, edit, or delete a movement, **Then** none exists — the ledger is immutable.
5. **Given** filters for movement type, warehouse, variant, date range, and source type, **When** the administrator applies any combination, **Then** the ledger narrows to matching entries.

---

### Edge Cases

- **Warehouse still referenced**: An administrator tries to remove a warehouse that has stock rows or movement history. Removal is blocked; deactivation is offered so the warehouse stops being selectable for new activity without destroying its records.
- **Duplicate warehouse code**: Two warehouses are given the same code. The second is rejected at validation before any record is written.
- **Low-stock boundary**: A variant's available quantity is exactly equal to its reorder level. It is treated as low stock (the threshold is "at or below"), not merely below.
- **Zero or negative-looking availability**: Reserved quantity meets or exceeds on-hand, so available is zero (or would be negative). The available figure is shown as the plain result of on-hand minus reserved without the view attempting any correction — the numbers reflect the underlying data, which only the trusted flows can change.
- **Cross-module source document**: A movement's source is a document owned by Sales or another module. It renders strictly as a read-only link; the inventory area never offers to edit another module's document.
- **Attempted direct write via address**: An administrator without permission, or any administrator seeking a write control on the stock or movement screens, cannot reach one — these screens expose no create/edit/delete path at all, and unpermitted areas are refused by address per the foundation rules.
- **Same variant in multiple warehouses**: The uniqueness of a stock balance is per variant-and-warehouse pair, so the same variant legitimately appears once per warehouse it is stored in.

## Requirements *(mandatory)*

> **Phasing note**: This feature spans two plan phases. Requirements tagged **[FI-1]** deliver warehouse/location master data. Requirements tagged **[FI-2]** deliver read-only stock and movement visibility. Both inherit the access, authorization, integrity, and audit guarantees established in the foundation phase (FI-0) rather than re-defining them.

### Functional Requirements — Warehouses & Locations (FI-1)

- **FR-001**: The system MUST allow a permitted administrator to create a warehouse with a name and a code, where the code MUST be unique across all warehouses. *[FI-1]*
- **FR-002**: The system MUST reject a warehouse whose code duplicates an existing warehouse's code, with a field-level validation error and no record created. *[FI-1]*
- **FR-003**: The system MUST allow a permitted administrator to record and manage the locations/bins belonging to a warehouse. *[FI-1]*
- **FR-004**: The system MUST allow a warehouse (and its locations) to be marked active or inactive, so a warehouse can be retired from new activity without being removed. *[FI-1]*
- **FR-005**: The system MUST block removal of a warehouse that is referenced by any stock balance or movement record, and offer deactivation instead. *[FI-1]*
- **FR-006**: The system MUST make any warehouse removal reversible (recoverable), never a permanent destruction of the record. *[FI-1]*
- **FR-007**: The system MUST let administrators find warehouses by code and name, and filter the warehouse list by active state (and by removed/recoverable state). *[FI-1]*
- **FR-008**: The system MUST present, for each warehouse, read-only reference counts of its locations and its stock rows so an administrator can gauge whether it is safe to retire. *[FI-1]*

### Functional Requirements — Stock Levels (FI-2, read-only)

- **FR-009**: The system MUST present stock balances as read-only, showing for each product variant and warehouse the on-hand quantity, reserved quantity, available quantity, and reorder level. *[FI-2]*
- **FR-010**: The system MUST expose NO control to create, edit, or delete a stock balance from the stock view. *[FI-2]*
- **FR-011**: The system MUST flag a stock row as low stock when its available quantity is at or below its reorder level. *[FI-2]*
- **FR-012**: The system MUST let administrators filter stock by warehouse and by low-stock only, and search by product/variant. *[FI-2]*
- **FR-013**: The system MUST treat each product-variant-and-warehouse pair as a single distinct stock row, so the same variant in different warehouses is shown separately. *[FI-2]*
- **FR-014**: The system MUST make clear on the stock view that balances change only through the sanctioned adjustment and transfer flows; where those flows are available, the stock view MUST provide navigation to start one rather than any inline editing. *[FI-2; the navigation targets become live as FI-3/FI-4 ship]*

### Functional Requirements — Movement Ledger (FI-2, read-only)

- **FR-015**: The system MUST present the movement ledger as read-only and immutable, exposing NO control to create, edit, or delete a movement anywhere. *[FI-2]*
- **FR-016**: The system MUST show, for each movement, its date, product variant, warehouse, movement type, a signed quantity that visibly distinguishes increases from decreases, the source reference, status, and the acting user. *[FI-2]*
- **FR-017**: The system MUST let administrators filter the ledger by movement type, warehouse, variant, date range, and source type. *[FI-2]*
- **FR-018**: The system MUST provide a per-movement detail view that includes a link to the source document that produced the movement. *[FI-2]*
- **FR-019**: When a movement's source document is owned by another module, the system MUST render it strictly as a read-only cross-module link and MUST NOT offer any way to edit that document from the inventory area. *[FI-2]*

### Cross-Cutting Requirements (inherited from FI-0, reaffirmed here)

- **FR-020**: The system MUST hide the warehouse, stock, and movement areas from any administrator lacking the corresponding view permission, and refuse direct access by address. *[inherited FI-0; realized per resource here]*
- **FR-021**: Because the stock and movement screens expose no write path, the system MUST NOT provide any way to change a stock balance or the movement ledger from these two screens — reinforcing that balances change only via the trusted domain flows. *[FI-2; the "no ledger delete" guarantee promised at foundation is realized here with the first ledger screens]*

### Key Entities *(include if feature involves data)*

- **Warehouse**: A physical storage facility with a unique code, a name, an address, and an active/inactive state; anchors all stock and movement records. May be deactivated but not permanently destroyed while referenced.
- **Warehouse Location**: A subdivision (bin/shelf/area) inside a warehouse, with its own name/code and active state.
- **Stock Level**: The current balance for one product variant in one warehouse — on-hand, reserved, and available quantities plus a reorder level. Unique per variant-and-warehouse pair; read-only in this feature.
- **Stock Movement**: An immutable ledger entry recording one stock change — its type, signed quantity, variant, warehouse, status, acting user, timestamp, and a reference to the source document (which may belong to another module). Read-only in this feature.
- **Product Variant**: A catalog item referenced (read-only) by stock levels and movements; owned by the catalog module, not managed here.
- **Source Document**: The originating record for a movement (e.g., an adjustment, transfer, delivery note, or credit note); referenced as a read-only link, and never edited from the inventory area when it belongs to another module.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An administrator can create a warehouse, add at least one location, and deactivate it end-to-end without any stock data present — confirming warehouse master data stands on its own. *[FI-1]*
- **SC-002**: 100% of attempts to save a warehouse with a duplicate code are rejected with a field-level error and create no record. *[FI-1]*
- **SC-003**: 100% of attempts to remove a warehouse that is still referenced by stock or movement records are blocked, with deactivation offered instead. *[FI-1]*
- **SC-004**: 0 controls to create, edit, or delete a stock balance or a movement exist on the stock and movement screens — verified by inspection and by an automated test asserting their absence. *[FI-2]*
- **SC-005**: 100% of variants whose available quantity is at or below their reorder level appear when the low-stock filter is applied, and none that are above it do. *[FI-2]*
- **SC-006**: For any movement sourced from another module's document, the source is presented only as a read-only link — 0 edit paths to that document exist from the inventory area. *[FI-2]*
- **SC-007**: An administrator can locate a specific stock row or movement using the provided filters/search in under 30 seconds on a representative data set. *[FI-2]*
- **SC-008**: The same product variant stored in N warehouses appears as exactly N independent stock rows. *[FI-2]*

## Assumptions

- **Foundation phase delivered first**: The access gate, granular inventory permissions, shared authorization policies, and the service-only mutation guarantee from the foundation phase (FI-0, spec 001) are in place. This feature adds screens on top of them and does not redefine them.
- **Backend prerequisites exist**: The warehouse, location, stock, and movement data models, together with the shared domain logic and validation rules from the backend Products-and-Inventory phase, already exist. Read-only screens surface existing data; master-data screens reuse the existing warehouse validation rules. (Plan Open Question #11.)
- **Read-only means read-only**: Stock levels and the movement ledger are strictly observational in this feature. All stock change continues to flow only through the adjustment/transfer/return/sales flows and their domain logic — none of which is built here.
- **Sanctioned-write navigation is forward-looking**: The stock view communicates that balances change only via adjustments and transfers; the actual navigation to start those flows becomes live when FI-3 and FI-4 ship. Until then the stock view remains fully usable as a read-only screen.
- **Stock balances are never cached**: Consistent with existing architecture rules, the stock and movement views read current data directly and do not cache balances. Stable lookups (such as the warehouse list) may be cached.
- **Cross-module boundary is read-only**: Where a movement references a document owned by another module (catalog, sales, accounting, payments), the inventory area only links to it; it never reaches into another module's write logic.
- **Access scope defaults to System Administrator only**: Consistent with the foundation phase; whether a warehouse/operator role later receives dashboard access remains deferred. (Plan Open Question #7.)
- **Reservations and returns are out of scope here**: Reservation and return screens are a later, conditional phase (FI-5) that depends on decisions still open in the plan.

## Out of Scope

- Any stock-changing screen or action: adjustments, transfers, reservations, returns, and sales-driven movements (later phases FI-3 through FI-5).
- Dashboard widgets, exports, bulk imports, and reports (later phase FI-6).
- Managing product variants or any other catalog data — variants are referenced read-only and owned by the catalog module.
- Editing any cross-module source document (delivery notes, credit notes, etc.).
- Introducing or changing authorization, the audit trail, or the authentication mechanism — all inherited unchanged from the foundation phase.

## Dependencies

- The completed foundation phase (FI-0, spec 001): panel access gate, seeded inventory permissions, shared authorization policies, and the service-only mutation guardrails.
- Backend inventory data models and validation rules for warehouses, locations, stock, and movements (backend Products-and-Inventory phase).
- Existing product-variant catalog data, referenced read-only by stock and movement views.
- The project's standard role/permission system, with the inventory view/manage permissions seeded in the foundation phase.
