# Feature Specification: Reservations & Returns

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-23

**Input**: User description: "Read the next implement Phase (FI-5 — Reservations and Returns) from Docs/FILAMENT_INVENTORY_DASHBOARD_PLAN.md and create the spec per GitHub Spec Kit best practices."

## Overview

This feature gives administrators visibility and controlled intervention over two flows that *originate outside the inventory dashboard* but affect inventory: **reservations** (holds placed on stock by the sales flow) and **returns** (stock coming back in, recorded as return-typed ledger movements). Neither is created inside this dashboard — both are produced by the sales/credit-note flows — so this phase deliberately adds **almost no write surface**. It has two related parts:

- **Reservation monitoring and release** — a read-only window onto the stock currently reserved across warehouses (who reserved it, against which sales document, how much, and when it expires), plus a single sanctioned write action: **release** a reservation, which frees the reserved quantity through the trusted domain logic, in one transaction, with an audit record. Reservations are never hand-created or hand-edited here.
- **Returns visibility (interim, read-only)** — a filtered, read-only view of the movement ledger showing return-typed movements, each linking back (read-only) to its originating credit note in the sales module. This phase intentionally does **not** introduce a dedicated returns document or any returns write flow; that is deferred pending a data-model decision.

The core promise of this phase is the same integrity guarantee applied to observation and a single narrow intervention: administrators can *see* reserved and returned stock accurately, and can *release* a reservation only through the trusted flow that adjusts the balance and writes an audit entry — never by hand-editing a balance, a reservation, or a movement.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Monitor reserved stock across the warehouse network (Priority: P1)

An administrator opens the reservations area and sees every active hold on stock: the product variant, the warehouse, the reserved quantity, the sales document that caused the reservation (quotation, order, or delivery — shown as a read-only reference), when it expires, its status, and who created it. They can filter by status, warehouse, source type, and whether the reservation has expired. Nothing on this screen creates or edits a reservation — it is purely a monitoring window.

**Why this priority**: Visibility of what is reserved (and therefore not available) is the foundational value of this phase and is safe by construction — it changes nothing. An administrator can diagnose why available stock is lower than on-hand stock, and spot stale or expired holds, before any intervention is offered. It is the minimum viable slice.

**Independent Test**: With reservations created by the sales flow present, open the reservations list and confirm each shows its variant, warehouse, reserved quantity, source reference, expiry, status, and creator; apply the status, warehouse, source-type, and expired filters and confirm the list narrows correctly — all without any create or edit control being present.

**Acceptance Scenarios**:

1. **Given** reservations produced by the sales flow, **When** an administrator opens the reservations area, **Then** each reservation is listed read-only with its variant, warehouse, reserved quantity, source reference, expiry, status, and creator.
2. **Given** the reservations list, **When** the administrator looks for a control to create or hand-edit a reservation, **Then** none exists — reservations originate only from the sales flow.
3. **Given** a set of reservations with differing statuses, warehouses, source types, and expiry, **When** the administrator applies the corresponding filters, **Then** only the matching reservations are shown.
4. **Given** a reservation caused by a sales document, **When** the administrator views it, **Then** the source is shown as a read-only reference to that document and cannot be edited from here.

---

### User Story 2 - Release a reservation to free reserved stock (Priority: P2)

A permitted administrator releases a reservation that is no longer needed (for example, a quotation that will not proceed, or a stale hold that should be freed). Releasing frees the reserved quantity through the trusted domain logic — the reservation is marked released and the affected stock's reserved quantity decreases (raising available quantity accordingly) — as one all-or-nothing operation, with an audit record naming who released it. The change is immediately visible on the read-only stock screen. An already-released or expired reservation cannot be released again.

**Why this priority**: Release is the single legitimate intervention this phase offers and the only reason a write path exists. It depends on being able to see reservations (P1), so it comes second, but it delivers the concrete operational value of recovering wrongly-held stock.

**Independent Test**: Take an active reservation holding quantity R of a variant at a warehouse, release it, then verify the reserved quantity for that variant/warehouse decreased by R, available increased by R, the reservation shows as released, an audit record names the acting user, and attempting to release it a second time is refused with no further change.

