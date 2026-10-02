# Inventory Domain

---
status: canonical
owner: inventory
last_verified: 2026-10-02
verified_against: app/Services/Inventory, app/Enums inventory lifecycle enums, app/Filament/Resources inventory surfaces, tests/Feature/Inventory
---

## Purpose

Inventory owns physical stock truth. Other domains may request inventory effects, but stock custody, balances, reservations, lots/serials, counts, corrections, returns, and inventory movements are enforced through Inventory services.

## Main Code Anchors

- `app/Services/Inventory/InventoryOperationService.php`
- `InventoryPostingService.php`
- `InventoryBalanceService.php`
- `InventoryReservationService.php`
- `InventoryLotService.php`
- `InventoryCountService.php`
- `InventoryCorrectionService.php`
- `InventoryReturnService.php`
- `InventoryConditionChangeService.php`
- `QuantityNormalizer.php`
- `ProductTypeGuard.php`

Inventory also contains catalog/pricing helpers that will later be split into the Catalog & Pricing canonical domain.

## Main UI Surfaces

Warehouses, Stock Levels, Stock Movements, Inventory Operations, Reservations, Counts, Lots, Serialized Units, Adjustments, Returns, Corrections/condition changes, Alerts, Reports, Replenishment Policies, Products/Variants and inventory import surfaces.

## Boundary

Purchasing creates receipt provenance and allocations but does not write stock directly. Sales/Logistics may create delivery demand, but Inventory owns the actual stock effect. Accounting is not the owner of stock quantities.

## Related Decisions

- [ADR 0001](../../adr/0001-filament-inventory-dashboard-for-inventory.md)
- [ADR 0012](../../adr/0012-origin-domain-owns-business-facts.md)

See [Business Rules](BUSINESS_RULES.md), [Workflows](WORKFLOWS.md), and [Testing](TESTING.md).

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
