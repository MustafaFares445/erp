# Feature Specification: Sales Module Completion — Quotation → Delivery Note → Invoice → Payment, with Credit Notes

**Feature Directory**: `019-sales-lifecycle-payments-credits`

**Created**: 2026-08-23

**Status**: Draft

**Input**: Extract three consecutive entries of the documented extraction order as one slice — `007-sales-flow-quotation-delivery-invoice`, `008-payments-stripe-manual-tax-recognition` (manual channel only, see D9), and `009-credit-notes`. Canonical sources: `Docs/IMPLEMENTATION_PLAN.md` §8 (Sales Flow: quotation CRUD and accept/reject, delivery note conversion and confirmation, invoice creation from delivery note, invoice PDF/email jobs, invoice receipt confirmation with signature), §9 (Payments and Tax: payment methods CRUD, manual payment recording with proof, tax recognition service, payment allocation, journal posting) and §10 (Credit Notes: creation, cancel-only draft flow, cancel-and-new-invoice flow, journal reversal postings); `Docs/PRD.md` §5 feature rows and FR-003 through FR-013; `Docs/SDD.md` §Quotation Flow, §Delivery Note Flow, §Invoice Flow, §Credit Notes, §Manual Payments, §Tax Recognition; `Docs/database/ERD.md` (tables `payment_terms`, `quotations`, `quotation_items`, `orders`, `order_items`, `delivery_notes`, `delivery_note_items`, `invoices`, `invoice_items`, `invoice_files`, `invoice_confirmations`, `credit_notes`, `credit_note_items`, `payment_methods`, `payments`, `payment_allocations`, `manual_payment_records`, `tax_recognition_entries`) and §10 Status and Enum Catalog. The feature fills the five reserved-but-unbuilt `sales` sidebar slots in `App\Filament\AdminModuleRegistry::groups()` — `admin.resources.quotations`, `admin.resources.delivery_notes`, `admin.resources.invoices`, `admin.resources.payments`, `admin.resources.credit_notes`, whose translation keys already exist at `lang/en/admin.php:872-877` — completes the sixth (`admin.resources.orders`, today a list-and-create-only fulfillment surface), and makes real two `system` slots the sales flow depends on: `admin.resources.payment_terms` and `admin.resources.payment_methods`. The five decisions D5–D9 in §Owner Decisions were taken by the project owner on 2026-08-23 and are binding; this specification encodes them rather than reopening them. D2, D3 and D4, taken on 2026-08-18 and recorded in `specs/018-chart-of-accounts-journals/spec.md` §Owner Decisions as binding on this entry, are likewise settled inputs.

**Governance prerequisite**: This feature is **blocked** until ADR 0008 (`Docs/adr/0008-filament-sales-payments-dashboard.md`) is approved and the constitution amendment to 1.8.0 is merged. See §Governance Gate.

## Owner Decisions

D2, D3 and D4 were taken on 2026-08-18 and recorded in spec 018 as binding on this entry. D5–D9 were taken on 2026-08-23. None is an open question.

### Carried forward from spec 018

- **D2 — The built `orders` table is extended, not replaced.** No `sales_orders` table is introduced; `order_lines` keeps its built name rather than adopting the ERD's `order_items`.
- **D3 — No `delivery_notes` table is created.** The Delivery Notes surface derives from `InventoryOperation` rows with `operation_type = 'delivery'`, and from their `InventoryOperationLine` children.
- **D4 — `barryvdh/laravel-dompdf` is the approved PDF dependency.** It is installed by this feature. It was deliberately not installed by 018.

### Taken for this feature

- **D5 — One slice covering extraction entries 007, 008 and 009.** All six `sales` sidebar items become real in a single feature. This **supersedes** ADR 0007 §Alternatives considered, which rejected "Build all four financial entries as one feature" on reviewability grounds. The project owner accepts that cost. The mitigation is structural, not aspirational: the user stories below are ordered P1 → P3 and each is independently shippable, so the work lands as a sequence of reviewable increments inside one specification rather than one undifferentiated change. ADR 0008 must record this supersession explicitly, so the reversal reads as a decision rather than an oversight.
- **D6 — Invoice issuance, payment collection and credit-note confirmation post to the general ledger.** This **amends** ADR 0007's "automatic posting from any commercial document" exclusion, for exactly those three events and no others. `JournalPostingService`, built and left unwired by 018, gains its first three callers. Quotations, orders, delivery operations, purchase orders, inventory movements and ticket payments still post nothing.
- **D7 — No `tax_definitions` table.** Tax is a single configurable default rate on a `sales_settings` singleton, following the `InventorySetting` and `PurchaseSetting` precedent, with a per-line override. The reserved `system` → Tax Definitions navigation item stays a placeholder; it has no ERD table and gains none here.
- **D8 — A customer's accept or reject of a quotation is recorded by an admin or employee in the dashboard.** There is no public route, no signed link, and no customer portal. This follows the supplier-confirmation precedent in ADR 0006 §Decision and keeps extraction entry `010-customer-app-flows` wholly out of scope.
- **D9 — Stripe is deferred; the manual payment channel only.** No `stripe_payment_records` table, no Stripe client, no webhook, no public route. Constitution Principle III's prohibition on divergent code paths per payment channel is satisfied structurally: one posting service and one tax-recognition service handle every channel, so adding Stripe later adds a channel record, not a second posting path. An architecture test enforces this.

## Governance Gate

No implementation task may begin until all three hold:

