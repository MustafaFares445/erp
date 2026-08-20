# Tasks: Accounting Foundation — Chart of Accounts and Journal Entries

**Feature Directory**: `018-chart-of-accounts-journals`

**Created**: 2026-08-18

Ordered by dependency. `[P]` marks tasks that may run in parallel with the
others in their group.

## Phase G — Governance (blocking)

- [x] **T001** Author `Docs/adr/0007-filament-accounting-dashboard.md` (Proposed).
- [x] **T002** Amend the constitution to 1.7.0: sixth Filament exception, the
      `006` extraction-order mapping, and the "a ledger existing is not
      permission to post to it" clarification for ADR 0006.
- [x] **T003** Record both ERD deviations in `Docs/database/ERD.md` (E-1 removes
      `fiscal_periods.status`; E-2 adds `journal_entry_lines.sort_order`), plus
      the reversal-morph and immutability notes.
- [x] **T004** Add the ADR 0007 exception and its exclusion list to `Docs/PRD.md`
      §11.
- [x] **T005** Point `.specify/feature.json` at `specs\018-chart-of-accounts-journals`.
- [x] **T006** ⚠ **BLOCKING** — project owner moves ADR 0007 to **Accepted**.
      No task below may be merged until this is done.

## Phase 1 — Enums

- [x] **T007** [P] `app/Enums/AccountElement.php` — five cases,
      `normalBalance()`, `label()`.
- [x] **T008** [P] `app/Enums/NormalBalance.php` — `Debit`/`Credit`, `sign()`.
- [x] **T009** [P] `app/Enums/JournalEntryStatus.php` — `Draft`/`Posted`,
      `isPosted()`, `label()`.
- [x] **T010** [P] `app/Enums/AccountingPermission.php` — the eleven cases from
      `contracts/permissions.md` §1, plus `values()`.
- [x] **T011** Add `ChiefAccountant` and `Accountant` to `app/Enums/DashboardRole.php`.
- [x] **T012** `tests/Unit/Enums/AccountingEnumsTest.php` covering T007–T011.

## Phase 2 — Migrations

Strictly ordered by foreign key (data-model.md §11).

- [x] **T013** `create_account_types_table` — `name` unique, `normal_balance`.
- [x] **T014** `create_chart_accounts_table` — FK to `account_types`,
      self-referencing nullable `parent_id`, unique `code`, `is_postable`,
      `is_active`, blameable, soft deletes.
- [x] **T015** `create_fiscal_periods_table` — unique `name`, `starts_at`,
      `ends_at`, `is_closed`, blameable, composite index on the date pair. **No
      `status`, no `deleted_at`.**
- [x] **T016** `create_journal_entries_table` — nullable FK to `fiscal_periods`,
      unique `entry_number`, `entry_date`, `description`, nullable morph,
      `status` default `draft`, blameable. **No `deleted_at`.**
- [x] **T017** `create_journal_entry_lines_table` — FK cascade to
      `journal_entries`, FK restrict to `chart_accounts`, `debit`/`credit`
      `decimal(15,2)` default 0, `description`, `sort_order`.
- [x] **T018** `tests/Feature/Accounting/AccountingSchemaTest.php` — every
      column, index, and FK, and the deliberate absence of `deleted_at` on
      entries and lines and of `status` on periods.

## Phase 3 — Models and factories

- [x] **T019** [P] `app/Models/AccountType.php` — enum casts, `accounts()`.
- [x] **T020** [P] `app/Models/ChartAccount.php` — `TracksBlameable`,
      `SoftDeletes`, `accountType()`, `parent()`, `children()`,
      `journalEntryLines()`, `descendantIds()`.
- [x] **T021** [P] `app/Models/FiscalPeriod.php` — `TracksBlameable`, date
      casts, `journalEntries()`, `contains(date)` scope.
- [x] **T022** `app/Models/JournalEntry.php` — `TracksBlameable`, status cast,
      `lines()`, `fiscalPeriod()`, `source()` morph, `reversal()`,
      `entry_number` generation on create, **and the `booted()` immutability
      guard**.
- [x] **T023** `app/Models/JournalEntryLine.php` — `entry()`, `chartAccount()`,
      **and the `booted()` parent-posted guard**.
- [x] **T024** [P] Five factories, with states: `ChartAccountFactory::postable()`
      / `::header()`, `FiscalPeriodFactory::closed()`,
      `JournalEntryFactory::posted()` / `::balanced()`.

## Phase 4 — Permissions and policies

- [x] **T025** `app/Policies/Concerns/ChecksAccountingPermissions.php` —
      mirroring `ChecksSupportPermissions`, with `forceDelete(): false`.
