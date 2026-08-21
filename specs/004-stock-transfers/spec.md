# Feature Specification: Stock Transfers

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-23

**Input**: User description: "Read the next implement Phase (FI-4 — Stock Transfers) from Docs/FILAMENT_INVENTORY_DASHBOARD_PLAN.md and create the spec per GitHub Spec Kit best practices."

## Overview

This feature delivers the **second stock-changing screen** in the inventory area, building on the read-only visibility phase (FI-1/FI-2) and mirroring the integrity guarantees proven by stock adjustments (FI-3). It lets an administrator move stock from one warehouse to another through a deliberate, two-part workflow:

- **Prepare (draft)** — an administrator records what needs moving: a source warehouse, a destination warehouse (which must be different from the source), and one line per product variant giving the quantity to move. While the transfer is a draft, no stock balance changes at either warehouse; the draft can be edited or discarded freely.
- **Apply (confirm)** — a permitted administrator confirms the draft. Confirming is the **single moment** at which stock actually moves: for each item the system records a *pair* of movements — a negative movement out of the source and a positive movement into the destination — and updates both stock balances by exactly that quantity, together, as one all-or-nothing operation, with an audit record of who applied it. Before anything is applied, the system checks that the source warehouse actually has enough available stock for every line and refuses the whole transfer if it does not.

The core promise of this phase is a **balanced ledger**: stock is never created or destroyed by a transfer, only relocated. Every unit that leaves one warehouse arrives at another as a matching pair of ledger entries; a transfer can never drive the source negative; confirmation is atomic (both sides of every line or nothing); and — once applied — a transfer is immutable. This extends, to a two-warehouse flow, the same guarantee adjustments established: balances change only through a sanctioned flow that records a movement.

## Clarifications

### Session 2026-07-24

- Q: If a single transfer has two item lines referencing the same product variant (both moving source→destination), how should the system behave? → A: Allow duplicate lines and sum their quantities for the source-availability check; each line still produces its own paired negative/positive movement on confirmation.
- Q: Does this phase include a UI to view and restore discarded (soft-deleted) draft transfers, or is soft-delete data-layer only? → A: This phase ships a full trashed-drafts view plus a restore control (self-service recovery), permission-gated.
- Q: Which transfer lifecycle actions must write an audit record? → A: All of them — create, edit, discard, restore, and confirm each write an audit entry (confirmation additionally captures before/after balance values).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Prepare a stock transfer as a draft (Priority: P1)

An administrator opens the transfers area and creates a new transfer, choosing a source warehouse and a different destination warehouse and optionally adding notes. They add one line per product variant to move, giving the quantity for each. The administrator can add, change, or remove lines and can save and reopen the draft later. Throughout, no stock balance at either warehouse changes and no ledger entry is written — the draft is purely preparatory.

**Why this priority**: Capturing the intended movement is the entry point of the whole flow and delivers standalone value on its own: an administrator can record and refine a planned relocation before anyone commits it to stock. It is the minimum viable slice — a draft that changes nothing is safe to build and demonstrate first.

**Independent Test**: Create a draft transfer from warehouse A to warehouse B with notes, add two variant lines with quantities, edit one line, remove another, save and reopen the draft — then verify that no stock balance at A or B and no movement has changed anywhere.

**Acceptance Scenarios**:

1. **Given** an administrator permitted to create transfers, **When** they create a draft with a source warehouse, a different destination warehouse, and at least one item line, **Then** the draft is saved with a system-generated transfer number and a status of draft, and no stock balance or ledger entry changes.
2. **Given** a transfer form, **When** the administrator selects the same warehouse as both source and destination, **Then** validation prevents saving in an applicable state and explains that source and destination must differ, before any stock is touched.
3. **Given** an item line for a product variant, **When** the administrator sets a quantity that is zero or negative, **Then** validation prevents it and requires a quantity greater than zero.
4. **Given** a draft transfer, **When** the administrator adds, edits, or removes item lines and saves, **Then** the changes persist and the transfer remains a draft that has not touched stock.
5. **Given** a transfer with no destination, no source, or no item lines, **When** the administrator tries to save it in a state that could be applied, **Then** validation prevents it and explains what is missing.

---

### User Story 2 - Apply the transfer so both warehouses update together (Priority: P2)

A permitted administrator opens a prepared draft and confirms it. In that single act, the system first checks that the source warehouse has enough available stock for every line; if so, it records — per item line — one negative movement out of the source and one positive movement into the destination, and updates both stock balances by exactly that quantity. The movements and both balance changes happen together as one all-or-nothing operation, and an audit record captures who applied it and when. After confirmation the transfer is marked confirmed, and the decreased source balances, increased destination balances, and new paired ledger entries are immediately visible on the read-only stock and movement screens.

