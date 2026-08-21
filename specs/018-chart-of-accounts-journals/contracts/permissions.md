# Contract: Accounting Permissions and Roles

**Feature Directory**: `018-chart-of-accounts-journals`

**Created**: 2026-08-18

Guard: `web`. Backed by `spatie/laravel-permission`, seeded by
`AccountingPermissionSeeder`, consumed by `App\Enums\AccountingPermission` and
`App\Policies\Concerns\ChecksAccountingPermissions`.

## 1. Permission Catalogue

`App\Enums\AccountingPermission` is the single source of truth. The seeder and
every policy read from it; no permission string is written literally anywhere
else.

| Case | Value | Governs |
|---|---|---|
| `ChartAccountView` | `accounting.chart-account.view` | List and view accounts |
| `ChartAccountManage` | `accounting.chart-account.manage` | Create, edit, delete accounts |
| `FiscalPeriodView` | `accounting.fiscal-period.view` | List and view periods |
| `FiscalPeriodManage` | `accounting.fiscal-period.manage` | Create, edit, delete periods |
| `FiscalPeriodClose` | `accounting.fiscal-period.close` | Close and reopen a period |
| `JournalEntryView` | `accounting.journal-entry.view` | List and view entries |
| `JournalEntryManage` | `accounting.journal-entry.manage` | Create, edit, delete **drafts** |
| `JournalEntryPost` | `accounting.journal-entry.post` | Transition `draft` → `posted` |
| `JournalEntryReverse` | `accounting.journal-entry.reverse` | Create a reversing entry |
| `LedgerView` | `accounting.ledger.view` | Per-account balances and ledger lines |
| `AuditView` | `accounting.audit.view` | Accounting entries in the audit log |

**Three separations are deliberate and are what FR-040 requires:**

1. `JournalEntryManage` does **not** imply `JournalEntryPost`. Recording a draft
   and committing it to the ledger are different acts.
2. `JournalEntryPost` does **not** imply `JournalEntryReverse`. Reversal edits
   the meaning of already-reported history; posting only adds to it.
3. `FiscalPeriodManage` does **not** imply `FiscalPeriodClose`. Creating next
   year's periods is routine; declaring a period final is not.

## 2. Fixed Roles

Two new roles, registered in `App\Enums\DashboardRole` (R-006) so every module's
admin-bypass check narrows consistently.

| Permission | System Admin | Chief Accountant | Accountant | Reviewer |
|---|---|---|---|---|
| `chart-account.view` | ✅ | ✅ | ✅ | ✅ |
| `chart-account.manage` | ✅ | ✅ | ✅ | — |
| `fiscal-period.view` | ✅ | ✅ | ✅ | ✅ |
| `fiscal-period.manage` | ✅ | ✅ | — | — |
| `fiscal-period.close` | ✅ | ✅ | — | — |
| `journal-entry.view` | ✅ | ✅ | ✅ | ✅ |
| `journal-entry.manage` | ✅ | ✅ | ✅ | — |
| `journal-entry.post` | ✅ | ✅ | ✅ | — |
| `journal-entry.reverse` | ✅ | ✅ | — | — |
| `ledger.view` | ✅ | ✅ | ✅ | ✅ |
| `audit.view` | ✅ | ✅ | — | ✅ |

`Reviewer` is an existing cross-module role. This feature grants it the four
read permissions and `audit.view`, matching how spec 016 extended it, and grants
it nothing that writes.

`Accountant` is the day-to-day role: maintain the chart, record drafts, post
them. It cannot reverse a posted entry and cannot close a period — both require
`Chief Accountant`.

## 3. Rules Beyond the Matrix

- **R-1** A posted entry is not editable or deletable by anyone, including a
  System Admin. This is an invariant, not a permission (FR-025). No permission
  in this catalogue unlocks it; the only path is `journal-entry.reverse`.
- **R-2** `forceDelete` returns `false` on every accounting policy, via the
  shared concern. No role can hard-delete an account.
- **R-3** An entry may be posted only from `draft`. `journal-entry.post` does
  not permit un-posting; no such operation exists.
- **R-4** `ledger.view` is implied by nothing. A user may hold
  `journal-entry.view` (see entries) without `ledger.view` (see account
  balances), which is the shape needed if a data-entry clerk should record
  entries without seeing company-wide totals.
- **R-5** Every mutating service method calls
  `Gate::forUser($actor)->authorize(...)` before acting (R-010), so a direct
  service call is never a bypass. An architecture test proves those services
  never call `auth()`.

## 4. Admin Bypass Semantics

`ChecksAccountingPermissions::authorizeAccountingAbility()` mirrors the existing
`ChecksSupportPermissions` implementation exactly:

```
if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
    return true;
}

return $user->can($permission);
```

A user flagged `isAdmin()` who holds **no** fixed dashboard role keeps the
blanket bypass. A user who holds any fixed role — including one from another
module — is checked explicitly. That is the intended narrowing: assigning a
scoped role is a statement that this user's access is scoped.

### Cross-module regression risk

Adding `ChiefAccountant` and `Accountant` to `DashboardRole` narrows the bypass
for CRM, pricing, Employees, and Support as well, because all four consult the
same `fixedRoleNames()` list. A user who previously bypassed those modules on
`isAdmin()` alone and is now given an accounting role will start failing those
modules' explicit checks.

Core Inventory is **not** affected: `ChecksInventoryPermissions` has no admin
bypass at all and goes straight to the permission check, so `DashboardRole` has
never influenced it. Only the pricing policies
(`PricingTierPolicy`, `CustomerPricingTierPolicy`, `PriceFloorOverridePolicy`)
and `AuditLogPolicy` consult the list from that side of the codebase.

This is by design and is the reason the enum is central. The existing
cross-module policy tests cover it; this feature adds a test asserting that a
user holding only `Accountant` is refused by a Support and an Inventory policy
they would previously have bypassed, so the narrowing is proven rather than
assumed.

## 5. Seeder

`Database\Seeders\AccountingPermissionSeeder`, following
`SupportPermissionSeeder` exactly:

1. `forgetCachedPermissions()`.
2. `Permission::findOrCreate($value, 'web')` for every
   `AccountingPermission::values()`.
3. `forgetCachedPermissions()` again.
4. `Role::findOrCreate($name, 'web')->givePermissionTo($permissions)` for each
   role in the matrix above.

Registered in `DatabaseSeeder` after `SupportPermissionSeeder`. Idempotent —
`findOrCreate` and `givePermissionTo` are both safe to repeat.
