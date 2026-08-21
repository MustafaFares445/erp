# Quickstart: Inventory Dashboard Foundation & Guardrails

A validation guide proving FI-0's rails work end-to-end. Implementation details live in `tasks.md` (created by `/speckit-tasks`) and the code; this file is how you confirm the foundation behaves.

## Prerequisites

- Dependencies installed: `composer install`, `npm install && npm run build`.
- App key + DB ready: `php artisan key:generate`, `php artisan migrate` (SQLite by default; engine unconfirmed — research R10).
- Permissions + a System Admin seeded: `php artisan db:seed`.
- A queue worker is **not** required for FI-0 (no queued work yet).

## Contracts under validation

- [Panel access](contracts/panel-access.md)
- [Permission catalogue](contracts/permissions.md)
- [Policy ability mapping](contracts/policy-abilities.md)
- [Action adapter](contracts/action-adapter.md)

Data shapes: [data-model.md](data-model.md).

## Automated validation (authoritative)

Run the feature's tests plus the quality gates:

```bash
# Fast inner loop — just this feature's tests
php artisan test --compact tests/Feature/Filament tests/Unit/ArchTest.php tests/Unit/InteractsWithInventoryServicesTest.php

# Full CI-equivalent gate (lint, static analysis, 100% type + code coverage)
composer test
```

Expected: all green, including 100% type coverage and 100% code coverage for new files.

### Scenario checklist (maps to spec acceptance criteria)

1. **Admin can enter** (US1 / SC-001): a `UserType::Admin` user gets HTTP 200 on `/admin`.
2. **Non-admin denied** (US1 / SC-001): `customer` and `employee` users are denied; a guest is redirected to `/admin/login`.
3. **Permission catalogue seeded** (US2 / FR-003, FR-010): all 13 `inventory.*` permissions exist on guard `web`; re-seeding creates no duplicates; a role granted a subset yields exactly that subset via `can()`.
4. **Ability mapping** (US2 / FR-004, FR-005): the policy trait grants/denies each ability strictly per its mapped permission (per-resource navigation-hide + 403 verified in FI-1+).
5. **Adapter surfaces errors, no partial writes** (US3 / FR-006, FR-007): a domain error thrown inside `runInventoryOperation` produces a danger notification and zero writes; success produces a success notification.
6. **Architecture guard** (US3 / SC-004): `tests/Unit/ArchTest.php` fails the build if any `App\Filament` class uses `App\Models\InventoryStock` or `App\Models\InventoryMovement`.

## Manual smoke (optional)

```bash
php artisan serve
```

- Visit `/admin` as a guest → redirected to login.
- Log in as the seeded System Admin → dashboard loads; the Inventory group appears in the module switcher with placeholder items (no real inventory screens yet — that is FI-1+).
- Confirm no inventory screen offers a create/edit/delete control for stock or movements (there are none yet by design).

## Definition of done for FI-0

- [ ] Panel gate denies non-admins and guests, allows admins.
- [ ] `inventory.*` catalogue defined in one source and seeded idempotently.
- [ ] Reusable policy trait maps abilities → permissions.
- [ ] Action adapter translates domain/validation errors to notifications with no partial writes.
- [ ] Architecture test forbids direct Filament writes to inventory stock/movement models.
- [ ] Existing `DashboardPageTest` updated for the new gate; `composer test` green (100% type + coverage).
