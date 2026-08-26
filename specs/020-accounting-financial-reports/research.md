# Phase 0 Research: Accounting Financial Reports

**Feature**: `020-accounting-financial-reports` | **Date**: 2026-08-23 | **Plan**: [plan.md](./plan.md)

Eight questions. All resolved; no `NEEDS CLARIFICATION` remains.

---

## R1 — The date-bounded aggregation primitive

**Decision.** One primitive, parameterised by a date bound, returning per-account debit and credit totals in integer minor units:

```
SELECT jel.chart_account_id,
       COALESCE(SUM(jel.debit),  0) AS debit_total,
       COALESCE(SUM(jel.credit), 0) AS credit_total
FROM journal_entry_lines jel
JOIN journal_entries je ON je.id = jel.journal_entry_id
WHERE je.status = 'posted'
  AND <date bound on je.entry_date>
GROUP BY jel.chart_account_id
```

Three bounds cover every report:

| Bound | Used by |
|---|---|
| `entry_date < :from` | Trial Balance opening, General Ledger opening |
| `entry_date BETWEEN :from AND :to` | Trial Balance movement, Profit and Loss |
| `entry_date <= :asOf` | Balance Sheet |

**Closing balance is never a third query.** `closing = opening + movement`, computed in PHP. That is what holds the report to at most two aggregate queries plus one `chart_accounts` fetch, satisfying FR-020 regardless of how many accounts or lines exist.

**Rationale.** `AccountBalanceService::netMinorUnitsByAccount()` already establishes this exact shape — one grouped aggregate, `whereHas` on posted status, `COALESCE` so the row always returns — and its correctness is already covered by `AccountBalanceServiceTest`. Reusing the shape rather than inventing one means the only new thing is the date predicate.

Both bounds are **inclusive** at each end (FR-009), and `entry_date` is a `date` column, so no time-of-day boundary case exists. SC-006's four-way boundary test (day before start, exactly start, exactly end, day after end) is the guard.

**Alternatives considered.** A single query returning all three windows via conditional aggregation (`SUM(CASE WHEN … END)`) — rejected: it is one query instead of two, but it makes the SQL substantially harder to read and to test in isolation, and query count is already constant. A window function running balance in SQL — rejected: MySQL supports it, but the running balance is needed only by the General Ledger, must restart per account at the account's opening balance, and is trivial in PHP over an already-ordered collection.

---

## R2 — Filament page shape

**Decision.** A plain custom `Filament\Resources\Pages\Page` with a Blade view and a report-type selector, following `ListPurchasingReports` and `ViewSupportReports`. **Not** `ManageRecords` with tabs, as `ManageInventoryReports` uses.

**Rationale.** The five reports are not five lists. Three of them — Trial Balance, Profit and Loss, Balance Sheet — are *grouped statements*: rows clustered by account type, subtotals per group, a grand total, and a footing or equation proof beneath. A Filament table can group and can summarise, but expressing "assets subtotal, liabilities subtotal, equity subtotal, computed earnings line, then an equation proof row that is not an account at all" means fighting the table abstraction the whole way. The two that *are* lists, General Ledger and Posting Register, render perfectly well as plain HTML tables in the same Blade view — which is what `ViewSupportReports` already does for its aggregates.

Keeping one page also means one permission check, one date-range filter, one Blade view, and one export dispatch point, rather than five of each.

**Alternatives considered.** `ManageRecords` + tabs, per `InventoryReports` — rejected above; its fit there is genuine because every inventory report *is* a record list. A hybrid, tabs for the two lists and Blade for the three statements — rejected: two page shapes for one navigation slot doubles the surface for no user-visible gain. Five separate resources — already rejected by spec decision D5.

---

## R3 — Balance-sheet accumulated earnings, with proof

**Decision.** One computed equity line:

```
accumulated_earnings = signed(Income accounts as of date) − signed(Expense accounts as of date)
```

where `signed(x)` applies `NormalBalance::sign()` — `+1` debit-normal, `−1` credit-normal.

**Proof that the equation holds identically.** Let `D_t` and `C_t` be total debits and credits across accounts of element `t`, for all posted lines dated on or before the as-of date. Because every posted entry balances (spec 018 FR-020 enforces this at posting time), summing over all five elements:

```
(D_a−C_a) + (D_l−C_l) + (D_e−C_e) + (D_i−C_i) + (D_x−C_x) = 0
```

Rearranging for assets, which are debit-normal so `signed = D_a−C_a`:

```
D_a−C_a = (C_l−D_l) + (C_e−D_e) + (C_i−D_i) + (C_x−D_x)
```