**Why this priority**: This is the reason the feature exists — actually relocating stock — and it is where the balanced-ledger guarantee is enforced. It depends on a draft existing (P1), so it comes second, but it is the point at which stock legitimately moves between warehouses.

**Independent Test**: With a draft moving quantity 4 of variant A from warehouse X (available ≥ 4) to warehouse Y, confirm it, then verify that X's available balance decreased by 4, Y's balance increased by 4, exactly two linked movements exist carrying −4 (at X) and +4 (at Y), an audit record names the confirming user, and the transfer now shows as confirmed.

**Acceptance Scenarios**:

1. **Given** a draft transfer with item lines and sufficient available stock at the source, **When** a permitted administrator confirms it, **Then** for each line a negative movement is recorded at the source and a positive movement at the destination, and both stock balances change by exactly that line's quantity, all within a single all-or-nothing operation.
2. **Given** a source warehouse whose available quantity for any line is less than the quantity requested, **When** the administrator attempts to confirm, **Then** the transfer is rejected, no movement is created and no balance changes, and the shortfall is shown as a clear message.
3. **Given** a confirmed transfer, **When** an administrator views the stock and movement screens, **Then** the decreased source balances, increased destination balances, and the new transfer-typed movements are visible there.
4. **Given** a transfer is confirmed, **When** the confirmation completes, **Then** an audit record captures the acting user, the action, and the before/after values.
5. **Given** an item line for a variant that has no existing stock balance at the destination, **When** the transfer is confirmed, **Then** a destination balance is established at zero and increased by the moved quantity, with a movement recording the arrival.
6. **Given** an administrator who may create transfers but is not permitted to apply them, **When** they open a draft, **Then** no control to confirm it is available to them.

---

### User Story 3 - Trust that applied transfers are balanced, integrity-safe, and immutable (Priority: P3)

An administrator relies on applied transfers being final, balanced, and trustworthy. Once a transfer is confirmed it can no longer be edited, removed, or applied a second time — attempting any of these is refused. If applying a transfer fails for a domain reason (for example, a warehouse has been deactivated, or the source lacks stock), the whole operation is abandoned with nothing changed at either warehouse — no partial movements, no partial balance changes — and the administrator is shown a clear message explaining why. Every confirmed transfer keeps the ledger balanced: the total quantity leaving the source equals the total arriving at the destination. Drafts, by contrast, remain fully editable and can be discarded reversibly (recoverable, never permanently destroyed) before they are applied.

**Why this priority**: Immutability, atomicity, and ledger balance are what make a relocation believable; without them the flow is untrustworthy and stock could be lost, duplicated, or driven negative. It builds on the apply flow (P2) and hardens it, so it is prioritized third while remaining essential to the feature's promise.

**Independent Test**: Confirm a transfer and verify edit, delete, and re-confirm are all refused; force a domain failure (e.g., confirm against a deactivated destination warehouse, or a source with insufficient stock) and verify that no movement and no balance changed at either warehouse and a clear error was shown; verify that for a successful transfer the summed source decrease equals the summed destination increase; then discard a separate draft and verify it is recoverable rather than permanently destroyed.

**Acceptance Scenarios**:

1. **Given** a confirmed transfer, **When** an administrator looks for controls to edit or delete it, **Then** none are available and the record is immutable.
2. **Given** a confirmed transfer, **When** anyone attempts to apply (confirm) it again, **Then** the attempt is refused and no additional movement or balance change occurs.
3. **Given** a domain rule prevents applying a transfer (e.g., an inactive warehouse or insufficient source stock), **When** confirmation is attempted, **Then** the entire operation is abandoned with no movement and no balance change at either warehouse, and the reason is shown as a clear message.
4. **Given** a draft transfer that has not been applied, **When** the administrator discards it, **Then** it is removed reversibly (recoverable, not permanently destroyed) and appears in a trashed-drafts view from which a permitted administrator can restore it back to an editable draft, with no stock balance or ledger entry ever having changed.
5. **Given** confirmation partially fails partway through the item lines, **When** the operation ends, **Then** no line's source or destination movement or balance change is left applied — the result is exactly the pre-confirmation state.
6. **Given** any confirmed transfer, **When** its resulting movements are totalled, **Then** the total quantity removed from the source equals the total quantity added to the destination (no stock created or lost).

---

### Edge Cases

