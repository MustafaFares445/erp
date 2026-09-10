# ADR 0012: Origin Domain Owns the Business Fact

**Status**: Accepted

**Date**: 2026-09-10

**Deciders**: Project Owner

**Related**: `ERP_CROSS_MODULE_REMEDIATION_PLAN.md` Phase 0, `Docs/adr/0006-filament-purchasing-dashboard.md`, `Docs/adr/0011-accounting-payables-expenses-bills.md`, `Docs/ERP_DOMAIN_MODEL.md`, `Docs/CROSS_MODULE_BUSINESS_FLOWS.md`

## Context

The Phase 0 cross-module remediation found several facts represented in more than
one module and therefore independently editable: a purchase order owned a
warehouse even though warehouse routing belongs to Logistics/Inventory, a
PO-linked Bill could independently name a supplier already fixed by the purchase
order, an InventoryStock row owned a reorder threshold that is policy rather than
physical stock, and receipt lines carried commercial cost despite Inventory
owning physical custody and quantity.

Those duplications create a structural failure mode: two modules can disagree
while each remains internally valid. Fixing individual fields is not enough; the
system needs one rule that decides where a cross-module fact is allowed to be
created and changed.

## Decision

A business fact is owned by the domain where that fact originates. Downstream
modules may reference it, derive from it, or create their own domain artefact in
response to it, but they may not introduce a second independently writable source
for the same fact.

This ownership rule is **immutable architecture**. A feature that needs to move
ownership must amend this ADR explicitly; adding a convenience column, hidden
writeback, duplicated form input, or cross-module mutable copy is not an
acceptable shortcut.

### Purchasing owns the commercial commitment

`PurchaseOrder` / `PurchaseOrderLine` own supplier, ordered quantity, accepted
commercial unit cost, currency, and the commercial commitment lifecycle.

- The purchase order does **not** own a destination warehouse.
- `PurchaseOrderLine.unit_cost` is the frozen commercial price signal used by
  `SupplierCostWritebackService` when the order becomes Accepted.
- Receipt completion does not compute or write supplier commercial cost.
- Inventory/Logistics surfaces do not expose procurement price inputs.

### Logistics / Inventory owns physical routing and custody

An Accepted PO activates one `PurchaseInbound`, with one inbound line per PO line.
`PurchaseInboundAllocation` owns the warehouse destination selected after
acceptance. Inventory operations own receipt execution, lot/serial/expiry data,
physical quantities, stock balances, reservations, movements, and custody.

Accepting a PO therefore creates **no** `InventoryOperation`, `InventoryMovement`,
or stock mutation. Physical stock changes only through the canonical Inventory
operation lifecycle.

Replenishment thresholds are policy and live in
`WarehouseReplenishmentPolicy`; they are not columns on `InventoryStock`.

### Accounting owns the payable and financial posting

On PO acceptance the system provisions one PO-linked **Draft** `Bill` so the
Accounting workflow is present automatically. This is a cross-module handoff, not
a transfer of accounting authority:

- the generated Bill derives its supplier from `PurchaseOrder.supplier_id`;
- a PO-linked Bill must not independently set `supplier_id`;
- `resolved_supplier_id` is the canonical supplier-scoped query value for both
  standalone and PO-linked Bills;
- the generated lines reference their originating `purchase_order_line_id`;
- Purchasing roles do not receive generic Bill-create or Bill-approval rights;
- only Accounting approval can recognise/post the liability.

This decision **supersedes the narrow statement in ADR 0011 that a purchasing
action may never create a Bill at all**. What remains prohibited is Purchasing
owning or approving the payable. The acceptance orchestrator may ask the
Accounting domain service to provision a Draft because that is an automatic
workflow handoff; it may not post, approve, pay, or directly mutate ledger state.

### Supplier confirmation is explicit, not guessed

Automatic supplier-confirmation activation is controlled by
`Supplier.requires_confirmation`. The flag defaults to false. If enabled, PO
acceptance creates one Pending confirmation workflow; retries reuse the existing
workflow. This is commercial Purchasing metadata and must not be editable merely
because an Inventory user can access the shared Supplier record.

### Acceptance is the single cross-module handoff

`PurchaseOrderAcceptanceOrchestrator` is the one acceptance-time entry point for
cross-module side effects. Inside the PO acceptance transaction it:

1. ensures the `PurchaseInbound` allocation aggregate;
2. creates a Pending supplier confirmation only when the supplier opts in;
3. applies the accepted supplier commercial-cost writeback;
4. ensures one PO-linked Draft Bill with copied line provenance;
5. emits `PurchaseOrderAccepted` after commit so Inventory allocation owners and
   Accounting bill owners are notified only after the transaction is durable.

No Filament action may independently reproduce those side effects.

## Enforcement

Phase 0 architecture tests are required to fail if any of these ownership leaks
return:

- `purchase_orders.destination_warehouse_id`;
- `inventory_stocks.reorder_level`;
- independently settable supplier ownership on a PO-linked Bill;
- procurement price fields in Inventory operation forms;
- receipt/Inventory services writing `SupplierProductReference.purchase_cost`;
- Inventory permissions being used as a route to Purchasing price management;
- shared Supplier UI exposing commercial reference prices or confirmation policy
  without Purchasing permission.

The database constraints and service-level transaction/locking rules remain the
second line of defence; architecture tests prevent a future refactor from
silently reopening the same ownership paths.

## Consequences

**Positive.** Cross-module workflows remain integrated without making modules
co-own the same data. The system has one place to explain every critical fact,
and retry/rollback behavior can be reasoned about at the acceptance transaction
boundary.

**Negative.** Some convenient UI shortcuts are intentionally unavailable. A user
who can maintain Inventory supplier contact/catalogue data is not automatically
allowed to maintain supplier commercial prices or confirmation policy. New
cross-module features must call the owning domain service rather than writing the
other module's tables directly.

## Superseded statements

ADR 0011 remains Accepted for Accounting payables, posting, tax, matching, and
approval ownership, except for its absolute prohibition on creating a Bill as a
Purchasing-triggered side effect. That one statement is replaced by this ADR's
system-provisioned Draft Bill handoff. A Draft created by acceptance is not a
posted liability and conveys no Accounting permission to the Purchasing actor.
