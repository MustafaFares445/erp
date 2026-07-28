---

# Tasks: Stock Transfers (FI-4)

**Input**: Design documents from `specs/004-stock-transfers/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/)

**Tests**: INCLUDED — mandated by the constitution (Principle VI, and Principle III is NON-NEGOTIABLE for the stock-mutating confirm path), the spec success criteria (SC-001…SC-010), and the `composer test` gate (100% type + code coverage). Write each story's test task first and confirm it fails before implementing.

**Organization**: Grouped by user story (US1 P1, US2 P2, US3 P3) so each is independently implementable and testable on the shared schema. US1 is the MVP (a draft that changes nothing); US2 adds the sole write path (confirm) with paired movements; US3 hardens immutability, segregation of duties, and the recoverable-draft (trashed/restore) lifecycle.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1 / US2 / US3 for story-phase tasks; Setup / Foundational / Polish carry no story label

## Path Conventions

Single Laravel application. Source under `app/`, migrations/seeders/factories under `database/`, translations under `lang/`, tests under `tests/` (Pest). Paths below are repo-relative. The Filament resource uses the nested directory layout matching the `AdminModuleRegistry` namespace `App\Filament\Resources\Transfers` it **already imports** (no registry edit — research D1/D8). New migrations continue the FI-3 sequence (`000011`, `000012`) and MUST be engine-agnostic (Blueprint only; `decimal(15,3)` quantities; `string` status/number; no native enums).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm a clean starting point. FI-0/FI-1/FI-2/FI-3 are committed and — critically for this feature — the transfer scaffolding is already wired (verified in research): the seeded `inventory.transfer.view|create|confirm` permissions (`App\Enums\InventoryPermission` + `InventoryPermissionSeeder`), the `MovementType::Transfer` enum case, the `AdminModuleRegistry` entry importing `App\Filament\Resources\Transfers\TransferResource` (as a `::class` string — no autoload), the `StockMovementsTable` back-link resolver mapping `'transfer' → TransferResource` and its badge colour, and the `admin.resources.transfers` label all exist. The `ChecksInventoryPermissions` policy trait, the `InteractsWithInventoryServices` Filament concern, the `AuditLogger` service, the `TracksBlameable` model concern, and the `ArchTest` guard (whose comment already anticipates "Transfers") are all present.

- [X] T001 Confirm a green baseline: run `php artisan test --compact` and record that the existing FI-0…FI-3 suites pass (incl. `ArchTest`, `AdjustmentResourceTest`, `ConfirmAdjustmentTest`, `InventoryPermissionSeederTest`). Verify `InventoryPermission` already exposes `TransferView/TransferCreate/TransferConfirm` and `MovementType` has `Transfer`. No files changed in this task. **Result**: 129/129 passed; both enums confirmed present.

**Checkpoint**: Baseline green and transfer scaffolding confirmed present — foundational work can begin.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Create the shared schema, enum, models, factories, lifecycle-audit observer, the source-availability read helper, and the authorization policy that ALL three user stories depend on. Table shapes are verbatim from [data-model.md](data-model.md).

**⚠️ CRITICAL**: Blocks US1, US2, and US3. No resource/service/UI work may begin until this phase completes.

- [X] T002 [P] Create backed string enum `App\Enums\TransferStatus` with cases `Draft='draft'`, `Confirmed='confirmed'` in `app/Enums/TransferStatus.php` (data-model §Enum; single-confirm workflow — research D7; no in-transit case). Label/colour are rendered at the Filament layer, no methods on the enum.
- [X] T003 [P] Create migration for `stock_transfers` (`from_warehouse_id` FK→warehouses `restrictOnDelete`; `to_warehouse_id` FK→warehouses `restrictOnDelete`; `transfer_number` string(100) **nullable + unique**; `notes` text nullable; `status` string(50) default `'draft'`; timestamps; `created_by`/`updated_by` FK→users `nullOnDelete`; `deleted_at`; index `from_warehouse_id`,`to_warehouse_id`,`status`,`created_at`) in `database/migrations/2026_07_22_000011_create_stock_transfers_table.php` (data-model §1)
- [X] T004 [P] Create migration for `stock_transfer_items` (`stock_transfer_id` FK `cascadeOnDelete`; `product_variant_id` FK→product_variants `restrictOnDelete`; `quantity` decimal(15,3); timestamps; index the two FKs; **no** unique on `(stock_transfer_id, product_variant_id)` — duplicate variant lines are permitted, research D4) in `database/migrations/2026_07_22_000012_create_stock_transfer_items_table.php` (data-model §2)
- [X] T005 [P] Create `App\Observers\StockTransferObserver` (constructor-injects `AuditLogger`) with `created`→`inventory.transfer.created`, `updated`→`inventory.transfer.edited`, `deleted`→`inventory.transfer.discarded`, `restored`→`inventory.transfer.restored`, each calling `AuditLogger::log(<action>, $transfer, oldValues, newValues, auth()->user(), 'dashboard')` in `app/Observers/StockTransferObserver.php` (contracts/audit-log.md; reuses the existing `AuditLogger` — no new audit infrastructure)
- [X] T006 [P] Create `App\Models\StockTransfer` (`final`; `HasFactory` + `SoftDeletes` + `App\Models\Concerns\TracksBlameable`; `#[ObservedBy(StockTransferObserver::class)]`; `casts(): status => TransferStatus::class`; relations `fromWarehouse()`/`toWarehouse()` BelongsTo Warehouse via `from_warehouse_id`/`to_warehouse_id`, `items()` HasMany, `createdBy()` BelongsTo User via `created_by`, `movements()` = `hasMany(InventoryMovement::class,'source_id')->where('source_type','transfer')`; helpers `isDraft()`/`isConfirmed()`; fillable `from_warehouse_id`,`to_warehouse_id`,`notes` only — NOT `transfer_number`/`status`) in `app/Models/StockTransfer.php` (data-model §1; depends on T002 enum + T005 observer)
- [X] T007 [P] Create `App\Models\StockTransferItem` (`final`; `HasFactory`; no soft delete; `casts(): quantity => 'decimal:3'`; relations `transfer()` BelongsTo, `productVariant()` BelongsTo; fillable `product_variant_id`,`quantity`) in `app/Models/StockTransferItem.php` (data-model §2)
- [X] T008 [P] Create `StockTransferFactory` with a default `draft` state (`transfer_number => null`, `status => Draft`, distinct `from_warehouse_id`/`to_warehouse_id`, nullable `notes`) and a `confirmed()` state (sets `transfer_number => 'TRF-'+padded seq`, `status => Confirmed`) in `database/factories/StockTransferFactory.php`
- [X] T009 [P] Create `StockTransferItemFactory` (FK factories for parent + variant; realistic `quantity` `randomFloat(3, 0.1, 50)`) in `database/factories/StockTransferItemFactory.php`
- [X] T010 Add `currentAvailable(int $productVariantId): float` to `App\Models\Warehouse` returning `stocks()->where('product_variant_id', $productVariantId)->value('available_quantity')` cast to float (`0.0` when no row) in `app/Models/Warehouse.php` — the ArchTest-safe read path the relation manager uses for source availability (research D6; exact analogue of the existing `currentOnHand()`)
- [X] T011 Create `App\Policies\StockTransferPolicy` using `ChecksInventoryPermissions` with `inventoryPermissionMap()`: `viewAny`/`view` → `TransferView`; `create`/`restore` → `TransferCreate`; `update`/`delete` → `TransferCreate` **but return false when `status === Confirmed`** (draft-only, FR-017/FR-019); custom `confirm(User, StockTransfer)` → `TransferConfirm` **and** `$transfer->isDraft()` (FR-022/FR-023); `forceDelete()` → **always false** (no hard delete, FR-019) in `app/Policies/StockTransferPolicy.php` (contracts/authorization.md — auto-discovered by Laravel, no registration; depends on T006 model)