- **Same source and destination**: A transfer whose source and destination warehouses are identical is rejected by validation before any service call — a transfer must move stock between two *different* warehouses.
- **Insufficient available stock at source**: If, for any line, the source's *available* quantity is less than the requested quantity at confirmation time, the whole transfer is refused with a clear shortfall message and nothing changes. Availability reflects reserved stock: quantity already reserved at the source is not transferable.
- **Zero or negative quantity line**: A line quantity that is not greater than zero is blocked by validation; a transfer moves a positive quantity.
- **Duplicate variant across lines**: The same product variant appears on more than one item line of one transfer. This is permitted; the source-availability check is evaluated against the summed quantity for that variant, and on confirmation each line records its own paired source/destination movement (lines are not merged).
- **Variant not stocked at the destination**: The variant has no existing balance at the destination. Confirming establishes that balance at zero and increases it by the moved quantity, recording an arrival movement.
- **Variant not stocked at the source**: The source has no balance (available zero) for the variant, so any positive line is an insufficient-stock case and the transfer is refused with nothing changed.
- **Deactivated warehouse at apply time**: A source or destination warehouse that was active when the draft was prepared but deactivated before it is applied causes confirmation to be refused as a domain error with no partial effect.
- **Double confirmation / concurrent confirm**: The same draft is confirmed twice (including two administrators acting near-simultaneously). Only the first application takes effect; the second is refused, and stock is never moved twice for one document.
- **Editing after confirmation**: Any attempt to change item lines, the source, the destination, or the notes of a confirmed transfer is refused — confirmed transfers are immutable.
- **Preparer without apply permission**: An administrator who can prepare drafts but lacks the separate apply permission sees no confirm control and cannot apply the transfer, even for a draft they created.
- **Availability changes between preparation and apply**: Stock available at draft time may be consumed by another flow (a sale, another transfer, a reservation) before this transfer is confirmed. The availability check is evaluated at confirmation, not at preparation, so a transfer valid when drafted can still be legitimately refused when applied.

## Requirements *(mandatory)*

> **Inheritance note**: This feature inherits the panel access gate, granular inventory permissions, shared authorization policies, service-only mutation guarantee, non-destructive/audit rules, and validation-reuse approach established in the foundation phase (FI-0, spec 001), the read-only stock and movement visibility from FI-2 (spec 002), and the draft → confirm write-flow pattern proven in stock adjustments (FI-3, spec 003). It does not redefine them. Requirements below add the transfer workflow on top of that foundation.

### Functional Requirements — Preparing a Transfer (draft)

- **FR-001**: The system MUST allow a permitted administrator to create a stock transfer with a source warehouse, a destination warehouse, optional notes, and one or more item lines.
- **FR-002**: The system MUST require the source and destination warehouses to be different and MUST prevent applying a transfer whose source and destination are the same, explaining the constraint before any stock is touched.
- **FR-003**: The system MUST assign each transfer a system-generated transfer number that the administrator cannot edit, and MUST NOT rely on the administrator to supply it.
- **FR-004**: The system MUST record each item line against a product variant with a quantity to move that is greater than zero.
- **FR-005**: The system MUST allow a transfer's source, destination, notes, and item lines to be added, edited, and removed only while it is a draft.
- **FR-006**: The system MUST guarantee that while a transfer is a draft, no stock balance at either warehouse and no ledger entry has changed as a result of it.
- **FR-007**: The system MUST prevent a transfer that lacks a source, a destination, or any item line from being applied, explaining what is missing.

### Functional Requirements — Applying a Transfer (confirm)

- **FR-008**: The system MUST make confirmation the sole action that changes stock as a result of a transfer; there MUST be no other path in the inventory area to relocate a balance from a transfer.
- **FR-009**: The system MUST, before applying any change, verify that the source warehouse's available quantity is at least the requested quantity for every item line, and MUST refuse the entire transfer with a clear shortfall message if any line cannot be satisfied. When two or more item lines reference the same product variant, the availability check MUST be evaluated against the **sum** of those lines' quantities for that variant at the source.
- **FR-009a**: The system MUST allow more than one item line to reference the same product variant within a single transfer; on confirmation each such line produces its own paired source/destination movement (the lines are not merged), while the source-availability check treats them in aggregate per FR-009.
- **FR-010**: On confirmation, the system MUST, for each item line, record one negative stock movement out of the source warehouse and one positive stock movement into the destination warehouse (both typed as a transfer and carrying the moved quantity), and update the corresponding source and destination stock balances by exactly that quantity.
- **FR-011**: The system MUST apply a confirmation as a single all-or-nothing operation across all item lines and both warehouses, so that either every line's paired movements and both balance changes are applied or none is.
- **FR-012**: The system MUST treat a variant with no existing balance at the destination as starting from zero, establishing the balance and increasing it by the moved quantity on confirmation.
- **FR-013**: The system MUST ensure a transfer can never drive the source's available balance below zero.
- **FR-014**: The system MUST record an audit entry for each confirmation, capturing the acting user, the action, and the before/after values, attributed to the dashboard channel.
- **FR-014a**: The system MUST record an audit entry for every transfer lifecycle action — create, edit, discard, restore, and confirm — each capturing the acting user, the action, the affected transfer, and the originating dashboard channel. Confirmation additionally records the before/after stock-balance values per FR-014; the non-confirming actions need not carry balance values (they change no stock).
- **FR-015**: The system MUST reflect the decreased source balances, increased destination balances, and the new transfer movements on the read-only stock and movement screens immediately after confirmation.
- **FR-016**: The system MUST surface a domain failure during confirmation (e.g., an inactive warehouse or insufficient source stock) as a clear message, while leaving no movement and no balance changed at either warehouse.

