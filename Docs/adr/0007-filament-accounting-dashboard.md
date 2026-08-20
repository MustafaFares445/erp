# ADR 0007: Adopt the Existing Filament Dashboard for the Accounting Foundation

**Status**: Accepted

**Date**: 2026-08-18

**Deciders**: Project Owner

**Related**: `specs/018-chart-of-accounts-journals/spec.md`, `Docs/PRD.md`, `Docs/SDD.md`, `Docs/database/ERD.md`, `Docs/IMPLEMENTATION_PLAN.md` §6, ADR 0001 (Inventory), ADR 0002 (CRM), ADR 0003 (Employees), ADR 0004 (Support), ADR 0006 (Purchasing), and the IERP Constitution Product Scope & Boundaries section

## Context

The constitution's Product Scope & Boundaries section permits a Filament
dashboard dependency only for the modules that have an ADR: Inventory (0001),
CRM (0002), Employees (0003), Support and Maintenance (0004), and Purchasing
(0006). Accounting has none. The `/admin` panel already reserves the
`accounting` navigation group with nine resource links and their English
labels (`app/Filament/AdminModuleRegistry.php`, `lang/en/admin.php:665-673`),
every one of which currently renders the shared `ModulePlaceholder` page
because not one of the classes exists.

Unlike every module approved so far, this one is **not** an owner-prioritised
addition to the extraction order. `006-chart-of-accounts-and-journals` is the
next unbuilt entry in the documented order, and it is the hard prerequisite
three later entries depend on:
`007-sales-flow-quotation-delivery-invoice`,
`008-payments-stripe-manual-tax-recognition`, and `009-credit-notes` all post
to the general ledger. Constitution §Specification Governance already says so
in the strongest terms available to it, in the passage added at 1.6.0 to keep
Purchasing out of the ledger:

> Adding any accounts-payable or general-ledger behaviour to the Purchasing
> module before `006-chart-of-accounts-and-journals` exists would skip a
> prerequisite and violate this section, regardless of how small the addition
> appears.

That sentence is only enforceable if the entry it defers to actually gets
built. This ADR authorises building it.

### What the documentation asks for

The documented requirement is unusually thin but unusually precise about the
one thing that matters. `Docs/SDD.md` describes Chart of Accounts as
"Maintain account hierarchy, account types, and posting targets" and Journal
Entries as "Record accounting postings produced manually or by invoices,
payments, credit notes, and tax recognition" — both with the generic
boilerplate acceptance criteria the SDD applies to every feature.
`Docs/IMPLEMENTATION_PLAN.md` §6 is the load-bearing source. It names four
tasks — COA CRUD, journal entry CRUD/confirmation, balance validation, and a
"posting service interface for invoices, payments, tax, credit notes" — and
exactly one acceptance criterion:

> Given a journal entry is confirmed, when debit does not equal credit, then
> confirmation fails.

The ERD supplies five tables and no ambiguity about their shape:
`account_types`, `chart_accounts`, `fiscal_periods`, `journal_entries`, and
`journal_entry_lines`. `journal_entries` carries a `source_type`/`source_id`
pair, which is the ERD's own expression of the posting-service interface: a
ledger entry knows which commercial document produced it.

### Why this is lower-risk than the modules already approved

Purchasing (ADR 0006) had to invent a `purchase_orders` entity the ERD did not
contain, and had to be fenced off from the stock-writing path Principle III
protects. This module needs neither. All five tables are already specified in
the ERD, and none of them touches stock: Principle III is not engaged at any
point, because no accounting record here changes a
`product_variant_id + warehouse_id` balance.

There is also nothing to reconcile against built code. Zero of the five tables
exist among the 102 migrations, no model, service, policy, or Filament class
references any of them, and no built code path writes a journal entry. The
three conflicts that make the sales-flow entry expensive — the built `orders`
table's fulfillment semantics, the `delivery_notes`-versus-`InventoryOperation`
question, and the accounting navigation items with no ERD tables — all live in
`007` and later. This slice is the one part of the financial module that can be
built without resolving any of them.

### The risk that is real

A general ledger's value is that it cannot quietly lie. Two failure modes
matter more than anything else in this module:

1. **An unbalanced entry reaching the ledger.** The one documented acceptance
   criterion exists because a posted entry whose debits and credits differ
   makes every downstream figure wrong and gives no signal it happened.
2. **A posted entry being edited or deleted after the fact.** Accounting
   history that can be rewritten is not an audit trail. Every module approved
   so far has needed an append-only invariant somewhere (`TaskStatusLog`,
   `VisitGpsLog`, `TicketAssignment`, `TicketMessage`, `ServiceRecordPart`);
   for a ledger it is the central property, not a detail.

Both are enforced in this decision at the service layer and again at the model
layer, following the defense-in-depth precedent `MaintenanceRecord` and
`MaintenanceTask` set in spec 016.