**Checkpoint**: `php artisan migrate` succeeds; PHPStan type-checks the two models + enum + observer + policy + the `Warehouse` edit; the existing `ArchTest` stays green (its guard already bans `App\Filament\Resources\Transfers` from `InventoryStock`/`InventoryMovement` — research D6, no edit). User stories can now proceed.

---

## Phase 3: User Story 1 - Prepare a stock transfer as a draft (Priority: P1) 🎯 MVP

**Goal**: A permitted admin creates/edits a **draft** transfer — a source warehouse, a *different* active destination warehouse, optional notes, and one or more variant lines (quantity > 0) — that changes **no** stock balance and **no** ledger entry. Add/edit/remove lines and save/reopen freely; each save is audited.

**Independent Test**: As an admin with `inventory.transfer.create`, create a draft from warehouse A to a different warehouse B with notes and two variant lines, edit one line, remove another, save and reopen — then assert `inventory_stocks` and `inventory_movements` are entirely unchanged, the transfer has status `draft` with no number, selecting A as both source and destination is rejected, a zero/negative quantity is rejected, and an `inventory.transfer.created` audit row exists (SC-001, FR-001…FR-007).

### Tests for User Story 1 ⚠️ (write first, confirm they fail)

- [X] T012 [US1] Create `tests/Feature/Filament/TransferResourceTest.php` covering: create draft (source + different destination + ≥1 item) saved as `draft` with **no** `transfer_number` and **zero** stock/movement change (SC-001, FR-006); validation rejects same source/destination (`different`, FR-002), missing source/destination/items for an applyable state (FR-007), and a line `quantity` ≤ 0 (FR-004); `created_by` set to the acting admin (via `TracksBlameable`); the index and record URLs 403 without the matching `inventory.transfer.*` permission (FR-024); creating a draft writes one `inventory.transfer.created` audit row and editing writes an `inventory.transfer.edited` row (FR-014a). Matches [contracts/transfer-resource.md](contracts/transfer-resource.md) + [contracts/audit-log.md](contracts/audit-log.md). (depends on Foundational)

