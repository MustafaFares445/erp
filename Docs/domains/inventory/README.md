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

The Inventory sidebar is workflow-oriented: seven destinations, each backed by existing Filament resources. This is a presentation layer only — no model, service, permission, status or URL changed, and every former resource keeps its own routes and policies.

| Destination | Tabs (existing resources) | Workspace tools |
| --- | --- | --- |
| Overview | Inventory dashboard (quick actions: Barcode Workbench, new transfer, new adjustment/count) | — |
| Stock | Stock Levels (default), Products, Lots, Serialized Units, Stock Movements | Catalog imports, Inventory reports |
| Inbound | Inbound Allocation (`PurchaseInbound`), Receipts | Barcode Workbench |
| Outbound | Outbound Fulfillment, Deliveries, Shipments, Packages | Barcode Workbench |
| Operations | Transfers, Adjustments, Counts, Returns, Corrections, Condition Changes, Reservations | Barcode Workbench |
| Planning & Alerts | Alerts, Replenishment Policies | Low stock (filtered Stock Levels), Inventory reports |
| Warehouses | Warehouse list/detail (links to stock, movements, reservations) | — |

Rules for this structure:

- Workspaces are declared once, as `tabs`/`tools` on items of the `inventory` group in `AdminModuleRegistry::groups()`; `App\Filament\Support\WorkspaceNavigation` renders them. A group's `contextual` list holds classes that belong to the module without a sidebar entry (Product Variants, Catalog Imports, Barcode Workbench).
- A workspace is visible when the user can open at least one tab; tabs and tools the user cannot open are never rendered. Hidden resources stay registered in the panel, so deep links, record links and notifications keep working, and they still resolve to the Inventory module.
- Inventory Reports live under Reports; Catalog Setup, Package Types and Inventory Settings live under System. Their URLs are unchanged.
- Stock quantities are never editable from Stock Levels; stock changes go through the Operations workflows.
- Operations lists (all, Receipts, Deliveries, Internal Transfers) and Stock Levels use the standard list-table experience ([ADR 0014](../../adr/0014-standard-list-table-experience.md)): a view tab bar, Group by and slide-over rule filters. Operations also have per-user favorites and a Starred tab; Stock Levels are a lookup list and have neither.

The Inventory dashboard follows the [module dashboard layout](../../architecture/SYSTEM_OVERVIEW.md#module-dashboards) and has a warehouse filter. It shows movements (inbound vs outbound) beside stock value by warehouse, then low stock beside the latest movements. Pending adjustments and transfers are counted in the "awaiting action" card. Open replenishment requirements, with their internal-transfer suggestions, appear here; the Purchasing dashboard shows only the residual external need.

## Boundary

Purchasing creates receipt provenance and allocations but does not write stock directly. Sales/Logistics may create delivery demand, but Inventory owns the actual stock effect. Accounting is not the owner of stock quantities.

## Related Decisions

- [ADR 0001](../../adr/0001-filament-inventory-dashboard-for-inventory.md)
- [ADR 0012](../../adr/0012-origin-domain-owns-business-facts.md)

See [Business Rules](BUSINESS_RULES.md), [Workflows](WORKFLOWS.md), and [Testing](TESTING.md).

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
