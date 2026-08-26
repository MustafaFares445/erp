---

description: "Task list for Accounting Financial Reports"
---

# Tasks: Accounting Financial Reports

**Input**: Design documents from `/specs/020-accounting-financial-reports/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md) — all complete and authoritative.

**Tests**: **Required, not optional.** Constitution Principle VI.6 and `.ai/feature-development` rule 5 both mandate a test for every implemented behaviour. Test tasks below are not negotiable and may not be dropped to hit a deadline.

**Governance**: ✅ Gate cleared 2026-08-23 — ADR 0009 Accepted, constitution 1.9.0, PRD §11 qualified. T001 verifies rather than blocks.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1–US7, mapping to the user stories in [spec.md](./spec.md)

## Path Conventions

Existing modular-monolith layout. No new base folder. One new subdirectory, `app/Services/Accounting/Support/`, inside the existing accounting domain folder.

## ⛔ Standing prohibitions for every task below

These are the ways this feature can fail governance *after* being authorised. Any task whose implementation would require one of these is wrong, and the answer is to stop rather than to proceed.

1. **No migration.** No task in this list creates, alters, or drops a table, column, or index. If one seems necessary, the design is being misread.
2. **No write of any kind.** No create, update, delete, or upsert on any table, from any class in this feature (FR-052).
3. **No posting caller.** Nothing here calls `JournalPostingService`. ADR 0008's three commercial-document events plus the manual journal path remain the complete list. A "post the year-end close from the Balance Sheet" convenience is one line of plausible code and would be a governance breach (FR-053).
4. **No refactor of `AccountBalanceService`.** Research [§R6](./research.md) and constitution Principles II and VI.7. Its behaviour must be unchanged and its tests must pass untouched (FR-054).
5. **No new dependency.** No Composer or npm package. `barryvdh/laravel-dompdf` is not installed here.
6. **No account referenced by code.** Statements group by account *type*, never by a hard-coded code such as `3200`.

---

## Phase 1: Setup

**Purpose**: Verify the gate, then add the two shared vocabulary items every story needs.

- [X] T001 Verify the governance gate before writing any code: confirm `Docs/adr/0009-accounting-financial-reports.md` reads `**Status**: Accepted`, that `.specify/memory/constitution.md` footer reads `**Version**: 1.9.0` and contains the "eighth narrow exception" paragraph referencing ADR 0009, and that `Docs/PRD.md` §11 carries the ADR 0009 qualifier. Stop and report if any of the three is missing — a task list outlives the session that wrote it.
- [X] T002 Create `app/Enums/FinancialReportType.php` with exactly five cases — `TrialBalance`, `GeneralLedger`, `ProfitAndLoss`, `BalanceSheet`, `PostingRegister` — a `label()` returning `__('admin.accounting.report_type.'.$this->value)`, and a `values(): list<string>` helper, mirroring the shape of `app/Enums/InventoryReportType.php`. Deliberately **no** `sourcePermission()` method — research [§R7](./research.md) records that all five share one permission.
- [X] T003 Add `case ReportView = 'accounting.report.view';` to `app/Enums/AccountingPermission.php`, extending the existing docblock to record that it is implied by no other permission and specifically not by `LedgerView`, which grants one account rather than the whole book in aggregate. See [contracts/permissions.md](./contracts/permissions.md) §2.

**Checkpoint**: The enum and permission exist. Nothing is wired yet.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The aggregation core, the page shell, and the navigation fix. Every user story depends on this phase.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

### Aggregation core

- [X] T004 [P] Create `app/Services/Accounting/Support/LedgerAggregate.php` as a `final readonly` value object exposing `debitMinorFor(int)`, `creditMinorFor(int)`, `netMinorFor(int)`, and `accountIds(): list<int>`, all in integer minor units. Built from **one** grouped aggregate query filtered to `journal_entries.status = 'posted'` with a caller-supplied `entry_date` predicate, using `COALESCE(SUM(...), 0)` so a missing account yields `0` and no caller needs a null check. Follow the query shape of `AccountBalanceService::netMinorUnitsByAccount()` and convert via `JournalEntryLine::toMinorUnits()`. See research [§R1](./research.md).
- [X] T005 [P] Create `app/Services/Accounting/Support/AccountTree.php` as a `final readonly` helper exposing `displayOrder(): list<ChartAccount>`, `rollUp(int $accountId, callable $ownValue): int`, `childIdsOf(int)`, and `selfAndDescendantIds(int)`. `displayOrder()` is depth-first — each account immediately followed by its descendants, siblings ordered by `code` as a string — computed in memory, **not** as a SQL `ORDER BY` (research [§R5](./research.md)). `rollUp()` carries a `$visited` set so a cyclic hierarchy terminates (FR-015). Loads accounts once with `accountType` eager-loaded. **Do not** extract anything out of `AccountBalanceService` to build this — research [§R6](./research.md).
- [X] T006 [P] Create `tests/Feature/Accounting/AccountTreeTest.php` covering: depth-first order places a parent immediately above its children; sibling order is by code and is stable across runs; `rollUp()` sums a three-level subtree; `rollUp()` terminates on a hierarchy cycle written directly to the database (FR-015); and a parent's rolled-up figure counts each descendant exactly once (**invariant I-10**, FR-014).
- [X] T007 Create `app/Services/Accounting/FinancialReportService.php` as `final readonly` with the six public methods from [contracts/financial-report-service.md](./contracts/financial-report-service.md) §4, each initially throwing `LogicException` pending its story phase. Implement now only: the shared date-bound resolution, rejection of an inverted range with a validation exception rather than an empty result (FR-010), `fiscalPeriodOptions()` returning open and closed periods alike (FR-011), and the private helpers that build a `LedgerAggregate` per bound and an `AccountTree` per report. Sign every balance through `NormalBalance::sign()` — never reimplement the rule (FR-013).
- [X] T008 [P] Create `tests/Feature/Accounting/FinancialReportServiceTest.php` with the shared fixture from [quickstart.md](./quickstart.md) §"The fixture that makes every proof checkable" — one January fiscal period; accounts `1100`, `1200`, `4100`, `5300`; entries E1–E5 including one draft and one February entry. Assert only the Phase 2 surface for now: an inverted range throws; `fiscalPeriodOptions()` includes a closed period; date bounds are inclusive at both edges (**SC-006**, all four cases — day before start, exactly start, exactly end, day after end).

### Presentation shell

- [X] T009 Create `app/Filament/Resources/FinancialReports/FinancialReportResource.php` following `app/Filament/Resources/PurchasingReports/PurchasingReportResource.php` exactly: `$model = JournalEntry::class` as the nominal model Filament requires, `$shouldRegisterNavigation = false`, `Heroicon::OutlinedDocumentChartBar`, `getNavigationLabel()` returning `__('admin.resources.financial_reports')`, empty `form()`, pass-through `table()` with the `@codeCoverageIgnore` comment explaining Filament never calls it, `canCreate()` returning `false`, and `canAccess()`/`canViewAny()` checking `AccountingPermission::ReportView`.
- [X] T010 Create `app/Filament/Resources/FinancialReports/Pages/ViewFinancialReports.php` as a plain `Filament\Resources\Pages\Page` — **not** `ManageRecords` with tabs (research [§R2](./research.md)). Holds the report-type selector bound to `FinancialReportType`, the date-range and as-of-date filters, the fiscal-period convenience selector, and `getViewData()` dispatching to the service. Re-check the permission on the page so a direct URL cannot bypass the resource gate ([contracts/permissions.md](./contracts/permissions.md) §5).
- [X] T011 Create `resources/views/filament/financial-reports/view-financial-reports.blade.php` as the shell: report-type selector, filter controls, and an `@include` of the per-report partial. Wrap every wide table in an `overflow-x: auto` container. Create the five partial files under `resources/views/filament/financial-reports/partials/` as empty placeholders so each story phase can fill its own file in parallel.

### Navigation defect N-1

- [X] T012 Remove the duplicate `admin.resources.financial_reports` entry from the `accounting` group in `app/Filament/AdminModuleRegistry.php:137`, keeping the `reports`-group entry at line 236 (decision **D3**, FR-048). The accounting group is left with six placeholder items. Do not touch any other group.
- [X] T013 [P] Extend `tests/Unit/AdminModuleRegistryTest.php` with a test asserting that **no navigation label is registered in more than one group**, across every group in `AdminModuleRegistry::groups()` (FR-050). Assert the general invariant, not the single instance — fixing one duplicate fixes one duplicate; the invariant fixes the next one too.
- [X] T014 [P] Add English keys to `lang/en/admin.php` for the five report-type labels, the filter labels, and the section and proof labels. Arabic falls back per the convention at the top of `lang/ar/admin.php` — do **not** add Arabic keys (FR-051).

**Checkpoint**: The page renders empty, is permission-gated, and appears exactly once in the sidebar. Every story can now proceed.

---

## Phase 3: User Story 1 — Grant and Withhold Report Access (Priority: P1) 🎯 MVP

**Goal**: The surface exists and only the right roles can reach it, including via a direct export request.

**Independent test**: Seed the catalogue, assign each role, assert page access and export access per role. Delivers the access-control guarantee before any figure renders.

- [X] T015 [US1] Add explicit `AccountingPermission::ReportView` entries for the `Accountant` and `Reviewer` roles in `database/seeders/AccountingPermissionSeeder.php`. System Admin and Chief Accountant need **no** edit — both are assigned `AccountingPermission::values()` and inherit the new case automatically ([contracts/permissions.md](./contracts/permissions.md) §3). Keep the seeder idempotent (FR-007).
- [X] T016 [P] [US1] Add a map entry for the report ability in `app/Policies/Concerns/ChecksAccountingPermissions.php` only if the page's gating needs one; if the resource-level `$actor->can(...)` check is sufficient — as it is for `PurchasingReportResource` — make no change and record that in the task's completion note.
- [X] T017 [US1] Create `tests/Feature/Accounting/FinancialReportResourceTest.php` asserting: a System Admin, Chief Accountant, Accountant, and Reviewer each open the page and see all five report types selectable; a user holding `accounting.journal-entry.post` and `accounting.ledger.view` but **not** `accounting.report.view` is refused and the navigation link is absent; and the Reviewer's page offers no action that changes a record (**SC-008**, FR-004).
- [X] T018 [P] [US1] Add a test asserting `AccountingPermission::values()` returns 12 entries, that the new value string is exactly `accounting.report.view`, and that running `AccountingPermissionSeeder` twice produces identical grants with no duplicate rows (FR-001, FR-007).

**Checkpoint**: US1 shippable. The surface is reachable by exactly the intended roles and by nobody else.

---

## Phase 4: User Story 2 — Prove the Ledger Foots with a Trial Balance (Priority: P1)

**Goal**: A four-column trial balance whose totals foot, with the proof displayed and never repaired.

**Independent test**: Post the fixture, open the trial balance, verify every figure and the footing proof by hand against [quickstart.md](./quickstart.md) §Scenario 1.

- [X] T019 [US2] Implement `FinancialReportService::trialBalance(from, to)` per [contracts/financial-report-service.md](./contracts/financial-report-service.md) §4. Two `LedgerAggregate` builds only — `entry_date < from` for opening, `from..to` for movement — with `closing = opening + signed(debit − credit)` computed in PHP, **never** a third query (research [§R1](./research.md), FR-020, FR-025). Omit an account with neither movement nor a non-zero opening balance (FR-022).
- [X] T020 [US2] **Apply the signed-versus-raw rule exactly** (research [§R4](./research.md)): `openingBalance` and `closingBalance` are **signed** by normal balance; `periodDebit` and `periodCredit` are **raw, unsigned** sums. Compute `totalDebit`, `totalCredit`, and `foots`, and carry the variance so a failure can be displayed rather than merely detected.
- [X] T021 [P] [US2] Fill `resources/views/filament/financial-reports/partials/trial-balance.blade.php`: one row per account with code, name, type, and the four amount columns; a TOTAL row; and a proof row reading BALANCED or **OUT OF BALANCE BY {variance}** rendered prominently as an error. It must **not** round, suppress, adjust, or plug a difference (FR-024).
- [X] T022 [US2] Add trial-balance tests to `FinancialReportServiceTest.php` asserting the exact figures from quickstart Scenario 1 (Cash 0/1000/300/700, Receivable 0/500/0/500, Sales 0/0/1500/1500, Rent 0/300/0/300, totals 1800/1800) and that `foots` is true (**invariant I-1**, FR-023, SC-001).
- [X] T023 [P] [US2] **Add a dedicated regression test for the raw-versus-signed rule** — the most likely bug in the feature. Assert that a credit-normal account's `periodCredit` is a **positive** raw sum while its `closingBalance` is positive by sign convention, and that `totalDebit === totalCredit` over a ledger containing both debit-normal and credit-normal accounts. A "sign everything" implementation passes the balance columns and fails this test, which is the point (research [§R4](./research.md)).
- [X] T024 [P] [US2] Add tests asserting `closingBalance = openingBalance + signed(periodDebit − periodCredit)` for every row (**invariant I-2**); that an account with no movement and a zero opening balance is omitted rather than shown as zeroes (FR-022); and that a draft entry's lines appear in no row and change no total (FR-007).
- [X] T025 [P] [US2] Add a test that deliberately breaks the tie — write an unbalanced pair of lines directly to the database, bypassing the posting service — and assert the report **displays** the discrepancy as an error rather than concealing it (FR-024, SC-003 of the checklist's tie-out reasoning).

**Checkpoint**: US2 shippable. The ledger's global debit-equals-credit invariant is now demonstrable, which is the feature's core value.

---

## Phase 5: User Story 3 — Read a Profit and Loss for a Period (Priority: P1)

**Goal**: Income and expense movement for a range, subtotalled by type, with net profit or loss.

**Independent test**: quickstart [§Scenario 3](./quickstart.md) — January net profit 1200.00, extending to February 1900.00.

- [X] T026 [US3] Implement `FinancialReportService::profitAndLoss(from, to)` — one `LedgerAggregate` build over the range, income and expense sections only, each subtotalled, with `netResult = income − expense` and an `isLoss` flag (FR-030, FR-032). Every amount **signed**.
- [X] T027 [P] [US3] Fill `resources/views/filament/financial-reports/partials/profit-and-loss.blade.php` with the two sections, their subtotals, and a `NET PROFIT` / `NET LOSS` row whose **label** distinguishes the two rather than a minus sign alone (FR-032).
- [X] T028 [P] [US3] Add tests asserting the quickstart Scenario 3 figures; that asset, liability, and equity accounts never appear despite having movement in the range (FR-031); that a parent income account's figure includes its descendants without double-counting them in the subtotal (FR-014, **invariant I-10**); and that an expense-exceeds-income range yields `isLoss` true.

**Checkpoint**: US3 shippable independently of US2.

---

## Phase 6: User Story 4 — Read a Balance Sheet as of a Date (Priority: P1)

**Goal**: Position as of a date, with a computed accumulated-earnings equity line and the accounting equation displayed.

**Independent test**: quickstart [§Scenario 4](./quickstart.md) — assets 1200.00, computed earnings 1200.00, equation balanced.

- [X] T029 [US4] Implement `FinancialReportService::balanceSheet(asOf)` — one `LedgerAggregate` build with `entry_date <= asOf`; asset, liability, and equity sections each subtotalled; and `accumulatedEarnings = signed(Income) − signed(Expense)` as of that date (FR-034). Research [§R3](./research.md) carries the algebraic proof this relies on. Reference **no** account by code — in particular not Retained Earnings `3200` (FR-035).
- [X] T030 [US4] Compute `balances` and carry `variance`, so a failure can be displayed rather than merely detected (FR-036, FR-037). Exclude every entry dated after `asOf` (FR-038).
- [X] T031 [P] [US4] Fill `resources/views/filament/financial-reports/partials/balance-sheet.blade.php` with the three sections, their subtotals, the computed line **labelled `Accumulated Earnings (computed, not posted)`** (FR-034), and a proof row reading BALANCED or OUT OF BALANCE BY {variance} rendered prominently as an error. No figure may be adjusted to make the equation hold (FR-037).
- [X] T032 [US4] Add tests asserting `totalAssets === totalLiabilities + totalPostedEquity + accumulatedEarnings` at several as-of dates including one before the ledger's first entry (**invariant I-3**, FR-036, **SC-004**).
- [X] T033 [P] [US4] Add a cross-report test asserting the P&L net for a range equals the balance sheet's computed earnings line at that range's end, over a ledger whose first entry falls inside the range (**invariant I-4**, **SC-005**). This is the pair of numbers most worth checking, because it catches the "period net income instead of inception-to-date" error that makes the statement silently stop balancing after year one.
- [X] T034 [P] [US4] Add a test asserting that producing the balance sheet creates **no** journal entry and leaves Retained Earnings at zero (FR-035), and that an entry dated after the as-of date is excluded entirely (FR-038).

**Checkpoint**: US4 shippable. All three P1 statements now render, and the two riskiest requirements in the feature are under test.

---

## Phase 7: User Story 5 — Trace a Figure Through the General Ledger (Priority: P2)

**Goal**: Posted lines with a running balance that reconciles to the trial balance.

**Independent test**: quickstart [§Scenario 5](./quickstart.md) — Cash's final running balance 700.00 equals its trial-balance closing balance.

- [X] T035 [US5] Implement `FinancialReportService::generalLedger(from, to, accountId, perPage)` returning a `LengthAwarePaginator` (research [§R8](./research.md)). Order by `entry_date`, entry id, then `sort_order`, matching `AccountBalanceService::ledgerFor()` (FR-029). A non-null `accountId` includes that account **and its descendants**, each line labelled with the account it was posted to (FR-027).
- [X] T036 [US5] Start the running balance from the filtered account's opening balance for the range, restarting per account when unfiltered, so the final figure equals that account's trial-balance closing balance (FR-028).
- [X] T037 [P] [US5] Fill `resources/views/filament/financial-reports/partials/general-ledger.blade.php` with the eight columns from [contracts/report-columns.md](./contracts/report-columns.md) §3, raw debit and credit, signed running balance, and the paginator links.
- [X] T038 [US5] Add a cross-report test asserting the final `runningBalance` for an account and range equals that account's `closingBalance` on the trial balance for the same range (**invariant I-5**, FR-028, **SC-007**).
- [X] T039 [P] [US5] Add tests asserting a draft entry's lines never appear (FR-007); that filtering to a parent includes descendants' lines with each labelled by its own account (FR-027); and that the line order is deterministic across runs (FR-016, FR-029).

**Checkpoint**: US5 shippable. Statements are now drillable.

---

## Phase 8: User Story 6 — Trace an Entry Back to Its Source (Priority: P2)

**Goal**: A chronological register of posted entries whose source column is forward-compatible with documents that do not yet exist.

**Independent test**: quickstart [§Scenario 7](./quickstart.md) — a morph at a non-accounting model, an unresolvable morph, and an absent morph all render without failing.

- [X] T040 [US6] Implement `FinancialReportService::postingRegister(from, to, perPage)` returning a `LengthAwarePaginator` of posted entries with lines, resolved fiscal period, and posting user. Exclude drafts — a register records postings, not intentions (FR-040).
- [X] T041 [US6] Implement generic source-morph rendering per the five-case table in [data-model.md](./data-model.md) §Posting Register: absent → empty; a `JournalEntry` target → its entry number; a recognised resolving model → its label; an **unrecognised** type → `{Type} #{id}`; a target that **no longer resolves** → `{Type} #{id} (unresolved)`. None of the five may fail the report (FR-041, FR-042, FR-043).
- [X] T042 [P] [US6] Fill `resources/views/filament/financial-reports/partials/posting-register.blade.php` per [contracts/report-columns.md](./contracts/report-columns.md) §6, with one visual group per entry and its lines beneath.
- [X] T043 [US6] Add the **forward-compatibility tests** (**SC-011**): post an entry whose `source` morph points at a non-accounting model and assert it renders as a readable type-and-id label; delete that target row and assert the register renders the reference as unresolved without failing; post an entry with no source and assert the cell is empty rather than a placeholder resembling data. These prove `019-sales-lifecycle-payments-credits`'s documents will appear here with no change to this feature.
- [X] T044 [P] [US6] Add a test asserting a reversal's source column names the original entry by its `entry_number` (FR-041), using the reversal path from quickstart [§Scenario 6](./quickstart.md).

