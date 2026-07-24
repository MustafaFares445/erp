# Phase 0 Research: Stock Adjustments (FI-3)

All Technical Context unknowns and design decisions resolved below. Each entry: **Decision / Rationale / Alternatives considered**.

## R1 — Where the stock mutation lives (service layer introduction)

**Decision**: Create `app/Services/Inventory/InventoryAdjustmentService.php` with a single public `confirm(InventoryAdjustment $adjustment, User $actor): void`. All balance math, movement creation, number generation, and the audit write happen inside one `DB::transaction()` there. The Filament confirm action only calls it (through the FI-0 `InteractsWithInventoryServices` concern) and renders success/failure.

**Rationale**: Constitution Principle II (business rules in domain services) and Principle III (every stock change goes through a service that writes a movement, inside a transaction). The FI-0 architecture test bans `App\Filament` from using `InventoryStock`/`InventoryMovement`, so the mutation physically cannot live in the resource — it must be a service. This is the first occupant of the services layer.

**Alternatives considered**: (a) Logic in the Filament page action — rejected: violates Principle II/III and fails the arch guard. (b) A Laravel Action class in `app/Actions` — rejected only for consistency: the plan and COMPONENT_DESIGN §6 speak of an "inventory movement service"/"adjustment service", so `app/Services/Inventory` matches the documented vocabulary. Either would satisfy the constitution; `Services` chosen for spec alignment.

## R2 — Sidebar wiring (registry edit needed?)

**Decision**: No `AdminModuleRegistry` edit. Creating `App\Filament\Resources\Adjustments\AdjustmentResource` is sufficient.

**Rationale**: `AdminModuleRegistry.php` already `use`s `App\Filament\Resources\Adjustments\AdjustmentResource` (line 13) and lists it in the `inventory` group (line 137, label `admin.resources.adjustments`, which already exists in `lang/en/admin.php`). Per registry §1.2, `resolveLink()` returns a real URL as soon as the class exists, is a Filament `Resource`, and passes `canAccess()`; until then the slot routes to `ModulePlaceholder`. This mirrors how FI-1/FI-2 resources activated with no registry change.

**Alternatives considered**: Editing the registry — rejected as unnecessary and out of the module boundary the plan set for non-reservation resources.

## R3 — Draft→confirmed transition guard (shared service vs inline)

**Decision**: Implement the guard inline in `InventoryAdjustmentService::confirm()`: open the transaction, re-load the adjustment `->lockForUpdate()`, and throw a `DomainException` if its status is not `draft`. Do **not** build the `StatusTransitionService` named in plan §2.1 yet.

**Rationale**: Only one document transitions in FI-3, so a shared abstraction has a single caller — premature (YAGNI, CLAUDE.md/constitution "avoid unrelated refactors"). The row lock makes the guard safe against concurrent/double confirmation (FR-017, edge case "double confirmation / concurrent confirm"): the second transaction blocks, then sees `confirmed` and is refused, so stock is never adjusted twice. The guard is fully unit-tested.

**Alternatives considered**: A shared `StatusTransitionService` now — deferred to FI-4 (transfers) when a second caller reveals the real shared shape. Optimistic locking via a version column — rejected: not in ERD §6; `lockForUpdate` on the existing row is simpler and engine-portable (SQLite test DB treats it as a no-op but the status re-check still holds within the transaction).

## R4 — Architecture guard (does it need changing?)

**Decision**: **No change** to `tests/Unit/ArchTest.php`. The existing guard bans every `App\Filament` namespace except `Resources\StockLevels` and `Resources\StockMovements` from using `InventoryStock`/`InventoryMovement`. `Resources\Adjustments` is **not** excepted, so the guard actively enforces that the adjustment resource cannot touch the ledger and must delegate to the service.

**Rationale**: This is exactly the guarantee FI-3 needs. The service lives in `App\Services\Inventory` (outside `App\Filament`), so it is free to write the models; the resource is not. The guard therefore protects Principle III for free. Add a positive assertion in the feature test that confirming goes through the service (movement rows appear only after the service call), rather than loosening the arch rule.

**Alternatives considered**: Adding an exception for `Resources\Adjustments` — rejected: it would defeat the guard's purpose and let a future edit bypass the service.

## R5 — PHPStan trait shim cleanup

**Decision**: Remove the `tests/Unit/InteractsWithInventoryServicesTest.php` line from `phpstan.neon`'s `paths`. Keep the `ChecksInventoryPermissionsTest.php` line as-is (untouched by this feature).