**Acceptance Scenarios**:

1. **Given** an active reservation and an administrator permitted to release, **When** they release it, **Then** the reserved quantity is freed through the trusted domain logic in a single all-or-nothing operation and the reservation is marked released.
2. **Given** a reservation is released, **When** the release completes, **Then** an audit record captures the acting user, the action, and the before/after values, attributed to the dashboard channel.
3. **Given** a released reservation, **When** the administrator views the read-only stock screen, **Then** the freed quantity is reflected as reduced reserved and increased available immediately.
4. **Given** a reservation that is already released or expired, **When** anyone attempts to release it, **Then** the attempt is refused and no balance change occurs.
5. **Given** an administrator who may view reservations but is not permitted to release them, **When** they open a reservation, **Then** no control to release it is available to them.
6. **Given** a domain rule prevents releasing a reservation, **When** release is attempted, **Then** the operation is abandoned with no balance change and the reason is shown as a clear message.

---

### User Story 3 - Review returned stock in the movement ledger (Priority: P3)

An administrator reviews stock that has come back in — returns — as a read-only, filtered view of the movement ledger showing only return-typed movements. Each return movement links back (read-only) to the credit note in the sales module that produced it. This phase provides no way to create, edit, or reverse a return from the dashboard, and it adds no dedicated returns document; it is a lens on existing ledger data so the reserved sidebar slot for returns is meaningful rather than broken.

**Why this priority**: Returns visibility is genuinely useful but is the least urgent part of this phase and is intentionally minimal because the returns data model is not yet settled. Delivering it as a read-only ledger view avoids committing to a design prematurely while still giving administrators a place to see returns.

**Independent Test**: With return-typed movements present in the ledger, open the returns view and confirm only return movements are shown, each with its variant, warehouse, quantity, date, and a read-only link to its source credit note; confirm no create, edit, delete, or reverse control exists anywhere in the view.

**Acceptance Scenarios**:

1. **Given** return-typed movements exist in the ledger, **When** an administrator opens the returns view, **Then** only return-typed movements are listed, read-only, with variant, warehouse, quantity, date, and status.
2. **Given** a return movement produced by a credit note, **When** the administrator views it, **Then** the source is shown as a read-only cross-module link to that credit note and cannot be edited from here.
3. **Given** the returns view, **When** the administrator looks for a control to create, edit, delete, or reverse a return, **Then** none exists — this phase adds no returns write flow.

---

### Edge Cases

- **Manual reservation creation attempted**: There is no path to create or hand-edit a reservation in the dashboard; reservations are produced only by the sales flow. Any such control is absent by design.
- **Double release / concurrent release**: The same reservation is released twice (including two administrators acting near-simultaneously). Only the first release takes effect; the second is refused, and stock is never freed twice for one reservation.
- **Releasing an expired reservation**: An expired reservation is treated as no longer holding stock; releasing it (or its automatic expiry) frees no more than the quantity still held, and cannot free stock twice.
- **Reservation whose source document is in another module**: The source (quotation, order, delivery, or credit note) belongs to the sales module and is always shown as a read-only reference — never an editable relation from the inventory dashboard.
- **Release domain failure**: If a domain rule prevents releasing a reservation, the whole operation is abandoned with no balance change and a clear message is shown.
- **Returns without a dedicated store**: Because this phase adds no returns table or document, the returns view derives entirely from return-typed movements; it shows returns but offers no action that would change a balance.
- **Reservations not yet surfaced in navigation**: Reservations have no existing navigation entry or labels; surfacing this area requires a small, reviewable addition of a navigation entry and its labels (including Arabic), called out as a dependency.

## Requirements *(mandatory)*

> **Inheritance note**: This feature inherits the panel access gate, granular inventory permissions, shared authorization policies, service-only mutation guarantee, non-destructive/audit rules, and validation-reuse approach established in the foundation phase (FI-0, spec 001) and the read-only movement ledger from FI-2 (spec 002). It does not redefine them. Requirements below add reservation monitoring/release and an interim read-only returns view on top of that foundation.

