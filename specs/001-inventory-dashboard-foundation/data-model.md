# Phase 1 Data Model: Inventory Dashboard Foundation & Guardrails

FI-0 is a guardrail phase, so its "data model" is deliberately small: it adds one identity attribute and formalizes the permission/authorization vocabulary. It creates **no** inventory tables — those belong to the backend Products-and-Inventory phase and to FI-1+.

## Entities

### UserType (enum, new)

Backed string enum identifying the account channel (constitution: `users.user_type`).

| Case | Value | Meaning |
|---|---|---|
| `Admin` | `admin` | System Administrator — the only channel allowed into the `/admin` panel |
| `Customer` | `customer` | Customer app channel — no dashboard access |
| `Employee` | `employee` | Employee app channel — affects inventory via the employee-app API, not the dashboard |

- **Type-coverage**: backed enum with explicit `string` values (satisfies 100% type coverage).
- **Extension note**: The future auth spec may add behavior/methods but MUST NOT remove or renumber these cases (see plan Complexity Tracking).

### User (existing, extended)

| Field | Type | Rules / Notes |
|---|---|---|
| `user_type` | `UserType` (cast from string column) | NEW. Column NOT NULL, least-privilege default `'customer'` (research R3). Added to `#[Fillable]`. Cast declared in `casts()`. |

- **Relationships**: Spatie `HasRoles` provides `roles` / `permissions` (Spatie already migrated). No new relationship tables in FI-0.
- **Derived rule**: `canAccessPanel(Panel $panel)` returns true for the `admin` panel only when `user_type === UserType::Admin`.
- **Helper (optional)**: a readable accessor such as `isAdmin(): bool` may back the gate for clarity/testability.

### InventoryPermission (`App\Enums\InventoryPermission`, new)

Single source of truth for the `inventory.*` permission strings, consumed by the seeder and by FI-1+ policies. Not a database table — the values become Spatie `permissions` rows when seeded.

| Permission | Governs (spec FR-003) |
|---|---|
| `inventory.warehouse.view` | View warehouses/locations |
| `inventory.warehouse.manage` | Create/update warehouses/locations |
| `inventory.stock.view` | View stock levels (read-only) |
| `inventory.movement.view` | View the movement ledger (read-only) |
| `inventory.adjustment.view` | View adjustments |
| `inventory.adjustment.create` | Create draft adjustments |
| `inventory.adjustment.confirm` | Confirm adjustments (segregation-of-duties lever, Open Question #10) |
| `inventory.transfer.view` | View transfers |
| `inventory.transfer.create` | Create draft transfers |
| `inventory.transfer.confirm` | Confirm/dispatch transfers |
| `inventory.reservation.view` | View reservations |
| `inventory.reservation.release` | Release/expire reservations |
| `inventory.export` | Trigger queued exports |

- **Guard**: `web` (session-authenticated panel).
- **Seeding**: idempotent (`firstOrCreate` by name + guard). Re-running the seeder is a no-op.

### Role & Permission (Spatie, existing tables)

- Provided by `spatie/laravel-permission` (`roles`, `permissions`, and pivots already migrated on 2026-07-20).
- FI-0 seeds the permission rows above and a System Admin user. Role composition beyond that is out of FI-0 scope (a warehouse/operator role is deferred — Open Question #7).

### Audit Entry (referenced, not created here)

- The spec's Audit Entry (who/what/before-after/`source_channel = dashboard`) is written by the **domain services** as a side effect (plan §2.4). FI-0 defines no audit table and writes no audit rows; it only guarantees, via the action adapter, that dashboard actions route through those services. Realized when services exist (Open Question #11).

## State transitions

None in FI-0. Document draft→confirmed lifecycles belong to FI-3 (adjustments) and FI-4 (transfers). The only state-like element here is the binary panel-access decision, which is a pure function of `user_type`, not a stored transition.

## Validation rules

| Rule | Where enforced |
|---|---|
| Panel access requires `user_type === Admin` | `User::canAccessPanel()` |
| Resource visibility/access requires the matching `inventory.*` permission | `ChecksInventoryPermissions` policy trait (applied by FI-1+ resources) |
| Stock-changing actions must delegate to a domain service (no direct writes) | `InteractsWithInventoryServices` adapter + `ArchTest` guard |
| Domain/validation errors surface as notifications with no partial write | `InteractsWithInventoryServices` adapter |
| Permission names are unique per guard | Spatie `permissions` unique index + idempotent seeder |
