# Feature Specification: Accounting Subledgers — Accounts Receivable, Tax Register, and Customer Refunds

**Feature Directory**: `021-accounting-receivables-tax-refunds`

**Created**: 2026-08-23

**Status**: Blocked — missing Sales lifecycle dependency and governance approvals

**Input**: Fill three of the six remaining `accounting` navigation slots — `accounts_receivable`, `taxes`, and `refunds` — over the invoice, payment, tax-recognition, and credit-note data delivered by `019-sales-lifecycle-payments-credits`. Load-bearing sources: `Docs/PRD.md` §5 (FR-010 "Tax must be recognized only when payment is collected", FR-011 "Partial payments must recognize tax proportionally") and §Business Rules; `Docs/SDD.md` §Tax Recognition, §Invoice Flow, and §Credit Notes; `Docs/database/ERD.md` tables `invoices`, `payments`, `payment_allocations`, `tax_recognition_entries`, `credit_notes`, and `payment_methods`; constitution Principle III (NON-NEGOTIABLE). The three navigation slots and their English labels already exist (`lang/en/admin.php:882`, `:887`, `:886`). The five decisions D1–D5 in §Owner Decisions are binding.

**Governance prerequisite**: This feature is **blocked** until ADR 0010 (`Docs/adr/0010-accounting-receivables-tax-refunds.md`) is approved, the constitution amendment is merged, and `019-sales-lifecycle-payments-credits` is implemented. See §Governance Gate.

## Owner Decisions

### Carried forward

- **From ADR 0008 / spec 019 D6** — invoice issuance, payment collection, and credit-note confirmation post to the general ledger through `JournalPostingService`. Those three callers exist before this feature begins.
- **From ADR 0008 / spec 019 D7** — there is no `tax_definitions` table. Tax is a default rate on the `sales_settings` singleton with a per-line override. The `system` → Tax Definitions slot stays a placeholder, and this feature does not fill it.
- **From ADR 0008 / spec 019 D9** — the manual payment channel only. No Stripe. A refund therefore has no gateway to call, and refunds are recorded, approved, and paid by an admin.

### Taken for this feature

- **D1 — Accounts Receivable and Taxes add no table.** Both are read surfaces derived from invoices, payments, allocations, credit notes, and tax-recognition entries. A stored receivable balance or stored tax total would be a cache able to disagree with the documents and with the ledger, which is the failure this feature exists to detect rather than create.
- **D2 — Refunds get a dedicated `refunds` table.** A refund is **not** modelled as a negative `payments` row. Overloading `payments` would silently corrupt every sum built on it, including `019`'s proportional tax recognition, which divides collected amount by invoice total: a negative collection would recognise negative tax at the wrong moment and no test in `019` would catch it. A refund is a distinct event with its own date, method, approver, and posting.
- **D3 — A refund posts to the general ledger, and it reverses recognised tax proportionally.** This is a **fourth** authorised posting event, beyond the three ADR 0008 authorises, and ADR 0010 must name it explicitly. Refunding collected money must un-recognise the tax that collection recognised, or constitution Principle III's rule that tax follows collection is satisfied in one direction only.
- **D4 — Recording a refund and approving it are separate permissions held by different roles.** Money leaving the business is the one operation in the accounting module with no reversal that costs nothing, so it gets the separation of duties that posting and reversing already have in `018`.
- **D5 — A refund is only ever paid against an available credit balance.** It is not a free-form disbursement. The available balance is computed from confirmed credit notes and overpayments, and a refund may not exceed it. Without this rule, the Refunds surface becomes a way to move money out of the business with no document behind it.

## Governance Gate

No implementation task may begin until all four hold.

1. **`019-sales-lifecycle-payments-credits` implemented.** This feature reads `invoices`, `payments`, `payment_allocations`, `tax_recognition_entries`, `credit_notes`, `payment_methods`, and `sales_settings`. None exists yet. This is a hard prerequisite, not a sequencing preference.
2. **ADR 0010 approved.** `Docs/adr/0010-accounting-receivables-tax-refunds.md` must be authored and moved to **Accepted**. Accounts-receivable subledgers, refunds, and financial reports of any kind are each named in ADR 0007's out-of-scope list; ADR 0008 additionally and explicitly leaves the Accounts Receivable slot a placeholder. Without ADR 0010 every Filament class here violates the constitution.
3. **Constitution amended.** The amendment must add the narrow exception for receivable, tax, and refund administration, and must record D3's authorisation of a **fourth** ledger-posting event, naming it and no others. It must restate that ADR 0006's prohibition on accounts-payable and general-ledger behaviour in the Purchasing module survives untouched.
4. **ERD deviations recorded.** Every row of §ERD Divergence Register must be written into `Docs/database/ERD.md` before implementation begins, following the ADR 0006 and ADR 0008 precedent.