1. **ADR 0008 approved.** `Docs/adr/0008-filament-sales-payments-dashboard.md` must exist and be moved to **Accepted** by the project owner. Without it, a Filament dashboard for the Sales and Payments module remains out of scope under constitution §Product Scope & Boundaries, and every Filament class in this feature would violate it. ADR 0008 must, at minimum: adopt a seventh narrow exception to the Filament out-of-scope rule; record D5's supersession of ADR 0007 §Alternatives considered; record D6's amendment of ADR 0007's no-automatic-posting exclusion, naming the three authorised posting events and no others; and restate that ADR 0006's accounts-payable prohibition on Purchasing survives untouched.
2. **Constitution amended to 1.8.0.** The amendment adds the seventh narrow Filament exception and records in §Specification Governance that this work delivers entries `007-sales-flow-quotation-delivery-invoice`, `008-payments-stripe-manual-tax-recognition` (manual channel only) and `009-credit-notes` as one owner-authorised slice, that their shared prerequisite `006-chart-of-accounts-and-journals` is built as spec 018, and that the Purchasing prohibition is unaffected.
3. **ERD deviations recorded.** Every row of §ERD Divergence Register must be written into `Docs/database/ERD.md` before implementation begins, following the precedent set by ADR 0006's `purchase_orders` extension.

This feature depends on `018-chart-of-accounts-journals` (built) for the ledger and posting service, and on `005-products-variants-warehouses-inventory` (built) for variants, warehouses, pricing and the inventory operation path. It does **not** depend on `017-purchasing-orders-suppliers`, but it must not regress it: the pending-supplier-confirmation path an order already uses stays intact (FR-031).

## ERD Divergence Register

| # | Table | Divergence | Authority |
|---|---|---|---|
| E-1 | `orders` | Extended in place per D2 rather than rebuilt. Columns **added**: `quotation_id`, `payment_term_id`, `subtotal`, `tax_total`, `grand_total`, `payment_status`. Column **omitted**: `supplier_id` — the built code records a supplier's answer through the `SupplierConfirmation` `confirmable` morph (spec 017 FR-028), so a scalar FK would be a second, disagreeing source for the same fact. | ADR 0008 |
| E-2 | `order_items` | **Not created.** The built `order_lines` keeps its name per D2, and gains `unit_price`, `tax_amount`, `line_total` as nullable columns. Nullable, not `default 0`: rows created before this feature carry no price, and recording that as zero would assert the goods were free. | ADR 0008 (D2) |
| E-3 | `delivery_notes`, `delivery_note_items` | **Not created** per D3. The surface derives from `InventoryOperation` where `operation_type = 'delivery'` and its `InventoryOperationLine` children. A parallel table would be a second delivery record able to disagree with the one that actually moves stock. | ADR 0007 (D3) |
| E-4 | `invoice_files` | **Not created.** Constitution Principle IV requires generated PDFs to use Spatie Media Library and prohibits custom per-feature file tables absent a proven need. Invoice and credit-note PDFs are media on their document. | Constitution IV |
| E-5 | `stripe_payment_records` | **Not created** per D9. | ADR 0008 (D9) |
| E-6 | `tax_definitions` | **Not created**, and never in the ERD. Replaced by a `sales_settings` singleton per D7. | ADR 0008 (D7) |
| E-7 | `sales_settings` | **Added.** Singleton holding `default_tax_percent`, `default_quotation_validity_days`, and four posting-account references (`receivable`, `revenue`, `deferred_tax`, `tax_payable`). Follows the `inventory_settings` / `purchase_settings` precedent. Posting accounts live in configuration rather than hardcoded account codes so the chart of accounts stays the accountant's to own. | ADR 0008 |
| E-8 | `payment_methods` | Per ERD, plus an added `chart_account_id` naming the postable account a collection through this method debits, and a `requires_proof` flag. Without the account reference, a cash receipt and a bank transfer would be indistinguishable to the ledger. | ADR 0008 |
| E-9 | `quotation_items`, `invoice_items`, `credit_note_items` | Each gains `sort_order unsigned integer default 0`, so lines render in a stable author-chosen order and a credit note's lines visibly pair with the invoice's. Presentational and additive, matching spec 018 divergence E-2. | ADR 0008 |
| E-10 | `invoice_confirmations` | Per ERD, except `signature_path` is **omitted** — the signature is a Media Library attachment on the confirmation, per Principle IV and E-4's reasoning. | Constitution IV |
| E-11 | `payments` | `invoice_id` is **omitted**, though the ERD carries it directly alongside the separate `payment_allocations` table. FR-054 requires allocation across one or more invoices; keeping a scalar `invoice_id` next to the allocation table would be the same two-disagreeing-sources shape E-1 rejected for `orders.supplier_id`. | ADR 0008 |

**Not divergences**, recorded here because each looks like one:

- `payment_methods.type` and `.is_online` are **kept** from the ERD, not dropped. Only the manual four `type` values are reachable while D9 holds, and the Filament resource refuses to create or activate a `stripe` or online method — enforced by the resource, not the schema, so no migration is needed when a later feature lifts the restriction.

- `quotations`, `invoices`, `credit_notes`, `payments` and `orders` all **keep** their `status` column. For these tables it is a real lifecycle, not the ERD generator's `draft/pending` boilerplate.
- `tax_recognition_entries` and `invoice_confirmations` gain **no** `deleted_at`. Both are append-only evidence; this matches the ERD and is required by FR-049 and FR-062.
- `invoices`, `credit_notes` and `payments` **keep** `deleted_at`, used only for drafts. An issued invoice, a posted payment and a confirmed credit note are never deleted by any path, soft or hard (FR-043, FR-060, FR-065).
- The chart-of-accounts seeder gains one account, `2350 Deferred Sales Tax`. This is additive reference data, not a schema change. It is required by Principle III: if invoice issuance credited `2300 Sales Tax Payable` directly, tax would be recognised at issuance, which the constitution forbids.
- A reversal adds **no** column anywhere. Every reversal link rides the `source_type`/`source_id` morph 018 already built.

## Scope

**In scope.** Payment terms with due-date derivation; a sales settings singleton carrying the default tax rate and the four posting accounts; quotations with tier-resolved priced lines, a price-floor guard, and an admin-recorded accept/reject; conversion of an accepted quotation into a priced order; a Delivery Notes surface derived from existing delivery operations; invoices created from a completed delivery or entered directly, with payment-term due dates, queued PDF generation, queued email, and append-only receipt confirmation with signature; ledger posting on invoice issuance; payment methods; manual payments with proof, multi-invoice allocation, ledger posting, and proportional tax recognition on collection; credit notes with reversal posting and no destructive deletion; a `sales.*` permission catalogue with three fixed dashboard roles; audit logging of every state-changing sales event; and English labels for all of it.

