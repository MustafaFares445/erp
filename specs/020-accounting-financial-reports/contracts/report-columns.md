# Contract: Report Columns and CSV Exports

**Feature**: `020-accounting-financial-reports` | **Asserted by**: `FinancialReportExportTest`

The on-screen columns and the exported columns are the same set in the same order, so a reader can check one against the other. Signed-versus-raw treatment is fixed by research [§R4](../research.md) and is repeated per column here because getting it uniform is the most likely single mistake in the feature.

## §1 Rules common to every export

| # | Rule | Source |
|---|---|---|
| C-1 | Streamed via `response()->streamDownload`, not buffered | research §R8 |
| C-2 | Gated on `accounting.report.view`, re-checked **inside** the streaming method | FR-005, [permissions.md](./permissions.md) §5.3 |
| C-3 | First line is a **scope line** stating the report and its date bounds | FR-045 |
| C-4 | Second line is the header row | FR-044 |
| C-5 | Data rows match the screen exactly, in the same order | FR-044 |
| C-6 | Totals, subtotals, and the footing or equation proof are included as rows | FR-044 |
| C-7 | An empty report exports the scope line and header with zero data rows | FR-046 |
| C-8 | **Never paginated** — the export covers the whole scope | research §R8 |
| C-9 | Writes no `export_logs` row and no other persistent record | FR-047 |
| C-10 | Amounts as plain decimal strings, two places, no thousands separator, no currency symbol | FR-012 |
| C-11 | `fputcsv(..., escape: '\\')`, matching `ListPurchasingReports` | precedent |

**On C-3.** A detached CSV with no period stated is the classic way a Q1 balance sheet gets circulated as a Q2 one. The scope line costs one row and removes the failure mode.

**On C-8.** A paginated general-ledger export would silently misrepresent the period and could not reconcile to the trial balance, breaking SC-007. The spec's §Scope also forbids silent caps.

## §2 Trial Balance

Scope line: `Trial Balance — {from} to {to}`

| Column | Treatment |
|---|---|
| `account_code` | — |
| `account_name` | — |
| `account_type` | — |
| `opening_balance` | **signed** |
| `period_debit` | **raw** |
| `period_credit` | **raw** |
| `closing_balance` | **signed** |

Trailing rows: `TOTAL` with `period_debit` and `period_credit` sums, then a proof row — `BALANCED` when they are equal, or `OUT OF BALANCE BY {variance}` when they are not (FR-023, FR-024). The proof row is **never omitted** when it fails, and no figure is adjusted to make it pass.

Rows are omitted entirely for accounts with neither movement nor a non-zero opening balance (FR-022) — not exported as rows of zeroes.

## §3 General Ledger

Scope line: `General Ledger — {from} to {to}` plus `— Account {code} {name}` when filtered.

| Column | Treatment |
|---|---|
| `entry_number` | — |
| `entry_date` | — |
| `account_code` | — |
| `account_name` | — |
| `description` | — |
| `debit` | **raw** |
| `credit` | **raw** |
| `running_balance` | **signed** |

The last `running_balance` for an account must equal that account's `closing_balance` on the trial balance for the same range (SC-007). The export test asserts this across both reports rather than within one.

## §4 Profit and Loss

Scope line: `Profit and Loss — {from} to {to}`

| Column | Treatment |
|---|---|
| `section` | `Income` or `Expense` |
| `account_code` | — |
| `account_name` | — |
| `amount` | **signed** |

Trailing rows: `SUBTOTAL Income`, `SUBTOTAL Expense`, then `NET PROFIT` or `NET LOSS` — the label itself distinguishes the two (FR-032), not a minus sign alone.

Asset, liability, and equity accounts never appear (FR-031).

## §5 Balance Sheet

Scope line: `Balance Sheet — as of {asOf}`

| Column | Treatment |
|---|---|
| `section` | `Asset`, `Liability`, or `Equity` |
| `account_code` | empty for the computed earnings line — it is not an account |
| `account_name` | for the computed line: `Accumulated Earnings (computed, not posted)` |
| `amount` | **signed** |

Trailing rows: `SUBTOTAL Assets`, `SUBTOTAL Liabilities`, `SUBTOTAL Equity (posted)`, `Accumulated Earnings (computed)`, then a proof row — `BALANCED` or `OUT OF BALANCE BY {variance}` (FR-036, FR-037).

**The computed line's label must say it is computed** (FR-034). An accountant reading an exported balance sheet has no other way to know that figure is not a posted balance, and mistaking it for one would send them looking for a journal entry that does not exist.

## §6 Posting Register

Scope line: `Posting Register — {from} to {to}`

| Column | Treatment |
|---|---|
| `entry_number` | — |
| `entry_date` | — |
| `description` | — |
| `fiscal_period` | — |
| `posted_by` | — |
| `source` | see below |
| `account_code` | one row per line |
| `account_name` | — |
| `debit` | **raw** |
| `credit` | **raw** |

One CSV row per journal entry **line**, with the entry-level columns repeated, so the file is flat and spreadsheet-pivotable.

`source` rendering, matching [data-model.md](../data-model.md) §Posting Register:

| Morph state | Cell |
|---|---|
| absent | empty |
| a reversal pointing at a `JournalEntry` | that entry's `entry_number` |
| a recognised model that resolves | its label |
| an unrecognised model type | `{Type} #{id}` |
| a target that no longer resolves | `{Type} #{id} (unresolved)` |

The last two must not fail the export (FR-041, FR-042, SC-011). They are the reason `019`'s invoices, payments, and credit notes will appear here with no change to this feature.

## §7 Labels

Every column header, section label, proof label, and report name needs an English key under `lang/en/admin.php` (FR-051). Arabic falls back to English per the convention at the top of `lang/ar/admin.php`. `AccountingEnglishLabelsTest` is extended to cover the new keys, following its existing shape.

CSV **header cells use stable snake_case identifiers** — `period_debit`, not the translated label. A translated header would make the file's shape depend on the reader's locale and would break any spreadsheet built on it.
