# Phase 0 Research: Stock Transfers

All technical context is established by the shipped foundation (FI-0), visibility (FI-2), and adjustments (FI-3) phases, so there are **no open `NEEDS CLARIFICATION` unknowns**. The decisions below record the deliberate choices this feature inherits or extends, with rationale and rejected alternatives. Each was confirmed against the existing codebase.

## D1. Mirror the FI-3 adjustment architecture

- **Decision**: Deliver the feature as (a) a `final readonly` domain service that is the sole stock writer, (b) a thin Filament resource under `App\Filament\Resources\Transfers`, (c) a spatie-data DTO for validation rules, and (d) a policy using `ChecksInventoryPermissions`. Reuse `AuditLogger`, `InteractsWithInventoryServices`, `TracksBlameable`, and the existing enums/permissions/registry wiring.
- **Rationale**: FI-3 already proved this shape against the same constitution gates (Integrity, transactions, ArchTest, 100% coverage). Reuse minimizes risk and review surface and keeps the two stock-write screens consistent.
- **Alternatives rejected**: A generic "inventory operation" abstraction unifying adjustments+transfers — premature (only two writers exist); would be an unrelated refactor (Constitution II/VI).

## D2. Two tables, paired movements, no pairing column

- **Decision**: `stock_transfers` (header: `from_warehouse_id`, `to_warehouse_id`, `transfer_number`, `notes`, `status`) + `stock_transfer_items` (`stock_transfer_id`, `product_variant_id`, `quantity`). Each confirmed line produces **two** `inventory_movements` rows sharing `source_type='transfer'` + `source_id=<transfer id>`; the sign of `quantity` (−Q at source, +Q at destination) distinguishes the two sides. No dedicated "movement pair id" column.
- **Rationale**: Matches the FI-3 free-form `source_type`/`source_id` linkage and the existing `inventory_movements` schema — no schema change to the ledger. `StockMovementsTable` already maps `'transfer'` back to `TransferResource`, so the FI-2 ledger links to the document with zero FI-2 edits.
- **Alternatives rejected**: A `movement_pair_id` self-referencing column (extra migration + backfill, unused by any query — the shared `source_id` already groups a transfer's movements); a polymorphic `morphs()` source (the codebase deliberately uses free-form string + id, not `morphTo`).

## D3. Transfer number assigned at confirmation (`TRF-%06d`)

- **Decision**: `transfer_number` is nullable while draft (list shows `number_pending` = "Assigned on confirmation"), and is generated inside `confirm()` as `sprintf('TRF-%06d', $seq)` where `$seq` derives from `MAX(transfer_number)` read under `lockForUpdate()`.
- **Rationale**: Identical to `nextAdjustmentNumber()`; keeps numbers gap-tolerant but monotonic, avoids assigning identifiers to drafts that may be discarded, and satisfies FR-003 (admin cannot supply/edit it).
- **Alternatives rejected**: Assign at draft creation (wastes numbers on discarded drafts; FI-3 chose confirm-time); DB auto-increment/sequence formatting (not portable, diverges from the established prefix convention).

## D4. Availability check — evaluated at confirm, summed per variant across duplicate lines

- **Decision** (from `/speckit-clarify`): Duplicate lines for the same variant are **allowed**. Before applying anything, group all lines by `product_variant_id`, sum their quantities, and require the source's `available_quantity` (`on_hand − reserved`, the stored column) to be ≥ that sum for every variant, all under `lockForUpdate()`. Each individual line still writes its own movement pair on confirm (lines are not merged).
- **Rationale**: Availability is the transfer's governing constraint (spec Assumptions); summing prevents two half-lines from each passing a per-line check yet jointly overdrawing the source. Per-line movements preserve the administrator's stated document structure and keep line→movement traceability.
- **Alternatives rejected**: Block duplicate variants at validation (rejected by product decision); merge duplicates into one movement pair (loses per-line traceability; the clarification chose "sum for check, keep per-line movements"); check on-hand instead of available (would relocate reserved stock — violates the Assumption).

## D5. All-lifecycle audit via observer + `saveQuietly()` on confirm

- **Decision** (from `/speckit-clarify`): Audit **every** lifecycle action. A `StockTransferObserver` (registered with `#[ObservedBy(StockTransferObserver::class)]` on the model) writes audit entries on `created` → `inventory.transfer.created`, `updated` → `inventory.transfer.edited`, `deleted` → `inventory.transfer.discarded`, `restored` → `inventory.transfer.restored`. The `confirm()` service performs its status flip with `saveQuietly()` (muting model events) and writes its own richer `inventory.transfer.confirmed` entry carrying before/after balances — so confirmation is audited **once**, not double-counted by the observer. Draft item-line changes call `$ownerRecord->touch()` in the relation-manager actions so they register as an `edited` event on the parent.
- **Rationale**: Satisfies FR-014a and Constitution VI ("sensitive actions covered by audit logging") without a fragile in-observer "is this the confirm transition?" branch. `saveQuietly()` is the idiomatic Laravel way to update without firing observers. All writes go through the single reused `AuditLogger` with `source_channel='dashboard'`.
- **Alternatives rejected**: Audit only confirm (FI-3 behavior) — contradicts the clarified requirement; audit from Filament page hooks only (misses non-UI paths and is harder to test than a model observer); let the observer also audit the confirm `updated` event and de-dupe later (produces two rows per confirm — noisy, and the two would disagree on payload richness).

## D6. Source-availability read path — `Warehouse::currentAvailable()` (ArchTest boundary)

- **Decision**: Add `Warehouse::currentAvailable(int $productVariantId): float` returning `stocks()->where('product_variant_id', $id)->value('available_quantity')` ?? 0.0. The relation manager reads source availability through this helper; the service reads/locks `InventoryStock` directly (it is a service, not the Filament layer).
- **Rationale**: `tests/Unit/ArchTest.php` forbids `App\Filament\*` from using `InventoryStock`/`InventoryMovement`, excepting only `StockLevels`/`StockMovements` (its comment already anticipates "Transfers"). Adjustments solved the identical need with `Warehouse::currentOnHand()`; `currentAvailable()` is the exact analogue for the transfer's availability display.
- **Alternatives rejected**: Add `App\Filament\Resources\Transfers` to the ArchTest exception list (weakens the integrity boundary — forbidden by Constitution VIII/"never weaken quality gates"); expose availability through the service for display (heavier than a read helper; FI-3 precedent is the model helper).

## D7. Single confirm step — no in-transit / dispatch→receive

- **Decision**: Status model is `draft → confirmed` (a two-case `TransferStatus` enum), applied as one atomic relocation. No intermediate in-transit state.
- **Rationale**: Spec Assumption and Plan Open Question #9 resolved to the single-confirm default; in-transit tracking is explicitly Out of Scope. The `stock_transfers.status` column is a string that can gain cases later without a breaking change.
- **Alternatives rejected**: Two-step dispatch→receive with an `in_transit` state and partial receipts — deferred to a future phase; would add states, a second service action, and partial-quantity accounting not needed now.

## D8. Segregation of duties via distinct existing permissions

- **Decision**: Gate prepare vs apply behind `inventory.transfer.create` and `inventory.transfer.confirm` respectively (both already defined in `InventoryPermission` and seeded by `InventoryPermissionSeeder`). `viewAny/view` → `inventory.transfer.view`. Restore maps to `inventory.transfer.create` (restoring returns a draft to the preparer's surface).
- **Rationale**: Plan Open Question #10 resolved as for adjustments; the permissions exist and are seeded (verified), so **no** seeder or enum change is needed. Policy auto-discovery binds `StockTransferPolicy` to `StockTransfer` by name.
- **Alternatives rejected**: A new `inventory.transfer.restore` permission (over-granular; restore is part of preparing); an intermediate "submitted for approval" state (adds workflow the spec explicitly excludes).

## D9. Concurrency & atomicity

- **Decision**: `confirm()` runs entirely inside `DB::transaction`; the transfer row and every touched `InventoryStock` row are read with `lockForUpdate()`; the `status == Draft` guard inside the locked transaction makes double-confirm impossible (the second waiter sees `Confirmed` and is refused).
- **Rationale**: Directly mirrors the proven FI-3 confirm; satisfies FR-011/FR-018 and the "concurrent confirm" edge case without optimistic-locking columns.
- **Alternatives rejected**: A `version`/optimistic-lock column (unnecessary given row locks); application-level mutex (weaker than a DB transaction + row lock).

## D10. Validation via `TransferData` (spatie-data)

- **Decision**: `TransferData::rules()` returns Laravel rule arrays consumed field-by-field by the Filament form (FI-3 pattern): `from_warehouse_id` required/exists, `to_warehouse_id` required/exists/`different:from_warehouse_id`, `notes` nullable string, `items` array min:1 for an applicable state, `items.*.product_variant_id` required/exists, `items.*.quantity` required/`numeric`/`gt:0`.
- **Rationale**: Keeps validation declarative and reusable, matching `AdjustmentData`/`WarehouseData`. `different` enforces FR-002 at the form layer; the service re-guards it for defense in depth.
- **Alternatives rejected**: A Laravel Form Request (Filament forms don't use them; the codebase standard for dashboard input is spatie-data `rules()`).

## Summary of resulting new/edited artifacts

New: `TransferStatus` enum, `TransferData`, `StockTransfer` + `StockTransferItem` models, two migrations, `StockTransferService`, `StockTransferObserver`, `StockTransferPolicy`, the `Transfers` Filament resource tree, two factories, two test files. Edited: `Warehouse` (add `currentAvailable()`), `lang/en/admin.php` (`inventory.transfer.*` subtree), optionally `InventoryDemoSeeder`. Confirmed **not** needed: new permissions/seeding, registry wiring, ArchTest change, FI-2 changes, `MovementType` change.