**Out of scope.** Everything ADR 0008 excludes, most importantly: **any API surface**, dashboard-facing or public; the customer app and the employee app (entries `010` and the `-app-` half of `011`); **Stripe, its webhook, and any online payment channel** (D9); accounts-receivable and accounts-payable **subledger pages** — the `Accounts Receivable` and `Accounts Payable` navigation items stay placeholders even though this feature creates the receivable balances they would report on; supplier bills, expenses, refunds, and tax definitions, which have no ERD table and stay placeholders; **financial reports of any kind**, including an aged-receivables report, a sales report, and a tax report — `Financial Reports` stays a placeholder in both the `accounting` and `reports` groups and belongs to `014-reporting-notifications-audit`; document templates and the Settings page; cost-of-goods-sold posting and inventory valuation, so a delivery still posts nothing to the ledger; multi-currency, conversion, and revaluation; recurring billing, subscriptions, and renewals; dunning and automated reminder schedules beyond deriving an `overdue` status; customer credit limits and credit holds; sales commission calculation, which belongs to the Employees module's existing performance path; goods-return inventory movements arising from a credit note; debit notes; and wiring `TicketPaymentLink` to the Payments module.

Two exclusions deserve emphasis because each is the most tempting thing to add quietly and the most damaging:

**Nothing outside the three events named in D6 may post to the ledger.** No observer, no event listener, no service call from the Inventory, Purchasing, Support, CRM, or Employees modules gains a posting path. ADR 0006's prohibition on any accounts-payable or general-ledger behaviour in Purchasing survives this feature untouched, and a ledger that now has callers is not permission for a fourth (FR-080, FR-081).

**No sales document writes stock.** Delivery is the only stock-affecting step in the lifecycle, and it happens exclusively through the existing `InventoryOperationService`. This feature adds no second stock-writing path, which is what constitution Principle III exists to prevent (FR-020, FR-034, FR-070).

### Sales Module Resource Inventory

The module Dashboard remains the shared dashboard page and is not a sales-document resource. Every other visible item in the Sales navigation is covered by this feature as follows:

| Navigation item | Required surface | Scope boundary |
|---|---|---|
| Quotations | Full quotation lifecycle, including send, recorded customer decision, and conversion to an order. | No inventory or ledger write. |
| Orders | Existing fulfillment surface extended with commercial pricing, source quotation, and view/edit pages. | Existing warehouse allocation and delivery behavior remains unchanged. |
| Delivery Notes | Read and act on delivery inventory operations and their lines. | No delivery-note model or table; stock actions remain owned by Inventory. |
| Invoices | Draft, issue, send, document generation, and receipt-confirmation surface. | Issuance is one of the three authorized ledger-posting events. |
| Payments | Manual collection, proof, allocation, posting, reversal, and tax-recognition surface. | Stripe and every online channel remain out of scope. |
| Credit Notes | Draft, confirm, reverse, and document-generation surface. | No stock return or destructive invoice correction. |
| Sales Settings | Singleton for default tax, quotation validity, and sales posting accounts. | It is a Sales navigation item, not a System placeholder. |
| Payment Terms | System configuration for invoice due-date and grace-period rules. | A referenced term cannot be deleted. |
| Payment Methods | System configuration for manual collection methods and their posting accounts. | A referenced method cannot be deleted; online methods remain unavailable. |

## User Scenarios & Testing

### User Story 1 — Enforce Sales Roles and Permissions (Priority: P1)

A System Admin grants one colleague the Sales Officer role and another the Billing Officer role. The Sales Officer can draft and send quotations but cannot issue an invoice or post a payment. The Billing Officer can issue invoices and record payments but cannot confirm a credit note.

**Why this priority**: Every other story depends on the permission catalogue existing. This feature creates the first records in the system that represent money owed and money collected, and separating who may commit each is the control that makes those records trustworthy.

**Independent Test**: Seed the catalogue, assign each role to a user, and assert every page and every service entry point either loads or refuses per the matrix — no sales document needs to exist.

**Acceptance scenarios**:

1. Given the sales permission seeder has run, when a System Admin opens Quotations, Orders, Delivery Notes, Invoices, Payments, Credit Notes, Sales Settings, Payment Terms and Payment Methods, then all nine load.
2. Given a user holds only the Sales Officer role, when they open an invoice, then no Issue action is offered and calling the issuing service directly is refused.
3. Given a user holds only the Billing Officer role, when they open a draft credit note, then no Confirm action is offered and calling the confirmation service directly is refused.
4. Given a user holds only the Reviewer role, when they open any sales page, then it loads read-only with no create, edit, send, issue, post, confirm, or reverse action available.
5. Given a user holds no sales permission, when they open any sales page, then access is refused and the sidebar omits the link.
6. Given a System Admin is also granted the Sales Officer role, when they act anywhere in Inventory, CRM, Employees, Support, Accounting or Purchasing, then their admin bypass is gone and every check is explicit.

---

### User Story 2 — Configure Payment Terms and Sales Settings (Priority: P1)

An accountant defines a "Net 30" payment term with a 5-day grace period and marks it default, then sets the default tax rate to 5% and names the four accounts the sales flow posts to.

**Why this priority**: An invoice cannot compute a due date without a payment term, and cannot post without knowing which accounts to hit. This is the configuration every later story reads.

**Independent Test**: Configure both surfaces and assert derivation and validation, with no quotation or invoice in existence.

**Acceptance scenarios**:

1. Given no payment terms exist, when the accountant creates "Net 30" with 30 due days and marks it default, then it is listed as the default term.
2. Given "Net 30" is default, when the accountant marks a second term default, then the first stops being default and exactly one default remains.
3. Given a payment term with 30 due days, when an invoice dated 2026-09-01 uses it, then the due date is 2026-10-01.
4. Given a payment term is referenced by an invoice, when the accountant deletes it, then deletion is refused with a stated reason.
5. Given the accountant selects a non-postable or inactive account as the revenue posting account, then the selection is refused.
6. Given the default tax rate is set to 5%, when a quotation line of quantity 2 at 100.00 is entered, then its tax is 10.00 and its line total is 210.00.
7. Given the accountant enters a tax rate of 101 or -1, then it is refused.

