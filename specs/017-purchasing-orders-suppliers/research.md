# Phase 0 Research: Purchasing — Purchase Orders and Supplier Confirmations

**Feature**: `017-purchasing-orders-suppliers` | **Date**: 2026-08-18

Each entry records a decision, the alternatives considered, and the rationale. Decisions are referenced from `plan.md`, `data-model.md`, and `tasks.md` by their R-number.

---

## R-001 — Receiving reuses `InventoryOperation`; purchasing writes no stock

**Decision**: A purchase-order receipt is an existing `InventoryOperation` with `operation_type = receipt`, carrying the purchase order in its already-present nullable `source_document` morph. `App\Services\Purchasing\*` never touches `inventory_stocks` or `inventory_movements`; it calls `InventoryOperationService` and reacts to completion.

**Alternatives considered**:

- *A new `PurchaseReceipt` model with its own stock posting.* Rejected outright. It would create a second stock-writing path, directly violating Principle III's "every stock-changing action MUST create a corresponding inventory movement" by duplicating the logic that guarantees it. It would also fork lot, serial, expiry, and unit-conversion handling.
- *Extending `InventoryReceipt` (the legacy receipt model).* Rejected. `InventoryOperation` supersedes it — `2026_07_27_130002_backfill_inventory_operations_from_legacy_documents.php` migrates the legacy tables forward, and `OperationBackfillReconciler` exists to retire them. Building new work on the deprecated model moves in the wrong direction.

**Rationale**: The seam is not merely available, it was designed for exactly this. `InventoryOperation::sourceDocument()`'s docblock already reads *"The originating commercial document — a purchase order for a receipt, a sales delivery note for a delivery (FR-012)"*, and `inventory_operations` already carries `supplier_id` and `supplier_reference` columns whose only current writer is `InventoryOperationBackfiller`, copying them forward from legacy `inventory_receipts` — no live user-facing flow populates them, because only a purchasing flow would. The morph is proven in production by `OrderFulfillmentService:269`, which sets `source_document_type => Order::class` for deliveries; the inbound half has no writer at all. Purchasing is the mirror-image case on the inbound side.

**Consequence for tests**: An architecture test asserts that no class under `App\Services\Purchasing` or `App\Filament\Resources\PurchaseOrders` references `InventoryStock`, `InventoryMovement`, or `InventoryBalanceService`. This makes SC-002 mechanically enforced rather than review-dependent.

---

## R-002 — Received quantity advances on operation completion, via a domain event

**Decision**: `InventoryOperationService::complete()` already runs inside a transaction. Purchasing observes completion and, inside that same transaction, locks the purchase-order lines and increments `quantity_received`. The link is made by a listener on an operation-completed event rather than by editing `InventoryOperationService` to know about purchasing.

**Alternatives considered**:

- *Calling a purchasing method directly from `InventoryOperationService`.* Rejected — it inverts the dependency, making Inventory depend on Purchasing. Principle II's folder-level domain boundaries exist precisely to prevent this.
- *An Eloquent observer on `InventoryOperation` watching `stage` changes.* Rejected as too broad: it fires for every stage change on every operation type, and reconstructing "this was a completion of a purchase-order receipt" from dirty attributes is fragile.
- *A queued listener.* Rejected. Received quantity must be consistent with stock the moment the transaction commits; deferring it opens a window where stock exists but the order still shows nothing received, and a job failure would leave them permanently divergent.

**Rationale**: A synchronous, same-transaction listener keeps stock and received quantity atomically consistent — the FR-039/FR-041 invariant — while keeping the dependency arrow pointing Purchasing → Inventory only.

**Note for implementation**: `InventoryOperationService` currently emits no completion event. Adding one is a small, additive change to Inventory that introduces no purchasing knowledge, which is acceptable under Principle II. If review prefers zero change to Inventory, the fallback is a purchasing-owned observer narrowly scoped to `source_document_type === PurchaseOrder::class`.

---

## R-003 — Over-receipt is blocked with a pessimistic row lock

**Decision**: Before incrementing, the listener issues `SELECT ... FOR UPDATE` over the affected purchase-order lines, then rejects the whole completion if any line's `quantity_received + incoming > quantity_ordered`.

**Alternatives considered**:

- *Optimistic check without a lock.* Rejected — two concurrent completions both read a stale `quantity_received` and both pass, producing over-receipt. This is exactly the FR-041 concurrency case.
- *A database CHECK constraint.* Rejected as insufficient alone: MySQL's error surfaces as an opaque driver exception, so FR-040's requirement to *name the offending line* cannot be met. Considered as defence-in-depth, but the ordering guarantee still needs the lock.
- *Allowing configurable over-receipt tolerance.* Rejected per D7 — the owner chose hard blocking. Tolerance is a future setting, not a hidden default.

