# ADR 0010: Adopt the Existing Filament Dashboard for Receivable, Tax, and Refund Administration

**Status**: Proposed

**Date**: 2026-08-23

**Deciders**: Project Owner

**Related**: `specs/021-accounting-receivables-tax-refunds/spec.md`, `Docs/PRD.md` §5 FR-010/FR-011 and §11, `Docs/SDD.md` §Tax Recognition and §Credit Notes, `Docs/database/ERD.md`, constitution Principle III (NON-NEGOTIABLE), ADR 0005 (Activitylog), ADR 0007 (Accounting foundation), ADR 0008 (Sales, Payments, Credit Notes), ADR 0009 (Financial Reports)

## Context

ADR 0008 delivers the sales lifecycle, and with it invoices, manual payments,
proportional tax recognition on collection, and credit notes. It deliberately
stops short of three things it creates the data for:

> accounts-receivable and accounts-payable **subledger pages** — the
> `Accounts Receivable` and `Accounts Payable` navigation items stay
> placeholders even though this feature creates the receivable balances they
> would report on

and it leaves `refunds` and `taxes` as placeholders too, both being named in ADR
0007's out-of-scope list.

That leaves the system in a specific and uncomfortable state once ADR 0008 ships.
Receivable balances will exist, spread across invoices, allocations, and credit
notes, and posted to a control account in the ledger — with no surface that can
say whether the documents and the control account agree. Tax will be recognised
on collection, as constitution Principle III requires, with no surface that can
demonstrate the rule was followed. And a customer who has overpaid or holds a
confirmed credit note will have money owed to them that the system offers no way
to return.

### Why a subledger is not a report

ADR 0009 authorises general-ledger reporting: trial balance, profit and loss,
balance sheet, general ledger, posting register. It is tempting to read a
receivable aging as one more report and fold it in there. That would be wrong,
for a reason worth stating precisely: ADR 0009's reports read *only* the five
accounting tables and reconcile nothing. A receivable subledger reads the
*documents* — invoices, payments, allocations, credit notes — and its entire
value is the comparison between what the documents say and what the control
account says. It is a reconciliation surface, not a statement, and it carries a
dependency on the sales module that ADR 0009 explicitly forbids itself.

### Why refunds cannot be deferred alongside the read surfaces

The receivable and tax surfaces are read-only. Refunds are not, and it would be
tidier to split them out. They are here for one reason: constitution Principle
III makes tax follow collection, and ADR 0008 implements that in one direction
only. Money coming in recognises tax. Money going back out currently
un-recognises nothing, because there is no path for money to go back out. The
moment a refund path exists it must reverse the recognition, or Principle III
holds asymmetrically — and an asymmetric tax rule is worse than an unimplemented
one, because it looks implemented.

## Decision

The existing `/admin` Filament panel is approved for **receivable, tax, and
customer-refund administration** in the Accounting module. Concretely:

- **An Accounts Receivable subledger**, computed and never stored: per-customer
  invoiced, collected, credited, refunded, and outstanding amounts, aged against
  invoice due dates, with a per-customer drill-down to the underlying documents.
- **A Tax register**, computed and never stored: tax recognised per period from
  the append-only recognition entries, against tax still deferred on uncollected
  invoices.
- **Mandatory control-account reconciliation on both.** The receivable subledger
  total is compared to the ledger balance of the receivable control account; the
  tax register's recognised and deferred totals are compared to the tax-payable
  and deferred-tax control accounts. Each equality is displayed as an explicit
  proof, and each failure is displayed prominently as an error. **Neither surface
  may adjust, round, suppress, or plug a difference.** A subledger that plugs its
  own variance converts a detectable posting bug into an undetectable one, which
  is the precise opposite of why these surfaces exist.
- **Customer refunds** with a `draft` → `approved` → `paid` lifecycle, recorded
  against an available credit balance computed from confirmed credit notes and
  overpayments. Recording and approving are separate permissions and MUST be held
  by different users for any given refund. An approved or paid refund is
  immutable and undeletable.
- **A fourth authorised ledger-posting event.** Marking a refund paid posts a
  journal entry debiting the receivable control account and crediting the payment
  method's account, and appends a **negative** tax-recognition row proportional to
  the refunded fraction of the collection it reverses. This is authorised here and
  nowhere else; ADR 0008's three events plus this one are the complete list as of
  this ADR.
