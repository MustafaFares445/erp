# Contract: audit logging via `spatie/laravel-activitylog`

> Superseded from the original `App\Services\Audit\AuditLogger` design described below (plan §2.4: exactly one audit trail) by ADR 0005: the audit trail is now backed by `spatie/laravel-activitylog` (table `activity_log`, model `App\Models\AuditLog extends Spatie\Activitylog\Models\Activity`). There is no longer an `AuditLogger` service class — call sites use Spatie's `activity()` helper directly. Introduced by FI-3 as the first sensitive action needed it (plan Complexity Tracking); later sensitive actions (transfers, reservation release) reuse the same `activity()` call shape unchanged.

## Public surface

Callers write directly via the global `activity()` helper, chaining:

```php
activity()
    ->performedOn(Model $entity)             // sets subject_type/subject_id
    ->causedBy(?User $actor)                 // sets causer_type/causer_id; omit entirely, or pass null, to fall back to auth()->user()
    ->withChanges([
        'old' => array $oldValues,           // omit key if there's no "before" state
        'attributes' => array $newValues,    // omit key if there's no "after" state
    ])
    ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
    ->log(string $action);                   // stored in the `description` column; returns the AuditLog (Activity) row
```

Behavior:
- `causer_type`/`causer_id` = `$actor`'s morph identity; when `causedBy()` is omitted (or called with `null`), Spatie's `ActivityLogger` resolves `auth()->user()` automatically — same default as the old `AuditLogger`.
- `subject_type`/`subject_id` = `$entity::class` / `$entity->getKey()`.
- `properties.ip_address` = `request()->ip()`, set explicitly by the caller (no longer implicit).
- Writes and returns the `AuditLog`. Performs **no** transaction of its own — it relies on the caller's (research R10) — Eloquent inserts made through `activity()->log()` participate in the caller's ambient `DB::transaction()` exactly like any other write.

## Adjustment-confirmation call (from `InventoryAdjustmentService::confirm`)

```php
activity()
    ->performedOn($locked)
    ->causedBy($actor)
    ->withChanges([
        'old' => [
            'status' => 'draft',
            'items' => $oldValuesItems, // [['product_variant_id' => ..., 'old_quantity' => ...], ...]
        ],
        'attributes' => [
            'status' => 'confirmed',
            'adjustment_number' => $adjustmentNumber,
            'items' => $newValuesItems, // [['product_variant_id' => ..., 'new_quantity' => ..., 'difference' => ...], ...]
        ],
    ])
    ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
    ->log('inventory.adjustment.confirmed');
```

One row per confirmation (per-item detail lives in the `attribute_changes` JSON), satisfying FR-013 / SC-005.

## Invariants

- Exactly one `activity_log` row per successful confirmation; **zero** if the confirm transaction rolls back (SC-003).
- The `AuditLogResource` Filament resource is read-only (no create/edit/delete surface). Rows are permanent (no soft delete).

## Test obligations

- After a successful confirm: one audit row with `description = inventory.adjustment.confirmed`, `causer_id` = confirming user, `subject_type`/`subject_id` = the adjustment, `properties.source_channel = dashboard`, and `attribute_changes` containing each line's old/new/difference.
- After a rolled-back confirm (forced domain error): **no** audit row (unit test asserting `activity_log` count unchanged).
