# ADR 0011: Adopt the Existing Filament Dashboard for Payables — Expenses, Supplier Bills, and Accounts Payable

**Status**: Accepted

**Date**: 2026-08-26

**Deciders**: Project Owner

**Related**: `specs/022-accounting-payables-expenses-bills/spec.md`, `Docs/PRD.md` §5 and §11, `Docs/SDD.md` §Supplier Management, `Docs/database/ERD.md`, ADR 0005 (Activitylog), **ADR 0006 (Purchasing) — amended by this ADR**, ADR 0007 (Accounting foundation), ADR 0008 (Sales, Payments, Credit Notes), ADR 0009 (Financial Reports), ADR 0010 (Receivables, Tax, Refunds), and the IERP Constitution Product Scope & Boundaries and Specification Governance sections

## Context

`017-purchasing-orders-suppliers` shipped under ADR 0006: purchase orders with an
approval gate, transmission, supplier confirmations, receiving through the
existing Inventory services, supplier product references, and purchasing
reports. ADR 0006 drew its boundary in unusually strong terms, and the
constitution then reinforced it twice.

ADR 0006 §Out of scope excludes "supplier bills, accounts payable, payments to
suppliers, journal entries, purchase-tax recognition." Constitution
§Specification Governance, at 1.6.0, added:

> Adding any accounts-payable or general-ledger behaviour to the Purchasing
> module before `006-chart-of-accounts-and-journals` exists would skip a
> prerequisite and violate this section, regardless of how small the addition
> appears.

and at 1.7.0, after the ledger shipped, deliberately declined to relax it:

> the completion of 018 does **not** relax the Purchasing prohibition above.
> That prohibition survives because ADR 0006 excludes supplier bills, accounts
> payable, payments to suppliers, journal entries, and purchase-tax recognition
> from the Purchasing module's scope — not merely because the ledger did not
> exist when ADR 0006 was written. **A ledger that exists is not permission to
> post to it.**

That passage is the reason this ADR is worded the way it is. The purchasing side
of the business currently records what was ordered and what arrived, and records
nothing about what is owed. Three `accounting` navigation slots — `expenses`,
`bills`, `accounts_payable` — have been reserved for that since the panel was
built, and all three are still placeholders.

### The distinction this ADR turns on

The constitution forbids accounts-payable behaviour **in the Purchasing module**.
It does not forbid accounts payable. This ADR builds bills, expenses, supplier
payments, and the payable subledger in the **Accounting** module, where the
ledger and the posting service already live, and has them *read* purchase orders.

That distinction is real, not a technicality, and it is worth being explicit
about why: the prohibition exists so that a purchasing officer cannot create an
accounting artefact as a side effect of a purchasing action. Building payables in
Accounting preserves that exactly — a purchase order still creates no liability;
an accountant creates the liability, from a supplier's invoice, in the accounting
module, with its own approval and its own separation of duties.

The amendment ADR 0006 needs is therefore narrow, and it is the whole of what
this ADR takes from it: **an accounting document may reference a purchase order
and its received quantities.** Nothing flows the other way.

## Decision

The existing `/admin` Filament panel is approved for **payables administration**
in the Accounting module. Concretely:

- **Expenses** with a `draft` → `approved` → `paid` lifecycle, an expense
  account, an optional supplier and requesting employee, and a receipt attachment
  through Media Library.
- **Supplier bills** with priced lines each carrying their own account, an
  optional purchase-order reference, payment-term-derived due dates, and a
  `draft` → `approved` → `partially_paid` → `paid` lifecycle. A supplier's own
  invoice number is unique per supplier, which is the feature's primary
  duplicate-payment control.
- **An advisory three-way match.** Where a bill line references a purchase-order
  line, the surface shows ordered, cumulative received, and cumulative billed
  quantities and flags quantity and price variances. It does **not** refuse the
  bill. The approver is the control; a blocking rule would make a legitimate
  over-delivery or partial bill unrecordable and push the accountant outside the
  system.
- **Supplier payments** with allocation across several bills, mirroring the
  customer-side allocation model.
- **An Accounts Payable subledger**, computed and never stored: per-supplier
  billed, paid, and outstanding amounts, aged against bill due dates, with a
  per-supplier drill-down — and **mandatory reconciliation against the payable
  control account**, displayed as an explicit proof, with any difference shown
  prominently as an error and never adjusted, rounded, suppressed, or plugged.
- **Four additional authorised ledger-posting events**, and no others: bill
  approval, expense approval, expense payment, and supplier payment. Bill and
  expense approval recognise the liability; the two payment events clear it.
  With ADR 0008's three and ADR 0010's one, the authorised posting callers now
  number eight, and that list is closed until amended.
- **Permissions** for viewing, recording, and approving expenses and bills, for
  recording supplier payments, and for viewing the payable subledger, with
  `manage` never implying `approve` and a recorder never able to approve their own
  document.

### Two accounting decisions this ADR takes deliberately

**Purchased goods are expensed, not capitalised.** A bill line debits an account
the accountant chooses. It does not debit Inventory, because that is inventory
valuation, which ADR 0007 excludes and ADR 0008 also declines. The consequence is
stated rather than hidden: in this phase the ledger shows purchase cost at bill
date rather than as inventory carried until sale. This is symmetric with ADR
0008's accepted limitation of revenue without matched cost, and both resolve
together when a valuation feature is specified. An accountant reading the profit
and loss will notice this immediately, so it belongs in the decision record
rather than in a code comment.

