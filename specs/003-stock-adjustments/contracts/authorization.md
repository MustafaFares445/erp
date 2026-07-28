# Contract: Authorization — `InventoryAdjustmentPolicy`

`App\Policies\InventoryAdjustmentPolicy` uses the FI-0 `ChecksInventoryPermissions` trait, mapping each ability to an already-seeded `App\Enums\InventoryPermission` case (no forked ACL — constitution Principle IV). The `inventory.adjustment.view|create|confirm` permissions are seeded by `InventoryPermissionSeeder` (FI-0); this feature adds **no** new permissions.

## Ability → permission map

| Filament / policy ability | Permission | Extra guard |
|---|---|---|
| `viewAny`, `view` | `inventory.adjustment.view` | — |
| `create` | `inventory.adjustment.create` | — |
| `update` | `inventory.adjustment.create` | **draft only** — `false` if `status === confirmed` (FR-016) |
| `delete` | `inventory.adjustment.create` | **draft only** — `false` if `status === confirmed` (FR-016/FR-018 soft delete) |
| `restore` | `inventory.adjustment.create` | — (recover a soft-deleted draft) |
| `forceDelete` | — | **always `false`** (no hard delete, FR-018) |
| `confirm` *(custom)* | `inventory.adjustment.confirm` | `false` if `status !== draft` (nothing to confirm) |

Unmapped abilities are denied by default via the trait.

## Segregation of duties (FR-020 / FR-021)

- `create`/`update` and `confirm` map to **distinct** permissions, so "apply" can be granted independently of "prepare".
- An administrator holding `create` but **not** `confirm`:
  - sees **no** Confirm action (Filament hides it via `->authorize('confirm', $record)` / `visible()` bound to the policy), and
  - is refused if they reach the confirm path by any means — even for a draft they created themselves.

## Panel / discovery gating (FR-022, inherited FI-0)

- Without `inventory.adjustment.view`, `AdjustmentResource` is hidden from the sidebar (`canViewAny` false) and every route returns 403 (`canAccess`).
- Panel access still requires `user_type = admin` (FI-0 `PanelAccessTest`); adjustments default to System Admin only (spec Assumption; plan Open Question #7).

## Confirm-action authorization wiring

The Filament Confirm action calls the policy `confirm` ability before invoking the service:

```php
Action::make('confirm')
    ->authorize(fn (InventoryAdjustment $record) => auth()->user()->can('confirm', $record))
    ->visible(fn (InventoryAdjustment $record) => $record->isDraft() && auth()->user()->can('confirm', $record))
    ->requiresConfirmation()
    ->action(fn (InventoryAdjustment $record) => $this->runInventoryOperation(
        fn () => app(InventoryAdjustmentService::class)->confirm($record, auth()->user()),
        'admin.inventory.adjustment.notifications.confirmed',
    ));
```

(`runInventoryOperation` is the FI-0 `InteractsWithInventoryServices` concern.)

## Test obligations

- View/create/confirm each hidden + 403 without the matching permission.
- `create`-only user: Confirm action absent; direct confirm attempt refused (FR-021).
- Confirmed adjustment: Edit + Delete actions absent; policy `update`/`delete` return false (FR-016).
- `forceDelete` always false (FR-018).
- Reuses the FI-1/FI-2 permission-matrix test style (`WarehouseResourceTest`).
