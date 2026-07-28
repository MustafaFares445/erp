# Contract: `AuditLogger` (shared audit-log writer)

`App\Services\Audit\AuditLogger` is the single writer of `audit_logs` (plan §2.4: exactly one audit trail; no parallel Filament trail). Introduced by FI-3 as the first sensitive action needs it (plan Complexity Tracking). Later sensitive actions (transfers, reservation release) reuse it unchanged.

## Public surface

```php
final class AuditLogger
{
    /**
     * Persist one audit_logs row. Call from WITHIN the caller's transaction
     * so a rollback discards the audit entry along with the action it records.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        Model $entity,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $actor = null,
        string $sourceChannel = 'dashboard',
    ): AuditLog;
}
```

Behavior:
- `actor_user_id` = `$actor?->id` (defaults to `auth()->user()` when null).
- `entity_type` = `$entity::class`; `entity_id` = `$entity->getKey()`.
- `ip_address` = `request()->ip()` when a request context exists, else null.
- Writes and returns the `AuditLog`. Performs **no** transaction of its own — it relies on the caller's (research R10).

## Adjustment-confirmation call (from `InventoryAdjustmentService::confirm`)

```php
$this->auditLogger->log(
    action: 'inventory.adjustment.confirmed',
    entity: $adjustment,
    oldValues: [
        'status' => 'draft',
        'items' => $items->map(fn ($i) => [
            'product_variant_id' => $i->product_variant_id,
            'old_quantity' => $i->old_quantity,
        ])->all(),
    ],
    newValues: [
        'status' => 'confirmed',
        'adjustment_number' => $adjustment->adjustment_number,
        'items' => $items->map(fn ($i) => [
            'product_variant_id' => $i->product_variant_id,
            'new_quantity' => $i->new_quantity,
            'difference' => $i->difference,
        ])->all(),
    ],
    actor: $actor,
    sourceChannel: 'dashboard',
);
```

One row per confirmation (per-item detail lives in the JSON), satisfying FR-013 / SC-005.

## Invariants

- Exactly one `audit_logs` row per successful confirmation; **zero** if the confirm transaction rolls back (SC-003).
- `audit_logs` has no Filament create/edit/delete surface (there is no `AuditLogResource` in FI-3). Rows are permanent (no soft delete).

## Test obligations

- After a successful confirm: one audit row with `action = inventory.adjustment.confirmed`, `actor_user_id` = confirming user, `entity_type`/`entity_id` = the adjustment, `source_channel = dashboard`, and before/after JSON containing each line's old/new/difference.
- After a rolled-back confirm (forced domain error): **no** audit row (unit test asserting `audit_logs` count unchanged).
