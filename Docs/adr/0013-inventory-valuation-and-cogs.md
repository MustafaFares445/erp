# ADR 0013: Activate Weighted-Average Inventory Valuation and Cost of Sales (WP-4.6)

**Status**: Accepted

**Date**: 2026-09-12

**Deciders**: Project Owner

**Related**: `PHASE_4_PLAN.md` §1 WP-4.6, `ERP_REMEDIATION_PLAN.md` GAP-MW-15, ADR 0007 (Accounting foundation, §"Out of scope" — "cost accounting, inventory valuation, or cost-of-goods-sold posting"), `app/Services/Inventory/InventoryValuationService.php`, `app/Models/InventoryValuationBalance.php`, `app/Models/InventoryValuationEntry.php`, `tests/Feature/Accounting/NoAutomaticPostingTest.php`

## Context

ADR 0007 explicitly put cost accounting, inventory valuation, and
cost-of-goods-sold posting out of scope when the general-ledger foundation
was built. `PHASE_4_PLAN.md` recorded the resulting gap as GAP-MW-15 and
called it, in its own words, **"the single largest divergence between what
the ledger says and what the business is"**: the P&L reports revenue with
no cost of sales, so gross margin is not derivable at any grain — per
product, per customer, per job. The balance sheet omits inventory as an
asset while the warehouse physically holds it. Disposals and damage
write-offs produce a stock movement and no expense, so shrinkage never
reaches the P&L.

Every other Phase 4B gap costs the business a capability it can live
without a little longer. This one costs the business the truth of its own
financial statements, which is why the plan flagged it as the one deferred
item with a live argument for pulling forward rather than waiting for a
volume threshold. `SupplierCostWritebackService` already maintained a
last-received unit cost per variant, which made weighted-average — rather
than FIFO, which would require lot-level cost layers the schema does not
carry — the smallest honest step available.

## Decision

Build `App\Services\Inventory\InventoryValuationService` as a
**weighted-average** valuation layer sitting beside, not inside, the
existing quantity ledger (`InventoryMovement`), triggered synchronously by
`InventoryOperationCompleted` via `ApplyInventoryValuationOnOperationCompleted`
— valuation and its financial consequence commit in the same transaction as
the completed operation, by design.

### In scope, and what was actually built

- **`inventory_valuation_balances`** (`InventoryValuationBalance`): one
  materialized weighted-average balance per variant × warehouse —
  `quantity_base`, `average_unit_cost`, `inventory_value` — recomputed on
  every valued movement, never on read.
- **`inventory_valuation_entries`** (`InventoryValuationEntry`): one
  immutable entry per canonical movement that was valued, carrying the
  quantity delta, the unit cost snapshot, and the value delta. This is the
  audit trail a reconciliation can replay; the balance table is a cache of
  it, not a second source of truth.
- **Receipt posts inventory asset, credits GRNI**
  (`postReceiptIfConfigured()`): `Dr Inventory / Cr GRNI` for the line's
  received cost, sourced from the operation line's `unit_cost` (adjusted by
  its conversion-factor snapshot) or, failing that, the variant's
  `cost_price`.
- **Delivery posts cost of sales** (`postCogsIfConfigured()`): `Dr COGS / Cr
  Inventory` at the balance's *current* weighted-average cost at the moment
  of delivery — **this changes the invariant `NoAutomaticPostingTest`
  guarded**, and that test was deliberately rewritten (now naming twelve
  callers instead of nine, `InventoryValuationService` among them) rather
  than left describing a rule this package intentionally supersedes. That
  rewrite is the clearest signal available that this is a scope decision,
  not a defect being reintroduced.
- **Damage, disposal, and adjustment post shrinkage**
  (`postShrinkageIfConfigured()`, called from `processStandaloneMovement()`):
  `Dr Shrinkage expense / Cr Inventory` for a valued write-down.