- **Permissions**: `accounting.receivable.view`, `accounting.tax.view`,
  `accounting.refund.view`, `accounting.refund.manage`,
  `accounting.refund.approve`. `manage` does not imply `approve`.
- Streamed CSV exports gated on each surface's own view permission.

### Schema

One table is added, `refunds`, and one existing table is extended:
`tax_recognition_entries` gains a nullable `refund_id` and permits a negative
recognised amount. Neither `accounts_receivable` nor `taxes` becomes a table;
both are computed. See `specs/021-accounting-receivables-tax-refunds/spec.md`
§ERD Divergence Register for the full register, every row of which must be
written into `Docs/database/ERD.md` before implementation begins.

**A refund is not a negative payment.** Modelling it as a negative `payments` row
would be smaller, and it would silently corrupt ADR 0008's proportional tax
recognition, which divides collected amount by invoice total. A negative
collection would recognise negative tax at the wrong moment, and no test in ADR
0008's feature would catch it. The separate table is the point.

### Out of scope

- **Accounts payable, supplier bills, and expenses.** Those are ADR 0011's.
- **General-ledger statements.** Those are ADR 0009's. This feature reconciles
  *to* control accounts; it reports nothing else about the ledger.
- **Any API surface**, dashboard-facing or public.
- **Any gateway call.** Per ADR 0008 there is no payment gateway. A refund
  records that money was sent; it does not send it.
- **Bad-debt write-off.** It also reduces a receivable, but it recognises a loss,
  has different tax treatment, and needs different approval. Conflating it with a
  refund would smuggle a second posting decision into the refund path. It needs
  its own specification.
- **Dunning, reminder schedules, posted statements of account, credit limits, and
  credit holds.**
- **Any change to how ADR 0008 recognises tax on collection.** This ADR adds the
  reverse direction and must not alter the forward one.
- **Tax filing, tax returns, or any statutory report.** The Taxes surface is an
  internal register.
- **Multi-currency**, conversion, or revaluation.
- **A fifth posting caller**, and **no new Composer or npm dependency.**

**ADR 0006's prohibition on accounts-payable and general-ledger behaviour in the
Purchasing module survives this ADR untouched.**

## Consequences

**Positive.** The receivable control account becomes provably correct rather than
assumed correct, and it becomes so at exactly the moment ADR 0008 starts posting
to it automatically. Constitution Principle III becomes symmetric: tax follows
collection in both directions. A customer owed money can be paid, with separation
of duties and an audit trail on the one operation in the module that cannot be
undone for free.

**Negative.** It adds the fourth posting caller to a ledger that had none three
ADRs ago, which is a rate of expansion the constitution's out-of-scope list was
written to slow. It adds a hard ordering dependency: nothing here can be built or
tested before ADR 0008's feature exists, making this the only accounting
specification that cannot proceed independently. And the proportional tax
un-recognition is genuinely difficult arithmetic — a full refund must un-recognise
exactly what its collection recognised, with no residual minor unit, or the tax
tie-out fails by a cent and the surface correctly reports a defect that is in this
feature rather than in the postings it is checking.

**Neutral.** Refund postings appear in ADR 0009's reports automatically, because
those reports read the ledger rather than the documents. Neither ADR needs to
know about the other.

## Alternatives considered

**Fold the receivable aging into ADR 0009's financial reports.** Rejected. ADR
0009's reports read only the five accounting tables and reconcile nothing; a
subledger reads the sales documents and exists to reconcile. Merging them would
force ADR 0009 to take a sales-module dependency it explicitly refuses.

**Defer everything to `014-reporting-notifications-audit`.** Rejected. That entry
covers reporting, notifications, and audit visibility. Refunds are a write path
with money leaving the business; they are not reporting, and filing them under a
reporting entry would hide the fourth posting caller inside a feature nobody
expects to contain one.

**Model a refund as a negative payment.** Rejected on correctness — see §Schema.

**Let a refund be a free-form disbursement rather than requiring a credit
balance.** Rejected. Without the credit-balance rule the Refunds surface becomes
a way to move money out of the business with no document behind it, which no
amount of permission granularity compensates for.

**Skip tax un-recognition and treat a refund as a pure cash movement.** Rejected.
It is the cheaper implementation and it breaks Principle III asymmetrically,
leaving recognised tax overstated by every refund ever issued.