### Functional Requirements — Reservation Visibility

- **FR-001**: The system MUST let a permitted administrator list stock reservations read-only, showing each reservation's product variant, warehouse, reserved quantity, source reference (the originating sales document), expiry, status, and creator.
- **FR-002**: The system MUST provide **no** path to create or hand-edit a reservation in the dashboard; reservations originate only from the sales flow.
- **FR-003**: The system MUST let administrators filter the reservations list by status, warehouse, source type, and whether the reservation has expired.
- **FR-004**: The system MUST render each reservation's source (quotation, order, or delivery) as a read-only cross-module reference that cannot be edited from the inventory dashboard.

### Functional Requirements — Reservation Release

- **FR-005**: The system MUST make **release** the only write action on a reservation, and MUST perform the release through the shared trusted domain logic that frees the reserved quantity in a single all-or-nothing operation with an audit record.
- **FR-006**: On release, the system MUST decrease the affected stock's reserved quantity (raising available quantity accordingly) by exactly the quantity the reservation still holds, and MUST reflect this on the read-only stock screen immediately.
- **FR-007**: The system MUST prevent releasing a reservation that is already released or expired, so a single reservation can never free stock twice.
- **FR-008**: The system MUST record an audit entry for each release, capturing the acting user, the action, and the before/after values, attributed to the dashboard channel.
- **FR-009**: The system MUST surface a domain failure during release as a clear message, while leaving no balance changed.
- **FR-010**: The system MUST gate viewing reservations and releasing reservations behind distinct permissions, and MUST hide the release control from, and refuse release by, any administrator lacking the release permission.

### Functional Requirements — Returns (interim read-only view)

- **FR-011**: The system MUST surface returns as a read-only view of the movement ledger filtered to return-typed movements, showing each return's product variant, warehouse, quantity, date, and status.
- **FR-012**: The system MUST render each return movement's source as a read-only cross-module link to its originating credit note in the sales module, not an editable relation.
- **FR-013**: The system MUST provide **no** control to create, edit, delete, or reverse a return, and MUST NOT introduce any returns write flow or balance-changing action in this phase.

### Functional Requirements — Access & Non-Destruction

- **FR-014**: The system MUST hide the reservations and returns areas from, and refuse direct access by address to, any administrator lacking the corresponding view permission, consistent with the foundation phase.
- **FR-015**: The system MUST never permanently destroy a reservation record or any ledger movement from these areas; the only state change available is releasing a reservation through the trusted flow.

### Key Entities *(include if feature involves data)*

- **Stock Reservation**: A hold on a quantity of one product variant at one warehouse, created by the sales flow and referencing its originating sales document, carrying a reserved quantity, an expiry, a status, and a creator. Never hand-created or hand-edited in the dashboard; its only dashboard-driven state change is release.
- **Stock Movement (return-typed)**: The immutable ledger entry (owned by the read-only movement model from FI-2) representing stock returned in; surfaced here read-only and linked to its source credit note.
- **Stock Level**: The current per-variant-and-warehouse balance (from FI-2) whose reserved and available quantities change when a reservation is released; never edited directly, only through the trusted flow.
- **Warehouse**: The facility (from FI-1) a reservation or return is associated with; referenced read-only here.
- **Product Variant**: The catalog item (referenced read-only) a reservation holds or a return concerns; owned by the catalog module, not managed here.
- **Source Document**: The sales-module document (quotation, order, delivery, or credit note) that caused a reservation or return; always shown as a read-only cross-module reference and never editable from the inventory dashboard.
- **Audit Record**: The trace written as a side effect of a release, capturing actor, action, entity, before/after values, and the originating channel.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An administrator can view every active reservation with its variant, warehouse, reserved quantity, source reference, expiry, status, and creator, with 0 create or hand-edit controls present.
- **SC-002**: An administrator can locate a specific reservation using the provided status/warehouse/source-type/expired filters in under 30 seconds on a representative data set.
- **SC-003**: On releasing a reservation, 100% of the still-held quantity is freed through the trusted flow — reserved decreases and available increases by exactly that amount — verified against a known before/after data set.
- **SC-004**: 100% of attempts to release an already-released or expired reservation are refused, and 0 reservations free stock more than once.
- **SC-005**: 100% of releases write an audit record identifying the acting user and the before/after values.
- **SC-006**: An administrator with view permission but without release permission has 0 available paths to release a reservation.
- **SC-007**: The returns view shows 100% of return-typed movements and 0% of non-return movements, each with a read-only link to its source credit note, and exposes 0 create/edit/delete/reverse controls.
- **SC-008**: 0 reservation records or ledger movements can be permanently destroyed from these areas.