**Checkpoint**: US6 shippable. The audit loop closes, and the integration surface for future documents is proven before those documents exist.

---

## Phase 9: User Story 7 — Export a Statement for Circulation (Priority: P2)

**Goal**: A streamed CSV per report, matching the screen exactly and gated on the same permission.

**Independent test**: request each export as an entitled and an unentitled user, and diff the file against the screen.

**Dependency**: needs at least US2 complete; each export task depends on its own report's phase. Individual exports can be pulled forward into their report's phase if incremental shipping is preferred.

- [X] T045 [US7] Add the five export actions to `ViewFinancialReports.php` following `app/Filament/Resources/PurchasingReports/Pages/ListPurchasingReports.php`: `visible()`, `authorize()`, **and** a permission re-check at the top of each streaming method. The third is the one that matters — an export guarded only by its button's visibility can be requested directly (FR-005, [contracts/permissions.md](./contracts/permissions.md) §5.3). Use `response()->streamDownload` with `fputcsv(..., escape: '\\')`.
- [X] T046 [US7] Implement the per-report CSV bodies per [contracts/report-columns.md](./contracts/report-columns.md): a **scope line** first stating report and date bounds (FR-045), then a header row of **stable snake_case identifiers, never translated labels**, then data rows matching the screen order, then totals and the footing or equation proof as rows (FR-044). The general-ledger and posting-register exports consume the **unpaginated** query — a paginated export would misrepresent the period and could not reconcile to the trial balance (research [§R8](./research.md), FR — C-8).
- [X] T047 [US7] Create `tests/Feature/Accounting/FinancialReportExportTest.php` asserting for each of the five: the scope line is present and states the bounds; the header row matches the contract exactly; rows and totals equal the screen; the proof row is included; and an empty report exports the scope line and header with zero data rows (FR-044, FR-045, FR-046).
- [X] T048 [P] [US7] Add tests asserting an export requested **directly**, without `accounting.report.view`, is refused for all five reports (FR-005, **SC-008**), and that no `export_logs` row — nor any other row anywhere — is written by any export (FR-047).

