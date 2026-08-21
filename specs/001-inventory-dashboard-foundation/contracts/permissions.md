# Contract: Inventory Permission Catalogue

**Guard**: `web`. **Source of truth**: `App\Enums\InventoryPermission`. **Seeder**: `InventoryPermissionSeeder` (idempotent).

## Catalogue (must all exist after seeding)

```
inventory.warehouse.view
inventory.warehouse.manage
inventory.stock.view
inventory.movement.view
inventory.adjustment.view
inventory.adjustment.create
inventory.adjustment.confirm
inventory.transfer.view
inventory.transfer.create
inventory.transfer.confirm
inventory.reservation.view
inventory.reservation.release
inventory.export
```

## Guarantees

- **Completeness**: every string above exists as a Spatie `permission` row on guard `web` after `InventoryPermissionSeeder` runs.
- **Idempotency**: running the seeder N times yields exactly one row per permission (no duplicates, no errors).
- **Grantability**: a role granted any subset of these permissions causes `$user->can('<permission>')` to return true for exactly that subset.
- **No side effects**: seeding permissions does not create roles or assign permissions to users (except the explicit System Admin seed in `DatabaseSeeder`).

## Verification

- Feature test `InventoryPermissionSeederTest`: assert catalogue count/names; run twice and assert no duplicates; grant a subset to a role, assign to a user, assert `can()` reflects exactly that subset.
