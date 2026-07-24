# Feature Specification: Stock Adjustments

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-22

**Status**: Draft

**Input**: User description: "Read the next implement Phase (FI-3 — Stock Adjustments) from Docs/FILAMENT_INVENTORY_DASHBOARD_PLAN.md and create the spec per GitHub Spec Kit best practices."

## Overview

This feature delivers the **first stock-changing screen** in the inventory area, building on the read-only visibility phase (FI-1/FI-2). It lets an administrator correct a physical stock discrepancy — for example, after a physical count reveals more or fewer units than the system shows — through a deliberate, two-part workflow:

- **Prepare (draft)** — an administrator records what needs correcting: the warehouse, a reason, and one line per product variant giving the counted quantity. While the adjustment is a draft, nothing about the actual stock balance changes; the draft can be edited or discarded freely.
- **Apply (confirm)** — a permitted administrator confirms the draft. Confirming is the **single moment** at which stock actually changes: for each item the system records a movement in the ledger and updates the stock balance by exactly the counted difference, together, as one all-or-nothing operation, with an audit record of who applied it.

The core promise of this phase is integrity: an administrator can never move a stock balance by hand or "in place." Every correction leaves a permanent movement in the ledger, is applied atomically (all lines or none), can be authorized separately from who prepared it, and — once applied — is immutable. This is the concrete realization, on a real write flow, of the guarantee the read-only phase only promised: balances change only through a sanctioned flow that records a movement.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Prepare a stock correction as a draft (Priority: P1)

An administrator opens the adjustments area and creates a new adjustment for a chosen warehouse, giving a reason for the correction. They add one line per product variant that needs correcting; for each line the system shows the current on-hand quantity (read-only) and asks only for the newly counted quantity, then shows the resulting difference. The administrator can add, change, or remove lines and can save and reopen the draft later. Throughout, no stock balance and no ledger entry changes — the draft is purely preparatory.

**Why this priority**: Capturing the intended correction is the entry point of the whole flow and delivers standalone value on its own: an administrator can record and refine a count discrepancy for review before anyone commits it to stock. It is the minimum viable slice — a draft that changes nothing is safe to build and demonstrate first.

**Independent Test**: Create a draft adjustment for a warehouse with a reason, add two variant lines with counted quantities, confirm the current quantity and difference are shown per line, edit one line, remove another, save and reopen the draft — then verify that no stock balance and no movement has changed anywhere.

**Acceptance Scenarios**:

1. **Given** an administrator permitted to create adjustments, **When** they create a draft for a warehouse with a reason and at least one item line, **Then** the draft is saved with a system-generated adjustment number and a status of draft, and no stock balance or ledger entry changes.
2. **Given** an item line for a product variant, **When** the administrator selects the variant, **Then** the current on-hand quantity is shown as read-only and only a counted (new) quantity is requested from them.
3. **Given** a counted quantity entered on a line, **When** it differs from the current quantity, **Then** the difference is shown automatically and is not directly editable by the administrator.
4. **Given** a draft adjustment, **When** the administrator adds, edits, or removes item lines and saves, **Then** the changes persist and the adjustment remains a draft that has not touched stock.
5. **Given** an adjustment with no reason or no item lines, **When** the administrator tries to save it in a state that could be applied, **Then** validation prevents it and explains what is missing.

---

### User Story 2 - Apply the correction so stock and the ledger update together (Priority: P2)

A permitted administrator opens a prepared draft and confirms it. In that single act, the system records one stock movement per item line (typed as an adjustment, carrying the signed difference) and updates each affected stock balance by exactly that difference — the movement and the balance change happen together as one all-or-nothing operation, and an audit record captures who applied it and when. After confirmation the adjustment is marked confirmed and the corrected balances and new ledger entries are immediately visible on the read-only stock and movement screens.

**Why this priority**: This is the reason the feature exists — actually correcting stock — and it is where the inventory-integrity guarantee is enforced. It depends on a draft existing (P1), so it comes second, but it is the first place in the whole dashboard where a balance legitimately changes.

**Independent Test**: With a draft that sets variant A from 10 to 13 and variant B from 5 to 2, confirm it, then verify that A's balance increased by 3, B's decreased by 3, exactly two adjustment movements exist carrying +3 and −3, an audit record names the confirming user, and the adjustment now shows as confirmed.

**Acceptance Scenarios**:

1. **Given** a draft adjustment with item lines, **When** a permitted administrator confirms it, **Then** for each line a movement is recorded and the matching stock balance changes by exactly that line's difference, all within a single all-or-nothing operation.
2. **Given** a confirmed adjustment, **When** an administrator views the stock and movement screens, **Then** the corrected balances and the new adjustment-typed movements are visible there.
3. **Given** an adjustment is confirmed, **When** the confirmation completes, **Then** an audit record captures the acting user, the action, and the before/after values.
4. **Given** an item line for a variant that has no existing stock balance in the warehouse, **When** the adjustment is confirmed, **Then** the current quantity is treated as zero, a balance is established, and a movement records the full counted quantity.
5. **Given** an administrator who may create adjustments but is not permitted to apply them, **When** they open a draft, **Then** no control to confirm it is available to them.

---

### User Story 3 - Trust that applied adjustments are integrity-safe and immutable (Priority: P3)

An administrator relies on applied adjustments being final and trustworthy. Once an adjustment is confirmed it can no longer be edited, removed, or applied a second time — attempting any of these is refused. If applying an adjustment fails for a domain reason (for example, the warehouse has been deactivated, or the draft is not in a state that can be applied), the whole operation is abandoned with nothing changed — no partial movements, no partial balance changes — and the administrator is shown a clear message explaining why. Drafts, by contrast, remain fully editable and can be discarded reversibly (recoverable, never permanently destroyed) before they are applied.

**Why this priority**: Immutability and atomicity are what make the corrected numbers believable; without them the whole flow is untrustworthy. It builds on the apply flow (P2) and hardens it, so it is prioritized third while remaining essential to the feature's promise.

**Independent Test**: Confirm an adjustment and verify edit, delete, and re-confirm are all refused; force a domain failure (e.g., confirm against a deactivated warehouse) and verify that no movement and no balance changed and a clear error was shown; then discard a separate draft and verify it is recoverable rather than permanently destroyed.

**Acceptance Scenarios**:

1. **Given** a confirmed adjustment, **When** an administrator looks for controls to edit or delete it, **Then** none are available and the record is immutable.
2. **Given** a confirmed adjustment, **When** anyone attempts to apply (confirm) it again, **Then** the attempt is refused and no additional movement or balance change occurs.
3. **Given** a domain rule prevents applying an adjustment (e.g., an inactive warehouse), **When** confirmation is attempted, **Then** the entire operation is abandoned with no movement and no balance change, and the reason is shown as a clear message.
4. **Given** a draft adjustment that has not been applied, **When** the administrator discards it, **Then** it is removed reversibly (recoverable, not permanently destroyed).
5. **Given** confirmation partially fails partway through the item lines, **When** the operation ends, **Then** no line's movement or balance change is left applied — the result is exactly the pre-confirmation state.

---

### Edge Cases

- **Empty or reasonless adjustment**: An adjustment with no item lines, or with no reason, cannot be applied; validation blocks it and states what is missing before any stock is touched.
- **No change on a line**: A line whose counted quantity equals the current quantity yields a zero difference. Confirming still behaves consistently (a zero-difference line makes no net balance change), and the ledger reflects the operation faithfully without inventing corrections.
- **Variant not yet stocked in the warehouse**: The variant has no existing balance in that warehouse. Its current quantity is treated as zero; confirming establishes the balance and records a movement for the full counted amount.
- **Negative resulting balance**: A counted quantity is below zero, or a difference would drive a balance negative in a way the domain rules forbid. The domain rules decide; if rejected, the whole confirmation is abandoned with nothing changed and a clear message shown.
- **Deactivated warehouse at apply time**: The warehouse was active when the draft was prepared but deactivated before it is applied. Confirmation is refused as a domain error with no partial effect.
- **Double confirmation / concurrent confirm**: The same draft is confirmed twice (including two administrators acting near-simultaneously). Only the first application takes effect; the second is refused, and stock is never adjusted twice for one document.
- **Editing after confirmation**: Any attempt to change item lines, the reason, or the warehouse of a confirmed adjustment is refused — confirmed adjustments are immutable.
- **Preparer without apply permission**: An administrator who can prepare drafts but lacks the separate apply permission sees no confirm control and cannot apply the adjustment, even for a draft they created.

## Requirements *(mandatory)*

> **Inheritance note**: This feature inherits the panel access gate, granular inventory permissions, shared authorization policies, service-only mutation guarantee, non-destructive/audit rules, and validation-reuse approach established in the foundation phase (FI-0, spec 001) and does not redefine them. Requirements below add the adjustment workflow on top of that foundation and the read-only visibility from FI-2 (spec 002).

### Functional Requirements — Preparing an Adjustment (draft)

