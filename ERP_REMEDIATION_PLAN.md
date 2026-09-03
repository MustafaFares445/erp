# ERP Remediation Plan — IERP

**Document type:** Implementation plan (gap → design → code → test)
**Perspective:** Principal Software Architect / Laravel ERP Architect
**Baseline:** `feat/cross-module-remediation` @ `b29a49a`
**Created:** 2026-09-03
**Companion:** `ERP_IMPLEMENTATION_PHASES.md` — sequencing, dependencies, exit criteria, effort

---

## 0. How to read this document

### 0.1 Inputs

| Input | Role in this plan |
|---|---|
| `Docs/EXPECTED_BUSINESS_SCENARIOS.md` | 128 scenarios — the acceptance criteria every work package must satisfy |
| `CURRENT_IMPLEMENTATION_MAP.md` | What exists at `b29a49a` — the starting point every design reuses |
| `BUSINESS_LOGIC_GAPS.md` | 40 gaps (3 Critical / 24 High / 12 Medium / 1 Low) — the work list |
| `Docs/CROSS_MODULE_BUSINESS_FLOWS.md`, `CROSS_MODULE_FLOW_MATRIX.md` | 21 flows, 60 seams — what each fix must restore |
| `Docs/adr/0001…0011` | Scope authority. Where the PRD and an ADR disagree, the ADR wins |

### 0.2 Work-package identifiers

Every gap maps to exactly one work package, `WP-<phase>.<n>`. Some work packages close several
gaps because those gaps share one root cause and splitting them would ship two half-fixes — for
example `WP-1.6` closes GAP-MW-04, GAP-MW-05 and GAP-UI-01, which are a service, a permission and
a button that are individually worthless.

### 0.3 The six sections each work package defines

| Section | Contents |
|---|---|
| **Database** | Migrations, relationships, constraints, backfills |
| **Domain** | Services, actions, events, policies, enums, exceptions |
| **API** | Command shape, read shape, endpoint contract (see §0.4) |
| **Filament** | Resources, actions, pages, widgets, relation managers |
| **Mobile** | Impact on the deferred field/customer channel |
| **Tests** | Unit, feature, integration — named files, named assertions |

### 0.4 A standing note on the API and Mobile sections

**There is no HTTP API in this repository.** `routes/` contains `web.php` and `console.php` only.
No `routes/api.php`, no Sanctum, no `JsonResource`, no mobile client. This is deliberate
(ADR 0003, ADR 0008) — and `Docs/api/API_CONTRACT.md` describes 276 lines of surface that was never
built, while `dedoc/scramble` sits in `composer.json` with nothing to document.

Writing "N/A" forty times would be accurate and useless. This plan takes a stricter position:

> **Every work package states the contract it *would* expose, even though no route is built.**

The reason is architectural. GAP-MW-19 (customer and employee channels) is a funded-later
decision, and the most expensive way to build it is to retrofit contracts onto forty services
designed only for Filament. So each work package names its **command shape** (the typed data object
its service accepts) and its **read shape** (the fields a client needs). Every service in this plan
takes a typed data object and returns a model or a typed result — **never reads `request()`**.
When the channel is funded, `WP-4.5` adds routes, form requests and API resources over services
already shaped for them, with no domain rewrite.

**Mobile** follows the same rule: it records what a field or customer app would need from this
package, so the deferred build inherits a specification instead of a discovery phase. Where a
package has no client consequence, the section says so in one line.

### 0.5 Constraints every work package inherits

From `.ai/feature-development`, `CLAUDE.md`, and the invariants the codebase already test-guards.
Stated once; **not** repeated per package.

1. **`InventoryBalanceService` remains the sole writer of stock rows**, reachable only through
   `InventoryPostingService`. No package writes `inventory_stocks`, `inventory_condition_balances`
   or `inventory_lot_balances` directly. Guarded by `Unit/ArchTest.php` and
   `Feature/InventoryDomainContractTest.php`.
2. **`JournalPostingService` keeps a narrow, named caller set** (AC-16). A new posting caller is an
   explicit, reviewed addition to `Feature/Accounting/NoAutomaticPostingTest.php` — never silent.
   This plan adds exactly **one**: `WriteOffPostingService` (WP-1.9).
3. **Delivery still posts no ledger entry.** COGS stays out of scope until `WP-4.6` is funded
   (ADR 0007 §11 / ADR 0008). `NoAutomaticPostingTest` passes unchanged through Phases 1–3.
4. **Posted documents are immutable.** Every correction is a new, linked document.
5. **New lifecycle state is a backed enum with `canTransitionTo()`** — the convention 14 enums
   already follow. No new `string` status column is created by this plan.
6. **No new PHPStan baseline entries.** The baseline may only shrink; a package touching a
   baselined file removes the entries it obsoletes.
7. **Every behaviour change ships with a Pest test**; every bug fix ships with a regression test.
8. `vendor/bin/pint --dirty` before every commit; `composer test` is the gate.
9. **Money is integer minor units.** Quantities are decimal strings through `QuantityNormalizer`.
   No floats anywhere in this plan.
10. **Every new sensitive action is audited** via `activity()` with the established
    `withProperties(['source_channel' => …, 'ip_address' => …])` convention.

---

## 1. Architectural decisions this plan makes

Several gaps cannot be fixed independently because they share one missing abstraction. Rather than
build the same thing three times, this plan introduces six shared primitives, each built once in
the phase where the first gap needs it, then widened.

### AD-01 — One condition-change document family, introduced narrow

**Closes / enables:** GAP-MW-03 (Critical), GAP-WL-03, GAP-UI-06.

IN-07, IN-08, IN-09 and IN-14 all demand the same artefact: *a document carrying a cause, an
authorising actor, before and after condition, and — for disposal — evidence*. Today damage,
recovery and disposal are modal actions on a stock row, and quarantine has no exit at all.

**Decision:** introduce `inventory_condition_changes` + `InventoryConditionChangeType`, posting
through the existing `InventoryDamageService` engine (which already computes correct
`conditionFrom` / `conditionTo` / serialised-custody transitions). Phase 1 ships **one** enum case,
`QuarantineDisposition`, because that is the Critical gap. Phase 3 adds `Damage`, `DamageRecovery`
and `Disposal` and migrates the modal actions onto the document.

This mirrors an established precedent: `InventoryCorrectionType` ships today with exactly one case
(`Receipt`) and is widened by `WP-2.11`. Shipping a document family narrow is the house pattern,
not a compromise.

**Rejected alternative:** extend `InventoryDamageService`'s `match` matrix to include Quarantine.
It moves the stock in about twenty lines, but IN-14 requires the decision, the inspector and the
reason to be recorded plus an ageing report — none of which a modal action can hold — and
`WP-3.3` would have to undo it.

### AD-02 — Document status becomes a type, uniformly

**Closes / enables:** GAP-WL-01, GAP-WL-04, and every future guard on those six document families.

Six money-carrying families (`invoices`, `payments`, `bills`, `expenses`, `supplier_payments`,
`refunds`) use `string(30)` columns compared against inline literals, while 14 other lifecycles are
backed enums with transition matrices. Three enum files exist as **empty stubs** advertising a type
system that was never built.

**Decision:** fill the three stubs, add four more, cast all six columns, and centralise the guard in
one concern, `App\Models\Concerns\TransitionsDocumentStatus`. **Stored values are unchanged**, so
this is a cast-and-guard change, not a data migration — except for the GAP-WL-04 backfill, which
repairs invoices whose `status` was overwritten with a confirmation type.

### AD-03 — A reporting shape, replicated rather than generalised

**Closes / enables:** GAP-MW-17, GAP-UI-02, GAP-UI-04, GAP-MW-16, GAP-UI-05.

`FinancialReportService`, `InventoryReportService`, `EmployeeReportService`,
`PurchasingReportService` and `SupportReportService` already share one shape: a `…ReportType` enum
with `sourcePermission()`, a service returning arrays, a read-only Filament page, and per-report
CSV export. Sales has none of it; AR and the tax register have a list where they need a proof.

**Decision:** do not invent a reporting framework. Replicate the known shape three more times —
`SalesReportService` + `SalesReportType`, `AccountsReceivableService` (mirroring
`AccountsPayableService` method-for-method), `TaxRegisterService`. A sixth instance of a known
shape is cheaper to review, test and operate than a first instance of a generalised one.

### AD-04 — Reconciliation results become a persisted, first-class artefact

**Closes / enables:** GAP-MW-16, GAP-BW-04, GAP-MW-18.

`InventoryLotReconciliationService::inspect()` already proves the right four invariants and is
reachable only from a shell. IN-17 needs its result as a *report*; AC-10 needs it as a *gate*.

**Decision:** introduce `reconciliation_runs` — an append-only record of every reconciliation
execution (scope, invariant, pass/fail, divergence detail, actor, timestamp). The scheduled command
writes one; the report reads them; the period-close checklist reads the latest per scope and
refuses to close on a failure. One artefact, three consumers.

### AD-05 — Notifications are events with a delivery log, not mail calls

**Closes / enables:** GAP-MW-12, and the reminder legs of SL-07, SU-03, IN-10, IN-11, PU-03.

XC-03's binding requirement is not "send email". It is *"every attempt and outcome is logged; a
failed delivery is recorded and retried, never silently dropped."*

**Decision:** Laravel notifications on a queued channel, plus `notification_templates`
(locale-aware, satisfying XC-08) and `notification_deliveries` (per-attempt outcome log). Domain
services **raise events**; a listener resolves recipients and dispatches. Services never call the
mailer. This keeps the "exactly one domain event" discipline honest by making the second, third and
fourth events deliberate rather than incidental.

### AD-06 — Price provenance is a column group that survives conversion

**Closes / enables:** GAP-BW-03, CR-08, and the discount-incidence report in GAP-MW-17.

`PriceResolver` produces a `ResolvedPrice` carrying a `ResolvedPriceSource`. It is persisted on
`quotation_lines.resolved_price_source` as an untyped `string(40)` and nowhere else.

**Decision:** treat provenance as a three-column group — `resolved_price_source` (cast enum),
`resolved_price_tier_id` (nullable FK: *which* tier won), `price_floor_override_id` (nullable FK:
*who* permitted the breach) — present on `quotation_lines`, `order_lines` and `invoice_lines`, and
copied forward by every conversion service. A shared `CarriesPriceProvenance` concern owns the
copy so a fourth document type cannot forget.

---

## 2. Cross-cutting deliverables

Four items are not gaps but are required by more than one work package. They are built first so
later packages depend on something that exists.

| ID | Deliverable | Consumed by |
|---|---|---|
| **CC-01** | `App\Models\Concerns\TransitionsDocumentStatus` — one guard, `assertCanTransitionTo()`, throwing `IllegalStatusTransition` | WP-1.8 and all six document families |
| **CC-02** | `App\Exceptions\Domain\` consolidation — `IllegalStatusTransition`, `SelfConfirmationRejected`, `QuarantineDispositionRejected`, `DuplicateSupplierReference`, `PeriodCloseBlocked`, `CreditExceedsReturn` | WP-1.1, 1.3, 1.4, 1.5, 2.5 |
| **CC-03** | `App\Services\Concerns\EnforcesMakerChecker` — extracted from the pattern `RefundService::approve()` and `PurchaseOrderApprovalService::approve()` already implement twice, independently | WP-1.4, WP-1.9, WP-3.5 |
| **CC-04** | `tests/Pest.php` helpers — `actingAsInventoryManager()`, `actingAsAccountant()`, `seedPostingAccounts()`: the `beforeEach` block duplicated across ~40 existing test files | every test in this plan |

**CC-03 note.** The extraction is behaviour-preserving and must be proven so: `RefundService` and
`PurchaseOrderApprovalService` keep their existing exception types and messages
(`'The user who recorded a refund cannot approve it.'`, `SelfApprovalRejected`), and their existing
tests run unchanged. The concern supplies the check, not the message.

---

# Phase 1 — Critical business correctness

**Theme:** stop money and stock from being wrong, and switch on the controls that are already
written, tested and unreachable.

Every package in this phase is contained within one module. None depends on a package in a later
phase. Eight of the ten are small changes to code that already works.

---

## WP-1.1 — Quarantine disposition (GAP-MW-03)

| | |
|---|---|
| **Priority** | **Critical** — the only gap where a correct, routine business action strands company assets with no in-system remedy |
| **Scenario** | IN-14; IN-01 step 3; IN-13 step 3 |
| **Flow** | F-08, F-16; Inventory internal seam |
| **Depends on** | CC-02 |
| **Blocks** | WP-3.3 (condition-change documents), WP-1.2 (shares the multi-condition posting path) |

### Problem restated

`StockCondition::Quarantine` is a fully materialised balance and stock enters it from two paths
(`InventoryReturnDisposition::Quarantine`, and receipt-time condition capture). No service moves
stock out. `InventoryDamageService::execute()` hard-codes its condition matrix to
`Saleable ↔ Damaged → Disposed` (`app/Services/Inventory/InventoryDamageService.php:270-282`);
`InventoryAdjustmentService` touches `Saleable` exclusively. Quarantined goods are removed from
availability permanently, and the only remedy is a direct database edit — which bypasses the
movement ledger and breaks the IN-17 invariants.

### Database

**Migration `2026_09_04_100000_create_inventory_condition_changes_table.php`**

```
inventory_condition_changes
  id
  document_number            string(30)  unique        -- ICC-2026-00001, via DocumentNumberGenerator
  type                       string(40)                -- InventoryConditionChangeType, cast
  status                     string(20)                -- InventoryConditionChangeStatus, cast
  product_variant_id         FK product_variants        restrictOnDelete
  warehouse_id               FK warehouses              restrictOnDelete
  inventory_lot_id           FK inventory_lots nullable nullOnDelete
  serialized_inventory_unit_id FK nullable              nullOnDelete
  condition_from             string(20)                -- StockCondition, cast
  condition_to               string(20)                -- StockCondition, cast
  base_quantity              decimal(18,6)
  disposition                string(30) nullable       -- QuarantineDisposition, cast
  reason_category            string(40)                -- ConditionChangeReason, cast (controlled list)
  reason                     text                      -- free text, required
  inspected_by               FK users nullable          nullOnDelete
  inspected_at               timestamp nullable
  posted_by                  FK users nullable          nullOnDelete
  posted_at                  timestamp nullable
  created_by                 FK users                   restrictOnDelete
  inventory_movement_id      FK inventory_movements nullable  nullOnDelete
  supplier_return_id         FK inventory_returns nullable    nullOnDelete
  timestamps, softDeletes

  index (product_variant_id, warehouse_id, condition_from, status)
  index (status, created_at)      -- quarantine ageing report
```

**Constraints and rationale**

- `document_number` unique — XC-01 (every business document is numbered predictably).
- `restrictOnDelete` on variant, warehouse and `created_by`: this is evidence for a stock movement;
  it must not vanish. Contrast GAP-BW-08, which this plan fixes for exactly this reason.
- `inventory_movement_id` is written after posting, so the document points at its own ledger effect
  (AC-11's two-way document ↔ entry navigation, applied to stock).
- **No** `unique` on `(variant, warehouse, lot)` — quarantine may be dispositioned in several
  tranches, and IN-14 does not require all-or-nothing.

**Backfill:** none. Existing quarantine balances become dispositionable the moment the service
exists; no historical document is fabricated for stock that entered quarantine before this build.
A one-line note is added to the ageing report explaining that pre-`WP-1.1` quarantine has no
inbound document.

### Domain

**New enums**

```php
// app/Enums/InventoryConditionChangeType.php  — narrow by AD-01
enum InventoryConditionChangeType: string {
    case QuarantineDisposition = 'quarantine_disposition';
    // Phase 3 (WP-3.3) adds: Damage, DamageRecovery, Disposal
}

// app/Enums/InventoryConditionChangeStatus.php
enum InventoryConditionChangeStatus: string {
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
    public function canTransitionTo(self $t): bool { /* Draft → Posted|Cancelled; both terminal */ }
}

// app/Enums/QuarantineDisposition.php  — the four IN-14 outcomes
enum QuarantineDisposition: string {
    case ReleaseToSaleable = 'release_to_saleable';
    case DowngradeToDamaged = 'downgrade_to_damaged';
    case Dispose = 'dispose';
    case ReturnToSupplier = 'return_to_supplier';

    public function conditionTo(): StockCondition { /* Saleable | Damaged | Disposed | Saleable* */ }
    public function requiresSupplierReturn(): bool { return $this === self::ReturnToSupplier; }
}

// app/Enums/ConditionChangeReason.php — IN-06's "reason from a controlled list plus free text"
enum ConditionChangeReason: string {
    case QualityInspectionPassed, QualityInspectionFailed, SupplierDefect,
         ExpiredOnArrival, DamagedInTransit, CustomerReturnInspection, Other;
}
```

`ReturnToSupplier` does **not** move the balance itself: it hands the quantity to
`InventoryReturnService::createSupplierReturn()`, which owns the outbound posting. The condition
change records the decision and links the supplier return; the movement is the return's.

**New service `App\Services\Inventory\InventoryConditionChangeService`**

```php
final readonly class InventoryConditionChangeService
{
    public function __construct(
        private InventoryPostingService $postingService,
        private InventoryReturnService $returnService,
        private InventoryAlertService $alertService,
        private DocumentNumberGenerator $numbers,
    ) {}

    public function draftQuarantineDisposition(
        QuarantineDispositionData $data, User $actor,
    ): InventoryConditionChange;

    public function post(InventoryConditionChange $change, User $actor): InventoryConditionChange;

    public function cancel(InventoryConditionChange $change, User $actor, string $reason): InventoryConditionChange;
}
```

`post()` runs inside `DB::transaction(…, attempts: 5)` — matching `InventoryDamageService` — and:

1. Locks the change row, then the stock row via `InventoryBalanceService::stockForUpdate()`.
2. Asserts status `Draft` through `canTransitionTo`.
3. Asserts `condition_from === StockCondition::Quarantine`
   (throws `QuarantineDispositionRejected` otherwise — this service does not become a general
   condition mover before WP-3.3 says so).
4. Asserts the quarantine balance covers `base_quantity` at the aggregate **and**, for tracked
   variants, the lot and serial grain.
5. For `ReturnToSupplier`: delegates to `InventoryReturnService::createSupplierReturn()` and stores
   `supplier_return_id`. For the other three: builds an `InventoryPostingCommand` with
   `conditionFrom: Quarantine`, `conditionTo: $disposition->conditionTo()`,
   `conditionTransferBaseQuantity`, and the serialised status/custody targets, then calls
   `InventoryPostingService::post()`.
6. Writes `inventory_movement_id`, `posted_by`, `posted_at`; status → `Posted`.
7. `activity()->log('inventory.condition_change.posted')` with before/after balances,
   `source_channel`, `ip_address`.
8. `InventoryAlertService::syncStock()`.

**Maker/checker.** IN-14 names two actors (Inventory Manager, Quality reviewer) but does not
mandate that they differ. This package therefore keeps drafting and posting as two permissions but
**does not** enforce maker ≠ checker — that is a deliberate, recorded decision, unlike WP-1.4 where
IN-06 states the separation explicitly. If the business wants it, flipping on `CC-03` is a one-line
change plus a test.

**Data object `App\Data\Inventory\QuarantineDispositionData`** — readonly, constructor-promoted:
`productVariantId`, `warehouseId`, `inventoryLotId`, `serializedInventoryUnitId`,
`baseQuantity` (string), `disposition`, `reasonCategory`, `reason`, `inspectedBy`, `inspectedAt`.

**Policy `App\Policies\InventoryConditionChangePolicy`** using `ChecksInventoryPermissions`:
`viewAny/view` → `ConditionChangeView`, `create` → `ConditionChangeCreate`,
`post` → `ConditionChangePost`, `cancel` → `ConditionChangeCancel`.

**Permissions** added to `InventoryPermission` and `InventoryPermissionSeeder`:
`inventory.condition-change.view|create|post|cancel`.

**Events:** none in this package. Condition change raises no cross-module signal; adding one would
violate constraint §0.5.2 for no consumer.

### API

**Not built** (§0.4). Contract this package makes available to WP-4.5:

- Command shape: `QuarantineDispositionData` — already the service's only input.
- `POST /api/v1/inventory/condition-changes` → draft; `POST …/{id}/post`; `POST …/{id}/cancel`.
- Read shape: `GET /api/v1/inventory/quarantine?warehouse_id=&aged_over_days=` returning
  `{variant, warehouse, lot, quantity, days_in_quarantine, inbound_document}`.
- The service returns models, not responses, so an API resource is a pure projection.

### Filament

**New resource `app/Filament/Resources/InventoryConditionChanges/`**, following the
`InventoryCorrections` layout exactly:

```
InventoryConditionChangeResource.php
Pages/ListInventoryConditionChanges.php
Pages/CreateInventoryConditionChange.php
Pages/ViewInventoryConditionChange.php
Actions/InventoryConditionChangeActions.php     -- post(), cancel()
Schemas/InventoryConditionChangeForm.php
Schemas/InventoryConditionChangeInfolist.php
Tables/InventoryConditionChangesTable.php
```

- Actions use `InteractsWithInventoryServices`, `visible()` + `authorize()` on the policy, and
  `Notification::make()->success()` — the `CreditNoteActions` shape.
- **Entry point from the stock row.** `StockLevels/Actions/StockDamageActions` gains a sibling
  `disposition_quarantine` action, visible only when the row's quarantine balance is non-zero. It
  pre-fills variant, warehouse and quantity and routes to the create page — so the operator reaches
  the document from the number that told them there was a problem (IN-18).
- **New report** `InventoryReportType::QuarantineAgeing` — variant, warehouse, lot, quantity, days
  in quarantine, oldest inbound movement, bucketed 0–7 / 8–30 / 31–90 / 90+.
  `sourcePermission()` → `StockView`. This is IN-14's "ageing report, never a parking space."
- **Widget** `InventoryQuarantineAgeing` on `InventoryDashboard` — count and total quantity aged
  over 30 days, linking to the report.

### Mobile

A warehouse app would carry the inspection: scan a lot or serial in quarantine, choose one of the
four dispositions, capture a reason and a photo, submit. The document's `inspected_by` /
`inspected_at` exist precisely so the inspection can be captured in the field and posted in the
office. **No client work in this phase**; the schema does not need to change when it lands.

### Tests

**Unit**
- `tests/Unit/Enums/QuarantineDispositionTest.php` — `conditionTo()` for all four cases;
  `requiresSupplierReturn()` true only for `ReturnToSupplier`.
- `tests/Unit/Enums/InventoryConditionChangeStatusTest.php` — the full transition matrix, both
  legal and illegal directions.

**Feature** — `tests/Feature/Inventory/QuarantineDispositionTest.php`
- Release to saleable moves quarantine → saleable, writes one movement, leaves on-hand unchanged.
- Downgrade moves quarantine → damaged.
- Dispose moves quarantine → disposed and reduces on-hand.
- Return to supplier creates an `InventoryReturn` of type `Supplier` and links it; the condition
  change posts **no** movement of its own.
- Disposition exceeding the quarantine balance throws and posts nothing.
- Disposition of a lot-tracked variant without a lot throws.
- A serialised unit in quarantine transitions status and custody correctly.
- Posting twice throws `IllegalStatusTransition`; the second attempt writes no movement.
- Cancelling a draft posts nothing and is terminal.
- An unpermitted actor is refused at the policy.
- **Ledger invariant:** after each disposition,
  `InventoryLotReconciliationService::inspect()` reports zero divergence on all four invariants.

**Feature (Filament)** — `tests/Feature/Filament/InventoryConditionChangeResourceTest.php`
- List, create, view render for a permitted actor; are refused for an unpermitted one.
- The `post` action calls the service and shows the success notification.
- The stock-row `disposition_quarantine` action is hidden when quarantine is zero.

**Integration** — `tests/Feature/Inventory/QuarantineLifecycleIntegrationTest.php`
- Receive to quarantine → disposition to saleable → reserve → deliver. Asserts the goods become
  sellable and the movement ledger replays to the final balance.
- Customer return dispositioned to quarantine (IN-13 step 3) → quarantine disposition to damaged →
  IN-17 invariants hold.

---

## WP-1.2 — Adjustments and counts across every condition (GAP-WL-03)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | IN-06, IN-17, IN-18 |
| **Depends on** | WP-1.1 (shares the multi-condition posting path and the reason enum) |
| **Blocks** | WP-3.5 (physical count document) |

### Problem restated

`InventoryPostingService::materializedConditions()` maintains three condition balances and derives
on-hand from all three. `InventoryAdjustmentService` reads and writes `StockCondition::Saleable`
**only** — line 230 compares against `conditionOnHandQuantity(Saleable)`, and lines 316, 323, 326,
343 require `$unit->stock_condition === StockCondition::Saleable`. If a count finds damaged or
quarantined quantity wrong, the adjustment corrects *saleable* to fix an aggregate discrepancy that
originated elsewhere — balancing the total while making both condition balances wrong. That is
precisely the silent divergence IN-06 exists to prevent.

### Database

**Migration `2026_09_04_100200_add_condition_to_inventory_adjustment_items.php`**

```
inventory_adjustment_items
  + stock_condition  string(20)  not null  default 'saleable'   -- StockCondition, cast
  index (inventory_adjustment_id, stock_condition)
