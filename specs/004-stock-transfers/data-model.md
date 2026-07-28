# Phase 1 Data Model: Stock Transfers

Two new tables own the transfer document; everything they act on (`inventory_stocks`, `inventory_movements`, `audit_logs`, `warehouses`, `product_variants`, `users`) already exists and is referenced read-only or written only through `StockTransferService`. Decimal quantities are `decimal(15,3)` to match the inventory columns. Migrations follow the sequence after `..._000010` → `000011`, `000012`.

## Entities

### 1. `stock_transfers`

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | bigint unsigned PK | no | auto | |
| `from_warehouse_id` | bigint unsigned FK→`warehouses` | no | — | `restrictOnDelete`; source |
| `to_warehouse_id` | bigint unsigned FK→`warehouses` | no | — | `restrictOnDelete`; destination; app-enforced ≠ source |
| `transfer_number` | string(100) | yes | null | Unique; assigned `TRF-%06d` at confirm; null while draft |
| `notes` | text | yes | null | Optional |
| `status` | string(50) | no | `'draft'` | Cast to `TransferStatus` |
| `created_by` | bigint unsigned FK→`users` | yes | null | `nullOnDelete`; set by `TracksBlameable` |
| `updated_by` | bigint unsigned FK→`users` | yes | null | `nullOnDelete` |
| `created_at` / `updated_at` | timestamps | yes | — | |
| `deleted_at` | timestamp | yes | null | `softDeletes` — reversible discard (FR-019) |

**Indexes**: `unique('transfer_number')`; index `from_warehouse_id`; index `to_warehouse_id`; index `status`; index `created_at`.

**Model** `App\Models\StockTransfer` (`final`):
- Traits: `HasFactory`, `SoftDeletes`, `TracksBlameable`. Attribute: `#[ObservedBy(StockTransferObserver::class)]`.
- Fillable (PHP-8 attribute, mirroring FI-3): `#[Fillable(['from_warehouse_id', 'to_warehouse_id', 'notes'])]` — `transfer_number` and `status` are **service-owned, non-fillable**.
- Casts: `status => TransferStatus::class`.
- Relations: `fromWarehouse(): BelongsTo<Warehouse>` (FK `from_warehouse_id`); `toWarehouse(): BelongsTo<Warehouse>` (FK `to_warehouse_id`); `items(): HasMany<StockTransferItem>`; `createdBy(): BelongsTo<User, created_by>`; `movements(): HasMany<InventoryMovement>` = `hasMany(InventoryMovement::class, 'source_id')->where('source_type', 'transfer')`.
- Helpers: `isDraft(): bool`, `isConfirmed(): bool`.

### 2. `stock_transfer_items`

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | bigint unsigned PK | no | auto | |
| `stock_transfer_id` | bigint unsigned FK→`stock_transfers` | no | — | `cascadeOnDelete` |
| `product_variant_id` | bigint unsigned FK→`product_variants` | no | — | `restrictOnDelete` |
| `quantity` | decimal(15,3) | no | — | > 0 (app-enforced); the amount to move |
| `created_at` / `updated_at` | timestamps | yes | — | |

**Indexes**: index `stock_transfer_id`; index `product_variant_id`. No unique on `(stock_transfer_id, product_variant_id)` — **duplicate variant lines are permitted** (research D4); the service sums them for the availability check.

**Model** `App\Models\StockTransferItem` (`final`):
- Traits: `HasFactory` (no soft delete).
- Fillable: `#[Fillable(['product_variant_id', 'quantity'])]`.
- Casts: `quantity => 'decimal:3'`.
- Relations: `transfer(): BelongsTo<StockTransfer>` (FK `stock_transfer_id`); `productVariant(): BelongsTo<ProductVariant>`.

## Enum

```php
// app/Enums/TransferStatus.php
enum TransferStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
```

Label/colour are rendered at the Filament layer (`Str::headline($state->value)` + `match { Draft => 'warning', Confirmed => 'success' }`), matching `AdjustmentStatus` — no methods on the enum.