- [x] **T026** [P] `app/Policies/ChartAccountPolicy.php`.
- [x] **T027** [P] `app/Policies/FiscalPeriodPolicy.php` — including `close`.
- [x] **T028** [P] `app/Policies/JournalEntryPolicy.php` — including `post` and
      `reverse`, with `update`/`delete` refused for a posted entry regardless of
      permission (R-1).
- [x] **T029** `database/seeders/AccountingPermissionSeeder.php` — the role
      matrix from `contracts/permissions.md` §2.
- [x] **T030** Register it in `database/seeders/DatabaseSeeder.php` after
      `SupportPermissionSeeder`.
- [x] **T031** `tests/Unit/Policies/AccountingPolicyTest.php` — the full matrix,
      the three separations, `forceDelete`, admin bypass.
- [x] **T032** `tests/Unit/Policies/AccountingRoleNarrowingTest.php` — a user
      holding only `Accountant` is refused by a Support and an Inventory policy
      they would previously have bypassed.

## Phase 5 — Domain exceptions

- [x] **T033** The nine exceptions in `app/Services/Accounting/Exceptions/`
      listed in data-model.md §9, each with a named constructor carrying its
      context.

## Phase 6 — Chart of accounts service and seeder

- [x] **T034** `app/Services/Accounting/ChartOfAccountService.php` — create,
      update, delete with C-1..C-5, cycle detection, `is_postable` auto-clear.
- [x] **T035** `database/seeders/ChartOfAccountsSeeder.php` — the five account
      types, then a starting chart of non-postable headers with postable leaves.
      Idempotent.
- [x] **T036** Register in `DatabaseSeeder`.
- [x] **T037** `tests/Feature/Accounting/ChartOfAccountServiceTest.php` —
      FR-005..FR-012.
- [x] **T038** `tests/Feature/Accounting/AccountingSeederTest.php` — idempotency,
      exactly five types, every leaf postable and every header not.

## Phase 7 — Fiscal periods

- [x] **T039** `app/Services/Accounting/FiscalPeriodService.php` — create,
      update, delete, `close()`, `reopen()`, `forDate()`, with P-1..P-3 and audit
      logging.
- [x] **T040** `tests/Feature/Accounting/FiscalPeriodServiceTest.php` —
      FR-013..FR-018, overlap inside the transaction, close/reopen audit rows.

## Phase 8 — Posting (the core)

- [x] **T041** `app/Services/Accounting/JournalPostingService.php` — `draft()`,
      `post()`, `reverse()`, `postNew()`, in the exact validation order of
      `contracts/journal-posting.md` §2, with integer-minor-unit arithmetic and
      `lockForUpdate()`.
- [x] **T042** `tests/Feature/Accounting/JournalPostingServiceTest.php` — one
      test per rejection reason, plus the `33.33/33.34` rounding case, the
      two-line minimum, the closed-period and missing-period cases, and the
      concurrent-post guard.
- [x] **T043** `tests/Feature/Accounting/PostedEntryImmutabilityTest.php` —
      model-layer guard: `update()`, `delete()`, and line create/update/delete
      all throw on a posted entry, while the same operations succeed on a draft.
- [x] **T044** `tests/Feature/Accounting/JournalReversalTest.php` —
      FR-027..FR-029, mirrored amounts, morph link, double reversal refused,
      closed-period reversal refused, net-zero pairing, custom reversal date.

## Phase 9 — Balances and ledger

- [x] **T045** `app/Services/Accounting/AccountBalanceService.php` —
      `balanceFor()`, `balancesForAll()`, `ledgerFor()`.
- [x] **T046** `tests/Feature/Accounting/AccountBalanceServiceTest.php` —
      FR-035..FR-038, both sign conventions, descendant roll-up, draft exclusion,
      and a query-count assertion on `balancesForAll()`.

## Phase 10 — Filament resources

- [x] **T047** `ChartOfAccounts/` — resource, `ChartAccountForm`,
      `ChartAccountsTable` with the balance column and type filter, List, Create,
      Edit, View pages, and `LedgerRelationManager` for FR-038. Nav sort 201.
- [x] **T048** `JournalEntries/` — resource, `JournalEntryForm` with the lines
      repeater and a live running total, `JournalEntriesTable`, List, Create,
      Edit, View pages, and the `Post` and `Reverse` actions calling
      `JournalPostingService`. Posted entries render read-only. Nav sort 202.
- [x] **T049** `FiscalPeriods/` — resource, form, table, List/Create/Edit pages,
      and the `Close`/`Reopen` actions calling `FiscalPeriodService`. Nav sort 203.
- [x] **T050** Add the `fiscal_periods` item to the `accounting` group in
      `app/Filament/AdminModuleRegistry.php`, between `journal_entries` and
      `accounts_receivable`.
- [x] **T051** Register the three resources in
      `app/Providers/Filament/AdminPanelServiceProvider.php` (explicit
      registration — auto-discovery was removed).
