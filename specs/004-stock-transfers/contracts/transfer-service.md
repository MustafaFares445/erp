# Contract: `StockTransferService`

The sole writer of stock as a result of a transfer. Mirrors `InventoryAdjustmentService`; diverges by moving between **two** warehouses with **paired** movements and a summed **availability** precheck.

**Location**: `app/Services/Inventory/StockTransferService.php`

## Public surface

```php
final readonly class StockTransferService
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Apply a draft transfer: relocate stock from source to destination as a
     * balanced pair of ledger movements per line, atomically.
     *
     * @throws \DomainException on any guard failure (nothing is persisted).
     */
    public function confirm(StockTransfer $transfer, User $actor): void;
}
```

Private helpers (mirroring FI-3): `applyOut(StockTransferItem, int $fromWarehouseId): array{0:float,1:float}`, `applyIn(StockTransferItem, int $toWarehouseId): array{0:float,1:float}`, `nextTransferNumber(): string`.

## Behavior (all inside one `DB::transaction`)

1. Re-fetch the transfer with `->with('fromWarehouse','toWarehouse')->lockForUpdate()->findOrFail($transfer->getKey())`.
2. **Guard** `status === Draft` else `DomainException(__('admin.inventory.transfer.errors.not_draft'))`.
3. **Guard** `from_warehouse_id !== to_warehouse_id` else `errors.same_warehouse`.
4. **Guard** both warehouses non-null and `is_active` else `errors.inactive_warehouse`.
5. Load `items()->orderBy('id')->lockForUpdate()->get()`; **guard** non-empty else `errors.no_items`.
6. **Availability precheck** — group items by `product_variant_id`, sum `quantity`; for each variant read the source `InventoryStock` row `->lockForUpdate()` (available = row `available_quantity` or 0 if no row) and **guard** `available >= summedQuantity` else `errors.insufficient_stock` (message names the variant/shortfall).
7. **Apply per line** (each line independently, preserving duplicates): decrement source stock by `quantity` (recompute `available = on_hand − reserved`), increment destination stock by `quantity` (establish row at 0 first if absent), and `InventoryMovement::query()->forceCreate([...])` **twice** — `quantity = -q` at `from_warehouse_id` and `quantity = +q` at `to_warehouse_id`; both `movement_type => MovementType::Transfer`, `source_type => 'transfer'`, `source_id => $transfer->getKey()`, `status => 'confirmed'`, `created_by => $actor->getKey()`, `notes => $transfer->notes`.
8. Assign `transfer_number = nextTransferNumber()` (`sprintf('TRF-%06d', $seq)` from `MAX` under lock), set `status = Confirmed`, `updated_by = actor`, and persist with `saveQuietly()` (so the observer does **not** emit a duplicate `edited` audit).
9. `auditLogger->log('inventory.transfer.confirmed', $transfer, oldValues: ['status'=>'draft','balances'=>before], newValues: ['status'=>'confirmed','transfer_number'=>…,'balances'=>after], actor: $actor, sourceChannel: 'dashboard')`.

## Invariants

- Never mutates anything outside the transaction; any throw ⇒ full rollback (0 movements, 0 balance changes).
- Source `available_quantity` never ends < 0.
- Σ(quantities moved out) = Σ(quantities moved in) for the transfer.
- Runs synchronously (no queue) — the operation is short and must be atomic with the request.

## Test obligations (`tests/Feature/Inventory/ConfirmTransferTest.php`)

- Confirms a draft (source available ≥ needed) ⇒ source balance −Σq, destination +Σq; exactly 2 movements per line with correct signs/warehouses; status `Confirmed`; `transfer_number` matches `/^TRF-\d{6}$/`.
- Duplicate lines for one variant ⇒ availability checked against the **sum**; each line still yields its own movement pair (movement count = 2 × line count).
- Destination has no prior stock row ⇒ row created, increased by moved quantity, arrival movement recorded.
- Insufficient source available (single line, and split-across-duplicate-lines summing over available) ⇒ `DomainException`, 0 movements, 0 balance change, status still `Draft`, no audit `confirmed` row.
- `from == to`, inactive source, inactive destination, empty items, already-confirmed ⇒ each throws the matching `DomainException`; nothing persisted.
- Re-confirm of a `Confirmed` transfer ⇒ refused; no second set of movements.
- Balanced-ledger assertion: summed out = summed in.
- Audit: exactly one `inventory.transfer.confirmed` row naming the actor, `source_channel='dashboard'`, before/after balances present.
