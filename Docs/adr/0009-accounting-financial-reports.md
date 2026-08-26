# ADR 0009: Adopt the Existing Filament Dashboard for Accounting Financial Reports

**Status**: Accepted

**Date**: 2026-08-23

**Accepted**: 2026-08-23

**Deciders**: Project Owner

**Constitution**: Recorded as the eighth narrow Filament dashboard exception at version **1.9.0**

**Related**: `specs/020-accounting-financial-reports/spec.md`, `Docs/PRD.md` §5 FR-023 and §11, `Docs/SDD.md` §Reports and Notifications, `Docs/database/ERD.md`, ADR 0005 (Activitylog), ADR 0007 (Accounting foundation), ADR 0008 (Sales, Payments, Credit Notes), and the IERP Constitution Product Scope & Boundaries and Specification Governance sections

## Context

ADR 0007 authorised the accounting foundation and it shipped as
`018-chart-of-accounts-journals`: five tables, a posting service with balance
validation and reversal, posted-entry immutability, fiscal periods that close,
per-account balances, and a single-account ledger.

What it did not authorise is any way to read the ledger in aggregate. ADR 0007's
out-of-scope list is explicit, and it is explicit in the strongest available
terms — it names the reports individually rather than gesturing at a category:

> It does not approve ... financial reports of any kind — including a trial
> balance, profit and loss, or balance sheet ...

The same exclusion is restated in constitution §Product Scope & Boundaries and
in `Docs/PRD.md` §11. So the ledger currently has the property that an
accountant can post to it and inspect one account at a time, but cannot ask
whether the books balance. That is a strange resting place, and it is the gap
this ADR closes.

### What the documentation asks for

The documented requirement is thin but not absent. `Docs/PRD.md` §5 FR-023 says
reports "must cover sales, invoices, payments, tax, inventory, employees,
tickets, and CRM" — a list that conspicuously omits the general ledger, because
the PRD's reporting requirement was written from the operational side. §11's
exclusion is an ADR 0007 consequence, not an owner preference against reporting.
`Docs/SDD.md` §Reports and Notifications describes reporting generically for
every module.

More useful than any of that is what the ledger itself implies. A double-entry
system whose entries are validated on posting but never aggregated afterwards
cannot demonstrate the one property that makes double entry worth the cost: that
debits equal credits across the whole book, not merely within each entry. Spec
018 proves the invariant per entry. Only a trial balance proves it globally.

### Why this is separate from ADR 0008

ADR 0008 covers the sales lifecycle, payments, and credit notes, and it
explicitly excludes "financial reports of any kind, including an aged-receivables
report, a sales report, and a tax report," assigning them to
`014-reporting-notifications-audit`. That assignment is right for
*sales* reporting. It is wrong for the general ledger, because ledger reporting
needs nothing from the sales module — it needs only the five accounting tables
that already exist.

Waiting for `014` would leave the built ledger unreadable in aggregate for the
whole of the sales, payments, and credit-note programme, during which the ledger
acquires its first automatic postings. Reviewing those postings without a trial
balance is exactly the situation this ADR exists to avoid.

## Decision

The existing `/admin` Filament panel is approved for **read-only reporting over
the posted general ledger**. Concretely:

- **Five report surfaces**, all derived from posted journal entry lines and from
  nothing else: a Trial Balance with opening balance, period debits, period
  credits, and closing balance per account; a General Ledger of posted lines with
  a running balance; a Profit and Loss over a date range subtotalled by account
  type; a Balance Sheet as of a date; and a Posting Register listing posted
  entries with their lines, fiscal period, posting user, and source.
- **Date scoping** by range or as-of date, with fiscal-period selection offered
  as a convenience over both open and closed periods. Closing a period stops
  postings, not reads.
- **Displayed integrity proofs.** The Trial Balance displays the equality of its
  debit and credit totals; the Balance Sheet displays the accounting equation.
  Where a proof fails, the report surfaces the discrepancy as an error. It may
  not round, suppress, adjust, or plug it. A reporting layer that can silently
  correct what it reports destroys the only reason to trust it.
- **A computed accumulated-earnings equity line** on the Balance Sheet, holding
  the net of all income and expense movement from ledger inception through the
  as-of date, labelled as computed rather than posted. This is authorised
  because it is the only way the statement can balance while ADR 0007's
  exclusion of a year-end retained-earnings close stands. It is a presentation
  device, not an accounting event.
- **One new permission**, `accounting.report.view`, in the existing
  `AccountingPermission` catalogue, implied by no other permission — in
  particular not by `accounting.ledger.view`, which grants one account at a time.
  Granted to Chief Accountant, Accountant, and Reviewer.