Liability, equity and income are credit-normal, so `signed = C−D` for each; expense is debit-normal, so `C_x−D_x = −signed(Expense)`. Substituting:

```
Assets = Liabilities + Equity + signed(Income) − signed(Expense)
       = Liabilities + Equity + accumulated_earnings          ∎
```

The equation is therefore an **identity**, not an approximation. It can only fail if a posted entry does not balance — which spec 018 prevents — or if this feature's own arithmetic is wrong. That is exactly why FR-037 requires a failure to be displayed as an error and never adjusted: a failing equation is a real defect, and it is the single most valuable signal this feature produces.

**Rationale for one line, not a retained-earnings/current-year split.** Spec decision D4. `fiscal_periods` is a flat list of named date ranges with no fiscal-year parent, so the split point is not derivable from the schema; deriving it would mean inventing a fiscal-year concept, which is a schema change and out of scope.

**Alternatives considered.** Posting a year-end close so equity is complete on its own — rejected, ADR 0007 excludes it and it would make this read-only feature a writing one. Referencing the seeded Retained Earnings account (`3200`) by code — rejected: the spec's §Assumptions require grouping by account *type*, never by hard-coded code, so a user who restructures the chart does not break any report. Showing the line as a footnote rather than an equity row — rejected: then the statement visibly does not balance.

---

## R4 — Which columns are signed and which are raw

**Decision.** This is the finding most likely to be got wrong, so it is stated as a rule per column:

| Column | Treatment |
|---|---|
| Trial Balance — opening balance | **Signed** by normal balance |
| Trial Balance — period debits | **Raw**, unsigned sum of `debit` |
| Trial Balance — period credits | **Raw**, unsigned sum of `credit` |
| Trial Balance — closing balance | **Signed** by normal balance |
| General Ledger — line debit / credit | **Raw** |
| General Ledger — running balance | **Signed** |
| Profit and Loss — every amount | **Signed** |
| Balance Sheet — every amount | **Signed** |
| Posting Register — line debit / credit | **Raw** |

**Rationale.** FR-013 requires balances to be signed so an account holding its normal balance reads positive. It says *balances*. The trial balance's debit and credit columns are **movements**, and their whole purpose is the footing proof `Σ debits = Σ credits` (FR-023). Signing them would negate every credit-normal account's column and the proof would fail on any real ledger — while looking like a data problem rather than a code problem. An implementer applying "sign everything" uniformly produces exactly that bug, and it would present as a broken trial balance rather than as a broken report.

**Alternatives considered.** Presenting trial-balance balances as two unsigned columns, debit-balance and credit-balance, which is the traditional printed form — rejected for consistency: the Chart of Accounts page already shows one signed balance per account, and two different balance presentations in one module invites reconciling them by hand. The signed single column ties to the existing page.

---

## R5 — Deterministic row ordering

**Decision.** Depth-first tree order: each account immediately followed by its descendants, siblings ordered by `code` as a string. Computed in memory during the `AccountTree` walk, not as a SQL `ORDER BY`.

**Rationale.** FR-016 requires determinism; the accounting convention requires a parent to appear above its children, which a flat sort by code only achieves accidentally. It happens to hold for the seeded chart, where every code is four characters (`1000`, `1100`, `1110`, `1200`…), and breaks the moment a user creates a three- or five-character code: `'900'` sorts before `'1000'` as text. Since every account is already loaded into memory for the roll-up (R1, R6), ordering there costs nothing and is engine-independent — a SQL sort would additionally risk varying with collation.

**Alternatives considered.** `ORDER BY LENGTH(code), code` — rejected: it approximates numeric order for numeric codes only, and still interleaves children away from parents. `ORDER BY code` alone — rejected as above. Adding a materialised path or `lft`/`rgt` columns — rejected: schema change, and the spec forbids one.

---

## R6 — Sharing the hierarchy roll-up with `AccountBalanceService`

**Decision.** Do **not** refactor `AccountBalanceService`. Build the parent→children map, the cycle-guarded roll-up, and the new depth-first ordering in a new `App\Services\Accounting\Support\AccountTree`, used only by `FinancialReportService`. Accept that the roll-up walk then exists in two places.

**Rationale.** Extracting the walk out of `AccountBalanceService` so both classes share one copy is the DRY answer and it is the wrong answer here. Constitution Principle II states "unrelated refactors are prohibited when delivering a feature" and Principle VI.7 repeats it. FR-054 additionally requires the existing Chart of Accounts balance behaviour to be unchanged, and `AccountBalanceService` carries existing tests and static-analysis baseline entries. Putting a tested, correctness-critical class at risk to satisfy a tidiness preference this feature does not need is precisely the trade the constitution forbids.