### Implementation for User Story 1

- [X] T013 [P] [US1] Create `App\Data\Inventory\TransferData` (spatie/laravel-data) holding the rule set via static `rules()`: `from_warehouse_id` required + exists + active; `to_warehouse_id` required + exists + active + `different:from_warehouse_id`; `notes` nullable string max 1000; `items` array min:1; `items.*.product_variant_id` required + exists; `items.*.quantity` required + numeric + `gt:0` in `app/Data/Inventory/TransferData.php` (data-model §Validation; research D10; matches sibling `AdjustmentData`/`WarehouseData`)
- [X] T014 [P] [US1] Create `Schemas/TransferForm.php`: `from_warehouse_id` Select (active warehouses, required, `->live()`); `to_warehouse_id` Select (active warehouses, required, **excludes the chosen source** via `disableOptionWhen`, carries the `different` rule); `transfer_number` read-only placeholder showing `number_pending` while draft; `notes` Textarea; whole form `->disabled(fn (?StockTransfer $r) => $r?->isConfirmed() ?? false)` — rules sourced from `TransferData::rules()` in `app/Filament/Resources/Transfers/Schemas/TransferForm.php` (contracts/transfer-resource.md §Form)
- [X] T015 [P] [US1] Create `Tables/TransfersTable.php`: `defaultSort('created_at','desc')`; columns `transfer_number` (searchable, placeholder `number_pending`), source `fromWarehouse.code` → destination `toWarehouse.code`, `status` badge (`Str::headline`; draft = warning, confirmed = success), `items_count` (`->counts('items')`), creator name (default "System"), `created_at`; filters `status`, `from_warehouse_id`, `to_warehouse_id`, `created_at` date range (FR-025) in `app/Filament/Resources/Transfers/Tables/TransfersTable.php` (TrashedFilter + row-action gating added in US3)
- [X] T016 [US1] Create `RelationManagers/TransferItemsRelationManager.php`: `product_variant_id` Select (SKU + name, required, `->live()`); `quantity` numeric required with rule `gt:0`; a read-only "available at source" display via `->state(fn () => $this->fromWarehouse()->currentAvailable((int) $variantId))` (uses the T010 helper — **never** reads `InventoryStock`, ArchTest); whole schema `->disabled(fn () => ! $this->transfer()->isDraft())`; header/row/bulk actions gated on parent `isDraft()`; each create/edit/delete action `->after(fn () => $this->transfer()->touch())` so line edits register as an `edited` audit (FR-014a); private helpers `transfer()`/`fromWarehouse()`; touches no stock (FR-006) in `app/Filament/Resources/Transfers/RelationManagers/TransferItemsRelationManager.php` (contracts/transfer-resource.md §Relation manager)
- [X] T017 [US1] Create `TransferResource` + its four pages (`ListTransfers`, `CreateTransfer`, `EditTransfer`, `ViewTransfer`) wiring the form/table/items relation manager, the `admin.groups.inventory` nav group, `getRecordRouteBindingEloquentQuery()` stripping `SoftDeletingScope`, and model binding; `CreateTransfer` leaves `status` at its `draft` default and `transfer_number` null (`created_by` via `TracksBlameable`); `ListTransfers` header action `CreateAction` in `app/Filament/Resources/Transfers/TransferResource.php` and `app/Filament/Resources/Transfers/Pages/{ListTransfers,CreateTransfer,EditTransfer,ViewTransfer}.php` (no `AdminModuleRegistry` edit — already wired, research D1)
- [X] T018 [US1] Add the `inventory.transfer.*` translation block to `lang/en/admin.php` mirroring the `adjustment` subtree — `transfer_number`, `number_pending` ("Assigned on confirmation"), `status`, `items_count`, `quantity`, `available`, `confirm`, `notifications.confirmed`, `errors.not_draft`, `errors.inactive_warehouse`, `errors.no_items`, **`errors.same_warehouse`**, **`errors.insufficient_stock`** in `lang/en/admin.php` (contracts/transfer-service.md §Behavior; `admin.resources.transfers` already present)