**Rationale**: This mirrors how `InventoryOperationService` already locks operations before stage transitions (`$locked` in its `complete()` path), so the codebase has one consistent concurrency idiom rather than two.

---

## R-004 — Threshold approval is evaluated at submission, never retroactively

**Decision**: `PurchaseOrderApprovalService::submit()` reads the threshold at call time and branches. `approved_at` / `approved_by` are written for auto-approvals too. Changing the threshold does not re-evaluate anything already submitted.

**Alternatives considered**:

- *Storing the threshold on the order at draft time.* Rejected — a draft edited over days would carry a stale threshold, and the natural reading of "the rule in force when I committed" is the rule at submission.
- *Re-evaluating pending orders when the threshold changes.* Rejected explicitly by FR-024. Silently approving a waiting order because a setting moved is an audit hole.
- *Leaving `approved_by` null for auto-approvals.* Rejected — SC-005 requires every state change be attributable. Auto-approval attributes to the submitter, which is the truthful record of who caused it.

**Rationale**: Submission is the commitment point. Anchoring the decision there gives a single, defensible, auditable rule.

---

## R-005 — Separation of duties on above-threshold approval

**Decision**: The submitter may not approve their own above-threshold order. System Admin is exempt, matching how `DashboardRole`-aware policies already grant admin bypass across modules.

**Alternatives considered**:

- *No separation.* Rejected — it makes the threshold decorative, since one user could submit and immediately self-approve any amount.
- *No admin exemption.* Rejected — a single-admin deployment would deadlock with no way to approve anything.

**Rationale**: This is the minimum viable financial control, and it reuses the established admin-bypass shape rather than inventing a new escape hatch.

---

## R-006 — Two new fixed dashboard roles registered centrally

**Decision**: Add `PurchasingManager` and `PurchasingOfficer` to `App\Enums\DashboardRole`. Add a `purchase.*` catalogue as `App\Enums\PurchasePermission`, a `ChecksPurchasePermissions` policy trait, and a `PurchasePermissionSeeder`, following the Support module's shape exactly.

**Alternatives considered**:

- *Reusing Inventory permissions.* Rejected — FR-008 requires a purchasing user to receive against a purchase order without gaining access to Inventory Operations, Adjustments, or Stock Levels. Sharing the permission namespace makes that separation impossible.
- *A `purchasing.*` prefix.* Rejected in favour of `purchase.*` for consistency with the singular-noun style of `inventory.*`, `support.*`, and `crm.*`.

**Rationale**: `DashboardRole`'s own docblock states its purpose — adding one module's fixed role automatically narrows every other module's admin bypass. Registering there is required for correctness across modules, not merely tidy.

**Cross-module consequence (must be tested)**: Adding two cases to `DashboardRole` changes the behaviour of every existing `isAdmin() && ! hasAnyRole(fixedRoleNames())` check. A user who is an admin *and* holds a purchasing role loses bypass in CRM, Employees, Inventory, and Support. Regression tests for the other four modules must be run, not assumed.

---

## R-007 — Supplier confirmation is a polymorphic, append-only record

**Decision**: `supplier_confirmations` uses `confirmable_type` / `confirmable_id`, restricted at the service layer to `PurchaseOrder` and `Order`. A confirmation whose status is confirmed or rejected is immutable; corrections append a new row.

**Alternatives considered**:

- *Two separate tables.* Rejected — duplicated lifecycle, duplicated policy, duplicated UI, for one differing column.
- *A nullable `order_id` plus a nullable `purchase_order_id`.* Rejected — it permits the illegal both-set and neither-set states, requiring a check constraint to express what a morph expresses natively.
- *Mutable confirmations.* Rejected by FR-031. The supplier's original answer is evidence; overwriting it destroys the history that makes the receiving-performance report meaningful.

**Rationale**: Append-only matches how the codebase already treats consequential records (`PriceHistory`, `TaskStatusLog`) and satisfies the ERD's `confirmed_by` / `confirmed_at` fields naturally.

**ERD note**: The morph replaces the ERD's `order_id`, and the ERD's redundant generic `status` column is dropped in favour of `confirmation_status` alone. Both are registered as E-3 and E-4 in the spec.

---

## R-008 — Stored totals, not computed accessors

**Decision**: `line_total` and the order's `total_amount` are stored columns, recomputed by a service whenever lines change while the order is a draft, and frozen thereafter.

**Alternatives considered**:

- *Computed accessors.* Rejected — the open-commitments report (FR-051, SC-007) aggregates across all non-terminal orders; computing per row makes it unindexable and forces PHP-side summation.
- *A database generated column.* Rejected — the order-level total spans rows, which a generated column cannot express, and mixing the two mechanisms is worse than either.

