# Quickstart: Validating Accounting Financial Reports

**Feature**: `020-accounting-financial-reports` | **Plan**: [plan.md](./plan.md)

How to prove this feature works end to end. Scenarios only — no implementation code. Implementation belongs in `tasks.md`.

## Prerequisite: the governance gate

**Do not start.** Confirm both first:

```bash
grep -m1 '^\*\*Status\*\*' Docs/adr/0009-accounting-financial-reports.md
```

Must read `**Status**: Accepted`. And the constitution must be at 1.9.0 with the eighth narrow Filament exception recorded. Until both hold, every `App\Filament` class in this feature violates constitution §Product Scope & Boundaries. See [plan.md](./plan.md) §Governance Gate.

## Setup

Prerequisites are already built — `018-chart-of-accounts-journals` ships the five tables, the four services, and the permission catalogue. Nothing to migrate: this feature adds no schema.

```bash
php artisan migrate --seed
```

```bash
php artisan db:seed --class=AccountingPermissionSeeder
```

The second is idempotent and is what grants the new `accounting.report.view`.

## The fixture that makes every proof checkable

Build this by hand once, or as a Pest `beforeEach`. Every expected figure below is derived from it, so the numbers can be verified by inspection rather than by trusting the code.

One fiscal period covering January. Postable accounts: `1100 Cash` (Asset), `1200 Receivable` (Asset), `4100 Sales` (Income), `5300 Rent` (Expense).

| # | Date | Debit | Credit | Amount | Status |
|---|---|---|---|---|---|
| E1 | 2026-01-05 | `1100 Cash` | `4100 Sales` | 1000.00 | posted |
| E2 | 2026-01-15 | `5300 Rent` | `1100 Cash` | 300.00 | posted |
| E3 | 2026-01-20 | `1200 Receivable` | `4100 Sales` | 500.00 | posted |
| E4 | 2026-01-25 | `5300 Rent` | `1100 Cash` | 100.00 | **draft** |
| E5 | 2026-02-10 | `1100 Cash` | `4100 Sales` | 700.00 | posted |

E4 is a draft and must be invisible everywhere. E5 falls outside January and is the boundary control.

## Scenario 1 — Trial balance foots

Open Financial Reports → Trial Balance, range `2026-01-01` to `2026-01-31`.

Expect:

| Account | Opening | Debit | Credit | Closing |
|---|---|---|---|---|
| 1100 Cash | 0.00 | 1000.00 | 300.00 | 700.00 |
| 1200 Receivable | 0.00 | 500.00 | 0.00 | 500.00 |
| 4100 Sales | 0.00 | 0.00 | 1500.00 | 1500.00 |
| 5300 Rent | 0.00 | 300.00 | 0.00 | 300.00 |
| **TOTAL** | | **1800.00** | **1800.00** | |

Verify: the proof row reads **BALANCED**; totals are equal (SC-001); E4's 100.00 appears nowhere (SC-003); E5's 700.00 appears nowhere.

Note `4100 Sales` closing reads **positive 1500.00** despite being a credit balance — that is FR-013's sign convention, and the debit/credit columns above it stay raw. If the credit column shows a negative number, research [§R4](./research.md) has been violated and the footing proof will fail on any real ledger.

## Scenario 2 — Date bounds at both edges

Re-run the trial balance for `2026-01-05` to `2026-01-20`.

E1 (exactly the start) and E3 (exactly the end) both appear. Widen to `2026-01-04`–`2026-01-21` and nothing changes. Narrow to `2026-01-06`–`2026-01-19` and both vanish, leaving only E2. That is SC-006's four-way boundary check.

Then set the range end to `2026-01-31` and the start to `2026-02-01`: an inverted range must raise a validation error, **not** an empty report (FR-010).

## Scenario 3 — Profit and loss

Range `2026-01-01`–`2026-01-31`.

Income 1500.00, Expense 300.00, **Net profit 1200.00**. No asset or liability account appears despite `1100` and `1200` both having movement (FR-031). Extend to `2026-02-28` and net profit becomes 1900.00.

## Scenario 4 — Balance sheet balances

As of `2026-01-31`.