- **A streamed CSV export per report**, gated on the same permission as the
  screen that produces it and enforced on the export request itself.
- **Resolution of a latent navigation defect.** `admin.resources.financial_reports`
  is registered twice in `AdminModuleRegistry::groups()`, in both the `accounting`
  and `reports` groups. It is registered once from now on, in `reports`, matching
  every sibling report resource. A test asserts the invariant for all modules,
  not just this one.

### Schema

**No table is added, altered, or removed.** No column, no index, no migration.
This is the first module-scale feature in the project with no schema change at
all, and that property is worth stating because it is what makes the decision
cheap to reverse: reverting this ADR deletes classes and a permission row, and
leaves no data behind.

### Out of scope

This ADR approves no write of any kind. It specifically does not approve:

- **Any write path.** No create, update, or delete of any row, in any table, by
  any class in this feature.
- **Any automatic posting.** ADR 0007's no-automatic-posting rule and ADR 0008's
  three named posting events are untouched. This feature adds no fourth caller
  and no observer, listener, or event.
- **A year-end retained-earnings close.** Accumulated earnings are computed for
  display. Nothing is posted to Retained Earnings or to any other account.
- **Accounts-receivable or accounts-payable subledgers**, aged-receivables or
  aged-payables reports, supplier bills, expenses, refunds, or tax definitions.
  These are specified as `021-accounting-receivables-tax-refunds` and
  `022-accounting-payables-expenses-bills` and require their own ADRs.
- **Any API surface**, dashboard-facing or public.
- A cash-flow statement; budget-versus-actual; comparative or multi-period
  columns; consolidation; segment or dimension reporting; multi-currency or
  revaluation; cost accounting, inventory valuation, or cost-of-goods-sold
  derivation; bank reconciliation; PDF rendering; scheduled or emailed report
  delivery; saved report definitions; or report-result caching.
- **Any new dependency.** No Composer or npm package. `barryvdh/laravel-dompdf`
  is installed by ADR 0008's feature, not this one, and nothing here renders a
  PDF.

**ADR 0006's prohibition on accounts-payable and general-ledger behaviour in the
Purchasing module survives this ADR untouched.** A ledger that can now be
reported on is still not permission to post to it, and reporting on it grants
Purchasing nothing.

## Consequences

**Positive.** The ledger becomes verifiable from the dashboard: the global
debit-equals-credit invariant, which spec 018 could only assert per entry, is
now demonstrable across the whole book. That matters most precisely while ADR
0008's automatic postings are being reviewed. The feature is read-only, so it
cannot corrupt what it reads, and it has no schema footprint, so it is unusually
cheap to revert. It is also independent of ADR 0008 in both directions, so the
two programmes can proceed in either order.

**Negative.** It adds a seventh module to the Filament panel and a fourth ADR
relaxing ADR 0007's exclusions, which continues a pattern of incremental scope
expansion the constitution's out-of-scope list was written to slow down. The
computed accumulated-earnings line is a presentation device standing in for an
accounting process that does not exist; it is correct, but it will read as
unfamiliar to an accountant expecting a retained-earnings/current-year split,
and it will need revisiting when a year-end close is specified. Reports derived
from lines rather than from stored balances will get slower as the ledger grows;
the specification bounds query count rather than promising latency, and caching
is deliberately excluded, so a large ledger is a known future cost.

**Neutral.** Because these reports read the ledger rather than the documents,
every entry ADR 0008's feature posts appears in them with no change to either
specification. The Posting Register's generic source rendering is the whole of
that integration.

## Alternatives considered

**Wait for `014-reporting-notifications-audit`.** Rejected. It would leave the
ledger un-auditable in aggregate throughout the sales and payments programme,
and `014` bundles ledger reporting with notifications and audit visibility,
which share no data and no permission with it.

**Amend ADR 0007 instead of writing a new ADR.** Rejected on reviewability. ADR
0007 is Accepted; a diff against it reads as a revision of a settled decision
rather than as a new one, and the one-ADR-per-scope-expansion pattern of
0001–0008 is easier to audit.

**Add stored balance columns to make reports fast.** Rejected. Spec 018 already
rejected stored balances for the same reason: a cached balance is the first thing
to disagree with the ledger after any direct write, and the ledger's whole value
is that the lines are the truth. Reports that recompute cannot drift.

**Post a year-end close so the Balance Sheet balances naturally.** Rejected —
that is a substantial accounting process ADR 0007 excluded on purpose, and it
would make this read-only feature a writing one, forfeiting the property that
makes it safe.

**Five separate navigation entries, one per report.** Rejected. The registry
reserves one `financial_reports` slot; five would need five labels, five
permission decisions, and five registry entries. A single surface with a
report-type selector follows the established `InventoryReportType` pattern.
