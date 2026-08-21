# Implementation Plan: Accounting Foundation — Chart of Accounts and Journal Entries

**Feature Directory**: `018-chart-of-accounts-journals`

**Created**: 2026-08-18

**Spec**: `specs/018-chart-of-accounts-journals/spec.md`

## Summary

Build extraction entry `006-chart-of-accounts-and-journals`: five ERD-defined
tables, three Filament resources, three domain services, and an
`accounting.*` permission catalogue with two new fixed roles. The module's
correctness rests on two invariants — an unbalanced entry cannot be posted, and
a posted entry cannot be changed — each enforced at more than one layer and
proven by tests.

Nothing is wired to the ledger. The posting interface is built and left unwired
for `007`/`008`/`009` to call.

## Technical Context

**Language**: PHP 8.4. **Framework**: Laravel 13, Filament 5, Livewire 3.
**Testing**: Pest 4 with Xdebug coverage. **Static analysis**: Larastan 3 at the
configured level, baseline may only shrink. **Formatting**: Pint.

**New dependencies**: none. D4's `barryvdh/laravel-dompdf` belongs to `007` and
must not appear in `composer.json` as part of this feature.

**Existing code this builds on**: `User` and `spatie/laravel-permission`
(`003`); `DashboardRole`; `TracksBlameable`; `spatie/laravel-activitylog`
(ADR 0005); `AdminModuleRegistry` and the `AdminPanelServiceProvider`
navigation contract; the `ChecksSupportPermissions` concern as the template for
`ChecksAccountingPermissions`; `InventoryOperationService::nextOperationNumber()`
as the numbering template.

**Existing code this must not touch**: `orders`, `order_lines`,
`InventoryOperation`, `Shipment`, `OrderFulfillmentService`, tickets and
`ticket_payment_links`, and every inventory service. SC-008 asserts none of them
gains a ledger write.

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I — ERD canonical | ✅ | Two deviations, both authorised by ADR 0007 and recorded in the ERD itself and in §ERD Divergence Register |
| II — Backend owns business rules | ✅ | Every invariant is in a service, several also at the model layer; the Filament layer calls services and never performs a status transition itself |
| III — Stock movements | ✅ **not engaged** | No accounting record here changes a `product_variant_id + warehouse_id` balance |
| IV — Audit logging | ✅ | Postings, reversals, period closes/reopens logged via `activity()` per ADR 0005 |
| Product Scope | ⚠ **gated** | Requires ADR 0007 **Accepted**; see §Governance Gate |
| Specification Governance | ✅ | Corresponds to extraction entry `006`; prerequisites `002`/`003` are built |

### Governance Gate — blocking prerequisites

No task below may begin until:

1. `Docs/adr/0007-filament-accounting-dashboard.md` moves from **Proposed** to
   **Accepted**.
2. The constitution amendment to **1.7.0** is merged.

Both artifacts are authored. The ERD deviations are already recorded, so gate
item 3 from the spec is discharged.

Independent of `017-purchasing-orders-suppliers`, which is blocked on its own
ADR 0006 and may land before or after this.

## Project Structure

### Documentation (this feature)

```
specs/018-chart-of-accounts-journals/
├── spec.md
├── plan.md                      (this file)
├── research.md                  R-001..R-013
├── data-model.md                5 tables, enums, invariants, migration order
├── quickstart.md                manual validation scenarios
├── tasks.md                     ordered task list
└── contracts/
    ├── permissions.md           catalogue, role matrix, bypass semantics
    └── journal-posting.md       service surface, validation order, audit
```

### Source Code (repository root)