---

### User Story 3 — Quote a Customer and Record Their Answer (Priority: P1)

A sales officer builds a quotation for a customer. Prices default from the customer's pricing tier. They discount one line, send the quotation, and three days later record that the customer accepted it by phone.

**Why this priority**: The quotation is the entry point of the whole lifecycle and the only step that reaches the customer before anything is committed. It is also the only place customer-specific pricing is applied, so getting it wrong mis-prices everything downstream.

**Independent Test**: Create, price, send and decide a quotation end to end; assert no stock quantity anywhere changed and no journal entry exists.

**Acceptance scenarios**:

1. Given a customer on a pricing tier, when the officer adds a variant to a quotation, then the line's unit price defaults to that customer's resolved tier price and the source of the price is shown.
2. Given a variant with a price floor, when the officer overrides the unit price below the floor, then the change is refused and the floor is stated.
3. Given a draft quotation with lines, when a line quantity changes, then subtotal, tax total and grand total are recomputed and stored.
4. Given a draft quotation, when the officer sends it, then its status becomes sent and its customer, lines, quantities, prices and totals become immutable.
5. Given a sent quotation, when the officer records the customer's acceptance with a date and note, then its status becomes accepted and the recording user, date and note are stored.
6. Given a quotation whose expiry date has passed, when the officer tries to record an acceptance, then it is refused and the quotation shows as expired.
7. Given a draft quotation, when the officer tries to record a decision, then it is refused — only a sent quotation can be decided.
8. Given any quotation in any state, then no stock reservation, movement or on-hand quantity exists anywhere as a result of it.
9. Given an approved sales opportunity from the Employees module, when the officer creates a quotation from it, then customer and note are carried across and the opportunity records the resulting quotation.

---

### User Story 4 — Turn an Accepted Quotation into a Priced Order (Priority: P2)

The sales officer converts the accepted quotation into an order. The order carries the quotation's prices verbatim and enters the delivery-scheduling flow already built.

**Why this priority**: This is the join between the new sales lifecycle and the fulfillment machinery already in production. It is the highest-regression-risk step in the feature.

**Independent Test**: Convert a quotation and assert the resulting order's totals equal the quotation's, then run an existing fulfillment scenario against it unchanged.

**Acceptance scenarios**:

1. Given an accepted quotation, when the officer converts it, then an order is created whose lines, unit prices, taxes and totals equal the quotation's exactly, and the quotation's status becomes converted_to_delivery.
2. Given a quotation already converted, when the officer converts it again, then it is refused and no second order exists.
3. Given a quotation that was rejected, expired or is still draft, when the officer converts it, then it is refused.
4. Given an order created from a quotation, when it enters the existing fulfillment flow, then warehouse allocation, shipments and delivery operations behave exactly as they do for an order created directly.
5. Given an order created before this feature, when it is opened, then it displays with no prices and no payment status regression, and remains fully usable in the fulfillment flow.
6. Given an order whose stock is unavailable, when it is marked pending supplier confirmation, then the existing supplier-confirmation path is used unchanged and no invoice may be issued against it.
7. Given any order in any state, then no journal entry exists as a result of it.

---

### User Story 5 — Work Delivery Notes Off the Existing Operations (Priority: P2)

A warehouse supervisor opens Delivery Notes, sees the outbound deliveries awaiting dispatch, and completes one. Stock decreases. No tax is recognised and nothing posts to the ledger.

**Why this priority**: It is the lifecycle's only stock-affecting step and the constitution's most explicit invariant. It must be demonstrably the same code path Inventory already uses.

**Independent Test**: Complete a delivery from this surface and assert the stock decrement, the inventory movement, the absence of any journal entry, and that the movement is indistinguishable from one raised inside the Inventory module.

**Acceptance scenarios**:

1. Given delivery operations exist, when the supervisor opens Delivery Notes, then only operations of type delivery are listed, with their originating commercial document, customer, warehouse, stage and lines.
2. Given a delivery operation ready for dispatch, when the supervisor completes it from this surface, then stock decreases for each variant and warehouse and one inventory movement exists per line.
3. Given a delivery operation is completed, then no journal entry and no tax recognition entry exists as a result.
4. Given a delivery operation not in a completed stage, when the user tries to invoice it, then it is refused.
5. Given a delivery operation already invoiced, when the user tries to invoice it again, then it is refused.
6. Given a receipt or internal-transfer operation, then it never appears on the Delivery Notes surface.

---

### User Story 6 — Issue an Invoice, Send It, and Capture Receipt (Priority: P2)

The billing officer creates an invoice from the completed delivery. Lines and prices carry across from the order. They issue it, a PDF is generated and emailed, and later they record that the customer signed for it.

**Why this priority**: The invoice is the financial claim. Everything about collection depends on it existing accurately and being immutable once issued.

**Independent Test**: Invoice a completed delivery, issue it, generate the PDF, email it, and record a signed confirmation — assert immutability at each step.

**Acceptance scenarios**:

1. Given a completed delivery operation, when the officer creates an invoice from it, then lines reflect the delivered quantities and prices carry from the originating order line.
2. Given a delivered variant whose originating line had no price, when the invoice is created, then that line requires a manual price before the invoice can be issued.
3. Given a customer and no delivery, when the officer creates an invoice with manual service lines, then it is accepted and touches no stock.
4. Given a draft invoice and a default payment term, when it is created dated 2026-09-01 with Net 30, then the due date is 2026-10-01 and may be overridden to any date on or after 2026-09-01.
5. Given a draft invoice, when the officer edits lines, quantities or prices, then it is accepted and totals recompute.
6. Given an issued invoice, when anyone edits its customer, lines, quantities, prices or totals, then it is refused.
7. Given an issued invoice, when anyone deletes it by any path, then it is refused; a draft invoice may be deleted.
8. Given an issued invoice, when the PDF job runs, then a PDF is attached to the invoice as media and regenerating it replaces the current file while keeping the prior one.
9. Given an issued invoice, when the email job fails, then the invoice's status is unchanged and the failure is visible.
10. Given an issued invoice, when the officer records a customer receipt with a signature image, then a confirmation is stored with confirming user, type, timestamp, note and signature, and it cannot afterwards be edited or deleted.
11. Given an unpaid issued invoice past its due date plus grace period, then it presents as overdue.