`MovementType::Transfer` (value `'transfer'`) and `InventoryPermission::{TransferView,TransferCreate,TransferConfirm}` **already exist** — not redefined here.

## Referenced (read-only, owned elsewhere)

| Entity | Role in this feature | Owner |
|--------|----------------------|-------|
| `InventoryStock` (`product_variant_id`+`warehouse_id`) | Source `available_quantity` prechecked & decremented; destination `on_hand`/`available` incremented (row established at 0 if absent). Written **only** by the service. | FI-2 |
| `InventoryMovement` | Two rows per confirmed line (−Q source, +Q destination), `movement_type=Transfer`, `source_type='transfer'`, `source_id=<transfer id>`. Immutable ledger. | FI-2 |
| `Warehouse` | Source & destination; both must be active at confirm; `currentAvailable()` helper (new, additive) reads source availability for display. | FI-1 |
| `ProductVariant` | Each line references one variant (read-only catalog). | Catalog |
| `User` | `created_by`/`updated_by`; confirming actor for audit. | FI-0 |
| `AuditLog` | One row per lifecycle action via `AuditLogger`. | FI-0 |

## State transitions

```text
        create (create perm)          confirm (confirm perm, atomic)
  (none) ───────────────▶ Draft ──────────────────────────────▶ Confirmed  [immutable]
                            │  ▲
              discard (soft)│  │ restore (create perm)
                            ▼  │
                        Trashed (deleted_at set, recoverable)
```

**Guards**
- create/edit/discard/restore require `TransferStatus::Draft` (the policy denies `update`/`delete` unless `isDraft()`).
- confirm requires `Draft` + both warehouses active + `from ≠ to` + ≥1 item + summed source availability ≥ requirement per variant.
- `Confirmed` is terminal: no edit, no delete, no re-confirm (second confirm hits the `status == Draft` guard under lock).
- No transfer is ever `forceDelete`d (`forceDelete()` policy → `false`).

## Validation (`TransferData::rules()` + service guards)

| Field / rule | Form rule | Service guard (defense in depth) |
|--------------|-----------|----------------------------------|
| `from_warehouse_id` | required, exists, active | must exist & be active (`errors.inactive_warehouse`) |
| `to_warehouse_id` | required, exists, active, `different:from_warehouse_id` | `from ≠ to` (`errors.same_warehouse`); active |
| `notes` | nullable, string | — |
| `items` | array, ≥1 to be applyable | non-empty (`errors.no_items`) |
| `items.*.product_variant_id` | required, exists | — |
| `items.*.quantity` | required, numeric, `gt:0` | summed-per-variant ≤ source available (`errors.insufficient_stock`); result never < 0 |
| `transfer_number` | read-only, non-fillable | generated `TRF-%06d` at confirm |
| `status` | not user-editable | draft-only edits; single confirm (`errors.not_draft`) |

## Integrity invariants

1. **Balanced ledger** — for every confirmed transfer, Σ(source movement quantities) = −Σ(destination movement quantities); net two-warehouse change = 0 (FR-020, SC-003).
2. **Movement per stock change** — every balance change is accompanied by exactly one `inventory_movements` row (Constitution III); a confirmed line yields exactly two rows.
3. **Never negative** — no confirmation leaves any source `available_quantity` < 0 (FR-013, SC-009); enforced by the summed availability precheck under lock.
4. **Atomic** — all lines' movements + both balance updates + number assignment + audit commit together or not at all (FR-011, SC-005).
5. **Immutable once confirmed** — no field of a confirmed transfer changes; the record is never physically deleted (FR-017, Constitution III).
6. **Single writer** — `inventory_stocks`/`inventory_movements` are mutated only inside `StockTransferService::confirm()`; the Filament layer never touches them (ArchTest).
7. **Availability, not on-hand, governs** — the precheck uses `available_quantity` (`on_hand − reserved`); reserved stock is not transferable.
8. **Every lifecycle action audited** — create, edit, discard, restore each write one `audit_logs` row; confirm writes one with before/after balances (FR-014a).