### Functional Requirements — Integrity & Lifecycle

- **FR-017**: The system MUST make a confirmed transfer immutable — its source, destination, notes, and item lines cannot be edited and the record cannot be removed.
- **FR-018**: The system MUST prevent a transfer from being applied more than once, so a single transfer document can never move stock twice.
- **FR-019**: The system MUST allow a draft transfer to be discarded reversibly (recoverable), and MUST NOT permanently destroy any transfer record.
- **FR-019a**: The system MUST provide permitted administrators a way to view the list of discarded (trashed) draft transfers and to restore a discarded draft back to draft status; restoring MUST return the transfer to an editable draft without having touched any stock balance or ledger entry. Confirmed transfers are never discardable (per FR-017) and therefore never appear as restorable.
- **FR-020**: The system MUST guarantee that, for every confirmed transfer, the total quantity removed from the source equals the total quantity added to the destination (no stock created or lost).
- **FR-021**: The system MUST make a transfer's status (draft versus confirmed) visible wherever transfers are listed or viewed.

### Functional Requirements — Authorization & Segregation of Duties

- **FR-022**: The system MUST gate preparing (creating/editing) a transfer and applying (confirming) a transfer behind distinct permissions, so that the ability to apply can be granted independently of the ability to prepare.
- **FR-023**: The system MUST hide the confirm control from, and refuse confirmation by, any administrator who lacks the apply permission — including for a draft they created themselves.
- **FR-024**: The system MUST hide the transfers area from, and refuse direct access by address to, any administrator lacking the corresponding view/create permission, consistent with the foundation phase.

### Functional Requirements — Discovery

- **FR-025**: The system MUST let administrators list transfers with their number, source and destination warehouses, status, item count, creator, and creation date, and filter that list by status, source/destination warehouse, and date range.

### Key Entities *(include if feature involves data)*

- **Stock Transfer**: A document relocating stock from one warehouse to another, carrying a system-generated number, a source warehouse, a different destination warehouse, optional notes, an acting user, and a workflow status that is a draft until it is confirmed. Editable only while a draft; immutable once confirmed; recoverable rather than permanently destroyed.
- **Stock Transfer Item**: A single line within a transfer for one product variant, recording the quantity to move (greater than zero).
- **Stock Movement**: The immutable ledger entry (owned by the read-only movement model from FI-2) that a confirmed transfer produces — two per item line, typed as a transfer, one negative at the source and one positive at the destination, linked as a pair.
- **Stock Level**: The current per-variant-and-warehouse balance (from FI-2) that a confirmed transfer decreases at the source and increases at the destination; never edited directly, only through this flow. Its *available* quantity (on-hand minus reserved) governs whether a transfer may proceed.
- **Warehouse**: The active/inactive facility (from FI-1) that a transfer moves stock between; both the source and the destination must be active for confirmation, and they must be different.
- **Product Variant**: The catalog item (referenced read-only) each transfer line moves; owned by the catalog module, not managed here.
- **Audit Record**: The trace written as a side effect of a confirmation, capturing actor, action, entity, before/after values, and the originating channel.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An administrator can prepare a draft transfer (source, a different destination, notes, and multiple variant lines with quantities) end-to-end with zero change to any stock balance or ledger entry until it is confirmed.
- **SC-002**: On confirming a transfer with sufficient source stock, 100% of item lines produce exactly one negative movement at the source and one positive movement at the destination and change both balances by exactly that line's quantity — verified against a known before/after data set.
- **SC-003**: For 100% of confirmed transfers, the total quantity removed from the source equals the total quantity added to the destination (net stock change across the two warehouses is zero).
- **SC-004**: 100% of confirmation attempts where any line exceeds the source's available quantity are rejected with a shortfall message and leave 0 movements and 0 balance changes.
- **SC-005**: Confirmation is atomic in 100% of failure cases: when any part fails, 0 movements and 0 balance changes remain at either warehouse, and the state equals the pre-confirmation state.
- **SC-006**: 100% of attempts to edit, delete, or re-apply a confirmed transfer are refused, and 0 confirmed transfers can be changed after the fact.
- **SC-007**: 100% of confirmations write an audit record identifying the acting user and the before/after values, and 100% of the other lifecycle actions (create, edit, discard, restore) also write an audit record identifying the acting user and action.
- **SC-008**: An administrator with prepare permission but without apply permission has 0 available paths to apply a transfer.
- **SC-009**: A transfer can never drive a source balance negative — across all confirmations, 0 source balances end below zero.
- **SC-010**: An administrator can locate a specific transfer using the provided status/warehouse/date filters in under 30 seconds on a representative data set.