**Rationale**: This matches how `InventoryReceiptItem` already stores `purchase_cost` rather than deriving it, and it makes the frozen-after-transmission requirement (FR-025) trivially true: the number that was approved is the number that is stored.

---

## R-009 — Cost writeback is a direct copy with an audit trail

**Decision**: On receipt completion, each line's received unit cost overwrites the matching `SupplierProductReference.purchase_cost` and `currency_code`, creating the reference if absent. The prior value is preserved by the activity log, not by a new history table.

**Alternatives considered**:

- *A dedicated `supplier_cost_history` table.* Rejected as premature — no requirement reads a cost time series, and ADR 0005's activity log already captures old and new attribute values.
- *A moving-average cost.* Rejected — averaging requires landed cost (freight, duty), which the spec places out of scope. A misleading average is worse than a plain last-paid price.
- *No writeback.* Rejected — FR-048 requires it, and stale reference costs are the main reason PO drafting degrades over time.

**Rationale**: Last-paid price is honest about what it is, needs no new schema, and satisfies FR-048 through FR-050 exactly.

---

## R-010 — Filament resource shape follows the Inventory Operations precedent

**Decision**: `PurchaseOrderResource` uses the full page set (List / Create / Edit / View) with a `Schemas` + `Tables` + `RelationManagers` subdirectory split, matching `AdjustmentResource` and `InventoryOperationResource`. `SupplierConfirmationResource` and `SupplierProductReferenceResource` use the simpler single-page `ManageX` shape, matching `SupplierResource`.

**Alternatives considered**:

- *A wizard for purchase-order creation.* Rejected — `DeliveryWizardTest` shows wizards exist here for genuinely multi-stage flows. Drafting a PO is one form with a repeater.
- *Everything as `ManageX` modals.* Rejected — a purchase order needs a View page for the lines, the confirmation history, the linked receipts, and the audit trail.

**Rationale**: Two established shapes already exist in this codebase for exactly these two levels of complexity; no third pattern is warranted.

---

## R-011 — Reports join the existing Reports group, not a purchasing sub-navigation

**Decision**: The three reports are delivered as a `PurchasingReportResource` registered under the existing `reports` navigation group, alongside `InventoryReportResource`, `OperationalReportResource`, `EmployeeReportResource`, and `SupportReportResource`.

**Alternatives considered**:

- *A "Reporting" section inside the purchasing group.* Rejected — the Inventory group has sections because it holds fifteen items; purchasing holds four.
- *Filament widgets on the main dashboard.* Deferred, not rejected. Widgets can be added later without schema change; the reports are the requirement.

**Rationale**: `AdminModuleRegistry` already establishes that every module's reports live together in the `reports` group. Following it keeps the sidebar coherent.

---

## R-012 — The `orders` table gains only `pending_reason`

**Decision**: Add one nullable `pending_reason` column to `orders`, plus the three supplier-related status values. The ERD's `supplier_id`, `payment_status`, and `grand_total` are **not** added.

**Alternatives considered**:

- *Adding all four ERD columns.* Rejected — `payment_status` and `grand_total` belong to the sales/accounting feature. Adding unused financial columns now would let them drift or be half-populated before that feature defines their semantics.
- *Adding `supplier_id` to `orders`.* Rejected as redundant: the supplier is already carried by the confirmation record, and a customer order could be sourced from more than one supplier over time.

**Rationale**: Principle II forbids unrelated refactors when delivering a feature. `pending_reason` is the minimum needed for FR-033; the rest is another feature's scope.

---

## R-013 — Numbering reuses the established sequence approach

**Decision**: Purchase-order numbers are assigned inside the creating transaction using the same mechanism as `operation_number` and `order_number`, with a unique index as the final guarantee.

**Alternatives considered**:

- *UUID or ULID.* Rejected — FR-011 requires a human-readable number a buyer can quote to a supplier by phone.
- *Application-level `max() + 1`.* Rejected — it races, and FR-011 explicitly requires distinct numbers under concurrent creation.

**Rationale**: Three existing tables already solve this. A fourth solution would be gratuitous divergence.

---

## Open Items Carried Into Planning

| Item | Status | Owner |
|------|--------|-------|
| ADR 0006 authoring and approval | **Blocking** — no implementation task may start first | Project owner |
| Constitution amendment to 1.6.0 | **Blocking** | Project owner |
| `Docs/database/ERD.md` update with E-1…E-6 | **Blocking**, per Principle I | Implementer, owner-approved |
| `Docs/PRD.md` §11 exception list update | **Blocking** | Implementer, owner-approved |
| Whether `InventoryOperationService` emits a completion event or Purchasing observes narrowly (R-002) | Non-blocking; decide at implementation, both satisfy the requirements | Implementer |
| Default approval threshold value and currency for the seeder | Non-blocking; seed as `0` (everything requires approval) until the owner sets it | Implementer |
