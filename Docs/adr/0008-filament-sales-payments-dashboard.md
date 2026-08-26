# ADR 0008: Adopt the Existing Filament Dashboard for the Sales Lifecycle, Payments, and Credit Notes

**Status**: Accepted

**Date**: 2026-08-23 | **Accepted**: 2026-08-23

**Deciders**: Project Owner

**Related**: `specs/019-sales-lifecycle-payments-credits/spec.md`, `Docs/PRD.md`, `Docs/SDD.md`, `Docs/database/ERD.md`, `Docs/IMPLEMENTATION_PLAN.md` §8–§10, ADR 0001 (Inventory), ADR 0002 (CRM), ADR 0003 (Employees), ADR 0004 (Support), ADR 0005 (Activitylog), ADR 0006 (Purchasing), ADR 0007 (Accounting foundation), and the IERP Constitution Product Scope & Boundaries and Specification Governance sections

## Context

The constitution's Product Scope & Boundaries section permits a Filament
dashboard dependency only for the modules that have an ADR: Inventory (0001),
CRM (0002), Employees (0003), Support and Maintenance (0004), Purchasing
(0006), and the Accounting foundation (0007). Sales has none.

The `/admin` panel reserves six `sales` navigation links
(`app/Filament/AdminModuleRegistry.php`, labels at `lang/en/admin.php:872-877`).
Five of them — Quotations, Delivery Notes, Invoices, Payments, Credit Notes —
render the shared `ModulePlaceholder` page because their classes do not exist.
The sixth, Orders, resolves to a real `OrderResource`, but that resource has
only a list and a create page, and the `orders` table behind it
(`2026_08_03_073204_create_orders_table.php`) has no `grand_total`, no
`payment_status`, and no `quotation_id`; `order_lines` has no `unit_price` and
no `line_total`. What is built is a *delivery-scheduling* document, not the
ERD's sales order. Sales is therefore the largest unbuilt surface left in the
dashboard, and the only module whose navigation group is mostly placeholder.

### What the documentation asks for

Unlike Purchasing, this module is not thinly documented. It is the most heavily
specified area of the entire canonical set, and it is specified consistently.