---

### User Story 7 — Invoice Issuance Reaches the Ledger (Priority: P2)

Issuing the invoice posts one balanced journal entry: receivable debited for the full claim, revenue credited net of tax, and the tax parked as deferred rather than recognised.

**Why this priority**: This is D6's amendment made real, and the point at which the Sales module and the general ledger become one system. It is also the step where getting tax timing wrong would violate a non-negotiable principle.

**Independent Test**: Issue one invoice and assert the resulting entry's shape, balance, source link, and that `2300 Sales Tax Payable` is untouched.

**Acceptance scenarios**:

1. Given a draft invoice with subtotal 1000.00 and tax 50.00, when it is issued, then one posted journal entry exists debiting the receivable account 1050.00, crediting the revenue account 1000.00 and crediting the deferred-tax account 50.00.
2. Given that entry, then `2300 Sales Tax Payable` carries no movement from it.
3. Given that entry, then it links back to the invoice through the ledger's existing source reference, and opening the invoice shows the entry.
4. Given the fiscal period covering the invoice date is closed, when the officer issues the invoice, then issuing is refused, the invoice stays draft, and no entry exists.
5. Given the posting fails for any reason, then the invoice remains draft — issuing and posting succeed or fail together.
6. Given an issued invoice, then its posted entry is immutable and correctable only by reversal, exactly as any other posted entry.
7. Given the sales settings name no revenue account, when the officer issues an invoice, then it is refused with a message naming the missing configuration.

---

### User Story 8 — Collect Payment and Recognise Tax Proportionally (Priority: P3)

The billing officer records a 525.00 bank transfer against the 1050.00 invoice, attaching the transfer receipt. Half the invoice is settled, and exactly half its tax becomes recognised.

**Why this priority**: Tax recognition on collection is the single most business-specific rule in the system and the one the PRD calls out most insistently. It depends on invoices existing, so it follows them.

**Independent Test**: Record one partial and then one settling payment against a single invoice; assert balances, statuses, both journal entries and the tax recognised at each step.

**Acceptance scenarios**:

1. Given a payment method configured against the bank account, when the officer records 525.00 allocated to the 1050.00 invoice, then the invoice's paid amount is 525.00 and its status is partially_paid.
2. Given that payment, then one posted journal entry debits the bank account 525.00 and credits the receivable account 525.00.
3. Given that payment, then a tax recognition entry of 25.00 exists and one posted entry debits deferred tax 25.00 and credits sales tax payable 25.00.
4. Given the remaining 525.00 is then collected, then total recognised tax is exactly 50.00 with no rounding residue, and the invoice's status is paid.
5. Given an invoice with a tax total that does not divide evenly, when it is settled across three payments, then the sum of recognised tax equals the invoice tax total exactly and the final payment absorbs the rounding difference.
6. Given a payment method that requires proof, when the officer records a payment with no proof file, then it is refused.
7. Given a payment of 1000.00 allocated 600.00 and 300.00 across two invoices, then the 100.00 remainder posts to customer deposits and both invoices' balances reflect their allocation.
8. Given an allocation larger than an invoice's outstanding balance, then it is refused.
9. Given allocations summing to more than the payment amount, then they are refused.
10. Given a posted payment, when anyone edits or deletes it, then it is refused; reversing it reverses its journal entries, reverses its tax recognition, and restores every affected invoice's balance and status.
11. Given the codebase, then exactly one service posts a payment and exactly one recognises tax, with no branch on payment channel anywhere in either.

---

### User Story 9 — Correct an Invoice with a Credit Note (Priority: P3)

The customer was billed for two units they never received. The accountant raises a credit note against those lines and confirms it. The invoice is corrected, the ledger is reversed proportionally, and nothing is deleted.

**Why this priority**: It is the only correction path for an issued invoice, so it completes the lifecycle. It depends on both invoices and payments, since the reversal split depends on how much tax was already recognised.

**Independent Test**: Credit a partially paid invoice and assert the invoice's remaining balance, its status, and that the reversal split deferred versus recognised tax correctly.

**Acceptance scenarios**:

1. Given an issued invoice, when the accountant drafts a credit note against two of its lines with a stated reason, then a draft credit note exists with those lines and its own totals.
2. Given a credit note line quantity exceeding the invoice line's uncredited remainder, then it is refused.
3. Given credits already issued against an invoice, when a further credit would exceed its grand total, then it is refused.
4. Given a draft credit note, when it is edited, then it is accepted; when it is confirmed, then it becomes immutable.
5. Given a credit note confirmed against an invoice with no tax yet recognised, then the reversal debits revenue and deferred tax and credits the receivable.
6. Given a credit note confirmed against an invoice half of whose tax is recognised, then the tax reversal splits between deferred tax and sales tax payable in that same proportion, and the entry balances.
7. Given a credit note covering an invoice's full remaining value, when it is confirmed, then the invoice's status becomes credited.
8. Given a partial credit note, when it is confirmed, then the invoice's status continues to follow its remaining outstanding balance.
9. Given a confirmed credit note, when anyone edits or deletes it, then it is refused; a draft may be deleted.
10. Given a confirmed credit note, then its PDF is generated by the same queued path as an invoice's.
11. Given a confirmed credit note, then no stock quantity anywhere changed as a result of it.

---

### Edge Cases