**Checkpoint**: US1 is fully functional and testable independently — an admin can prepare/edit a draft that changes nothing, with same-warehouse and quantity validation enforced and lifecycle audit on create/edit; `TransferResourceTest` (draft + validation + permission + create/edit audit cases) passes; `ArchTest` still green.

---

## Phase 4: User Story 2 - Apply the transfer so both warehouses update together (Priority: P2)

**Goal**: A permitted admin confirms a draft. In one all-or-nothing transaction the system checks the source has enough *available* stock for every line (summed per variant across duplicate lines), then per line writes a negative movement out of the source and a positive movement into the destination, updates both `(variant, warehouse)` balances by exactly that quantity, assigns the transfer number, marks the document `confirmed`, and writes one `inventory.transfer.confirmed` audit row with before/after balances. Results appear immediately on the FI-2 read-only stock/movement screens.

**Independent Test**: With a draft moving quantity 4 of variant A from warehouse X (available ≥ 4) to warehouse Y, confirm it; assert X available −4, Y +4, exactly two linked `transfer` movements (−4 at X, +4 at Y), the transfer `confirmed` with a unique `TRF-######` number, and one `audit_logs` row naming the confirming user (SC-002/SC-003/SC-007, mirrors spec US2 Independent Test).

### Tests for User Story 2 ⚠️ (write first, confirm they fail)

- [X] T019 [US2] Create `tests/Feature/Inventory/ConfirmTransferTest.php` covering: confirm qty 4 X→Y ⇒ exact balance deltas + exactly two signed `transfer` movements sharing `source_type='transfer'`+`source_id` + `available = on_hand − reserved` recomputed at both warehouses (SC-002); **movement count == 2 × line count**, including two lines for the **same** variant whose availability is checked against the **sum** while each line still emits its own pair (FR-009a, research D4); a variant with no destination balance is established at 0 then increased (FR-012); insufficient source available (single line, and summed duplicate lines exceeding available) ⇒ `DomainException(insufficient_stock)` with **0** movements, **0** balance change, **0** `confirmed` audit rows, status still `draft` (SC-004/SC-005, FR-009/FR-016); same-warehouse / inactive source / inactive destination / empty items / already-confirmed each throw the matching `DomainException` and roll back (FR-002/FR-016/FR-017); balanced-ledger assertion Σout = −Σin (SC-003, FR-020); one `audit_logs` row `action='inventory.transfer.confirmed'`, `actor_user_id`, `source_channel='dashboard'`, before/after balances (SC-007, FR-014). Matches [contracts/transfer-service.md](contracts/transfer-service.md) + [contracts/audit-log.md](contracts/audit-log.md). (depends on Foundational)

### Implementation for User Story 2

