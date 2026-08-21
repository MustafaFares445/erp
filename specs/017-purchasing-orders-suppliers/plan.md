# Implementation Plan: Purchasing — Purchase Orders and Supplier Confirmations

**Branch**: `017-purchasing-orders-suppliers` | **Date**: 2026-08-18 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/017-purchasing-orders-suppliers/spec.md`

---

## Summary

Add a Purchasing module to the `/admin` Filament dashboard that fills the two navigation slots `AdminModuleRegistry` already reserves under its `purchasing` group. A **Purchase Order** document carries supplier, destination warehouse, currency, and priced lines defaulted from `SupplierProductReference`; it moves through `draft → pending approval → approved → sent` with a value-threshold approval gate, then tracks receiving progress to `received` or `closed`. A polymorphic **Supplier Confirmation** records the supplier's manual answer against either a purchase order or a customer order — the flow the canonical ERD actually sanctions.

The technical core is that **purchasing writes no stock**. Receiving reuses the existing `InventoryOperation` receipt flow through its already-present nullable `source_document` morph, whose docblock has read *"a purchase order for a receipt"* since the operations feature shipped. Purchasing creates the operation, `InventoryOperationService` posts the stock, and a same-transaction listener advances the purchase order's received quantities under a row lock. This keeps Principle III intact by construction rather than by discipline, and it is verified by an architecture test rather than by review.

No accounting is built. There is no bill, no payable, no payment, no journal entry, and no tax recognition — that seam is documented for the future accounting feature, not implemented here.

---

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13, Filament 5, Livewire 3, Spatie Permission, Spatie Activitylog (ADR 0005), Spatie Media Library

**Storage**: MySQL — four new tables, one modified table, one new partial unique index on an existing table

**Testing**: Pest 4 (PHPUnit 12), Larastan 3 at the project's configured level, Pint, Rector

**Target Platform**: Laravel Herd / Laragon, served at the project's `.test` host

**Project Type**: Modular monolith — domain services under `app/Services/<Domain>`, dashboard under `app/Filament`

**Performance Goals**: List and report pages respond within the PRD's 5-second envelope under normal load; the open-commitments report aggregates stored totals rather than computing per row (R-008)

**Constraints**: Dashboard-only. No API surface. No supplier portal. English-only UI. No new PHPStan baseline entries — the baseline may only shrink. Coverage and type-coverage thresholds stay at 100.

**Scale/Scope**: 4 new models, 4 new enums, 4 new policies, 7 new services, 4 new Filament resources, 6 migrations, 2 seeders, ~55 functional requirements

---

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Status | Assessment |
|---|---|---|
| **I. Specification-First Development** | ⚠️ **BLOCKED on owner sign-off** | Three of four documentation prerequisites are complete: constitution 1.6.0, `Docs/PRD.md` §11, and `Docs/database/ERD.md`. ADR 0006 is drafted but sits at Status `Proposed`; it must be moved to `Accepted` by the project owner before any implementation task begins. See §Governance Gate below. |
| **II. Domain-Driven Modular Monolith** | ✅ PASS | Business rules live in `app/Services/Purchasing`. Filament resources stay thin. The dependency arrow points Purchasing → Inventory only; Inventory gains no purchasing knowledge (R-002). No unrelated refactor is bundled — notably, only `pending_reason` is added to `orders`, not the three other ERD columns (R-012). |
| **III. Financial & Inventory Integrity** | ✅ PASS | Every stock change goes through `InventoryOperationService`, which already creates movements and runs in a transaction. Purchasing adds no second path (R-001), enforced by an architecture test (SC-002). Over-receipt is blocked under a pessimistic lock (R-003). Sent orders are immutable (FR-025). Records are archived, never physically deleted (FR-009). **No tax is recognized anywhere**, honouring the payment-time rule by simply not participating. |
| **IV. Unified Access, Media & Payment Standards** | ✅ PASS | Permissions follow the established `<module>.<entity>.<action>` catalogue plus policy-trait shape. Two new fixed roles register centrally in `DashboardRole` so every other module's bypass narrows automatically (R-006). No payment channel is touched. |
| **V. AI Isolation & Human Oversight** | ✅ N/A | No AI in this feature. Every supplier answer and every approval is a human action. |
| **VI.** *(see constitution)* | ✅ PASS | No new dependency is introduced; the feature uses packages already installed. |

### Governance Gate — blocking prerequisites

1. ⚠ **OPEN** — **ADR 0006** (`Docs/adr/0006-filament-purchasing-dashboard.md`) is drafted, scoped to dashboard-only purchasing administration and explicitly excluding any API, supplier portal, AP/GL posting, supplier bills, and purchase-tax recognition. Its Status is `Proposed`; the project owner must move it to `Accepted`.
2. ✅ **DONE** — **Constitution 1.6.0** — Product Scope & Boundaries materially expanded, Sync Impact Report regenerated, Specification Governance amended to record that this feature has **no** entry in the documented extraction order and is an owner-prioritised addition, with the prerequisite analysis explaining why excluding accounts payable is what makes that ordering legal.
3. ✅ **DONE** — **`Docs/PRD.md` §11** — the ADR 0006 exception listed, plus a bullet enumerating what it does not authorise. ADR 0004 was missing from §11 and was added in the same change.
4. ✅ **DONE** — **`Docs/database/ERD.md`** — extensions E-1 through E-6 from [data-model.md](./data-model.md) applied, with Entity Groups, Full Entity List, and Relationships updated to match. E-7 needed no ERD change: the ERD already documents `orders.pending_reason`; only the built table lacks it.

Only item 1 remains, and it is a signature rather than a work item. Phase G below is complete apart from that sign-off.

---

## Project Structure

### Documentation (this feature)

```text
specs/017-purchasing-orders-suppliers/
├── spec.md              # Feature specification (complete)
├── research.md          # Phase 0 — 13 decisions with alternatives (complete)
├── data-model.md        # Phase 1 — authoritative schema (complete)
├── quickstart.md        # Phase 1 — validation walkthrough (complete)
├── contracts/
│   └── permissions.md   # Phase 1 — permission catalogue and role matrix (complete)
└── tasks.md             # Phase 2 — produced by /speckit-tasks, NOT by this plan
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── PurchaseOrderStatus.php                    # new — lifecycle + canTransitionTo()
│   ├── SupplierConfirmationStatus.php             # new
│   ├── PurchasePermission.php                     # new — purchase.* catalogue
│   └── DashboardRole.php                          # MODIFIED — +PurchasingManager, +PurchasingOfficer
├── Models/
│   ├── PurchaseOrder.php                          # new
│   ├── PurchaseOrderLine.php                      # new
│   ├── SupplierConfirmation.php                   # new — confirmable morph
│   ├── PurchaseSetting.php                        # new — singleton, InventorySetting shape
│   ├── Supplier.php                               # MODIFIED — +purchaseOrders(), +confirmations()
│   ├── Order.php                                  # MODIFIED — +confirmations(), pending_reason
│   └── SupplierProductReference.php               # MODIFIED — +scopeActiveFor()
├── Policies/
│   ├── PurchaseOrderPolicy.php                    # new
│   ├── SupplierConfirmationPolicy.php             # new
│   ├── SupplierPolicy.php                         # new — Supplier currently has no policy
│   ├── SupplierProductReferencePolicy.php         # new
│   ├── PurchaseSettingPolicy.php                  # new
│   └── Concerns/ChecksPurchasePermissions.php     # new — ChecksSupportPermissions shape
├── Services/Purchasing/
│   ├── PurchaseOrderNumberGenerator.php           # new
│   ├── PurchaseOrderService.php                   # new — draft mutation, total recomputation
│   ├── PurchaseOrderApprovalService.php           # new — submit/approve/reject/send/cancel/close
│   ├── PurchaseOrderReceivingService.php          # new — initiate receipt, advance on completion
│   ├── SupplierConfirmationService.php            # new
│   ├── SupplierCostWritebackService.php           # new
│   ├── PurchasingReportService.php                # new
│   └── Exceptions/
│       ├── PurchaseOrderNotEditable.php
│       ├── PurchaseOrderNotReceivable.php
│       ├── OverReceiptRejected.php
│       ├── SelfApprovalRejected.php
│       └── ConfirmationNotAmendable.php
├── Listeners/
│   └── AdvancePurchaseOrderOnOperationCompleted.php   # new — same-transaction, R-002
├── Filament/
│   ├── AdminModuleRegistry.php                    # MODIFIED — real classes replace 2 string stubs
│   └── Resources/
│       ├── PurchaseOrders/                        # full page set, AdjustmentResource shape
│       │   ├── PurchaseOrderResource.php
│       │   ├── Pages/{List,Create,Edit,View}PurchaseOrder.php
│       │   ├── Schemas/{PurchaseOrderForm,PurchaseOrderInfolist}.php
│       │   ├── Tables/PurchaseOrdersTable.php
│       │   └── RelationManagers/{Lines,Confirmations,Receipts}RelationManager.php
│       ├── SupplierConfirmations/                 # ManageX shape
│       │   ├── SupplierConfirmationResource.php
│       │   └── Pages/ManageSupplierConfirmations.php
│       ├── SupplierProductReferences/             # ManageX shape
│       │   ├── SupplierProductReferenceResource.php
│       │   └── Pages/ManageSupplierProductReferences.php
│       ├── PurchaseSettings/                      # InventorySettings shape
│       │   ├── PurchaseSettingResource.php
│       │   └── Pages/ManagePurchaseSettings.php
│       └── PurchasingReports/                     # InventoryReportResource shape
│           ├── PurchasingReportResource.php
│           └── Pages/ListPurchasingReports.php
database/
├── migrations/                                    # 6 migrations, order per data-model.md §11
├── factories/{PurchaseOrder,PurchaseOrderLine,SupplierConfirmation,PurchaseSetting}Factory.php
└── seeders/
    ├── PurchasePermissionSeeder.php               # new, registered in DatabaseSeeder
    └── PurchasingDemoSeeder.php                   # new, registered in DatabaseSeeder