This feature is independent of `020-accounting-financial-reports` in both directions and of `022-accounting-payables-expenses-bills` in both directions. Any of the three may land in any order.

## Current Repository Gap and Delivery Target

The Accounting sidebar already reserves these links through `App\Filament\AdminModuleRegistry`, but none has a corresponding resource class in the current repository. This specification is the implementation contract for those missing links; it does not authorise placeholder pages or a stored receivables/tax balance.

| Sidebar link | Required resource | Required backing capability | Delivery condition |
|---|---|---|---|
| Accounts Receivable | `App\Filament\Resources\AccountsReceivable\AccountsReceivableResource` | Computed customer aging and a customer detail view over the `019` documents | Authorized users can open the resource, inspect the ledger tie-out, and export it (FR-008–FR-018, FR-046–FR-048). |
| Taxes | `App\Filament\Resources\Taxes\TaxResource` | Computed recognised/deferred tax register over `019` tax-recognition data | Authorized users can inspect both control-account proofs and export the selected scope (FR-019–FR-025, FR-046–FR-048). |
| Refunds | `App\Filament\Resources\Refunds\RefundResource` | Refund lifecycle and the only new posting path in this slice | Authorized users can record, separately approve, and pay a refund atomically (FR-026–FR-041). |

`019-sales-lifecycle-payments-credits` must land before any of these classes are created. Until then, the registry's conditional link resolution must continue to omit the three links rather than expose unusable pages.

## ERD Divergence Register

| # | Table | Divergence | Authority |
|---|---|---|---|
| E-1 | `refunds` | **Added**; never in the ERD. Columns: `refund_number` (unique), `customer_id`, `credit_note_id` (nullable), `invoice_id` (nullable), `payment_method_id`, `amount`, `refund_date`, `reason` (text), `status` (`draft`/`approved`/`paid`/`cancelled`), `journal_entry_id` (nullable), `approved_by`, `approved_at`, blameable, soft-deletable for drafts only. Per D2. | ADR 0010 |
| E-2 | `refund_items` | **Not created.** A refund is a money movement against an existing credit balance, not a line-item document. Its lines would duplicate the credit note's. | ADR 0010 (D2) |
| E-3 | `tax_recognition_entries` | Gains a nullable `refund_id` and permits a **negative** `recognized_tax_amount`, so a refund's tax un-recognition is recorded in the same append-only register as the recognition it reverses rather than in a parallel table. Still no `deleted_at` — the register stays append-only, matching `019`. | ADR 0010 (D3) |
| E-4 | `accounts_receivable`, `accounts_payable` | **Not created**, and never in the ERD. Both are computed views. This feature creates the receivable surface as a read model only (D1). | ADR 0010 (D1) |
| E-5 | `taxes`, `tax_definitions` | **Not created.** The Taxes slot is a read surface over `tax_recognition_entries`; rate configuration remains `sales_settings.default_tax_percent` per `019` D7. | ADR 0008 (D7) |

**Not divergences**, recorded here because each looks like one:

- `refunds` **keeps** `deleted_at`, used only for drafts. An approved or paid refund is never deleted by any path, soft or hard (FR-038).
- `refunds` carries **no** `refund_items`, and no reversal column. A cancelled refund is cancelled by status; a paid refund is corrected by a reversing journal entry through the existing `source_type`/`source_id` morph.
- No new chart-of-accounts row is seeded. A refund debits the receivable control account named in `sales_settings` and credits the payment method's `chart_account_id`, both established by `019`.

## Scope

**In scope.** An Accounts Receivable subledger showing per-customer outstanding balances with aging buckets, reconciled against the general ledger's receivable control account; a per-customer receivable drill-down listing open invoices, allocations, credit notes, and refunds; a Tax register showing tax recognised by period and tax still deferred, each reconciled against its control account; customer refunds with a `draft` → `approved` → `paid` lifecycle, separation of duties between recording and approving, ledger posting on payment, and proportional tax un-recognition; `accounting.*` permission additions; audit logging of every refund state change; streamed CSV exports; English labels.

**Out of scope.** Everything ADR 0010 excludes, most importantly:

- **Accounts payable, supplier bills, and expenses.** These belong to `022-accounting-payables-expenses-bills`. The `accounts_payable`, `bills`, and `expenses` slots stay placeholders.
- **General-ledger financial statements** — trial balance, profit and loss, balance sheet, general ledger, posting register. Those belong to `020-accounting-financial-reports`. This feature reconciles *to* the ledger; it does not report *on* it.
- **Any API surface**, dashboard-facing or public.
- **Stripe refunds and any gateway call.** Per `019` D9 there is no gateway. A refund records that money was sent; it does not send it.
- **Dunning, reminder schedules, statements of account posted to customers, and credit limits or credit holds.** Deriving an `overdue` status is `019`'s; acting on it is not this feature's.
- **Write-off of a bad debt.** Writing off a receivable is a posting decision with its own approval and tax consequences, and it is not a refund. It needs its own specification.
- **Any change to how `019` recognises tax on collection.** This feature adds the reverse direction and must not alter the forward one.
- **Multi-currency**, conversion, or revaluation. Single currency throughout.
- **Tax filing, tax returns, or statutory tax reports** of any jurisdiction. The Taxes surface is an internal register, not a filing.
- **A fifth posting caller.** D3 authorises refunds and nothing else. No observer, listener, or service call from Inventory, Purchasing, Support, CRM, or Employees gains a posting path.
- **No new dependency.** No Composer or npm package is added.

One exclusion deserves emphasis because it is the most tempting thing to add quietly and the most damaging: **a subledger may not adjust the ledger to make itself tie.** When the receivable subledger and the receivable control account disagree, that is a real defect in posting, and the only correct behaviour is to display the difference prominently and stop. A subledger that plugs its own variance converts a detectable bug into an undetectable one.

## User Scenarios & Testing

### User Story 1 — Enforce Receivable, Tax, and Refund Permissions (Priority: P1)

A System Admin grants an accountant the ability to read receivables and the tax register and to record a refund, but not to approve one. Approving is the Chief Accountant's.

**Why this priority**: Every other story depends on the catalogue existing, and D4's separation of duties is the control that makes the refund path trustworthy. Money leaving the business is the highest-consequence operation in the module.

**Independent Test**: Seed the catalogue, assign roles, assert page and action availability per role. Delivers the access-control guarantee before any figure renders.

**Acceptance Scenarios**:

1. **Given** the seeder has run, **When** a System Admin opens Accounts Receivable, Taxes, and Refunds, **Then** all three load.
2. **Given** a user holds `accounting.refund.manage` but not `accounting.refund.approve`, **When** they open a draft refund, **Then** no Approve action is offered and calling the approval service directly is refused.
3. **Given** a user holds `accounting.refund.approve`, **When** they open a refund they themselves recorded, **Then** approval is refused — the recorder and the approver must be different users.
4. **Given** a user holds only `accounting.receivable.view`, **When** they open Taxes or Refunds, **Then** access is refused and both navigation links are absent.
5. **Given** a user holds only the Reviewer role, **When** they open all three surfaces, **Then** each loads read-only with no record, approve, or pay action available.

---

### User Story 2 — Read the Receivable Aging and Prove It Ties to the Ledger (Priority: P1)

An accountant opens Accounts Receivable, reads each customer's outstanding balance bucketed by age, and confirms the subledger total equals the general ledger's receivable control account.

**Why this priority**: The aging is the surface's reason to exist, and the tie-out is what makes it evidence rather than a second opinion. A subledger nobody has reconciled is a liability.

**Independent Test**: Post invoices, payments, and credit notes across date boundaries and assert per-customer balances, bucket placement, and the control-account tie-out.

**Acceptance Scenarios**:

1. **Given** issued invoices with partial collections exist, **When** the accountant opens Accounts Receivable, **Then** each customer with a non-zero balance is listed with invoiced, collected, credited, refunded, and outstanding totals.
2. **Given** any set of documents, **When** the aging is produced, **Then** the sum of all customers' outstanding balances equals the general ledger balance of the receivable control account named in `sales_settings`, and the report displays that equality as an explicit proof.
3. **Given** the subledger total and the control account differ, **When** the aging is produced, **Then** the difference is displayed prominently as an error and no figure is adjusted to conceal it.
4. **Given** an invoice whose due date is in the future, **When** the aging is produced, **Then** its balance falls in the current bucket, not in an overdue one.
5. **Given** invoices overdue by 15, 45, 75, and 120 days as of the report date, **When** the aging is produced, **Then** they fall in the 1–30, 31–60, 61–90, and over-90 buckets respectively, and each bucket boundary is inclusive at its lower edge.
6. **Given** a draft invoice, **When** the aging is produced, **Then** it is excluded — a draft is not yet a claim.
7. **Given** a customer who has overpaid, **When** the aging is produced, **Then** their negative outstanding balance is shown as a credit rather than as a zero or an overdue amount.
8. **Given** a fully collected invoice, **When** the aging is produced, **Then** the customer is omitted if they have no other open document.

