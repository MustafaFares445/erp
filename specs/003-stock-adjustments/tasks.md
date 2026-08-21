---

# Tasks: Stock Adjustments (FI-3)

**Input**: Design documents from `specs/003-stock-adjustments/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/](contracts/)

**Tests**: INCLUDED — mandated by the constitution (Principle VI, and Principle III is NON-NEGOTIABLE for the stock-mutating confirm path), the spec success criteria (SC-001…SC-007), and the `composer test` gate (100% type + code coverage). Write each story's test task first and confirm it fails before implementing.

**Organization**: Grouped by user story (US1 P1, US2 P2, US3 P3) so each is independently implementable and testable on the shared schema. US1 is the MVP (a draft that changes nothing); US2 adds the sole write path (confirm); US3 hardens immutability/segregation.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1 / US2 / US3 for story-phase tasks; Setup / Foundational / Polish carry no story label

## Path Conventions

Single Laravel application. Source under `app/`, migrations/seeders/factories under `database/`, translations under `lang/`, tests under `tests/` (Pest). Paths below are repo-relative. The Filament resource uses the nested directory layout matching the `AdminModuleRegistry` namespace `App\Filament\Resources\Adjustments` it already imports (no registry edit — research R2). New migrations continue the FI-1/FI-2 sequence and MUST be engine-agnostic (Blueprint only; `decimal(15,3)` quantities; `string` status/number; `json` audit values; no native enums).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm a clean starting point. FI-0/FI-1/FI-2 are committed: panel gate, the seeded `inventory.adjustment.view|create|confirm` permissions (`App\Enums\InventoryPermission` + `InventoryPermissionSeeder`), the `ChecksInventoryPermissions` policy trait, the `InteractsWithInventoryServices` Filament concern, the `MovementType` enum, the `inventory_stocks`/`inventory_movements` models, and the arch guard already exist.

- [X] T001 Confirm a green baseline: run `php artisan test --compact tests/Feature tests/Unit` and record that the existing FI-0/FI-1/FI-2 suites (incl. `PanelAccessTest`, `DashboardPageTest`, `ArchTest`, `WarehouseResourceTest`, `StockLevelResourceTest`, `StockMovementResourceTest`, `InventoryPermissionSeederTest`) pass. No files changed in this task. **Result**: 101/101 passed.

**Checkpoint**: Baseline green — foundational work can begin.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Create the shared schema, models, enum, factories, and the authorization policy that ALL three user stories depend on. Table shapes are verbatim from [data-model.md](data-model.md) / ERD §6.

**⚠️ CRITICAL**: Blocks US1, US2, and US3. No resource/service/UI work may begin until this phase completes.

- [X] T002 [P] Create backed string enum `App\Enums\AdjustmentStatus` with cases `Draft='draft'`, `Confirmed='confirmed'` in `app/Enums/AdjustmentStatus.php` (data-model §Enum; no `pending` case — single-confirm workflow, Open Question #10 resolved)
- [X] T003 [P] Create migration for `inventory_adjustments` (`warehouse_id` FK→warehouses `restrictOnDelete`; `adjustment_number` string(100) **nullable + unique**; `reason` text; `status` string(50) default `'draft'`; timestamps; `created_by`/`updated_by` FK→users `nullOnDelete`; `deleted_at`; index `warehouse_id`,`status`,`created_at`) in `database/migrations/2026_07_22_000008_create_inventory_adjustments_table.php` (data-model §1)
- [X] T004 [P] Create migration for `inventory_adjustment_items` (`inventory_adjustment_id` FK `cascadeOnDelete`; `product_variant_id` FK→product_variants `restrictOnDelete`; `old_quantity` decimal(15,3) default 0; `new_quantity` decimal(15,3); `difference` decimal(15,3) default 0; timestamps; index the two FKs) in `database/migrations/2026_07_22_000009_create_inventory_adjustment_items_table.php` (data-model §2)
- [X] T005 [P] Create migration for `audit_logs` (`actor_user_id` FK→users nullable `nullOnDelete`; `action` string(100); `entity_type` string(150); `entity_id` bigint nullable; `old_values`/`new_values` json nullable; `source_channel` string(50) nullable; `ip_address` string(50) nullable; timestamps; index `actor_user_id`,`action`,`['entity_type','entity_id']`,`created_at`) in `database/migrations/2026_07_22_000010_create_audit_logs_table.php` (data-model §3; ERD §6 — first sensitive-action store, Complexity Tracking)
- [X] T006 [P] Create `App\Models\InventoryAdjustment` (`final`; `HasFactory` + `SoftDeletes` + `App\Models\Concerns\TracksBlameable`; `casts(): status => AdjustmentStatus::class`; relations `warehouse()` BelongsTo, `items()` HasMany, `createdBy()` BelongsTo User via `created_by`; helpers `isDraft()`/`isConfirmed()`; fillable `warehouse_id`,`reason` only — NOT `adjustment_number`/`status`/`created_by`) in `app/Models/InventoryAdjustment.php` (data-model §1)
- [X] T007 [P] Create `App\Models\InventoryAdjustmentItem` (`final`; `HasFactory`; no soft delete; `casts()`: `old_quantity`/`new_quantity`/`difference` → `decimal:3`; relations `adjustment()` BelongsTo, `productVariant()` BelongsTo; fillable `product_variant_id`,`new_quantity` only — NOT `old_quantity`/`difference`) in `app/Models/InventoryAdjustmentItem.php` (data-model §2)
- [X] T008 [P] Create `App\Models\AuditLog` (`final`; `HasFactory`; no soft delete; `casts()`: `old_values`/`new_values` → `'array'`; relation `actor()` BelongsTo User via `actor_user_id`) in `app/Models/AuditLog.php` (data-model §3)
- [X] T009 [P] Create `InventoryAdjustmentFactory` with a default `draft` state and a `confirmed()` state (sets `adjustment_number`, `status=confirmed`) in `database/factories/InventoryAdjustmentFactory.php`
- [X] T010 [P] Create `InventoryAdjustmentItemFactory` (realistic `new_quantity`; `old_quantity`/`difference` derivable) in `database/factories/InventoryAdjustmentItemFactory.php`
- [X] T011 [P] Create `AuditLogFactory` (sample `action`, `entity_type`, json values, `source_channel='dashboard'`) in `database/factories/AuditLogFactory.php`
- [X] T012 Create `App\Policies\InventoryAdjustmentPolicy` using `ChecksInventoryPermissions`: `viewAny`/`view` → `AdjustmentView`; `create`/`restore` → `AdjustmentCreate`; `update`/`delete` → `AdjustmentCreate` **but return false when `status === Confirmed`** (draft-only, FR-016); `forceDelete()` → **always false** (no hard delete, FR-018); custom `confirm(User, InventoryAdjustment)` → `AdjustmentConfirm` **and** `$adjustment->isDraft()` in `app/Policies/InventoryAdjustmentPolicy.php` (contracts/authorization.md — auto-discovered by Laravel, no registration)

**Checkpoint**: `php artisan migrate` succeeds on SQLite; PHPStan type-checks the three models + enum + policy once all exist; the existing `ArchTest` stays green (its guard already bans `App\Filament\Resources\Adjustments` from `InventoryStock`/`InventoryMovement` — research R4, no edit). User stories can now proceed.

---

## Phase 3: User Story 1 - Prepare a stock correction as a draft (Priority: P1) 🎯 MVP

**Goal**: A permitted admin creates/edits a **draft** adjustment — a warehouse, a required reason, and item lines showing each variant's read-only current on-hand and computed difference — that changes **no** stock balance and **no** ledger entry. Add/edit/remove lines and save/reopen freely.

**Independent Test**: As an admin with `inventory.adjustment.create`, create a draft for a warehouse with a reason and two variant lines (counted quantities), confirm the current on-hand and difference show per line, edit one line, remove another, save and reopen — then assert `inventory_stocks` and `inventory_movements` are entirely unchanged and the adjustment has status `draft` with no number (SC-001).

### Tests for User Story 1 ⚠️ (write first, confirm they fail)

- [X] T013 [US1] Create `tests/Feature/Filament/AdjustmentResourceTest.php` covering: create draft (warehouse + reason + ≥1 item) saved as `draft` with **no** `adjustment_number` and **zero** stock/movement change (SC-001, FR-007); validation rejects missing reason, zero items, and negative `new_quantity` (FR-005/FR-008); item line shows read-only `old_quantity` from the live `(variant, warehouse)` balance (0 when none) and a computed read-only `difference` (FR-003/FR-004); `created_by` set to the acting admin on create; view/create hidden + direct-URL 403 without the matching `inventory.adjustment.*` permission (FR-022). Matches [contracts/adjustment-resource.md](contracts/adjustment-resource.md). (depends on Foundational)

### Implementation for User Story 1

- [X] T014 [P] [US1] Create `App\Data\Inventory\AdjustmentData` (spatie/laravel-data) holding the draft rule set: `warehouse_id` required + exists + active; `reason` required string max 1000; `items` required array min:1; `items.*.product_variant_id` required + exists; `items.*.new_quantity` required numeric min:0 in `app/Data/Inventory/AdjustmentData.php` (data-model §Validation; contracts/adjustment-resource.md; matches sibling `WarehouseData`)
- [X] T015 [P] [US1] Create `Schemas/AdjustmentForm.php`: `warehouse_id` Select (active warehouses, required, disabled when confirmed); `adjustment_number` read-only placeholder showing `number_pending` while draft / the number once confirmed (FR-002); `reason` Textarea (required); `status` display-only; whole form read-only when `status === confirmed` — rules sourced from `AdjustmentData` in `app/Filament/Resources/Adjustments/Schemas/AdjustmentForm.php`
- [X] T016 [P] [US1] Create `Tables/AdjustmentsTable.php`: columns `adjustment_number`, `warehouse.code`+name, `reason` (truncated), `status` badge (draft = warning/gray, confirmed = success), `items_count`, creator name, `created_at`; filters `status`, `warehouse_id`, `created_at` date range (FR-023) in `app/Filament/Resources/Adjustments/Tables/AdjustmentsTable.php`
- [X] T017 [US1] Create `RelationManagers/AdjustmentItemsRelationManager.php`: `product_variant_id` Select (SKU + name, required); `old_quantity` read-only from the live `(variant, warehouse)` on-hand (0 if none); `new_quantity` numeric required ≥ 0; `difference` read-only computed `new_quantity − old_quantity` (FR-004); add/edit/remove actions gated on parent `isDraft()` (FR-006), read-only once confirmed; touches no stock (FR-007) in `app/Filament/Resources/Adjustments/RelationManagers/AdjustmentItemsRelationManager.php`
- [X] T018 [US1] Create `AdjustmentResource` + its four pages (`ListAdjustments`, `CreateAdjustment`, `EditAdjustment`, `ViewAdjustment`) wiring the form/table/items relation manager, `inventory` nav group, `SoftDeletes` handling, and model binding; `CreateAdjustment` sets `created_by = auth()->id()` and leaves `status` at its `draft` default and `adjustment_number` null (R6) in `app/Filament/Resources/Adjustments/AdjustmentResource.php` and `app/Filament/Resources/Adjustments/Pages/{ListAdjustments,CreateAdjustment,EditAdjustment,ViewAdjustment}.php`
- [X] T019 [US1] Add the `inventory.adjustment.*` translation block to `lang/en/admin.php` — `reason`, `adjustment_number`, `number_pending` ("Assigned on confirmation"), `status`, `items_count`, `old_quantity`, `new_quantity`, `difference`, `confirm`, `notifications.confirmed`, `errors.not_draft`, `errors.inactive_warehouse`, `errors.negative_result` (contracts/adjustment-resource.md §i18n; Arabic restoration deferred — Open Question #8)

**Checkpoint**: US1 is fully functional and testable independently — an admin can prepare/edit/discard a draft that changes nothing; `AdjustmentResourceTest` (draft + validation + view/create permission cases) passes; `ArchTest` still green.

---

## Phase 4: User Story 2 - Apply the correction so stock and the ledger update together (Priority: P2)

**Goal**: A permitted admin confirms a draft. In one all-or-nothing transaction the system writes one `adjustment` movement per item (signed difference), updates each `(variant, warehouse)` balance by exactly that difference, assigns the adjustment number, marks the document `confirmed`, and writes one audit row. Results appear immediately on the FI-2 read-only stock/movement screens.

**Independent Test**: With a draft that sets variant A 10→13 and B 5→2, confirm it; assert A on-hand +3, B on-hand −3, exactly two `adjustment` movements (+3, −3), the adjustment `confirmed` with a unique number, and one `audit_logs` row naming the confirming user (SC-002/SC-005/SC-007, mirrors spec US2 Independent Test).

### Tests for User Story 2 ⚠️ (write first, confirm they fail)

- [X] T020 [US2] Create `tests/Feature/Inventory/ConfirmAdjustmentTest.php` covering: confirm A 10→13 / B 5→2 ⇒ exact balance deltas + exactly two signed `adjustment` movements + `available = on_hand − reserved` recomputed (SC-002); movement count **equals** item count including a zero-difference line (one movement, `quantity=0`, no balance change) (SC-007); a variant with no existing balance treated as `old_quantity=0`, balance row established, movement = full counted qty (FR-012); one `audit_logs` row with `action='inventory.adjustment.confirmed'`, `actor_user_id`, `entity_type`/`entity_id`, `source_channel='dashboard'`, and before/after JSON per line (SC-005); a forced domain error (inactive warehouse / negative result) rolls back with **0** movements, **0** balance changes, **0** audit rows, adjustment still `draft` (SC-003, FR-015). Matches [contracts/adjustment-service.md](contracts/adjustment-service.md) + [contracts/audit-log.md](contracts/audit-log.md). (depends on Foundational)

### Implementation for User Story 2

- [X] T021 [P] [US2] Create `App\Services\Audit\AuditLogger` — the single writer of `audit_logs`: `log(string $action, Model $entity, ?array $oldValues, ?array $newValues, ?User $actor, string $sourceChannel='dashboard'): AuditLog`; `actor` defaults to `auth()->user()`, `entity_type`/`entity_id` from the model, `ip_address` from `request()->ip()` when present; performs **no** transaction of its own (relies on the caller's, R10) in `app/Services/Audit/AuditLogger.php` (contracts/audit-log.md — first occupant of the services layer)
- [X] T022 [US2] Create `App\Services\Inventory\InventoryAdjustmentService` with `confirm(InventoryAdjustment $adjustment, User $actor): void` running one `DB::transaction`: reload+`lockForUpdate` and guard `status === Draft` else `DomainException(not_draft)` (FR-017); guard active warehouse (`inactive_warehouse`, FR-015) and non-empty items; per item — lock/read the `(variant, warehouse)` stock row (treat missing as 0, create on write, FR-012), compute `difference`/`newOnHand`, reject `newOnHand < 0` (`negative_result`, R8/FR-015), persist item `old_quantity`+`difference` (R7), upsert stock (`on_hand`, `available = on_hand − reserved`), and write one `inventory_movements` row (`movement_type=adjustment`, signed `quantity` incl. 0, `source_type='adjustment'` (short code matching the shipped `StockMovementsTable::sourceResource()` link resolver), `source_id`, `status=confirmed`, `created_by=actor->id`, `notes=reason`); assign `ADJ-` + zero-padded sequence from the locked max (R6); set `status=Confirmed`+`updated_by`; call `AuditLogger::log(...)` inside the transaction in `app/Services/Inventory/InventoryAdjustmentService.php` (contracts/adjustment-service.md — the NON-NEGOTIABLE integrity core; depends on T021) (depends on Foundational)
- [X] T023 [US2] Add the **Confirm** action to `AdjustmentResource` as a thin adapter: `use App\Filament\Concerns\InteractsWithInventoryServices`; the action is `->authorize(fn ($record) => auth()->user()->can('confirm', $record))`, `->visible(fn ($record) => $record->isDraft() && auth()->user()->can('confirm', $record))`, `->requiresConfirmation()`, and on run calls `runInventoryOperation(fn () => app(InventoryAdjustmentService::class)->confirm($record, auth()->user()), 'admin.inventory.adjustment.notifications.confirmed')` — computing nothing itself (arch guard) in `app/Filament/Resources/Adjustments/AdjustmentResource.php` (contracts/adjustment-resource.md §Confirm action; contracts/authorization.md)
- [X] T024 [US2] Create `Schemas/AdjustmentInfolist.php` for the View page: number, warehouse, reason, status, creator, timestamps, the item lines (variant, old, new, difference), and — once confirmed — a **read-only** link to the resulting movements on the FI-2 `StockMovementResource` (`source_type=InventoryAdjustment`, `source_id=id`), never an editable cross-module relation (FR-014, plan §0) in `app/Filament/Resources/Adjustments/Schemas/AdjustmentInfolist.php`
- [X] T025 [US2] Remove the now-obsolete `tests/Unit/InteractsWithInventoryServicesTest.php` PHPStan shim path from `phpstan.neon` now that `AdjustmentResource`'s Confirm action is a real production consumer of the concern — the baseline **shrinks**, never grows (research R5; CLAUDE.md rule 7) in `phpstan.neon`

**Checkpoint**: US1 AND US2 both work — a draft can be prepared and confirmed; `ConfirmAdjustmentTest` passes (deltas, movement-per-line, audit, atomic rollback, zero-stock); confirmed balances/movements are visible on the FI-2 screens.

---

## Phase 5: User Story 3 - Trust that applied adjustments are integrity-safe and immutable (Priority: P3)

**Goal**: Confirmed adjustments are final — no edit, delete, or re-apply; a domain failure during confirm leaves nothing changed; drafts are discarded reversibly (soft delete, recoverable). Prepare vs apply are separately permissioned.

**Independent Test**: Confirm an adjustment and verify Edit/Delete/Confirm controls are absent and the policy refuses `update`/`delete`; attempt a second confirm and verify it is refused with stock adjusted only once; soft-delete a separate draft and verify it is recoverable (not hard-deleted); with a `create`-only admin verify no Confirm control appears and a direct confirm is refused even for their own draft (SC-003/SC-004/SC-006, FR-016…FR-021).

### Tests for User Story 3 ⚠️ (write first, confirm they fail)

- [X] T026 [US3] Extend `tests/Feature/Filament/AdjustmentResourceTest.php` and `tests/Feature/Inventory/ConfirmAdjustmentTest.php` with integrity/lifecycle cases: confirmed adjustment hides Edit/Delete/Confirm actions and `InventoryAdjustmentPolicy` `update`/`delete` return false (FR-016); `forceDelete` always false (FR-018); re-confirm and a simulated concurrent confirm (second call on a `confirmed`/locked record) are refused and stock is adjusted **once** only (FR-017); a `create`-only admin (no `confirm`) sees no Confirm action and a direct confirm is refused for their own draft (FR-020/FR-021, SC-006); a soft-deleted draft is recoverable via the trashed filter/restore, never hard-deleted (FR-018). (depends on US1 + US2 implementation)

### Implementation for User Story 3

- [X] T027 [US3] Wire immutability + lifecycle into the Filament layer: in `Tables/AdjustmentsTable.php` gate `EditAction`/`DeleteAction` on `fn ($record) => $record->isDraft()` and add a `TrashedFilter` + `RestoreAction`/`RestoreBulkAction`; in `EditAdjustment` (`app/Filament/Resources/Adjustments/Pages/EditAdjustment.php`) block/redirect when `status === confirmed`; ensure `AdjustmentResource` enables soft-delete scopes (`->modifyQueryUsing` with trashed) so discards are recoverable (FR-016/FR-018) — all authorization still delegated to `InventoryAdjustmentPolicy` (no forked checks)

**Checkpoint**: All three stories work independently. Confirmed adjustments are immutable and single-application; drafts are recoverable; segregation of duties holds. Full `AdjustmentResourceTest` + `ConfirmAdjustmentTest` green.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Quality gates and optional dev ergonomics across the feature.

- [X] T028 [P] (Optional, dev only) Edit `database/seeders/InventoryDemoSeeder.php` to add a sample **draft** adjustment (one warehouse, two variant lines) for manual smoke testing — must not auto-confirm
- [X] T029 Run `vendor/bin/pint --dirty --format agent` and fix any formatting on all new/edited files
- [X] T030 Run `vendor/bin/rector` (dry-run first) and apply safe suggestions to the new files, keeping behavior unchanged
- [X] T031 Run `vendor/bin/phpstan analyse` at level `max` and resolve findings on the new files **without** adding baseline entries (baseline may only shrink — CLAUDE.md rule 7; confirm the T025 shim removal did not regress)
- [X] T032 Run the full `composer test` gate (Pint check + PHPStan + Pest incl. `ArchTest`, 100% type + 100% code coverage) and confirm green; fix any coverage gaps on new files
- [X] T033 Execute [quickstart.md](quickstart.md) end-to-end (prepare a draft → confirm → verify balances/movements/audit on the FI-2 screens → attempt edit/re-confirm) and record the result. **Result**: All four scenarios (A–D) are exercised by the automated suite (AdjustmentResourceTest + ConfirmAdjustmentTest, 129/129 passing) — draft-changes-nothing, confirm produces exact deltas/movements/audit row, immutability/atomicity/segregation-of-duties all verified. Live browser click-through was attempted against https://ierp.test/admin but blocked by a per-action approval gate this session could not grant; demo data seeded (InventoryDemoSeeder, incl. a sample draft) for a future manual pass.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately.
- **Foundational (Phase 2)**: Depends on Setup — **BLOCKS all user stories**. Migrate + models + enum + policy must exist first.
- **User Stories (Phase 3–5)**: All depend on Foundational. In priority order P1 → P2 → P3; US2 depends on US1's `AdjustmentResource` existing (it adds the Confirm action + Infolist to it); US3 depends on US1 + US2 (it hardens their resource/service).
- **Polish (Phase 6)**: Depends on all desired stories being complete.

### User Story Dependencies

- **US1 (P1)**: Foundational only. Delivers the inert draft — the MVP.
- **US2 (P2)**: Foundational + US1 (extends `AdjustmentResource`). Adds the sole write path.
- **US3 (P3)**: Foundational + US1 + US2. Verifies/gates immutability, atomicity, and segregation already built into the policy (Foundational) and service (US2).

### Within Each User Story

- Tests are written first and MUST fail before implementation.
- Foundational models/enum before services; services before the Filament action that adapts them.
- Story complete and its checkpoint green before moving to the next priority.

### Parallel Opportunities

- All Foundational tasks marked [P] (T002–T011) can run together; **T012 policy** waits only on the models it type-hints.
- US1: T014 (Data), T015 (Form), T016 (Table) are [P]; T017 (items RM) and T018 (resource + pages) follow.
- US2: T021 (AuditLogger) is [P] and precedes T022 (service); T023/T024 follow the service.
- Different developers can take US1 → US2 → US3 as a relay, or split US2's service (T022) and US1's UI once Foundational is done.

---

## Parallel Example: Foundational (Phase 2)

```bash
# After T001, launch the independent schema/model/factory tasks together:
Task: "Create App\Enums\AdjustmentStatus in app/Enums/AdjustmentStatus.php"
Task: "Create migration inventory_adjustments (000008)"
Task: "Create migration inventory_adjustment_items (000009)"
Task: "Create migration audit_logs (000010)"
Task: "Create App\Models\InventoryAdjustment"
Task: "Create App\Models\InventoryAdjustmentItem"
Task: "Create App\Models\AuditLog"
# Factories (T009–T011) reference the models; run once those exist.
# Then T012 policy (references models + InventoryPermission).
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational — blocks everything).
2. Complete Phase 3 (US1): the draft screen that changes nothing.
3. **STOP and VALIDATE**: prepare/edit/discard a draft, confirm zero stock/ledger change (SC-001).
4. Demo the inert draft flow.