- [X] T020 [US2] Create `App\Services\Inventory\StockTransferService` (`final readonly`, constructor-injects `AuditLogger`) with `confirm(StockTransfer $transfer, User $actor): void` running one `DB::transaction`: reload with `->with('fromWarehouse','toWarehouse')->lockForUpdate()` and guard `status === Draft` else `DomainException(not_draft)` (FR-018); guard `from_warehouse_id !== to_warehouse_id` (`same_warehouse`, FR-002) and both warehouses active (`inactive_warehouse`, FR-016); load `items()->orderBy('id')->lockForUpdate()`, guard non-empty (`no_items`, FR-007); **availability precheck** — group items by variant, sum `quantity`, lock/read source `InventoryStock` and guard `available_quantity >= summed` else `insufficient_stock` (FR-009/FR-009a/FR-013); per line — decrement source stock and increment destination stock (create the destination row at 0 if absent, FR-012), recompute `available = on_hand − reserved`, and `InventoryMovement::forceCreate` **twice** (`quantity=-q` at source, `quantity=+q` at destination; both `movement_type=Transfer`, `source_type='transfer'`, `source_id=$transfer->id`, `status='confirmed'`, `created_by=$actor->id`, `notes=$transfer->notes`) (FR-010/FR-011); assign `transfer_number` = `sprintf('TRF-%06d', $seq)` from the locked max (FR-003), set `status=Confirmed`+`updated_by`, persist with **`saveQuietly()`** (so the observer does not emit a duplicate `edited` audit — research D5); call `AuditLogger::log('inventory.transfer.confirmed', $transfer, oldValues incl. before-balances, newValues incl. after-balances + number, $actor, 'dashboard')` inside the transaction in `app/Services/Inventory/StockTransferService.php` (contracts/transfer-service.md — the NON-NEGOTIABLE integrity core; depends on Foundational)
- [X] T021 [US2] Add the **Confirm** action to `ViewTransfer` as a thin adapter: `use App\Filament\Concerns\InteractsWithInventoryServices`; the action is `->authorize(fn (StockTransfer $record) => auth()->user()?->can('confirm', $record) ?? false)`, `->visible(fn (StockTransfer $record) => $record->isDraft() && (auth()->user()?->can('confirm', $record) ?? false))`, `->requiresConfirmation()`, and on run calls `runInventoryOperation(fn () => app(StockTransferService::class)->confirm($record, $actor), 'admin.inventory.transfer.notifications.confirmed')` — computing nothing itself (arch guard) in `app/Filament/Resources/Transfers/Pages/ViewTransfer.php` (contracts/transfer-resource.md §Confirm action; contracts/authorization.md; depends on T020)
- [X] T022 [US2] Create `Schemas/TransferInfolist.php` for the View page: header (number, status badge, source→destination, notes, creator, timestamps); item lines (variant SKU/name, quantity); and — once confirmed (`->visible(fn (StockTransfer $r) => $r->isConfirmed())`) — a **read-only** `RepeatableEntry` of the resulting `movements` with each row linking to the FI-2 `StockMovementResource::getUrl('view', ...)` (importing the resource class is allowed; the ledger model is not — FR-015, ArchTest) in `app/Filament/Resources/Transfers/Schemas/TransferInfolist.php`

**Checkpoint**: US1 AND US2 both work — a draft can be prepared and confirmed; `ConfirmTransferTest` passes (paired movements, dual-balance, summed-duplicate availability, atomic rollback, balanced ledger, audit); confirmed balances/movements are visible on the FI-2 screens and link back to the transfer.

---

## Phase 5: User Story 3 - Trust that applied transfers are balanced, integrity-safe, and immutable (Priority: P3)

**Goal**: Confirmed transfers are final — no edit, delete, or re-apply; a domain failure during confirm leaves nothing changed at either warehouse; the ledger stays balanced. Drafts are discarded reversibly and **restorable through the UI** (trashed view + restore control). Prepare vs apply are separately permissioned, and every lifecycle action is audited.

**Independent Test**: Confirm a transfer and verify Edit/Delete/Confirm controls are absent and the policy refuses `update`/`delete`; attempt a second confirm and verify it is refused with stock moved only once; discard a separate draft, find it under the Trashed filter, restore it to an editable draft, and verify no stock ever changed and `discarded`/`restored` audit rows exist; with a `create`-only admin verify no Confirm control appears and a direct confirm is refused even for their own draft (SC-005/SC-006/SC-008/SC-009, FR-017…FR-023, FR-014a).

### Tests for User Story 3 ⚠️ (write first, confirm they fail)