## Assumptions

- **Foundation, visibility, and adjustment phases delivered first**: The access gate, granular inventory permissions, shared authorization policies, and the service-only mutation guarantee from FI-0 (spec 001), plus the read-only stock and movement screens from FI-2 (spec 002), are in place. This feature reuses the same draft → confirm write-flow pattern proven by stock adjustments (FI-3, spec 003).
- **Trusted transfer logic is the sole writer**: The actual stock movement on confirmation is performed by the shared trusted domain logic that records the paired movements and updates both balances together in one transaction with an audit record, after checking source availability. The transfer screen only collects input and applies/observes the result; it never computes balances or writes movements itself. (Plan §2.1, Open Question #11.)
- **Single confirm step (no separate dispatch → receive)**: The workflow is draft → confirmed, applied as one atomic relocation. In-transit tracking (a two-step dispatch → receive with an intermediate in-transit state) is out of scope for this phase; the underlying status model carries a single confirmed outcome. (Plan Open Question #9.)
- **Segregation of duties via distinct permissions**: Preparing and applying a transfer are gated by separate permissions, enabling role-level separation without introducing an intermediate submitted-for-approval state. (Plan Open Question #10.)
- **Availability, not on-hand, governs a transfer**: The quantity a transfer may move is bounded by the source's *available* quantity (on-hand minus reserved), so stock reserved for other purposes is not relocated.
- **Balances are never cached**: Consistent with existing architecture rules, available quantities checked at confirmation and the balances updated read/write current data directly rather than a cached copy.
- **Access scope defaults to System Administrator only**: Consistent with the foundation phase; whether any warehouse/operator role receives transfer access later remains deferred. (Plan Open Question #7.)
- **Transfer numbering is system-owned**: The transfer number is generated by the trusted logic and is unique; the UI treats it as read-only.
- **The availability check is evaluated at apply time**: Sufficiency of source stock is decided when the transfer is confirmed, not when the draft is prepared, so a draft may be legitimately refused later if stock has since been consumed.

## Out of Scope

- Stock adjustments (delivered in FI-3), reservations and returns (later phase FI-5), and any sales-driven movements.
- Dashboard widgets, exports, bulk/CSV imports, and reports (later phase FI-6) — this phase covers single, hand-prepared transfers only.
- A two-step dispatch → receive workflow or any intermediate in-transit state, and any partial-receipt handling.
- Managing product variants or any other catalog data — variants are referenced read-only and owned by the catalog module.
- Introducing or changing authorization mechanisms, the audit trail infrastructure, or the authentication mechanism — all inherited unchanged from the foundation phase.
- Editing the read-only stock and movement screens themselves — they continue to expose no write controls; this feature adds a separate transfer document that feeds them.

## Dependencies

- The completed foundation phase (FI-0, spec 001): panel access gate, seeded inventory permissions (including distinct create/confirm abilities for transfers), shared authorization policies, and the service-only mutation guardrails.
- The completed visibility phase (FI-2, spec 002): the read-only stock balance and movement ledger screens that this feature's confirmations feed into and that make results observable.
- The shared trusted inventory domain logic and validation rules for transfers (backend Products-and-Inventory phase) that check source availability, record the paired movements, update both balances, generate the transfer number, and write the audit record inside one transaction. (Plan Open Question #11.)
- Existing warehouse master data (FI-1) and product-variant catalog data, referenced when preparing a transfer.
- The project's standard role/permission system, with the inventory transfer permissions seeded in the foundation phase.