```
app/
├── Enums/
│   ├── AccountElement.php                    new
│   ├── NormalBalance.php                     new
│   ├── JournalEntryStatus.php                new
│   ├── AccountingPermission.php              new
│   └── DashboardRole.php                     modified (+2 cases)
├── Models/
│   ├── AccountType.php                       new
│   ├── ChartAccount.php                      new
│   ├── FiscalPeriod.php                      new
│   ├── JournalEntry.php                      new  (booted() guard)
│   └── JournalEntryLine.php                  new  (booted() guard)
├── Policies/
│   ├── Concerns/ChecksAccountingPermissions.php   new
│   ├── ChartAccountPolicy.php                new
│   ├── FiscalPeriodPolicy.php                new
│   └── JournalEntryPolicy.php                new
├── Services/Accounting/
│   ├── JournalPostingService.php             new
│   ├── AccountBalanceService.php             new
│   ├── ChartOfAccountService.php             new
│   ├── FiscalPeriodService.php               new
│   └── Exceptions/                           9 domain exceptions
├── Filament/
│   ├── AdminModuleRegistry.php               modified (+fiscal_periods item)
│   └── Resources/
│       ├── ChartOfAccounts/                  new  (List/Create/Edit/View, ledger RM)
│       ├── FiscalPeriods/                    new  (List/Create/Edit)
│       └── JournalEntries/                   new  (List/Create/Edit/View, lines RM)
└── Providers/Filament/AdminPanelServiceProvider.php   modified (+3 resources)

database/
├── migrations/                               5 new
├── factories/                                5 new
└── seeders/
    ├── AccountingPermissionSeeder.php        new
    ├── ChartOfAccountsSeeder.php             new  (types + starting chart)
    ├── AccountingDemoSeeder.php              new  (periods + sample entries)
    └── DatabaseSeeder.php                    modified

lang/en/admin.php                             modified (+resource and field keys)
tests/
├── Feature/Accounting/                       new
└── Unit/                                     ArchTest modified; enum + policy tests
```

**Structure decision**: one namespace per concern, matching every module built
so far. `App\Services\Accounting` groups the four services; exceptions sit
beside them rather than in `App\Exceptions`, following the
`App\Services\Support\Exceptions` precedent and requiring the same `ArchTest`
Laravel-preset exemption.

Chart of Accounts gets a `View` page because the account ledger (FR-038) needs
somewhere to live; Fiscal Periods does not, since a period has nothing to show
beyond its own fields.

## Implementation Phases

### Phase G — Governance (blocking, no code)

- G1 ADR 0007 authored. **Done.**
- G2 Constitution amended to 1.7.0. **Done.**
- G3 ERD deviations recorded. **Done.**
- G4 PRD §11 lists the ADR 0007 exception. **Done.**
- G5 **ADR 0007 moved to Accepted by the project owner. Outstanding — blocks
  everything below.**

### Phase 1 — Schema and models (US2, US3, US4 foundations)

Five migrations in FK order, five models with casts and relations, five
factories. The two `booted()` guards land with their models, not later, so no
window exists in which a posted entry is mutable.

### Phase 2 — Permissions and policies (US1)

`AccountingPermission`, the two `DashboardRole` cases,
`ChecksAccountingPermissions`, three policies, `AccountingPermissionSeeder`.
Done before any Filament class exists, so no resource is ever briefly
unauthorized.

### Phase 3 — Chart of accounts service and seeder (US2)

`ChartOfAccountService` with the cycle, leaf-postable, and deletability rules.
`ChartOfAccountsSeeder` seeds the five account types and the starting chart.

### Phase 4 — Fiscal periods (US3)

`FiscalPeriodService` with overlap, ordering, deletability, and close/reopen with
audit logging.

### Phase 5 — Posting (US4, US5) — the core

`JournalPostingService` with `draft()`, `post()`, `reverse()`, `postNew()`,
validated in the order `contracts/journal-posting.md` §2 fixes, plus the nine
domain exceptions. This is the phase whose tests matter most.

### Phase 6 — Balances and ledger (US6)

`AccountBalanceService` with `balanceFor()`, `balancesForAll()`, `ledgerFor()`.

### Phase 7 — Filament resources and navigation

Three resources, the lines repeater, the post/reverse/close actions, the ledger
relation manager, registration in `AdminPanelServiceProvider`, the
`fiscal_periods` navigation item, and the English labels.

### Phase 8 — Demo data, architecture tests, and gate

`AccountingDemoSeeder`; `ArchTest` updates (three: the two `booted()` strict
exemptions, the `App\Services\Accounting\Exceptions` Laravel-preset exemption,
the `App\Filament` ledger-write ban, and extending the no-`auth()` service rule);
SC-008's no-automatic-posting regression test; then Pint, PHPStan, and the full
suite.

## Testing Strategy

Every FR maps to at least one test. The concentration is deliberate: the posting
service carries the module's two real risks and gets the densest coverage.