**Input tax is recognised at bill approval, posted to one new seeded account,
`1450 Recoverable Input Tax`.** Constitution Principle III makes *output* tax
follow collection; it says nothing about input tax, and `Docs/PRD.md` §12's
currency and tax-rate question is still open. This is the decision in this ADR
most likely to be wrong for the eventual jurisdiction, and it is deliberately
isolated to one account and one posting line so that changing it later is cheap.

### Amendment to ADR 0006

ADR 0006 is amended **only** as follows: an accounting document may hold a
reference to a purchase order and to a purchase-order line, and may compute
received quantities from the existing inventory operation records, for the
advisory match described above.

ADR 0006 is **not** otherwise relaxed. Specifically, the Purchasing module gains
no bill, no payable, no supplier payment, no journal entry, and no purchase-tax
behaviour; no purchasing class, table, column, or surface is modified by this
feature; and no purchasing surface may display a bill, a payable balance, or a
billed amount. The dependency direction is Accounting → Purchasing and never the
reverse, enforced by an architecture test rather than by convention — because the
natural next change, showing a purchase order's billed amount on the purchase
order, is exactly what ADR 0006 forbids and exactly what a helpful developer would
add. The supplier-facing portal the constitution excludes is **not** relaxed.

### Schema

Five tables are added — `expenses`, `bills`, `bill_lines`, `supplier_payments`,
`supplier_payment_allocations` — and one account, `1450 Recoverable Input Tax`, is
seeded. No purchasing table gains a column. `accounts_payable` does not become a
table. See `specs/022-accounting-payables-expenses-bills/spec.md` §ERD Divergence
Register for the full register, every row of which is recorded in
`Docs/database/ERD.md` by this accepted decision.

**A supplier payment is not a `payments` row.** The ERD's `payments` table is
customer-facing with a non-nullable `customer_id`, and ADR 0008 builds it that
way. Reusing it for outbound money would break every sum in the Payments module
and, as ADR 0010 records for refunds, would corrupt proportional tax recognition.

### Out of scope

- **Any change to the Purchasing module** beyond the read reference above.
- **Inventory valuation, capitalisation, cost-of-goods-sold posting, landed-cost
  allocation, and moving-average or FIFO recalculation.**
- **Supplier cost writeback.** ADR 0006's feature already does this through its
  own service; this ADR adds no second path and touches neither.
- **Accounts receivable, the tax register, and customer refunds** (ADR 0010), and
  **general-ledger statements** (ADR 0009).
- **Any API surface**, and **no supplier-facing portal.**
- **Purchase requisitions, RFQs, supplier returns, and debit notes** — still
  excluded by ADR 0006 and not reopened here.
- **Recurring bills or expenses, scheduled payment runs, payment batching, and
  bank file generation.**
- **Bank accounts, bank reconciliation, and cash-flow forecasting.**
- **Input-tax filing or any statutory return.**
- **Employee expense reimbursement through payroll.** An expense may name the
  requesting employee; settling it through the salary path is not wired.
- **Multi-currency**, conversion, or revaluation.
- **A ninth posting caller**, and **no new Composer or npm dependency.**

## Consequences

**Positive.** The business can finally record what it owes, which is the largest
functional gap left in the finance module, and it can prove the payable control
account is correct. The duplicate-payment control — unique supplier invoice number
per supplier — is the single highest-value control in the feature and costs one
index. The three-way match makes purchasing and accounting agree without coupling
them. And because both prerequisites are already built, this is the only remaining
accounting feature that can be implemented immediately, independent of the sales
programme.

**Negative.** It amends an Accepted ADR, which the project has not done before,
and it does so for a boundary the constitution reinforced twice — so the amendment
will need careful reading to confirm it is as narrow as it claims. It adds four
posting callers, bringing the authorised total to eight.
It adds five tables, the largest schema addition since the ledger itself. The
expense-not-capitalise decision leaves the ledger's cost timing wrong in a way an
accountant will notice, accepted only because inventory valuation is a much larger
feature. And the input-tax timing decision is a guess about a jurisdiction that
`Docs/PRD.md` §12 has not yet named.

**Neutral.** Payable postings appear in ADR 0009's reports automatically. This
feature shares only the ledger with ADR 0008's and ADR 0010's work, and shares
`payment_terms` with ADR 0008's feature — whichever lands first creates that
table and the other reuses it.

## Alternatives considered

**Amend ADR 0006 broadly and build payables in the Purchasing module.** Rejected.
It is where a purchasing officer would look for a bill, and it is precisely what
the constitution forbade twice, on the reasoning that a purchasing action must not
create an accounting artefact as a side effect. Building in Accounting satisfies
the intent rather than negotiating with the words.

**Block the bill on a failed three-way match.** Rejected. Over-delivery, partial
billing, and price renegotiation are all normal, and a hard block would make them
unrecordable — which does not prevent the payment, it just moves the record into a
spreadsheet.

**Capitalise purchased goods to Inventory.** Rejected as out of scope: it is
inventory valuation, which requires a costing method, a revaluation path, and
cost-of-goods-sold posting on sale. Doing a third of it would be worse than doing
none.

**Defer input tax entirely and record bills net of tax.** Rejected. Bills carry
tax, and dropping it would make the payable subledger disagree with every supplier
invoice by the tax amount — an immediate, guaranteed tie-out failure rather than a
possible future one.

**Reuse the `payments` table for outbound money.** Rejected on correctness — see
§Schema.