```

**Backfill:** the default `'saleable'` is historically correct — every existing item *was* a
saleable adjustment, because the service could not produce anything else. The default is dropped in
a follow-up statement inside the same migration so new rows must be explicit.

`inventory_adjustments` gains `reason_category string(40)` (cast `ConditionChangeReason`, nullable
for history, required for new rows at the service layer) to satisfy IN-06's controlled list.

### Domain

- `InventoryAdjustmentService::createCorrection()` and `confirm()` take the item's
  `stock_condition` through to the posting command instead of assuming `Saleable`.
- The serialised-unit guards at lines 316/323/326/343 change from
  `=== StockCondition::Saleable` to `=== $item->stock_condition`, preserving every other assertion.
- The variance computation compares against
  `conditionOnHandQuantity($item->stock_condition)`.
- **New guard:** `StockCondition::Disposed` is refused — disposed stock is gone, not miscounted.
  Throws `DomainException` naming the condition.
- **New guard:** a tracked variant still cannot be adjusted without lot or serial identity — the
  existing rule is unchanged and its test is extended to cover the two new conditions.

No new service. This is a widening of an existing one, which keeps the change reviewable.

### API

Command shape gains one field: `InventoryAdjustmentItemData.stockCondition`. No route.

### Filament

- `Adjustments/Schemas/AdjustmentForm.php` — the line repeater gains a `stock_condition` select,
  defaulting to `Saleable`, options limited to the three materialised conditions.
- `Adjustments/Tables/AdjustmentsTable.php` — a condition column and filter.
- The variance preview shows system-vs-counted **per condition**, not per variant.

### Mobile

A count app must send the condition with each counted line. Recorded for WP-4.5.

### Tests

**Unit** — `tests/Unit/Enums/StockConditionTest.php` extended: `isMaterialized()` excludes only
`Disposed`; `allowsReservation()` only `Saleable`.

**Feature** — extend `tests/Feature/Inventory/InventoryAdjustmentServiceTest.php`
- Adjusting damaged quantity up and down moves only the damaged balance; saleable is untouched.
- Adjusting quarantine quantity likewise.
- Aggregate on-hand equals the sum of the three condition balances after each case.
- Adjusting `Disposed` throws.
- A lot-tracked variant adjusted in the damaged condition without a lot throws.
- A serialised unit in the damaged condition is adjustable; one in `Disposed` is not.
- **Regression:** an existing saleable-only adjustment behaves byte-identically (the pinned
  assertions from the current test file must pass unchanged).

**Integration** — `tests/Feature/Inventory/ConditionBalanceReconciliationTest.php`
- Damage 5, adjust damaged to 3, recover 3 → all four IN-17 invariants hold at each step.

---

## WP-1.3 — Return ↔ credit note linkage (GAP-BW-01)

| | |
|---|---|
| **Priority** | **Critical** — the same returned goods can be credited twice, and neither leg can prove the other happened |
| **Scenario** | IN-13 step 5, SL-12 step 6, AC-04 |
| **Flow** | F-03 (Product return and credit note) |
| **Depends on** | CC-02 |

### Problem restated

Both documents are individually strong. `InventoryReturnService` caps returns against the source
delivery and validates lot and serial identity through four distinct guards. `CreditNoteService`
caps each credited quantity at the invoice line's uncredited remainder and posts a correctly split
reversal. **There is no foreign key in either direction.** The return caps against the *delivery*;
the credit note caps against the *invoice*; neither sees the other. So: double-crediting is
unprevented, goods can come back with no credit, a credit can be raised for goods that never came
back, and IN-13's "customer retained the goods" credit is indistinguishable from a clerical
omission.

### Database

**Migration `2026_09_04_100300_link_credit_notes_to_inventory_returns.php`**

```
credit_notes
  + inventory_return_id   FK inventory_returns nullable  nullOnDelete
  + stock_consequence     string(30) not null default 'not_applicable'  -- CreditNoteStockConsequence
  index (inventory_return_id)

credit_note_lines
  + inventory_return_line_id  FK inventory_return_lines nullable  nullOnDelete
  unique (credit_note_id, inventory_return_line_id)   -- one return line credited once per note
  index (inventory_return_line_id)

inventory_returns
  + credit_note_required  boolean not null default false   -- set by disposition/reason at creation
```

**Why `nullOnDelete` and not `restrictOnDelete`:** an `InventoryReturn` is soft-deleted, never hard
deleted, in normal operation; `nullOnDelete` is the safe posture for a link that is informational
on the financial side. The *quantity* control does not rely on the FK surviving — see the domain
section.

**Why the link is at line grain:** the cap must be computed per returned line, per variant, per lot.
A header-only link would let a credit note for variant A be justified by a return of variant B.

**Backfill:** none — historical returns and credit notes stay unlinked and are reported as such by
the new exception report (below), rather than being guessed at by a heuristic join. Guessing here
would manufacture false evidence, which is worse than an explicit unknown.

### Domain

**New enum**

```php
// app/Enums/CreditNoteStockConsequence.php  — IN-13's explicit-nil requirement
enum CreditNoteStockConsequence: string {
    case GoodsReturned = 'goods_returned';      // linked to an InventoryReturn
    case CustomerRetained = 'customer_retained'; // credit with the stock consequence explicitly nil
    case NotApplicable = 'not_applicable';       // pricing/tax/commercial adjustment
    public function requiresReturnLink(): bool { return $this === self::GoodsReturned; }
}
```

**`CreditNoteService` changes**

- `addLine()` gains an optional `inventoryReturnLineId`. When present, the credited quantity is
  capped at `min(invoiceLineUncreditedRemainder, returnLineReturnedQuantity − alreadyCreditedFromThatReturnLine)`.
  Exceeding it throws `CreditExceedsReturn`.
- `confirm()` asserts consistency before posting:
  - `stock_consequence === GoodsReturned` ⟹ `inventory_return_id` is set **and** the return is
    `Posted` **and** every line carries an `inventory_return_line_id`.
  - `reason_category === CreditNoteReason::SalesReturn` ⟹ `stock_consequence` is
    `GoodsReturned` or `CustomerRetained` — never `NotApplicable`. This is the check that makes a
    customer-retained credit *representable* rather than indistinguishable from an omission.
  - The linked return's customer matches the invoice's customer.
- A new read method `creditedQuantityForReturnLine(InventoryReturnLine $line): string` — used by
  the cap, the infolist and the exception report, so all three agree by construction.

**`InventoryReturnService` changes**

- `createCustomerReturn()` sets `credit_number_required = true` when the disposition implies a
  commercial consequence, so the exception report has a denominator.
- No financial behaviour is added. IN-13's rule that "the return posts no financial entry by
  itself" is preserved, and `NoAutomaticPostingTest` is extended to assert that the new FK did not
  introduce one.

**Concurrency.** The cap is a read-then-write. `confirm()` already locks the credit note; it now
also locks the linked `inventory_return_lines` rows in id order inside the same transaction —
the deadlock-safe ordering the receipt listener already uses. A unique index on
`(credit_note_id, inventory_return_line_id)` closes the same-note race; the row lock closes the
cross-note race.

**Policy:** unchanged. Linking is part of creating and confirming a credit note.

### API

Command shape: `CreditNoteLineData.inventoryReturnLineId`, `CreditNoteData.stockConsequence`.
Read shape: a return exposes `credited_quantity` per line; a credit note exposes its return link.
No route.

### Filament

- `CreditNotes/Schemas/CreditNoteForm.php` — a `stock_consequence` radio, and when
  `GoodsReturned` is chosen, a searchable `inventory_return_id` select scoped to the invoice's
  customer and to `Posted` returns. Line repeater gains a return-line select scoped to the chosen
  return and the line's variant, showing returned and already-credited quantities in the label.
- `CreditNotes/Schemas/CreditNoteInfolist.php` — a "Goods return" section linking to the return,
  or the explicit text "Customer retained the goods — no stock consequence."
- `Returns/Schemas/InventoryReturnInfolist.php` — a reciprocal "Credit notes" entry, per line,
  showing returned vs credited quantity.
- `Returns/Actions/InventoryReturnActions.php` — a `create_credit_note` action on a posted customer
  return, pre-filling the invoice, the lines and `stock_consequence = GoodsReturned`. This is the
  path that makes the linked case the easy one.
- **New report** `SalesReportType::ReturnsWithoutCredit` (delivered by WP-2.8, specified here):
  posted customer returns with no confirmed credit note, aged. The exception list that makes the
  unlinked history visible instead of silently wrong.

### Mobile

A customer app showing "return received / credit issued" needs both sides of this link. Read shape
recorded for WP-4.5.

### Tests

**Unit** — `tests/Unit/Enums/CreditNoteStockConsequenceTest.php`: `requiresReturnLink()`.

**Feature** — `tests/Feature/Sales/CreditNoteReturnLinkTest.php`
- Crediting a linked return line at exactly the returned quantity succeeds.
- Crediting one unit more throws `CreditExceedsReturn` and confirms nothing.
- Two credit notes against the same return line: the second is capped at the remainder; a third
  for any quantity throws.
- Confirming a `GoodsReturned` note with no return link throws.
- Confirming a `SalesReturn`-reason note with `NotApplicable` consequence throws.
- A `CustomerRetained` note confirms with **no** return link and posts the normal reversal —
  and asserts zero inventory movements were written.
- Cross-customer link (return of customer A, invoice of customer B) throws.
- The invoice-line uncredited-remainder cap still binds when it is tighter than the return cap.

**Feature (regression)** — `tests/Feature/Sales/CreditNoteLifecycleTest.php` runs unchanged:
an unlinked `PricingAdjustment` credit note is unaffected by this package.

**Integration** — `tests/Feature/Sales/ReturnToCreditNoteFlowTest.php` (flow F-03 end to end)
- Deliver 10 → invoice 10 → issue → collect → return 4 (disposition saleable) → credit 4 →
  confirm. Asserts: stock is back at the right condition, the credit note reverses revenue and
  splits the tax correctly, `invoices.credited_amount` moved by exactly the credited total, the
  return line reports `credited_quantity = 4`, and a second credit against the same line is
  refused.
- Goods never come back → `CustomerRetained` credit only → asserts no movement and a
  representable, distinguishable record.

**Concurrency** — `tests/Feature/Sales/CreditNoteReturnConcurrencyTest.php`: two concurrent
confirmations against the same return line; exactly one succeeds.

---

## WP-1.4 — Maker/checker on stock adjustments (GAP-WL-02)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | IN-06; §1.3 separation-of-duties principle 3 |
| **Depends on** | CC-03 |

### Problem restated

`InventoryAdjustmentService::confirm()` (lines 85–160) is otherwise correct — it locks, re-checks
`Draft`, validates the warehouse and posts canonically — but never compares `$actor` to the
adjustment's `created_by`. One user holding both permissions can create an adjustment and confirm
it in one uninterrupted action. Stock adjustment is the single operation that can make an inventory
discrepancy disappear with no counterparty. The same codebase enforces this control for money twice
(`RefundService::approve()`, `PurchaseOrderApprovalService::approve()`), so the pattern exists and
was simply not applied to goods.

### Database

None. `inventory_adjustments.created_by` already exists and is populated.

**Verification step (not a migration):** a read-only check that no existing row has a null
`created_by`, run as part of the deployment checklist. If any exist, they are reported, not
back-filled — a null maker cannot be invented.

### Domain

- `App\Services\Concerns\EnforcesMakerChecker` (CC-03):
  `assertDifferentActor(?int $makerId, User $checker, string $message): void`.
- `InventoryAdjustmentService::confirm()` calls it immediately after the `Draft` re-check and
  before the warehouse validation, throwing `SelfConfirmationRejected`.
- **Configurable escape, deliberately narrow:** none. IN-06 states the separation without
  exception, and an override switch would recreate the gap. If a single-operator site needs it,
  that is a business decision requiring its own ADR, not a config flag added pre-emptively.
- `InventoryAdjustmentPolicy::confirm()` additionally returns `false` when
  `$adjustment->created_by === $user->id`, so the Filament action is hidden as well as refused.
  **Hiding is not authorisation** (XC-05) — the service guard is the control; the policy is
  ergonomics.

### API

The refusal is a domain exception, so any channel inherits it. No route.

### Filament

- `Adjustments/Actions/AdjustmentActions::confirm()` — `visible()` and `authorize()` already
  delegate to the policy, so the button disappears for the maker with no action-file change beyond
  a clearer `modalDescription`.
- `Adjustments/Tables/AdjustmentsTable.php` — a "Created by" column, so a confirmer can see whose
  work they are checking, and a "Pending my confirmation" filter (`created_by != auth()->id()` and
  status `Draft`), which turns the control into a worklist rather than an obstacle.

### Mobile

None.

### Tests

**Feature** — `tests/Feature/Inventory/InventoryAdjustmentMakerCheckerTest.php`
- The creator confirming their own adjustment throws `SelfConfirmationRejected` and posts nothing —
  asserted by movement count **and** by unchanged balances.
- A different user with `AdjustmentConfirm` succeeds.
- A different user *without* the permission is refused by the policy, not by the maker check
  (asserting the two controls are independent).
- `InventoryAdjustmentPolicy::confirm()` returns false for the maker.

**Feature (Filament)** — extend `tests/Feature/Filament/AdjustmentResourceTest.php`: the confirm
action is absent for the maker and present for a second confirmer.

**Unit** — `tests/Unit/Services/EnforcesMakerCheckerTest.php`: null maker, same actor, different
actor.

**Regression** — `tests/Feature/Accounting/RefundLifecycleTest.php` and the purchase-order approval
tests run unchanged after the CC-03 extraction, with their original exception messages.

---

## WP-1.5 — Close the duplicate supplier-invoice control (GAP-BW-05)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | PU-09 — "the supplier's own invoice number is unique per supplier; **this is the primary duplicate-payment control**" |
| **Depends on** | CC-02 |

### Problem restated

`AccountingDocumentService` lines 502–515 implement the check, but line 502 reads
`if (blank($bill->supplier_reference)) { return; }` — **a bill with no reference bypasses the
control entirely.** And there is no database constraint: `bills.bill_number` is unique (the
*internal* number), while `supplier_reference` carries no index at all, so the guard is a
service-layer read-then-write with no protection against a concurrent duplicate. Since bill
approval recognises the payable and supplier payment clears it, a duplicate flows straight through
to a duplicate disbursement.

### Database

**Migration `2026_09_04_100500_enforce_supplier_reference_uniqueness.php`**

Three statements, in order, and the order matters:

1. **Report first, migrate second.** The migration begins by selecting existing
   `(supplier_id, supplier_reference)` duplicates and existing blank references. If any duplicate
   exists, the migration **throws** with the offending bill numbers listed. A unique index applied
   over dirty data either fails opaquely or silently drops rows; neither is acceptable for
   payables. The remediation runbook (`ERP_IMPLEMENTATION_PHASES.md` §5) covers the manual
   resolution.
2. `bills.supplier_reference` → `string(100)`, `not null`, `default ''` removed at the service
   layer (see below); blank rows are back-filled with a generated placeholder
   `LEGACY-{bill_number}` **and flagged** via a new nullable `supplier_reference_backfilled_at`
   timestamp, so the audit trail never claims the company knows a reference it does not.
3. `unique (supplier_id, supplier_reference)` — a partial index is not portable across the
   supported drivers, hence the placeholder approach rather than a filtered unique.

```
bills
  ~ supplier_reference             string(100) not null      (was nullable)
  + supplier_reference_backfilled_at timestamp nullable
  unique (supplier_id, supplier_reference)
```

### Domain

- `AccountingDocumentService::recordBill()` / `approveBill()`:
  - The `blank()` early return is **deleted**.
  - A blank reference is now a validation failure at recording time, throwing
    `DuplicateSupplierReference`'s sibling `SupplierReferenceRequired`. PU-09 makes the reference
    the control; a bill without one cannot be controlled.
  - The existing duplicate query is retained as the friendly error path, and the write is wrapped
    so a `QueryException` on the unique index is translated into the same
    `DuplicateSupplierReference` domain exception. Two layers, one message: the service explains,
    the database guarantees.
  - The supplier row is locked (`lockForUpdate`) before the duplicate read, matching the pattern
    the purchase-order receipt listener already uses.
- **Deliberate scope note.** PU-11 expenses have no supplier bill and are unaffected. Credit-side
  supplier documents (debit notes) are GAP-MW-14, deferred by ADR 0006 §11.

### API

Command shape: `BillData.supplierReference` becomes non-nullable. Recorded for WP-4.5.

### Filament

- `Bills/Schemas/BillForm.php` — `supplier_reference` becomes `required()`, with a
  `unique(ignoreRecord: true, modifyRuleUsing: …)` scoped to the selected supplier so the operator
  sees the clash before submitting rather than as an exception.
- `Bills/Tables/BillsTable.php` — a "Backfilled reference" badge on rows carrying
  `supplier_reference_backfilled_at`, so the placeholder is never mistaken for a real reference.
- `PurchasingReports` gains a `DuplicateReferenceAttempts` entry sourced from the refusal audit
  entries, so a repeatedly-attempted duplicate is visible rather than merely blocked.

### Mobile

None.

### Tests

**Feature** — `tests/Feature/Accounting/DuplicateSupplierReferenceTest.php`
- Two bills, same supplier, same reference → the second throws `DuplicateSupplierReference` and no
  bill row is created.
- Same reference, **different** suppliers → both succeed (the control is per supplier).
- A blank reference throws `SupplierReferenceRequired` at recording.
- The database unique index rejects a direct insert that bypasses the service — proving the
  guarantee is not only in PHP.
- Approving a bill is unaffected when the reference is unique.

**Feature (concurrency)** — `tests/Feature/Accounting/DuplicateSupplierReferenceConcurrencyTest.php`
- Two simultaneous `recordBill` calls with the same reference; exactly one succeeds and the other
  surfaces the domain exception, not a raw `QueryException`.

**Feature (migration)** — `tests/Feature/Accounting/SupplierReferenceBackfillTest.php`
- Seeded blank references are back-filled to `LEGACY-{bill_number}` and stamped.
- Seeded duplicates cause the migration to throw with the offending bill numbers named.

**Regression** — `tests/Feature/Accounting/PayablesLifecycleTest.php` passes unchanged with
references supplied.

---

## WP-1.6 — Complete the reservation lifecycle (GAP-MW-04, GAP-MW-05, GAP-UI-01)

| | |
|---|---|
| **Priority** | **High ×3** |
| **Scenario** | IN-03 alternate paths; IN-18 |
| **Flow** | F-15 |
| **Why one package** | A scheduler line, a permission that is declared and never checked, and a button. Each alone is inert |

### Problem restated

`InventoryReservationService::expire()` (line 156) is implemented and correct. **Its only caller in
the entire repository is a test.** `routes/console.php` schedules three commands, none of them
reservations. `ReservationStatus::Expired` is therefore unreachable in production, so stock held by
an abandoned quotation stays reserved without bound — available quantity understates reality,
salespeople are told stock is unavailable while it sits on the shelf, and the low-stock queue fires
against phantom demand, triggering real purchasing spend. The documented remedy is equally inert:
`release()` exists, `InventoryPermission::ReservationRelease` is declared and referenced **zero
times**, and `InventoryReservationResource` has a single page with no actions at all.

### Database

**Migration `2026_09_04_100600_add_reservation_release_evidence.php`**

```
inventory_reservations
  + released_by     FK users nullable   nullOnDelete
  + released_at     timestamp nullable
  + release_reason  string(255) nullable
  index (status, expires_at)      -- the expiry sweep's covering index
```

`expires_at` already exists. The index makes the sweep a range scan rather than a table scan, which
matters because it runs hourly against a table that grows with every sales document.

### Domain

**New command `App\Console\Commands\ExpireInventoryReservationsCommand`**
signature `inventory:reservations:expire`, purpose *"Expire active reservations whose validity has
lapsed, freeing the stock they hold."*

- Selects `status = Active AND expires_at <= now()`, chunked by id (`chunkById`, 500) so a backlog
  cannot exhaust memory.
- Calls `InventoryReservationService::expire()` per reservation — **the existing, tested method**;
  no new expiry logic is written.
- `actor` is null (system-caused), which the service already accepts.
- Emits a summary line and returns a non-zero exit code if any individual expiry threw, so the
  scheduler surfaces failure.

**Scheduler** — `routes/console.php`:
```php
Schedule::command('inventory:reservations:expire')->hourly();
```

**New event `App\Events\InventoryReservationExpired`** — carrying the reservation and its source
document. Consumed by:
- A listener flagging the source sales document as **no longer covered** — IN-03's alternate path
  requires this and nothing does it today. The flag is a derived read (`Order`/`Quotation` gains a
  `hasLapsedReservations()` accessor plus a table badge), **not** a stored status, so no lifecycle
  is corrupted.
- WP-2.10's notification listener, once it exists.

This is the plan's second domain event, added deliberately per §0.5 with two named consumers.

**`InventoryReservationService::release()`** gains `?string $reason` and writes
`released_by` / `released_at` / `release_reason`, then audits
`inventory.reservation.released` with `source_channel` and `ip_address`. The reason is **required**
when an actor is present and null when the system releases through document cancellation — so a
human release is always explained and an automatic one is never asked to invent a justification.

**Policy** — `InventoryReservationPolicy::release()` → `InventoryPermission::ReservationRelease`.
This is the first reference to that permission anywhere in `app/`.

### API

- Command shape: `release(InventoryReservation, User, string $reason)` — already service-shaped.
- Read shape for IN-18 drill-through: `GET /api/v1/inventory/reservations?variant_id=&warehouse_id=`
  returning source document type, id, number, quantity, expiry, allocations.
- No route.

### Filament

`InventoryReservations` goes from one bare page to a working screen:

- **`Actions/InventoryReservationActions::release()`** — a modal requiring a reason (`Textarea`,
  `required`, min 10 characters), `visible()`/`authorize()` on the policy, calling the service via
  `InteractsWithInventoryServices`, then a success notification.
- **Bulk action** `release_selected` — same policy, one shared reason. Bulk actions are authorised
  per record, not once for the batch (XC-05).
- **`Tables/InventoryReservationsTable.php`** — filters on status, warehouse, variant, and
  "expiring within 7 days"; a source-document column that **links** to the quotation, order or
  operation holding the stock; `released_by` / `release_reason` columns.
- **`Pages/ViewInventoryReservation.php`** — new: allocations (lots, serialised units), source
  document, status history.
- **`InventoryReservationResource`** gains `getEloquentQuery()` eager-loading the source document
  and allocations, so the list does not N+1 across a table that will be large.

### Mobile

A salesperson checking availability needs the *reason* a quantity is unavailable, which is the read
shape above. No client work now.

### Tests

**Feature (command)** — `tests/Feature/Inventory/ExpireReservationsCommandTest.php`
- A reservation past `expires_at` becomes `Expired`; available quantity rises by exactly its
  quantity; on-hand is unchanged.
- A reservation not yet expired is untouched.
- An already-`Consumed` or `Released` reservation is untouched (the "exactly once" invariant).
- A batch of 600 exercises the chunking path.
- One failing reservation does not abort the remainder, and the command exits non-zero.
- `InventoryReservationExpired` is dispatched once per expired reservation.

**Feature (scheduler)** — extend `tests/Feature/ScheduledCommandsTest.php` (new file if absent):
assert `inventory:reservations:expire` is registered hourly, alongside the three existing entries
and the WP-1.7 addition. This is the test that would have caught GAP-BW-04 and GAP-MW-04 at source.

**Feature (release)** — `tests/Feature/Inventory/ReservationReleaseTest.php`
- A permitted actor releases with a reason; status, `released_by`, `released_at` and the audit entry
  are all written; availability rises immediately.
- An actor without `ReservationRelease` is refused.
- Release without a reason is refused.
- Releasing a consumed reservation throws.
- Serialised allocations are freed — the unit becomes allocatable to a new reservation.

**Feature (Filament)** — `tests/Feature/Filament/InventoryReservationResourceTest.php`
- The release action is visible to a permitted actor and hidden otherwise.
- The bulk action authorises per record: a mixed selection releases only the permitted ones.
- The source-document link resolves for a quotation-sourced and an operation-sourced reservation.

**Integration** — `tests/Feature/Inventory/ReservationExpiryFlowTest.php`
- Accept a quotation → reserve → let expiry pass → run the command → the order shows as no longer
  covered, availability is restored, and `InventoryLotReconciliationService::inspect()` still
  reports aggregate reserved equal to the sum of active allocations.

---

## WP-1.7 — Schedule and persist the stock reconciliation (GAP-BW-04, part of GAP-MW-16)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | IN-17; AC-10 step 1 |
| **Depends on** | AD-04 |
| **Blocks** | WP-2.4 (report surface), WP-2.5 (period-close gate) |

### Problem restated

`ReconcileInventoryLotsCommand` (`inventory:lots:reconcile`) is fully implemented, checks the right
four invariants, and is **not scheduled**. It is dead code from a runtime perspective. The system
can detect that its own stock ledger has diverged and does not use the ability; divergence is
silent until somebody thinks to run an artisan command.

This package delivers the *runtime* half. WP-2.4 delivers the *report* half; splitting them keeps
each change small, and the runtime half is worth having on its own the day it lands.

### Database

**Migration `2026_09_04_100700_create_reconciliation_runs_table.php`** (AD-04)

```
reconciliation_runs
  id
  scope             string(40)      -- ReconciliationScope: inventory_lots | receivables | payables | tax
  invariant         string(60)      -- e.g. aggregate_equals_lot_sum
  passed            boolean
  divergence_count  unsigned int    default 0
  detail            json nullable   -- bounded: first 100 divergences, then a truncation marker
  started_at        timestamp
  finished_at       timestamp
  triggered_by      FK users nullable  nullOnDelete   -- null = scheduler
  trigger_source    string(20)      -- schedule | manual | period_close
  timestamps

  index (scope, invariant, finished_at)
  index (scope, passed, finished_at)
