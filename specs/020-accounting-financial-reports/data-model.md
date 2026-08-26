# Phase 1 Data Model: Accounting Financial Reports

**Feature**: `020-accounting-financial-reports` | **Date**: 2026-08-23 | **Plan**: [plan.md](./plan.md)

## Schema change

**None.** No table, column, index, constraint, or migration is added, altered, or removed. This section exists to say so explicitly, because every sibling accounting specification has a schema section and its absence here would read as an omission rather than as the decision it is.

Consequently: Principle I's "database design MUST be finalized before implementation begins" is satisfied trivially, there is nothing to add to `Docs/database/ERD.md`, and reverting this feature leaves no data behind.

## Entities read

All five are built by `018-chart-of-accounts-journals` and are read without modification.

| Entity | Read for | Never |
|---|---|---|
| `AccountType` | `normal_balance` → `NormalBalance::sign()`; `AccountElement` → statement grouping (Income/Expense → Profit and Loss; Asset/Liability/Equity → Balance Sheet) | written |
| `ChartAccount` | `code`, `name`, `account_type_id`, `parent_id`, `deleted_at` | written |
| `FiscalPeriod` | `name`, `start_date`, `end_date` → the date-range convenience selector (FR-011) | written, closed, or reopened |
| `JournalEntry` | `entry_number`, `entry_date`, `description`, `status`, `fiscal_period_id`, `source_type`/`source_id`, `created_by` | written |
| `JournalEntryLine` | `debit`, `credit`, `chart_account_id`, `sort_order`; `toMinorUnits()` | written |

**Filter applied to every read**: `journal_entries.status = 'posted'`. Draft entries are invisible to this feature (FR-007, SC-003).

## Internal value shapes

Two collaborators live in `app/Services/Accounting/Support/`. Neither is persisted; both are built per request and discarded.

### `LedgerAggregate`

Per-account debit and credit totals in integer minor units, for one date bound.

```
LedgerAggregate
  debitMinorFor(int $accountId): int      // 0 when the account has no line in the window
  creditMinorFor(int $accountId): int
  netMinorFor(int $accountId): int        // debit − credit, unsigned by normal balance
  accountIds(): list<int>                 // accounts with any movement in the window
```

Built from exactly one grouped aggregate query (research §R1). `COALESCE` in the query means a missing account yields `0` rather than `null`, so no caller needs a null check.

### `AccountTree`

The chart of accounts as a hierarchy, loaded once per report.

```
AccountTree
  displayOrder(): list<ChartAccount>              // depth-first: each account followed by its descendants,
                                                 // siblings by code (research §R5)
  rollUp(int $accountId, callable $ownValue): int // own value plus every descendant's, cycle-guarded
  childIdsOf(int $accountId): list<int>
  selfAndDescendantIds(int $accountId): list<int>
```

`rollUp()` carries a `$visited` set so a cycle introduced by a direct database write terminates instead of recursing forever (FR-015) — the same guard `ChartAccount::selfAndDescendantIds()` and `AccountBalanceService::rollUp()` already use. Research §R6 records why this is a second copy of that walk rather than a shared extraction.

## Derived concept

### Accumulated earnings

Exists only in presentation. It is **not** an account, **not** a row in any table, and **not** posted anywhere.

```
accumulatedEarnings(asOf) = signed(Income accounts as of asOf) − signed(Expense accounts as of asOf)
```

It appears as one labelled line in the Balance Sheet's equity section, marked as computed rather than posted (FR-034). Research §R3 carries the algebraic proof that it makes the accounting equation an identity. Nothing is written to Retained Earnings (`3200`) or to any other account (FR-035), and no account is referenced by code anywhere in this feature.

## Report row shapes

The service returns plain arrays; the Blade view renders them. Signed-versus-raw treatment per column is fixed by research §R4 and repeated in [contracts/report-columns.md](./contracts/report-columns.md), which is what the export test asserts against.

### Trial Balance — `TrialBalanceRow`

```
accountId, code, name, element, depth, isDeleted,
openingBalance   (signed),
periodDebit      (raw),
periodCredit     (raw),
closingBalance   (signed)
```