**Rationale**: That shim exists only because `InteractsWithInventoryServices` had no production consumer (documented note in `phpstan.neon`, FI-0 research R11). FI-3's confirm action is the first production consumer, so PHPStan now analyses the trait against real code and the shim is obsolete. Removing it **shrinks** the analysis surface with no baseline/ignore suppression — consistent with the AI feature-development rule "the baseline may only shrink" and "remove entries that no longer apply". `ChecksInventoryPermissions` already has a production consumer (FI-1 `WarehousePolicy`); its shim line is out of scope for this feature and left untouched to avoid an unrelated change.

**Alternatives considered**: Leaving both shim lines — rejected: the note explicitly says to remove a trait's shim "once FI-1/FI-3 add a real production consumer for each trait."

## R6 — Adjustment number generation

**Decision**: The service generates a unique, human-readable, read-only `adjustment_number` at **confirmation** time (not at draft creation), inside the transaction: `ADJ-` + zero-padded sequential (`ADJ-000001`). It is derived from a locked count/max of existing numbers within the transaction to keep it unique and gap-tolerant.

**Rationale**: FR-002 (system-generated, admin cannot edit, must not rely on admin input) and the Assumption "adjustment numbering is system-owned … unique". Generating at confirm keeps drafts (which may be discarded) from consuming numbers, so issued numbers stay meaningful. Generating inside the confirm transaction guarantees uniqueness under concurrency alongside the status lock.

**Open sub-point (non-blocking)**: whether numbers should be year-scoped (`ADJ-2026-000001`). ERD (line ~2236) only lists that adjustments *have* a number, with no format. Chosen the simplest unique scheme; year-scoping can be layered later without a data migration since the column is a free-form `varchar(100)`. Draft rows show a placeholder ("Assigned on confirmation") in the read-only number field.

**Alternatives considered**: Assign at draft creation — rejected: discarded drafts would burn numbers and leave gaps that look like deletions. UUID — rejected: not human-referenceable for FR-023 discovery.

## R7 — old_quantity / difference capture timing

**Decision**: While a draft, each item stores only the operator-entered `new_quantity`; `old_quantity` shown in the form is read from the **live** `inventory_stocks.on_hand_quantity` (0 if no row) for display, and `difference` is computed for display. At **confirm**, the service re-reads the live balance under the row lock and writes the authoritative `old_quantity`, `difference` (= `new_quantity − old_quantity`), and the movement from those locked values, then persists them onto the item.

**Rationale**: Spec Assumption "difference is derived, never entered" and "old quantity is always taken from the current balance at the moment it is needed, not hand-entered." Capturing `old_quantity` at confirm (not at draft prep) means a balance that changed between prep and confirm is handled correctly and atomically. ERD stores `old_quantity`/`difference` as NOT NULL on the item, so they are finalized/persisted at confirm.

**Alternatives considered**: Freeze `old_quantity` at draft time — rejected: stale if stock moved meanwhile, and would let the ledger disagree with the balance. Never persist `old_quantity`/`difference` — rejected: violates ERD §6 NOT NULL columns and loses the audit-friendly before/after record.

## R8 — Balance update semantics & negative-balance policy

**Decision**: On confirm, per item: `on_hand_quantity += difference`; `available_quantity = on_hand_quantity − reserved_quantity` (reserved untouched by adjustments). If no `inventory_stocks` row exists for `(variant, warehouse)`, create it with `old_quantity = 0`. Reject the whole confirmation with a `DomainException` if any resulting `on_hand_quantity` would be negative.

**Rationale**: Source of truth is `(product_variant_id, warehouse_id)` (Principle III); `available = on_hand − reserved` keeps FI-2's read model consistent. FR-012 requires zero-stock variants to establish a balance and record a movement for the full counted quantity. The spec delegates the negative-balance decision to "the domain rules"; forbidding a negative on-hand is the safe default and is surfaced as a clear message (FR-015, edge case "negative resulting balance"). `new_quantity ≥ 0` is already enforced at validation (FR-005), so a negative on-hand can only arise from a pre-existing reserved/consistency anomaly, which we refuse rather than silently correct.

**Alternatives considered**: Allowing negative on-hand — rejected as the default; can be revisited per domain rules without UI change since the check lives in the service. Recomputing `available` by summing movements — rejected: SYSTEM_ARCHITECTURE §9 says do not cache/derive balances that way here; the stored balance is the source of truth and is updated transactionally.

## R9 — Movement row fields on confirm