---

### User Story 3 — Drill Into One Customer's Receivable Position (Priority: P2)

An accountant opens a customer from the aging and reads every document behind their balance: open invoices with days overdue, the payments allocated to each, confirmed credit notes, and any refunds.

**Why this priority**: It is what makes the aging actionable, but it is a drill-down, so it follows the surface it explains.

**Independent Test**: Build one customer with invoices, partial allocations, a credit note, and a refund, then assert the drill-down's rows and its total.

**Acceptance Scenarios**:

1. **Given** a customer with open invoices, **When** the accountant opens their receivable detail, **Then** each invoice is listed with number, date, due date, days overdue, grand total, allocated amount, and remaining balance.
2. **Given** a payment allocated across two invoices, **When** the detail is read, **Then** the allocation appears under each invoice at its allocated amount, and the payment is not double-counted in the customer total.
3. **Given** a confirmed credit note against an invoice, **When** the detail is read, **Then** it appears as a reduction and the invoice's remaining balance reflects it.
4. **Given** a paid refund, **When** the detail is read, **Then** it appears as a reduction of the customer's available credit.
5. **Given** the customer detail is read, **When** its remaining balances are summed, **Then** the total equals that customer's outstanding balance on the aging.

---

### User Story 4 — Read the Tax Register and Separate Recognised from Deferred (Priority: P1)

An accountant opens Taxes for a period and reads tax recognised on collections in that period, tax still deferred on uncollected invoices, and confirms each ties to its control account.

**Why this priority**: Constitution Principle III makes tax-on-collection non-negotiable, and this is the only surface that can demonstrate the rule was actually followed. It is also the surface an auditor will ask for first.

**Independent Test**: Collect partial payments against taxed invoices and assert recognised amounts, the deferred remainder, and both tie-outs.

**Acceptance Scenarios**:

1. **Given** collections occurred in the period, **When** the accountant opens Taxes, **Then** each recognition is listed with its invoice, payment, recognition date, payment amount, and recognised tax, subtotalled for the period.
2. **Given** an invoice collected in part, **When** the register is read, **Then** the recognised tax is proportional to the collected fraction and the remainder appears as deferred.
3. **Given** any set of documents, **When** the register is produced, **Then** total recognised tax ties to the general ledger balance of the tax-payable control account, total deferred tax ties to the deferred-tax control account, and both equalities are displayed as explicit proofs.
4. **Given** either tie-out fails, **When** the register is produced, **Then** the difference is displayed prominently as an error and nothing is adjusted.
5. **Given** an issued but uncollected invoice carrying tax, **When** the register is read, **Then** its whole tax sits in deferred and none in recognised.
6. **Given** a refund that un-recognised tax, **When** the register is read for the refund's period, **Then** the un-recognition appears as a negative recognition attributed to that refund, and the period subtotal reflects it.
7. **Given** a zero-tax invoice, **When** the register is read, **Then** it contributes nothing to either total and does not appear as a zero row.

---

### User Story 5 — Record and Approve a Customer Refund (Priority: P1)

An accountant records a refund against a customer's credit balance and states the reason. A chief accountant reviews the credit behind it and approves it. The accountant then marks it paid once the money has actually left.

**Why this priority**: It is the feature's only write path, it moves money, and it carries D4's separation of duties and D5's credit-balance rule. Everything that can go irreversibly wrong in this feature goes wrong here.

**Independent Test**: Build a customer with a confirmed credit note, then walk a refund through record, approve, and pay, asserting each guard.

**Acceptance Scenarios**:

1. **Given** a customer with an available credit balance, **When** the accountant records a refund for part of it with a method and reason, **Then** it is created as a draft with a generated refund number.
2. **Given** a draft refund, **When** the accountant edits its amount, method, date, or reason, **Then** the changes save — drafts are freely editable.
3. **Given** a refund whose amount exceeds the customer's available credit balance, **When** it is approved, **Then** approval is refused and the error states both the requested amount and the available balance.
4. **Given** a customer with no credit balance, **When** a refund against them is approved, **Then** approval is refused.
5. **Given** a draft refund, **When** the user who recorded it tries to approve it, **Then** approval is refused naming the separation-of-duties rule.
6. **Given** an approved refund, **When** anyone tries to edit its amount, customer, or source credit, **Then** the change is refused at both the service and the model layer.
7. **Given** an approved refund, **When** the accountant marks it paid, **Then** it becomes paid, its ledger posting is created, and an audit entry records who paid it and when.
8. **Given** a paid refund, **When** anyone tries to delete it, **Then** the deletion is refused.
9. **Given** two accountants approve the same draft refund concurrently, **When** both attempts run, **Then** the second finds it already approved and is refused; the row is locked for the transition.
10. **Given** a draft refund, **When** it is cancelled, **Then** it becomes cancelled, posts nothing, and leaves the customer's credit balance untouched.

---

### User Story 6 — A Paid Refund Reaches the Ledger and Un-Recognises Its Tax (Priority: P1)

When a refund is marked paid, the ledger records the money leaving and the tax that the original collection recognised is un-recognised in proportion.

**Why this priority**: This is D3, the fourth posting event, and the half of Principle III that `019` does not deliver. A refund that moves money without touching the ledger silently breaks the receivable tie-out that User Story 2 depends on.

**Independent Test**: Collect a taxed invoice in part, refund part of the collection, and assert both the journal entry and the negative recognition.

**Acceptance Scenarios**:

1. **Given** a refund is marked paid, **When** the posting runs, **Then** a posted journal entry debits the receivable control account and credits the payment method's account for the refund amount, and its `source` points at the refund.
2. **Given** the original collection recognised tax, **When** the refund posts, **Then** a tax-recognition row is appended with a negative recognised amount proportional to the refunded fraction of that collection, and its `refund_id` names the refund.
3. **Given** a refund against a collection that recognised no tax, **When** it posts, **Then** no tax-recognition row is appended and the refund still posts.
4. **Given** a refund dated inside a closed fiscal period, **When** it is marked paid, **Then** the posting is refused naming the period and the refund stays approved.
5. **Given** a refund whose posting fails for any reason, **When** the attempt completes, **Then** the refund is not marked paid and no partial ledger or tax row remains.
6. **Given** a collection, its full refund, and the tax register, **When** all are read, **Then** the pair contributes zero net recognised tax and zero net receivable movement.
7. **Given** a paid refund, **When** the receivable aging is produced, **Then** the customer's available credit is reduced by the refund and the control-account tie-out still holds.

---

### User Story 7 — Export a Subledger for Circulation (Priority: P2)

An accountant exports the aging, the customer detail, or the tax register to attach to a month-end pack.

**Why this priority**: It is how the evidence leaves the system, and it depends on the surfaces existing.

**Independent Test**: Request each export and assert rows, headers, scope statement, and permission gate.

**Acceptance Scenarios**:

1. **Given** a user holds the surface's view permission, **When** they export it, **Then** a file downloads whose rows and totals match the screen exactly, including its tie-out proof.
2. **Given** the surface was scoped to a date or period, **When** it is exported, **Then** the export states that scope, so a detached file cannot be misread.
3. **Given** a user lacks the surface's view permission, **When** they request its export directly, **Then** it is refused.
4. **Given** a surface is empty for the chosen scope, **When** it is exported, **Then** the file downloads with headers and zero data rows.

---

### Edge Cases

- **An invoice with no due date** — `019` derives due dates from payment terms, so this should be impossible; if encountered, the invoice ages from its invoice date and the anomaly is surfaced rather than silently bucketed as current.
- **A refund whose credit source is a credit note later cancelled** — cancelling a confirmed credit note is not permitted by `019`, so the credit cannot vanish beneath a paid refund. A draft refund against it is refused at approval when the balance no longer supports it.
- **A cancelled invoice with collections against it** — the collections remain, so the customer holds a credit balance; the aging shows it as a credit and it is refundable.
- **A fully credited invoice** — nets to zero and is omitted from the aging; the credit note's own effect on the customer's credit balance is still shown in the drill-down.
- **An overpayment larger than any single invoice** — appears as an unallocated credit balance, refundable up to its amount.
- **A refund of exactly the available balance** — permitted; a refund of one minor unit more is refused.
- **Two refunds each within the balance but together exceeding it** — the second is refused at approval, because availability is recomputed inside the approving transaction rather than read beforehand.
- **A partial refund of a partial collection** — tax un-recognition is proportional to the refunded fraction *of that collection*, not of the invoice.
- **Rounding on proportional tax un-recognition** — computed on integer minor units; a full refund of a collection must un-recognise exactly what that collection recognised, with no residual minor unit left behind. This is the rounding case most likely to leave a one-cent tie-out failure.
- **Aging bucket boundaries at exactly 30, 60, and 90 days** — each boundary belongs to the lower bucket, specified so the report does not vary by off-by-one.
- **A report date earlier than every invoice date** — every bucket is zero and the tie-out holds trivially.
- **An empty system with no invoices at all** — all three surfaces render zero rows and zero totals without error.
- **A customer deleted after being invoiced** — their documents and balance remain; the customer appears marked as deleted, because history is never rewritten by a later deletion.