**Checkpoint**: US7 shippable. All seven stories complete.

---

## Phase 10: Polish & Cross-Cutting

**Purpose**: The cross-report invariants, the read-only proof, and the quality gates.

- [X] T049 [P] Create `tests/Feature/Accounting/FinancialReportReadOnlyTest.php` asserting that producing **and exporting** all five reports leaves the row counts of `account_types`, `chart_accounts`, `fiscal_periods`, `journal_entries`, and `journal_entry_lines` identical before and after (**invariant I-9**, FR-052, **SC-010**).
- [X] T050 [P] Add a cross-report test asserting a posted entry paired with its reversal contributes zero net movement to **every one of the five reports** (**invariant I-6**, **SC-002**), following quickstart [§Scenario 6](./quickstart.md).
- [X] T051 [P] Add a cross-report test asserting no draft entry appears in, or affects any total on, any of the five reports (**invariant I-7**, FR-007, **SC-003**).
- [X] T052 [P] Add a cross-report test asserting every report renders over an **empty ledger** — zero rows, zero totals, no error, no division by zero — and that the balance-sheet equation still holds at `0.00 = 0.00 + 0.00 + 0.00` (**invariant I-8**, FR-017, **SC-009**). This is the state a first-time user actually sees.
- [X] T053 [P] Add tests asserting a soft-deleted account's posted lines still appear with the account marked deleted (FR-018), and that `is_active` and `is_postable` have no effect on any report (FR-019).
- [X] T054 [P] Add a query-count assertion per report proving the count does not grow with the number of accounts or posted lines — at most two aggregate queries plus one `chart_accounts` fetch (FR-020). Seed two account/line volumes and assert the counts are equal.
- [X] T055 [P] Extend `tests/Feature/Accounting/AccountingEnglishLabelsTest.php` to cover every new key — five report types, filter labels, column headers, section labels, proof labels — following the file's existing shape (FR-051).
- [X] T056 Add an architecture assertion to `tests/Unit/ArchTest.php` proving no class under `App\Filament\Resources\FinancialReports` or `App\Services\Accounting\FinancialReportService` references `JournalPostingService` (FR-053, **SC-013**).
- [X] T057 Verify `AccountBalanceService` and `ChartAccount` are **unmodified** — `git diff --stat` shows no change to either — and that `AccountBalanceServiceTest`, `PostedEntryImmutabilityTest`, and `NoAutomaticPostingTest` pass untouched (FR-054, FR-055, **SC-014**).
- [X] T058 Confirm **no migration file** was added by this feature: `git status` shows nothing new under `database/migrations/`. Standing prohibition 1.
- [X] T059 Run `vendor/bin/pint --dirty --format agent` and resolve every finding.
- [X] T060 Run `vendor/bin/phpstan analyse` and resolve every finding **without adding a baseline entry**. The baseline may only shrink (`.ai/feature-development` rule 7).
- [X] T061 Run `composer test` and confirm a full green suite (**SC-013**). Then walk [quickstart.md](./quickstart.md) Scenarios 1–11 by hand against the running dashboard, including Scenario 11's `grep -c` returning `1` for the navigation registration.