- **FR-001**: The system MUST allow a permitted administrator to create a stock adjustment against a single warehouse, with a required reason and one or more item lines.
- **FR-002**: The system MUST assign each adjustment a system-generated adjustment number that the administrator cannot edit, and MUST NOT rely on the administrator to supply it.
- **FR-003**: The system MUST record each item line against a product variant, showing the variant's current on-hand quantity as read-only and requesting only the newly counted (new) quantity.
- **FR-004**: The system MUST compute and display each line's difference (counted quantity minus current quantity) automatically, and MUST NOT allow the administrator to set the difference directly.
- **FR-005**: The system MUST require the counted (new) quantity on each line to be a non-negative number.
- **FR-006**: The system MUST allow an adjustment's warehouse, reason, and item lines to be added, edited, and removed only while it is a draft.
- **FR-007**: The system MUST guarantee that while an adjustment is a draft, no stock balance and no ledger entry has changed as a result of it.
- **FR-008**: The system MUST prevent an adjustment that lacks a reason or lacks any item line from being applied, explaining what is missing.

### Functional Requirements — Applying an Adjustment (confirm)

- **FR-009**: The system MUST make confirmation the sole action that changes stock as a result of an adjustment; there MUST be no other path in the inventory area to alter a balance from an adjustment.
- **FR-010**: On confirmation, the system MUST, for each item line, record a stock movement typed as an adjustment carrying the signed difference, and update the corresponding stock balance by exactly that difference.
- **FR-011**: The system MUST apply a confirmation as a single all-or-nothing operation across all item lines, so that either every line's movement and balance change is applied or none is.
- **FR-012**: The system MUST treat a variant with no existing balance in the warehouse as having a current quantity of zero, establishing the balance and recording a movement for the counted quantity on confirmation.
- **FR-013**: The system MUST record an audit entry for each confirmation, capturing the acting user, the action, and the before/after values, attributed to the dashboard channel.
- **FR-014**: The system MUST reflect the corrected balances and the new adjustment movements on the read-only stock and movement screens immediately after confirmation.
- **FR-015**: The system MUST surface a domain failure during confirmation (e.g., an inactive warehouse or an invalid state) as a clear message, while leaving no movement and no balance changed.

### Functional Requirements — Integrity & Lifecycle

- **FR-016**: The system MUST make a confirmed adjustment immutable — its warehouse, reason, and item lines cannot be edited and the record cannot be removed.
- **FR-017**: The system MUST prevent an adjustment from being applied more than once, so a single adjustment document can never change stock twice.
- **FR-018**: The system MUST allow a draft adjustment to be discarded reversibly (recoverable), and MUST NOT permanently destroy any adjustment record.
- **FR-019**: The system MUST make an adjustment's status (draft versus confirmed) visible wherever adjustments are listed or viewed.

### Functional Requirements — Authorization & Segregation of Duties

- **FR-020**: The system MUST gate preparing (creating/editing) an adjustment and applying (confirming) an adjustment behind distinct permissions, so that the ability to apply can be granted independently of the ability to prepare.
- **FR-021**: The system MUST hide the confirm control from, and refuse confirmation by, any administrator who lacks the apply permission — including for a draft they created themselves.
- **FR-022**: The system MUST hide the adjustments area from, and refuse direct access by address to, any administrator lacking the corresponding view/create permission, consistent with the foundation phase.

### Functional Requirements — Discovery

- **FR-023**: The system MUST let administrators list adjustments with their number, warehouse, reason, status, item count, creator, and creation date, and filter that list by status, warehouse, and date range.

### Key Entities *(include if feature involves data)*

