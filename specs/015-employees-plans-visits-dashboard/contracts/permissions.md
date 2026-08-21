# Contract: Employee Permission Catalogue and Fixed Roles

**Guard**: `web`. **Source of truth**: `App\Enums\EmployeePermission`. **Seeder**:
`EmployeePermissionSeeder` (idempotent, mirrors `CrmPermissionSeeder`).

## Catalogue (must all exist after seeding)

```text
employees.employee.view          employees.employee.manage        employees.employee.restore
employees.plan.view              employees.plan.manage            employees.plan.restore
employees.task.view              employees.task.manage
employees.visit.view             employees.visit.review           employees.visit.field-edit
employees.voice-note.view        employees.voice-note.play
employees.ai-rule.view           employees.ai-rule.manage
employees.opportunity.view       employees.opportunity.review
employees.performance.view       employees.performance.recalculate
employees.salary.view            employees.salary.calculate       employees.salary.confirm
employees.bonus.view             employees.bonus.approve
employees.report.view            employees.audit.view
```

Two abilities carry FR-044/D7's split and must never be conflated:

- **`employees.visit.field-edit`** — the admin-only escape hatch that permits editing a
  `field`-recorded visit's data. Granted to `System Admin` only.
- **`employees.visit.review`** — permits creating or updating the single review note. It remains
  usable on a locked field-recorded visit; that is the entire point of FR-044 — the record stays
  immutable while review stays possible.

## Fixed roles (SRS §2)

| Role | Permissions |
|---|---|
| `System Admin` *(existing)* | all of the above |
| `Employee Manager` *(new)* | employee/plan/task view+manage, visit view+review, voice-note view+play, ai-rule view, opportunity view, performance view, report view, audit view |
| `Payroll Officer` *(new)* | employee view, plan view, performance view+recalculate, salary view+calculate+confirm, bonus view+approve, report view, audit view |
| `Reviewer` *(existing)* | every `.view` permission; nothing else — no `visit.review`, no `salary.confirm`, no `bonus.approve` |

## Cross-module fixed-role guarantee (R-006)

`ChecksCrmPermissions::authorizeCrmAbility()` and the equivalent Inventory trait grant any
`isAdmin()` user full module access unless that user holds one of *that module's* fixed roles.
Adding `Employee Manager`/`Payroll Officer` without updating those checks would silently grant both
new roles full CRM and Inventory access. This feature introduces one shared source of truth for
every module's fixed dashboard role names, consulted by all three `Checks*Permissions` traits.

## Guarantees

- **Completeness**: every string above exists as a Spatie `permission` row on guard `web` after
  `EmployeePermissionSeeder` runs.
- **Idempotency**: running the seeder N times yields exactly one row per permission.
- **Grantability**: a role granted any subset of these permissions causes `$user->can('<permission>')`
  to return true for exactly that subset.
- **Dual enforcement (FR-005)**: every ability is checked both when a page opens and when the action
  itself executes — never on page load alone.
- **No hidden-button-only enforcement (FR-006)**: every action is authorized server-side even when
  reached directly, bypassing a hidden or disabled control.
- **Bulk parity (FR-007)**: every `BulkAction` authorizes each record with the same ability as the
  single-record equivalent.
- **No cross-module leak**: a user whose only role is `Employee Manager` or `Payroll Officer` gains
  no CRM or Inventory access through the `isAdmin()` bypass in either module's permission trait.

## Verification

- Feature test `EmployeePermissionSeederTest`: catalogue count/names; run twice, assert no
  duplicates; grant a subset to a role, assign to a user, assert `can()` reflects exactly that subset.
- Feature test per fixed role: `Reviewer` cannot write a review note, confirm a salary calculation,
  or approve a bonus; `Employee Manager` cannot calculate or confirm salary; `Payroll Officer` cannot
  manage employees, plans, or tasks.
- Regression test: an admin holding only `Payroll Officer` (and separately, only `Employee Manager`)
  cannot manage CRM customers or Inventory records.
- Policy test per model: page-open check, direct-action check, bulk-action check, and a
  service-level rejection when a status transition is attempted with the UI bypassed entirely
  (FR-008; see [plan-lifecycle.md](./plan-lifecycle.md)).