### Incremental Delivery

1. Setup + Foundational → schema, models, policy ready.
2. US1 → inert draft → validate → demo (MVP).
3. US2 → confirm writes movement + balance + audit atomically → validate `ConfirmAdjustmentTest` → demo the first real stock change.
4. US3 → immutability, single-application, segregation, recoverable drafts → validate → demo.
5. Polish → Pint/Rector/PHPStan/`composer test` green + quickstart.

### Constitution guardrails (do not weaken to pass CI)

- Principle III (NON-NEGOTIABLE): every stock change flows through `InventoryAdjustmentService::confirm()`, creating a movement and updating the `(variant, warehouse)` balance in one transaction; the FI-0 arch guard forbids Filament from touching the ledger.
- Confirmation writes exactly one `audit_logs` row via the shared `AuditLogger` (Principle VI, FR-013); zero on rollback.
- No hard deletes; confirmed adjustments immutable; baseline may only shrink.

---

## Notes

- [P] tasks = different files, no dependency on incomplete tasks.
- [Story] label maps a task to its user story for traceability.
- Verify each test fails before implementing; commit after each task or logical group.
- The `confirm()` tests (T020, extended by T026) are the heart of this phase — assert movement count == item count, exact balance deltas, audit content, and zero partial state on any thrown domain error.
- `AdminModuleRegistry` and `tests/Unit/ArchTest.php` need **no** edit (research R2/R4): the registry already imports `AdjustmentResource`, and the guard already bans `App\Filament\Resources\Adjustments` from writing the ledger.