```

Append-only by convention and by policy (no update, no delete surface). `detail` is bounded because
a genuinely broken ledger could otherwise write a multi-megabyte row per invariant per hour.

### Domain

- **New enum** `App\Enums\ReconciliationScope` — `InventoryLots`, `Receivables`, `Payables`,
  `TaxRegister`. Phase 1 uses only `InventoryLots`; Phase 2 adds the rest, which is why the enum is
  introduced here rather than a string column.
- **New service** `App\Services\Reconciliation\ReconciliationRunRecorder::record(ReconciliationScope, iterable $results, string $triggerSource, ?User): void`
  — the single writer of `reconciliation_runs`, so every scope records identically.
- `ReconcileInventoryLotsCommand` is modified to pass its existing `inspect()` result to the
  recorder. **The inspection logic itself is untouched** — this is wiring, and the existing
  reconciliation tests must pass byte-identically.
- The command returns a **non-zero exit code** when any invariant fails, so the scheduler's failure
  handling and any external monitor see it.
- **Scheduler:** `Schedule::command('inventory:lots:reconcile')->dailyAt('01:30');` — daily rather
  than hourly because the inspection is a full-ledger replay; the cadence is revisited by WP-4.1
  once the Phase 4 performance work measures it.

### API

Read shape for a future ops dashboard: `GET /api/v1/reconciliation/runs?scope=&passed=`. No route.

### Filament

Nothing in this package — deliberately. The report surface is WP-2.4. What this package adds is a
single **failure signal**: `InventoryDashboard` gains a `ReconciliationStatus` widget showing the
latest run per invariant as pass/fail with its timestamp, coloured danger on failure. IN-17 requires
divergence to be "reported prominently as an error", and a widget is the smallest thing that
satisfies "prominently" before the full report exists.

### Mobile

None.

### Tests

**Feature** — `tests/Feature/Inventory/ReconciliationRunRecordingTest.php`
- A clean ledger records four passing rows and exits zero.
- A deliberately divergent ledger (constructed by a direct balance write inside the test, which is
  the only legitimate use of one) records a failing row with a bounded `detail` and exits non-zero.
- `detail` truncates at 100 divergences with a marker.
- `trigger_source` is `schedule` from the command and `manual` when invoked with the flag.

**Feature (scheduler)** — extend `tests/Feature/ScheduledCommandsTest.php`: `inventory:lots:reconcile`
is registered daily.

**Feature (Filament)** — `tests/Feature/Filament/ReconciliationStatusWidgetTest.php`: the widget
renders danger state on the latest failing run and success on a passing one.

**Regression** — the existing `InventoryLotReconciliationService` tests pass unchanged, proving the
inspection logic was wired, not rewritten.

---

## WP-1.8 — Type the accounting and billing document lifecycles (GAP-WL-01, GAP-WL-04)

| | |
|---|---|
| **Priority** | **High** (WL-01) + **Medium** (WL-04, which resolves with it) |
| **Scenario** | XC-01, XC-07, AC-16, SL-10 |
| **Depends on** | CC-01, CC-02 |
| **Blocks** | nothing structurally, but every later guard on these six families is cheaper after it |

### Problem restated

`invoices`, `payments`, `bills`, `expenses`, `supplier_payments` and `refunds` — **every document
that carries money** — use `string(30)` status columns compared against inline literals
(`$document->status !== 'approved'`, `in_array($locked->status, ['issued','sent'], true)`,
`'status' => 'partially_paid'`). Three enum files exist as empty stubs with zero references. The
rules are enforced only where a guard clause happens to exist, each guard is a separate literal
that can drift, an invalid status value is storable, and no test pins the legal transition set for
any of the six families.

GAP-WL-04 is the visible cost. `InvoiceConfirmationService::confirm()` writes the confirmation
*type* into the invoice's *status* column, so after confirmation `invoices.status` holds
`'customer_received'`. The `sent` state is destroyed; a second confirmation of the other type
creates its row but silently fails to update the status, because the new value is not in
`['issued','sent']`. Two independent axes — lifecycle and receipt evidence — are collapsed into one
column, so neither is answerable.

### Database

**Migration `2026_09_04_100800_type_document_statuses.php`** — casts and constraints only. Stored
values for the five non-invoice families are already the enum values, so nothing moves.

**Migration `2026_09_04_100801_separate_invoice_receipt_confirmation.php`** — the GAP-WL-04 repair:

```
invoices
  + received_confirmation_type  string(40) nullable   -- InvoiceConfirmationType, cast
  + received_confirmed_at       timestamp nullable
  + received_confirmed_by       FK users nullable  nullOnDelete
```

**Backfill, and why it is safe.** For every invoice whose `status` is `customer_received` or
`employee_confirmed_received`:

1. Copy that value into `received_confirmation_type`.
2. Copy `invoice_confirmations.confirmed_at` / `confirmed_by` (the authoritative row, which was
   always written correctly) into the two new columns.
3. Set `status = 'sent'` — recoverable because `InvoiceConfirmationService` only ever overwrote a
   status that was already `issued` or `sent`, and confirmation requires the invoice to be issued
   and sent. The financial machinery never read the status (`Invoice::isIssued()` tests
   `issued_at !== null`), so nothing downstream shifts.
4. Where an invoice has confirmation rows of **both** types — the silent-divergence case — the
   *earliest* confirmation supplies `received_confirmation_type` and the invoice is listed in the
   migration's output for review. Choosing silently would hide the very divergence this fixes.

A `down()` is provided and tested, since this is the only data-moving migration in Phase 1.

### Domain

**Enums filled or added** (all with `canTransitionTo()`, `isTerminal()`, `label()`):

```php
InvoiceStatus:          Draft → Issued → Sent → WrittenOff | Cancelled     // WrittenOff added by WP-1.9
PaymentStatus:          Draft → Posted → Reversed
InvoiceConfirmationType: CustomerReceived | EmployeeConfirmedReceived      // not a lifecycle: a type
BillStatus:             Draft → Approved → PartiallyPaid → Paid | Cancelled
ExpenseStatus:          Draft → Approved → Paid | Cancelled
SupplierPaymentStatus:  Draft → Paid | Cancelled
RefundStatus:           Draft → Approved → Paid | Cancelled
```

Values are transcribed from the literals currently in the services — no lifecycle is redesigned in
this package. Where a literal appears that no enum case covers, that is a finding, not a case to
add: it is reported and resolved before the cast lands.

**`CC-01` concern** on all six models:
```php
public function assertCanTransitionTo(BackedEnum $target): void; // throws IllegalStatusTransition
```

**Service changes** — mechanical and individually reviewable:
- Every `$x->status !== 'approved'` becomes `$x->status !== BillStatus::Approved`.
- Every `->update(['status' => 'paid'])` becomes `assertCanTransitionTo(...)` then the enum write.
- `whereNotIn('status', ['cancelled'])` becomes `whereNot('status', BillStatus::Cancelled)`.
- `InvoiceConfirmationService::confirm()` **stops writing `invoices.status`** and writes the three
  new columns instead. A second confirmation of a different type now updates them rather than
  failing silently, and `InvoiceStatus::Sent` survives.

**Deliberate non-goal.** This package does not change any lifecycle's *shape*. If a transition that
the code currently permits is business-wrong, that is a separate gap with its own evidence — mixing
the two would make a large mechanical diff unreviewable.

### API

Read shape: status becomes a stable enum value plus a translated label, and receipt confirmation
becomes its own field group rather than a status value a client must special-case. Recorded for
WP-4.5.

### Filament

- Status columns across `Invoices`, `Payments`, `Bills`, `Expenses`, `SupplierPayments`, `Refunds`
  become `TextColumn::make('status')->badge()` driven by the enum's `label()` and colour, replacing
  six independent inline `formatStateUsing` maps.
- `Invoices/Schemas/InvoiceInfolist.php` gains a **Receipt confirmation** section — type, when, by
  whom, and the signature media — separate from the lifecycle badge. This is the UI expression of
  the two axes SL-10 requires.
- `Invoices/Tables/InvoicesTable.php` gains an independent "Receipt confirmed" filter, so
  "which issued invoices have not yet been sent?" and "which were confirmed by the customer?" are
  both answerable again.

### Mobile

A customer app confirming receipt writes the confirmation, never the status. The separation is what
makes that safe.

### Tests

**Unit** — one file per enum, `tests/Unit/Enums/{Invoice,Payment,Bill,Expense,SupplierPayment,Refund}StatusTest.php`
- Every legal transition returns true; **every illegal one returns false** (exhaustive over the
  case matrix, not a sample).
- `isTerminal()` and `label()` translate.
This is the "no test pins the legal transition set" finding, closed.

**Feature** — `tests/Feature/Accounting/DocumentStatusTransitionTest.php`
- For each of the six families: an illegal transition attempted through the service throws
  `IllegalStatusTransition` and mutates nothing.
- An invalid raw value in the column throws on hydration (the cast's guarantee).

**Feature** — `tests/Feature/Sales/InvoiceReceiptConfirmationTest.php`
- Confirming receipt leaves `status = Sent` and populates the three receipt columns.
- Confirming the second type updates the receipt columns and still leaves `status = Sent` —
  the silent-failure regression.
- Confirming an unissued invoice is refused.
- Payment allocation, crediting and posting behave identically before and after confirmation.

**Feature (migration)** — `tests/Feature/Accounting/InvoiceStatusBackfillTest.php`
- An invoice seeded with `status = 'customer_received'` migrates to `Sent` plus populated receipt
  columns.
- The both-types case takes the earliest confirmation and is reported.
- `down()` restores the prior representation.

**Regression** — the whole existing accounting and sales suite must pass unchanged. This package's
correctness argument is that nothing behavioural moved; the test suite is the proof, and any
failure is a real divergence the literals were hiding.

---

## WP-1.9 — Write off an uncollectable receivable (GAP-MW-07)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | AC-13 |
| **Depends on** | WP-1.8 (`InvoiceStatus::WrittenOff`), CC-03 |
| **Adds** | the **only** new `JournalPostingService` caller in this plan |

### Problem restated

No write-off document, service, status, account reference or UI exists — a case-insensitive scan
for `write_off`, `writeoff`, `bad_debt`, `baddebt` across `app/` returns nothing. A receivable that
will never be collected has two in-system exits and both are wrong. A **credit note** debits
*revenue*, booking the loss as if the sale never happened and understating both revenue and
bad-debt expense. Leaving the invoice open permanently inflates AR ageing and the receivable control
account. Either way the trial balance carries an asset the company knows it does not have.

### Database

**Migration `2026_09_04_100900_create_receivable_write_offs_table.php`**

```
receivable_write_offs
  id
  write_off_number      string(30) unique          -- WO-2026-00001
  status                string(20)                 -- WriteOffStatus, cast
  customer_id           FK customer_profiles        restrictOnDelete
  invoice_id            FK invoices                 restrictOnDelete
  amount_minor          bigint unsigned            -- always ≤ outstanding at approval time
  tax_amount_minor      bigint unsigned default 0  -- the deferred-tax portion
  reason_category       string(40)                 -- WriteOffReason, cast
  reason                text
  recorded_by           FK users  restrictOnDelete
  approved_by           FK users nullable nullOnDelete
  approved_at           timestamp nullable
  journal_entry_id      FK journal_entries nullable nullOnDelete
  fiscal_period_id      FK fiscal_periods          restrictOnDelete
  timestamps, softDeletes

  index (customer_id, status)
  index (invoice_id)
```

**Migration `2026_09_04_100901_add_write_off_accounts_to_sales_settings.php`**

```
sales_settings
  + bad_debt_expense_account_id  FK chart_accounts nullable  restrictOnDelete
```

Joining the four posting accounts `SalesSetting` already holds (receivable, revenue, deferred tax,
tax payable). MD-07 says the Accountant owns tax policy and the posting accounts; this is a fifth
member of the same set, administered in the same place.

`ChartOfAccountsSeeder` gains account `6800 Bad Debt Expense` under the Expense element.

### Domain

**New enums**
```php
WriteOffStatus: Draft → Approved | Cancelled       // Approved is terminal; correction is by reversal
WriteOffReason: Insolvency | Untraceable | DisputedAndAbandoned | TimeBarred | CommerciallyUneconomic | Other
```

**New service `App\Services\Accounting\ReceivableWriteOffService`**
- `record(WriteOffData, User $actor): ReceivableWriteOff` — draft only. Asserts the invoice is
  issued, not cancelled, not already written off, and that `amount_minor` ≤ current outstanding
  (total − credited − collected − already written off). Refuses a zero or negative amount.
- `approve(ReceivableWriteOff, User $actor): ReceivableWriteOff` — **maker ≠ checker via CC-03**,
  matching AC-12's rule for refunds, because a write-off removes an asset with no counterparty
  confirmation. Locks the write-off and the invoice, re-checks outstanding under lock (the amount
  may have been collected in the interim — in which case it throws rather than writing off money
  that arrived), posts, and sets `InvoiceStatus::WrittenOff`.
- `cancel(ReceivableWriteOff, User, string $reason)` — drafts only.

**New posting service `App\Services\Accounting\WriteOffPostingService`** — the plan's one new
`JournalPostingService` caller. The entry, per AC-13:

```
Dr  Bad debt expense        amount_minor − taxPortion
Dr  Deferred tax            taxPortion                 (only the still-deferred part)
Dr  Tax payable             0                          (never — see below)
    Cr  Accounts receivable  amount_minor
```

**The tax treatment, stated explicitly because it is the part that is easy to get wrong.** Tax on
an issued invoice is credited to *deferred tax* and only moves to *tax payable* when money is
collected (`TaxRecognitionService`). A write-off means the money never arrives, so the tax was
never recognised as payable and must be released from **deferred tax only**. The write-off therefore
reverses the deferred portion in proportion to the amount written off, and touches tax payable
never. This is why AC-13's rule — "never recognises tax as if money arrived" — holds by
construction rather than by care.

The deferred portion is computed as
`invoice.tax_total × (amount_minor / invoice.total_amount)`, floored, with the remainder settled
exactly when the write-off clears the entire outstanding balance — the same
avoid-rounding-drift technique `TaxRecognitionService::recognise()` already uses. That technique is
extracted into `App\Support\ProportionalAllocator` and **both** services use it, so they cannot
drift apart.

**Policy `ReceivableWriteOffPolicy`** — `view`/`create` → `AccountingPermission::WriteOffRecord`,
`approve` → `WriteOffApprove` (a new, distinct permission — the separation is only real if the
permissions are separable), `cancel` → `WriteOffRecord`.

**Guards inherited:** posting into a closed period throws `ClosedFiscalPeriod`; the posted entry is
immutable and corrected by reversal only.

**AR impact:** `AccountsReceivableService` (WP-2.6) subtracts approved write-offs from outstanding.
Until it exists, `Invoice::outstandingMinor()` is updated here so the ageing figure is right from
day one.

### API

Command shape: `WriteOffData{customerId, invoiceId, amountMinor, reasonCategory, reason}`.
Read shape: the invoice exposes `written_off_amount_minor` and `write_off` so no client mistakes a
written-off invoice for a paid one. No route.

### Filament

**New resource `app/Filament/Resources/ReceivableWriteOffs/`** — the `Refunds` layout, which is the
closest existing analogue (record → approve → post, maker ≠ checker):

```
ReceivableWriteOffResource.php
Pages/{ListReceivableWriteOffs, CreateReceivableWriteOff, ViewReceivableWriteOff}.php
Actions/ReceivableWriteOffActions.php        -- approve(), cancel()
Schemas/{ReceivableWriteOffForm, ReceivableWriteOffInfolist}.php
Tables/ReceivableWriteOffsTable.php
```

- `Invoices/Actions/InvoiceActions.php` gains `write_off`, visible only when outstanding > 0 and
  the invoice is issued, pre-filling customer, invoice and outstanding amount.
- `Invoices/Tables/InvoicesTable.php` — a **Written off** badge, distinct in colour from Paid. The
  scenario's binding requirement is that the invoice "shows as written off, never as paid", and the
  badge is where that promise is kept or broken.
- `SalesSettings` form gains the bad-debt expense account selector alongside the four existing ones.
- `AccountingDashboard` — a `BadDebtThisPeriod` stat.

### Mobile

None. A write-off is an internal accounting decision.

### Tests

**Unit** — `tests/Unit/Support/ProportionalAllocatorTest.php`: allocation sums to the total with no
drift across 1/3 splits and odd minor-unit totals; the final allocation absorbs the remainder.
`tests/Unit/Enums/WriteOffStatusTest.php`: transition matrix.

**Feature** — `tests/Feature/Accounting/ReceivableWriteOffTest.php`
- Recording against an issued invoice creates a draft and posts nothing.
- Recording more than outstanding throws.
- Recording against a cancelled or already-written-off invoice throws.
- The recorder approving their own write-off throws (CC-03).
- Approval posts one balanced entry: bad-debt expense debited, deferred tax debited for the
  proportional part, AR credited for the full amount, **tax payable untouched** — asserted by
  account, not merely by balance.
- The invoice becomes `WrittenOff` and is excluded from AR outstanding.
- A partial write-off leaves the remainder outstanding and collectable.
- Money collected between recording and approval causes approval to throw.
- Approving into a closed period throws `ClosedFiscalPeriod`.
- The posted entry is immutable; correction is by reversal.

**Feature (permissions)** — `tests/Feature/Accounting/WriteOffPermissionTest.php`: `WriteOffRecord`
alone cannot approve; `WriteOffApprove` alone cannot record.

**Feature (invariant)** — extend `tests/Feature/Accounting/NoAutomaticPostingTest.php` with
`WriteOffPostingService` as the seventh named caller, **and** assert the caller count is exactly
seven, so the eighth is a deliberate decision.

**Integration** — `tests/Feature/Accounting/WriteOffTrialBalanceTest.php`
- Issue → partially collect → write off the remainder → the trial balance still balances, AR
  agrees with the AR subledger, and the P&L carries the bad-debt expense in the write-off's period.

---

## WP-1.10 — Stop transcription deletion from destroying opportunity evidence (GAP-BW-08)

| | |
|---|---|
| **Priority** | **Medium** by the gap document; sequenced into Phase 1 because it is a one-line schema change that prevents irreversible loss of audit evidence, and every day it waits is a day a retention job can fire |
| **Scenario** | EM-07, EM-08, XC-02 |

### Problem restated

`sales_opportunities.voice_note_transcription_id` is `constrained()->cascadeOnDelete()`
(`database/migrations/2026_08_05_210000:15`). Deleting a transcription **hard-deletes every
opportunity derived from it**, including `reviewed_by`, `reviewed_at` and `review_notes` — the
recorded human decision EM-07 requires as evidence. `ai_keyword_rule_id` on the *same table* is
correctly `nullOnDelete()`, so the design intent is visibly there and was applied inconsistently.
If the opportunity produced a quotation, the quotation survives with a dangling
`sales_opportunity_id`, so the AI origin disappears exactly when someone asks where the deal came
from.

### Database

**Migration `2026_09_04_101000_preserve_opportunity_evidence.php`**

```
sales_opportunities
  ~ voice_note_transcription_id  → nullable, nullOnDelete   (was NOT NULL, cascadeOnDelete)
  + origin_summary               text nullable   -- denormalised snapshot of the transcript excerpt
                                                 -- that justified the opportunity, captured at creation
```

Dropping the FK and re-adding it requires naming the existing constraint; the migration does so
explicitly rather than relying on convention, and its `down()` is deliberately **not** a restoration
of `cascadeOnDelete` — reinstating a data-destroying constraint on rollback would be a defect. The
`down()` restores nullability only, with a comment stating why.

**`origin_summary` rationale.** Nulling the FK preserves the *row* but loses the *reason*. EM-07
requires the AI origin to "remain visible on everything it produces". A snapshot taken at creation
keeps the origin readable after the audio and its transcript are lawfully purged — which is the
whole point of a retention policy — without keeping the audio itself.

**Backfill:** populate `origin_summary` from the current transcription text for existing
opportunities where the transcription still exists. Where it does not, the column stays null and
the UI shows "origin transcript no longer retained", which is the truth.

**Dangling-reference sweep:** quotations whose `sales_opportunity_id` points at a deleted row are
reported by the migration (not repaired — there is nothing to repair to) so the count of already-lost
evidence is known rather than assumed to be zero.

### Domain

- `SalesOpportunity::voiceNoteTranscription()` becomes nullable in its return type and every caller
  is checked; `SalesOpportunity::isAiOriginated()` is derived from `origin_summary !== null ||
  ai_keyword_rule_id !== null`, so it survives the transcript.
- `KeywordDetectionService` writes `origin_summary` at draft creation.
- **This package does not make the FK optional at creation time** — an AI-originated opportunity
  still requires its transcription when it is created. Making opportunities creatable without one
  is GAP-MW-02 (WP-2.2), a business change with its own design. This package changes only what
  happens on deletion. Keeping those separate is what makes both reviewable.

### API

None.

### Filament

- `SalesOpportunities/Schemas/SalesOpportunityInfolist.php` — the origin section renders the live
  transcription when present, otherwise `origin_summary` with a "transcript no longer retained"
  note, otherwise "origin unknown (recorded before evidence retention)".
- `SalesOpportunities/Tables/SalesOpportunitiesTable.php` — an "AI-originated" badge driven by
  `isAiOriginated()` rather than by the FK.

### Mobile

None.

### Tests

**Feature** — `tests/Feature/Employees/OpportunityEvidenceRetentionTest.php`
- Deleting a transcription **preserves** its opportunities, with `reviewed_by`, `reviewed_at` and
  `review_notes` intact and the FK nulled.
- `origin_summary` survives the deletion and renders.
- `isAiOriginated()` stays true after deletion.
- A quotation derived from the opportunity keeps a resolvable `sales_opportunity_id`.
- Deleting a keyword rule still nulls its reference (unchanged behaviour, re-pinned).

**Feature (migration)** — `tests/Feature/Employees/OpportunityBackfillTest.php`: `origin_summary` is
populated where a transcription exists and left null where it does not.

**Regression** — `tests/Feature/Employees/SalesOpportunityTest.php` and the voice-note pipeline
suite pass unchanged.

---

# Phase 2 — Cross-module integration

**Theme:** make evidence cross the seams. Phase 1 fixed what was *wrong*; this phase fixes what
does not *connect*. Thirty-three of sixty seams are impaired, and the flow matrix shows they
cluster into one shape — both sides individually well built, nothing joining them.

Two packages here are large enough to run as their own track: `WP-2.1` (CRM foundation) is a new
module and `WP-2.10` (notifications) is new infrastructure. Both are dependency-free with respect
to Phase 1 and should start in parallel with it — see `ERP_IMPLEMENTATION_PHASES.md` §2.

---

## WP-2.1 — CRM foundation: leads, interactions, campaigns (GAP-MW-01)

| | |
|---|---|
| **Priority** | **Critical** — a named PRD core feature (FR-022) with no code behind it |
| **Scenario** | CR-01, CR-02, CR-03, CR-05, CR-06, CR-07 |
| **Flow** | F-17 (Campaign → lead → customer → first sale) |
| **Depends on** | nothing in Phase 1 — runs as a parallel track from day one |
| **Blocks** | WP-2.2 (opportunity origin), WP-3.1 (customer timeline), the funnel half of WP-2.8 |

### Problem restated

None of the five concepts exists — no model, table, service, resource, policy or test for leads,
interactions, campaigns, campaign recipients or campaign responses. The CRM module that was built
is customers plus pricing tiers. The company can report revenue but never attribute it to what
caused it, so marketing spend is unmeasurable and CR-07's requirement that the funnel be "traceable
to money, not stop at interested" is unreachable.

### Database

Six migrations, one table each, so the module can land incrementally.

```
leads
  id, lead_number string(30) unique
  status            string(30)      -- LeadStatus, cast
  source            string(40)      -- LeadSource, cast, NOT NULL  (CR-01: source is mandatory)
  source_detail     string(255) nullable
  campaign_id       FK campaigns nullable nullOnDelete
  first_name, last_name, company_name, job_title  string nullable
  email             string(255) nullable
  phone             string(50) nullable
  preferred_language string(5) default 'en'       -- XC-08
  assigned_to       FK users nullable nullOnDelete
  converted_customer_id FK customer_profiles nullable nullOnDelete
  converted_at      timestamp nullable
  disqualified_reason string(40) nullable          -- LeadDisqualificationReason, cast
  disqualified_note text nullable
  last_interaction_at timestamp nullable            -- derived, maintained by the service
  created_by        FK users restrictOnDelete
  timestamps, softDeletes
  index (status, last_interaction_at)   -- the dormant-lead report
  index (source, status)                -- funnel by source
  index (campaign_id)
  unique (email) WHERE email IS NOT NULL -- expressed as a service guard + a non-unique index,
                                         -- because partial indexes are not portable here