The duplication is roughly fifteen lines: the `$visited` guard and the recursive sum. Both copies must keep the cycle guard, because a hierarchy cycle introduced by a direct database write must terminate (FR-015) — the same reasoning `ChartAccount::selfAndDescendantIds()` and `AccountBalanceService::rollUp()` already document.

**Recorded as a candidate follow-up.** Unifying the walk is worth doing as its own change, with its own review, where the only risk on the table is the refactor itself. It is logged in [plan.md](./plan.md) §Complexity Tracking so it is not lost, and explicitly **not** to be smuggled into this feature.

**Alternatives considered.** Extract and have both use it — rejected above. Have `FinancialReportService` call `AccountBalanceService` and subtract to derive period figures — rejected: `AccountBalanceService` returns formatted decimal strings, so deriving a period from two all-time figures would reintroduce string parsing and defeat FR-012's integer-minor-unit rule. Make `rollUp()` public — rejected: it widens a contract the spec says to leave alone, for one caller.

---

## R7 — Permission granularity across the five reports

**Decision.** One permission, `accounting.report.view`, for all five reports and all five exports. No per-report gating, and therefore no analogue of `InventoryReportType::sourcePermission()`.

**Rationale.** FR-001 says "exactly one permission". The five reports are five views of the same posted ledger and expose the same underlying facts — anyone who can read a trial balance can derive a balance sheet from it, so gating them separately would be theatre. `InventoryReportType::sourcePermission()` exists because inventory reports span genuinely different data domains (catalog, stock, movements, **pricing**), and pricing is a real access boundary there. No such boundary exists inside the general ledger.

The permission must not be implied by `accounting.ledger.view` (FR-002). That distinction is real and worth keeping: `ledger.view` grants one account's own lines from the Chart of Accounts page; `report.view` grants the whole book in aggregate.

**Alternatives considered.** Separate permissions per statement — rejected above. Reusing `accounting.ledger.view` — rejected by FR-002 for the reason just given. Gating export separately from viewing — rejected: FR-005 requires the export to check the *same* permission as the screen, because an export checking a weaker rule is a way to read the report without being allowed to see it.

---

## R8 — General Ledger and Posting Register volume

**Decision.** The service returns a `LengthAwarePaginator` for the two line-level reports; the Blade view renders its links. The three statements are not paginated — they have one row per account with movement, bounded by the size of the chart of accounts.

**Rationale.** A general ledger over a wide date range is unbounded in a way a statement is not: it is one row per posted line. The date range bounds it in principle, but a year of activity is not a page. Laravel's paginator is the framework answer, needs no package, and works inside a Blade view. The statements need no pagination because their row count is bounded by the account count — tens, not thousands.

The **CSV export is never paginated** (`contracts/report-columns.md`): an exported general ledger must be the whole range, or the file silently misrepresents the period. The export therefore streams the full result set with `response()->streamDownload`, which is what makes streaming rather than buffering the right call.

**Alternatives considered.** A Filament table for those two reports so pagination comes free — rejected in R2; it would mean two page shapes. Capping rows with a "showing first N" notice — rejected: the spec's §Scope forbids silent caps, and a capped ledger cannot reconcile to the trial balance, breaking SC-007. Infinite scroll — rejected: not exportable, not printable, and not what a month-end pack needs.

---

## Cross-cutting decisions confirmed, not researched

These were settled by the spec and are recorded here so the implementer does not re-derive them:

- **Integer minor units throughout** (FR-012), reusing `JournalEntryLine::toMinorUnits()` and formatting to a decimal string only at the presentation boundary, exactly as `AccountBalanceService::format()` does.
- **Posted lines only** (FR-007), via the same `status = 'posted'` predicate `AccountBalanceService` uses. Drafts appear nowhere and affect no total (SC-003).
- **Soft-deleted accounts still appear** with their posted history, marked deleted (FR-018). History is never rewritten by a later deletion.
- **`is_active` and `is_postable` have no bearing on reporting** (FR-019). They govern future postings.
- **Fiscal-period selection is a date-range convenience only** (FR-011), offered over open and closed periods alike — closing a period stops postings, not reads.
- **Source morph rendered generically** (FR-041, FR-042) so the documents `019-sales-lifecycle-payments-credits` posts appear in the Posting Register with no change here. Tested today with a morph pointing at a non-accounting model and with an unresolvable one (SC-011).