- **Stock Adjustment**: A document correcting stock in one warehouse, carrying a system-generated number, a required reason, an acting user, and a workflow status that is a draft until it is confirmed. Editable only while a draft; immutable once confirmed; recoverable rather than permanently destroyed.
- **Stock Adjustment Item**: A single line within an adjustment for one product variant, recording the current quantity (before), the counted quantity (after), and the computed difference between them.
- **Stock Movement**: The immutable ledger entry (owned by the read-only movement model from FI-2) that a confirmed adjustment produces — one per item line, typed as an adjustment and carrying the signed difference.
- **Stock Level**: The current per-variant-and-warehouse balance (from FI-2) that a confirmed adjustment updates by the line difference; never edited directly, only through this flow.
- **Warehouse**: The active/inactive facility (from FI-1) an adjustment targets; an inactive warehouse blocks confirmation.
- **Product Variant**: The catalog item (referenced read-only) each adjustment line corrects; owned by the catalog module, not managed here.
- **Audit Record**: The trace written as a side effect of a confirmation, capturing actor, action, entity, before/after values, and the originating channel.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An administrator can prepare a draft adjustment (warehouse, reason, and multiple variant lines showing current quantity and computed difference) end-to-end with zero change to any stock balance or ledger entry until it is confirmed.
- **SC-002**: On confirming an adjustment, 100% of item lines produce exactly one adjustment movement and change the matching balance by exactly that line's difference — verified against a known before/after data set.
- **SC-003**: Confirmation is atomic in 100% of failure cases: when any part fails, 0 movements and 0 balance changes remain, and the state equals the pre-confirmation state.
- **SC-004**: 100% of attempts to edit, delete, or re-apply a confirmed adjustment are refused, and 0 confirmed adjustments can be changed after the fact.
- **SC-005**: 100% of confirmations write an audit record identifying the acting user and the before/after values.
- **SC-006**: An administrator with prepare permission but without apply permission has 0 available paths to apply an adjustment.
- **SC-007**: Every confirmed adjustment results in exactly as many new ledger movements as it has item lines — never more (no double application) and never fewer (no silently dropped line).
- **SC-008**: An administrator can locate a specific adjustment using the provided status/warehouse/date filters in under 30 seconds on a representative data set.

## Assumptions

- **Foundation and visibility phases delivered first**: The access gate, granular inventory permissions, shared authorization policies, and the service-only mutation guarantee from FI-0 (spec 001), plus the read-only stock and movement screens from FI-2 (spec 002), are in place. This feature adds the first write flow on top of them.
- **Trusted adjustment logic is the sole writer**: The actual stock change on confirmation is performed by the shared trusted domain logic that records the movement and updates the balance together in one transaction with an audit record. The adjustment screen only collects input and applies/observes the result; it never computes balances or writes movements itself. (Plan §2.1, Open Question #11.)
- **Single confirm step (no separate submit/approve stage)**: The workflow is draft → confirmed. Segregation of duties is handled by making "apply/confirm" a distinct permission from "prepare/create" rather than by adding an intermediate submitted-for-approval state. A fuller multi-step approval workflow is out of scope for this phase. (Plan Open Question #10.)
- **Difference is derived, never entered**: Each line's difference is always the counted quantity minus the current quantity; administrators never type a difference, and old quantity is always taken from the current balance at the moment it is needed, not hand-entered.
- **Balances are never cached**: Consistent with existing architecture rules, current quantities shown while preparing and the balances updated on confirmation read/write current data directly rather than a cached copy.
- **Access scope defaults to System Administrator only**: Consistent with the foundation phase; whether any warehouse/operator role receives adjustment access later remains deferred. (Plan Open Question #7.)
- **Adjustment numbering is system-owned**: The adjustment number is generated by the trusted logic and is unique; the UI treats it as read-only.
- **Negative-balance policy lives in the domain rules**: Whether a confirmation may drive a balance negative is decided by the existing domain rules, not by this screen; the screen simply surfaces any refusal as a clear message.

## Out of Scope

- Stock transfers between warehouses (later phase FI-4), reservations and returns (later phase FI-5), and any sales-driven movements.
- Dashboard widgets, exports, bulk/CSV adjustment imports, and reports (later phase FI-6) — this phase covers single, hand-prepared adjustments only.
- A multi-step submit → approve → apply workflow or an intermediate pending-approval state beyond the distinct apply permission described above.
- Managing product variants or any other catalog data — variants are referenced read-only and owned by the catalog module.
- Introducing or changing authorization mechanisms, the audit trail infrastructure, or the authentication mechanism — all inherited unchanged from the foundation phase.
- Editing the read-only stock and movement screens themselves — they continue to expose no write controls; this feature adds a separate adjustment document that feeds them.

## Dependencies

- The completed foundation phase (FI-0, spec 001): panel access gate, seeded inventory permissions (including distinct create/confirm abilities for adjustments), shared authorization policies, and the service-only mutation guardrails.
- The completed visibility phase (FI-2, spec 002): the read-only stock balance and movement ledger screens that this feature's confirmations feed into and that make results observable.
- The shared trusted inventory domain logic and validation rules for adjustments (backend Products-and-Inventory phase) that record the movement, update the balance, generate the adjustment number, and write the audit record inside one transaction. (Plan Open Question #11.)
- Existing warehouse master data (FI-1) and product-variant catalog data, referenced when preparing an adjustment.
- The project's standard role/permission system, with the inventory adjustment permissions seeded in the foundation phase.