| Area | File | Covers |
|---|---|---|
| Schema | `tests/Feature/Accounting/AccountingSchemaTest.php` | Columns, indexes, FKs, absence of `deleted_at` on entries and lines |
| Enums | `tests/Unit/Enums/AccountingEnumsTest.php` | `AccountElement::normalBalance()`, `NormalBalance::sign()`, `JournalEntryStatus`, `AccountingPermission::values()` |
| Permissions | `tests/Unit/Policies/AccountingPolicyTest.php` | The full role matrix, the three separations, `forceDelete` false, admin-bypass narrowing |
| Cross-module | `tests/Unit/Policies/AccountingRoleNarrowingTest.php` | A user holding only `Accountant` is refused by a Support and an Inventory policy they would previously have bypassed |
| Chart | `tests/Feature/Accounting/ChartOfAccountServiceTest.php` | FR-005..FR-012, cycle detection, auto-clear of `is_postable` |
| Periods | `tests/Feature/Accounting/FiscalPeriodServiceTest.php` | FR-013..FR-018, overlap, close/reopen audit |
| Posting | `tests/Feature/Accounting/JournalPostingServiceTest.php` | FR-019..FR-034 — every rejection reason separately, the `33.33/33.34` rounding case, concurrent-post guard |
| Immutability | `tests/Feature/Accounting/PostedEntryImmutabilityTest.php` | FR-025 at the model layer: direct `update()`, `delete()`, and line append/edit/delete all throw |
| Reversal | `tests/Feature/Accounting/JournalReversalTest.php` | FR-027..FR-029, double reversal refused, closed-period reversal refused, net-zero pairing |
| Balances | `tests/Feature/Accounting/AccountBalanceServiceTest.php` | FR-035..FR-038, sign convention both ways, descendant roll-up, draft exclusion |
| Resources | `tests/Feature/Accounting/AccountingResourceTest.php` | Pages load per role; post/reverse/close actions present or absent per the matrix |
| Labels | `tests/Feature/Accounting/AccountingEnglishLabelsTest.php` | Navigation and field labels, following `CrmEnglishLabelsTest` |
| Navigation | `tests/Feature/Accounting/AccountingNavigationTest.php` | Three real links; the other six still render `ModulePlaceholder` (SC-004) |
| Seeders | `tests/Feature/Accounting/AccountingSeederTest.php` | Idempotency of both seeders; exactly five account types |
| No auto-post | `tests/Feature/Accounting/NoAutomaticPostingTest.php` | SC-008 — exercising order, delivery, ticket-payment, and inventory paths leaves `journal_entries` empty |
| Architecture | `tests/Unit/ArchTest.php` | Filament ledger-write ban; no `auth()` in `App\Services\Accounting` |

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Float arithmetic silently accepting an unbalanced entry | All comparison on integer minor units (R-001), with an explicit `33.33/33.34` test |
| A future change wiring a document to the ledger without an ADR | SC-008's regression test fails the moment any existing path posts |
| A future direct `$entry->update()` rewriting posted history | Model-layer guard (R-002) plus the Filament arch ban (R-011) |
| Adding two `DashboardRole` cases silently narrowing four other modules' admin bypass | Called out in `contracts/permissions.md` §4 and proven by `AccountingRoleNarrowingTest` |
| Concurrent posts both succeeding | `lockForUpdate()` plus a status re-read inside the transaction (FR-031) |
| `balancesForAll()` degrading into N+1 on the Chart of Accounts table | One aggregate query plus one in-memory tree walk; asserted by a query-count test |
| Scope creep into a trial balance | Explicitly out of scope in ADR 0007 and the spec; the balance surface is deliberately per-account only |

## Complexity Tracking

No constitutional violation requires justification. Two things are worth naming
as deliberate cost:

- **Two-layer enforcement of immutability** duplicates a rule between the
  service and the model. Accepted because the model guard is the only thing that
  stops code written later, by someone who does not know the rule, from
  rewriting posted history.
- **Computed rather than stored balances** trades query cost for the guarantee
  that a balance can never disagree with the lines that justify it (R-008). At
  this feature's volumes the cost is negligible, and a cache remains additive.

## Next Steps

1. Obtain ADR 0007 approval (Phase G5) — the only blocking item.
2. Work `tasks.md` in order.
3. Run `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, and
   `composer test` before opening the PR.
4. `007-sales-flow-quotation-delivery-invoice` is unblocked as to this
   prerequisite once this lands. D2, D3, and D4 are already settled for it.
