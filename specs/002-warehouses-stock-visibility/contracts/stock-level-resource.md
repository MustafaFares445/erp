# Contract: StockLevelResource (FI-2, READ-ONLY)

`App\Filament\Resources\StockLevels\StockLevelResource` — read-only view of `inventory_stocks`. **No create/edit/delete anywhere.**

## Read-only mechanics (research R6)

- `canCreate(): bool => false`.
- No `CreateAction`, `EditAction`, `DeleteAction`, or delete bulk action registered.
- Pages: `List`, `View` only (no Create/Edit page).
- Row action: `ViewAction` only.

## Table

- **Columns**: product variant (`sku` + `name`), warehouse (`code`/`name`), `on_hand_quantity`, `reserved_quantity`, `available_quantity`, `reorder_level`, low-stock indicator.
- **Low-stock rule**: flagged ⇔ `reorder_level IS NOT NULL AND available_quantity <= reorder_level` (research R4; inclusive boundary; null reorder ⇒ not low).
- **Filters**: warehouse (select), low-stock only (filter applying the rule above), product/variant search (SKU + name).
- **Sanctioned-write messaging (FR-014)**: header communicates that balances change only via adjustments/transfers. Header actions linking to `AdjustmentResource`/`TransferResource` are added when those ship (FI-3/FI-4); until then no inline write control exists.
- **No caching** of balances (research R4).

## Behavior contract

| Given | When | Then |
|---|---|---|
| admin with `inventory.stock.view` | open stock view | each row shows variant, warehouse, on-hand, reserved, available, reorder (FR-009) |
| any admin | search for create/edit/delete control | none exists (FR-010, SC-004) |
| variant with available ≤ reorder (reorder set) | apply low-stock filter | row listed + flagged (FR-011, SC-005) |
| variant with `reorder_level` null | apply low-stock filter | row **not** flagged/listed (edge case) |
| warehouse + search term | apply filters | list narrows to matching rows (FR-012) |
| same variant in 2 warehouses | list | 2 independent rows (FR-013, SC-008) |
| admin lacking `inventory.stock.view` | direct URL | 403; hidden in nav (FR-020) |

## Authorization

`InventoryStockPolicy`: viewAny/view → `inventory.stock.view`; all write abilities → false (deny-by-default). See authorization.md.
