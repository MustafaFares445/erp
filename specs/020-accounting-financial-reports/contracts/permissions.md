# Contract: Permissions

**Feature**: `020-accounting-financial-reports` | **Catalogue**: `App\Enums\AccountingPermission`

## §1 The addition

Exactly one case is added to the existing enum, which remains the single source of truth consumed by `AccountingPermissionSeeder` and `ChecksAccountingPermissions` (FR-001):

```php
case ReportView = 'accounting.report.view';
```

The catalogue goes from 11 permissions to 12. No existing case is renamed, removed, or given a new value.

## §2 Non-implication rules

| Rule | Requirement |
|---|---|
| `accounting.ledger.view` MUST NOT imply `accounting.report.view` | FR-002 |
| No other existing permission MUST imply it | FR-002 |
| It MUST NOT imply any other permission | FR-002 |

**Why this separation is load-bearing rather than granularity for its own sake.** `ledger.view` grants one account's own posted lines, reached from that account's page on the Chart of Accounts — a targeted look at a single account. `report.view` grants the whole book in aggregate: every account, every posted line, in a form built for circulation outside the system. Someone trusted to check one account's history is not automatically someone trusted to export the company's balance sheet. This mirrors the three separations spec 018 already documents in the enum's docblock.

## §3 Role matrix delta

Added to `AccountingPermissionSeeder::rolePermissions()`:

| Role | `accounting.report.view` | Reasoning |
|---|---|---|
| System Admin | ✅ (via `AccountingPermission::values()`) | already receives the whole catalogue |
| Chief Accountant | ✅ (via `values()`) | already receives the whole catalogue |
| Accountant | ✅ **explicit entry required** | FR-003; reading the statements is day-to-day work |
| Reviewer | ✅ **explicit entry required** | FR-003; the read-only oversight role |

System Admin and Chief Accountant need no seeder edit — both are assigned `AccountingPermission::values()`, so the new case flows through automatically. Accountant and Reviewer hold explicit lists and each needs one line added.

No role outside the accounting catalogue receives it (FR-003).

The seeder MUST stay idempotent (FR-007). `Permission::findOrCreate` and `givePermissionTo` are both safe to repeat, so the existing structure needs no change beyond the two list entries.

## §4 Admin-bypass behaviour

Unchanged, and inherited from `ChecksAccountingPermissions::authorizeAccountingAbility()`:

- A user flagged `isAdmin()` holding **no** fixed dashboard role keeps the blanket bypass.
- A user holding **any** fixed role — including another module's — is checked explicitly against the permission.

Adding `ChiefAccountant` and `Accountant` to `DashboardRole` in spec 018 already narrowed every module's bypass; this feature adds no role and so changes nothing here.

## §5 Enforcement points

Three, and all three must check the same permission:

| Point | Mechanism |
|---|---|
| `FinancialReportResource::canAccess()` / `canViewAny()` | `$actor instanceof User && $actor->can(AccountingPermission::ReportView->value)`, following `PurchasingReportResource` exactly |
| Page render | re-checked on the page, so a direct URL cannot bypass the resource gate |
| **Every CSV export request** | re-checked **inside** the export method, not only on the action's `visible()` |

**§5.3 is the one that matters.** FR-005 requires the export to be gated server-side on the export request itself. An export whose only guard is the visibility of the button that triggers it is not guarded — the request can be issued directly. `ListPurchasingReports` establishes the pattern: `visible()`, `authorize()`, *and* an `authorizeReportAccess()` call at the top of the streaming method. All three, not one.

`canCreate()` returns `false`. There is nothing to create.

## §6 What is not added

- **No new role.** `DashboardRole` is unchanged.
- **No per-report permission.** One permission covers all five reports and all five exports; research [§R7](../research.md) records why, and FR-001 requires it.
- **No write, approve, post, or reverse ability.** This feature has no write path, so it has no permission for one.
- **No policy class.** There is no model to authorise — the resource's nominal model is only what Filament requires, and the page renders aggregates rather than records. Gating lives on the resource and the page, as it does for `PurchasingReportResource` and `SupportReportResource`.

## §7 Tests this contract requires

| Assertion | SC |
|---|---|
| A user with `accounting.report.view` opens every report and completes every export | SC-008 |
| A user with `accounting.journal-entry.post` + `accounting.ledger.view` but not `report.view` is refused, and the nav link is absent | SC-008 |
| Reviewer can read every report and has no action that changes a record | SC-008 |
| An export requested directly, without the permission, is refused | SC-008, FR-005 |
| The seeder run twice produces the same grants and no duplicate rows | FR-007 |
| `AccountingPermission::values()` contains 12 entries and the new value string is exactly `accounting.report.view` | FR-001 |