---

## Dependencies

```
Phase 1 (T001–T003)
        │
Phase 2 (T004–T014)  ◄── blocking for every story
        │
        ├─► US1 (T015–T018)   P1   independent
        ├─► US2 (T019–T025)   P1   independent
        ├─► US3 (T026–T028)   P1   independent
        ├─► US4 (T029–T034)   P1   T033 also needs US3 (cross-report)
        ├─► US5 (T035–T039)   P2   T038 also needs US2 (cross-report)
        └─► US6 (T040–T044)   P2   independent
                    │
                US7 (T045–T048)   P2   needs US2 minimum; each export needs its report
                    │
              Phase 10 (T049–T061)   needs all stories
```

Three cross-report tests are the only inter-story dependencies: **T033** (US4↔US3), **T038** (US5↔US2), and Phase 10's sweeps. Everything else within a story phase is independent.

## Parallel opportunities

- **Phase 2**: T004, T005, T006 together; then T013 and T014 alongside T009–T012.
- **After Phase 2**: US1, US2, US3, and US6 can proceed fully in parallel. US4 and US5 can start in parallel and pause only at their one cross-report test.
- **Within stories**: every Blade partial is a separate file — T021, T027, T031, T037, T042 are all `[P]`. Every `[P]`-marked test task touches a distinct file or a distinct test in an additive file.
- **Phase 10**: T049–T056 are all independent; T057–T061 are sequential gates.