```
Assets            1200.00   (Cash 700 + Receivable 500)
Liabilities          0.00
Equity (posted)      0.00
Accumulated (computed) 1200.00
Proof: 1200.00 = 0.00 + 0.00 + 1200.00   BALANCED
```

Verify the computed line is **labelled as computed, not posted** (FR-034), and that no journal entry was created to produce it — Retained Earnings `3200` stays at zero (FR-035).

Cross-check against Scenario 3: the P&L net for a range ending `2026-01-31` equals this computed line, because the ledger begins inside that range. That is SC-005, and it is the pair of numbers most worth checking by hand.

## Scenario 5 — General ledger ties to the trial balance

Open General Ledger, range `2026-01-01`–`2026-01-31`, filtered to `1100 Cash`.

Two lines: E1 debit 1000.00 running 1000.00; E2 credit 300.00 running 700.00. The final running balance **700.00 equals Cash's trial-balance closing balance** from Scenario 1 (SC-007). E4 does not appear.

## Scenario 6 — Reversal nets to zero

Reverse E1 with a reversal date of `2026-01-28`, then re-run every report for January.

Trial balance: Cash and Sales each gain 1000.00 on the *opposite* side, totals rise to 2800.00 each, and both accounts' closing balances drop by 1000.00. Balance sheet assets fall to 200.00 and the equation still holds. P&L income falls to 500.00. The pair contributes zero net movement (SC-002).

The Posting Register now shows the reversal with its **source column naming E1's entry number** (FR-041).

## Scenario 7 — Source morph forward-compatibility

This is the guarantee that matters for `019`, and it must be tested **now**, before any commercial document exists.

Post an entry whose `source` morph points at a **non-accounting** model — any built model will do, e.g. a `Product`. The Posting Register must render it as a readable `{Type} #{id}` label without failing (FR-041, SC-011).

Then delete that target row and re-open the register. The row must render with the reference marked **unresolved** and the report must not fail (FR-042).

Finally, post an entry with no source at all: the cell must be **empty**, not a placeholder that looks like data (FR-043).

## Scenario 8 — Empty ledger

Against a database with the chart seeded but zero posted entries, open all five reports.

Every one renders: zero rows, zero totals, no error, no division by zero. The balance-sheet equation holds trivially at `0.00 = 0.00 + 0.00 + 0.00` (SC-009). This is the scenario most often skipped and the one a first-time user actually sees.

## Scenario 9 — Permission gate, including the export

| Actor | Reports | Exports | Nav link |
|---|---|---|---|
| Chief Accountant | ✅ | ✅ | visible |
| Accountant | ✅ | ✅ | visible |
| Reviewer | ✅ read-only | ✅ | visible |
| Holds `journal-entry.post` + `ledger.view`, not `report.view` | ❌ | ❌ | **absent** |

Then request an export URL **directly**, without the permission, bypassing the page. It must be refused (FR-005, SC-008). An export guarded only by its button's visibility is not guarded.

## Scenario 10 — Nothing was written

The check for SC-010. Count rows across `chart_accounts`, `account_types`, `fiscal_periods`, `journal_entries`, and `journal_entry_lines`; produce and export all five reports; count again. Every count identical.

## Scenario 11 — Financial Reports appears once

The fix for navigation defect N-1.

```bash
grep -c "admin.resources.financial_reports" app/Filament/AdminModuleRegistry.php
```

Must print `1`, not `2`. It appears under the shared **Reports** group and **not** under Accounting (D3). The accounting group is left with six placeholders.

The regression test in `tests/Unit/AdminModuleRegistryTest.php` asserts the general invariant — no navigation label registered in more than one group, for any module (FR-050). Fixing one duplicate fixes one duplicate; the invariant fixes the next one too.

## Full gate

```bash
composer test
```

Must pass with no new PHPStan baseline entries (SC-013), and `018`'s complete suite must pass unchanged — including `PostedEntryImmutabilityTest` and `NoAutomaticPostingTest` (SC-014).

```bash
vendor/bin/pint --dirty --format agent
```

## Targeted runs while iterating

```bash
php artisan test --compact --filter=FinancialReportServiceTest
```

```bash
php artisan test --compact tests/Feature/Accounting
```