lead_stage_transitions          -- CR-02: "a stage move is always explainable"
  id, lead_id FK cascadeOnDelete
  from_status, to_status string(30)
  interaction_id FK interactions nullable nullOnDelete   -- the justification
  reason string(255) nullable
  actor_id FK users nullable nullOnDelete
  occurred_at timestamp
  index (lead_id, occurred_at)

interactions                    -- CR-02 and CR-05 share one table
  id
  subject_type, subject_id      -- morph: Lead | CustomerProfile
  type            string(30)    -- InteractionType, cast: Call|Email|Meeting|FieldVisit|Demo|Note
  direction       string(10)    -- Inbound | Outbound
  outcome         string(30) nullable   -- InteractionOutcome, cast
  occurred_at     timestamp
  summary         string(255)
  notes           text nullable
  employee_id     FK users restrictOnDelete     -- CR-02: "naming the employee"
  customer_visit_id FK customer_visits nullable nullOnDelete   -- EM-03 seam
  ticket_id       FK tickets nullable nullOnDelete             -- SU seam
  campaign_id     FK campaigns nullable nullOnDelete
  created_by      FK users restrictOnDelete
  timestamps, softDeletes
  index (subject_type, subject_id, occurred_at)
  index (employee_id, occurred_at)

campaigns
  id, campaign_number string(30) unique
  name string(255), status string(20)   -- CampaignStatus, cast
  channel string(20)                     -- CampaignChannel: Email | Sms | Whatsapp | Event | Other
  content_template_id FK notification_templates nullable nullOnDelete   -- shared with WP-2.10
  scheduled_at, started_at, completed_at timestamp nullable
  segment_criteria json nullable         -- the recorded definition, so the list is reproducible
  created_by FK users restrictOnDelete
  timestamps, softDeletes

campaign_recipients
  id, campaign_id FK cascadeOnDelete
  recipient_type, recipient_id           -- morph: Lead | CustomerProfile
  email, phone string nullable           -- snapshot at build time
  send_status string(20)                 -- CampaignSendStatus: Pending|Sent|Failed|Suppressed
  send_error string(255) nullable        -- CR-06: "send failures are recorded, not silently dropped"
  sent_at timestamp nullable
  notification_delivery_id FK notification_deliveries nullable nullOnDelete
  timestamps
  unique (campaign_id, recipient_type, recipient_id)   -- never contacted twice by one campaign
  index (campaign_id, send_status)

campaign_responses
  id, campaign_recipient_id FK cascadeOnDelete
  type string(20)                        -- CampaignResponseType: Opened|Clicked|Replied|Interested|Unsubscribed
  occurred_at timestamp
  payload json nullable
  created_lead_id FK leads nullable nullOnDelete   -- CR-06 step 5: attribution survives
  index (campaign_recipient_id, type)

communication_suppressions             -- CR-06: "a suppressed recipient is never contacted again"
  id, channel string(20)
  address string(255)                  -- email or phone, normalised
  reason string(40), suppressed_at timestamp
  source_campaign_id FK campaigns nullable nullOnDelete
  unique (channel, address)
```

**Constraint rationale.**
- `leads.source` is `NOT NULL` at the schema level, not just validated — CR-01 makes it the whole
  point of a lead, and a nullable column would let an import defeat it.
- `campaign_recipients` unique per campaign+recipient is the anti-double-send control.
- `communication_suppressions` is unique per channel+address and is checked **at send time inside
  the send transaction**, not at list-build time, because consent can be withdrawn between the two.
- `interactions` is polymorphic across Lead and CustomerProfile precisely so CR-03's "conversion
  never loses history" is a pointer change, not a data copy.

### Domain

**Enums:** `LeadStatus` (`New → Contacted → Qualified → Converted | Disqualified`, with
`canTransitionTo()` — `Disqualified` reachable from any non-terminal, `Converted` only from
`Qualified`), `LeadSource` (`Website|Referral|Exhibition|ColdCall|Campaign|FieldObservation|Partner|Other`),
`LeadDisqualificationReason`, `InteractionType`, `InteractionDirection`, `InteractionOutcome`,
`CampaignStatus` (`Draft → Scheduled → Sending → Completed | Cancelled`), `CampaignChannel`,
`CampaignSendStatus`, `CampaignResponseType`.

**Services** (`app/Services/Crm/`, joining the existing namespace):

- `LeadService::create/update/assign/transition/disqualify`.
  `transition()` **requires an `Interaction`** for any advance (CR-02's binding rule) and writes a
  `lead_stage_transitions` row. Disqualification requires a reason from the controlled list.
- `LeadConversionService::convert(Lead, CustomerOnboardingData, User): CustomerProfile` — wraps the
  existing `CustomerOnboardingService::register()`, then re-points every `Interaction` from the lead
  to the new customer, sets `converted_customer_id` / `converted_at`, and transitions the lead to
  `Converted`. **Re-uses the onboarding service rather than duplicating customer creation**, so
  MD-01's rules apply identically whichever door the customer came through. Refuses a second
  conversion and refuses conversion of a lead whose email already matches a customer (CR-01's
  alternate path: the enquiry becomes an interaction on the existing customer instead).
- `InteractionService::log(InteractionData, User): Interaction` — the single writer, used by CRM,
  by visit review (EM-03) and by support, so one customer timeline has one shape.
- `CampaignService::create/schedule/buildRecipients/cancel`.
  `buildRecipients()` records the criteria it used in `segment_criteria` so the list is reproducible
  (XC-04's "retained with their parameters").
- `CampaignDispatchService::dispatch(Campaign): void` — queued, chunked, consults
  `communication_suppressions` inside the per-recipient transaction, hands the actual send to
  WP-2.10's notification layer, and records the send outcome per recipient.
- `CampaignResponseService::record(CampaignRecipient, CampaignResponseType, array $payload)` —
  and, for `Interested`, creates a `Lead` carrying `campaign_id` so attribution survives.
  `Unsubscribed` writes a `communication_suppressions` row in the same transaction.
- `CrmFunnelReportService::bySource/byStage/byCampaign/attributedRevenue` — CR-07. The
  `attributedRevenue` query is the one that matters: it joins lead → customer → invoices →
  payments, so the funnel reaches **collected** revenue rather than stopping at "interested".

**Events:** `LeadConverted`, `CampaignCompleted` — consumed by WP-2.10.

**Policies:** `LeadPolicy`, `InteractionPolicy`, `CampaignPolicy`, using the existing
`ChecksCrmPermissions` concern. New `CrmPermission` cases:
`crm.lead.view|create|update|assign|convert`, `crm.interaction.view|create`,
`crm.campaign.view|manage|send`, `crm.funnel.report`.
An employee sees leads assigned to them; a CRM Manager sees all — enforced in the policy **and** in
`getEloquentQuery()`, and asserted by an addition to the existing `CrmCrossModulePermissionLeakTest`.

### API

The customer-facing half of CRM (a lead form on the public site) is the one part of this module
with a natural public surface. Contract recorded for WP-4.5:
`POST /api/v1/leads` (rate-limited, captcha-gated, `source` forced to `Website`), and
`GET /api/v1/campaigns/{id}/unsubscribe/{token}` — a signed, no-auth route writing a suppression.
The existing public `join-us` form (`JoinUsController`) is the precedent for a public write path
that goes through a service.

**Interim, in this package:** the `join-us` controller gains an option to create a `Lead` instead of
a full customer when the submitter is not yet a trading party, so the module has real inbound data
from day one rather than only hand-keyed rows.

### Filament

New resources under `app/Filament/Resources/`:
`Leads/`, `Interactions/`, `Campaigns/` — each with the standard eight-file layout.

- `Leads/Actions/LeadActions.php` — `log_interaction` (a modal creating the interaction and
  offering the stage advance in the same step, so the rule "stage moves only through an
  interaction" is the *easy* path), `assign`, `convert` (opens the onboarding form pre-filled),
  `disqualify` (reason required).
- `Leads/RelationManagers/LeadInteractionsRelationManager.php` and
  `LeadStageHistoryRelationManager.php`.
- `Campaigns/Actions/CampaignActions.php` — `build_recipients` (shows the count before committing),
  `schedule`, `cancel`, `download_send_log` (CSV).
- `Campaigns/RelationManagers/{CampaignRecipients,CampaignResponses}RelationManager.php`.
- `CrmDashboard` gains widgets: `CrmLeadFunnel` (count by stage), `CrmLeadsBySource`,
  `CrmDormantLeads` (no interaction in 14 days — CR-02's "a dormant lead surfaces on a no-activity
  report rather than dying silently"), `CrmCampaignPerformance`.
- New report page `CrmReports/Pages/ViewCrmReports` with `CrmReportType`:
  `LeadsBySource`, `StageConversion`, `CampaignPerformance`, `PipelineValueAndAge`,
  `AttributedRevenue` — each with CSV export, matching the `FinancialReports` page shape.

### Mobile

Field lead capture is the highest-value mobile CRM feature: an employee meets a prospect, captures
name/company/source and a first interaction on the spot. `Interaction.customer_visit_id` exists so a
field interaction ties to the visit that produced it. Read/command shapes are recorded; no client
work in this phase.

### Tests

**Unit** — `tests/Unit/Enums/LeadStatusTest.php` (exhaustive transition matrix, both directions),
`CampaignStatusTest.php`, `CampaignResponseTypeTest.php`.

**Feature — leads** `tests/Feature/Crm/LeadLifecycleTest.php`
- Creating without a source is refused at the service and at the database.
- Advancing a stage without an interaction throws; advancing with one writes a transition row
  naming the interaction.
- Disqualification requires a controlled reason and is terminal.
- An unassigned employee cannot see another's lead (policy **and** query scope).

**Feature — conversion** `tests/Feature/Crm/LeadConversionTest.php`
- Conversion creates the customer through `CustomerOnboardingService` (asserted by the customer
  code format and the generated documents, so the reuse is real, not a copy).
- Every interaction re-points to the customer; none is lost or duplicated.
- A second conversion throws.
- A lead whose email matches an existing customer is refused with the CR-01 alternate-path message.

**Feature — campaigns** `tests/Feature/Crm/CampaignDispatchTest.php`
- Recipients build from criteria and the criteria are stored.
- A suppressed address is skipped, marked `Suppressed`, and never sent.
- A send failure records `send_error` and does not abort the batch.
- The same recipient cannot appear twice in one campaign.
- An `Unsubscribed` response writes a suppression in the same transaction; a later campaign skips
  that address.
- An `Interested` response creates a lead carrying `campaign_id`.

**Feature — funnel** `tests/Feature/Crm/CrmFunnelReportTest.php`
- Lead → customer → quotation → order → invoice → payment, then `attributedRevenue` reports the
  **collected** amount against the originating campaign and source. This is CR-07's acceptance
  criterion and the single test that proves the module reaches money.

**Feature (Filament)** — resource tests for all three resources plus the report page, following
`Feature/Filament/CreditNoteResourceTest.php`.

**Architecture** — extend `tests/Unit/ArchTest.php`: `App\Services\Crm` may not reference
`App\Services\Inventory` or `App\Services\Accounting` directly; the funnel report reads models.

---

## WP-2.2 — Opportunity as a first-class sales object (GAP-MW-02)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | CR-04, EM-07 |
| **Flow** | F-04 |
| **Depends on** | WP-1.10 (nullable transcription FK), WP-2.1 (lead origin) |

### Problem restated

`sales_opportunities` carries only `voice_note_transcription_id` (**NOT NULL**), `ai_keyword_rule_id`,
`summary`, `status`, `reviewed_by`, `review_notes`. `SalesOpportunityStatus` is
`Draft → Approved | Rejected` — a *review* state machine, not a *sales* pipeline. There is no
customer, no estimated value, no expected close date, no stage, no close-lost reason. Because the
transcription FK is mandatory, **a salesperson cannot record an opportunity at all** unless a field
employee recorded a voice note that an AI transcribed. Pipeline value, ageing, win/loss and
forecasting are impossible.

### Database

**Migration `2026_09_05_…_make_opportunities_first_class.php`**

```
sales_opportunities
  + origin                string(30)  -- OpportunityOrigin, cast, NOT NULL, default 'ai_voice_note'
  + customer_id           FK customer_profiles nullable nullOnDelete
  + lead_id               FK leads nullable nullOnDelete
  + title                 string(255) nullable
  + estimated_value_minor bigint unsigned nullable
  + currency              string(3) default 'AED'
  + expected_close_date   date nullable
  + stage                 string(30) NOT NULL default 'qualification'  -- OpportunityStage, cast
  + probability_percent   unsigned tinyint nullable
  + owner_id              FK users nullable nullOnDelete
  + closed_at             timestamp nullable
  + close_reason          string(40) nullable   -- OpportunityCloseReason, cast
  + close_note            text nullable
  index (stage, expected_close_date)   -- pipeline ageing
  index (owner_id, stage)
  index (customer_id), index (lead_id)

opportunity_stage_transitions   -- CR-04: stage history, so velocity is computable
  id, sales_opportunity_id FK cascadeOnDelete
  from_stage, to_stage string(30)
  interaction_id FK interactions nullable nullOnDelete
  actor_id FK users nullable nullOnDelete, occurred_at timestamp
```

**Backfill:** existing rows get `origin = AiVoiceNote`, `stage = Qualification`, and
`customer_id` left null (there is nothing to infer it from — inventing a customer would be false
evidence). A `CHECK`-equivalent service guard requires `customer_id` **or** `lead_id` for any
opportunity created after this package; historical rows are exempt and flagged in the UI.

### Domain

**The two-axis decision, stated explicitly.** `SalesOpportunityStatus` (`Draft → Approved |
Rejected`) is **kept unchanged** as the *AI review gate*, because EM-07 requires that no AI output
takes effect without a recorded human decision and that a rejected draft is retained as evidence.
A new, independent `OpportunityStage` carries the *sales pipeline*. Collapsing them would either
destroy the AI review evidence or force manually-created opportunities through a review they do not
need. Two axes, two enums, one table.

**New enums**
```php
OpportunityOrigin: AiVoiceNote | Lead | ExistingCustomer | FieldVisit | Inbound | Manual
OpportunityStage:  Qualification → NeedsAnalysis → Demo → Proposal → Negotiation
                                → ClosedWon | ClosedLost      // canTransitionTo(); backward moves allowed
                                                              // to Qualification..Negotiation, forward-only into closed
OpportunityCloseReason: WonAsQuoted | WonAfterNegotiation
                      | LostOnPrice | LostToCompetitor | LostNoBudget | LostNoDecision
                      | LostTimingWrong | LostRequirementChanged | Other
```

**`OpportunityService`** (new, alongside the existing `OpportunityReviewService`, which is untouched):
- `create(OpportunityData, User)` — requires `customer_id` or `lead_id`; `origin` is
  `Manual`/`Lead`/`ExistingCustomer` here. **`status` is set to `Approved` immediately** for a
  human-created opportunity, because the review gate exists to police AI output and a human's own
  entry needs no second human. Recorded in the enum docblock so the asymmetry is intentional and
  visible.
- `transitionStage(SalesOpportunity, OpportunityStage, ?Interaction, User)` — writes the transition
  row; moving to `ClosedLost` **requires** a `close_reason` from the controlled list (CR-04's
  "closing lost requires a reason, so the loss report means something").
- `closeWonFromQuotation(Quotation)` — called by `QuotationService::recordDecision()` when an
  accepted quotation carries `sales_opportunity_id`; sets `ClosedWon`, `closed_at`, and
  `close_reason = WonAsQuoted`. This is the CR-04 step-4 seam that does not exist today.
- `closeLostOnQuotationRejection(Quotation, string $reason)` — the mirror.

**`QuotationService::createFromOpportunity()`** is unchanged in behaviour but gains the
opportunity's `title` and `estimated_value_minor` in its snapshot, so the quotation "knows where it
came from" with more than a summary string.

**AI origin preservation:** `origin` is never editable after creation, and `isAiOriginated()`
(WP-1.10) drives an indelible badge. EM-07's "must remain visibly AI-originated" becomes a column
rather than an inference.

### API

Command shape: `OpportunityData`. Read shape: pipeline list with stage, value, age, owner, and
`is_ai_originated`. A field app creating an opportunity from a visit is the obvious first consumer.
No route.

### Filament

- `SalesOpportunities/` gains create/edit pages (today it is review-only), a stage-transition action
  requiring a close reason when closing lost, and a `StageHistory` relation manager.
- `SalesOpportunitiesTable` gains stage, value, expected close date, days-in-stage and owner
  columns, with filters on stage, owner and "closing this month".
- **New widgets** on `SalesDashboard`: `SalesPipelineValue` (open value by stage),
  `SalesPipelineAgeing` (opportunities stalled > 30 days in a stage).
- **New report** (delivered with WP-2.8): `SalesReportType::WinLossAnalysis` — win rate by employee,
  customer and product; loss reasons ranked. This is the report CR-04 says the controlled reason
  list exists to serve.

### Mobile

Creating an opportunity during a field visit, with the visit as its origin, is the primary field
sales action after the visit itself. Contract recorded.

### Tests

**Unit** — `tests/Unit/Enums/OpportunityStageTest.php` (full matrix; forward-only into closed
states), `OpportunityCloseReasonTest.php` (won reasons only reachable from `ClosedWon`).

**Feature** — `tests/Feature/Sales/OpportunityLifecycleTest.php`
- An opportunity is creatable **without** a transcription (the headline fix), given a customer or a
  lead.
- Creating with neither customer nor lead throws.
- Closing lost without a reason throws; with one, it records the transition.
- An accepted quotation closes its opportunity won automatically.
- A rejected quotation closes it lost with the recorded reason.
- Stage transitions write history with the interaction that justified them.
- `origin` cannot be edited after creation.

**Feature (regression)** — `tests/Feature/Employees/SalesOpportunityTest.php` and
`KeywordDetectionServiceTest.php` pass unchanged: the AI path still creates `Draft` opportunities
requiring review, and rejection still retains the draft with its reason.

**Integration** — `tests/Feature/Sales/OpportunityToCashTest.php` (F-04): lead → opportunity →
quotation → accepted → order → invoice → payment, asserting the opportunity closed won and the
funnel report attributes the collected revenue to the lead's source.

---

## WP-2.3 — Price provenance survives conversion (GAP-BW-03)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | MD-12, CR-08, PM-04 |
| **Flow** | F-11 |
| **Depends on** | AD-06 |

### Problem restated

`PriceResolver` is strong — it returns a `ResolvedPrice` carrying a `ResolvedPriceSource`, never
stacks, and `assertAtOrAboveFloor()` blocks below-floor sales pending a logged override. That
provenance is persisted on **exactly one table**: `quotation_lines.resolved_price_source`, an
untyped `string(40)`. `order_lines` and `invoice_lines` carry no provenance column at all. So
provenance dies at the Sales → Sales conversion boundary — on the document the customer actually
challenges and the auditor reviews — and a direct order raised without a quotation has no
provenance at any point in its life.

### Database

**Migration `2026_09_05_…_carry_price_provenance.php`**

```
quotation_lines
  ~ resolved_price_source  string(40) → cast to ResolvedPriceSource; a data-repair statement
                           rejects (and reports) any value outside the enum before the cast lands
  + resolved_price_tier_id      FK pricing_tiers nullable nullOnDelete
  + price_floor_override_id     FK price_floor_overrides nullable nullOnDelete
  + list_price_minor            bigint unsigned nullable    -- what it would have cost with no rule
  + floor_price_minor           bigint unsigned nullable    -- the floor at resolution time

order_lines      + the same five columns
invoice_lines    + the same five columns
```

`list_price_minor` and `floor_price_minor` are snapshots, not lookups. CR-08 asks the system to show
"the rules that lost and why" and "the floor position" — after a tier is edited, a live lookup would
answer with today's policy about yesterday's sale. MD-06's payment-term snapshot sets the precedent
in this codebase for exactly this reasoning.

**Backfill:** `quotation_lines.resolved_price_tier_id` is back-fillable only where the source is
`CustomerSpecificTier` or `GeneralTier` and exactly one tier could have applied; otherwise null.
`order_lines` and `invoice_lines` are back-filled **from their originating quotation line where one
exists** and left null otherwise, with a report of the null count. Nothing is guessed.

### Domain

- `App\Models\Concerns\CarriesPriceProvenance` — `copyPriceProvenanceFrom(Model $source): void`,
  used by every conversion so a fourth document type cannot forget.
- `ResolvedPrice` gains `tierId`, `listPriceMinor`, `floorPriceMinor` (all already computed inside
  `PriceResolver`; they are simply not returned today).
- `QuotationService::create/updateLines` persist the full group.
- `QuotationConversionService::convert()` copies it onto `order_lines`.
- `OrderFulfillmentService::create()` — for a **direct order with no quotation**, calls
  `PriceResolver::resolve()` and persists provenance at line creation. This is the path that has
  never had provenance at all.
- `InvoiceService::createFromDelivery()` copies from the order line;
  `createStandalone()` resolves fresh (and, if the operator overrode the price, records
  `ResolvedPriceSource::ManualOverride` — a new enum case, because a manual price is a provenance,
  not an absence of one).
- `PriceExplanationService::explain(CustomerProfile, ProductVariant, ?Carbon $asOf)` — returns the
  winner, **every losing candidate with the reason it lost**, the floor, and any override. This is
  CR-08's "explain a customer's price to their face" as a callable, used by the infolist, the price
  preview and the report.

### API

Read shape: a line exposes a `price_provenance` object — source, tier, list, floor, override. A
customer portal showing "your price" is the natural consumer, and it must not have to re-derive it.

### Filament

- Line repeaters and infolists on `Quotations`, `Orders` and `Invoices` all show a provenance badge
  with a hover/expand showing the explanation (winner, losers, floor, approver).
- `PricingTiers/Pages/ManagePricingTiers` `previewPrice` action reuses `PriceExplanationService`, so
  the preview and the document agree by construction.
- **New report** `SalesReportType::DiscountAndFloorOverrides` (WP-2.8) — discount incidence by
  employee, customer and product, and every floor override with its approver.

### Mobile

A field salesperson quoting on site needs the explanation, not just the number — it is the
conversation they are having. Contract recorded.

### Tests

**Unit** — `tests/Unit/Services/PriceExplanationServiceTest.php`: winner and losers for each of the
four sources; a below-floor case shows the override and its approver.

**Feature** — `tests/Feature/Sales/PriceProvenanceCarryTest.php`
- Quotation → order → invoice preserves source, tier, list, floor and override on every line.
- A direct order with no quotation resolves and persists provenance.
- A standalone invoice with a manual price records `ManualOverride`.
- Editing the pricing tier afterwards does **not** change any stored provenance (the snapshot
  guarantee).
- An out-of-enum value in a legacy row is rejected by the migration and reported.

**Feature (regression)** — `Feature/PricingTierPriceResolverTest.php` and
`Feature/PricingTierQueryCountTest.php` pass unchanged; the query budget the latter pins must not
regress, which is why the tier id is returned from the existing resolution rather than re-queried.

**Integration** — `tests/Feature/Sales/PriceExplanationEndToEndTest.php` (F-11): a customer
challenges an invoice line; the system answers from the invoice alone, with no quotation present.

---

## WP-2.4 — The reconciliation report surface (GAP-MW-16)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | IN-17, AC-10 step 1 |
| **Depends on** | WP-1.7 (`reconciliation_runs`) |
| **Blocks** | WP-2.5 |

### Problem restated

The engine is built and is exactly the right shape; WP-1.7 scheduled it and persisted its results.
What is still missing is the surface: `InventoryReportType` has 12 cases and **none is a
reconciliation report**, so a divergence introduced today is discovered at audit, by which time the
movements needed to explain it are months deep.

### Database

None — `reconciliation_runs` (WP-1.7) is the data source.

### Domain

- `InventoryReportType::Reconciliation` added; `sourcePermission()` → `StockView`.
- `InventoryReportService::reconciliation(array $filters): array` — latest run per invariant, with
  history, divergence detail and a pass/fail verdict per invariant.
- `ReconciliationRunRecorder` gains `latestFor(ReconciliationScope): Collection` and
  `hasUnresolvedFailure(ReconciliationScope, CarbonInterface $since): bool` — the second is what
  WP-2.5 calls.
- `ReconcileInventoryLotsCommand` gains `--scope=` and `--variant=` options so an investigator can
  narrow a replay without running the full ledger.

### API

Read shape: `GET /api/v1/reports/reconciliation` — verdict per invariant plus divergence rows.

### Filament

- `InventoryReports/Pages/ViewInventoryReports` gains the Reconciliation report, with the existing
  CSV export action (`InventoryReportFormatter` handles it as a thirteenth shape).
- The report renders **pass/fail per invariant as the first thing on the page**, in danger colour on
  failure. IN-17's "reported prominently as an error, never plugged" is a presentation requirement
  as much as a computational one.
- A `Run now` header action (permission-gated, queued) writing a `manual` run — so an investigator
  can re-prove after a fix without shell access.
- The WP-1.7 `ReconciliationStatus` widget links here.

### Mobile

None.

### Tests

**Feature** — `tests/Feature/Inventory/ReconciliationReportTest.php`
- The report shows the latest run per invariant and its history.
- A failing invariant renders in the failure state and is not roundable away.
- CSV export contains the divergence rows.
- `Run now` is permission-gated and writes a `manual` run.
- An empty history (before the first scheduled run) renders an explicit "never run" state rather
  than a false pass. This is the failure mode that would otherwise make the whole surface
  dangerous.

**Feature (Filament)** — extend `Feature/Filament/InventoryReportResourceTest.php`.

---

## WP-2.5 — Gate the period close on reconciliation (GAP-MW-18)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | AC-10 |
| **Flow** | F-14 |
| **Depends on** | WP-2.4, WP-2.6, WP-2.7 — the checklist can only check what has been built |

### Problem restated

`FiscalPeriodService::close()` authorises the actor and calls `setClosed()`, which flips `is_closed`
and writes an activity entry. There is no trial-balance check, no AR/AP reconciliation check, no
stock reconciliation check, and no close checklist. Refusing postings *into* a closed period is
correctly enforced; the **decision to close is entirely unguarded**. So a period can be closed over
an unbalanced subledger, and reopening is then the only route to correction — exactly the audited
exception AC-10 exists to make rare.

### Database

**Migration `2026_09_05_…_create_fiscal_period_close_checks_table.php`**

```
fiscal_period_close_checks       -- AC-10's "reconciliation pack", retained as evidence
  id, fiscal_period_id FK cascadeOnDelete
  check_key      string(60)     -- PeriodCloseCheck, cast
  passed         boolean
  detail         json nullable
  measured_at    timestamp
  reconciliation_run_id FK reconciliation_runs nullable nullOnDelete
  timestamps
  index (fiscal_period_id, check_key, measured_at)

