# Phase 2 Replenishment Implementation Record

**Canonical base:** `dev` after Phase 1  
**Scope:** Warehouse Min/Max policy → durable replenishment requirement → explicit coverage → transfer-first procurement queue.

## Canonical ownership

- `WarehouseReplenishmentPolicy` is the only Min/Max source of truth for a `(warehouse, product variant)` pair.
- `InventoryStock` remains the physical stock aggregate and does not own reorder thresholds.
- `ReplenishmentRequirement` is the durable procurement work item. `StockLow` remains an alert/event only.
- `ReplenishmentCoverage` records which business document covers part of a requirement; coverage is not physical stock and is not counted as incoming by itself.
- `ReplenishmentProjectionService` is the only replenishment projection implementation used by Phase 2 consumers.

## Projection

The Phase 2 projection is:

```text
saleable available
+ physical internal-transfer quantity already in transit
+ outstanding accepted/partially-received PO quantity with an actual inbound warehouse allocation
+ canonical supplier-replacement incoming (deferred until Phase 8 owns that document)
- uncovered committed demand not already represented by reservations
```

Current supplier-replacement incoming is intentionally `0` until Phase 8 introduces its canonical source document.

`InventoryStock::saleableAvailableQuantity()` already subtracts canonical reservations. Phase 2 therefore does not subtract the same committed demand a second time.

## Requirement lifecycle

- A policy can exist before any stock row exists.
- At or below Min, the engine opens one active requirement targeting Max.
- The database enforces at most one active requirement per policy on MySQL through a generated-column unique index.
- Stock-position changes resynchronize the requirement through the `InventoryStock` saved hook.
- Inactive policies and recovered stock cancel/resolve obsolete requirements according to the service lifecycle.

## Coverage lifecycle

Supported Phase 2 source types are:

- internal transfer;
- purchase order line;
- supplier replacement (reserved for the Phase 8 source document).

Purchase-order coverage is attached only after Inventory chooses an inbound warehouse. PO acceptance itself never chooses a destination warehouse.

Coverage is resynchronized when:

- an inbound line is allocated;
- a receipt partially advances the PO line;
- the PO becomes fully received;
- the PO is short-closed;
- the PO is cancelled.

Terminal PO states release active PO coverage and immediately resynchronize the affected replenishment policies, preventing stale covered quantities.

## Transfer-first behavior

`ReplenishmentTransferSuggestionService` evaluates another warehouse only through the canonical projection service and protects that source warehouse's Max buffer. Purchasing's "Requirements waiting for purchase" metric subtracts available internal-transfer suggestions before reporting residual external purchase need.

## Dashboard surfaces

Inventory dashboard metrics expose, behind the dedicated replenishment view permission:

- open replenishment requirements;
- uncovered base quantity;
- internal-transfer suggestion count and quantity.

Purchasing dashboard exposes requirements still waiting for external purchase after internal-transfer suggestions are applied.

## Phase boundary

Phase 2 does **not**:

- reintroduce destination warehouse ownership on Purchase Order;
- put procurement cost on Inventory/Logistics operation lines;
- implement supplier replacement receipts;
- split one PO line across multiple warehouse allocations (Phase 4);
- implement valuation/GRNI/three-way matching (Phase 6).