- [X] T023 [US3] Extend `tests/Feature/Filament/TransferResourceTest.php` and `tests/Feature/Inventory/ConfirmTransferTest.php` with integrity/lifecycle cases: a confirmed transfer hides Edit/Delete/Confirm actions and `StockTransferPolicy` `update`/`delete` return false (FR-017), `forceDelete` always false (FR-019); re-confirm and a simulated concurrent confirm (second call on a `confirmed`/locked record) are refused and stock is moved **once** only (FR-018); a `create`-only admin (no `confirm`) sees no Confirm action and a direct confirm is refused for their own draft (FR-023, SC-008); a soft-deleted draft appears under the `TrashedFilter`, is restored via `RestoreAction` to an editable `draft` with zero stock change, and is never hard-deleted (FR-019/FR-019a); discarding writes an `inventory.transfer.discarded` audit row and restoring writes `inventory.transfer.restored` (FR-014a). (depends on US1 + US2 implementation)

### Implementation for User Story 3

- [X] T024 [US3] Wire immutability + the trashed/restore UI into the Filament layer: in `Tables/TransfersTable.php` gate `EditAction`/`DeleteAction` on `fn ($record) => $record->isDraft()`, add a `TrashedFilter`, and add `RestoreAction` (`->visible(fn ($record) => $record->trashed())`) + `RestoreBulkAction` with **no** `ForceDeleteAction`; in `EditTransfer` add header `DeleteAction` (draft-only) + `RestoreAction` and block/redirect when `status === confirmed`; ensure the resource query exposes trashed drafts so discards are recoverable (FR-017/FR-019/FR-019a) — all authorization still delegated to `StockTransferPolicy` (no forked checks) in `app/Filament/Resources/Transfers/Tables/TransfersTable.php` and `app/Filament/Resources/Transfers/Pages/EditTransfer.php` (contracts/authorization.md; contracts/transfer-resource.md §Table/§Pages)

**Checkpoint**: All three stories work independently. Confirmed transfers are immutable and single-application with a balanced ledger; drafts are recoverable through the UI; segregation of duties holds; every lifecycle action is audited. Full `TransferResourceTest` + `ConfirmTransferTest` green.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Quality gates and optional dev ergonomics across the feature.

- [X] T025 [P] (Optional, dev only) Edit `database/seeders/InventoryDemoSeeder.php` to add a sample **draft** transfer (source + different destination + two variant lines with stock available at the source) for manual smoke testing — must not auto-confirm
- [X] T026 Run `vendor/bin/pint --dirty --format agent` and fix any formatting on all new/edited files
- [X] T027 Run `vendor/bin/rector` (dry-run first) and apply safe suggestions to the new files, keeping behavior unchanged
- [X] T028 Run `vendor/bin/phpstan analyse` and resolve findings on the new files **without** adding baseline entries (baseline may only shrink — CLAUDE.md rule 7)
- [X] T029 Run the full `composer test` gate (Pint check + Rector dry-run + PHPStan + Pest incl. `ArchTest`, 100% type + 100% code coverage) and confirm green; fix any coverage gaps on new files
- [X] T030 Execute [quickstart.md](quickstart.md) scenarios A–E end-to-end (prepare a draft → confirm with sufficient stock → verify balances/paired movements/audit on the FI-2 screens → attempt confirm with insufficient stock → attempt edit/re-confirm → discard + restore a draft) and record the result. **Result**: All five scenarios (A–E) are exercised by the automated suite (`TransferResourceTest` + `ConfirmTransferTest`, 30/30 passing) — draft-changes-nothing, confirm produces exact paired-movement/dual-balance/audit results, insufficient-stock rejection with zero side effects, immutability/re-confirm-refusal/segregation-of-duties, and discard→trashed-filter→restore all verified. Live browser click-through against `https://ierp.test/admin` was attempted but navigation was denied in this session (same limitation recorded for FI-3); demo data is seeded (`InventoryDemoSeeder`, including a sample draft transfer with real available stock at the source) for a future manual pass.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately.
- **Foundational (Phase 2)**: Depends on Setup — **BLOCKS all user stories**. Migrate + enum + models + observer + `Warehouse` helper + policy must exist first.
- **User Stories (Phase 3–5)**: All depend on Foundational. In priority order P1 → P2 → P3; US2 depends on US1's `TransferResource`/`ViewTransfer` existing (it adds the Confirm action + Infolist); US3 depends on US1 + US2 (it hardens their resource/service and adds the trashed/restore UI).
- **Polish (Phase 6)**: Depends on all desired stories being complete.

