# Contract: Authorization & Architecture Guard (FI-1/FI-2)

Three policies reuse the FI-0 `App\Policies\Concerns\ChecksInventoryPermissions` trait (ability → `inventory.*` permission, deny-by-default for unmapped abilities). No forked ACL (Principle IV). Filament v5 auto-reads these for resource CRUD, action visibility, and navigation.

## Ability → permission maps

### WarehousePolicy → `warehouses`

| Ability | Resolves to |
|---|---|
| `viewAny`, `view` | `inventory.warehouse.view` |
| `create`, `update`, `restore` | `inventory.warehouse.manage` |
| `delete` | `inventory.warehouse.manage` **AND** warehouse not referenced by any `inventory_stocks` / `inventory_movements` row (else deny → FR-005) |
| `forceDelete` | `false` — no hard delete (FR-006) |

### InventoryStockPolicy → `inventory_stocks`

| Ability | Resolves to |
|---|---|
| `viewAny`, `view` | `inventory.stock.view` |
| `create`, `update`, `delete`, `restore`, `forceDelete` | `false` (unmapped ⇒ deny) → read-only (FR-010) |

### InventoryMovementPolicy → `inventory_movements`

| Ability | Resolves to |
|---|---|
| `viewAny`, `view` | `inventory.movement.view` |
| all write abilities | `false` → immutable ledger (FR-015) |

## Warehouse delete guard

`delete(User $user, Warehouse $warehouse): bool` returns `false` unless the user has `inventory.warehouse.manage` **and** the warehouse has zero related `inventory_stocks` and zero related `inventory_movements`. Lives in the policy (reusable by API + read by Filament to hide/disable the delete action), not in a Filament component (research R5).

## Architecture guard refinement (research R1)

**Intent**: no `App\Filament` code path may **write** stock balances or the movement ledger.

- Retain the FI-0 Pest `arch()` ban — `App\Filament` **must not use** `App\Models\InventoryStock` / `App\Models\InventoryMovement` — for **all** Filament classes **except** the two sanctioned read-only namespaces:
  - `App\Filament\Resources\StockLevels` (+ `Pages`/`Tables`)
  - `App\Filament\Resources\StockMovements` (+ `Pages`/`Tables`/`Schemas`)
- Back the exception with behavior tests proving those resources cannot write: `canCreate() === false`, no `EditAction`/`DeleteAction`/delete bulk action, policies deny `create`/`update`/`delete`.

**Guarantee preserved**: future write surfaces (Adjustments FI-3, Transfers FI-4) live in *other* Filament namespaces and remain fully banned from referencing these models directly — they must delegate to domain services. The read-only resources, being write-actionless by construction and by test, cannot mutate anything.

## Behavior contract

| Given | When | Then |
|---|---|---|
| role granted only `inventory.stock.view` | open panel | stock visible; warehouses + movements hidden; their URLs 403 |
| role granted `inventory.warehouse.view` only (no manage) | open warehouse | list visible; create/edit/delete controls absent |
| a Filament class outside the read-only namespaces references `InventoryStock`/`InventoryMovement` | run `composer test` | `ArchTest` fails the build |
| `StockLevelResource` / `StockMovementResource` reference their models | run `composer test` | allowed (excepted) — and no-write behavior tests pass |
