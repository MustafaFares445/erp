# Phase 1 Data Model: Stock Adjustments (FI-3)

Derived from ERD §6 (`inventory_adjustments`, `inventory_adjustment_items`, `audit_logs`) and the spec Key Entities. New tables/models are **additive** and ERD-faithful (Complexity Tracking in [plan.md](plan.md)). Quantities are `decimal(15,3)`; status/number are portable `string` columns; audit before/after are `json` (research R12).

## Entities

### 1. `inventory_adjustments` → `App\Models\InventoryAdjustment`

A document correcting stock in one warehouse. Editable only while `draft`; immutable once `confirmed`; soft-deleted (recoverable), never hard-deleted.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned (PK) | No | auto | |
| `warehouse_id` | bigint unsigned (FK → warehouses) | No | | `restrictOnDelete` (warehouse referenced ⇒ cannot delete, FI-1) |
| `adjustment_number` | varchar(100), **unique** | Yes* | null | System-owned; assigned at **confirm** (R6). Nullable while draft, set + unique once confirmed. |
| `reason` | text | No | | Required (FR-001/FR-008) |
| `status` | varchar(50) | No | `'draft'` | Cast to `AdjustmentStatus` enum; `draft` → `confirmed` only |
| `created_by` | bigint unsigned (FK → users) | Yes | null | `nullOnDelete`; preparer |
| `updated_by` | bigint unsigned (FK → users) | Yes | null | `nullOnDelete` |
| `created_at` / `updated_at` | timestamp | No | now | |
| `deleted_at` | timestamp | Yes | null | Soft delete (drafts only, FR-018) |

*`adjustment_number` is nullable at the column level so drafts need no number; a **unique** index still forbids two confirmed adjustments sharing one. Uniqueness of NULLs is allowed by all target engines.

**Indexes**: PK; index `warehouse_id`, `status`, `created_at`; unique `adjustment_number`.

**Model**:
- `use HasFactory, SoftDeletes;` `final class`.
- `casts()`: `status => AdjustmentStatus::class`.
- Relations: `warehouse(): BelongsTo<Warehouse>`, `items(): HasMany<InventoryAdjustmentItem>`, `createdBy(): BelongsTo<User, created_by>`.
- Helpers: `isDraft(): bool` (`status === AdjustmentStatus::Draft`), `isConfirmed(): bool`.
- Fillable: `warehouse_id`, `reason` (draft-editable via Filament). **Not** fillable: `adjustment_number`, `status`, `created_by` (service/observer-owned).

### 2. `inventory_adjustment_items` → `App\Models\InventoryAdjustmentItem`

One line per product variant within an adjustment.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned (PK) | No | auto | |
| `inventory_adjustment_id` | bigint unsigned (FK) | No | | `cascadeOnDelete` (child of the document) |
| `product_variant_id` | bigint unsigned (FK → product_variants) | No | | `restrictOnDelete` |
| `old_quantity` | decimal(15,3) | No | 0 | Balance **before**; authoritative value written at confirm from the live locked balance (R7) |
| `new_quantity` | decimal(15,3) | No | | Operator-entered counted qty; `>= 0` (FR-005) |
| `difference` | decimal(15,3) | No | 0 | `new_quantity − old_quantity`; computed, never entered (FR-004); finalized at confirm |
| `created_at` / `updated_at` | timestamp | No | now | |

**Indexes**: PK; index `inventory_adjustment_id`, `product_variant_id`.

**Model**:
- `use HasFactory;` `final class` (no soft delete — lifecycle follows the parent).
- `casts()`: `old_quantity`, `new_quantity`, `difference` → `decimal:3`.
- Relations: `adjustment(): BelongsTo<InventoryAdjustment>`, `productVariant(): BelongsTo<ProductVariant>`.
- Fillable: `product_variant_id`, `new_quantity` (draft-editable). **Not** fillable: `old_quantity`, `difference` (derived at confirm).

### 3. `audit_logs` → `App\Models\AuditLog` (introduced by FI-3)

