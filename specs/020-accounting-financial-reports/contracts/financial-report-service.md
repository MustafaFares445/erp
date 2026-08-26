# Contract: `FinancialReportService`

**Feature**: `020-accounting-financial-reports` | **Class**: `App\Services\Accounting\FinancialReportService`

The single entry point for every figure this feature displays. `final readonly`, following `AccountBalanceService`.

## §1 Why this class exists rather than extending `AccountBalanceService`

`AccountBalanceService` computes **all-time** balances and returns them as formatted decimal strings. It has no date parameter anywhere in its public surface, and its callers — the Chart of Accounts table and the ledger relation manager — depend on that shape. Widening it would change a tested contract that FR-054 requires to stay unchanged.

Deriving period figures by subtracting two all-time calls is also ruled out: the returned strings would have to be parsed back into numbers, reintroducing exactly the float risk FR-012 forbids.

So: a new class, its own date-bounded primitive, no change to the old one. Research [§R6](../research.md) records the roll-up duplication this accepts and why the constitution requires accepting it.

## §2 Guarantees held by every method

| # | Guarantee | Source |
|---|---|---|
| G-1 | Reads posted lines only; a draft entry is invisible | FR-007, FR-040 |
| G-2 | Never writes. No create, update, delete, or upsert on any table | FR-052 |
| G-3 | Never calls `JournalPostingService` or any other write path | FR-053 |
| G-4 | All arithmetic on integer minor units; decimal strings produced only at the return boundary | FR-012, FR-030 of 018 |
| G-5 | Balances signed via `NormalBalance::sign()`; the rule is never reimplemented | FR-013 |
| G-6 | A parent's figure includes its descendants', with no double-count in a subtotal | FR-014 |
| G-7 | Terminates on a cyclic hierarchy | FR-015 |
| G-8 | Deterministic depth-first row order | FR-016 |
| G-9 | At most two aggregate queries plus one `chart_accounts` fetch per report | FR-020 |
| G-10 | Renders an empty ledger as zero rows and zero totals, never an error | FR-017 |
| G-11 | Includes soft-deleted accounts' posted history, flagged deleted | FR-018 |
| G-12 | Ignores `is_active` and `is_postable` entirely | FR-019 |

## §3 Date-bound semantics

Two shapes only:

- **Range** `(from, to)` — inclusive at both ends. Used by Trial Balance, General Ledger, Profit and Loss, Posting Register.
- **As-of** `(asOf)` — inclusive. Used by Balance Sheet. Equivalent to a range from ledger inception to `asOf`.

An inverted range (`to < from`) **throws** rather than returning an empty result (FR-010). Returning nothing would render as "no activity in this period", which is a wrong answer presented as a fact.

`journal_entries.entry_date` is a `date` column, so no time component participates and no timezone boundary exists.

## §4 Method contracts

### `trialBalance(CarbonImmutable $from, CarbonImmutable $to): TrialBalanceReport`

Returns rows per [data-model.md](../data-model.md) §Trial Balance plus `totalDebit`, `totalCredit`, `foots`.

- Omits an account with neither movement in the range nor a non-zero opening balance (FR-022).
- `openingBalance` = signed net of all posted lines dated **strictly before** `from` (FR-025).
- `periodDebit` / `periodCredit` are **raw, unsigned** sums (research §R4). Signing them would break `foots` on any ledger containing a credit-normal account.
- `closingBalance` = `openingBalance + signed(periodDebit − periodCredit)`, computed in PHP.
- `foots` = `totalDebit === totalCredit`. When false the caller **must display the discrepancy** (FR-024); the service neither rounds, adjusts, nor suppresses it.

**Invariant**: `foots` is true for any ledger whose entries all balance, which posting already enforces. A false result is a defect in this service or in a direct database write — never something to be corrected here.

### `generalLedger(CarbonImmutable $from, CarbonImmutable $to, ?int $accountId, int $perPage): LengthAwarePaginator`

Posted lines with a running balance.

- `$accountId` null → every account's lines. Non-null → that account **and its descendants** (FR-027), each line labelled with the account it was posted to.
- Ordered `entry_date`, entry id, `sort_order` (FR-029).
- `runningBalance` begins at the filtered account's opening balance for the range, so the final value equals that account's trial-balance closing balance (FR-028, SC-007). With no account filter the running balance is per account, restarting for each.
- Paginated on screen; the export consumes the unpaginated query (research §R8).

### `profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): ProfitAndLossReport`

Income and expense sections, each with rows and a subtotal, plus `netResult` and `isLoss`.

- Asset, liability, and equity accounts are excluded regardless of movement (FR-031).
- `netResult` = income subtotal − expense subtotal. `isLoss` = `netResult < 0` (FR-032).

### `balanceSheet(CarbonImmutable $asOf): BalanceSheetReport`

Asset, liability, and equity sections plus the computed earnings line.

- `accumulatedEarnings` = `signed(Income as of asOf) − signed(Expense as of asOf)` (FR-034). Labelled as computed, not posted.
- `balances` = `totalAssets === totalLiabilities + totalPostedEquity + accumulatedEarnings`.
- `variance` carried so a failure can be displayed rather than merely detected (FR-037).
- Writes nothing to Retained Earnings or any account (FR-035). References no account by code.
- Excludes every entry dated after `asOf` (FR-038).

**Invariant**: `balances` is an identity given balanced postings — proof in research §R3. A false result is a defect, and FR-037 forbids adjusting any figure to hide it.

### `postingRegister(CarbonImmutable $from, CarbonImmutable $to, int $perPage): LengthAwarePaginator`

Posted entries with lines, fiscal period, posting user, and source.

- Draft entries excluded (FR-040) — a register records postings, not intentions.
- Source resolution follows the five-case table in [data-model.md](../data-model.md) §Posting Register. **An unrecognised morph type, and a morph whose target no longer resolves, must both render without failing** (FR-041, FR-042). This is the forward-compatibility guarantee for `019`'s documents (SC-011).
- An entry with no source renders as empty, never as a placeholder resembling data (FR-043).

### `fiscalPeriodOptions(): array<int, string>`

Period id → label, for the date-range convenience selector. Open and closed periods alike (FR-011) — closing a period stops postings, not reads.

## §5 Collaborators

| Collaborator | Role |
|---|---|
`App\Services\Accounting\Support\LedgerAggregate` | one grouped aggregate per date bound
`App\Services\Accounting\Support\AccountTree` | children map, cycle-guarded roll-up, depth-first order
`App\Enums\NormalBalance::sign()` | the sign rule, reused not reimplemented (G-5)
`App\Enums\AccountElement` | statement grouping
`App\Models\JournalEntryLine::toMinorUnits()` | decimal → integer minor units

## §6 Explicitly absent from this contract

- Any write method.
- Any caching, memoisation across requests, or stored balance.
- Any reference to an invoice, payment, credit note, supplier, bill, or expense. This feature reads the ledger, not the documents, and nothing in it may reference a commercial document by type.
- Any join to a table outside the five accounting tables.
- Any account referenced by hard-coded code.
- Any currency handling. Single currency; no currency column exists.
