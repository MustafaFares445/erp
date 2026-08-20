# Quickstart: Validating the Accounting Foundation

**Feature Directory**: `018-chart-of-accounts-journals`

**Created**: 2026-08-18

Manual validation, in the order that builds on itself. Every scenario maps to a
user story in `spec.md` and to automated tests in `plan.md` §Testing Strategy —
this document exists to confirm the dashboard behaves as specified, not to
substitute for those tests.

## Prerequisites

```bash
php artisan migrate:fresh --seed
```

That runs `AccountingPermissionSeeder`, `ChartOfAccountsSeeder`, and
`AccountingDemoSeeder` along with the rest.

Confirm the seeded reference data:

```bash
php artisan tinker --execute 'echo App\Models\AccountType::count(), " account types, ", App\Models\ChartAccount::count(), " accounts, ", App\Models\FiscalPeriod::count(), " periods";'
```

Expect exactly **5** account types. Then create three users at `/admin`, or via
tinker, and assign one role each: `Chief Accountant`, `Accountant`, `Reviewer`.

## Scenario 1 — Roles and permissions (US1, run first)

As **Chief Accountant**:

1. Open `/admin`. The sidebar's Accounting group shows **Chart of Accounts**,
   **Journal Entries**, and **Fiscal Periods** as real links.
2. The other six items — Accounts Receivable, Accounts Payable, Bills, Expenses,
   Refunds, Financial Reports, Taxes — still open the placeholder page. That is
   correct and is asserted by SC-004.

As **Accountant**:

3. All three pages load.
4. Open a posted journal entry: **no Reverse action is offered.**
5. Open a fiscal period: **no Close action is offered.**

As **Reviewer**:

6. All three pages load read-only. No Create button, no Edit action, no Post,
   Reverse, or Close.

As a user with **no accounting role**:

7. The Accounting group's three real links are absent from the sidebar, and
   navigating directly to `/admin/chart-accounts` is refused.

## Scenario 2 — Chart of accounts (US2)

As **Accountant**, on Chart of Accounts:

1. The list shows code, name, type, parent, postable, active, and a balance per
   account. Header accounts are not postable; leaves are.
2. Create an account reusing an existing code → validation error naming the
   duplicate. Nothing is created.
3. Create an account under an existing **postable leaf** as its parent → saves,
   and the parent is now shown as **not postable** (FR-008 cleared it
   automatically).
4. Edit an account that has children and try to tick Postable → validation error
   explaining that only leaf accounts may be posting targets.
5. Open an account with posted lines and try to delete it → refused, with a
   message naming the posted lines.
6. Mark that same account **inactive** → succeeds. Inactive blocks future
   postings, never history.
7. Edit an account and set its parent to one of its own children → validation
   error reporting the cycle.

## Scenario 3 — Fiscal periods (US3)

As **Chief Accountant**, on Fiscal Periods:

1. The twelve monthly periods for the current year are listed, all open.
2. Create a period whose dates overlap an existing one → validation error naming
   the overlap.
3. Create one whose end date precedes its start → validation error.
4. Close January. It shows as closed, and `/admin/audit-logs` carries an
   `accounting.fiscal_period.closed` entry attributed to you.
5. Reopen January → an `accounting.fiscal_period.reopened` entry appears.
6. Try to delete a period that has entries → refused.

## Scenario 4 — Draft and post a balanced entry (US4)

As **Accountant**, on Journal Entries:

1. Create an entry: today's date, a description, and two lines — debit one
   postable account 100.00, credit another 100.00. Save. It appears as a
   **draft** with a generated number like `JE-000007`.
2. Edit its description and amounts, and save again. Drafts are freely editable.
3. Change the credit line to 90.00 and click **Post** → refused, with a message
   stating **both** totals (100.00 vs 90.00). The entry stays a draft. *(This is
   the one acceptance criterion `Docs/IMPLEMENTATION_PLAN.md` §6 states
   outright.)*
4. Put both a debit and a credit on the same line and Post → refused, naming the
   line.
5. Leave a line with neither amount and Post → refused, naming the line.
6. Delete a line so only one remains and Post → refused.
7. Point a line at a **non-postable header account** and Post → refused, naming
   the account.
8. Point a line at the account you marked inactive in Scenario 2 and Post →
   refused, naming the account.
9. Set the entry date into a year with no fiscal period and Post → refused.
10. Set the entry date inside the period you closed in Scenario 3, reclose it if
    needed, and Post → refused, naming the closed period.
11. Restore the balanced two-line version with today's date, and Post → status
    becomes **posted**, the fiscal period is stamped, and an
    `accounting.journal_entry.posted` audit entry appears.
12. Try to edit the now-posted entry → the form is read-only and the lines
    repeater is disabled.
13. Try to delete it → no delete action is available.

Rounding check: post a two-line entry of `33.33 / 33.33` → succeeds. Change it
to `33.33 / 33.34` → refused.

## Scenario 5 — Reversal (US5)

As **Accountant**: open the posted entry — **no Reverse action** (FR-040's
separation of duties).

As **Chief Accountant**:

1. Open the same posted entry → **Reverse** is available.
2. Reverse it, accepting the default date. A new posted entry is created whose
   lines mirror the original with debits and credits swapped, whose description
   reads `Reversal of JE-…`, and whose Source points at the original.
3. Try to reverse the original again → refused, naming the existing reversal.
4. Check both accounts' balances on Chart of Accounts → the pair nets to zero.
5. Reverse a different posted entry, this time supplying a date in a **later**
   open period → the reversal lands in that later period.
6. Close the period containing a third posted entry, then try to reverse it →
   refused. A reversal is a posting and obeys the same rule.
7. `/admin/audit-logs` shows **two** rows per reversal — `reversed` on the
   original and `posted` on the new entry.

## Scenario 6 — Balances and ledger (US6)

As **Accountant**:

1. On Chart of Accounts, each account's balance reflects posted lines only.
   Create a draft entry against an account and confirm its balance does **not**
   move.
2. A debit-normal account (Asset, Expense) with debits exceeding credits reads
   **positive**. A credit-normal account (Liability, Equity, Income) holding its
   normal balance also reads **positive** — the sign convention is inverted per
   type, so a normal balance never reads negative.
3. A header account's balance equals the sum of its descendants'.
4. Open a single account → its ledger lists posted lines with entry number,
   date, description, debit, credit, and a running balance.

## Scenario 7 — Nothing posts automatically (SC-008)

The most important check in this document, because the failure it guards against
is silent.

1. Note the current entry count:
   ```bash
   php artisan tinker --execute 'echo App\Models\JournalEntry::count();'
   ```
2. Exercise every existing money-or-stock path in the dashboard: create and
   fulfil an order, complete a delivery, settle a chargeable ticket's payment
   link, and complete an inventory receipt and an adjustment.
3. Re-run the count. **It must be unchanged.** ADR 0007 grants no automatic
   posting; the interface exists and is deliberately unwired.

## Full gate

```bash
vendor/bin/pint --dirty --format agent
```

```bash
vendor/bin/phpstan analyse
```

```bash
composer test
```

PHPStan must report **no new baseline entries** — the baseline may only shrink.