**Decision**: Each created `inventory_movements` row carries: `product_variant_id`, `warehouse_id`, `movement_type = 'adjustment'` (`MovementType::Adjustment`), `quantity = difference` (signed, may be negative), `source_type = 'adjustment'` + `source_id = adjustment->id`, `status = 'confirmed'`, `created_by = actor->id`, and `notes` = the adjustment reason (optional). A **zero-difference** line still writes a movement with `quantity = 0` for a faithful ledger (edge case "no change on a line"), OR is skipped — see sub-decision.

**Correction during implementation (was: `source_type = InventoryAdjustment::class`)**: the already-shipped `StockMovementsTable::sourceResource()` (FI-2) resolves the read-only link by matching `source_type` against short codes (`'delivery_note'`, `'invoice'`, `'credit_note'`, `'adjustment'`, `'transfer'`) mapped to resource classes — it does **not** expect a fully-qualified model class string. `source_type = 'adjustment'` is what makes FR-014's cross-module link actually resolve against the existing, already-tested FI-2 code with zero changes there. This mirrors `movement_type`'s value and is discovered by reading the existing implementation before writing new code (CLAUDE.md rule 1), not a new decision.

**Sub-decision (zero-difference lines)**: **Write** a `quantity = 0` movement. Rationale: SC-007 requires "exactly as many new ledger movements as it has item lines — never fewer (no silently dropped line)"; skipping a zero line would drop a line. The balance simply does not change for that line.

**Rationale**: Ties the movement back to its source document as a read-only cross-reference (consistent with FI-2's `source_type`/`source_id` infolist link). `status = 'confirmed'` distinguishes applied movements from the table's default `'pending'`.

**Alternatives considered**: Skipping zero-difference movements — rejected against SC-007's "never fewer" wording. Using a bespoke `source` string — rejected: FI-2 already renders `source_type`/`source_id`, so reuse it.

## R10 — Audit record shape (FR-013)

**Decision**: `AuditLogger::log()` writes one `audit_logs` row per confirmation (not per item): `actor_user_id = actor->id`, `action = 'inventory.adjustment.confirmed'`, `entity_type = InventoryAdjustment::class`, `entity_id = adjustment->id`, `old_values = {status: 'draft', items: [{variant_id, old_quantity}...]}`, `new_values = {status: 'confirmed', adjustment_number, items: [{variant_id, new_quantity, difference}...]}`, `source_channel = 'dashboard'`, `ip_address` = request IP if available. The write happens inside the same transaction as the movements/balance changes, so a rollback discards the audit row too.

**Rationale**: ERD §6 `audit_logs` columns exactly. Plan §2.4: audit is a side effect of the service call, `source_channel = dashboard`, and there must be exactly one audit trail (no parallel Filament trail). One row per document (with per-item before/after in the JSON) captures "the acting user, the action, and the before/after values" (FR-013, SC-005) without exploding row count.

**Alternatives considered**: One audit row per item — rejected: FR-013/SC-005 speak of auditing the confirmation, and per-item detail fits the `old_values`/`new_values` JSON. Auditing outside the transaction — rejected: a rollback must leave no audit row for an operation that did not happen (atomicity, SC-003).

## R11 — Validation reuse (spatie/laravel-data)

**Decision**: Centralize adjustment field rules in `app/Data/Inventory/AdjustmentData.php` (matching the existing `WarehouseData` pattern): `warehouse_id` (required, exists, active), `reason` (required, string), `items` (required, min 1), each item `product_variant_id` (required, exists) + `new_quantity` (required, numeric, ≥ 0). The Filament form/relation-manager reference these rules; the future REST endpoint reuses the same Data object.

**Rationale**: Plan §2.5 mandates single-sourced validation across API and Filament; `WarehouseData` set the precedent in FI-1. FR-005/FR-008 rules live in one place.

**Alternatives considered**: Hand-duplicating rules in Filament components — rejected by plan §2.5.

## R12 — Storage / engine portability

**Decision**: Migrations use Blueprint methods only: `string('status', 50)->default('draft')`, `string('adjustment_number', 100)` (unique), `decimal('*_quantity'|'difference', 15, 3)`, `json('old_values'|'new_values')->nullable()`, `foreignId(...)->constrained()`, `softDeletes()` on `inventory_adjustments`. No native DB enum. SQLite (test) and MySQL/PostgreSQL (prod, Open Question #6) all supported.

**Rationale**: Matches FI-1/FI-2 migration style; keeps engine unconfirmed per Open Question #6 without blocking. `json` column type is supported by SQLite ≥ 3.38 and both prod candidates.

**Alternatives considered**: Native enum for `status` — rejected for engine portability (same choice as `movement_type` in FI-2). `text` for JSON — rejected: `json` cast gives typed array access and DB-level validation on prod engines.
