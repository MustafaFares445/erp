# Contract: `AdjustmentResource` (Filament UI)

`App\Filament\Resources\Adjustments\AdjustmentResource` — the draft→confirm screen. Auto-discovered into the `inventory` navigation group; `AdminModuleRegistry` already references it (no registry edit, research R2). All authorization flows through `InventoryAdjustmentPolicy` ([authorization.md](authorization.md)). The Confirm action is a thin adapter over `InventoryAdjustmentService` via `InteractsWithInventoryServices` — it computes nothing (the FI-0 arch guard forbids this resource from touching `InventoryStock`/`InventoryMovement`).

## Form — `Schemas/AdjustmentForm.php` (draft only)

| Field | Control | Rules / behavior |
|---|---|---|
| `warehouse_id` | Select (active warehouses) | required; disabled once confirmed |
| `adjustment_number` | read-only text / placeholder | shows "Assigned on confirmation" while draft; the confirmed number after (FR-002) |
| `reason` | Textarea | required (FR-001/FR-008) |
| `status` | hidden/badge | display only; not editable |

Rules sourced from `App\Data\Inventory\AdjustmentData` (research R11). The whole form is read-only when `status === confirmed` (FR-016).

## Items — `RelationManagers/AdjustmentItemsRelationManager.php`

| Field | Control | Rules / behavior |
|---|---|---|
| `product_variant_id` | Select (SKU + name) | required (FR-003) |
| `old_quantity` | read-only | current on-hand for `(variant, warehouse)`, or 0 if none; display-only, taken from live balance (FR-003, R7) |
| `new_quantity` | numeric | required, `>= 0` (FR-005) |
| `difference` | read-only | `new_quantity − old_quantity`, computed live; not editable (FR-004) |

- Add/edit/remove **only** while the parent is `draft` (relation manager actions gated on `isDraft()`, FR-006). Read-only once confirmed.
- No stock is touched by any item edit — drafts are inert (FR-007).

## Table — `Tables/AdjustmentsTable.php`

Columns: `adjustment_number`, `warehouse.code`/name, `reason` (truncated), `status` (badge: draft = warning/gray, confirmed = success), `items_count`, `created_by`/creator name, `created_at`.

Filters: `status` (draft/confirmed), `warehouse_id`, `created_at` date range (FR-023).

Row actions: `View` (always); `Edit` (draft only); `Delete` (soft, draft only); `Confirm` (draft only + `confirm` permission).

## Infolist — `Schemas/AdjustmentInfolist.php` (View page)

Shows number, warehouse, reason, status, creator, timestamps, the item lines (variant, old, new, difference), and — once confirmed — a read-only link to the resulting movements (`source_type = 'adjustment'`, `source_id = id`) on the FI-2 `StockMovementResource` (FR-014, resolved by the already-shipped `StockMovementsTable::sourceResource()`). No editable cross-module relation (module boundary, plan §0).

## Confirm action

Bound to the `confirm` policy ability + `isDraft()` for visibility; `requiresConfirmation()`; on run calls `runInventoryOperation(fn () => app(InventoryAdjustmentService::class)->confirm($record, auth()->user()), 'admin.inventory.adjustment.notifications.confirmed')`. Domain errors surface as danger notifications with nothing changed (FR-015); success shows the confirmed notification and the refreshed record.

## Pages

- `ListAdjustments`, `CreateAdjustment`, `EditAdjustment` (redirects/blocks if confirmed), `ViewAdjustment`.
- `CreateAdjustment` sets `created_by = auth()->id()` and `status = draft` (via model default / `mutateFormDataBeforeCreate` or an observer). No number is set at create (R6).

## i18n keys to add (`lang/en/admin.php`, under `inventory.adjustment`)

`reason`, `adjustment_number`, `number_pending` ("Assigned on confirmation"), `status`, `items_count`, `old_quantity`, `new_quantity`, `difference`, `confirm` (action label), `notifications.confirmed` (success), `errors.not_draft`, `errors.inactive_warehouse`, `errors.negative_result`. (Arabic restoration deferred — Open Question #8.)

## Test obligations (`tests/Feature/Filament/AdjustmentResourceTest.php`)

- Create draft (warehouse + reason + ≥1 item) ⇒ saved as `draft`, no number, **no** stock/movement change (SC-001).
- Validation: missing reason, no items, negative `new_quantity` each rejected (FR-005/FR-008).
- Permission matrix: view/create/confirm hidden + 403 without each permission; `create`-only user sees no Confirm (FR-020/FR-021/FR-022).
- Confirmed adjustment: Edit/Delete/Confirm actions absent (FR-016/FR-017).
- Confirm happy path is covered end-to-end in `ConfirmAdjustmentTest` ([adjustment-service.md](adjustment-service.md)); this file asserts the **UI** wiring (action visible for authorized draft, notification on success/failure).