fiscal_periods
  + closed_by            FK users nullable nullOnDelete
  + closed_at            timestamp nullable
  + close_override_reason text nullable          -- null unless a failing check was overridden
  + close_override_by    FK users nullable nullOnDelete
```

### Domain

**New enum `PeriodCloseCheck`** — `TrialBalanceBalances`, `ReceivablesAgreeToControlAccount`,
`PayablesAgreeToControlAccount`, `TaxRegisterAgreesToTaxAccounts`, `StockLedgerReconciles`,
`NoDraftJournalEntriesInPeriod`, `NoUnpostedPaymentsInPeriod`. Each has
`isMandatory(): bool` — the first five are mandatory, the last two advisory.

**New service `App\Services\Accounting\PeriodCloseChecklistService`**
- `run(FiscalPeriod): Collection<PeriodCloseResult>` — executes every check, persists the results,
  and returns them. Each check delegates to the service that owns the figure:
  `FinancialReportService::trialBalance()`, `AccountsReceivableService::reconciliation()`,
  `AccountsPayableService::payableControlAccountMinor()`, `TaxRegisterService::reconciliation()`,
  `ReconciliationRunRecorder::hasUnresolvedFailure(InventoryLots, $period->ends_at)`.
  **No check recomputes a figure a report already owns** — XC-04's rule that no report may compute a
  business figure by a rule that disagrees with the document owning it applies to the gate as well.
- `assertCloseable(FiscalPeriod): void` — throws `PeriodCloseBlocked` listing the failing mandatory
  checks.

**`FiscalPeriodService::close()`** calls `assertCloseable()` before `setClosed()`, and records
`closed_by` / `closed_at`.

**The override, and its limits.** A System Admin may close over a failing mandatory check **only**
with a written reason, recorded in `close_override_reason` / `close_override_by` and audited as a
distinct event `accounting.period.closed_with_override`. AC-10 says a period *cannot* be closed
while a reconciliation shows a difference; reality says a company sometimes must close and disclose.
The compromise is that the exception is loud, attributed and reportable — never silent. A separate
permission `AccountingPermission::PeriodCloseOverride` gates it, so the ability to close is not the
ability to close over a failure.

**`reopen()`** is unchanged but now writes a `PeriodCloseCheck` snapshot at reopen time, so the
before/after of the correction is evidenced.

### API

Read shape: `GET /api/v1/accounting/periods/{id}/close-checklist` — the pack an auditor asks for.

### Filament

- `FiscalPeriods/Pages/ViewFiscalPeriod` gains a **Close checklist** section: each check with
  pass/fail, its measured figures, and a drill-through link to the report that owns it.
- `FiscalPeriods/Actions/FiscalPeriodActions::close()` runs the checklist **before** opening the
  confirmation modal and renders the failures inside it. Closing is disabled unless all mandatory
  checks pass or the actor holds `PeriodCloseOverride`, in which case a reason field appears.
- `Run checklist` action (idempotent, re-runnable) so the accountant can work the exceptions
  iteratively rather than discovering them at the moment of closing.
- `AccountingDashboard` — a `PeriodCloseReadiness` widget for the current open period.
- Export of the pack to CSV, since it is the artefact handed to the auditor.

### Mobile

None.

### Tests

**Unit** — `tests/Unit/Enums/PeriodCloseCheckTest.php`: mandatory classification.

**Feature** — `tests/Feature/Accounting/PeriodCloseChecklistTest.php`
- A clean period passes all checks and closes.
- An unbalanced trial balance blocks the close with `PeriodCloseBlocked` naming the check.
- An AR/control-account divergence blocks it.
- A failing stock reconciliation within the period blocks it.
- A draft journal entry produces an advisory failure that does **not** block.
- Override with a reason closes and writes the override fields plus the distinct audit event.
- Override **without** `PeriodCloseOverride` is refused even for a System Admin lacking it.
- Override without a reason is refused.
- Results persist to `fiscal_period_close_checks` and are re-readable after the close.

**Feature (Filament)** — `tests/Feature/Filament/FiscalPeriodCloseTest.php`: the modal renders
failures; the close button is disabled without the override permission.

**Integration** — `tests/Feature/Accounting/MonthEndCloseFlowTest.php` (F-14): a full month of
sales, purchases, payments, a credit note and a write-off, then a close that passes; then a
deliberately divergent month that blocks and closes only after the divergence is corrected.

---

## WP-2.6 — Receivables subledger that proves itself (GAP-UI-02)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | AC-05, SL-07, SL-15 |
| **Flow** | F-14 |
| **Depends on** | WP-1.9 (write-offs affect outstanding) |
| **Blocks** | WP-2.5 |

### Problem restated

`AccountsReceivable` is one resource file and one page — a computed read-only surface over invoices,
payments and credit notes. No ageing buckets, no CSV export, no per-customer statement, no
reconciliation-to-control-account proof. The payables side, built later under ADR 0011, has all of
it. **The company can age and prove what it owes and cannot age or prove what it is owed.**

### Database

None. AC-05's binding rule is "derived, never stored" — an aggregate column here would be the
defect, not the fix.

Two **indexes** are added for the queries this package introduces, because the derivation must stay
fast enough to run inside the close checklist:
`invoices (customer_id, status, due_date)` and `payment_allocations (invoice_id)`.

### Domain

**New service `App\Services\Accounting\AccountsReceivableService`** — mirroring
`AccountsPayableService` method-for-method, which is the point (AD-03):

```php
public function summary(?CarbonInterface $asOf = null): array;
public function aging(?CarbonInterface $asOf = null): array;      // 0-30 / 31-60 / 61-90 / 90+ / not due
public function customerDetail(CustomerProfile $c, ?CarbonInterface $asOf = null): array;
public function toCsv(?CarbonInterface $asOf = null): string;
public function receivableControlAccountMinor(): int;
public function reconciliation(?CarbonInterface $asOf = null): array;  // subledger vs control account
public function statement(CustomerProfile $c, CarbonInterface $from, CarbonInterface $to): array;
```

Outstanding per invoice = `total_amount − credited_amount − allocated_payments − written_off`.
The write-off term is why this package sequences after WP-1.9; without it the subledger and the
control account would disagree by design the moment a write-off posts.

`reconciliation()` returns subledger total, control-account balance, the difference, and — when the
difference is non-zero — the candidate causes (unposted payments, journal entries hitting AR
directly, allocations to cancelled invoices). AC-05 requires the difference to be *shown as an
error, never plugged*, so the method returns the difference and never a reconciling adjustment.

`statement()` is the per-customer document SL-07's collection conversation needs.

### API

Read shape: `GET /api/v1/accounting/receivables/aging`,
`GET /api/v1/customers/{id}/statement?from=&to=`. A customer portal showing "what you owe" reads the
statement — and must read the *same* method the accountant reads, or the two will disagree.

### Filament

- `AccountsReceivable/Pages/ListAccountsReceivable` becomes an ageing view: buckets as columns,
  drill-through per customer, an as-of date filter, and a **reconciliation banner** at the top
  showing subledger vs control account, in danger colour when they differ.
- `Pages/ViewCustomerReceivable` — per-customer detail with document drill-through.
- `download_statement` (CSV and the existing dompdf path, reusing `pdf.invoice`'s layout
  conventions) and `download_aging` actions, matching the AP resource's export.
- `Customers` gains an `overdue` badge and a "Send statement" action (wired to WP-2.10).
- `AccountingDashboard` — `ReceivablesAgeing` widget.

### Mobile

The customer statement is the single most valuable read for a customer portal. Contract recorded.

### Tests

**Feature** — `tests/Feature/Accounting/AccountsReceivableServiceTest.php`
- Ageing buckets by due date, with an as-of date in the past reproducing the historical position.
- Outstanding accounts for credit notes, partial payments and write-offs.
- A cancelled invoice is excluded.
- `reconciliation()` returns zero difference for a clean set.
- A journal entry posted directly to AR produces a **non-zero** difference that is reported, not
  absorbed — the test that proves the proof works.
- `statement()` opens with the brought-forward balance and closes with the carried-forward.
- CSV export matches the on-screen figures (guarding the classic export/screen drift).

**Feature (Filament)** — `tests/Feature/Filament/AccountsReceivableResourceTest.php`: ageing
renders, the reconciliation banner shows danger on divergence, exports download, permissions bind.

**Integration** — `tests/Feature/Accounting/ReceivablesReconciliationTest.php`: issue, part-collect,
credit, write off — the subledger equals the control account at every step.

---

## WP-2.7 — Tax register that proves the period (GAP-UI-04)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | AC-06, MD-07, AC-10 |
| **Depends on** | nothing; **blocks** WP-2.5 |

### Problem restated

The write path is exactly right — proportional recognition with exact remainder settlement,
reversals handled, entries immutable and append-only. The read path is `TaxResource`: a list-only,
read-only page with a filter on `direction`. There is no period grouping, no
deferred-versus-payable summary, no reconciliation to the two tax accounts, and no export. So the
accounting rule the whole system is built around — **tax follows collection, not issuance** — has no
report that demonstrates it, and preparing a filing means reconciling a flat list by hand.

### Database

None. Entries are immutable facts; the register is a derivation.

Index added: `tax_recognition_entries (recognised_at, direction)`.

### Domain

**New service `App\Services\Accounting\TaxRegisterService`**
```php
public function period(CarbonInterface $from, CarbonInterface $to): array;
// returns, per AC-06:
//   output_tax_charged_deferred    -- tax on invoices issued in the period
//   output_tax_recognised_payable  -- tax recognised on collections in the period
//   output_tax_reversed            -- credit notes and refunds
//   input_tax_recognised           -- purchase tax on approved bills and expenses
//   net_position
public function reconciliation(CarbonInterface $from, CarbonInterface $to): array;
// deferred tax account movement vs register deferred movement; tax payable likewise; difference
public function toCsv(CarbonInterface $from, CarbonInterface $to): string;
public function entriesFor(CarbonInterface $from, CarbonInterface $to, ?TaxDirection $d): LazyCollection;
```

The deferred-versus-payable split is the register's whole purpose ("that distinction *is* the
company's tax policy"), so it is the top-level shape of the return value, not a derived column.

`reconciliation()` compares the register's movement against the *journal* movement on the deferred
tax and tax payable accounts, returning both sides and the difference. This is the artefact AC-06
demands and the leg WP-2.5's close checklist calls.

### API

Read shape: `GET /api/v1/accounting/tax-register?from=&to=`.

### Filament

- New page `Taxes/Pages/ViewTaxRegister` — period selector (defaulting to the current fiscal
  period), the four-figure summary with the deferred/payable split, the reconciliation panel showing
  register vs ledger for both accounts, and the entry list beneath, drill-through to invoice,
  payment, credit note, refund or bill.
- CSV export of both the summary and the entries.
- `ListTaxes` remains as the raw register; the new page is the *report*. Keeping both is deliberate:
  AC-11 requires that any figure be traceable to its cause, and the flat list is the trace.
- **GAP-UI-07 partial resolution:** `AdminModuleRegistry`'s `admin.resources.tax_definitions` entry
  is re-pointed at `SalesSettings` (which genuinely owns the default rate and the posting accounts)
  and the dangling `TaxDefinitionResource` import is removed. MD-07's "the Accountant sets the
  default tax rate and the four posting accounts" gets an obvious home. The remaining three
  placeholders are handled by WP-3.7.
- `AccountingDashboard` — a `TaxPositionThisPeriod` widget.

### Mobile

None.

### Tests

**Feature** — `tests/Feature/Accounting/TaxRegisterServiceTest.php`
- Issuing an invoice increases deferred and not payable.
- Collecting moves the proportional amount from deferred to payable.
- A credit note reverses the correct split.
- A refund un-recognises proportionally.
- An approved bill records input tax.
- The period figures reconcile to the deferred tax and tax payable account movements exactly.
- A manual journal entry against a tax account produces a reported difference.
- Period boundaries: an invoice issued on the last day and collected on the first day of the next
  period appears as deferred in one and payable in the other. This is the boundary case a hand-rolled
  register always gets wrong.

**Feature (Filament)** — `tests/Feature/Filament/TaxRegisterPageTest.php`: rendering, the
reconciliation panel's failure state, export, permission binding.

**Unit** — extend `tests/Unit/AdminModuleRegistryTest.php`: the tax entry resolves to a real class,
not `ModulePlaceholder`.

---

## WP-2.8 — The sales reporting surface (GAP-MW-17, GAP-UI-05)

| | |
|---|---|
| **Priority** | **High** (MW-17) + **Medium** (UI-05, which is the same build) |
| **Scenario** | SL-15, CR-08, XC-04 |
| **Depends on** | WP-2.3 (provenance for discount reporting), WP-2.2 (win/loss), WP-1.3 (returns without credit) |

### Problem restated

Every other module has a report resource. **Sales has none.** `app/Services/Sales/` contains no
report service; the entire sales reporting surface is two dashboard widgets. A grep for
`delivered_not_invoiced` / `uninvoiced` returns nothing — and SL-15 names delivered-not-invoiced and
invoiced-not-collected as first-class *because they are the two places money silently leaks in a
Quotation → Delivery → Invoice → Payment model*. Both are unmonitored. Separately, no sales
resource has an export action, so finance cannot get invoice or payment data out without database
access.

### Database

None. Indexes added to keep the two leak reports cheap:
`inventory_operations (type, stage, completed_at)` and `invoices (issued_at, status)`.

### Domain

**New enum `SalesReportType`** with `sourcePermission()`, mirroring `InventoryReportType`:

| Case | Answers |
|---|---|
| `QuotationFunnel` | volume and stage conversion by employee, customer, product |
| `WinLossAnalysis` | win rate and ranked loss reasons (needs WP-2.2) |
| `ConversionVelocity` | median days per lifecycle stage |
| `DeliveredNotInvoiced` | **leak point 1** — completed deliveries with no invoice, aged |
| `InvoicedNotCollected` | **leak point 2** — issued invoices with outstanding balance, aged |
| `TaxRecognitionSummary` | recognised vs deferred by period (reads `TaxRegisterService`) |
| `DiscountAndFloorOverrides` | discount incidence and every floor override with its approver |
| `ReturnsWithoutCredit` | posted customer returns with no confirmed credit note (WP-1.3) |
| `CustomerRevenue` | revenue by customer, invoiced and collected |

**New service `App\Services\Sales\SalesReportService`** — one method per case, each returning an
array, following `FinancialReportService`'s shape exactly. `DeliveredNotInvoiced` deliberately
counts **standalone invoices too**, so the GAP-MW-13 workaround (a standalone invoice referencing no
delivery) cannot hide a delivery from the report.

**`SalesReportFormatter`** for CSV, mirroring `InventoryReportFormatter`.

**Permissions:** `SalesPermission::ReportView` and `SalesPermission::Export`. The existing,
never-checked `SalesPermission::AuditView` is wired to the audit-log filter on sales resources in
the same package, closing one of the four dead permissions from systemic finding 6.

### API

Read shape: `GET /api/v1/reports/sales/{type}` with filters, plus `?format=csv`.

### Filament

- New `SalesReports/` resource + `Pages/ViewSalesReports`, cloned from `FinancialReports` — a report
  selector, filters, table, and a per-report CSV export action.
- **Exports on the sales documents (GAP-UI-05):** a `ExportAction` on `Invoices`, `Payments`,
  `Quotations`, `CreditNotes` and `Orders` tables, honouring the current filters and writing an
  `InventoryExport`-style record with its parameters (XC-04's "retained with their parameters so a
  figure can be reproduced"). The existing `RequestsInventoryExports` concern is generalised to
  `RequestsDocumentExports` and reused rather than re-implemented.
- `SalesDashboard` gains `SalesLeakage` — one widget showing the two leak figures side by side,
  because that pairing is the point.

### Mobile

A field salesperson's "my pipeline / my win rate" view reads `QuotationFunnel` and
`WinLossAnalysis` scoped to themselves. Contract recorded.

### Tests

**Unit** — `tests/Unit/Enums/SalesReportTypeTest.php`: every case maps to a permission and a label.

**Feature** — `tests/Feature/Sales/SalesReportServiceTest.php`, one group per report:
- `DeliveredNotInvoiced` lists a completed delivery with no invoice and **excludes** one covered by
  a standalone invoice that references it; ages correctly.
- `InvoicedNotCollected` matches `AccountsReceivableService::aging()` figure-for-figure — a
  cross-service consistency assertion, so XC-04's "no report may compute a figure by a rule that
  disagrees" is enforced by test rather than by hope.
- `WinLossAnalysis` ranks loss reasons and computes win rate per employee.
- `DiscountAndFloorOverrides` surfaces an override with its approver.
- `ReturnsWithoutCredit` lists an unlinked posted return and drops it once credited.

**Feature (Filament)** — `tests/Feature/Filament/SalesReportResourceTest.php` and
`SalesDocumentExportTest.php`: exports download, honour filters, record parameters, and are
permission-gated.

---

## WP-2.9 — Service job cost and chargeable billing (GAP-MW-09, GAP-MW-10)

| | |
|---|---|
| **Priority** | **High ×2** |
| **Scenario** | MT-05, MT-06, MT-08 |
| **Flow** | F-06, F-07 |
| **Why one package** | A job with no cost cannot be billed correctly and a bill with no job cannot show margin. Splitting them ships two halves that prove nothing |

### Problem restated

`service_record_parts` stores the part, the warehouse, the quantity and the movement — and **no cost
column of any kind**. There is no labour time field, no labour rate, no third-party cost, and no
job-cost or service-margin report. Separately, there is **no link** from `MaintenanceRecord` or
`MaintenanceTask` to a quotation or an invoice; billing service work means an accountant hand-keying
a standalone invoice with no reference to the job. So F-07 produces exactly what MT-05 warns
against — a warranty job as a blank record — and F-06 breaks at the Maintenance → Sales seam.

### Database

```
service_record_parts
  + unit_cost_minor   bigint unsigned nullable   -- snapshot at consumption
  + total_cost_minor  bigint unsigned nullable
  + cost_source       string(30) nullable        -- CostSource: LastReceivedCost | ManualEntry | Unknown

maintenance_labour_entries                        -- new
  id, maintenance_record_id FK cascadeOnDelete
  service_record_id FK maintenance_tasks nullable nullOnDelete
  employee_id FK users restrictOnDelete
  performed_on date
  minutes unsigned int
  hourly_rate_minor bigint unsigned
  total_cost_minor bigint unsigned
  notes text nullable
  created_by FK users restrictOnDelete
  timestamps
  index (maintenance_record_id, performed_on)

maintenance_third_party_costs                     -- new
  id, maintenance_record_id FK cascadeOnDelete
  supplier_id FK suppliers nullable nullOnDelete
  bill_id FK bills nullable nullOnDelete          -- links the cost to the payable that paid it
  description string(255), amount_minor bigint unsigned
  incurred_on date, created_by FK users restrictOnDelete
  timestamps

maintenance_records
  + billing_type   string(30) not null default 'unbilled'  -- MaintenanceBillingType, cast
  + quotation_id   FK quotations nullable nullOnDelete
  + invoice_id     FK invoices nullable nullOnDelete
  + billed_at      timestamp nullable
  index (billing_type, closed_at)

invoices
  + maintenance_record_id FK maintenance_records nullable nullOnDelete
  index (maintenance_record_id)

employee_profiles
  + default_hourly_rate_minor bigint unsigned nullable      -- the labour rate source
```

**Cost snapshot rationale.** `unit_cost_minor` is copied from
`SupplierCostWritebackService`'s last-received unit cost **at consumption time**. It is a snapshot,
not a lookup, for the same reason as the price provenance in WP-2.3: a later cost change must not
retroactively restate a closed job's margin. `cost_source = Unknown` where no cost exists, which is
honest — and is why the margin report shows a coverage percentage rather than pretending.

**This is not COGS.** No ledger posting is added here. Job costing is a management figure derived
from snapshots; inventory valuation and cost of sales remain deferred (GAP-MW-15 / WP-4.6).
`NoAutomaticPostingTest` is extended to assert that consuming a part still posts nothing.

### Domain

**New enums:** `MaintenanceBillingType` (`Unbilled | WarrantyCovered | TicketSettled | Quoted |
Invoiced`), `CostSource`.

**`ServiceRecordPartService::consume()`** additionally snapshots the cost. `reverse()` reverses it.

**New service `App\Services\Support\MaintenanceCostService`**
```php
public function jobCost(MaintenanceRecord $r): array;   // parts, labour, third-party, total, coverage%
public function recordLabour(LabourEntryData, User): MaintenanceLabourEntry;
public function recordThirdPartyCost(ThirdPartyCostData, User): MaintenanceThirdPartyCost;
public function marginFor(MaintenanceRecord $r): array; // cost, revenue, margin, billing type
```

**New service `App\Services\Support\MaintenanceBillingService`**
```php
public function markWarrantyCovered(MaintenanceRecord, User, string $reason): MaintenanceRecord;
public function createQuotation(MaintenanceRecord, User): Quotation;
public function createInvoice(MaintenanceRecord, User): Invoice;
```

Both delegate to the **existing** `QuotationService::create()` and `InvoiceService::createStandalone()`
— MT-06's binding rule is that service revenue uses the *same* invoicing, collection and
tax-recognition machinery, because "a second revenue path would be a second tax policy". This
package therefore adds **no** posting service, **no** tax path and **no** revenue account of its own.
It builds lines from the job's parts (at sale price via `PriceResolver`, carrying provenance per
WP-2.3) and its labour (as a service line at the configured rate), then hands them to Sales.

Guards: only a `Closed` maintenance record may be billed; a record is billed once (the FK plus a
service check); a `WarrantyCovered` record cannot be invoiced without first changing its billing
type, which is an audited decision with a reason.

**Events:** `MaintenanceRecordBilled` — consumed by WP-2.10.

**Permissions:** `SupportPermission::MaintenanceCostView|CostRecord|Bill`.

### API

Command shapes: `LabourEntryData`, `ThirdPartyCostData`. Read shape: job cost and margin, and the
device history MT-08 needs. A technician app recording labour time on site is the obvious consumer,
and it is the field capture that makes labour cost real rather than estimated.

### Filament

- `MaintenanceRequests/RelationManagers/LabourEntries` and `ThirdPartyCosts`.
- `MaintenanceRequests/Schemas/MaintenanceRecordInfolist` gains a **Job cost** section — parts,
  labour, third-party, total, and revenue with the margin, plus a cost-coverage note where a part
  has `cost_source = Unknown`.
- `MaintenanceRequests/Actions/MaintenanceBillingActions` — `mark_warranty_covered` (reason
  required), `create_quotation`, `create_invoice`, each visible only in the valid state.
- `ConsumedParts` relation manager shows unit and total cost.
- **New report** `SupportReportType::ServiceMargin` — margin per job, per equipment, per customer,
  with warranty-covered work shown at zero revenue and its real cost. This is MT-05's headline
  requirement: free-of-charge service made visible as a cost centre.
- `SupportDashboard` — `WarrantyCostThisPeriod` widget.
- `SerializedInventoryUnits` device timeline gains service records, parts fitted and costs (MT-08).

### Mobile

Technician labour capture on site — start/stop or duration plus notes — is the single highest-value
field feature in this module. Contract recorded.

### Tests

**Unit** — `tests/Unit/Services/MaintenanceCostServiceTest.php`: cost aggregation across the three
sources; coverage percentage when a part has no cost.

**Feature** — `tests/Feature/Support/MaintenanceCostTest.php`
- Consuming a part snapshots the cost; a later variant cost change does not restate it.
- Reversing a consumption reverses the cost.
- Labour and third-party costs aggregate.
- A warranty-covered job reports real cost and **zero** revenue.
- Consuming a part still writes no journal entry (the deferral, re-pinned).

**Feature** — `tests/Feature/Support/MaintenanceBillingTest.php`
- Billing a closed record creates an invoice linked both ways, with parts at sale price carrying
  provenance and labour as a service line.
- Billing an open record throws.
- Billing twice throws.
- A warranty-covered record cannot be invoiced until reclassified, and the reclassification is
  audited with its reason.
- The invoice follows the **standard** path: issuing posts the normal entry, collecting recognises
  tax proportionally — asserted against the same accounts as a goods invoice, which is MT-06's
  "not a second tax policy" made testable.

**Integration** — `tests/Feature/Support/WarrantyServiceFlowTest.php` (F-07): ticket → maintenance →
parts consumed → labour recorded → warranty-covered → the margin report shows the cost at zero
revenue. And `ChargeableServiceFlowTest.php` (F-06): ticket → maintenance → invoice → payment → tax
recognised.

---

## WP-2.10 — Notification and reminder engine (GAP-MW-12)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | XC-03, SL-07 |
| **Depends on** | AD-05; consumed by WP-2.1, WP-2.6, WP-2.9, WP-2.14, WP-3.6 |
| **Track** | Runs in parallel from Phase 1, like WP-2.1 |

### Problem restated

`app/Notifications/` **does not exist**. The only outbound mail is `InvoiceMail` via the
`SendInvoiceEmail` job; the only other notification is an internal queued job. There is no delivery
log, no retry policy, no template layer, and no scheduled reminder. `Invoice::isOverdue()` correctly
derives overdue status and **nothing acts on it**. Every alerting mechanism in the specification
degrades to "someone remembers to look at a screen", and SL-07's required evidence of the collection
conversation does not exist.

### Database

```
notification_templates
  id, key string(80)                 -- e.g. invoice.issued
  locale string(5)                   -- en | ar   (XC-08)
  channel string(20)                 -- NotificationChannel: Mail | Database | Sms | Whatsapp
  subject string(255) nullable
  body text                          -- blade-safe placeholder syntax, variables declared below
  variables json                     -- declared variable names, validated at render
  is_active boolean default true
  timestamps
  unique (key, locale, channel)