## Requirements

### Functional Requirements

**Permissions and navigation**

- **FR-001**: The system MUST add `accounting.receivable.view`, `accounting.tax.view`, `accounting.refund.view`, `accounting.refund.manage`, and `accounting.refund.approve` to the existing `AccountingPermission` catalogue, which remains the single source of truth for the seeder and the policies.
- **FR-002**: The system MUST NOT let `accounting.refund.manage` imply `accounting.refund.approve`. Recording a refund and authorising money to leave are different acts (D4).
- **FR-003**: The system MUST refuse approval by the same user who recorded the refund, enforced in the service and not only in the UI.
- **FR-004**: The system MUST grant the refund-approval permission to Chief Accountant only, and the recording and view permissions to Chief Accountant and Accountant, with all view permissions also granted to Reviewer.
- **FR-005**: The system MUST fill exactly the `accounts_receivable`, `taxes`, and `refunds` navigation slots, and MUST leave `accounts_payable`, `bills`, and `expenses` as placeholders.
- **FR-006**: The system MUST provide English labels for all three surfaces, every column, every status, and every tie-out proof. Arabic falls back to English per the convention at the top of `lang/ar/admin.php`.
- **FR-007**: The permission seeder MUST remain idempotent.

**Accounts Receivable (read-only)**

- **FR-008**: The system MUST compute each customer's outstanding balance from issued invoices, allocated payments, confirmed credit notes, and paid refunds, storing no balance column (D1).
- **FR-009**: The system MUST exclude draft and cancelled invoices from the receivable balance.
- **FR-010**: The system MUST bucket each outstanding amount by age against its invoice's due date as of the report date, into current, 1–30, 31–60, 61–90, and over-90 buckets, with each boundary belonging to the lower bucket.
- **FR-011**: The system MUST present a customer whose collections exceed their claims as holding a credit balance, distinguishable from zero.
- **FR-012**: The system MUST omit a customer whose outstanding balance and credit balance are both zero.
- **FR-013**: The system MUST reconcile the sum of all customers' outstanding balances against the general ledger balance of the receivable control account named in `sales_settings`, and MUST display that equality as an explicit proof.
- **FR-014**: The system MUST display any tie-out difference prominently as an error, and MUST NOT adjust, round, suppress, or plug it.
- **FR-015**: The system MUST offer a per-customer detail listing open invoices with number, date, due date, days overdue, total, allocated amount, and remaining balance, plus that customer's confirmed credit notes and paid refunds.
- **FR-016**: The system MUST NOT double-count a payment allocated across several invoices in the customer total.
- **FR-017**: The system MUST make the per-customer detail's remaining balances sum to that customer's aging balance.
- **FR-018**: The system MUST include a soft-deleted customer's documents and mark the customer as deleted where it appears.

**Tax register (read-only)**

- **FR-019**: The system MUST list, for a chosen period, each tax-recognition row with its invoice, payment or refund, recognition date, payment amount, and recognised tax, subtotalled.
- **FR-020**: The system MUST compute deferred tax as invoiced tax not yet recognised, over issued and uncancelled invoices.
- **FR-021**: The system MUST reconcile total recognised tax against the general ledger balance of the tax-payable control account, and total deferred tax against the deferred-tax control account, displaying both equalities as explicit proofs.
- **FR-022**: The system MUST display either tie-out's difference prominently as an error without adjusting any figure.
- **FR-023**: The system MUST present a refund's tax un-recognition as a negative recognition attributed to that refund.
- **FR-024**: The system MUST omit an invoice carrying no tax rather than listing it as a zero row.
- **FR-025**: The system MUST NOT alter how `019` recognises tax on collection.