lang/en/admin.php                                  # MODIFIED — purchasing labels, statuses, actions
tests/
├── Feature/Purchasing/                            # story-aligned feature tests
└── Unit/                                          # enum transitions, ArchTest additions
```

**Structure Decision**: The feature follows the existing modular-monolith layout exactly — a `Purchasing` domain folder under `app/Services`, policies with a permission-checking trait under `app/Policies`, and Filament resources grouped per entity under `app/Filament/Resources`. Two Filament shapes already established in this codebase are reused rather than inventing a third: the full four-page set for purchase orders (matching `AdjustmentResource` and `InventoryOperationResource`) and the single-page `ManageX` shape for the three simpler entities (matching `SupplierResource`).

---

## Implementation Phases

Phases are ordered by dependency. Each maps to spec user stories and delivers independently testable value. Detailed per-task breakdown belongs in `tasks.md`, produced by `/speckit-tasks`.

### Phase G — Governance (blocking, no code)

ADR 0006, constitution 1.6.0, `Docs/PRD.md` §11, `Docs/database/ERD.md` extensions E-1…E-6. **No implementation task may start until this phase completes.**

### Phase 1 — Foundations (US1)

Enums (`PurchaseOrderStatus` with its transition matrix, `SupplierConfirmationStatus`, `PurchasePermission`), the two new `DashboardRole` cases, `ChecksPurchasePermissions`, all five policies, `PurchasePermissionSeeder`, and translation keys.

**Delivers**: The permission boundary, testable before any purchasing record exists.

**Critical**: This phase changes shipped behaviour in four other modules by narrowing their admin bypass (R-006). Their authorization suites must be run here, not at the end.

### Phase 2 — Purchase order drafting (US2)

Migrations 1–3 and 6, `PurchaseOrder` / `PurchaseOrderLine` / `PurchaseSetting` models and factories, `PurchaseOrderNumberGenerator`, `PurchaseOrderService`, `PurchaseOrderResource` with its form, table, infolist, and lines relation manager, `PurchaseSettingResource`, and the `AdminModuleRegistry` swap for the Purchase Orders slot.

**Delivers**: A drafted, priced, searchable purchase order.

### Phase 3 — Approval and transmission (US3)

`PurchaseOrderApprovalService` with submit, approve, reject, send, cancel, and close; the threshold rule; separation of duties; the post-transmission immutability guard enforced at both checkpoints; the corresponding Filament actions.

**Delivers**: The financial control point and the immutability boundary.

### Phase 4 — Receiving (US5)

Migration for nothing new — this phase is pure integration. `PurchaseOrderReceivingService`, the operation-completed listener with its row lock and over-receipt rejection, the Receive action on the purchase order, the receipts relation manager, and the architecture test proving no direct stock write.

**Delivers**: Purchase orders become real inventory. This is the phase Principle III bears on most directly and where the concurrency test (SC-004) lives.

**Sequenced before US4 deliberately**: receiving is P1 and confirmations are P2; a purchase order is fully useful without ever recording a confirmation.

### Phase 5 — Supplier confirmations (US4)

Migration 4 and 5, `SupplierConfirmation` model and factory, `SupplierConfirmationService`, the append-only rule, the customer-order back-order flow and its `pending_reason`, `SupplierConfirmationResource`, the confirmations relation manager, and the `AdminModuleRegistry` swap for the Supplier Confirmations slot.

**Delivers**: The ERD's sanctioned flow, on both document types.

### Phase 6 — Supplier product references and cost writeback (US6)

`SupplierProductReferenceResource`, the active-reference scope, the V-14 unique index with its duplicate-resolution guard, and `SupplierCostWritebackService` wired into receipt completion.

**Delivers**: Costs that stay current without manual maintenance.

### Phase 7 — Reports and audit (US7)

`PurchasingReportService`, `PurchasingReportResource` registered in the existing `reports` group, the three reports, exports honouring the same permission boundary, and activity-log configuration on the purchasing models.

**Delivers**: Management visibility and the audit trail.

### Phase 8 — Demo data and gate

`PurchasingDemoSeeder`, `DatabaseSeeder` registration, `tests/Unit/ArchTest.php` additions, and the full `composer test` gate.

---

## Testing Strategy

Per project rule 5, every behaviour change ships with a Pest test. Tests are organised by user story so each phase is independently verifiable.

| Concern | Test | Proves |
|---|---|---|
| Permission boundary | `PurchasePermissionTest` | Every ability × every role, at **both** the page and service checkpoints (SC-003, FR-007) |
| Cross-module bypass narrowing | Existing Inventory / CRM / Employees / Support authorization suites | The `DashboardRole` change breaks nothing (R-006) |
| Module isolation | `CrossModulePermissionLeakTest` | Purchasing roles reach no other module; purchasing receiving needs no `inventory.*` (FR-008) |
| No second stock path | `PurchasingArchitectureTest` (in `ArchTest.php`) | No purchasing class references `InventoryStock`, `InventoryMovement`, or a balance writer (SC-002) |
| Lifecycle | `PurchaseOrderStatusTest` (unit) | Every legal transition allowed, every illegal one refused |
| Numbering | `PurchaseOrderNumberTest` | Uniqueness including soft-deleted rows, and under concurrent creation (FR-011) |
| Approval | `PurchaseOrderApprovalTest` | Threshold branch, self-approval refusal, no retroactive approval, concurrent-approval single winner |
| Immutability | `PurchaseOrderImmutabilityTest` | Sent order unchangeable via UI **and** direct service call (SC-006) |
| Receiving | `PurchaseOrderReceivingTest` | Partial receipt, full receipt, cancellation, stock and movements correct |
| Over-receipt | `PurchaseOrderOverReceiptTest` | Rejection names the line; concurrent completion never double-counts (SC-004) |
| Confirmations | `SupplierConfirmationTest` | Both target types, append-only, promised-date validation, customer-order status reaction |
| Cost writeback | `SupplierCostWritebackTest` | Update, create-if-absent, currency follow, inactive-reference exclusion |
| Reports | `PurchasingReportTest` | Open commitments reconcile exactly (SC-007); export permission parity |

Coverage and type-coverage thresholds stay at 100 (`composer test:coverage`, `composer test:type-coverage`). Per project rule 8, neither may be lowered to accommodate this feature.

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| **`DashboardRole` change silently alters four shipped modules** | High — an admin holding a purchasing role loses bypass in CRM, Inventory, Employees, Support | Run all four authorization suites in Phase 1, before any purchasing UI exists, so a break is attributable |
| **Concurrent receipts over-receiving** | High — corrupts the ordered-vs-received invariant and the commitments report | Pessimistic row lock inside the completion transaction (R-003), plus an explicit concurrency test |
| **Coupling Inventory back to Purchasing** | Medium — violates Principle II and makes Inventory untestable in isolation | Listener-based integration (R-002); if an Inventory event proves contentious in review, fall back to a purchasing-owned observer narrowly scoped to the morph type |
| **Scope creep into accounting** | Medium — a PO naturally invites "and then the bill" | The spec's exclusion list and this plan's Constitution Check both name it explicitly; no AP table, column, or service appears anywhere in the file tree above |
| **ERD divergence becoming undocumented drift** | Medium — future readers cannot tell intent from accident | Every divergence is registered E-1…E-7 in the spec and must be applied to `Docs/database/ERD.md` in Phase G |
| **Unit mismatch between ordered unit and stock unit** | Medium — received quantity could be compared in the wrong unit | Received quantity reconciles in the **ordered** unit; conversion stays entirely inside the existing Inventory receipt behaviour (FR-045) |
| **Governance phase treated as paperwork and skipped** | High — Principle I violation, and the whole feature is unauthorised | Phase G is a hard gate with no code; `tasks.md` must place every implementation task after it |

---

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Extending the ERD with a purchase-order entity (E-1, E-2) | The ERD models purchasing only as a supplier answer on a customer order. Without a PO, `InventoryOperation.source_document` — built for exactly this — has nothing to point at, and received stock has no ordered baseline to reconcile against | The ERD-literal alternative (confirmations only) was offered to the project owner and rejected: it leaves the reserved Purchase Orders sidebar slot permanently empty and provides no way to record what was ordered |
| Polymorphic `supplier_confirmations` (E-3) | One entity must serve both the ERD's customer back-order flow and purchase-order acknowledgement (owner decision D2) | Two tables duplicate the lifecycle, policy, and UI for one differing column; twin nullable foreign keys permit both-set and neither-set illegal states |
| A new `purchase_settings` singleton (E-6) | The approval threshold needs a home that a System Admin can edit at runtime | A config constant cannot be changed without a deploy; overloading `inventory_settings` would put a purchasing concern in the Inventory domain, violating Principle II |
| Adding an operation-completed event to Inventory (R-002) | Purchasing must react to receipt completion inside the same transaction without Inventory depending on Purchasing | A direct call from `InventoryOperationService` inverts the dependency; a queued listener breaks same-transaction consistency; a broad model observer cannot reliably identify a purchase-order receipt |

---

## Next Steps

1. **Owner action** — approve or amend the Governance Gate items. Nothing else proceeds first.
2. Run `/speckit-tasks` to generate `tasks.md` from this plan, with Phase G tasks ordered ahead of every implementation task.
3. Optionally run `/speckit-analyze` for a cross-artifact consistency check across `spec.md`, `plan.md`, and `tasks.md`.
4. Implement phase by phase, running the relevant `php artisan test --compact --filter=…` after each, and `composer test` before considering the feature complete.