### Alternatives considered

**Defer accounting and build the sales flow first.** Rejected. It inverts the
documented prerequisite order, and invoices without a ledger would need either
a second ad-hoc financial record or a later backfill of every historical
invoice. The constitution forbids skipping prerequisites, and this is the
prerequisite.

**Build all four financial entries (006-009) as one feature.** Rejected. It is
roughly twenty-four tables and fifteen Filament resources in one change,
against the `.ai/feature-development` rule that changes stay small and
reviewable, and it would force the three unresolved sales-flow conflicts to be
decided under time pressure inside a much larger diff.

**Skip fiscal periods.** Rejected. Without them a closed accounting month stays
writable forever, and `journal_entries.fiscal_period_id` in the ERD has no
meaning. The close flag is the only mechanism in this slice that stops a
correction being backdated into a period whose numbers have already been
reported.

**Model corrections as edits to the offending entry.** Rejected in favour of
reversal, which is standard double-entry practice and the only correction
method compatible with the append-only invariant above.

## Decision

Adopt a **sixth narrow exception** to the Filament out-of-scope rule: the
existing `/admin` Filament panel is approved for the **Accounting foundation**
— dashboard-only administration of the general ledger's structural records and
manual postings.

### In scope

- **Account types** as seeded reference data: the five accounting elements
  (Asset, Liability, Equity, Income, Expense) with their normal balance. Fixed
  rows, seeded rather than user-created, with no Filament resource of their own
  — surfaced as a column and filter on Chart of Accounts. This follows the
  `SlaPolicySeeder` precedent for fixed reference rows, taken one step further:
  the five accounting elements are universal, so there is no legitimate reason
  for an admin to add a sixth or rename one.
- **Chart of accounts**: a hierarchy of accounts, each with a unique code, a
  name, an account type, an optional parent, an `is_postable` flag marking
  whether journal lines may target it, and an `is_active` flag.
  Soft-deletable and blameable. Guarded so an account that has children or any
  posted journal line cannot be turned into an invalid posting target and
  cannot be deleted out from under existing history.
- **Fiscal periods**: named date ranges that may be closed. A closed period
  refuses new postings and refuses to have existing ones altered.
- **Manual journal entries**: a `draft` → `posted` lifecycle with debit/credit
  lines against postable, active accounts. Drafts are freely editable; a
  posted entry is immutable at both the service and model layer, and the only
  way to correct one is a reversing entry that links back to it.
- **Balance validation on posting**, enforcing the one documented acceptance
  criterion: total debits must equal total credits, every line must carry
  exactly one of debit or credit, and an entry must have at least two lines.
- **A posting service** (`App\Services\Accounting\JournalPostingService`)
  exposing `post()` and `reverse()` against the ERD's `source_type`/`source_id`
  morph. This is the interface `Docs/IMPLEMENTATION_PLAN.md` §6 names for
  invoices, payments, tax recognition, and credit notes to call once those
  documents exist. This ADR authorises the interface and its manual caller
  only; it authorises **no** automatic posting, because none of those documents
  exist yet.
- **Per-account balances and ledger view**: a computed balance column on the
  Chart of Accounts list and, on a single account, the posted journal lines
  that hit it. This is a read surface on the resource, not a reporting module —
  without it the ledger cannot be verified from the dashboard at all.
- **Permissions and roles** for the above, following the module-scoped
  `accounting.*` permission catalogue pattern established by
  `InventoryPermission`, `CrmPermission`, `EmployeePermission`, and
  `SupportPermission`.
- **Audit logging** of postings, reversals, and period closes through
  `spatie/laravel-activitylog` per ADR 0005.

### Out of scope

This exception does **not** approve:

- any API surface, under `/api/dashboard` or any other prefix;
- accounts-receivable or accounts-payable subledgers, so the `Accounts
  Receivable` and `Accounts Payable` navigation items stay placeholders;
- supplier bills, expenses, refunds, or tax definitions — the `Bills`,
  `Expenses`, `Refunds`, and `Taxes` navigation items have **no ERD table at
  all** and stay placeholders;
- financial reports of any kind — no trial balance, profit and loss, balance
  sheet, or cash-flow statement page. `Financial Reports` stays a placeholder
  in both the `accounting` and `reports` groups, and belongs to
  `014-reporting-notifications-audit`;
- **automatic posting from any commercial document.** No invoice, payment,
  credit note, tax-recognition entry, purchase order, ticket payment, or
  inventory movement posts to the ledger as a result of this decision. Wiring
  any of them up belongs to that document's own feature;
- multi-currency, currency conversion, or revaluation;
- cost accounting, inventory valuation, or cost-of-goods-sold posting;
- budgets, budget-versus-actual comparison, or forecasting;
- bank accounts, bank feeds, or bank reconciliation;
- a year-end close that rolls income and expense into retained earnings;
- opening-balance import from an external accounting system;
- recurring or scheduled journal entries, and journal-entry approval workflow
  beyond the `draft` → `posted` transition;