Immutable trace of a sensitive action. Written **only** by `AuditLogger` (research R10); no Filament write path.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned (PK) | No | auto | |
| `actor_user_id` | bigint unsigned (FK → users) | Yes | null | `nullOnDelete`; who confirmed |
| `action` | varchar(100) | No | | e.g. `inventory.adjustment.confirmed` |
| `entity_type` | varchar(150) | No | | `InventoryAdjustment::class` |
| `entity_id` | bigint unsigned | Yes | null | Adjustment id |
| `old_values` | json | Yes | null | `{status, items:[{variant_id, old_quantity}]}` |
| `new_values` | json | Yes | null | `{status, adjustment_number, items:[{variant_id, new_quantity, difference}]}` |
| `source_channel` | varchar(50) | Yes | null | `'dashboard'` here |
| `ip_address` | varchar(50) | Yes | null | Request IP if available |
| `created_at` / `updated_at` | timestamp | No | now | |

**Indexes**: PK; index `actor_user_id`, `action`, `['entity_type','entity_id']`, `created_at`.

**Model**:
- `use HasFactory;` `final class` (no soft delete; audit rows are permanent).
- `casts()`: `old_values => 'array'`, `new_values => 'array'`.
- Relation: `actor(): BelongsTo<User, actor_user_id>`.

## Enum: `App\Enums\AdjustmentStatus`

```
enum AdjustmentStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
```

Drives the status badge (draft = gray/warning, confirmed = success) and the transition guard. Kept in `app/Enums` (repo arch preset). No `pending` case — the workflow is single-confirm (spec Assumption; plan Open Question #10 resolved).

## Referenced (read-only, owned elsewhere)

| Entity | Owner | Use here |
|---|---|---|
| `Warehouse` (`warehouses`) | FI-1 | Target of the adjustment; must be active at confirm (FR-015). `restrictOnDelete` from adjustments. |
| `ProductVariant` (`product_variants`) | FI-2 catalog stub / catalog spec 005 | Each item's variant; SKU + name shown read-only. |
| `InventoryStock` (`inventory_stocks`) | FI-2 | Balance updated by `confirm()` (source of truth `(variant, warehouse)`); read-only in Filament. |
| `InventoryMovement` (`inventory_movements`) | FI-2 | One `adjustment` movement per item written by `confirm()`; immutable ledger. |
| `User` (`users`) | FI-0 | `created_by`, `actor_user_id`. |

## State transitions

```
        create/edit/delete (draft only)
        ┌───────────────────────────┐
        ▼                           │
   ┌─────────┐   confirm()      ┌───────────┐
   │  draft  │ ───────────────▶ │ confirmed │  (immutable; no further transitions)
   └─────────┘   (service,      └───────────┘
        │         transaction)
        │ soft delete (recoverable, FR-018)
        ▼
   (deleted_at set)
```

**Guards**:
- `confirm()` requires `status === draft`, re-checked under `lockForUpdate()` inside the transaction (R3) → double/concurrent confirm refused (FR-017).
- Update/delete allowed only while `draft` (policy + Filament visibility). Confirmed ⇒ edit/delete hidden and refused (FR-016).
- No hard delete anywhere (`forceDelete` → false in policy).

## Validation (`App\Data\Inventory\AdjustmentData`, research R11)

| Field | Rule | Requirement |
|---|---|---|
| `warehouse_id` | required, exists:warehouses, warehouse `is_active` | FR-001, FR-015 |
| `reason` | required, string, max 1000 | FR-001, FR-008 |
| `items` | required, array, min:1 | FR-008 |
| `items.*.product_variant_id` | required, exists:product_variants | FR-003 |
| `items.*.new_quantity` | required, numeric, min:0 | FR-005 |

`old_quantity` and `difference` are **not** user inputs (FR-004/FR-007); they are derived/read-only.

## Integrity invariants (enforced in `InventoryAdjustmentService`, verified by tests)

1. Confirming writes **exactly one** movement per item line (incl. zero-difference lines) — never more, never fewer (SC-002/SC-007, R9).
2. Each affected balance changes by exactly that line's `difference`; `available = on_hand − reserved` (R8).
3. The movement(s), balance update(s), and audit row are one transaction — a failure leaves **zero** of them (SC-003, atomicity).
4. A confirmed adjustment cannot be re-confirmed, edited, deleted, or hard-deleted (SC-004).
5. A variant with no balance row is treated as `old_quantity = 0`; a row is established on confirm (FR-012).
6. Resulting `on_hand_quantity` may not go negative (default domain rule, R8) — else the whole confirm is abandoned with a clear message (FR-015).