## Independent test criteria

| Story | Shippable proof |
|---|---|
| US1 | Four roles reach the page; a post-and-ledger-only user cannot, and neither can they reach an export directly |
| US2 | Trial balance matches quickstart Scenario 1 figure-for-figure and its totals foot |
| US3 | P&L net is 1200.00 for January, 1900.00 through February, with no balance-sheet account present |
| US4 | Equation holds at several as-of dates, including before the ledger begins |
| US5 | Cash's final running balance equals its trial-balance closing balance |
| US6 | All three source-morph edge cases render without failing |
| US7 | Each CSV matches its screen, states its scope, and is refused without the permission |

## Suggested MVP

**Phase 1 + Phase 2 + US1 + US2.** That is a permission-gated page that proves the ledger foots — the feature's entire reason for existing, and the thing you want in place before `019`'s automatic postings land. US3 and US4 complete the P1 set; US5–US7 are genuine but secondary.

## Task count

61 tasks: 3 setup, 11 foundational, 4 (US1), 7 (US2), 3 (US3), 6 (US4), 5 (US5), 5 (US6), 4 (US7), 13 polish.

Of these, 30 are test tasks. That ratio is deliberate: the feature's output is numbers that people will make decisions on, and every one of the 10 invariants in [data-model.md](./data-model.md) has an explicit task.