### User Story Dependencies

- **US1 (P1)**: Foundational only. Delivers the inert, audited draft — the MVP.
- **US2 (P2)**: Foundational + US1 (extends `TransferResource`/`ViewTransfer`). Adds the sole write path (paired movements + availability check).
- **US3 (P3)**: Foundational + US1 + US2. Verifies/gates immutability, atomicity, balanced ledger, and segregation already built into the policy (Foundational) and service (US2), and adds the trashed/restore UI.

### Within Each User Story

- Tests are written first and MUST fail before implementation.
- Foundational enum/models/observer/policy before the service; the service before the Filament action that adapts it.
- Story complete and its checkpoint green before moving to the next priority.

### Parallel Opportunities

- All Foundational tasks marked [P] (T002–T009) can run together; **T006 model** waits only on T002 (enum) + T005 (observer, for the `#[ObservedBy]` attribute); **T010** edits `Warehouse` and **T011 policy** waits only on the model it type-hints.
- US1: T013 (Data), T014 (Form), T015 (Table) are [P]; T016 (items RM) and T017 (resource + pages) follow; T018 (lang) is independent.
- US2: T020 (service) precedes T021 (confirm action) and shares the View page with T022 (infolist).
- Different developers can take US1 → US2 → US3 as a relay once Foundational is done.

---

## Parallel Example: Foundational (Phase 2)

```bash
# After T001, launch the independent schema/enum/observer/factory tasks together:
Task: "Create App\Enums\TransferStatus in app/Enums/TransferStatus.php"
Task: "Create migration stock_transfers (000011)"
Task: "Create migration stock_transfer_items (000012)"
Task: "Create App\Observers\StockTransferObserver"
Task: "Create App\Models\StockTransferItem"
# Then T006 model (needs T002 enum + T005 observer), T008/T009 factories (need models),
# T010 Warehouse helper, and T011 policy (needs the model).
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational — blocks everything).
2. Complete Phase 3 (US1): the draft screen that changes nothing.
3. **STOP and VALIDATE**: prepare/edit a draft, confirm zero stock/ledger change and same-warehouse/quantity validation (SC-001).
4. Demo the inert draft flow.

### Incremental Delivery

1. Setup + Foundational → schema, models, observer, policy ready.
2. US1 → inert audited draft → validate → demo (MVP).
3. US2 → confirm writes the paired movements + both balances + audit atomically, gated by the source-availability check → validate `ConfirmTransferTest` → demo the first real relocation.
4. US3 → immutability, single-application, segregation, balanced ledger, recoverable/restorable drafts → validate → demo.
5. Polish → Pint/Rector/PHPStan/`composer test` green + quickstart.

### Constitution guardrails (do not weaken to pass CI)

- Principle III (NON-NEGOTIABLE): every stock change flows through `StockTransferService::confirm()`, creating a movement per side and updating both `(variant, warehouse)` balances in one transaction; the FI-0 arch guard forbids `App\Filament\Resources\Transfers` from touching the ledger — reads go through `Warehouse::currentAvailable()`.
- A confirmed transfer keeps the ledger balanced (Σ out = −Σ in) and can never drive the source negative; the availability check uses *available* (on_hand − reserved), summed per variant.
- Every lifecycle action writes exactly one `audit_logs` row via the shared `AuditLogger` (Principle VI, FR-014a); confirm writes one with before/after balances and uses `saveQuietly()` to avoid a double audit; zero audit rows on a rolled-back confirm.
- No hard deletes; confirmed transfers immutable; baseline may only shrink.

---

## Notes

- [P] tasks = different files, no dependency on incomplete tasks.
- [Story] label maps a task to its user story for traceability.
- Verify each test fails before implementing; commit after each task or logical group.
- The `confirm()` tests (T019, extended by T023) are the heart of this phase — assert **two** movements per line, exact dual-balance deltas, summed-per-variant availability, audit content, and zero partial state on any thrown domain error.
- `AdminModuleRegistry`, `InventoryPermission`/`InventoryPermissionSeeder`, `MovementType`, `StockMovementsTable`, and `tests/Unit/ArchTest.php` need **no** edit (research D1/D6/D8): the registry already imports `TransferResource`, the permissions/enum/back-link are seeded/wired, and the guard already bans `App\Filament\Resources\Transfers` from writing the ledger.