- A quotation is sent, then the customer's pricing tier changes. The quotation keeps the prices it was sent with; it is immutable (FR-023).
- A quotation's price floor changes after it was sent. The sent quotation is unaffected; the floor guard applies only while drafting (FR-016).
- A quotation expires between being sent and being decided. Acceptance is refused (FR-022).
- A delivery is completed for less than the order's quantity. The invoice reflects delivered quantities, not ordered ones (FR-037).
- An order is partially delivered across two operations. Each completed operation may be invoiced once, producing two invoices (FR-036).
- An invoice's fiscal period is open at drafting and closed by the time it is issued. Issuing is refused; the invoice stays draft (US7 scenario 4).
- A payment is dated inside a closed fiscal period. Posting is refused and the payment is not recorded.
- A payment settles an invoice to the cent, but proportional tax rounds to one cent less than the invoice's tax total. The settling allocation absorbs the residue (FR-058).
- A credit note is raised against an invoice that is already fully paid. The reversal reduces the receivable below zero, leaving a customer credit balance; this is recorded, not blocked.
- Two users issue the same draft invoice simultaneously. Exactly one posting occurs; the other is refused.
- Two users allocate payments against the same invoice simultaneously such that the total would exceed the balance. One is refused.
- The PDF job fails after the invoice is issued. The invoice stays issued and posted; the PDF is regenerable (FR-047).
- The sales settings posting accounts are later made non-postable or inactive. Existing entries are unaffected; the next posting is refused with a message naming the account (FR-007).
- A payment method's collection account is changed after payments were posted through it. Historical entries keep the account they posted to.
- An order created before this feature is invoiced. Its lines have no price, so each line requires a manual price before issuing (US6 scenario 2).

## Requirements

### Functional Requirements

**Navigation and registry**

- **FR-001**: All seven `sales` navigation items — Quotations, Orders, Delivery Notes, Invoices, Payments, Credit Notes, and Sales Settings — MUST resolve to real Filament resources; no item in the `sales` group may render `ModulePlaceholder`.
- **FR-002**: The `system` group MUST gain real resources for Payment Terms and Payment Methods.
- **FR-003**: `Tax Definitions`, `Document Templates`, `Settings`, `Accounts Receivable`, `Accounts Payable`, `Bills`, `Expenses`, `Refunds`, `Taxes` and `Financial Reports` MUST remain placeholders.
- **FR-004**: Every new sales resource MUST declare the `admin.groups.sales` navigation group and a navigation sort inside the group's reserved 100–199 range; payment terms and payment methods MUST use the `system` group's reserved range.

**Configuration**

- **FR-005**: The system MUST provide a single sales settings record holding a default tax percent, a default quotation validity in days, and references to the receivable, revenue, deferred-tax and tax-payable posting accounts.
- **FR-006**: The default tax percent MUST be between 0 and 100 inclusive with at most two decimal places.
- **FR-007**: A posting account reference MUST name an account that is both postable and active; selecting any other account MUST be refused, and a posting whose configured account has since become non-postable or inactive MUST be refused with a message naming that account.
- **FR-008**: Users MUST be able to manage payment terms with a name, due days, grace days, an optional discount percent, and a default flag.
- **FR-009**: At most one payment term may be default at any time; marking a second default MUST clear the first.
- **FR-010**: An invoice's due date MUST default to its invoice date plus its payment term's due days, MUST be overridable, and MUST NOT precede the invoice date.
- **FR-011**: An issued invoice that is not fully paid MUST present as overdue once the current date passes its due date plus its payment term's grace days.
- **FR-012**: A payment term or payment method referenced by any document MUST NOT be deletable.

**Quotations**

- **FR-013**: Users MUST be able to create a quotation for a customer with an optional owning employee, a payment term, an issue date, an expiry date, and priced lines.
- **FR-014**: Every quotation MUST receive a unique document number, allocated so two concurrent creations cannot receive the same number.
- **FR-015**: A quotation line's unit price MUST default to the price resolved for that customer and variant by the existing pricing-tier resolution, and the resolved source MUST be shown to the author.
- **FR-016**: A unit-price override below the variant's effective price floor MUST be refused while the quotation is a draft, stating the floor.
- **FR-017**: A line's tax MUST default to the configured default tax percent applied to quantity times unit price, rounded to two decimal places, and MUST be overridable per line.
- **FR-018**: A quotation MUST store its subtotal, tax total and grand total, recomputed whenever a line changes while it is a draft.
- **FR-019**: A quotation MUST follow `draft → sent → accepted | rejected | expired`, with `accepted → converted_to_delivery`, and any non-terminal state to `cancelled`.
- **FR-020**: A quotation MUST NOT create, reserve, or change any stock quantity or inventory movement, in any state.
- **FR-021**: Only a `sent` quotation may be decided. Recording a decision MUST capture the deciding outcome, the decision date, an optional note, and the user who recorded it.
- **FR-022**: A quotation whose expiry date has passed MUST NOT be accepted and MUST present as expired.
- **FR-023**: A quotation that has been sent MUST be immutable in its customer, lines, quantities, unit prices, taxes and totals.
- **FR-024**: An accepted quotation MUST convert into at most one order; a second conversion MUST be refused.
- **FR-025**: A quotation MUST be creatable from an approved sales opportunity, carrying the customer and note across, and the opportunity MUST record the quotation that resulted.

**Orders**

- **FR-026**: The built `orders` table MUST be extended in place with a source quotation reference, a payment term reference, stored subtotal, tax total and grand total, and a payment status. No existing order row may lose data and no existing order behaviour may change.
- **FR-027**: The built `order_lines` table MUST gain a nullable unit price, tax amount and line total. Lines created before this feature MUST remain priceless rather than being assigned a fabricated price.
- **FR-028**: The Orders resource MUST gain view and edit surfaces in addition to its existing list and create surfaces.
- **FR-029**: Converting an accepted quotation MUST copy its lines, unit prices, taxes and totals to the order verbatim, so the order's totals equal the quotation's.
- **FR-030**: An order created from a quotation MUST enter the existing fulfillment flow — warehouse allocation, shipments and delivery operations — with no behavioural difference from an order created directly.
- **FR-031**: The existing pending-supplier-confirmation path MUST be preserved unchanged, and no invoice may be issued against an order awaiting a supplier's answer.
- **FR-032**: An order MUST NOT post to the general ledger.