notification_deliveries              -- XC-03's binding requirement
  id
  notifiable_type, notifiable_id
  template_key string(80), channel string(20), locale string(5)
  subject_document_type, subject_document_id nullable   -- "a notification references the document"
  status string(20)                  -- NotificationDeliveryStatus: Queued|Sent|Failed|Suppressed|Bounced
  attempt unsigned tinyint default 1
  error string(500) nullable
  queued_at, sent_at, failed_at timestamp nullable
  timestamps
  index (notifiable_type, notifiable_id, created_at)
  index (status, created_at)
  index (template_key, created_at)

notification_preferences
  id, user_id FK cascadeOnDelete
  template_key string(80), channel string(20), enabled boolean default true
  unique (user_id, template_key, channel)
```

### Domain

**Enums:** `NotificationChannel`, `NotificationDeliveryStatus`, `NotificationEventKey` (the
catalogue of the ten XC-03 triggers, so a template key cannot be a free string typo).

**Services**
- `NotificationTemplateRenderer::render(key, locale, channel, array $vars): RenderedNotification` —
  validates that every declared variable is supplied and that no undeclared one is used; falls back
  to the default locale with a logged warning rather than sending an empty body.
- `NotificationDispatcher::dispatch(Notifiable, NotificationEventKey, array $vars, ?Model $subject)`
  — checks preferences and `communication_suppressions` (shared with WP-2.1), writes a `Queued`
  delivery row, queues the notification, and is the **only** entry point.
- The Laravel notification's `failed()` hook and a `NotificationSent` listener update the delivery
  row. Retry uses the queue's backoff with `tries = 3`; a final failure lands `Failed` with the
  error — **never silently dropped**, which is the scenario's actual requirement.

**Events raised by domain services** (not mailer calls — AD-05): `InvoiceIssued`, `PaymentReceived`,
`QuotationDecided`, `TaskAssigned`, `VisitDue`, `TicketUpdated`, `SlaAtRisk`, `StockLow`,
`LotExpiring`, `ApprovalPending`, plus `InventoryReservationExpired` (WP-1.6), `LeadConverted` and
`CampaignCompleted` (WP-2.1), `MaintenanceRecordBilled` (WP-2.9).

**One listener** — `SendBusinessNotification` — maps event to template key and recipients. Domain
services never touch the notification layer, so a change of channel is not a change to accounting
code.

**Scheduled reminder commands**
- `notifications:overdue-invoices` (daily) — SL-07's chase. Escalating templates at 7 / 30 / 60 days
  past due, one per invoice per threshold (a `notification_deliveries` uniqueness check prevents
  daily re-nagging, which is XC-03's "volume is managed so alerts stay meaningful").
- `notifications:expiring-lots` (daily) — IN-10.
- `notifications:pending-approvals` (daily) — bills, expenses, refunds, write-offs, purchase orders.
- `notifications:visits-due` (daily) — EM-03.
- **`notifications:retry-failed` (hourly)** — re-queues `Failed` deliveries under the retry cap. The
  scenario says "retried, never silently dropped"; without this command "retried" means only "the
  queue tried three times in ten minutes".

`SendInvoiceEmail` / `InvoiceMail` are **migrated onto** this layer, so there is one path, not two.

### API

Read shape: `GET /api/v1/notifications` (in-app inbox) and
`POST /api/v1/notifications/{id}/read`. A mobile app's push registration attaches here later.

### Filament

- `NotificationTemplates/` resource — full CRUD, locale tabs, a variable reference panel, and a
  **preview** action rendering with sample data. A template that cannot be previewed will be edited
  in production by guesswork.
- `NotificationDeliveries/` — read-only list with status, channel, recipient, document link, error,
  attempt count; a `retry` action for a failed delivery; filters on status and template.
- `Pages/NotificationSettings` — per-user preferences, and admin defaults per role.
- Filament's own database-channel notification bell is enabled so in-app delivery is a real channel
  and not only email.
- `Dashboard` — a `FailedNotifications` widget (count in the last 24 hours), because a silently
  broken notification layer is worse than none.

### Mobile

Push is a channel added to `NotificationChannel`; the delivery log, preferences and templates all
work unchanged. This is why the channel is an enum on the delivery row rather than an assumption in
the code.

### Tests

**Unit** — `tests/Unit/Services/NotificationTemplateRendererTest.php`: variable validation, missing
variable throws, locale fallback logs and still renders.

**Feature** — `tests/Feature/Notifications/NotificationDispatchTest.php`
- Dispatch writes a `Queued` row, then `Sent` on success.
- A failing channel writes `Failed` with the error and does not throw into the caller.
- A suppressed address is `Suppressed` and never sent.
- A disabled preference suppresses the send but still records the decision.
- `notifications:retry-failed` re-queues under the cap and stops at it.
- Arabic locale renders the Arabic template (XC-08).

**Feature** — `tests/Feature/Notifications/OverdueInvoiceReminderTest.php`
- The 7/30/60 thresholds each fire once and only once per invoice.
- A paid invoice stops the chase.
- A written-off invoice stops the chase (the WP-1.9 interaction, easy to miss).

**Feature (Filament)** — template CRUD, preview, delivery list, retry action.

**Architecture** — extend `tests/Unit/ArchTest.php`: no class in `App\Services` may reference
`Illuminate\Support\Facades\Mail` or `Notification` directly; only the listener and dispatcher may.
This is the rule that keeps AD-05 true a year from now.

---

## WP-2.11 — Correction documents for deliveries and transfers (GAP-BW-02)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | IN-16 |

### Problem restated

`InventoryCorrectionService` implements the pattern correctly (`Draft → Posted | Cancelled`, writing
`MovementType::Correction`), but `InventoryCorrectionType` has exactly **one case: `Receipt`**. A
wrongly posted delivery — wrong warehouse, wrong quantity, wrong serial — has no linked correction
path. The workarounds are a customer return (which asserts goods physically came back and validates
against the delivery, so it fails for an internal keying error) or an adjustment (which posts an
unlinked movement, breaking IN-16's "every post-commit change is a new document with a reference to
the original"). A transfer posted between the wrong warehouses has no remedy at all beyond a second
compensating transfer with no reference to the first.

### Database

**Migration** — `inventory_corrections` gains `correction_reason string(40)` (cast
`ConditionChangeReason`, reusing WP-1.1's controlled list) and
`target_warehouse_id FK warehouses nullable nullOnDelete` (a transfer correction may need to name
the warehouse the stock should have gone to). No new table: the document family already exists and
is the right shape.

### Domain

- `InventoryCorrectionType` gains `Delivery` and `Transfer`.
- `InventoryCorrectionService::create()` validates per type:
  - `Delivery` — the origin operation is a completed `Delivery`; the correction quantity per line is
    capped at delivered minus already-corrected minus already-**returned** (the WP-1.3 link means
    the two documents can finally see each other); serialised units must be the ones that left.
  - `Transfer` — the origin is a completed `InternalTransfer`; the correction reverses the
    `transferOut`/`transferIn` pair, and where the destination was wrong, posts to
    `target_warehouse_id` in the same transaction so stock is never in two places or none.
- `post()` writes `MovementType::Correction` movements through the canonical path, referencing the
  origin operation.
- **A delivery correction is explicitly not a customer return.** The service refuses a correction
  where the goods physically came back — that is IN-13's document — and the refusal message says so,
  because the two are easy to confuse and choosing wrongly corrupts the return's evidence.
- `InventoryAdjustmentService::createCorrection()` is left in place but its Filament entry point is
  removed for delivery/transfer scenarios, so operators are routed to the linked document. It stays
  for adjustment-of-adjustment, which is its real job.

### API

Command shape: `InventoryCorrectionData.type`, `.targetWarehouseId`, `.correctionReason`.

### Filament

- `InventoryCorrections/Schemas/InventoryCorrectionForm` gains the type selector and a
  type-dependent origin picker (receipts, deliveries or transfers).
- `InventoryOperations` gains a `create_correction` action on completed delivery and transfer
  operations, pre-filling the origin and lines — the linked path made the easy one.
- `DeliveryNotes/Pages/ViewDeliveryNote` shows corrections raised against it.
- `InventoryOperations` infolist gains a "Corrections" section (two-way navigation, AC-11's
  principle applied to stock).

### Mobile

None.

### Tests

**Unit** — `tests/Unit/Enums/InventoryCorrectionTypeTest.php`: three cases, each with its allowed
origin operation type.

**Feature** — `tests/Feature/Inventory/DeliveryCorrectionTest.php`
- Over-correcting a delivery throws; the cap accounts for prior corrections **and** prior returns.
- Correcting restores the balance at the right condition and writes `Correction` movements
  referencing the origin.
- Serialised custody returns to the warehouse.
- A correction on a non-completed delivery throws.

**Feature** — `tests/Feature/Inventory/TransferCorrectionTest.php`
- A wrong-destination transfer corrects to the right warehouse in one transaction; neither warehouse
  is left over- or under-stated at any point (asserted inside the transaction via a lock probe).
- A transfer correction on a `PartiallyReceived` transfer is refused with a message pointing at the
  shortage path.

**Integration** — `tests/Feature/Inventory/PostedDocumentCorrectionTest.php` (IN-16): receipt,
delivery and transfer each corrected; the movement ledger replays to the correct balance and
`InventoryLotReconciliationService::inspect()` passes after each.

---

## WP-2.12 — Audit the acts that move stock (GAP-BW-06)

| | |
|---|---|
| **Priority** | **Medium** |
| **Scenario** | XC-02, IN-01, IN-04, IN-05 |

### Problem restated

Audit coverage is genuinely good — 79 distinct event names with a `withProperties` convention
carrying `source_channel` and `ip_address`. But `InventoryOperationService` contains exactly **one**
`log()` call: `inventory.operation.canceled`. Marking ready, dispatching, completing and receiving a
transfer — the acts that actually move stock — write no activity entry. An operation cancelled is
auditable; an operation completed is not. For the one module where a mistake is physically
irreversible, the audit trail is thinner than for a pricing tier edit.

### Database

None.

### Domain

`InventoryOperationService` gains `activity()` calls in `markReady()`, `dispatch()`, `complete()`
and `receiveTransfer()`, matching the established convention exactly:
`inventory.operation.marked_ready|dispatched|completed|transfer_received`, with
`performedOn($operation)`, `causedBy($actor)`, `withChanges(['old' => …, 'attributes' => …])`
carrying the stage transition and the line summary, and
`withProperties(['source_channel' => …, 'ip_address' => …])`.

**Placement matters:** the log is written **inside** the existing transaction, after posting and
before the event dispatch, so an audit entry never exists for an operation that rolled back. The
`InventoryDamageService` precedent (log inside `DB::transaction`) is followed.

**Payload size:** the line summary is capped (count, total base quantity, first five lines) rather
than serialising every line, because a 400-line delivery would otherwise write a large properties
blob on every completion.

### API

None.

### Filament

- `AuditLogs` resource: the four new event names appear automatically.
- `InventoryOperations/Schemas/InventoryOperationInfolist` gains an **Activity** section — who did
  what, when, from which channel — so the answer to "who confirmed this delivery" is on the document
  rather than requiring a join across the movement ledger.
- The three never-checked `AuditView` permissions (`SalesPermission`, `AccountingPermission`,
  `EmployeePermission` — systemic finding 6) are wired to that section's visibility, closing the
  dead-permission finding in the same package.

### Mobile

The `source_channel` property is what makes a field-app confirmation distinguishable from an office
one. It is already in the convention; this package makes stock movement use it.

### Tests

**Feature** — `tests/Feature/Inventory/InventoryOperationAuditTest.php`
- Each of the four acts writes exactly one activity entry with the expected name, causer, changes
  and properties.
- A rolled-back completion writes **no** entry (the transaction-placement guarantee, asserted by
  forcing a posting failure).
- The line summary is capped for a large operation.
- Cancellation still writes its existing entry, unchanged.

**Feature (Filament)** — `tests/Feature/Filament/InventoryOperationAuditSectionTest.php`: the
section renders for a permitted actor and is hidden without `AuditView`.

---

## WP-2.13 — Consolidated invoicing (GAP-MW-13)

| | |
|---|---|
| **Priority** | **Medium** |
| **Scenario** | SL-06 alternate path |
| **Depends on** | WP-1.8 (invoice status enum) |

### Problem restated

`invoices.inventory_operation_id` is a **nullable unique** foreign key, making the relationship
strictly one-to-one in both directions. `InvoiceService::createFromDelivery()` enforces
single-invoicing correctly, but there is no many-deliveries-to-one-invoice path. A customer taking
twenty deliveries in a month receives twenty invoices — or an accountant raises a standalone invoice
referencing **no** delivery, which silently defeats the "a delivery is invoiced at most once"
control because the standalone invoice is invisible to it. The workaround breaks the invariant the
constraint was built to protect.

### Database

**Migration `2026_09_05_…_allow_consolidated_invoicing.php`**

```
invoice_delivery_links                    -- new join table
  id
  invoice_id             FK invoices cascadeOnDelete
  inventory_operation_id FK inventory_operations restrictOnDelete
  timestamps
  unique (inventory_operation_id)          -- THE control: a delivery is invoiced at most once,
                                           -- now enforced across every invoice, standalone included
  index (invoice_id)

invoices
  ~ inventory_operation_id   retained, kept in sync for the single-delivery case, its unique index
                             dropped; deprecated in the model docblock and removed by WP-4.2 once
                             no reader remains
```

**The invariant is strengthened, not weakened.** Today the control lives on a nullable unique column
that a standalone invoice bypasses. After this change it lives on a join table whose unique index
covers *every* invoice-to-delivery link, so the workaround that defeated it is no longer possible.

**Backfill:** one link row per existing invoice carrying an `inventory_operation_id`, inside the
same migration, with a count assertion afterwards.

### Domain

- `InvoiceService::createFromDeliveries(Collection<InventoryOperation>, InvoiceData, User): Invoice`
  — asserts all deliveries share one customer and one currency, none is already linked (checked
  under a lock over the operation rows in id order), each is `Done`, and aggregates lines by variant
  and unit price while preserving per-delivery provenance in the line description.
- `createFromDelivery()` becomes a thin wrapper over it, so there is one implementation.
- `createStandalone()` gains an **optional** delivery link, and the `DeliveredNotInvoiced` report
  (WP-2.8) reads the join table — so a standalone invoice can now be *correctly* attributed instead
  of hiding the delivery.
- Payment terms and due date resolve from the invoice date as today; MD-06's snapshot rule is
  unaffected.

### API

Command shape: `InvoiceData.deliveryIds: array<int>`.

### Filament

- `Invoices/Actions/InvoiceActions::create_consolidated` — select a customer, see uninvoiced
  completed deliveries with dates and values, choose several, preview the aggregated lines, create.
- `Invoices/Schemas/InvoiceInfolist` lists **all** linked deliveries.
- `DeliveryNotes/Tables/DeliveryNotesTable` gains an "Invoiced" column and an "Uninvoiced" filter —
  the operator-facing half of the leak report.
- `Customers` gains a "Deliveries awaiting invoice" badge.

### Mobile

None.

### Tests

**Feature** — `tests/Feature/Sales/ConsolidatedInvoicingTest.php`
- Three deliveries for one customer produce one invoice with aggregated lines and three links.
- A delivery already linked to another invoice is refused.
- Mixed customers are refused.
- A non-completed delivery is refused.
- Concurrency: two simultaneous consolidations sharing a delivery — exactly one succeeds.
- Tax and totals equal the sum of the three individually-invoiced equivalents.

**Feature (migration)** — `tests/Feature/Sales/InvoiceDeliveryLinkBackfillTest.php`: every existing
linked invoice gains exactly one row; the count matches.

**Feature (regression)** — `Feature/DeliveryWizardTest.php` and `AccountingDocumentResourcesTest.php`
pass unchanged; single-delivery invoicing behaves identically.

---

## WP-2.14 — Quotation expiry becomes a state, not an accident (GAP-BW-07)

| | |
|---|---|
| **Priority** | **Medium** |
| **Scenario** | SL-02, SL-16 |
| **Flow** | F-18 |
| **Depends on** | WP-2.10 (the re-quote prompt) |

### Problem restated

The enforcement half is correct: `recordDecision()` checks `isExpired()` and writes
`QuotationStatus::Expired` before refusing acceptance. But the stored status of every *other*
expired quotation stays `Sent` indefinitely — no sweep, no scheduled transition, no listing-level
recomputation. "Open quotations" over-counts by every offer whose validity lapsed untouched, and
pipeline and win-rate figures computed from stored status are wrong in the same direction. F-18's
re-quote journey is never triggered because nothing announces the lapse.

### Database

Index `quotations (status, valid_until)` — the sweep's covering index.

### Domain

- **New command** `ExpireQuotationsCommand`, signature `sales:quotations:expire`, scheduled daily.
  Selects `status = Sent AND valid_until < today`, chunks by id, and transitions each through
  `QuotationService` — **not** by a mass `update()`, so the existing transition guard, the audit
  entry and any reservation release all run. A mass update would be faster and would silently skip
  every side effect the lifecycle owns.
- Expiry releases any reservation the quotation holds, via the WP-1.6 release path with the reason
  "quotation expired". This closes the loop between the two expiry mechanisms, which would
  otherwise disagree about whether stock is still promised.
- Raises `QuotationExpired`, consumed by WP-2.10 to notify the owner and prompt the re-quote.
- `QuotationService::recordDecision()` is unchanged — the enforcement half already works and stays
  as the backstop for a quotation that expires between the sweep and the decision.

### API

Read shape: a quotation's `is_expired` is a stored status a client can trust rather than a
computation it must repeat.

### Filament

- `Quotations/Tables/QuotationsTable` — an "Expiring within 7 days" filter, and the status badge is
  now truthful without a computed override.
- `Quotations/Actions/QuotationActions::requote` — clones an expired quotation into a new draft with
  **freshly resolved** prices (WP-2.3 provenance recomputed, since SL-16's whole point is that
  policy may have changed) and a reference to the original.
- `SalesDashboard` — `ExpiringQuotations` widget.

### Mobile

None.

### Tests

**Feature** — `tests/Feature/Sales/QuotationExpirySweepTest.php`
- A lapsed `Sent` quotation becomes `Expired` and its reservation is released.
- An `Accepted` or `Rejected` quotation is untouched.
- A quotation with no `valid_until` is untouched.
- The sweep is idempotent across two runs.
- `QuotationExpired` fires once per quotation.
- The audit entry is written (proving the service path ran, not a mass update).

**Feature** — `tests/Feature/Sales/QuotationRequoteTest.php`: the clone re-resolves prices against
current policy and links to the original.

**Feature (scheduler)** — extend `tests/Feature/ScheduledCommandsTest.php`.

---

# Phase 3 — UX and operator-workflow completion

**Theme:** the behaviour exists in the domain; make it reachable, explainable and comfortable to
operate. Two packages here (WP-3.5, WP-3.6) are workflow completions rather than pure UX — they are
sequenced into this phase because both are Medium priority and both build directly on Phase 1 and 2
foundations rather than standing alone.

---

## WP-3.1 — Customer 360 (GAP-UI-03)

| | |
|---|---|
| **Priority** | **High** |
| **Scenario** | CR-05, MD-01, PM-10 |
| **Depends on** | WP-2.1 (interactions are half the timeline), WP-2.6 (receivables position) |

### Problem restated

`CustomerProfile` declares exactly two relations — `user()` and `deliveryAddresses()`. It has no
`quotations()`, `orders()`, `invoices()`, `payments()`, `tickets()`, `visits()` or
`maintenanceRecords()`, **even though every one of those tables carries a `customer_id`**. The
inverse relations exist, so the data is reachable from the other side but not from the customer
record, and `ViewCustomer` has no relation managers. The account manager assembles the relationship
from five screens — exactly what CR-05 says they should never have to do.

### Database

None. Every foreign key already exists; this is the gap's whole character.

Two covering indexes are added because a timeline is a union of eight queries against a customer:
`tickets (customer_id, created_at)` and `customer_visits (customer_id, scheduled_at)`. The others
already have suitable indexes.

### Domain

- `CustomerProfile` gains: `quotations()`, `orders()`, `invoices()`, `payments()`, `creditNotes()`,
  `refunds()`, `tickets()`, `visits()`, `maintenanceRecords()`, `interactions()` (morphMany, from
  WP-2.1), `leads()` (the leads that converted into this customer), `writeOffs()`.
- **New service `App\Services\Crm\CustomerTimelineService`**
  ```php
  public function timeline(CustomerProfile $c, ?CarbonInterface $from, ?CarbonInterface $to,
                           array $types = []): LengthAwarePaginator;
  public function summary(CustomerProfile $c): array;  // lifetime value, outstanding, open tickets,
                                                       // last interaction, credit position
  ```
  The timeline is a **paginated union**, not eight eager loads rendered together. A five-year
  customer would otherwise load thousands of rows to show twenty. Each source contributes
  `(occurred_at, type, title, subtitle, link, actor)` through a small mapper, so adding a ninth
  source later is one mapper, not a rewrite.
- `summary()['outstanding']` calls `AccountsReceivableService::customerDetail()` rather than
  recomputing — XC-04's no-disagreeing-rules principle, applied inside a UI service.
- Policy: the timeline is visible to an actor who may view the customer **and** each row is filtered
  by that actor's module permissions, so a support agent sees tickets and visits but not invoices.
  This is enforced in the service, not the Blade view — XC-05's "hiding a control is not
  authorisation".

### API

Read shape: `GET /api/v1/customers/{id}/timeline?from=&to=&types[]=` — and this is the same shape a
customer portal's "my activity" page needs, scoped to the authenticated customer.

### Filament

- `Customers/Pages/ViewCustomer` gains a **Timeline** tab — reverse-chronological, type-filtered,
  each row linking to its document — plus a summary header (lifetime invoiced, collected,
  outstanding with ageing, available credit, open tickets, last interaction).
- Relation managers for the high-frequency sets: `Quotations`, `Orders`, `Invoices`, `Payments`,
  `Tickets`, `Interactions`, `Visits`, `MaintenanceRecords` — each read-only with a link out, since
  editing a document from inside another record's screen is how lifecycle guards get bypassed.
- A `record_interaction` action on the customer (CR-05's entry point).
- `Customers/Tables/CustomersTable` gains outstanding-balance and last-interaction columns with an
  "inactive 90 days" filter.

### Mobile

A field salesperson opening a customer before a visit wants exactly this screen. It is the single
most valuable read in a field app, and the paginated-union shape is what makes it feasible on a
phone.

### Tests

**Feature** — `tests/Feature/Crm/CustomerTimelineTest.php`
- All eight source types appear in one reverse-chronological stream.
- Type filtering and date-range filtering work.
- Pagination does not load the whole history (asserted with a query-count budget, following the
  `PricingTierQueryCountTest` precedent).
- A support-only actor sees tickets and visits and **not** invoices — asserted on the service
  result, not on rendered HTML.
- `summary()['outstanding']` equals `AccountsReceivableService::customerDetail()`'s figure.

**Feature (Filament)** — `tests/Feature/Filament/CustomerTimelineTabTest.php`: the tab renders,
relation managers are read-only, and links resolve.

---

## WP-3.2 — Availability explained to a named cause (GAP-WL-05)

| | |
|---|---|
| **Priority** | **Medium** |
| **Scenario** | IN-18, PM-05 |
| **Depends on** | WP-1.1 (quarantine documents), WP-1.6 (reservation drill-through), WP-3.3 (condition documents) |

### Problem restated

The numbers are all present and correct — `StockLevelsTable` and `StockLevelInfolist` surface
on-hand, saleable, quarantine, damaged, reserved and available, and the report exports the same set.
What is missing is the second half of every clause: **the drill-through**. Reserved quantity is a
number with no path to the reservations holding it; quarantine and damaged have no path to the
documents that produced them. A salesperson who sees 40 on hand and 12 available learns that 28 are
unavailable and cannot find out why or whom to ask. The requirement is not that the number exist —
it does — but that it be attributable.

### Database

None.

### Domain

**New service `App\Services\Inventory\StockAvailabilityExplainer`**
```php
public function explain(ProductVariant $v, Warehouse $w): AvailabilityExplanation;
```
Returning, per IN-18's clause list:
- `onHand`, `available`, and the gap;
- `reserved` **with the reservations** (source document type, number, quantity, expiry, holder);
- `inTransit` **with the transfer operations** carrying it;
- `quarantine` **with the inbound documents** that put it there and any open disposition;
- `damaged` **with the condition-change documents**;
- `expired` **with the lots** and their expiry dates.

Each cause carries a link target and, where applicable, the **action** that would resolve it
(release the reservation, disposition the quarantine), so the screen answers "whom do I ask" with
"you, if you have the permission".

### API

Read shape: `GET /api/v1/inventory/stock/{variant}/{warehouse}/availability` — the exact payload a
salesperson's "can we sell it?" check needs (PM-05).

### Filament

- `StockLevels/Schemas/StockLevelInfolist` gains an **Availability breakdown** section: each cause
  as a row with its quantity, its documents as links, and an inline action where one exists.
- `StockLevels/Tables/StockLevelsTable` — the `available` column becomes clickable, opening the
  breakdown modal. The number that raised the question becomes the path to the answer.
- `Products/Pages/ViewProduct` "can we sell it, and from where?" panel (PM-05) reuses the explainer
  across warehouses.
- `InventoryReportType::StockLevels` export gains the cause columns.

### Mobile

This is the field-sales availability check. Contract recorded.

### Tests

**Feature** — `tests/Feature/Inventory/StockAvailabilityExplainerTest.php`
- The gap between on-hand and available is fully attributed — the sum of the named causes equals the
  gap exactly, with **no residual**. This is the assertion that makes "never an unexplained number"
  testable, and it is the one that will catch a future condition being added without a cause mapper.
- Each cause carries its documents.
- A quarantined quantity with an open disposition shows it.
- A variant with no unavailability returns an empty cause list, not a zero-filled one.

**Feature (Filament)** — `tests/Feature/Filament/StockAvailabilityBreakdownTest.php`: the modal
renders, links resolve, and the release action honours the policy.

---

## WP-3.3 — Damage, recovery and disposal become documents (GAP-UI-06)

| | |
|---|---|
| **Priority** | **Medium** |
| **Scenario** | IN-07, IN-08, IN-09 |
| **Depends on** | WP-1.1 (the document family exists and is proven) |

### Problem restated

`InventoryDamageService::damage/recover/dispose` posts correct movements with before-and-after
conditions, and the movement ledger is a complete record of *what happened*. But the surface is
modal actions on a stock row. There is no condition-change document to open, no list of damage or
disposal events, no attachment collection for disposal evidence, and no reference field linking a
recovery to the damage it reverses. IN-08's "recovery is a new linked document referencing the
original damage" is not representable; IN-09's disposal evidence has nowhere to live, so a write-off
an auditor will question is supported by a movement row and a free-text reason.

### Database

**Migration** — widen the WP-1.1 table rather than create a second one:

```
inventory_condition_changes
  + reverses_condition_change_id  FK inventory_condition_changes nullable nullOnDelete
                                  -- IN-08: recovery references the damage it reverses
  + authorised_by                 FK users nullable nullOnDelete    -- IN-09's authorisation
  + authorised_at                 timestamp nullable
  index (reverses_condition_change_id)
