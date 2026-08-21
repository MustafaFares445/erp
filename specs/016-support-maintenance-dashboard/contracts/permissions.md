# Contract: Support Permission Catalogue and Fixed Roles

**Guard**: `web`. **Source of truth**: `App\Enums\SupportPermission`. **Seeder**:
`SupportPermissionSeeder` (idempotent, mirrors `EmployeePermissionSeeder`).
`SupportPermission` defines **no** `fixedRoleNames()` method of its own — only
`App\Enums\DashboardRole::fixedRoleNames()` is ever consulted for the
cross-module admin-bypass check (research.md §4; `CrmPermission`'s own stale
copy of that method is a documented pre-`DashboardRole` mistake, not a
pattern to repeat).

## Catalogue (must all exist after seeding)

```text
support.ticket.view              support.ticket.manage            support.ticket.assign
support.ticket.work              support.ticket.message            support.ticket.settle-payment
support.record.restore
support.sla-policy.view          support.sla-policy.manage
support.maintenance-request.view support.maintenance-request.manage
support.service-record.view      support.service-record.manage     support.service-record.execute
support.parts.consume            support.parts.reverse
support.report.view              support.audit.view
```

Three abilities carry an ownership split that must never be conflated with
their unrestricted counterpart:

- **`support.ticket.manage`** — Support Manager's unrestricted create/
  classify/triage/assign-eligible/cancel/close, on *any* ticket. Implies the
  ability to transition a ticket through its full lifecycle.