**Delivery notes**

- **FR-033**: The Delivery Notes surface MUST list existing delivery operations and their lines, showing the originating commercial document, customer, warehouse and stage. It MUST create no new delivery record of its own.
- **FR-034**: Any stock change reachable from this surface MUST go through the existing inventory operation service, producing the same inventory movements as the Inventory module does. This feature MUST add no second stock-writing path.
- **FR-035**: A delivery MUST NOT recognise tax and MUST NOT post any journal entry.
- **FR-036**: Only a delivery operation in a completed stage may be invoiced, and each may be invoiced at most once.
- **FR-037**: Invoice lines derived from a delivery MUST reflect the delivered quantities, not the ordered quantities.

**Invoices**

- **FR-038**: Users MUST be able to create an invoice either from a completed delivery operation or directly for a customer with manually entered lines.
- **FR-039**: Every invoice MUST receive a unique document number under the same concurrency guarantee as quotations.
- **FR-040**: An invoice created from a delivery MUST carry each line's price from its originating order line; a line whose origin has no price MUST require a manual price before the invoice can be issued.
- **FR-041**: An invoice MUST follow `draft → issued → sent → customer_received | employee_confirmed_received`, with `partially_paid`, `paid`, `overdue`, `cancelled` and `credited` reachable as its balance and corrections dictate.
- **FR-042**: A draft invoice MUST be freely editable. Issuing MUST freeze its customer, lines, quantities, unit prices, taxes and totals.
- **FR-043**: An issued invoice MUST NOT be deletable by any path, soft or hard. A draft invoice MAY be deleted.
- **FR-044**: Issuing an invoice MUST, in a single database transaction, post one balanced journal entry debiting the receivable account for the grand total, crediting the revenue account for the subtotal, and crediting the deferred-tax account for the tax total. If the posting fails for any reason, including a closed fiscal period, the invoice MUST remain a draft.
- **FR-045**: Issuing an invoice MUST NOT credit the tax-payable account. Tax becomes payable only on collection.
- **FR-046**: The posted entry MUST link back to the invoice through the ledger's existing source reference, and the invoice MUST surface its entry.
- **FR-047**: Invoice PDF generation MUST run as a queued job and attach the PDF to the invoice as media. Regeneration MUST replace the current file while retaining the previous one.
- **FR-048**: Invoice email delivery MUST run as a queued job. Its failure MUST NOT change the invoice's status, and MUST be visible to an operator.
- **FR-049**: A receipt confirmation MUST record the confirming user, the confirmation type, a timestamp, an optional note and an optional signature image, and MUST be append-only — never edited, never deleted.
- **FR-050**: An invoice's paid amount MUST never exceed its grand total less amounts already credited.

**Payments**

- **FR-051**: Users MUST be able to manage payment methods, each with a name, an active flag, a proof-required flag, and the postable account a collection through it debits.
- **FR-052**: Users MUST be able to record a payment with a unique number, a customer, a method, an amount, a payment date, an optional external reference, and notes.
- **FR-053**: A payment through a method that requires proof MUST NOT be recorded without a proof file attached.
- **FR-054**: A payment MUST be allocatable across one or more invoices. The sum of its allocations MUST NOT exceed the payment amount, and no single allocation may exceed its invoice's outstanding balance.
- **FR-055**: Any unallocated remainder of a payment MUST post to the customer-deposits account rather than being discarded.
- **FR-056**: Recording a payment MUST, in a single database transaction, post one balanced journal entry debiting the method's collection account for the payment amount, crediting the receivable account for the total allocated, and crediting customer deposits for any remainder.
- **FR-057**: For each allocation, the system MUST recognise tax proportionally — the allocated amount divided by the invoice's grand total, times the invoice's tax total, rounded to two decimal places — recording a tax recognition entry and posting a debit to deferred tax and a credit to tax payable.
- **FR-058**: The allocation that settles an invoice MUST recognise the exact residual tax, so total recognised tax equals the invoice's tax total with no rounding drift.
- **FR-059**: An invoice's status MUST follow its allocations: partially paid while a balance remains, paid when it reaches zero.
- **FR-060**: A posted payment MUST be immutable and MUST NOT be deletable. Reversing it MUST reverse its journal entries, reverse its tax recognition, and restore every affected invoice's paid amount and status.
- **FR-061**: Exactly one service MUST post payments and exactly one MUST recognise tax, and neither may branch on payment channel. This MUST be enforced by an architecture test, so adding a future channel cannot introduce a parallel path.
- **FR-062**: Tax recognition entries MUST be append-only.

**Credit notes**

- **FR-063**: Users MUST be able to create a credit note against an invoice or standalone, with a required reason, an issue date, lines and stored totals, following `draft → confirmed | cancelled`.
- **FR-064**: A credit note line referencing an invoice line MUST NOT exceed that line's uncredited remainder, and the total credited against an invoice MUST NOT exceed its grand total less amounts already credited.
- **FR-065**: A draft credit note MUST be editable and deletable. Confirming MUST freeze it, and a confirmed credit note MUST NOT be edited or deleted by any path.
- **FR-066**: Confirming a credit note MUST, in a single database transaction, post one balanced journal entry debiting revenue for its subtotal, crediting the receivable for its grand total, and debiting its tax across the deferred-tax and tax-payable accounts in the same proportion as that invoice's tax is currently recognised.
- **FR-067**: A credit note covering an invoice's full remaining value MUST set that invoice's status to credited; a partial credit note MUST leave the invoice's status following its remaining balance.
- **FR-068**: A confirmed credit note MUST be correctable only by reversal, under a permission distinct from confirming it.
- **FR-069**: Credit note PDF generation MUST use the same queued path as invoices.
- **FR-070**: A credit note MUST NOT change any stock quantity. Returned goods are a separate inventory concern, out of scope here.

**Permissions, audit and quality**

