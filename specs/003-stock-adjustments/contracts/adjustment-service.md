# Contract: `InventoryAdjustmentService` (the sole stock writer)

`App\Services\Inventory\InventoryAdjustmentService` is the **only** code path that mutates stock as a result of an adjustment (FR-009). The Filament layer never touches `InventoryStock`/`InventoryMovement` (enforced by the FI-0 arch guard, research R4).

## Public surface

```php
final class InventoryAdjustmentService
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Apply a draft adjustment: for each item write one adjustment movement,
     * update the (variant, warehouse) balance by the line difference, assign
     * the adjustment number, mark the document confirmed, and write one audit
     * record — atomically. Throws on any domain violation, leaving no partial
     * state.
     *
     * @throws \DomainException  invalid state / inactive warehouse / negative result
     */
    public function confirm(InventoryAdjustment $adjustment, User $actor): void;
}
```

## `confirm()` behavior (all inside one `DB::transaction`)

1. **Lock + guard**: reload `$adjustment->newQuery()->lockForUpdate()->findOrFail($adjustment->id)` with `items`. If `status !== AdjustmentStatus::Draft` → `throw new DomainException(__('admin.inventory.adjustment.errors.not_draft'))` (covers re-confirm + concurrent confirm, FR-017).
2. **Warehouse active check**: if the warehouse `is_active === false` → `DomainException` (`...errors.inactive_warehouse`) (FR-015).
3. **Empty guard**: if the adjustment has no items → `DomainException` (defense in depth; validation already blocks it, FR-008).
4. **Per item** (in a stable order):
   a. Read/lock the `inventory_stocks` row for `(product_variant_id, warehouse_id)`; if none, treat `old_quantity = 0` and create the row on write (FR-012).
   b. `oldQty = row?->on_hand_quantity ?? 0`; `difference = new_quantity − oldQty`; `newOnHand = oldQty + difference` (= `new_quantity`).
   c. If `newOnHand < 0` → `DomainException` (`...errors.negative_result`) (R8, FR-015).
   d. Persist item `old_quantity`, `difference` (finalized, R7).
   e. Upsert the stock row: `on_hand_quantity = newOnHand`; `available_quantity = newOnHand − reserved_quantity`; leave `reserved_quantity`/`reorder_level` untouched.
   f. Create one `inventory_movements` row: `movement_type = adjustment`, `quantity = difference` (signed, may be 0 — never skipped, SC-007/R9), `source_type = 'adjustment'` (short code matching the shipped `StockMovementsTable::sourceResource()` link resolver — R9 correction), `source_id = adjustment->id`, `status = confirmed`, `created_by = actor->id`, `notes = adjustment->reason`.
5. **Assign number**: set `adjustment_number` = next `ADJ-` sequential (unique within the transaction, R6).
6. **Mark confirmed**: `status = AdjustmentStatus::Confirmed`, `updated_by = actor->id`; save.
7. **Audit** (still in the transaction): `auditLogger->log(...)` — see [audit-log.md](audit-log.md). A rollback discards this too.

**Postconditions on success**: `#items` new movements exist for this adjustment; each `(variant, warehouse)` balance changed by exactly its line difference; the adjustment is `confirmed` with a unique number; exactly one audit row exists.

**Postconditions on any throw**: transaction rolled back — **0** new movements, **0** balance changes, **0** audit rows, adjustment remains `draft` (SC-003).

## Idempotency / concurrency

- Second `confirm()` on an already-confirmed adjustment throws at step 1 (no effect).
- Two concurrent `confirm()` calls: the row lock serializes them; the loser sees `confirmed` and throws.

## Number generation (R6)

`ADJ-` + zero-padded 6-digit sequence derived from the locked max existing number, assigned at confirm. Format is internal to the service; drafts display "Assigned on confirmation".

## Test obligations (Pest, `tests/Feature/Inventory/ConfirmAdjustmentTest.php`)

- Confirms A 10→13 and B 5→2 ⇒ A on_hand +3, B on_hand −3, exactly two `adjustment` movements (+3, −3), status `confirmed`, one audit row naming the actor (mirrors spec US2 Independent Test).
- Zero-stock variant ⇒ balance row created, movement = full counted qty (FR-012).
- Zero-difference line ⇒ still one movement (`quantity = 0`), no balance change (SC-007).
- Inactive warehouse / negative result ⇒ `DomainException`, **no** movement / balance / audit row (SC-003, FR-015).
- Re-confirm and simulated concurrent confirm ⇒ refused, stock adjusted once only (FR-017).
