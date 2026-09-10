# ERP Cross-Module Remediation Plan — Purchasing → Replenishment → Logistics → Accounting → Supplier Returns

**Document type:** Implementation-ready roadmap, adapted from an external source plan to this codebase's actual structure
**Source:** `ERP_PURCHASING_REPLENISHMENT_LOGISTICS_BILLS_SUPPLIER_RETURNS_REMEDIATION_PLAN_UPDATED.md` (external; the business decisions and rules it states are authoritative — this document says how they map onto `C:\laragon\www\ierp-new` specifically, what's already done, and what's left)
**Branch:** `feat/phase0-po-lifecycle` (off `dev` @ `b2eeef2`)
**Supersedes:** `ERP_PHASE0_REMEDIATION_STATUS.md` (folded into §Phase 0 below; that file has been removed)
**Last updated:** 2026-09-10

---

## 0. How to read this document

The source plan states **what** to build, in generic terms, and **why**. This document states, for
this specific codebase:

- what already satisfies a requirement (✅ — cite the file);
- what partially satisfies it and needs finishing (🟡);
- what is genuinely net-new work (⬜);
- where the source plan's generic suggestion conflicts with, or is already better served by, an
  existing convention in this codebase — and which one wins, with a reason.

Every phase below keeps the source plan's numbering (Phase 0–10) and its section references (e.g.
"§27" = the source document's numbered section) so the two documents can be read side by side.

**Do not confuse this with `ERP_REMEDIATION_PLAN.md` / `ERP_IMPLEMENTATION_PHASES.md`.** Those track
a separate, earlier remediation effort (work packages WP-1.x–WP-4.x) that this branch's Phase 0
reconciled against and, on four specific points, superseded. This document tracks only the plan
named above.

### 0.1 Ground rules (apply to every phase)

- Pre-production app — destructive migrations are fine; no backfill/compat shims. Every phase ends
  with a clean `php artisan migrate:fresh --seed`.
- Every PR, before merge: `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse`
  (baseline is 347 pre-existing errors as of this branch's start — **zero new errors**, ever),
  `vendor/bin/pest` (including `tests/Unit/ArchTest.php`), then `php artisan migrate:fresh --seed`.
- New/changed behavior ships with a Pest test in the same PR. Small, reviewable PRs — one concern
  each, per `CLAUDE.md`.
- A later phase's admission test is "the phase before it is fully shipped and its exit gate is
  green." No phase starts early just because its own dependencies happen to be ready.
- Commits are created only when explicitly requested.

### 0.2 What the codebase survey changed vs. the source plan's assumptions

Before writing Phases 1–10 below, the actual state of this codebase was audited so the plan doesn't
propose rebuilding what already exists, or inventing new patterns where an established one already
fits. Four findings materially change how later phases should be scoped:

1. **Supplier returns already have a working physical layer.** `InventoryReturn` /
   `InventoryReturnLine` already distinguish `InventoryReturnType::Customer` /
   `::Supplier`, and `InventoryReturnService::createSupplierReturn()` / `addSupplierLine()` already
   validate provenance against a receipt line and post negative stock with supplier custody. **Phase
   7 is not "build supplier returns" — it is "build the missing commercial aggregate
   (`SupplierReturnCase`) in front of a physical layer that already works."** This cuts Phase 7's
   scope roughly in half.
2. **Internal transfers already exist end-to-end** via `InventoryOperationService` /
   `OperationType::InternalTransfer`. Nothing suggests one automatically. **Phase 2's "internal
   transfer before external purchase" is purely the suggestion layer** (§7) — not transfer execution.
3. **`PurchasingReportService::costVariance()` is dead code as of this branch's Phase-0 PR5** (it
   compared `PurchaseOrderLine.unit_cost` against `last_received_unit_cost`, which nothing writes
   anymore since receipts carry no cost). **Phase 4/6 must replace it, not extend it** — the real
   variance signal only exists once a Bill is matched against a PO.
4. **There is no JSON API surface at all** (no `routes/api.php`, no API Resource classes — Filament
   dashboard only). The source plan's §77 ("API Contracts") does not apply to this codebase unless a
   REST API is explicitly requested as separate, new scope. It is **omitted below**, not deferred.

Two designed-not-blocking gaps found by the survey, left as-is on purpose:

- **No `DashboardRole::InventoryManager` case exists** — Inventory authorization is entirely
  `InventoryPermission`-based (50 cases already), not gated by a fixed role the way
  Purchasing/Accounting are. The source plan's §4.3 ("add a canonical Inventory Manager role") is
  satisfied by the existing permission-based convention; do **not** introduce a parallel
  role-based gate for one module when every existing Inventory policy already works the other way.
  Add a role only if the business specifically needs an assignable "Inventory Manager" label in the
  UI, not because the source plan names one.
- **No replenishment-policy- or inbound-allocation-specific permission exists yet** —
  `WarehouseReplenishmentPolicyPolicy` (PR4) and `AllocationsRelationManager` (PR2) both deliberately
  reused `InventoryPermission::StockView`/`WarehouseManage`, documented in their own docblocks as "no
  dedicated permission exists for this yet." Phase 1 (§65) can add fine-grained permissions
  (`inventory.replenishment_policy.manage`, `inventory.inbound.allocate`) once the business confirms
  it wants Inventory Managers and general Warehouse Managers to have different write access to these
  two specific things — until then, the coarser existing permissions are correct, not a shortcut.

---

## Phase 0 — Blocking Legacy Ownership & PO Lifecycle Correction

**Source: §1.3, §93 Phase 0. Mandatory before any later phase.**

Sliced into 7 PRs instead of the source plan's 0A–0E, per `CLAUDE.md`'s "small, reviewable changes"
rule. Status:

| PR | Source mapping | Title | Status | Commit |
|---|---|---|---|---|
| 1 | §1.3.2 (Phase 0C) | PO lifecycle correction (`Approved`→`Accepted`, retire `Sent` as a gate) | ✅ Done | `3ddd0ef` |
| 2 | §1.3.1 (Phase 0A/0B) | Remove PO warehouse ownership; `PurchaseInbound` + `PurchaseInboundAllocation` | ✅ Done | `9a61d3a` |
| 3 | §1.3.1, §27–§31 (Phase 0A/0B) | Remove `Bill.supplier_id` duplication | ✅ Done | `f1d9f76` |
| 4 | §1.3.1, §5 (Phase 0A/0B) | Replace `InventoryStock.reorder_level` with `WarehouseReplenishmentPolicy` | ✅ Done | `d432ff8` |
| 5 | §1.3.1, §22 (Phase 0A/0B) | Strip monetary data out of `InventoryOperationLine`; retrigger supplier-cost writeback at acceptance | ✅ Implemented, fully verified, **not yet committed** | — |
| 6 | §1.3.3, §14 (Phase 0D) | `PurchaseOrderAcceptanceOrchestrator` | ⬜ Not started | — |
| 7 | §1.3.5, §84 (Phase 0E) | Exit-gate architecture tests + docs update | ⬜ Not started | — |

Verification snapshot as of this update (branch working tree, PR5 included): `vendor/bin/pint`
clean · `vendor/bin/phpstan analyse` 347 errors (unchanged baseline) · `vendor/bin/pest` 2552
passed / 25 skipped, 0 failed · `php artisan migrate:fresh --seed` clean on MySQL 8.4.7.

### PR 1 — PO lifecycle correction ✅

Enum case `Approved` → `Accepted`; `Sent` removed from the transition graph — `sent_at` is pure
supplier-communication metadata layered on top of `Accepted` (`PurchaseOrderStatus::isAcceptedOrLater()`,
`PurchaseOrderNotYetAccepted`). `isReceivable()` gates on `Accepted`/`PartiallyReceived`.

### PR 2 — Remove PO warehouse ownership ✅

`PurchaseInbound` (1:1 per PO, `PurchaseInboundService::ensureForAccepted()`) →
`PurchaseInboundLine` (1:1 per PO line) → `PurchaseInboundAllocation` (1:1 per line for Phase 0 —
one warehouse per line; §19's full suggested-split-across-warehouses UX is explicitly deferred, see
Phase 2/4 below). `purchase_orders.destination_warehouse_id` is gone.
`PurchaseOrderReceivingService::initiate()` resolves the receiving warehouse via
`PurchaseInboundService::resolveReceivingWarehouse()`. A minimal `AllocationsRelationManager` lets
an Inventory Manager assign each line's warehouse from the PO's own page.

### PR 3 — Remove `Bill.supplier_id` duplication ✅

`bills.supplier_id` nullable; PO-linked bills leave it null and derive supplier from
`purchaseOrder->supplier_id`. `Bill::booted()`'s `saving` hook maintains `resolved_supplier_id` —
the one column every supplier-scoped query now reads. `SupplierOwnershipConflict` guards both-set
and neither-set. Seeders (which run under `WithoutModelEvents`) set `resolved_supplier_id`
explicitly.

### PR 4 — Replace `InventoryStock.reorder_level` ✅

`WarehouseReplenishmentPolicy` (`min_quantity`, `max_quantity`, `is_active`, unique on
`(warehouse_id, product_variant_id)`, MySQL `CHECK (max_quantity > min_quantity)`).
`isBreachedBy()` / `breachedSubquery()` replace `InventoryStock::isLowStock()` everywhere. A minimal
Filament resource lets a policy be set for a warehouse/variant pair with zero existing stock — the
exact fix §5.3 calls for.

### PR 5 — Strip monetary data out of `InventoryOperationLine` ✅ (uncommitted)

`inventory_operation_lines.unit_cost` is gone. Receiving no longer prefills cost.
`AdvancePurchaseOrderOnOperationCompleted` no longer computes/writes `last_received_unit_cost`.
`SupplierCostWritebackService::apply(PurchaseOrder $order)` now reads each line's own frozen
`unit_cost` and is called from `PurchaseOrderApprovalService` at both `submit()` auto-approval and
`approve()` — **interim wiring, until PR 6 becomes the single call site** (§22.2's list of valid
trigger events — `PurchaseOrderAccepted`, `CommercialPriceOverrideApproved`,
`SupplierBillVarianceApproved` — only the first is implemented; the other two don't exist yet and
belong to Phase 6, once a price-override/bill-variance-approval flow exists to trigger them).
`InventoryOperationService::receiveReceiptLines()`'s dead cost-to-`ProductVariant`-pricing write was
removed along with `ProductPricingService::updateCostFromInventory()`, its only caller (§22.3 — this
was exactly the kind of hidden pricing ownership inside Inventory the source plan flags). A new arch
test guards `unit_cost` from reappearing on `InventoryOperationLine`.

**Not yet committed.**

### PR 6 — `PurchaseOrderAcceptanceOrchestrator` ⬜

Per §1.3.3/§14, invoked from `PurchaseOrderApprovalService`'s `PendingApproval → Accepted`
transition (both `submit()` auto-approval and `approve()`), inside the existing lock, idempotently
ensuring on each call:

1. Commercial snapshot frozen — already true once a PO leaves `Draft`/`PendingApproval`; assert only.
2. `PurchaseInboundService::ensureForAccepted($po)` — exists (PR2) but is currently called lazily,
   only by `PurchaseOrderReceivingService` at first receipt and by test/seeder helpers via
   `allocateAllTo()`. **The orchestrator must call it explicitly at acceptance time** so the inbound
   exists immediately (§16's "must appear automatically in Logistics" — today it only appears lazily).
3. Supplier Confirmation created if required — `SupplierConfirmationService::record()` already
   exists and is fully built, but per the codebase survey it is **manual-only today**, invoked from
   `ConfirmationsRelationManager`'s Filament action. The orchestrator must call its create-if-required
   entrypoint automatically (§35 — "Accepted PO should create/activate supplier confirmation
   workflow"). Whether *every* accepted PO needs one, or only suppliers/products flagged as
   requiring confirmation, is a business rule not yet encoded anywhere — resolve this by adding an
   explicit flag (e.g. `Supplier.requires_confirmation` or a per-PO-line flag) rather than guessing;
   ask if unspecified.
4. One Draft `Bill` created with `purchase_order_id = $po->id` — guarded by PR3's
   `resolved_supplier_id`-scoped uniqueness so retries can't create a second one. `AccountingDocumentService::recordBill()`
   already exists and derives supplier correctly for a PO-linked bill (PR3); the orchestrator needs
   to build the line-copying step (§28, §32 — PO lines copied onto the Bill automatically, not
   manually mapped) since `recordBill()` today takes lines as an explicit array, not "derive from a
   PO."
5. `SupplierCostWritebackService::apply($po)` — relocated to acceptance time in PR5; the
   orchestrator becomes its single call site, replacing PR5's direct calls in
   `PurchaseOrderApprovalService`.
6. Audit record via the existing `$this->audit(...)` pattern (already used throughout
   `PurchaseOrderApprovalService`).
7. Notification to Inventory Manager ("PO ready for allocation") and Accountant ("Draft bill ready
   for review") — **use the existing generic pipeline**: a new domain event (e.g.
   `PurchaseOrderAccepted`, matching §70's suggested event name) mapped in `AppServiceProvider` to
   `SendBusinessNotification`, the same way `StockLow` is wired today. Do not create bespoke
   Notification subclasses — `app/Notifications/` has exactly one class
   (`BusinessNotification`) by design.

`ReplenishmentCoverage`/requirement attachment (§14 step 5) is **not** part of Phase 0 — that model
doesn't exist until Phase 2. The orchestrator performs steps 1–4/6/7 now; Phase 2 adds the
coverage-attachment step to this same orchestrator, not a second one (§14: "Do not create these
records independently from UI actions" applies just as much to a future phase adding a competing
trigger).

**Tests to add:** accepting a PO twice (including concurrently) creates exactly one
`PurchaseInbound` and exactly one `Bill`; accepting creates a Draft Bill; accepting activates the
inbound; orchestrator failure mid-way rolls back the whole transaction (§72's concurrency list,
items "two PO acceptance events cannot generate duplicate Bills/inbounds").

### PR 7 — Exit-gate architecture tests + docs update ⬜

Per §1.3.5 / §84:

- Extend `tests/Unit/ArchTest.php`: no warehouse-shaped column on `purchase_orders` (shipped in PR2;
  arch test still pending), no `supplier_id` misuse on PO-linked bills (PR3 shipped; arch test
  pending), no `reorder_level` on `inventory_stocks` (PR4 shipped; arch test pending), no
  `unit_cost` on `inventory_operation_lines` (PR5 shipped **and already has its arch test**), `Sent`
  not required for `isReceivable()` (PR1 shipped, covered by `PurchaseOrderStatusTest`), accepting is
  idempotent (depends on PR6).
- §84's "Logistics Price Isolation Tests" — add the broader sweep: no Inventory Filament
  form/table/infolist/export contains a cost field (spot checks exist per-PR; a single
  comprehensive arch/feature test closes the gate), Inventory role cannot reach a Purchasing price
  resource, receipt posting does not write `SupplierProductReference.purchase_cost` (already
  structurally true after PR5 — worth a regression test since it's exactly the bug being prevented).
- Update `Docs/ERP_DOMAIN_MODEL.md`, `Docs/CROSS_MODULE_BUSINESS_FLOWS.md`, and the purchasing ADR
  describing the pre-Phase-0 ownership model (§95's list, scoped to what Phase 0 actually changed).
- Add a new ADR (`docs/adr/0012-...md`) recording the four ownership decisions, since none had one.
- Final full run, all green: `vendor/bin/pint --format agent`, `vendor/bin/phpstan analyse`,
  `vendor/bin/pest`, `php artisan migrate:fresh --seed`.

**Phase 0 exit gate (§1.3.5) — status:**

| Gate item | Status |
|---|---|
| `purchase_orders.warehouse_id` no longer exists | ✅ |
| `PurchaseOrder` has no warehouse relationship / form field | ✅ |
| `bills.supplier_id` no longer exists (nullable + `resolved_supplier_id` instead) | ✅ |
| `Bill` has no duplicated direct supplier ownership | ✅ |
| `inventory_stocks.reorder_level` no longer exists | ✅ |
| Replenishment policy represented by `WarehouseReplenishmentPolicy` | ✅ |
| `inventory_operation_lines.unit_cost` no longer exists | ✅ |
| Inventory / Logistics APIs and UI expose no procurement monetary value | 🟡 shipped; comprehensive sweep test pending (PR7) |
| `Sent` is not a receiving/downstream-activation prerequisite | ✅ |
| `sent_at` is communication/audit metadata only | ✅ |
| `Accepted` activates downstream procurement records | 🟡 partial — writeback and lazy-inbound-creation yes; Bill/confirmation/notification automation is PR6 |
| Accepting the same PO repeatedly cannot create duplicate downstream records | 🟡 true for inbound (unique `purchase_order_id`) and writeback (idempotent upsert); Bill/confirmation duplication guard is PR6 |
| Factories and seeders generate only the corrected model | ✅ |
| Architecture tests prevent these legacy fields/dependencies from returning | 🟡 PR5's is shipped; PR1–4's are PR7 |
| `php artisan migrate:fresh --seed` remains green | ✅ |

**Phase 0 is not closed until PR6 and PR7 land and every row above is ✅.**

---

## Phase 1 — Domain boundary cleanup

**Source: §93 Phase 1, §2, §65.** Admission: Phase 0 fully closed.

- Confirm, don't re-litigate, the Inventory-Manager-as-permission-not-role decision above (§0.2).
  If the business wants an assignable "Inventory Manager" label for reporting/UI clarity, add
  `DashboardRole::InventoryManager` as a *convenience* role that maps to the same
  `InventoryPermission` set already gating everything — not a new authorization path.
- Add the fine-grained permissions §0.2 identified as deliberately deferred:
  `inventory.replenishment_policy.view`/`.manage` (currently piggybacking on
  `StockView`/`WarehouseManage`), `inventory.inbound.allocate` (currently piggybacking on `update`
  via `WarehouseReplenishmentPolicyPolicy`'s pattern extended to `AllocationsRelationManager`).
  Update `WarehouseReplenishmentPolicyPolicy` and `AllocationsRelationManager`'s `visible()` checks
  to the new permissions once they exist; this is a pure narrowing, not a behavior change, so it's
  low-risk to do anytime after Phase 0.
- Re-run the §84 Logistics Price Isolation sweep across the *whole* Inventory namespace (PR7 in
  Phase 0 covers what Phase 0 touched; this repeats it as new Inventory features land in Phase 2+,
  since new code is exactly where a monetary field could creep back in).
- `App\Services\Inventory\ProductPricingService` and friends (`PriceResolver`, `PricingTierService`,
  `PricingTierDiscountCalculator`) — §22.3 asks whether these are commercial/sales pricing services
  that belong outside the Inventory namespace. **Decision needed, not yet made**: audit each for
  whether it computes *selling* price (sales/commercial — should move to `App\Services\Sales\Pricing`
  or similar) versus *cost* tracking that's arguably still inventory-adjacent. `updateCostFromInventory()`
  was already removed in Phase 0 PR5 as the one clear violation; the rest need a case-by-case read
  before moving anything, since a blind namespace move without checking callers/tests would be a
  large, risky mechanical change for its own sake — do it as its own small PR per service, not one
  big sweep.

**Exit gate:** architecture tests cover the full Inventory namespace for monetary leakage, not just
the four Phase 0 fields; permission granularity matches what Phase 2's allocation UI will need.

---

## Phase 2 — Warehouse Min/Max and Replenishment

**Source: §93 Phase 2, §5–§9, §67.** Admission: Phase 1 closed. Builds directly on PR4's
`WarehouseReplenishmentPolicy` (already the sole Min/Max source of truth — nothing to redo there).

### 2.1 New models (all net-new — confirmed absent by the survey)

- `ReplenishmentRequirement` (§8): `warehouse_replenishment_policy_id`, `warehouse_id`,
  `product_variant_id`, `required_base_quantity`, `covered_base_quantity`,
  `fulfilled_base_quantity`, `status` (`open`/`partially_covered`/`covered`/`fulfilled`/`cancelled`),
  `triggered_at`, `resolved_at`, blameable, timestamps. DB constraint: at most one active
  (non-terminal-status) requirement per policy (§73) — implement as a MySQL generated-column partial
  unique index, the same pattern already used for `bills_supplier_reference_active_unique` (PR3) and
  `active_supplier_reference` — reuse that exact technique rather than inventing a new one.
- `ReplenishmentCoverage` (§9): `replenishment_requirement_id`, `source_type`
  (`internal_transfer`/`purchase_order_line`/`supplier_replacement`), `source_id`,
  `covered_base_quantity`, `status`. Polymorphic-by-string-type, matching the existing
  `confirmable_type`/`confirmable_id` pattern already used by `SupplierConfirmation` — reuse that
  convention rather than a `MorphTo` if the existing codebase favors explicit type strings (confirm
  by reading `SupplierConfirmation` before choosing).

### 2.2 New services

- `ReplenishmentProjectionService` (§6.2) — the **one** canonical projection: saleable available +
  confirmed incoming transfers + confirmed incoming PO allocations + confirmed supplier-replacement
  incoming − uncovered committed demand. Must not double-count what `saleableAvailableQuantity()`
  (already on `InventoryStock`) already reflects. No Filament widget, report, or other service may
  recompute this independently (§6.2's explicit "no duplicated calculation" rule) — every later
  consumer (dashboards, PO stock-matrix in Phase 3, alerts) calls this service.
- `ReplenishmentRequirementService` — opens/covers/fulfills/cancels a `ReplenishmentRequirement`,
  triggered by stock position changes. Replaces the current `StockLow` event's role as "the
  procurement workflow" (§8's explicit ask) — `StockLow` stays as the ephemeral notification trigger
  (it already drives `InventoryAlert`, which is a fine durable *alert*, but not a *work item* with a
  covered/fulfilled lifecycle); this service adds the missing work-item layer on top, it does not
  replace `InventoryAlertService`.
- `ReplenishmentCoverageService` — links a requirement to its covering source(s), preventing
  duplicate procurement per §9's worked example (50 required, 30 PO + 20 transfer = covered, no
  second PO).
- `InternalTransferSuggestionService` (§7) — genuinely new; `InventoryOperationService` already
  executes a transfer once decided, so this service only needs to produce the suggestion (source
  warehouse, quantity, "never push the source below its own protected minimum") and hand off to the
  existing transfer-creation path — it does not need to reimplement transfer posting.

### 2.3 Wiring into Phase 0's orchestrator

Once `ReplenishmentCoverage` exists, extend `PurchaseOrderAcceptanceOrchestrator` (PR6) with the
`ReplenishmentCoverage` attachment step the source plan lists but Phase 0 explicitly deferred (§1.3.3
step 5 / §14 step 5). This is the one place Phase 2 touches Phase 0's code, and it's additive (one
more `ensure...()` call in the same transaction), not a redesign.

### 2.4 UI / dashboards

- Replenishment Policy management UX (already exists as a minimal resource from PR4 — extend with
  bulk-edit or import if the business needs it; not required for the exit gate).
  # Enum reference for policy fields already correct (min/max/is_active, PR4).
- Work queues on `InventoryDashboard`: "Open Replenishment Requirements", "Transfer Suggestions" —
  net-new widgets, following the existing `StatsOverviewWidget`/`TableWidget` patterns already used
  by `InventoryLowStock`/`InventoryKeyMetrics` (PR4 already extended one of these; same shape).
- Work queues on `PurchasingDashboard`: "Requirements Waiting for Purchase" — same pattern as
  `PurchasingStatistics`.

**Tests (§82):** policy-before-stock (already covered, PR4); max>min constraint (already covered,
PR4); saleable-only trigger; damaged/quarantine excluded; requirement opens at/below Min; suggested
quantity targets Max; existing incoming coverage reduces requirement; confirmed replacement reduces
purchase need (depends on Phase 8 existing first, so this specific case is a Phase 8 regression test
added retroactively here, not blocking Phase 2's own exit); internal transfer surplus considered
first; source warehouse protected; no duplicate requirement under concurrency (unique-constraint
test, same style as PR4's `WarehouseReplenishmentPolicyTest`); fulfilled requirement closes after
receipt.

**Exit gate:** `reorder_level` does not reappear anywhere (already arch-tested from PR4 — re-run,
don't re-write); one projection service, zero duplicated Min/Max math anywhere else in the codebase.

---

## Phase 3 — Purchase Order redesign

**Source: §93 Phase 3, §10–§13.** Admission: Phase 0 closed (warehouse ownership already gone).
Most of Phase 3's *hard* work is already done by Phase 0 — this phase is UX completion, not
architecture change.

- §10.1/§10.2 (remove warehouse from PO, correct field set) — **done** (PR2). Nothing to do here.
- §11.1/§11.2 (supplier-first product picker, default price from `SupplierProductReference`) —
  **§11.2 is already implemented**: `PurchaseOrderService::addLine()` already defaults
  `unit_cost` from `SupplierProductReference.purchase_cost` via `resolveUnitCost()`. **§11.1's
  supplier-filtered picker UX is not yet confirmed to exist** — verify `PurchaseOrderForm`'s product
  select is scoped to the chosen supplier's active `SupplierProductReference` rows before assuming
  either way; if it currently offers every `ProductVariant` regardless of supplier, that's this
  phase's real remaining work.
- §11.3 (no silent zero-price for unsupported items) — verify current behavior; if a variant with no
  `SupplierProductReference` can be added at price 0 through the normal path today, gate it behind
  an explicit "add unsupported item" action requiring elevated permission, per the source rule.
- §12 (read-only stock matrix on the PO form) — **net-new UI**, but its data source
  (`ReplenishmentProjectionService`, per-warehouse on-hand/reserved/saleable/in-transit/Min/Max/
  suggested-need) doesn't exist until Phase 2 ships. **This is why Phase 3 is sequenced after Phase
  2** in the source plan despite being numbered lower in places — keep that order; do not attempt
  §12 before `ReplenishmentProjectionService` exists.
- §13's lifecycle is **already fully correct** (PR1) — nothing to redo.

**Tests (§83):** items 1, 6, 7, 8 already covered by Phase 0's Pest suite
(`PurchaseOrderDraftTest`, `PurchaseOrderApprovalTest`, `PurchasingGuardBranchTest`). Items 2–5
(supplier-filtered products, default price — already covered by existing `PurchaseOrderServiceTest`-style
coverage if it exists, verify — unsupported-item rejection, stock-matrix query has no mutation) are
this phase's new coverage.

**Exit gate:** Purchasing can see live warehouse stock while drafting a PO without being able to
mutate it or choose a destination warehouse.

---

## Phase 4 — Accepted PO orchestration and Logistics Inbound

**Source: §93 Phase 4, §16–§21.** Admission: Phase 0 closed (PR6 in particular — this phase
completes what PR6 starts, it doesn't duplicate it).

- `PurchaseInbound`/`PurchaseInboundLine` — **structurally done** (PR2), including the no-monetary-columns
  constraint and the `purchase_order_id`/`purchase_order_line_id` uniqueness (§16, §17). Statuses
  currently implemented: `AwaitingAllocation`, `AwaitingReceipt`, `PartiallyReceived` (enum case
  exists), `Received`, `Cancelled`. §16 additionally lists `waiting_purchase_approval` — **not
  currently a state**, and arguably shouldn't be: PR2's design creates the inbound only once a PO is
  `Accepted`, so there is never an inbound in a pre-approval waiting state to represent. Skip adding
  this case unless a concrete need for it surfaces.
- `PurchaseInboundAllocation` — **shipped for the Phase 0 scope** (one warehouse per line, PR2's
  documented simplification). **Phase 4 is where §18's full model applies**: allow more than one
  allocation per `PurchaseInboundLine` (splitting one line's quantity across several warehouses),
  which requires: dropping PR2's `unique(purchase_inbound_line_id)` constraint in favor of a
  sum-validation (§18: `SUM(allocated) per line = quantity to distribute`), and reworking
  `PurchaseOrderReceivingService::resolveReceivingWarehouse()`'s "exactly one warehouse or throw"
  logic into "resolve the requested warehouse's share," since a receipt already targets one warehouse
  at a time — this part of PR2's design was built to extend cleanly into this, per its own docblock.
- §19 (suggested allocation from Min/Max gaps) — depends on `ReplenishmentProjectionService`
  (Phase 2). Sequence Phase 4's allocation-splitting work after Phase 2, same reasoning as Phase 3's
  stock matrix.
- §20 (PO-derived receipts, one per warehouse allocation) — **this is the "one receipt per
  allocation" behavior PR2's plan draft originally described and then scoped down**; once §18's
  multi-allocation-per-line exists, `PurchaseOrderReceivingService::initiate()` needs the
  `Warehouse` parameter it was deliberately kept without in PR2 (to avoid churning ~30 test call
  sites for a Phase 0 concern that didn't need it yet) — add it now, when it's actually needed.
- §21 (remove standalone procurement receipt creation) — verify whether Filament's
  `InventoryOperations` resource currently allows creating a `Receipt`-type operation with no
  `source_document`. If so, restrict receipt creation to the PO-derived path for
  `OperationType::Receipt` specifically (deliveries and transfers are unaffected — they're not
  procurement).
- **GRNI is explicitly NOT this phase** (source plan places it in Phase 6) — Phase 4 only needs to
  guarantee stock/status propagation on receipt completion (§24–§26), which
  `AdvancePurchaseOrderOnOperationCompleted` already does correctly post-PR5.

**Tests (§85):** items 1–2 partially covered (inbound-on-acceptance is PR6's job, not yet done);
3–5 depend on multi-allocation (this phase's real new work); 6–9 already covered by existing
receiving tests; 10 (over-receipt) already covered (`PurchaseOrderOverReceiptTest`).

**Exit gate:** one PO line can be split across warehouses with a validated allocation, one receipt
per warehouse's share is generated automatically, and no second acceptance-trigger path exists
(§14's "do not create a second acceptance orchestration path" — everything routes through PR6's
orchestrator, extended, never duplicated).

---

## Phase 5 — Bill redesign

**Source: §93 Phase 5, §27–§32.** Admission: Phase 0 closed (§27/§29–§31's ownership rules are
already correct from PR3).

- §27.1/§29 (nullable, system-owned `purchase_order_id`; manual Bill forces it null) — **done**
  (PR3's `SupplierOwnershipConflict` guard). Nothing to redo.
- §30 (generated-Bill UI is read-only, no unlink/relink) — **verify**: `BillResource`'s form
  already disables the `supplier_id` select when `purchase_order_id` is set (PR3), but confirm the
  `purchase_order_id` select itself is not editable once a Bill exists with one — if it currently is,
  lock it (disabled + not dehydrated) the same way PR3 locked `supplier_id`.
- §31 (draft-compatible external reference — `supplier_reference` nullable until approval) — **not
  yet correct**: today `Bill::booted()`'s `saving` hook throws `SupplierReferenceRequired` if
  `supplier_reference` is blank on every save, including a fresh Draft. This phase must relax that
  to "required only when transitioning out of Draft toward Approved," matching §31's "do not require
  supplier invoice reference at Bill creation." This is a real, scoped behavior change — plan it as
  its own PR with its own tests, since the current requiredness is load-bearing for the
  duplicate-reference uniqueness control (PR3's `resolved_supplier_id` + `supplier_reference` unique
  index) and relaxing it needs the constraint to tolerate a temporarily-null reference correctly
  (partial/generated-column index already handles NULL-doesn't-collide, per its own docblock — confirm
  that still holds once references can be null for longer).
- §28/§32 (PO-generated Bill created automatically with lines copied from the PO) — **this is PR6's
  job** (Phase 0), not a separate Phase 5 task; Phase 5 only needs to verify the generated Bill's
  lines stay correct as PO lines change before acceptance (they can't — PO lines are frozen after
  Accepted) and add the "accounting-only fields Accounting may still complete" allowance (§32) if the
  generated Bill's lines need a chart-account/tax classification Purchasing doesn't set.

**Tests (§86):** items 1–2, 5–7, 10 already covered by PR3's `BillTest`/`DuplicateSupplierReferenceTest`
suite. Items 3–4 (accepted PO creates one Draft Bill, retry-safe) depend on PR6. Item 8 (draft
Bill can exist before external reference) is this phase's new work. Item 9 (approval requires
evidence/match) depends on Phase 6's three-way match.

**Exit gate:** a Draft Bill can exist with no supplier reference yet; approval is where the
reference becomes mandatory; PO-generated Bill lines are populated automatically once PR6 exists.

---

## Phase 6 — Accounting integration

**Source: §93 Phase 6, §33–§34.** Admission: Phase 4 (receipts complete correctly) and Phase 5
(Bill lifecycle correct) both closed. This is the largest single net-new phase — confirmed by the
survey that **nothing GRNI-related exists today**.

- `PurchaseThreeWayMatchService` (§33) — net-new. Inputs: PO, physical receipts (already fully
  trackable via `PurchaseOrderLine.quantity_received`/`received_base_quantity`, correct post-PR5),
  Bill/invoice evidence, Supplier Returns (once Phase 7 exists — sequence the return-adjusted-exposure
  part of matching after Phase 7, or ship matching without it first and add that input later; either
  order is workable, but don't block all of Phase 6 on Phase 7).
- GRNI ledger design (§34.1–§34.3) — net-new chart-of-accounts entry (a GRNI liability account) plus
  posting logic in `AccountingDocumentService` or a new dedicated service, mirroring the existing
  `journalPosting->postNew()` pattern already used for Bill approval/payment (`AccountingDocumentService::approveBill()`
  is the closest existing template — read it before designing the GRNI entries, since the
  debit/credit helper methods (`debit()`/`credit()`/`accountId()`) already exist and should be
  reused, not reinvented).
- Bill approval posting changes from "Dr GRNI-less direct AP" (today's `approveBill()`) to "Dr GRNI,
  Dr Recoverable Input Tax, Cr AP" (§34.2) — this is a real change to `approveBill()`'s posting
  lines, not additive; plan it as a dedicated PR with careful before/after journal-balance tests,
  since it changes what every existing `PayablesLifecycleTest`-style test currently asserts about
  the posted entries.
- **Replace, don't extend, `PurchasingReportService::costVariance()`** — confirmed dead as of PR5;
  its replacement is whatever the three-way-match service produces as its "price match" /
  "variance" output (§33).

**Tests (§87):** all net-new; none of this exists today to regress-test.

**Exit gate:** GRNI posts on receipt completion, clears correctly on Bill approval, AP is created
correctly, tax is recognized correctly, payment clears AP, journals stay balanced throughout — and
`costVariance()`'s replacement produces a real number instead of always returning empty.

---

## Phase 7 — Supplier Return commercial workflow

**Source: §93 Phase 7, §36–§53.** Admission: Phase 6 closed (return-adjusted exposure needs GRNI/
matching to mean anything financially) — though the *commercial* aggregate itself has no hard
dependency on Phase 6 existing first; sequence per the source plan's ordering regardless, since
§56's "Bill still Draft" scenario explicitly needs three-way matching to already exist.

**This phase is smaller than the source plan implies**, per the survey finding in §0.2: the physical
layer already works.

- §36–§38, §41 (commercial separation from customer returns; must start from provenance) —
  `InventoryReturnService::createSupplierReturn()`/`addSupplierLine()` **already enforce
  provenance** (validates against a receipt line, tracks remaining-returnable quantity). What's
  missing is the *commercial* front end: `SupplierReturnCase` (§41) + `SupplierReturnCaseLine`
  (§42), owned by Purchasing, that a warehouse-detected problem creates *before* any physical
  movement — today, per the survey, `createSupplierReturn()` can apparently be called directly
  without a prior commercial request/approval step. Add `SupplierReturnCase` as the new front door;
  `InventoryReturnService`'s existing methods become what fires automatically once a case is
  `approved_for_return` (§45), not a path Purchasing or Inventory calls directly anymore.
- §39–§40 (remove manual supplier/PO selectors from the *normal* provenance flow) — verify whether
  `InventoryReturnService::createSupplierReturn()`'s current Filament entry point already derives
  supplier/PO from the chosen stock/lot/receipt line, or still asks for them directly; if the latter,
  this phase removes those fields once `SupplierReturnCase` supplies them instead.
- §43 (commercial lifecycle: `requested`→`resolved`) lives on the new `SupplierReturnCase`, kept
  separate from §44's physical `InventoryReturnStatus` (`Draft`/`Ready`/`Posted`/`Cancelled` —
  already correct and already minimal, per the survey; **do not add commercial statuses to this
  enum**, that's exactly the "overload" §43 warns against).
- §46–§48 (dispatch decrements stock once, doesn't rewrite PO history, recalculates replenishment
  via saleable projection) — the stock-decrement and PO-history-preservation behavior already exists
  in `InventoryReturnService`'s posting path; the replenishment-recalculation hook is new (wire it to
  `ReplenishmentProjectionService` from Phase 2 once a supplier return posts).
- §49 (resolution outcomes: replacement / supplier_credit / disputed / rejected) — net-new field +
  branch on `SupplierReturnCase`, feeding Phase 8.

**Tests (§88):** items 5–6, 8–11 likely already substantially covered by `InventoryReturnServiceTest`
(the file already referenced elsewhere in this codebase's test suite) — confirm exact coverage
before writing new tests, don't duplicate. Items 1–4, 7 (case-first flow, no unrelated variant, no
stock change while only commercial) are net-new once `SupplierReturnCase` exists. Items 12–15
(historical reporting, Min/Max recalculation, damaged-dispatch no double-shortage, concurrency) mix
existing coverage and new assertions.

**Exit gate:** a supplier return cannot happen without a Purchasing-approved case first; the
existing physical posting is reused, not rebuilt; PO receiving history is provably unchanged by a
later return.

---

## Phase 8 — Replacement and Supplier Credit

**Source: §93 Phase 8, §49–§57.** Admission: Phase 7 closed (`SupplierReturnCase.resolution` is
the trigger for everything here).

- Supplier replacement flow (§50–§52) — reuses `PurchaseInbound`/`PurchaseInboundAllocation`
  primitives per §67's explicit preference ("prefer reuse of the same warehouse
  allocation/receiving primitives where possible") rather than inventing a parallel
  `SupplierReplacementInbound` model from scratch; a replacement inbound is structurally "a
  `PurchaseInbound`-shaped thing with a `SupplierReturnCase` as its origin instead of a
  `PurchaseOrder`" — model this as either a nullable alternate source-reference on the existing
  model, or a small distinct model reusing the same allocation/line primitives via a shared
  interface/trait. Decide based on how invasive a nullable-PO-source would be to
  `PurchaseInboundService`'s existing `ensureForAccepted()` assumptions — read that code fresh
  before choosing, since Phase 4 will have already extended it for multi-warehouse allocation and
  the two changes should be designed together, not in isolation.
- `SupplierCredit` (§54–§57) — **fully net-new, confirmed absent** by the survey (no
  `SupplierCredit` model, no supplier-side counterpart to the existing Sales-only `CreditNote`).
  Build it as its own model, **not** as a shared model with `CreditNote` (§54: "Do not reuse Sales
  `CreditNote`") — the two have almost no overlapping fields once you account for `CreditNote`'s
  customer/invoice ownership vs. `SupplierCredit`'s supplier/PO/bill ownership, and forcing a shared
  base class for two models with different owners, different lifecycles, and different posting rules
  would be exactly the kind of premature abstraction `CLAUDE.md` warns against.
- `SupplierCreditPostingService` (§57) — mirrors `AccountingDocumentService`'s existing posting
  patterns (reuse `debit()`/`credit()`/`journalPosting->postNew()`), branching on whether the
  original Bill is still open, already paid, or partially paid (§56).

**Tests (§89–§90):** all net-new.

**Exit gate:** a replacement never creates a second PO or Bill and never lets a PO's received
quantity exceed what was originally ordered; a supplier credit reduces AP without rewriting Bill
history and is fully separate from `CreditNote`.

---

## Phase 9 — Dashboards, reports, notifications, audit

**Source: §93 Phase 9, §59–§64, §78–§80.** Admission: Phases 2, 4, 6, 7, 8 closed enough that the
data these dashboards/reports read actually exists.

- All three dashboards (`PurchasingDashboard`, `InventoryDashboard`, `AccountingDashboard`) already
  exist with real widgets (survey confirmed exact current widget lists) — this phase **adds** the
  queue-style widgets the source plan lists (§61–§63) that don't exist yet: "Awaiting Warehouse
  Allocation", "Supplier Returns Awaiting Dispatch", "Replacement Inbounds" on Inventory; "Open
  Replenishment Requirements", "Accepted POs Awaiting Supplier Confirmation", "Supplier Return
  Requests" on Purchasing; "PO-generated Draft Bills", "Bills Blocked by Match Variance", "Supplier
  Credits Pending Approval", "Unmatched GRNI" on Accounting. Every one of these follows the existing
  `StatsOverviewWidget`/`TableWidget` conventions already in use — no new widget architecture needed.
- §64 (supplier performance metrics) — net-new report, fed by data Phase 7/8 will have created
  (return rate, replacement response time, credit response time).
- §79 (role-targeted notifications) — **use the existing single-notification-class pipeline**
  (`BusinessNotification` + `NotificationDelivery` + per-event listener mapping in
  `AppServiceProvider`, confirmed by the survey as the established and only convention). Each new
  notification in §79's list is a new domain event + one line in `AppServiceProvider`'s event map,
  not a new Notification subclass.
- §80 (audit) — the existing `activity()->...->log(...)` pattern, already used consistently across
  every service touched in Phase 0, extends naturally to every new service in Phases 2–8; no new
  audit infrastructure needed, just consistent use of what's already there.

**Exit gate:** every dashboard queue reflects real, correctly-scoped data (no monetary values on
Inventory's queues); every notification in §79's list fires through the existing pipeline.

---

## Phase 10 — Full cleanup and validation

**Source: §93 Phase 10, §66, §94–§97.** Admission: Phases 1–9 all closed.

- Sweep §66's full 20-item "legacy behavior to remove" list against the final state — most items
  are already gone by Phase 0 (1–2, 4–7 for the core four fields) or Phase 5/7 (8–11, 12–15); confirm
  none crept back in, rather than re-removing anything.
- Full documentation pass per §95 — by this point `ERP_PHASE0_REMEDIATION_STATUS.md`'s successor
  content (this document) plus the ADR from Phase 0 PR7 already cover the ownership decisions;
  Phase 10 extends that to the newer aggregates (`ReplenishmentRequirement`, `SupplierReturnCase`,
  `SupplierCredit`, GRNI) that didn't exist when PR7's docs were written.
- Final validation, this codebase's actual commands (not the source plan's generic ones): `vendor/bin/pint --format agent`,
  `vendor/bin/phpstan analyse` (347-baseline check, still zero new), `vendor/bin/pest` (full suite,
  including every arch test added across all ten phases), `php artisan migrate:fresh --seed`.

**Exit gate = Definition of Done, §97, verified item by item** — do not consider this phase done
from a plan document alone; re-derive the checklist from the live codebase state at that time, since
ten phases of work will have changed many of the specifics this document currently describes.

---

## Appendix A — Source-plan sections not carried into any phase above

- **§77 (API Contracts)** — omitted per §0.2 finding 4: no API surface exists in this codebase, and
  building one is a separate, larger decision than this remediation plan's scope. Revisit only if
  the business explicitly asks for a REST/JSON API layer.

## Appendix B — Open questions to resolve before the phase that needs them, not before

These are flagged inline above at the phase where they first matter; collected here for visibility:

1. **Supplier-confirmation-required rule** (Phase 0 PR6) — which suppliers/products/POs require a
   confirmation before proceeding, vs. which don't? Not currently encoded anywhere.
2. **Pricing-service namespace moves** (Phase 1) — case-by-case audit needed for `PriceResolver`,
   `PricingTierService`, `PricingTierDiscountCalculator`; do not move mechanically.
3. **`InventoryManager` role** (Phase 1) — add only if the business wants an assignable UI label;
   the permission-based gate already works correctly without it.
4. **Replacement-inbound modeling choice** (Phase 8) — nullable alternate source on `PurchaseInbound`
   vs. a small sibling model; decide once Phase 4's multi-warehouse-allocation rework is in hand, not
   before, since the two should share a design.