- **FR-071**: The system MUST expose a `sales.*` permission catalogue as the single source of truth consumed by its seeder and its policies.
- **FR-072**: The catalogue MUST separate managing a quotation from recording its decision, managing an invoice from issuing it, recording a payment from reversing it, and managing a credit note from confirming it. None of these may imply the other.
- **FR-073**: The feature MUST add three fixed dashboard roles — Sales Manager, Sales Officer and Billing Officer — to the shared role list, and MUST prove by test that adding them narrows every other module's admin bypass rather than assuming it.
- **FR-074**: The Reviewer role MUST have read-only access across every sales surface.
- **FR-075**: A user with no sales permission MUST be refused page access and MUST NOT see the link in the sidebar.
- **FR-076**: Quotation decisions, quotation conversion, invoice issuance, payment posting, payment reversal, credit note confirmation, credit note reversal, and PDF regeneration MUST be recorded in the activity log.
- **FR-077**: No sales service may resolve the acting user from the ambient session; the actor MUST be passed explicitly, as the accounting services already do, enforced by an architecture test.
- **FR-078**: Every user-facing label added by this feature MUST exist in the English translation file, enforced by a labels test.

**Cross-module isolation**

- **FR-079**: This feature MUST add no API route, dashboard-facing or public, and no unauthenticated surface of any kind.
- **FR-080**: Exactly three document events may post to the ledger: invoice issuance, payment collection with its tax recognition, and credit-note confirmation with its reversal. The existing test asserting that no document posts MUST be tightened to assert exactly these three and no fourth.
- **FR-081**: Purchasing MUST post nothing to the ledger and MUST gain no accounts-payable behaviour. Inventory, Support, CRM and Employees MUST likewise post nothing.
- **FR-082**: Ticket payment links MUST NOT be wired to the Payments module in this feature.
- **FR-083**: Every existing test MUST continue to pass, and the built order fulfillment, shipment and delivery behaviour MUST be unchanged.

### Key Entities

- **Sales settings**: The single configuration record for the module — default tax percent, default quotation validity, and the four accounts the flow posts to.
- **Payment term**: A due-date rule — due days, grace days, an optional discount, and whether it is the default.
- **Quotation** and **quotation line**: A priced offer to a customer, its lifecycle, and its immutable-once-sent lines carrying variant, quantity, unit price, tax and line total.
- **Order** and **order line**: The existing fulfillment document, extended to carry the accepted quotation's pricing and a payment status.
- **Delivery note**: Not an entity. A named view over existing delivery operations and their lines.
- **Invoice** and **invoice line**: The financial claim, its due date, its stored subtotal, tax, paid amount and grand total, and its lines.
- **Invoice confirmation**: Append-only evidence that a named person received an invoice, with an optional signature.
- **Payment method**: A collection channel and the account it debits.
- **Payment** and **payment allocation**: Money received, and how it is applied across invoices.
- **Tax recognition entry**: Append-only evidence that a specific collection recognised a specific amount of tax on a specific invoice.
- **Credit note** and **credit note line**: A correction to an invoice, its reason, and the lines it credits.

## Success Criteria

### Measurable Outcomes

- **SC-001**: All seven Sales sidebar items and both new System items open a working page; zero render the module placeholder.
- **SC-002**: A quotation can be carried through to an issued invoice without re-entering any line, quantity or price by hand.
- **SC-003**: For any date range, the sum of issued-invoice grand totals less credits equals the receivable account's movement, and the difference is exactly zero.
- **SC-004**: For every fully paid invoice, total recognised tax equals the invoice's tax total exactly — no rounding drift at any number of partial payments.
- **SC-005**: No issued invoice, posted payment or confirmed credit note in the system can be edited or deleted; every attempt is refused with a stated reason.
- **SC-006**: Every stock change arising from the sales lifecycle is attributable to an inventory operation and its movements; no sales document writes stock directly.
- **SC-007**: No quotation, in any state, has ever changed an on-hand quantity or created a reservation.
- **SC-008**: The ledger's only document sources are issued invoices, collected payments and confirmed credit notes; every other module contributes zero entries.
- **SC-009**: Tax appears in the tax-payable account only in proportion to money actually collected, verifiable by comparing recognised tax to collected amounts on any invoice.
- **SC-010**: The project quality gate passes unchanged — formatting, static analysis at the current level with no new baseline entries, type coverage, and the full test suite at its existing minimum coverage threshold.

## Assumptions

- **Stripe is deferred, not designed around.** The manual channel is the only channel here. Principle III's no-divergent-paths rule is met by building one posting service and one tax-recognition service now (FR-061), so a later Stripe feature adds a channel record and a webhook, not a second accounting path. This assumption is the one most likely to be revisited, and FR-061 is what makes revisiting it cheap.
- **A single tax rate is sufficient for this phase** (D7). Zero-rated and multi-rate items are expressed by the per-line tax override (FR-017) rather than by a rate catalogue. If mixed statutory rates become a requirement, a `tax_definitions` table and its reserved navigation item are the intended next step, and the per-line amount already stored is forward-compatible with it.
- **Deferred tax needs its own account.** The seeded chart has `2300 Sales Tax Payable` but nothing to hold tax that is invoiced and not yet collected. One additive seeded account, `2350 Deferred Sales Tax`, is assumed; without it, tax timing cannot be correct.
- **Cost of goods sold is not posted.** A delivery reduces stock and posts nothing (FR-035). Inventory valuation and COGS remain excluded by ADR 0007 and are not smuggled in here. Consequently the ledger in this phase shows revenue without matched cost, which is a known and accepted limitation of the phase, not a defect.
- **Receivable balances exist but are not reported on.** This feature creates the data an aged-receivables report would read, and deliberately does not build the report — that is `014-reporting-notifications-audit`. The Accounts Receivable navigation item stays a placeholder for the same reason.
- **Customer email addresses already on the customer profile are sufficient** for invoice delivery; no new contact management is introduced.
- **English only**, consistent with every module shipped so far.
- **Existing orders predate pricing.** Orders and lines created before this feature carry no price (FR-027) and require manual pricing if invoiced. Backfilling a price would fabricate financial history.