- **`support.ticket.work`** — Support Agent's transition rights, valid **only**
  on a ticket currently assigned to that agent (`TicketPolicy` checks
  `$ticket->assigned_employee_id === $user->employeeProfile?->id` in addition
  to the permission itself, mirroring `CustomerVisitPolicy`'s field-lock
  pattern and `PlanTaskPolicy`'s assignee check). A Support Manager never
  needs this permission — `support.ticket.manage` already authorizes
  transitioning any ticket.
- **`support.service-record.manage`** vs **`support.service-record.execute`** —
  identical split, one level down: `.manage` is the Support Manager's
  unrestricted create/assign/due-date/transition; `.execute` is the Support
  Agent's transition rights on a service record currently assigned to them
  (FR-075).

`support.ticket.settle-payment` and `support.parts.reverse` are **System Admin
only** — neither User Story 1's Support Manager scenario nor FR-002 lists
payment settlement or consumption reversal among Support Manager's
capabilities (contrast with FR-001, which lists both explicitly for System
Admin), and FR-086 states consumption reversal after closure requires System
Admin outright. Granting either to Support Manager would silently exceed the
spec's own role matrix.

`support.record.restore` is likewise **System Admin only**, shared across
`TicketPolicy`, `MaintenanceRecordPolicy`, and `MaintenanceTaskPolicy`'s
`restore`/`restoreAny`. FR-001 lists "restoration capability" as a System
Admin capability alongside settlement and reversal, and User Story 1 scenario
2 explicitly denies "record restoration" to Support Manager — restoring a
soft-deleted record must not piggyback on the broad `*.manage` permission
Support Manager already holds for everyday CRUD.

## Fixed roles (spec 016 §Roles and Authorization)

| Permission | System Admin | Support Manager | Support Agent | Reviewer |
|---|---|---|---|---|
| `ticket.view` | ✓ | ✓ | ✓ | ✓ |
| `ticket.manage` | ✓ | ✓ | | |
| `ticket.assign` | ✓ | ✓ | | |
| `ticket.work` *(own ticket only)* | ✓ | | ✓ | |
| `ticket.message` *(own ticket if Agent)* | ✓ | ✓ | ✓ | |
| `ticket.settle-payment` | ✓ | | | |
| `record.restore` | ✓ | | | |
| `sla-policy.view` | ✓ | ✓ | | ✓ |
| `sla-policy.manage` | ✓ | ✓ | | |
| `maintenance-request.view` | ✓ | ✓ | ✓ | ✓ |
| `maintenance-request.manage` | ✓ | ✓ | | |
| `service-record.view` | ✓ | ✓ | ✓ | ✓ |
| `service-record.manage` | ✓ | ✓ | | |
| `service-record.execute` *(own record only)* | ✓ | | ✓ | |
| `parts.consume` *(own record if Agent)* | ✓ | ✓ | ✓ | |
| `parts.reverse` | ✓ | | | |
| `report.view` | ✓ | ✓ | | ✓ |
| `audit.view` | ✓ | ✓ | | ✓ |

`System Admin` is granted every permission **explicitly** by the seeder (not
via the `isAdmin()` bypass) because holding a fixed role name removes that
bypass by design (research.md §4) — the same pattern `EmployeePermissionSeeder`
uses for its own `System Admin` grant.

## Cross-module fixed-role guarantee (FR-009, ADR 0004 consequence)

`ChecksSupportPermissions::authorizeSupportAbility()` grants any `isAdmin()`
user full module access **unless** that user holds one of the fixed dashboard
role names (`DashboardRole::fixedRoleNames()`) — identical shape to
`ChecksEmployeePermissions`/`ChecksCrmPermissions`. Adding `Support Manager`/
`Support Agent` to `DashboardRole` automatically narrows every *other*
module's admin-bypass check too, which is exactly why ADR 0004's consequences
section requires each existing module's cross-module boundary tests to gain a
case for the two new roles, in addition to Support's own new
`CrossModulePermissionLeakTest` (contracts/audit-log.md's sibling test
obligations list this explicitly).

## Guarantees

- **Completeness**: every string above exists as a Spatie `permission` row on
  guard `web` after `SupportPermissionSeeder` runs.
- **Idempotency**: running the seeder N times yields exactly one row per
  permission (mirrors `EmployeePermissionSeederTest`).
- **Grantability**: a role granted any subset of these permissions causes
  `$user->can('<permission>')` to return true for exactly that subset.
- **Ownership enforcement is a policy/service concern, not a permission-string
  concern**: `support.ticket.work` and `support.service-record.execute` are
  necessary but not sufficient — the policy additionally checks the record's
  current assignee against the acting user's employee profile.
- **Dual enforcement (FR-005)**: every ability is checked both when a page
  opens and when the action itself executes.
- **No hidden-button-only enforcement (FR-006, FR-008)**: every domain
  service in `app/Services/Support/` self-checks (`$user->can(...)` or
  `Gate::forUser($actor)->authorize(...)`) in addition to the Filament
  layer's own `->authorize()` call, so a direct service call bypassing the UI
  is rejected identically (research.md §4 — a deliberate strengthening over
  the Employees module's inconsistent precedent).
- **Bulk parity (FR-007)**: every `BulkAction` authorizes each record with the
  same ability as the single-record equivalent.
- **No cross-module leak (FR-009, SC-011)**: a user whose only role is
  `Support Manager` or `Support Agent` gains no Inventory, CRM, or Employees
  access through the `isAdmin()` bypass in any of those modules' permission
  traits, and gains no Support access from holding only one of *their* fixed
  roles.

## Verification

- Feature test `SupportPermissionSeederTest`: catalogue count/names; run
  twice, assert no duplicates; grant a subset to a role, assign to a user,
  assert `can()` reflects exactly that subset.
- Feature test per fixed role, covering the table above exactly — including
  the ownership-restricted rows (`ticket.work`, `service-record.execute`,
  `parts.consume` as Agent): assigned-to-self succeeds, assigned-to-another
  fails.
- Feature test: Support Agent cannot settle a payment or reverse a
  consumption under any circumstance, including on their own assigned
  ticket/service record.
- `tests/Feature/Support/CrossModulePermissionLeakTest.php` (new, mirrors
  `tests/Feature/Employees/CrossModulePermissionLeakTest.php`): a user holding
  only `Support Manager` (and separately, only `Support Agent`) is denied
  against `EmployeeProfilePolicy`, `CustomerProfilePolicy`, and a Warehouse/
  Inventory policy.
- Extend the *existing* `tests/Feature/Employees/CrossModulePermissionLeakTest.php`
  and its CRM/Inventory equivalents with the reverse case: a user holding only
  `Support Manager` or `Support Agent` is denied against `EmployeeProfilePolicy`,
  `CustomerProfilePolicy`, and Inventory policies (ADR 0004 consequence).
- Policy test per model (`TicketPolicy`, `MaintenanceRecordPolicy`,
  `MaintenanceTaskPolicy`, `SlaPolicyPolicy`): page-open check, direct-action
  check, bulk-action check, and a service-level rejection when a status
  transition or parts consumption is attempted with the UI bypassed entirely
  (FR-008; see [ticket-lifecycle.md](./ticket-lifecycle.md) and
  [maintenance-lifecycle.md](./maintenance-lifecycle.md)).