```

A `disposal-evidence` **media collection** (Spatie, as used by invoice confirmation signatures and
customer documents) carries IN-09's attached evidence.

**Backfill.** Historical damage, recovery and disposal exist only as `inventory_movements` rows.
This package **does not** fabricate documents for them. Instead the movement ledger stays the record
for pre-migration events and the new list renders them as read-only "legacy movement, no document"
entries, sourced from the movement table. Manufacturing documents with invented authorisers would
be false evidence in exactly the place an auditor looks.

### Domain

- `InventoryConditionChangeType` gains `Damage`, `DamageRecovery`, `Disposal`.
- `InventoryConditionChangeService` gains `draftDamage()`, `draftRecovery()`, `draftDisposal()`,
  and its `post()` widens its condition assertion from "must be Quarantine" to a per-type matrix —
  the matrix `InventoryDamageService` already holds, now driven by the document type.
- **Recovery requires `reverses_condition_change_id`** naming a posted damage document, and is
  capped at that damage's quantity minus already-recovered. IN-08's link becomes a constraint, not a
  convention.
- **Disposal requires `authorised_by`** distinct from `created_by` (CC-03) and at least one evidence
  attachment. IN-09 calls disposal "a decision the company accepts a loss on"; both requirements are
  what make that decision defensible six months later.
- `InventoryDamageService` is retained as the **posting engine** and is now called only by the
  condition-change service. Its public `damage/recover/dispose` methods become internal to that
  path, and `ArchTest` is extended to assert no other caller.
- `InventoryAlertService` gains a damaged-stock work queue signal (IN-07's "damaged-stock work
  queue").

### API

Command shape: the WP-1.1 data object, widened. Read shape: a condition-change list per variant and
warehouse, feeding WP-3.2's explainer.

### Filament

- `StockLevels/Actions/StockDamageActions` modals are **replaced** by routes into the
  condition-change create page, pre-filled. The action stays where the operator expects it; what
  changes is that it now produces a document.
- `InventoryConditionChanges` list gains type filters, so IN-07's damaged-stock work queue and a
  disposal register both exist as saved views.
- The infolist shows the reversal link both ways (a damage shows its recoveries) and renders the
  disposal evidence.
- `InventoryReportType::ConditionChanges` — damage, recovery and disposal by period, variant,
  warehouse and cause, with export. The shrinkage report the business has never had.
- `InventoryDashboard` — a `DamagedStockQueue` widget.

### Mobile

Photographing damage at the point of discovery is the natural capture. The media collection is
already the mechanism.

### Tests

**Feature** — `tests/Feature/Inventory/ConditionChangeDocumentsTest.php`
- Damage, recovery and disposal each post the same movements the modal actions posted (asserted
  against the existing `StockDamageActions` test's expectations, so the migration is proven
  behaviour-preserving).
- Recovery without a damage reference throws.
- Recovery exceeding the damaged quantity throws; two partial recoveries are capped in aggregate.
- Disposal without evidence throws.
- Disposal authorised by the creator throws.
- Legacy movements render in the list without documents and are not editable.

**Architecture** — `Unit/ArchTest.php`: `InventoryDamageService` has exactly one caller.

**Regression** — the existing damage/recovery/disposal feature tests pass unchanged against the new
path.

---

## WP-3.4 — Registry and directory hygiene (GAP-UI-08)

| | |
|---|---|
| **Priority** | **Low** |
| **Scenario** | none — codebase hygiene |

### Problem restated

`app/Filament/Resources/TaxRecognitionEntries/` contains an empty `Pages/` directory and no resource
class — a leftover of the rename to `Taxes/TaxResource`. `app/Filament/Resources/InventoryExports/`
contains only `Schemas/` with no resource class. No business impact; recorded so a maintainer
reading the tree does not conclude a tax recognition resource exists.

### Database, Domain, API, Mobile

None.

### Filament

- Delete `app/Filament/Resources/TaxRecognitionEntries/`.
- `app/Filament/Resources/InventoryExports/Schemas/` — determine whether the schema is referenced by
  `InventoryReports` (it is, for the export request form); if so **move** it to
  `app/Filament/Resources/InventoryReports/Schemas/` and delete the orphan directory. If not,
  delete it. The check is one grep and is part of the package, not an assumption.

### Tests

**Architecture** — a new `tests/Unit/FilamentResourceDirectoryTest.php`: every directory under
`app/Filament/Resources/` contains exactly one `*Resource.php`. This is what prevents the next
rename from leaving the next orphan, which is the only durable value in this package.

**Regression** — `composer test` unchanged; a deleted directory that something referenced fails
loudly.

---

## WP-3.5 — The physical count document (GAP-MW-06)

| | |
|---|---|
| **Priority** | **Medium** |
| **Scenario** | IN-06 |
| **Flow** | F-16 |
| **Depends on** | WP-1.2 (multi-condition adjustments), WP-1.4 (maker/checker), CC-03 |

### Problem restated

`InventoryAdjustment` is a free-standing list of variant lines carrying a target quantity. There is
no count scope, no count-sheet generation, no counted-versus-system worksheet, and no variance
valuation. Variance is computed per line at confirmation rather than presented as a reviewable
result, and escalation on a materiality threshold does not exist. An operator counting a warehouse
must hand-build the line list from an external spreadsheet — which means **uncounted variants are
silently assumed correct**, the classic way a stock ledger drifts unnoticed.

### Database

```
inventory_counts
  id, count_number string(30) unique
  status string(20)                      -- InventoryCountStatus, cast
  scope_type string(30)                  -- CountScope: Warehouse|Category|VariantSet|Lot
  warehouse_id FK warehouses restrictOnDelete
  product_category_id FK nullable nullOnDelete
  inventory_lot_id FK nullable nullOnDelete
  conditions json                        -- which StockConditions are in scope
  materiality_threshold_minor bigint unsigned nullable
  counted_by FK users nullable nullOnDelete
  confirmed_by FK users nullable nullOnDelete
  confirmed_at timestamp nullable
  inventory_adjustment_id FK inventory_adjustments nullable nullOnDelete  -- the correction it produced
  opened_at, closed_at timestamp nullable
  created_by FK users restrictOnDelete
  timestamps, softDeletes

inventory_count_lines
  id, inventory_count_id FK cascadeOnDelete
  product_variant_id FK restrictOnDelete
  inventory_lot_id FK nullable nullOnDelete
  serialized_inventory_unit_id FK nullable nullOnDelete
  stock_condition string(20)
  system_base_quantity decimal(18,6)     -- snapshot at sheet generation
  counted_base_quantity decimal(18,6) nullable   -- null = not yet counted, NOT zero
  variance_base_quantity decimal(18,6) nullable  -- derived, stored for the worksheet
  variance_value_minor bigint nullable
  recount_requested boolean default false
  note string(255) nullable
  timestamps
  unique (inventory_count_id, product_variant_id, inventory_lot_id, serialized_inventory_unit_id, stock_condition)
  index (inventory_count_id, recount_requested)
```

**`counted_base_quantity` is nullable and that is the point.** The gap's core failure is that
uncounted variants are silently assumed correct. A null counted quantity is *uncounted*; a zero is
*counted as zero*. Conflating them is the defect, so the schema refuses to.

### Domain

**Enums:** `InventoryCountStatus` (`Draft → Counting → PendingReview → Confirmed | Cancelled`),
`CountScope`.

**New service `App\Services\Inventory\InventoryCountService`**
- `open(CountScopeData, User)` — generates count lines by snapshotting the current position for the
  scope **at every grain that can diverge**: variant × lot × serial × condition. This is what
  guarantees an uncounted grain is visible rather than assumed.
- `recordCount(InventoryCountLine, string $quantity, User)` — variance is derived, never entered.
- `submitForReview(InventoryCount, User)` — refuses while any line is uncounted unless the operator
  explicitly marks the count partial, which is recorded on the document.
- `requestRecount(InventoryCountLine, User, string $reason)` — IN-06's materiality escalation:
  lines whose variance value exceeds `materiality_threshold_minor` are auto-flagged
  `recount_requested` at submission and block confirmation until re-counted or explicitly accepted
  with a reason.
- `confirm(InventoryCount, User)` — **maker ≠ checker (CC-03)**, then creates a single
  `InventoryAdjustment` carrying one item per non-zero variance **with its condition** (WP-1.2) and
  confirms it through `InventoryAdjustmentService`. The count does not post stock itself; the
  adjustment remains the canonical correction path, so IN-06's evidence and the existing ledger
  guarantees are both preserved.
- `variance(InventoryCount): array` — the reviewable worksheet: per grain, system, counted,
  variance, and value at last-received cost with an explicit "unvalued" marker where no cost exists.

**Policy / permissions:** `inventory.count.view|open|record|confirm`.

### API

A barcode-scanning count app is the highest-value inventory mobile feature; `recordCount` is
deliberately per-line so it can be called once per scan. Command and read shapes recorded.

### Filament

- `InventoryCounts/` resource with the standard layout.
- `Actions/InventoryCountActions` — `open` (scope wizard showing the line count before committing),
  `download_count_sheet` (CSV/PDF for a clipboard count), `upload_counts` (CSV re-import,
  validating against the generated lines), `submit`, `request_recount`, `confirm`.
- `RelationManagers/InventoryCountLines` — inline counted-quantity entry, variance columns, an
  **"uncounted" filter that is prominent by default**, and a recount flag.
- `Schemas/InventoryCountInfolist` — the variance worksheet with totals and value, and the flagged
  materiality exceptions listed first.
- `InventoryReportType::CountVariance` — variance history by period and warehouse.

### Mobile

Scan-to-count. Contract recorded.

### Tests

**Unit** — `tests/Unit/Enums/InventoryCountStatusTest.php`.

**Feature** — `tests/Feature/Inventory/InventoryCountTest.php`
- Opening a warehouse-scope count generates one line per variant × lot × serial × condition in
  scope, and **none** outside it.
- An uncounted line is distinguishable from a zero-counted one, and submission refuses while any
  line is uncounted unless marked partial.
- Variance is derived and cannot be hand-entered.
- A variance above the materiality threshold auto-flags a recount and blocks confirmation.
- The counter confirming their own count throws (CC-03).
- Confirmation creates exactly one adjustment carrying the correct condition per line, and the
  resulting balances match the counted quantities at every grain.
- Cancelling a count posts nothing.

**Integration** — `tests/Feature/Inventory/PhysicalCountFlowTest.php` (F-16): stock across three
conditions and two lots is counted, one grain diverges, the count confirms, and
`InventoryLotReconciliationService::inspect()` passes afterwards.

---

## WP-3.6 — Preventive maintenance programme (GAP-MW-08)

| | |
|---|---|
| **Priority** | **Medium** |
| **Scenario** | MT-07, MT-08 |
| **Depends on** | WP-2.9 (job cost, so a preventive service has a cost), WP-2.10 (due reminders) |

### Problem restated

`MaintenanceTask` is a checklist under a maintenance record, not a schedule. There is no recurrence
field, no interval, no next-due date, no generation command, and no scheduler entry. All maintenance
is reactive. Preventive service revenue and the contractual obligations behind it are managed
outside the system, and a missed service is invisible until a customer complains — which is also the
moment it becomes a warranty argument the company cannot evidence.

### Database

```
maintenance_schedules
  id, schedule_number string(30) unique
  serialized_inventory_unit_id FK restrictOnDelete   -- the equipment
  customer_id FK customer_profiles nullable nullOnDelete
  name string(255)
  interval_type string(20)          -- MaintenanceIntervalType: Days|Weeks|Months|Years|UsageHours
  interval_value unsigned int
  lead_time_days unsigned int default 7   -- MT-07: "raises due requests in advance"
  first_due_on date
  next_due_on date                        -- maintained by the service
  last_completed_on date nullable
  is_active boolean default true
  billing_type string(30)                 -- MaintenanceBillingType (WP-2.9): warranty vs chargeable
  checklist json nullable                 -- the task template
  created_by FK users restrictOnDelete
  timestamps, softDeletes
  index (is_active, next_due_on)          -- the generation command's covering index
  index (serialized_inventory_unit_id)

maintenance_schedule_occurrences         -- MT-07: "a missed preventive service is visible as missed"
  id, maintenance_schedule_id FK cascadeOnDelete
  due_on date
  status string(20)                      -- OccurrenceStatus: Pending|Raised|Completed|Missed|Skipped
  maintenance_record_id FK nullable nullOnDelete
  raised_at, completed_at timestamp nullable
  skipped_reason string(255) nullable
  timestamps
  unique (maintenance_schedule_id, due_on)   -- one occurrence per due date, so a re-run cannot duplicate
  index (status, due_on)