Rows omitted when the account has neither movement in the range nor a non-zero opening balance (FR-022). Accompanied by a totals object carrying `totalDebit`, `totalCredit`, and `foots` (boolean) — `closing = opening + (debit − credit)` is computed in PHP, never queried (research §R1).

### General Ledger — `LedgerLineRow`

```
lineId, entryId, entryNumber, entryDate, accountCode, accountName,
description, debit (raw), credit (raw), runningBalance (signed)
```

Ordered by `entry_date`, then entry id, then `sort_order` — the ordering `AccountBalanceService::ledgerFor()` already establishes (FR-029). The running balance starts from the filtered account's opening balance for the range, so the final figure equals that account's trial-balance closing balance (FR-028, SC-007). Returned as a `LengthAwarePaginator` on screen; never paginated in the export (research §R8).

### Profit and Loss — `ProfitAndLossSection`

```
element (Income | Expense),
rows: list<{ accountId, code, name, depth, amount (signed) }>,
subtotal (signed)
```

Plus `netResult` = income subtotal − expense subtotal, and an `isLoss` flag so a loss is unambiguously distinguishable from a profit (FR-032). Asset, liability, and equity accounts never appear regardless of their movement (FR-031).

### Balance Sheet — `BalanceSheetStatement`

```
sections: { Asset, Liability, Equity } each as
          { rows: list<{ accountId, code, name, depth, amount (signed) }>, subtotal },
accumulatedEarnings (signed, computed, labelled as such),
totalAssets, totalLiabilities, totalPostedEquity,
balances (boolean), variance (signed, zero when it balances)
```

`variance` is carried rather than discarded so a failure can be *displayed* (FR-037) instead of merely detected.

### Posting Register — `PostingRegisterRow`

```
entryId, entryNumber, entryDate, description,
fiscalPeriodName, postedByName,
source: null | { label, type, id, resolved (boolean) },
lines: list<{ accountCode, accountName, debit (raw), credit (raw) }>
```

`source` resolution rules (FR-041, FR-042, FR-043):

| Morph state | Rendered as |
|---|---|
| absent | `null` — an empty cell, never a placeholder that looks like data |
| points at a `JournalEntry` (a reversal) | that entry's `entry_number`, `resolved: true` |
| points at a recognised model that resolves | that model's label, `resolved: true` |
| points at an **unrecognised** model type | readable type-and-id label, `resolved: true` |
| points at a record that **no longer resolves** | type-and-id label, `resolved: false`, shown as unresolved |

The last two rows are the forward-compatibility guarantee: when `019-sales-lifecycle-payments-credits` starts posting invoices, payments, and credit notes, they appear here with no change to this feature (SC-011).

## State transitions

**None.** Nothing in this feature has a lifecycle, because nothing in it is written.

## Invariants the tests must hold

| # | Invariant | FR / SC |
|---|---|---|
| I-1 | `Σ periodDebit = Σ periodCredit` on every trial balance | FR-023, SC-001 |
| I-2 | `closingBalance = openingBalance + signed(periodDebit − periodCredit)` per row | FR-021, FR-025 |
| I-3 | `totalAssets = totalLiabilities + totalPostedEquity + accumulatedEarnings` | FR-036, SC-004 |
| I-4 | P&L `netResult` for a range = balance-sheet `accumulatedEarnings` at the range end, when the ledger begins inside that range | SC-005 |
| I-5 | General Ledger final `runningBalance` = trial-balance `closingBalance`, same account and range | FR-028, SC-007 |
| I-6 | An entry and its reversal contribute zero to every report | SC-002 |
| I-7 | No draft line appears in, or affects any total on, any report | FR-007, SC-003 |
| I-8 | Every report renders over an empty ledger: zero rows, zero totals, I-3 still holds | FR-017, SC-009 |
| I-9 | Row counts across all five tables are identical before and after producing and exporting every report | FR-052, SC-010 |
| I-10 | A parent's figure includes its descendants' and no descendant is double-counted in a subtotal | FR-014, FR-037 |