**Refunds (the only write path)**

- **FR-026**: The system MUST store refunds with a generated unique refund number, customer, optional source credit note and invoice, payment method, amount, refund date, reason, `draft`/`approved`/`paid`/`cancelled` status, optional journal entry, approver, and approval timestamp, blameable, soft-deletable for drafts only.
- **FR-027**: The system MUST compute a customer's available credit balance from confirmed credit notes and overpayments, less paid and approved refunds.
- **FR-028**: The system MUST refuse to approve a refund exceeding the customer's available credit balance, reporting both the requested amount and the available balance (D5).
- **FR-029**: The system MUST recompute available credit inside the approving transaction, so two refunds each individually within the balance cannot both be approved when together they exceed it.
- **FR-030**: The system MUST permit a draft refund to be freely edited and deleted.
- **FR-031**: The system MUST treat an approved or paid refund as immutable in its customer, amount, source credit, and method, enforced in the service and again at the model layer.
- **FR-032**: The system MUST post a paid refund to the ledger as a posted journal entry debiting the receivable control account and crediting the payment method's account, with `source` pointing at the refund (D3).
- **FR-033**: The system MUST append a negative tax-recognition row proportional to the refunded fraction of the original collection, carrying the refund's identifier, when that collection recognised tax.
- **FR-034**: The system MUST compute the proportional un-recognition on integer minor units, and MUST leave no residual minor unit when a collection is refunded in full.
- **FR-035**: The system MUST refuse to mark a refund paid when its date resolves to a closed fiscal period, naming the period, leaving the refund approved.
- **FR-036**: The system MUST perform approval and payment inside a database transaction, locking the refund row for the status transition.
- **FR-037**: The system MUST leave no journal entry and no tax row behind when a payment attempt fails, and MUST NOT mark the refund paid.
- **FR-038**: The system MUST refuse to delete an approved or paid refund by any path, soft or hard.
- **FR-039**: The system MUST permit cancelling a draft refund, which posts nothing and changes no balance.
- **FR-040**: The system MUST record an audit log entry for every refund recording, edit, approval, payment, and cancellation, attributed to the acting user, via `spatie/laravel-activitylog` per ADR 0005.
- **FR-041**: The system MUST expose refund approval and payment through a service taking an explicit acting `User` and self-checking authorisation against it.

**Common behaviour**

- **FR-042**: The system MUST compute all monetary aggregation on integer minor units and convert to a decimal string only at the presentation boundary.
- **FR-043**: The system MUST render all three surfaces over an empty system as zero rows and zero totals, without error.
- **FR-044**: The system MUST aggregate in a number of queries that does not grow with the number of customers, invoices, or payments.
- **FR-045**: The system MUST order rows deterministically, so the same scope produces the same order on every run.
- **FR-046**: The system MUST offer a streamed CSV export of each surface, gated on that surface's own view permission, enforced on the export request itself.
- **FR-047**: The system MUST state the exported scope inside the exported file.
- **FR-048**: The system MUST NOT record an export in any persistent log.

**Boundaries**

- **FR-049**: The system MUST NOT create any posting caller beyond the refund payment authorised by D3. No observer, listener, or service call from another module gains a posting path.
- **FR-050**: The system MUST NOT write any accounts-payable, supplier-bill, or expense record, and MUST NOT reach the Purchasing module's data.
- **FR-051**: The system MUST NOT produce a trial balance, profit and loss, balance sheet, general ledger, or posting register; it reconciles to control accounts and reports nothing else about the ledger.
- **FR-052**: The system MUST leave `019`'s invoice, payment, allocation, and credit-note behaviour unchanged, and its whole test suite passing.

### Key Entities

- **Refund** — the only entity this feature creates: money returned to a customer against an available credit balance, with a recorded reason, a separate approver, and a ledger posting on payment.
- **Receivable position** — computed, never stored: a customer's claims less collections, credits, and refunds, aged against invoice due dates.
- **Tax position** — computed, never stored: tax recognised on collections, less tax un-recognised on refunds, against tax still deferred on uncollected invoices.
- Read without modification: **Invoice**, **Payment**, **PaymentAllocation**, **CreditNote**, **TaxRecognitionEntry**, **PaymentMethod**, **SalesSetting**, **ChartAccount**, **FiscalPeriod**, **JournalEntry**.

## Success Criteria

### Measurable Outcomes