```

**The occurrence table is the design decision.** A `next_due_on` column alone can only answer "when
is the next one" — it cannot answer "was the one in March done", which is precisely what MT-07 and a
warranty argument require. Occurrences make a missed service a **row**, not an absence.

### Domain

**Enums:** `MaintenanceIntervalType`, `OccurrenceStatus`.

**Services**
- `MaintenanceScheduleService::create/update/deactivate` — creating generates occurrences forward
  for a bounded horizon (12 months or 12 occurrences, whichever is shorter), so the calendar is
  visible without generating infinitely.
- `MaintenanceScheduleGenerator::raiseDue(): int` — invoked by the command; for every occurrence
  due within `lead_time_days` and still `Pending`, creates a `MaintenanceRecord` via the **existing**
  `MaintenanceRecordService::createStandalone()` (no second creation path), links it, sets `Raised`,
  and raises `MaintenanceDue` for WP-2.10.
- `MaintenanceScheduleGenerator::markMissed(): int` — occurrences past due with no completed record
  become `Missed`. This is the method that makes the requirement true; without it "missed" is
  indistinguishable from "not yet raised".
- Completion of a linked maintenance record sets the occurrence `Completed`, updates
  `last_completed_on`, and extends the occurrence horizon.
- `skip(Occurrence, User, string $reason)` — a recorded decision, not a deletion.

**Command** `maintenance:schedules:generate` — daily, calling both generator methods, returning a
non-zero exit code on any failure.

**Permissions:** `SupportPermission::MaintenanceScheduleView|Manage`.

### API

Read shape: a customer portal's "your next service is due on…" and a technician app's route list for
the day. Contract recorded.

### Filament

- `MaintenanceSchedules/` resource with the standard layout, plus a `Occurrences` relation manager.
- `SerializedInventoryUnits` device view gains a **Service history** section — schedule, occurrences
  (including missed ones), records, parts fitted, costs. This completes MT-08's "the equipment's
  whole life is answerable from one place", which WP-2.9 started.
- `Actions/MaintenanceScheduleActions` — `raise_now`, `skip_occurrence` (reason required),
  `deactivate`.
- `SupportReportType::PreventiveCompliance` — due, raised, completed and **missed** counts by
  customer, equipment and period, with export. The report the contractual obligation is managed by.
- `SupportDashboard` — `MaintenanceDueSoon` and `MaintenanceMissed` widgets, the second in danger
  colour.

### Mobile

A technician's daily route is a list of raised occurrences; completion in the field closes the loop
and captures labour (WP-2.9). Contract recorded.

### Tests

**Unit** — `tests/Unit/Enums/MaintenanceIntervalTypeTest.php`: next-due arithmetic for each interval
type, including month-end edge cases (31 January + 1 month).

**Feature** — `tests/Feature/Support/MaintenanceScheduleTest.php`
- Creating a schedule generates a bounded set of occurrences.
- The generator raises only occurrences inside the lead time and is **idempotent** across two runs
  in the same day (the unique index plus a status check).
- A past-due, unraised occurrence becomes `Missed` and appears in the compliance report.
- Completing a record completes the occurrence, updates `last_completed_on`, and extends the
  horizon.
- Skipping records the reason and does not create a record.
- Deactivating stops generation without deleting history.
- A schedule for a disposed serialised unit stops generating.

**Feature (scheduler)** — extend `tests/Feature/ScheduledCommandsTest.php`.

**Integration** — `tests/Feature/Support/PreventiveMaintenanceFlowTest.php` (MT-07 → MT-08): a
schedule raises a request, parts and labour are recorded, the job is warranty-covered, the device
history answers a warranty claim from the schedule, the occurrence and the parts fitted.

---

## WP-3.7 — Resolve the placeholder navigation entries (GAP-UI-07)

| | |
|---|---|
| **Priority** | **Medium** |
| **Scenario** | MD-07, MD-08, XC-04 |
| **Depends on** | WP-2.7 (which already re-pointed the tax entry) |

### Problem restated

`AdminModuleRegistry` declares navigation entries pointing at four classes that do not exist:
`TaxDefinitionResource`, `DocumentTemplateResource`, `OperationalReportResource` and
`Pages\Settings`. `resolveLink()` guards with `class_exists()` and falls through to
`ModulePlaceholder`, so this is a deliberate "declared, not built" pattern that
`Unit/AdminModuleRegistryTest.php` pins — but the `use` statements for non-existent classes make the
intent ambiguous to the next maintainer, and a user navigating to Tax Definitions reaches a
placeholder.

WP-2.7 resolved the tax entry. This package resolves the remaining three, and each gets a decision
rather than a build-by-default.

### Decisions

| Entry | Decision | Rationale |
|---|---|---|
| `DocumentTemplateResource` | **Build**, as a thin resource over `notification_templates` (WP-2.10) extended with document-layout templates for invoice and credit-note PDFs | The templates now exist; a screen over them is small, and MD-08 wants document configuration owned by a user rather than a developer |
| `OperationalReportResource` | **Remove the entry and its import.** Replace the navigation item with a group linking to the six per-module report pages | After WP-2.4, WP-2.7, WP-2.8 and the existing four, every module has its own report surface. A seventh, cross-module report resource would duplicate them and violate XC-04's no-disagreeing-rules principle |
| `Pages\Settings` | **Build**, as a settings hub linking the existing settings pages (`SalesSettings`, `PurchaseSettings`, `InventorySettings`, notification defaults, the new document templates) | The pages exist and are scattered; the hub is navigation, not new behaviour |

Deleting an entry is a legitimate outcome and is recorded as such — the alternative is building a
screen because a `use` statement implied one.

### Database / Domain / API / Mobile

None beyond what WP-2.10 already delivered.

### Filament

- `DocumentTemplates/` resource — locale tabs, a preview action, and a `restore default` action so a
  broken edit is recoverable.
- `Pages/Settings` — a hub page with permission-filtered cards.
- `AdminModuleRegistry` — remove the `OperationalReportResource` import and entry; point the other
  two at the new classes; remove all remaining imports of non-existent classes.

### Tests

**Unit** — `tests/Unit/AdminModuleRegistryTest.php` is **inverted**: it currently pins that these
entries fall through to `ModulePlaceholder`. It is rewritten to assert that **no** registry entry
resolves to `ModulePlaceholder`, and that every declared class exists. The placeholder mechanism
stays in the codebase for future use; what changes is that nothing currently uses it.

**Feature (Filament)** — `tests/Feature/Filament/DocumentTemplateResourceTest.php` and
`SettingsHubTest.php`: rendering, preview, restore-default, permission filtering.

---

# Phase 4 — Optimization, hardening, and deferred-scope activation

**Theme:** two distinct kinds of work, kept visibly separate.

**4A (WP-4.1 – WP-4.4)** is optimization and hardening of what Phases 1–3 built. It is
engineering work with no new business decision behind it.

**4B (WP-4.5 – WP-4.8)** is the four gaps deferred by an ADR. **These are not engineering
decisions.** Each is specified here at the same depth as everything else so the owner can price the
decision rather than re-open a discovery. The question to answer for each is not "how" but "is the
recorded business consequence still acceptable". They are placed in Phase 4 because that is where
they sit if the answer stays *yes*; any of them can be pulled into an earlier phase the day the
answer changes, and WP-4.6 in particular has an argument for that.

---

## WP-4.1 — Performance of the new report and reconciliation surfaces

**Trigger:** Phases 2 and 3 add roughly twenty report queries, a full-ledger reconciliation on a
daily schedule, and a paginated timeline union. None has been measured against production-scale
data, and several are called from inside the period-close gate, where slowness becomes a blocker.

### Work

- **Measure first.** A seeder producing a realistic three-year dataset (≈50k invoices, ≈500k
  movements, ≈5k customers) plus a benchmark test asserting a wall-clock budget per report. The
  budget is the deliverable; the optimisation is whatever meets it.
- **Query-count budgets** on the timeline, the availability explainer and every report page,
  following the `PricingTierQueryCountTest` precedent already in the suite.
- **Index review** of every index this plan added, against actual query plans rather than intent.
- `InventoryLotReconciliationService` — chunked replay and, if the budget demands it, an incremental
  mode that replays only movements since the last passing run, with a weekly full replay retained
  as the backstop.
- Materialised daily snapshots for the AR ageing and tax register **only if measurement shows they
  are needed** — and if so, derived-and-cached with the live derivation retained as the source of
  truth, because AC-05's "derived, never stored" is a correctness rule, not a performance rule.

### Tests

`tests/Feature/Performance/ReportBudgetTest.php` — wall-clock and query-count budgets per report,
skipped unless the large dataset is seeded so CI stays fast.

---

## WP-4.2 — Schema deprecation cleanup

**Work:** remove `invoices.inventory_operation_id` once WP-2.13's join table has been in production
for one release and no reader remains (verified by grep and by a logged deprecation shim, not by
assumption). Remove the `AdjustmentStatus`-adjacent dead code paths superseded by WP-3.5. Drop the
`ModulePlaceholder` fall-through from `AdminModuleRegistry` if WP-3.7's inverted test has held.

Each removal is its own commit with its own test run, because a deprecation removal that breaks
something breaks it silently.

---

## WP-4.3 — Asynchronous exports with retained parameters

**Trigger:** XC-04 requires that "long exports run asynchronously and are retained with their
parameters so a figure can be reproduced." `InventoryExport` already does this for inventory; WP-2.8
generalised the concern to sales documents. This package completes the pattern across every report
surface added by this plan and moves any synchronous export above a row threshold onto the queue.

**Work:** a single `DocumentExport` model and queued job serving every module, with the requester,
parameters, row count, generated file and expiry retained; a download page listing the requester's
exports; scheduled cleanup of expired files.

**Tests:** an export requested, queued, generated and downloaded; parameters reproduce the same
figures on a re-run; another user cannot download it.

---

## WP-4.4 — Notification volume management

**Trigger:** XC-03's "notification volume is managed so alerts stay meaningful." Phase 2 delivers the
engine and five scheduled reminders; at scale these become noise, and a noisy alerting layer is
functionally the same as no alerting layer.

**Work:** per-user digest preferences (immediate / daily / weekly) with a digest builder; per-template
rate limits; a quiet-hours window; a `notifications:digest` command; and a delivery-volume report so
the noise is measurable rather than anecdotal.

**Tests:** digest batching, rate-limit suppression recorded as a decision (not a silent drop), quiet
hours deferring rather than dropping.

---

## WP-4.5 — API and channel foundation (GAP-MW-19) — **deferred by ADR 0003 / ADR 0008**

| | |
|---|---|
| **Business consequence if left as is** | All customer self-service and all field capture is re-keyed by office staff. **GPS check-in data (EM-04) cannot be captured at all**, so the work-time adherence factor that drives salary (EM-09, EM-10) is fed by office-entered timestamps. F-19 and F-20 do not run |
| **Scenario** | SL-13, SL-14, EM-03, EM-04, EM-05, SU-01, PM-09, CR-01 |

**Why this is cheaper than it looks, and why that matters to the decision.** The domain is genuinely
channel-ready and was built that way deliberately: `QuotationService::recordDecision` preserves both
the decider and the recorder; `ShipmentService::confirmByCustomer()` exists;
`ShipmentConfirmationSource` already distinguishes `Customer | AdminUser | System`; visit editing was
deliberately removed so field evidence stays field evidence. Every one of those is currently invoked
by an admin recording someone else's action. Every work package in this plan added a typed command
shape for the same reason. The remaining work is transport, authentication and authorisation — not
domain.

### Scope

- `routes/api.php`, Laravel Sanctum, versioned under `/api/v1`.
- `App\Http\Resources\` — `JsonResource` per read shape already named in this plan.
- `App\Http\Requests\Api\` — form requests mapping to the existing data objects. **No controller
  calls a model directly**; every write goes through the service that Filament calls, so the two
  channels cannot diverge. This is the single most important constraint in the package and is
  enforced by an `ArchTest` rule.
- Policies are reused unchanged — XC-05 requires role boundaries to hold identically across every
  path, and reusing the policy is the only way to guarantee it rather than assert it.
- **Customer surface:** catalogue at resolved prices, quotation request, order tracking, document
  download, ticket creation, statement.
- **Employee surface:** plan and tasks, visit check-in/out **with GPS**, voice note upload, lead and
  interaction capture, opportunity creation, van-warehouse sale.
- `dedoc/scramble` finally documents something real; `Docs/api/API_CONTRACT.md` is rewritten from
  the generated spec rather than maintained by hand.

### Tests

A contract test per endpoint; an authorisation matrix test asserting a customer reaches only their
own records and an employee only their own work; and — the important one —
`tests/Feature/Api/ChannelParityTest.php`, asserting that the same business action through the API
and through Filament produces **byte-identical** domain records. That test is what makes SL-13's
"a customer's decision made in-app and one recorded by staff must produce the same business record"
true rather than intended.

---

## WP-4.6 — Inventory valuation and cost of sales (GAP-MW-15) — **deferred by ADR 0007 §11 / ADR 0008**

| | |
|---|---|
| **Business consequence if left as is** | **The P&L reports revenue with no cost of sales.** Gross margin is not derivable at any grain — per product, per customer, per job. The balance sheet omits inventory as an asset while the warehouse holds it. Disposals and damage write-offs produce a stock movement and no expense, so shrinkage never reaches the P&L |
| **Scenario** | AC-14, MT-05, SL-15 |

**This is the single largest divergence between what the ledger says and what the business is**, and
it is the one Band 5 item with a live argument for pulling forward. Every other deferred gap costs
the business a capability; this one costs it the truth of its own financial statements. WP-2.9
delivers job-level margin from cost snapshots, which narrows the operational pain but does not touch
the ledger.

### Scope sketch

- A stated valuation basis — **weighted average** is the recommendation, because
  `SupplierCostWritebackService` already maintains a last-received unit cost and moving-average is
  the smallest honest step from it; FIFO would require lot-level cost layers the schema does not
  carry.
- `inventory_cost_layers` (or an average-cost column per variant × warehouse), maintained by
  `InventoryPostingService` on every receipt.
- Receipt posts inventory asset (Dr inventory, Cr GRNI); bill approval clears GRNI.
- Delivery completion posts COGS (Dr COGS, Cr inventory) — **which changes the invariant that
  §0.5.3 protects and that `NoAutomaticPostingTest` guards.** That test would be deliberately
  rewritten by this package, and that rewrite is the clearest possible signal that this is a scope
  decision and not a defect fix.
- Damage, disposal and count variance post to a shrinkage expense account.
- A valued stock position reconciling to the inventory control account, and a new
  `PeriodCloseCheck::InventoryAgreesToControlAccount` added to WP-2.5's checklist.

### Prerequisite

WP-2.5 must exist first, because introducing inventory postings without a reconciliation gate would
add a new way for the ledger to be wrong with no signal that it is.

---

## WP-4.7 — Ticket revenue through the ledger (GAP-MW-11) — **deferred by ADR 0008 / spec 016 D4**

| | |
|---|---|
| **Business consequence if left as is** | Cash is collected from a customer and marked settled while the general ledger, the receivables subledger and the **tax register** never see it. **Tax is charged and collected without being recognised as payable — a filing exposure, not a reporting gap.** The amount is invisible to AC-06 and to the AC-10 close |

`TicketPaymentService` writes `ticket_payment_links` and `tickets` and nothing else; its own docblock
states that no accounting, journal or tax side effect exists anywhere in the class.

**Scope sketch:** ticket settlement creates a standard `Invoice` (or allocates to one) through
`InvoiceService`, then follows the standard collection and proportional tax path — the same
"no second revenue path" rule WP-2.9 applies to maintenance billing, applied to tickets. WP-2.9's
`MaintenanceBillingService` is the template, which makes this the cheapest of the four deferred
items to activate.

**Note on sequencing:** this is a **tax compliance** exposure, not merely a reporting gap. If the
business collects ticket settlements at any material volume, this belongs in Phase 2 next to WP-2.9,
not in Phase 4. The volume figure is the deciding input and the business owns it.

---

## WP-4.8 — Supplier debit notes (GAP-MW-14) — **deferred by ADR 0006 §11**

| | |
|---|---|
| **Business consequence if left as is** | Stock leaves the building and the payable stays at its full value. The supplier's bill is paid in full for goods that were returned, or the recovery is tracked in email. F-08 completes its inventory half and stops at the Purchasing → Accounting seam |

The physical leg is built and well guarded — `InventoryReturnService::createSupplierReturn()` caps
the return against the referenced receipt line. The commercial leg does not exist: no debit note, no
supplier credit document, no expected-outcome field.

**Scope sketch:** an `expected_outcome` enum on the supplier return (`Replacement | Credit |
Refund`); a `SupplierDebitNote` document mirroring `CreditNote` in shape and lifecycle, linked to the
return at line grain exactly as WP-1.3 links customer returns to credit notes; posting that reduces
the payable and reverses input tax proportionally; and an exception report for supplier returns
awaiting a credit that never arrived.

WP-1.3 is the direct template — the same problem, the same shape, the other direction — which makes
this the second-cheapest deferred item once WP-1.3 has shipped.

---

# 5. Testing strategy

Per-package tests are specified above. This section states the rules that hold across all of them,
so a reviewer can check a package against a standard rather than an opinion.

## 5.1 The three levels, and what each is for

| Level | Location | Answers | Rule |
|---|---|---|---|
| **Unit** | `tests/Unit/` | "Does this enum, calculator or concern behave?" | No database, no container where avoidable. Every new enum with `canTransitionTo()` gets an **exhaustive** matrix test — legal *and* illegal directions — not a sample |
| **Feature** | `tests/Feature/<Module>/` | "Does this service enforce its rule?" | `RefreshDatabase`, factories with states, the service called directly. Every guard clause in a package has a test that trips it **and** asserts the side effect did not happen |
| **Feature (Filament)** | `tests/Feature/Filament/` | "Can a user reach it, and is it authorised?" | Livewire component tests. Every action asserts both the permitted and the refused path |
| **Integration** | `tests/Feature/<Module>/*FlowTest.php` | "Does the seam hold end to end?" | Named after the business flow (F-01…F-21), exercising several modules through their real services |

## 5.2 Assertion rules this plan adopts

1. **A refusal test asserts the absence of the side effect, not just the exception.** Asserting
   `expect(fn () => …)->toThrow(…)` proves the throw; it does not prove nothing was written. Every
   refusal test in this plan additionally asserts movement count, balance, or row count unchanged.
   This is the rule that would have caught the GAP-BW-05 blank-reference escape.
2. **A cross-service figure is asserted against the other service, not against a literal.** Where
   two surfaces report the same business figure — `SalesReportType::InvoicedNotCollected` and
   `AccountsReceivableService::aging()`, `TaxRegisterService` and the close checklist — the test
   compares them to each other. XC-04 forbids two rules for one figure; a shared literal in two
   tests would let them drift while both stayed green.
3. **A ledger-touching test asserts the invariants, not just the balance.** After any inventory
   package's behaviour, `InventoryLotReconciliationService::inspect()` must report zero divergence.
   This is cheap and is the difference between "the number is right" and "the ledger is right".
4. **A migration that moves data has a test.** Seed the pre-migration shape, run the migration,
   assert the post-migration shape and the reported exceptions. Four packages move data
   (WP-1.5, WP-1.8, WP-1.10, WP-2.13); each has a named backfill test.
5. **A scheduled command has a scheduler test.** `tests/Feature/ScheduledCommandsTest.php` asserts
   the full expected set of scheduled commands and their cadence. This one file is the direct
   countermeasure to GAP-MW-04 and GAP-BW-04, which are both "the code was written and never
   scheduled".
6. **A permission is tested from both sides.** Holding it succeeds; not holding it is refused at the
   service, not only hidden in the UI (XC-05). The existing five `CrossModulePermissionLeakTest`
   files are extended for every new permission this plan adds.
7. **A regression test is named for what it protects, not for what broke.** `NoAutomaticPostingTest`
   is the model: it does not describe a bug, it pins an invariant.

## 5.3 Invariant tests that must not regress

These already exist and are the load-bearing tests of the codebase. Every package states whether it
touches them; only two do, and both deliberately.

| Test | Protects | Touched by |
|---|---|---|
| `Unit/ArchTest.php` | Layering; sole-writer rules | **Extended** by WP-2.1, WP-2.10, WP-3.3, WP-4.5 (rules added, none removed) |
| `Feature/InventoryDomainContractTest.php` | `InventoryBalanceService` is the only stock writer | Untouched |
| `Feature/Accounting/NoAutomaticPostingTest.php` | The named posting caller set | **Extended** by WP-1.9 (a seventh caller, plus an exact-count assertion). **Rewritten** by WP-4.6 — and that rewrite is the signal that WP-4.6 is a scope decision |
| `Feature/Sales/QuotationTouchesNoStockTest.php` | Quotations move no stock | Untouched |
| `Unit/AdminModuleRegistryTest.php` | Navigation resolves | **Inverted** by WP-3.7 (from "placeholders are expected" to "no placeholders remain") |
| `Feature/Filament/CoverageSurfaceTest.php`, `Unit/Coverage/PrimitiveCoverageTest.php` | Coverage floors | Thresholds may rise, never fall (§0.5.6) |
| `Feature/PricingTierQueryCountTest.php` | Price resolution query budget | Must not regress — WP-2.3 returns data already computed rather than re-querying, specifically to hold this |
| `Feature/Employees/VisitEditRemovedTest.php` | Field evidence is not editable | Untouched, including by WP-4.5 |

## 5.4 Coverage expectations

Per `.ai/feature-development` rule 8, thresholds may not be lowered to make a build pass. Each
package is expected to raise line coverage in the files it touches, and `composer test:coverage`
(Xdebug is configured locally) is run before opening a pull request rather than discovered in CI.

## 5.5 What is deliberately not tested

- **Filament rendering detail.** Component tests assert behaviour and authorisation, not markup.
- **Framework behaviour.** Casts, relations and queue mechanics are Laravel's tests, not ours.
- **The performance budgets in WP-4.1** are skipped unless the large dataset is seeded, so CI stays
  fast. They are a gate for a release, not for a commit.

---

# 6. Risk register

| # | Risk | Where | Likelihood | Impact | Mitigation |
|---|---|---|---|---|---|
| R-01 | The WP-1.8 status cast surfaces a status value no enum covers, in production data | WP-1.8 | Medium | Hydration errors on a money document | The migration **reports before it casts**; deployment is gated on a clean report. The cast is the last statement, not the first |
| R-02 | The WP-1.5 unique index cannot be applied because production holds real duplicate supplier references | WP-1.5 | Medium | Migration fails mid-deploy | The migration throws with the offending bill numbers **before** altering anything; the runbook resolves them as a data task first |
| R-03 | WP-1.3's cap tightens crediting and blocks a legitimate in-flight credit note | WP-1.3 | Low | An operator is stuck | The cap applies only where a return link is supplied; unlinked credit notes behave exactly as today, and the historical set is reported rather than retro-capped |
| R-04 | The WP-2.5 close gate blocks a real month-end close on a pre-existing divergence | WP-2.5 | **High** | The business cannot close | This is the intended behaviour, so the mitigation is sequencing, not softening: run the checklist in report-only mode for one full period **before** the gate is enforced, so the exceptions are known and worked. The override exists for the residual case |
| R-05 | WP-2.1 (CRM) and WP-2.10 (notifications) are large enough to slip and block Phase 2 | WP-2.1, WP-2.10 | Medium | Phase 2 stalls | Both run as parallel tracks from day one with no Phase 1 dependency; nothing in Phase 2 except the funnel report and the campaign send depends on them |
| R-06 | The condition-change family (AD-01) is introduced for quarantine and later proves the wrong shape for damage | WP-1.1 → WP-3.3 | Low | Rework in Phase 3 | The shape is derived from `InventoryDamageService`'s existing, working matrix rather than invented; WP-3.3 widens a `match`, and its regression tests assert byte-identical movements |
| R-07 | WP-2.13's join-table migration mis-backfills and an invoice loses its delivery link | WP-2.13 | Low | Broken traceability | The backfill asserts a row count equal to the pre-migration linked-invoice count inside the same migration, and the old column is retained until WP-4.2 |
| R-08 | Notification volume makes the engine counterproductive before WP-4.4 lands | WP-2.10 | Medium | Alert fatigue; users disable everything | The per-threshold uniqueness check ships **in WP-2.10**, not deferred to WP-4.4; the reminder set starts at five templates, not the full ten |
| R-09 | Report queries added in Phase 2 are slow at production scale and make the close gate painful | WP-2.4…2.8 | Medium | Close takes hours | WP-4.1 measures; but each report is written against indexed columns from the start and the close checklist reads **persisted** reconciliation runs rather than recomputing |
| R-10 | CC-03's maker/checker extraction changes a message or an exception type and breaks refund or PO approval | CC-03 | Low | Regression in a working control | The concern supplies the check only; both call sites keep their exception types and messages, and their existing tests run unchanged as the acceptance criterion |
| R-11 | WP-4.6 (COGS) is deferred indefinitely and the P&L stays incomplete | WP-4.6 | **High** | Financial statements misstate margin permanently | Not an engineering mitigation. The consequence is recorded in §4B and in `BUSINESS_LOGIC_GAPS.md` so the deferral stays a visible decision rather than an assumption |
| R-12 | Phase 1 lands ten packages touching six modules and a regression escapes | Phase 1 | Medium | Production defect in money or stock | One logical change per pull request (rule 3); `composer test` on every one; the four data-moving migrations are separated into their own releases |

---

# 7. Traceability

## 7.1 Gap → work package → phase

| Gap | Priority | Work package | Phase |
|---|---|---|---|
| GAP-MW-01 CRM leads/campaigns | Critical | WP-2.1 | 2 (parallel track from P1) |
| GAP-MW-02 Opportunity first-class | High | WP-2.2 | 2 |
| GAP-MW-03 Quarantine exit | **Critical** | WP-1.1 | **1** |
| GAP-MW-04 Reservation expiry | High | WP-1.6 | 1 |
| GAP-MW-05 Reservation release | High | WP-1.6 | 1 |
| GAP-MW-06 Physical count document | Medium | WP-3.5 | 3 |
| GAP-MW-07 Bad-debt write-off | High | WP-1.9 | 1 |
| GAP-MW-08 Preventive maintenance | Medium | WP-3.6 | 3 |
| GAP-MW-09 Maintenance job cost | High | WP-2.9 | 2 |
| GAP-MW-10 Bill chargeable service | High | WP-2.9 | 2 |
| GAP-MW-11 Ticket revenue *(ADR)* | High | WP-4.7 | 4B |
| GAP-MW-12 Notification engine | High | WP-2.10 | 2 (parallel track) |
| GAP-MW-13 Consolidated invoicing | Medium | WP-2.13 | 2 |
| GAP-MW-14 Supplier debit note *(ADR)* | Medium | WP-4.8 | 4B |
| GAP-MW-15 Valuation and COGS *(ADR)* | High | WP-4.6 | 4B |
| GAP-MW-16 Reconciliation report | High | WP-1.7 + WP-2.4 | 1 + 2 |
| GAP-MW-17 Sales reporting | High | WP-2.8 | 2 |
| GAP-MW-18 Period-close gate | High | WP-2.5 | 2 |
| GAP-MW-19 Customer/employee channels *(ADR)* | High | WP-4.5 | 4B |
| GAP-BW-01 Return ↔ credit note | **Critical** | WP-1.3 | **1** |
| GAP-BW-02 Correction types | High | WP-2.11 | 2 |
| GAP-BW-03 Price provenance | High | WP-2.3 | 2 |
| GAP-BW-04 Reconcile not scheduled | High | WP-1.7 | 1 |
| GAP-BW-05 Duplicate bill control | High | WP-1.5 | 1 |
| GAP-BW-06 Operation audit | Medium | WP-2.12 | 2 |
| GAP-BW-07 Quotation expiry sweep | Medium | WP-2.14 | 2 |
| GAP-BW-08 Transcription cascade | Medium | WP-1.10 | 1 |
| GAP-WL-01 Untyped statuses | High | WP-1.8 | 1 |
| GAP-WL-02 Adjustment maker/checker | High | WP-1.4 | 1 |
| GAP-WL-03 Saleable-only adjustments | High | WP-1.2 | 1 |
| GAP-WL-04 Confirmation overwrites status | Medium | WP-1.8 | 1 |
| GAP-WL-05 Availability not attributable | Medium | WP-3.2 | 3 |
| GAP-UI-01 Reservations no actions | High | WP-1.6 | 1 |
| GAP-UI-02 AR subledger | High | WP-2.6 | 2 |
| GAP-UI-03 Customer 360 | High | WP-3.1 | 3 |
| GAP-UI-04 Tax register proof | High | WP-2.7 | 2 |
| GAP-UI-05 Sales exports | Medium | WP-2.8 | 2 |
| GAP-UI-06 Condition-change documents | Medium | WP-3.3 | 3 |
| GAP-UI-07 Placeholder navigation | Medium | WP-2.7 (tax) + WP-3.7 | 2 + 3 |
| GAP-UI-08 Dead directories | Low | WP-3.4 | 3 |

**40 gaps → 35 gap-closing work packages** (WP-1.1–1.10, WP-2.1–2.14, WP-3.1–3.7, WP-4.5–4.8),
plus **4 optimization and hardening packages** (WP-4.1–4.4) that close no gap of their own — 39 in
total. Every gap is assigned; four (WP-4.5–4.8) remain owner decisions rather than engineering ones.

## 7.2 Scenario coverage added by this plan

| Scenario group | Newly satisfied by |
|---|---|
| **CR-01…CR-07** (lead, interaction, campaign, funnel) | WP-2.1, WP-2.2 |
| **CR-08** (explain a price) | WP-2.3 |
| **IN-06** (count with SoD and materiality) | WP-1.2, WP-1.4, WP-3.5 |
| **IN-07/08/09** (condition documents, disposal evidence) | WP-3.3 |
| **IN-14** (quarantine disposition) | WP-1.1 |
| **IN-16** (correct a posted document) | WP-2.11 |
| **IN-17** (prove the ledger) | WP-1.7, WP-2.4 |
| **IN-18** (why can't I sell it) | WP-3.2 |
| **SL-06 alt** (consolidated invoicing) | WP-2.13 |
| **SL-02/SL-16** (expiry and re-quote) | WP-2.14 |
| **SL-07** (chase a receivable) | WP-2.6, WP-2.10 |
| **SL-10** (receipt evidence ≠ lifecycle) | WP-1.8 |
| **SL-15** (report the sales engine) | WP-2.8 |
| **AC-05** (receivables that prove themselves) | WP-2.6 |
| **AC-06** (tax register) | WP-2.7 |
| **AC-10** (close safely) | WP-2.5 |
| **AC-13** (write off) | WP-1.9 |
| **MT-05/MT-06** (cost and bill service work) | WP-2.9 |
| **MT-07/MT-08** (preventive programme, warranty evidence) | WP-3.6 |
| **XC-01** (numbering) | WP-1.1, WP-1.9, WP-3.5, WP-3.6 |
| **XC-02** (audit every sensitive action) | WP-1.10, WP-2.12 |
| **XC-03** (notify the right party) | WP-2.10 |
| **XC-04** (report across the business) | WP-2.4, WP-2.6, WP-2.7, WP-2.8, WP-4.3 |
| **PM-10 / CR-05** (one timeline) | WP-3.1 |
| **SL-13/SL-14, EM-04, SU-01** | WP-4.5 — **remain unsatisfied while deferred** |
| **AC-14** (inventory value and COGS) | WP-4.6 — **remains unsatisfied while deferred** |

## 7.3 Seam repair against `CROSS_MODULE_FLOW_MATRIX.md`

The matrix records 33 of 60 seams impaired. This plan repairs them by chain:

| Chain | Impaired at baseline | Repaired by end of Phase 3 | Remaining |
|---|---|---|---|
| Sales ↔ Inventory ↔ Accounting | 5 | 5 | 0 |
| Purchasing ↔ Inventory ↔ Accounting | 2 | 1 | 1 (supplier debit note — WP-4.8) |
| CRM ↔ Sales | 5 | 5 | 0 |
| Support ↔ Maintenance ↔ Inventory ↔ Accounting | 4 | 3 | 1 (ticket revenue — WP-4.7) |
| Employees ↔ CRM ↔ Sales ↔ Payroll | 3 | 2 | 1 (field capture — WP-4.5) |
| Inventory internal | 5 | 5 | 0 |
| Cross-cutting | 9 | 8 | 1 (COGS in the close — WP-4.6) |
| **Total** | **33** | **29** | **4 — all deferred by ADR** |

The four survivors are exactly the four owner decisions. That is the intended end state of Phase 3:
**every remaining impaired seam is a decision someone made on purpose, and none is an accident.**

---

# 8. What this plan deliberately does not do

Recorded so a later reader does not mistake an omission for an oversight.

1. **It does not redesign any lifecycle that currently works.** WP-1.8 types six status columns and
   changes no transition. Where a working rule looked odd, it was left alone and noted.
2. **It does not introduce a reporting, notification or CQRS framework.** AD-03 replicates a shape
   that already exists five times. A framework would be a seventh thing to learn and the first thing
   to disagree with the documents.
3. **It does not build the API speculatively.** It builds *toward* it, at zero cost, by keeping every
   service command-shaped — then stops.
4. **It does not add COGS.** That is WP-4.6 and it is the owner's call. This plan makes the absence
   visible in three places (§0.5.3, §4B, R-11) rather than quietly working around it.
5. **It does not fabricate historical evidence.** Four backfills could have guessed — return-to-credit
   links, pre-existing quarantine documents, legacy damage documents, orphaned opportunity origins.
   All four report the unknown instead. An ERP that invents evidence is worse than one that admits a
   gap, and every one of these would have been invented in exactly the place an auditor looks.
6. **It does not add a configuration flag to soften a new control.** WP-1.4's maker/checker has no
   override, and WP-2.5's exists only because closing a period is sometimes a legal necessity — and
   even then it is loud, permissioned and reportable.

---

*Companion document:* `ERP_IMPLEMENTATION_PHASES.md` — sequencing, dependency graph, effort,
parallelisation, exit criteria, and the deployment runbook for the four data-moving migrations.

*End of document.*