- **A reconciliation contract** (`reconciliation(CarbonImmutable $asOf)`):
  sums posted valuation entries against the inventory asset control
  account's posted ledger balance and reports the difference — the
  materialized-and-cached counterpart Phase 4A's WP-4.1 performance work
  measures against a production-scale dataset
  (`tests/Feature/Performance/ReportBudgetTest.php`).
- **Opt-in everywhere, by construction.** `isConfigured()` and each
  `postXIfConfigured()` method treat an unconfigured inventory asset,
  GRNI, or shrinkage account as "nothing to post," not as an error — an
  accounting dataset that has never turned valuation on incurs no behavior
  change. `PeriodCloseChecklistService::checkInventoryValuation()` mirrors
  this: it passes, rather than blocks the close, when no inventory asset
  account is configured at all.
- **"No invented cost" is enforced, not assumed.** A delivery or transfer
  touching stock whose tracked balance can't cover the movement — opening
  balances, demo data, anything that predates this package — is a no-op for
  valuation purposes rather than forcing the balance negative or inventing
  a cost basis. This guard was added during this session's own hardening
  pass after it broke roughly twenty-five pre-existing tests; see the
  remediation report for the incident.

### Out of scope

This decision does **not** authorize:

- **FIFO or lot-level cost layers.** Weighted-average was chosen because
  the schema does not carry per-lot cost history; introducing FIFO would be
  its own, separately-scoped decision requiring a new `inventory_cost_layers`
  table the plan itself flagged as an alternative shape.
- **Multi-currency valuation or revaluation.**
- **A year-end close rolling COGS or shrinkage into retained earnings** —
  that remains general ledger territory ADR 0007 and its successors govern.
- **GRNI clearing on bill approval** — that is a separate, already-existing
  concern (`GrniClearingService`, exercised in this session's WP-4.8 test
  additions), not introduced or altered by this package.

### Prerequisite honored

WP-2.5 (the period-close checklist) already existed before this package
activated, per the plan's own stated prerequisite: introducing inventory
postings without a reconciliation gate would have added a new way for the
ledger to be wrong with no signal that it is. `PeriodCloseCheck::InventoryAgreesToControlAccount`
is the mandatory check this package adds to that existing checklist.

## Consequences

**Positive.** AC-14, MT-05, and SL-15 no longer describe a permanent gap.
A configured business now gets a real inventory asset balance, real cost of
sales, and a reconciliation figure it can trust because the check is
mandatory at close, not advisory.

**Negative.** Until this session, `InventoryValuationService` — code that
moves real money in a financial ledger — had **zero direct test coverage**;
only two architecture tests referenced it in passing. This ADR is
accompanied by `tests/Feature/Inventory/InventoryValuationServiceTest.php`,
added in this same remediation pass, covering receipt costing, cost
averaging across receipts, delivery COGS at the current average, the
insufficient-balance and unconfigured-account no-ops, shrinkage posting,
idempotent reprocessing, and reconciliation — but this gap existing at all
until now is itself worth recording plainly rather than glossing over.
Separately, the service's bcmath-heavy arithmetic (`bccomp`/`bcmul`/`bcadd`
on plain `string`, not `numeric-string`) is part of the project's
pre-existing, unbaselined PHPStan backlog; this pass added tests, not a
type-safety rewrite of financial arithmetic already running in production
paths, and that backlog is documented separately in the remediation report
rather than addressed piecemeal here.

**Neutral.** `InventorySetting`'s asset/COGS/shrinkage account fields and
`PurchaseSetting`'s GRNI account field must be configured before any
posting occurs; a dataset that never configures them behaves exactly as it
did before this ADR.

**Enforcement.** `NoAutomaticPostingTest` now names `InventoryValuationService`
among its twelve permitted callers of `JournalPostingService`, so a
thirteenth caller appearing anywhere else in the codebase fails the build
rather than silently adding a new posting path. Per `.ai/feature-development`
rule 8, this list may shrink or be renamed to reflect an equally-authorized
caller; it may not be silently widened.
