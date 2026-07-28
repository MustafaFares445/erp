# Quickstart & Validation: Stock Adjustments (FI-3)

How to validate the feature end-to-end. Implementation details live in [data-model.md](data-model.md), [contracts/](contracts/), and (later) `tasks.md`.

## Prerequisites

- FI-0/FI-1/FI-2 in place (they are): panel access gate, seeded `inventory.*` permissions, warehouses, read-only stock + movement screens.
- Migrated DB with the new `inventory_adjustments`, `inventory_adjustment_items`, and `audit_logs` tables:
  ```bash
  php artisan migrate
  ```
- Permissions seeded (idempotent):
  ```bash
  php artisan db:seed --class=Database\\Seeders\\InventoryPermissionSeeder
  ```
- A System Admin user (`user_type = admin`) holding `inventory.adjustment.view`, `inventory.adjustment.create`, and `inventory.adjustment.confirm`. For the segregation-of-duties check, also a user with `create` but **not** `confirm`.
- At least one active warehouse and a couple of product variants (use `InventoryDemoSeeder` for local smoke).

## Automated validation (authoritative — run these first)

```bash
# Whole feature, compact output
php artisan test --compact --filter=Adjustment

# The integrity core (Principle III — must be green)
php artisan test --compact --filter=ConfirmAdjustmentTest

# Full CI gate (Pint + Rector + PHPStan max + Pest + 100% type/coverage)
composer test
```

Expected: all green, no PHPStan baseline growth (the `InteractsWithInventoryServices` shim line is removed from `phpstan.neon`, research R5), coverage stays at 100%.

## Manual smoke (dashboard at `/admin`)

Resolve the URL first:
```bash
php artisan route:list --path=admin --except-vendor
```

### Scenario A — Prepare a draft that changes nothing (US1)
1. Inventory → Adjustments → New. Pick a warehouse, enter a reason.
2. Add two item lines; for each, pick a variant and note the read-only current on-hand and the auto-computed difference. Enter counted quantities.
3. Save. Confirm the row shows status **draft** and **no** adjustment number yet.
4. Open the FI-2 Stock Levels and Stock Movements screens → **no** balance and **no** movement changed. ✅ SC-001.

### Scenario B — Apply the correction (US2)
1. Prepare a draft: variant A 10→13, variant B 5→2. Confirm it.
2. Stock Levels: A available +3, B available −3. Stock Movements: exactly two `adjustment` movements (+3, −3) sourced from this adjustment. ✅ SC-002/SC-007.
3. The adjustment now shows **confirmed** with an `ADJ-…` number. ✅ FR-002.
4. Check `audit_logs` has one row for this confirmation naming the acting user:
   ```bash
   php artisan tinker --execute 'App\Models\AuditLog::latest("id")->first()->only(["action","actor_user_id","entity_type","source_channel"]);'
   ```
   ✅ FR-013/SC-005.

### Scenario C — Integrity & immutability (US3)
1. On the confirmed adjustment: Edit, Delete, and Confirm actions are all **absent**. ✅ FR-016/FR-017.
2. Force a domain failure: prepare a draft against a warehouse, deactivate that warehouse, then Confirm → a danger notification explains why, and Stock Levels / Stock Movements / `audit_logs` are **unchanged**. ✅ SC-003/FR-015.
3. Soft-delete a *draft* → it disappears from the default list but is recoverable (trashed filter / restore), never hard-deleted. ✅ FR-018.

### Scenario D — Segregation of duties (FR-020/FR-021)
1. Log in as the `create`-only user. Open a draft (even one they created).
2. The **Confirm** control is not shown, and hitting the confirm path is refused. ✅ FR-021.
3. Log in as a user lacking `inventory.adjustment.view` → Adjustments is hidden from the sidebar and the URL returns 403. ✅ FR-022.

## What "done" looks like

- All `--filter=Adjustment` and `ConfirmAdjustmentTest` tests pass; `composer test` green at 100% coverage.
- Scenarios A–D behave as described.
- No stock balance can be changed anywhere in the dashboard except by confirming an adjustment (verified by the unchanged FI-0 arch guard + `ConfirmAdjustmentTest`).
- Exactly one `audit_logs` row per confirmation; none for a rolled-back confirm.