## Assumptions

- **Foundation and visibility phases delivered first**: The access gate, granular inventory permissions, shared authorization policies, and the service-only mutation guarantee from FI-0 (spec 001), plus the read-only movement ledger from FI-2 (spec 002), are in place. This feature adds monitoring and a single release action on top of them.
- **Reservations originate in the sales flow**: Reservations are created and maintained by the sales/quotation/order/delivery flows, not the inventory dashboard. This phase only observes them and offers release. (Plan §8.)
- **Trusted reservation logic is the sole writer**: Releasing a reservation is performed by the shared trusted domain logic, which frees the reserved quantity (and records whatever movement/adjustment it defines) in one transaction with an audit record. The dashboard only triggers the release and observes the result. (Plan §2.1, Open Question #11.)
- **Reservations require a small enabling navigation/label addition**: Reservations currently have no navigation entry or labels. Surfacing this area involves a small, reviewable addition of a navigation entry plus English and Arabic labels; this is treated as a dependency of the phase, adopting the plan's recommended resolution. (Plan Open Question #3.)
- **Returns are interim and read-only**: This phase adopts the plan's recommended resolution for returns — a read-only view over return-typed movements — and explicitly defers a dedicated returns document/resource until the returns data model is decided. No returns write flow is introduced. (Plan Open Question #4.)
- **Access scope defaults to System Administrator only**: Consistent with the foundation phase; whether any warehouse/operator role receives reservation-release access later remains deferred. (Plan Open Question #7.)
- **Balances are never cached**: Consistent with existing architecture rules, reserved/available quantities shown and updated read/write current data directly rather than a cached copy.

## Out of Scope

- Creating, editing, or hand-adjusting reservations — reservations are owned by the sales flow; this phase only monitors and releases them.
- A dedicated returns document or resource, and any returns write flow (create/edit/reverse) — deferred pending a returns data-model decision; this phase ships only a read-only returns view. (Plan Open Question #4.)
- Stock adjustments (FI-3) and transfers (FI-4), and any sales-driven creation of reservations or credit notes.
- Dashboard widgets, exports, and reports (later phase FI-6).
- Managing product variants or any other catalog data, and editing the sales-module documents referenced as sources — all referenced read-only and owned by other modules.
- Introducing or changing authorization mechanisms, the audit trail infrastructure, or the authentication mechanism — all inherited unchanged from the foundation phase.
- Automatic expiry policy design (when and how reservations expire) beyond reflecting expiry status and preventing a second release — the expiry mechanism itself is owned by the sales/reservation domain logic.

## Dependencies

- The completed foundation phase (FI-0, spec 001): panel access gate, seeded inventory permissions (including distinct view/release abilities for reservations), shared authorization policies, and the service-only mutation guardrails.
- The completed visibility phase (FI-2, spec 002): the read-only movement ledger that the returns view filters and that release results are observable against, plus the read-only stock screen that reflects freed quantities.
- The shared trusted reservation domain logic (backend Products-and-Inventory phase) that frees the reserved quantity and writes the audit record inside one transaction on release. (Plan Open Question #11.)
- A small, reviewable addition surfacing reservations in navigation with English and Arabic labels. (Plan Open Question #3.)
- The sales flow that creates reservations and the credit-note flow that produces return-typed movements, both referenced read-only.
- The project's standard role/permission system, with the inventory reservation permissions seeded in the foundation phase.