- any change to the built `orders` table, the delivery flow, or the
  `InventoryOperation` path.

Each of those requires its own specification and either a separate ADR or an
explicit amendment to this one.

### ERD deviations authorised by this decision

Two, both narrow, and both recorded here because Principle I makes the ERD
canonical:

1. **`fiscal_periods` omits the generic `status` column.** The ERD gives
   `fiscal_periods` both a purposeful `is_closed` boolean and the same
   `status varchar(50) default 'draft/pending'` column its generator applied
   uniformly to `orders`, `delivery_notes`, `journal_entries`, and others.
   Carrying both would put a period's lifecycle in two places that can
   disagree. `is_closed` is kept as the single source of truth and the generic
   column is dropped for this table only.
2. **`journal_entry_lines` gains a `sort_order` column.** The ERD's line table
   has no ordering column, which means a posted entry's lines would render in
   insertion order and a reversal's lines would not visibly pair with the
   original's. This is presentational and additive only.

No other deviation is authorised. In particular `journal_entries` keeps the
generic `status` column — it is the entry's real `draft` → `posted` lifecycle,
not boilerplate — and neither `journal_entries` nor `journal_entry_lines` gains
a `deleted_at`, matching both the ERD and the append-only invariant above.

Notably, the reversal link needs **no** new column and is therefore not a third
deviation. A reversing entry records the entry it reverses through the ERD's
existing `source_type`/`source_id` morph, pointing at the original
`JournalEntry`. That is what the morph is for — a ledger entry naming the
document that produced it — and a reversal's producing document genuinely is the
original entry. Whether an entry has already been reversed is answered by
querying for a reversal pointing at it, so no `reverses_journal_entry_id` column
and no `is_reversed` flag is introduced.

### Decisions carried forward for the sales-flow entry

Three decisions were taken by the project owner on 2026-08-18 alongside this
one. They bind `007-sales-flow-quotation-delivery-invoice`, not this feature,
and are recorded here so the next spec does not reopen them:

- **The built `orders` table is extended, not replaced.** The ERD's commercial
  columns (`quotation_id`, `supplier_id`, `payment_status`, `pending_reason`,
  `grand_total`, `deleted_at`) are added to it as nullable, and
  `unit_price`/`line_total` are added to `order_lines`. One order document
  serves both the commercial and the fulfillment role. No `sales_orders` table
  is introduced. This keeps the live delivery flow and `OrderResource` intact
  and keeps ADR 0006's supplier-confirmation design valid, since it already
  treats `orders` as the customer document a confirmation attaches to. The line
  table keeps its built name `order_lines` rather than the ERD's `order_items`.
- **No `delivery_notes` table is created.** The Delivery Notes surface is
  derived from the existing `InventoryOperation` with
  `operation_type = 'delivery'`, which is already the single system of record
  that decrements stock and writes `inventory_movements`. A second table
  recording the same event would create two systems of record for one stock
  movement, which Principle III exists to prevent. This is an ERD deviation and
  requires its own authorisation in the `007` ADR.
- **`barryvdh/laravel-dompdf` is the approved PDF dependency** for invoice
  documents and the `invoice_files` table. Pure PHP with no external binary, so
  it works unchanged on the local Laragon environment and in CI. It is **not**
  installed by this feature — nothing in this slice generates a PDF.

## Consequences

**Positive.** The prerequisite three later features depend on now exists, and
the 1.6.0 prohibition on Purchasing touching the ledger becomes a temporary
fence rather than a permanent one. The `accounting` navigation group stops
being nine placeholders and starts being three working surfaces. The posting
interface is defined once, before four separate features need it, so none of
them invents its own. The balance and immutability invariants are established
while the ledger is empty, which is the only time they are cheap to enforce.

**Negative.** A sixth Filament exception further entrenches a dependency the
constitution originally scoped to one module; the pattern is now the rule
rather than the exception, and that is worth acknowledging rather than
disguising. Six of the nine `accounting` navigation items remain placeholders
after this feature ships, so the group still looks half-built. A ledger with no
automatic posting and no reports is of limited standalone use to an operator —
its value is almost entirely as a foundation, and overselling it would be
misleading.

**Neutral.** Fiscal periods, account types, and the chart of accounts must be
seeded before any later feature can post. The seeded chart is a starting point,
not a fixed structure; accounts are user-editable, and the standard codes
chosen are conventional rather than prescribed by any document in the canonical
set.

**Enforcement.** The invariants above are enforced by tests, not by this
document: balance validation and closed-period rejection as service tests,
posted-entry immutability at both the service and model layer, and an
architecture test keeping `App\Filament` from writing journal rows directly so
every posting goes through `JournalPostingService`. Per
`.ai/feature-development` rule 8, none of these may be weakened to make a build
pass.