- **SC-001**: The receivable subledger total equals the general ledger balance of the receivable control account for every test scenario, and the proof is displayed.
- **SC-002**: Recognised tax ties to the tax-payable control account and deferred tax ties to the deferred-tax control account, in every test scenario, with both proofs displayed.
- **SC-003**: A tie-out failure is displayed as an error and no figure is adjusted to conceal it, proven by a test that deliberately breaks the tie.
- **SC-004**: Aging buckets are correct at their exact boundaries — 30, 60, and 90 days each fall in the lower bucket.
- **SC-005**: A draft or cancelled invoice never contributes to a receivable balance or an aging bucket.
- **SC-006**: A refund exceeding the available credit balance cannot be approved by any path — the Filament action, the service, or a direct model write — and each path has a test.
- **SC-007**: Two concurrent approvals of refunds that together exceed the available balance cannot both succeed.
- **SC-008**: A recorder cannot approve their own refund, proven by a policy test and by a Filament test asserting the action is absent.
- **SC-009**: An approved or paid refund's customer, amount, source credit, and method cannot be modified and it cannot be deleted, proven at both the service and model layer.
- **SC-010**: A paid refund creates exactly one posted journal entry whose `source` is the refund, and a full refund of a collection un-recognises exactly the tax that collection recognised, with zero residual minor units.
- **SC-011**: A collection paired with its full refund contributes zero net recognised tax and zero net receivable movement.
- **SC-012**: A refund into a closed fiscal period is impossible, and a failed payment leaves no journal entry, no tax row, and the refund unpaid.
- **SC-013**: Each surface is reachable only with its own view permission, and each export is refused without it, including when requested directly.
- **SC-014**: All three surfaces render over an empty system with zero rows, zero totals, and holding tie-outs.
- **SC-015**: The refund payment is the only new posting caller, proven by a test asserting no other module's paths create a journal entry.
- **SC-016**: `composer test` passes with no new PHPStan baseline entries, and `019`'s and `018`'s suites pass unchanged.

## Assumptions

- **`019` lands first.** Every figure here reads a table `019` creates. This is the only specification in the accounting set with a hard ordering constraint, and it is absolute rather than preferential.
- **The receivable control account is configuration, not a code.** It is read from `sales_settings`, following `019` E-7, so the chart of accounts stays the accountant's to restructure. No account is referenced by code anywhere in this feature.
- **Aging buckets of current / 1–30 / 31–60 / 61–90 / over-90 are conventional.** No document in the canonical set specifies buckets. These are the common default and are chosen for that reason; they are presentation and can change without a schema change.
- **A refund's tax un-recognition is proportional to the refunded fraction of the collection it reverses**, not of the invoice. Refunding half of a collection that itself covered a quarter of an invoice un-recognises half of what that collection recognised. This is the only reading consistent with `019`'s forward calculation.
- **Overpayment is possible and is a credit, not an error.** `019` allocates payments to invoices and does not forbid collecting more than is owed, so an unallocated remainder is treated as a customer credit and is refundable.
- **A bad-debt write-off is not a refund** and is excluded. Both reduce a receivable, but a write-off recognises a loss and has different tax treatment and different approval; conflating them would smuggle a second posting decision into the refund path.
- **Single currency**, consistent with `018`, `019`, and `020`.
- **English only**, consistent with every module shipped so far.

## Dependencies and Integration Points

**Depends on (built).** `018-chart-of-accounts-journals` for the ledger, `JournalPostingService`, fiscal periods, the `AccountingPermission` catalogue, and the accounting dashboard roles. `013-crm-customers-subscriptions` for customer profiles. ADR 0005's activity log. `003-auth-users-spatie-access` for users and permissions.

**Depends on (unbuilt — hard prerequisite).** `019-sales-lifecycle-payments-credits` for `invoices`, `payments`, `payment_allocations`, `credit_notes`, `tax_recognition_entries`, `payment_methods`, and `sales_settings`, and for the three posting callers this feature's fourth joins.

**Independent of.** `020-accounting-financial-reports` and `022-accounting-payables-expenses-bills`, in both directions. `020` reports on the ledger; this feature reconciles to it. The two overlap in no table and no permission.

**Explicitly not integrated.** Inventory, Purchasing, Support, Employees, and Orders. No query joins to their tables, and none of them gains a posting path. ADR 0006's prohibition on accounts-payable and general-ledger behaviour in Purchasing is untouched — supplier-side payables are `022`'s subject and need their own ADR 0006 amendment.

**New dependencies.** None.