`Docs/IMPLEMENTATION_PLAN.md` supplies three sequential phases with one
acceptance criterion each: §8 Sales Flow ("Given a delivery note is confirmed,
then stock decreases and no tax is recognized"), §9 Payments and Tax ("Given a
partial payment, then tax is recognized proportionally"), and §10 Credit Notes
("Given a credit note is confirmed, then the invoice is corrected without
physical deletion"). `Docs/PRD.md` names the lifecycle in its objectives
(§Objectives: "Quotation -> Delivery Note -> Invoice -> Payment"), lists eleven
functional requirements covering it (FR-003 through FR-013), and states the tax
rule twice more in §Business Rules. `Docs/database/ERD.md` supplies eighteen
tables and a status catalogue for five of them.

Most decisively, the constitution itself specifies this module in Principle III,
which is marked NON-NEGOTIABLE:

> The sales lifecycle MUST follow `Quotation → Delivery Note → Invoice →
> Payment`. Quotations MUST NOT affect inventory. Delivery notes affect
> inventory but MUST NOT recognize tax. Invoices represent the financial claim.
> Payments record actual collection.
>
> Tax MUST be recognized only when payment is collected; partial payments
> recognize tax proportionally.
>
> Manual payments and Stripe payments MUST share the same accounting and
> tax-recognition logic — no divergent code paths per payment channel.

No other module has its core invariants written into the constitution. This is
the module Principle III was drafted for.

### The three conflicts ADR 0007 deferred here

ADR 0007 §Why this is lower-risk explicitly named the three problems it was
avoiding and located all of them in extraction entry `007`:

> The three conflicts that make the sales-flow entry expensive — the built
> `orders` table's fulfillment semantics, the `delivery_notes`-versus-
> `InventoryOperation` question, and the accounting navigation items with no
> ERD tables — all live in `007` and later.

Two of the three were then settled in advance by project-owner decisions D2 and
D3, recorded in `specs/018-chart-of-accounts-journals/spec.md` §Owner Decisions
so that this specification would not reopen them: the built `orders` table is
extended rather than replaced, and no `delivery_notes` table is created — the
Delivery Notes surface derives from `InventoryOperation` rows with
`operation_type = 'delivery'`. D4 settled the PDF dependency.

The third conflict is resolved by scoping rather than by invention. The
accounting navigation items with no ERD table — Accounts Receivable, Accounts
Payable, Bills, Expenses, Refunds, Taxes — stay placeholders. This feature
creates receivable *balances* without building a receivable *subledger page*,
which is the same separation ADR 0007 drew between building the ledger and
reporting on it.

### The risks that are real

Four, in descending order of consequence.

1. **Tax recognised at the wrong moment.** Recognising tax at invoice issuance
   instead of at collection is a one-line mistake that misstates a statutory
   liability and is invisible until an audit. The seeded chart has
   `2300 Sales Tax Payable` and nothing else, so the naive posting is also the
   wrong one — crediting the only tax account available *is* the error. This is
   why one additive seeded account, `2350 Deferred Sales Tax`, is not optional.
2. **A second stock-writing path.** The sales lifecycle's delivery step is the
   most natural place in the whole system to write stock directly, and doing so
   would be exactly the divergent-code-path failure Principle III exists to
   prevent. D3 removes the temptation structurally: there is no delivery table
   to write against, only the operation that already moves stock.
3. **A second payment posting path.** Building manual payments now and Stripe
   later invites a `if ($channel === 'stripe')` branch in posting or tax code,
   which Principle III prohibits by name. Deferring Stripe does not reduce this
   risk; it defers it. The mitigation must therefore be structural and tested,
   not intentional.
4. **Rounding drift in proportional recognition.** Recognising tax per
   allocation, each rounded to the cent, does not in general sum to the
   invoice's tax total. Unreconciled, this leaves a permanent residue in the
   deferred-tax account for every invoice in the system.

### Regression surface

This is the first module to modify a built, in-production table rather than only
add to the schema. `orders` and `order_lines` carry live rows and are read by
`OrderFulfillmentService`, `DeliveryWarehouseAllocationService`,
`WarehouseStockService`, the `Shipment` flow, `SupplierConfirmation`'s
`confirmable` morph, and the `InventoryOperation.source_document` morph. Spec
017's supplier-confirmation-against-a-customer-order path runs through the same
table. Every one of these must behave identically afterwards.

### Alternatives considered

**Three separate features (007, then 008, then 009).** This is what ADR 0007
recommended and what this ADR reverses. Rejected by the project owner on
2026-08-23 in favour of one slice. The reasoning against three: the
tax-recognition rule spans all three entries, so specifying it three times
invites three subtly different readings of a non-negotiable principle; an
invoice without a payment path cannot demonstrate its own central invariant; and
the deferred-tax account would be created in 007, first used in 008, and first
reversed in 009, leaving two intermediate states in which the ledger is
arguably incomplete. See §Decision on how the size cost is contained.

**Defer Stripe's webhook but build the Stripe client now.** Rejected. A payment
channel with no way to be called is untestable, and Principle III's shared-logic
requirement is satisfied by the service boundary, not by the channel's presence.

**Post cost of goods sold on delivery.** Rejected. ADR 0007 excludes cost
accounting and inventory valuation, and neither is specified anywhere in the
canonical set. The consequence — revenue posted without matched cost — is
recorded as an accepted limitation of this phase rather than silently absorbed.

**Recognise tax at invoice issuance and adjust on collection.** Rejected. It
inverts a NON-NEGOTIABLE principle and would report a liability that is not yet
owed.

**Model an invoice correction as an edit, or as a cancel-and-delete.** Rejected.
Principle III forbids physically deleting a confirmed financial document. The
credit note is the correction path the PRD, the SDD, and
`Docs/IMPLEMENTATION_PLAN.md` §10 all name.

**Build an aged-receivables report while the data is fresh.** Rejected. It is
`014-reporting-notifications-audit`, and this feature is already the largest in
the project.

## Decision

Adopt a **seventh narrow exception** to the Filament out-of-scope rule: the
existing `/admin` panel is approved for the **Sales lifecycle, Payments, and
Credit Notes** — dashboard-only administration of the commercial documents
between a customer's quotation and the money collected against it.

This ADR delivers three consecutive entries of the documented extraction order
as one feature: `007-sales-flow-quotation-delivery-invoice`,
`008-payments-stripe-manual-tax-recognition` (manual channel only), and
`009-credit-notes`.

### Two reversals of ADR 0007, recorded as reversals

**First, the slice width.** ADR 0007 §Alternatives considered rejected building
the financial entries as one feature:

> **Build all four financial entries (006-009) as one feature.** Rejected. It
> is roughly twenty-four tables and fifteen Filament resources in one change,
> against the `.ai/feature-development` rule that changes stay small and
> reviewable […]

That judgement is **superseded** for entries 007 through 009 by project-owner
decision D5 of 2026-08-23. It is not withdrawn on the merits: the reviewability
concern was correct and remains correct. The owner has accepted that cost, and
this ADR records the reversal explicitly so that no future reader mistakes it
for an oversight or for the earlier judgement having been forgotten.

Two things reduce, but do not eliminate, the cost. Entry 006 already shipped
separately as spec 018, so this is three entries rather than four, and the
hardest of the four — the ledger itself — is behind us. And the specification's
nine user stories are ordered P1 → P3 with each independently shippable, so the
work lands as a sequence of reviewable increments. That ordering is binding on
`plan.md` and `tasks.md`: it must appear as real phase boundaries, not as labels
on one undifferentiated batch. If it degenerates into the latter, the mitigation
has failed and this decision was wrong.

**Second, automatic posting.** ADR 0007 states, in bold, that it approves no
automatic posting, and that connecting any document to the ledger "belongs to
that document's own feature and its own ADR." This is that ADR, for those
documents. Per project-owner decision D6, **exactly three events post to the
general ledger**:

| Event | Posting |
|---|---|
| Invoice issued | Dr receivable (grand total), Cr revenue (subtotal), Cr deferred tax (tax total) |
| Payment collected | Dr the method's collection account (amount), Cr receivable (allocated), Cr customer deposits (unallocated remainder); and per allocation, Dr deferred tax, Cr tax payable for the proportional tax |
| Credit note confirmed | Dr revenue (subtotal), Dr deferred tax and Dr tax payable split by the invoice's current recognition ratio, Cr receivable (grand total) |

Reversing a payment or a credit note reverses its own entries through
`JournalPostingService::reverse()` and posts nothing new of its own kind.

**No fourth caller is authorised by this ADR.** Quotations, orders, delivery
operations, purchase orders, inventory movements, ticket payments, spare-part
consumption, and every future document post nothing as a result of this
decision. Spec 018's `NoAutomaticPostingTest` is tightened rather than deleted:
it must assert that these three sources exist and that no other does.

### In scope

- **Payment terms**: due days, grace days, an optional discount, and a single
  default. Invoice due dates derive from them; overdue derives from due date
  plus grace.
- **Sales settings** as a singleton, following the `InventorySetting` and
  `PurchaseSetting` precedent: the default tax percent, the default quotation
  validity, and references to the four accounts the flow posts to. Posting
  accounts live in configuration rather than as hardcoded account codes, so the
  chart of accounts stays the accountant's to own.
- **Quotations**: priced lines defaulted from the existing pricing-tier
  resolution and guarded by the existing price floor; a
  `draft → sent → accepted | rejected | expired → converted_to_delivery`
  lifecycle; immutability once sent; expiry; and creation from an approved sales
  opportunity. A quotation affects **no** stock, in any state.
- **Orders**, extended in place per D2 with the accepted quotation's pricing, a
  payment term, stored totals, and a payment status, plus the view and edit
  surfaces the resource lacks. The built fulfillment behaviour is unchanged.
- **A Delivery Notes surface** derived per D3 from existing delivery operations.
  It creates no delivery record of its own, and every stock change it can reach
  goes through the existing Inventory operation services — the same constraint
  ADR 0006 placed on purchase-order receiving.
- **Invoices**: creation from a completed delivery operation or directly with
  manual lines; payment-term due dates; immutability once issued; no deletion of
  an issued invoice by any path; queued PDF generation into Spatie Media Library;
  queued email; and append-only receipt confirmation with an optional signature.
- **Ledger posting on invoice issuance**, atomic with issuing.
- **Payment methods**, each naming the postable account a collection through it
  debits and whether it requires proof.
- **Manual payments**: proof through Media Library, allocation across one or more
  invoices, an unallocated remainder posted to customer deposits, ledger posting,
  and **proportional tax recognition on collection** with the settling
  allocation absorbing the rounding residue so recognised tax equals invoice tax
  exactly. Immutable once posted; correctable only by reversal.
- **Credit notes**: `draft → confirmed | cancelled`, capped at the invoice's
  uncredited remainder, reversal posting split by the invoice's current
  recognition ratio, no physical deletion, and the same queued PDF path.
- **One additive seeded account**, `2350 Deferred Sales Tax`. Reference data,
  not a schema change, and required for tax timing to be correct at all.
- **`sales.*` permissions** and three fixed dashboard roles — Sales Manager,
  Sales Officer, Billing Officer — following the module-scoped catalogue pattern
  of `InventoryPermission`, `CrmPermission`, `EmployeePermission`,
  `SupportPermission`, `PurchasePermission`, and `AccountingPermission`, and
  added to `DashboardRole` so they narrow every other module's admin bypass.
- **Audit logging** of every state-changing sales event through
  `spatie/laravel-activitylog` per ADR 0005.
- **`barryvdh/laravel-dompdf`** as a new dependency, per D4.

### Out of scope

This exception does **not** approve:

- any API surface, under `/api/dashboard`, `/api/customer`, or any other prefix,
  and no unauthenticated route of any kind;
- the customer application or any customer-facing surface. Per D8, a customer's
  accept or reject of a quotation is **recorded by an admin or employee in the
  dashboard**, following the supplier-confirmation precedent in ADR 0006.
  Extraction entry `010-customer-app-flows` stays wholly out of scope, and no
  signed public link is created;
- **Stripe**, its client, its webhook, `stripe_payment_records`, and any online
  payment channel. Per D9 the manual channel is the only channel. Principle
  III's no-divergent-paths requirement is met structurally: one payment-posting
  service and one tax-recognition service, with no branch on channel in either,
  enforced by an architecture test. A later Stripe feature adds a channel record
  and a webhook, not a second accounting path;
- accounts-receivable and accounts-payable **subledger pages**. This feature
  creates the receivable balances such a page would report on, and the
  `Accounts Receivable` and `Accounts Payable` navigation items stay
  placeholders;
- supplier bills, expenses, refunds, and tax definitions. Per D7 tax is a single
  configurable default rate with a per-line override, and no `tax_definitions`
  table is created — it has no ERD table and gains none here. `Bills`,
  `Expenses`, `Refunds`, and `Taxes` stay placeholders;
- **financial reports of any kind**, including an aged-receivables report, a
  sales report, a tax report, a trial balance, profit and loss, and a balance
  sheet. `Financial Reports` stays a placeholder in both the `accounting` and
  `reports` groups and belongs to `014-reporting-notifications-audit`;
- document templates and the Settings page;
- **cost-of-goods-sold posting and inventory valuation.** A delivery reduces
  stock and posts nothing. The consequence — revenue recognised without matched
  cost — is an accepted limitation of this phase;
- multi-currency, currency conversion, and revaluation;
- recurring billing, subscriptions, and renewals;
- dunning, reminder schedules, and collection workflow beyond deriving an
  `overdue` status;
- customer credit limits and credit holds, which Product Scope & Boundaries
  already excludes and which this exception does **not** relax;
- sales commission calculation, which belongs to the Employees module's existing
  performance path;
- goods-return inventory movements arising from a credit note, and debit notes;
- wiring `TicketPaymentLink` to the Payments module. ADR 0004's exclusion of any
  journal entry, tax-recognition entry, or revenue posting arising from a ticket
  payment is **not** relaxed here;
- **any accounts-payable or general-ledger behaviour in Purchasing.** ADR 0006's
  prohibition survives this ADR completely intact. A ledger that now has three
  callers is not permission for a fourth, and the constitution's Specification
  Governance section says so in terms that this ADR does not disturb.

Implementing any of the above later requires its own specification and either a
separate ADR or an explicit amendment to this one.

## Consequences

**Positive.** The lifecycle Principle III mandates exists end to end for the
first time, and its central invariant — tax on collection, proportionally — is
demonstrable in one feature rather than inferable across three. The ledger built
by spec 018 stops being an unwired interface. The `orders` table becomes the
sales order the ERD describes without a parallel table to reconcile against.
Three of the four remaining financial extraction entries close.

**Negative.** This is the largest change in the project's history and knowingly
violates the small-and-reviewable rule that `.ai/feature-development` §3 sets
and that ADR 0007 upheld. Review quality depends entirely on the P1 → P3 phase
boundaries holding. It also modifies a live table read by six services, so the
regression surface is real and the fulfillment tests are load-bearing rather
than incidental.

**Deferred.** Stripe, the customer app, receivable reporting, and cost-of-goods
posting all become more clearly shaped by this work and none is delivered by it.
Revenue without matched cost is a real reporting limitation between this feature
and whichever one adds COGS.

**Reversal cost.** Low for Stripe and reporting, which are additive. High for
D2's `orders` extension and D3's derived Delivery Notes surface: both are load-
bearing schema and UI decisions that later features build on, and reversing
either would mean migrating live commercial data.
