# Contract: Authorization — `StockTransferPolicy`

**Location**: `app/Policies/StockTransferPolicy.php`. Bound to `StockTransfer` by Laravel **auto-discovery** (`App\Models\StockTransfer` → `App\Policies\StockTransferPolicy`) — no provider/`Gate::policy()` registration. Uses `App\Policies\Concerns\ChecksInventoryPermissions`.

All permission strings already exist in `App\Enums\InventoryPermission` and are seeded by `InventoryPermissionSeeder` (guard `web`) — **no seeding change**.

## Ability → permission map

```php
protected function inventoryPermissionMap(): array
{
    return [
        'viewAny' => InventoryPermission::TransferView->value,     // inventory.transfer.view
        'view'    => InventoryPermission::TransferView->value,
        'create'  => InventoryPermission::TransferCreate->value,   // inventory.transfer.create
        'update'  => InventoryPermission::TransferCreate->value,
        'delete'  => InventoryPermission::TransferCreate->value,
        'restore' => InventoryPermission::TransferCreate->value,
        'confirm' => InventoryPermission::TransferConfirm->value,  // inventory.transfer.confirm
    ];
}
```

## Methods

- `viewAny/view/create` → delegate to `authorizeInventoryAbility($user, <ability>)`.
- `update(User, StockTransfer)` → permission **and** `$transfer->isDraft()` (confirmed is immutable, FR-017).
- `delete(User, StockTransfer)` → permission **and** `$transfer->isDraft()` (only drafts discardable, FR-019).
- `restore(User, StockTransfer)` → `TransferCreate` permission (restoring returns a draft to the preparer surface).
- `confirm(User, StockTransfer)` → `TransferConfirm` permission **and** `$transfer->isDraft()` (FR-022/FR-023; single confirm).
- `forceDelete(): false` — always (never physically delete; Constitution III). The `ChecksInventoryPermissions` trait supplies `deleteAny/forceDeleteAny/restoreAny/replicate/reorder` as denied/unused.

## Segregation of duties

- Prepare (`create`/`update`/`delete`/`restore`) and apply (`confirm`) are **distinct** permissions, so an approver role can be granted `confirm` without `create` and vice-versa (FR-022).
- The View page's Confirm action is gated by both `->authorize()` and `->visible()` on `can('confirm', $record)`, so a preparer without `confirm` sees **no** confirm control and is refused server-side even by direct action call (FR-023).
- Panel/area access: unpermitted users are blocked from the resource index and record URLs (`viewAny`/`view` deny → 403), consistent with the FI-0 panel gate (FR-024).

## Test obligations

- Preparer (view+create, no confirm): can create/edit/discard/restore drafts; confirm action hidden; `can('confirm', $draft)` is `false`; direct confirm call refused.
- Approver (view+create+confirm): confirm action visible and succeeds.
- Neither permission: `TransferResource` index and view URLs return `assertForbidden()`.
- Confirmed transfer: `can('update')` / `can('delete')` are `false` (draft guard); `forceDelete` never allowed.
- Auto-discovery binding verified implicitly by the resource tests exercising `authorize`/`can`.