- [x] **T052** Add English labels to `lang/en/admin.php`: the
      `fiscal_periods` resource key and an `accounting.*` block for field and
      action labels. Arabic falls back per the note at the top of
      `lang/ar/admin.php`.
- [x] **T053** `tests/Feature/Accounting/AccountingResourceTest.php` — pages
      load per role; `Post`, `Reverse`, and `Close` actions present or absent per
      the matrix.
- [x] **T054** `tests/Feature/Accounting/AccountingNavigationTest.php` — the
      three real links resolve; the other six `accounting` items still render
      `ModulePlaceholder` (SC-004).
- [x] **T055** `tests/Feature/Accounting/AccountingEnglishLabelsTest.php`.

## Phase 11 — Demo data, architecture, gate

- [x] **T056** `database/seeders/AccountingDemoSeeder.php` — twelve monthly
      periods for the current year, a handful of posted entries, one reversal,
      one draft. Registered in `DatabaseSeeder` after the demo seeders.
- [x] **T057** `tests/Unit/ArchTest.php` updates:
      (a) add `JournalEntry` and `JournalEntryLine` to the strict-preset
      `ignoring()` list with a comment explaining the `booted()` guard, following
      the `TicketMessage` precedent;
      (b) add `App\Services\Accounting\Exceptions` to the Laravel-preset
      `ignoring()` list;
      (c) new assertion — `App\Filament` may not use `JournalEntryLine` outside
      the `JournalEntries` and `ChartOfAccounts` namespaces;
      (d) extend the no-`auth()` service rule to `App\Services\Accounting`.
- [x] **T058** `tests/Feature/Accounting/NoAutomaticPostingTest.php` — SC-008.
- [x] **T059** `vendor/bin/pint --dirty --format agent`.
- [x] **T060** `vendor/bin/phpstan analyse` — zero new baseline entries. Two
      obsolete entries were *removed* (the `ChartOfAccountResource` and
      `JournalEntryResource` "class not found" patterns in
      `AdminModuleRegistry`), so the baseline shrank from 39 entries to 37.
- [x] **T061** `composer test` green.
- [x] **T062** Walk `quickstart.md` end to end against the seeded database.
      Verified against the local dev database after `php artisan migrate` +
      `db:seed --class=AccountingDemoSeeder`: 5 types / 27 accounts / 12 periods /
      7 entries / 14 lines; the eleven expected routes registered; balance signs,
      header roll-up (`1000` = 136,650.00 = 118,250.00 + 18,400.00), the
      reversed pair netting to 0.00 on `5300`, and draft exclusion on `1300` all
      as specified; the audit trail carrying two rows per reversal plus
      `accounting.fiscal_period.closed`, correctly attributed. The purely visual
      browser walk (Scenarios 1–6 as clicked in the UI) is **not** done — it needs
      an interactive dashboard login. Its behavioural content is covered by
      `AccountingResourceTest`, `AccountingNavigationTest`, and
      `AccountingEnglishLabelsTest`.

## Deviations discovered during implementation

- **`ChartAccountForm` unique rule.** The `modifyRuleUsing: fn ($rule) =>
  $rule->withoutTrashed(false)` written for T047 silently disabled unique
  validation on `code`: `withoutTrashed()` takes a *column name*, so `false`
  became `whereNull('false')`. Filament never applies `withoutTrashed()` itself,
  so the intended behaviour (trashed codes stay reserved, C-1) is the default and
  the whole closure was removed. Regression-tested in `AccountingResourceTest`.
- **Entry-number allocation moved into the service.** `JournalPostingService::
  createEntry()` now calls `JournalEntry::nextEntryNumber()` directly instead of
  relying on the model's `creating` hook, because `DatabaseSeeder` runs under
  `WithoutModelEvents` and muted it. The hook stays as the backstop for direct and
  factory writes, mirroring `InventoryOperationService::nextOperationNumber()`.
- **`DatabaseSeederTest` and `TicketPaymentTest` updated.** The former's expected
  permission catalogue grew by the eleven `accounting.*` entries. The latter
  asserted `journal_entries` does not exist; it now asserts the table exists and
  stays empty after a chargeable settlement, which is the SC-008 intent.
- **`@property` docblocks added to the five accounting models**, following the
  `InventoryOperation` precedent, so PHPStan resolves `$model->id` and friends
  instead of `mixed` — this removed ~60 `cast.*` errors at the root rather than
  by casting.

## Dependency Notes

- T006 blocks the merge of everything, not the writing of it.
- T013–T017 are strictly sequential; every later phase depends on them.
- T025 must precede T026–T028; T029 must precede T031.
- T033 must precede T034, T039, and T041.
- T041 must precede T042–T044, T045, and T048.
- T050–T052 must precede T053–T055.
- T057 must precede T061, since the strict preset otherwise fails on the two
  `booted()` overrides.
