# Feature Specification: Accounting Payables — Expenses, Supplier Bills, and Accounts Payable

**Feature Directory**: `022-accounting-payables-expenses-bills`

**Created**: 2026-08-23

**Status**: Accepted — governance gate cleared on 2026-08-26; implementation in progress

**Input**: Fill the last three `accounting` navigation slots — `expenses`, `bills`, and `accounts_payable` — over the suppliers and purchase orders delivered by `017-purchasing-orders-suppliers` and the general ledger delivered by `018-chart-of-accounts-journals`. Load-bearing sources: `Docs/PRD.md` §5 and §11; `Docs/SDD.md` §Supplier Management; `Docs/database/ERD.md` tables `suppliers`, `purchase_orders`, `purchase_order_lines`, `payment_terms`, `chart_accounts`, `journal_entries`; ADR 0006 §Out of scope, which excludes exactly this work from the Purchasing module and therefore locates it here. The three navigation slots and their English labels already exist (`lang/en/admin.php:883`–`:886`). The six decisions D1–D6 in §Owner Decisions are binding.

**Governance prerequisite**: Cleared on 2026-08-26. ADR 0011 is Accepted, ADR 0006 contains the narrow Accounting-side amendment, the constitution is amended, and the ERD divergence register is recorded. See §Governance Gate for the approval record.

## Owner Decisions

- **D1 — Payables live in the Accounting module, not in Purchasing.** ADR 0006 excludes supplier bills, accounts payable, payments to suppliers, journal entries, and purchase-tax recognition from the Purchasing module, and constitution §Specification Governance states that adding any of them to Purchasing requires an explicit ADR 0006 amendment. This feature does not add them to Purchasing. It builds them in Accounting and *reads* purchase orders. The ADR 0006 amendment this feature needs is therefore narrow: permission for an accounting document to reference a purchase order and its received quantities. No purchasing class changes, and no purchasing surface gains a payable.
- **D2 — Bill approval, expense approval, expense payment, and supplier payment post to the general ledger.** Expense approval recognises the payable liability; its later payment clears that liability. These four new posting callers join the three ADR 0008 authorises and the one ADR 0010 authorises. ADR 0011 must name these four and no others.
- **D3 — Goods bills expense rather than capitalise.** A bill line debits an account the accountant chooses, defaulting from settings. It does **not** debit Inventory, because that would be inventory valuation, which ADR 0007 excludes and `019` also declines. The consequence is stated rather than hidden: in this phase the ledger shows purchase cost as expense at bill date rather than as inventory carried until sale. This mirrors `019`'s accepted limitation of revenue without matched cost, and both resolve together when a valuation feature is specified.
- **D4 — Bill tax is captured and posted to a recoverable input-tax account, with no timing rule.** One additive seeded account, `1450 Recoverable Input Tax`. Output tax follows collection because constitution Principle III says so; Principle III says nothing about input tax, and `Docs/PRD.md` §12's tax question is still open. Input tax is therefore recognised at bill approval. **This is the requirement in this specification most likely to be wrong for the eventual jurisdiction**, and it is isolated to one account and one posting line so that changing it later is cheap.
- **D5 — Three-way match is advisory, not blocking.** A bill line may reference a purchase-order line; where it does, the surface shows ordered, received, and billed quantities and flags a variance. It does **not** refuse the bill. A hard block would make a legitimate over-delivery or a partial bill unrecordable, and the purpose here is to make the variance visible to the approver, who is the control.
- **D6 — Recording and approving are separate permissions held by different users**, for both bills and expenses, matching the separation ADR 0010 establishes for refunds and ADR 0006 establishes for purchase-order approval. Money leaving the business gets the same control wherever it leaves from.

## Governance Gate

Implementation was held until all four approvals were recorded. The gate is now cleared.

1. **ADR 0011 approved.** `Docs/adr/0011-accounting-payables-expenses-bills.md` was moved to **Accepted** on 2026-08-26.
2. **ADR 0006 amended.** The accepted amendment is narrow and explicit: an accounting document may reference a purchase order and its received quantities for the advisory match in D5. Purchasing gains no bill, payable, supplier payment, journal entry, or purchase-tax behaviour, and no purchasing class is modified by this feature.
3. **Constitution amended.** The 2026-08-26 amendment adds the narrow payables exception, records D2's four posting callers by name, and records that the §Specification Governance passage forbidding accounts-payable behaviour in Purchasing is satisfied — not overridden — because the behaviour is built in Accounting.
4. **ERD deviations recorded.** Every row of §ERD Divergence Register is recorded in `Docs/database/ERD.md`.

This feature is independent of `019-sales-lifecycle-payments-credits` and of `020-accounting-financial-reports` and `021-accounting-receivables-tax-refunds`, in all directions. Its technical prerequisites — `017` and `018` — are both built. Once this specification's governance gate is cleared, it is the first remaining Accounting slice that can be implemented without waiting for another feature.

## Current Repository Gap and Delivery Target

The Accounting sidebar already reserves these links through `App\Filament\AdminModuleRegistry`, but none has a corresponding resource class in the current repository. This specification is the implementation contract for those missing links. The delivery must preserve the one-way Accounting-to-Purchasing dependency; it must not replace the missing resources with Purchasing pages or modify Purchasing code.

| Sidebar link | Required resource | Required backing capability | Delivery condition |
|---|---|---|---|
| Expenses | `App\Filament\Resources\Expenses\ExpenseResource` | Draft → approved → paid expense lifecycle, receipt media, payable-liability recognition, and payment settlement | Authorized users can record, approve, and pay an expense with separation of duties and closed-period protection (FR-008–FR-015). |
| Bills | `App\Filament\Resources\Bills\BillResource` | Draft → approved → paid supplier bill lifecycle, advisory three-way match, and supplier-payment allocation | Authorized users can review match variances, approve a balanced bill, and allocate a supplier payment without overpaying (FR-016–FR-038). |
| Accounts Payable | `App\Filament\Resources\AccountsPayable\AccountsPayableResource` | Computed supplier aging and supplier detail reconciled to the payable control account | Authorized users can inspect the tie-out and export the selected payable scope (FR-039–FR-045, FR-051). |

The registry may expose the three links as their Accounting resources are implemented and permission-gated.

## ERD Divergence Register

| # | Table | Divergence | Authority |
|---|---|---|---|
| E-1 | `expenses` | **Added**; never in the ERD. Columns: `expense_number` (unique), `expense_date`, `supplier_id` (nullable), `requested_by` (nullable, `employee_profiles`), `chart_account_id`, `payment_method_id`, `amount`, `tax_amount`, `description`, `status` (`draft`/`approved`/`paid`/`cancelled`), `journal_entry_id` (nullable), `approved_by`, `approved_at`, blameable, soft-deletable for drafts only. | ADR 0011 |
| E-2 | `bills` | **Added**; never in the ERD. Columns: `bill_number` (unique), `supplier_id`, `supplier_reference` (the supplier's own invoice number), `purchase_order_id` (nullable), `payment_term_id` (nullable), `bill_date`, `due_date`, `subtotal`, `tax_total`, `grand_total`, `paid_amount`, `status` (`draft`/`approved`/`partially_paid`/`paid`/`cancelled`), `journal_entry_id` (nullable), `approved_by`, `approved_at`, blameable, soft-deletable for drafts only. | ADR 0011 |
| E-3 | `bill_lines` | **Added**. Columns: `bill_id`, `purchase_order_line_id` (nullable, the D5 match reference), `product_variant_id` (nullable), `chart_account_id`, `description`, `quantity`, `unit_price`, `tax_amount`, `line_total`, `sort_order`. | ADR 0011 |
| E-4 | `supplier_payments` | **Added**. The ERD's `payments` table is customer-facing with a non-nullable `customer_id`, and `019` builds it that way. Reusing it for outbound money would break every sum in the Payments module and in `019`'s proportional tax recognition. Columns: `supplier_payment_number` (unique), `supplier_id`, `payment_method_id`, `amount`, `payment_date`, `reference`, `status` (`draft`/`paid`/`cancelled`), `journal_entry_id` (nullable), blameable. | ADR 0011 |
| E-5 | `supplier_payment_allocations` | **Added**, mirroring the ERD's `payment_allocations` for the supplier side: `supplier_payment_id`, `bill_id`, `amount`. One payment settles several bills. | ADR 0011 |
| E-6 | `accounts_payable` | **Not created.** The Accounts Payable slot is a computed read surface over bills, allocations, and expenses, exactly as `021` treats receivables. | ADR 0011 |
| E-7 | `purchase_orders`, `purchase_order_lines` | **Not modified.** Not one column is added, and no purchasing class changes. The match reference points *from* `bill_lines` *to* `purchase_order_lines`, so the dependency direction is Accounting → Purchasing and never the reverse (D1). | ADR 0006 amendment |

**Not divergences**, recorded here because each looks like one:

- The chart-of-accounts seeder gains one account, `1450 Recoverable Input Tax` (D4). Additive reference data, not a schema change. `2100 Accounts Payable` already exists and is the payable control account.
- `bills`, `expenses`, and `supplier_payments` **keep** `deleted_at`, used only for drafts. An approved bill, an approved expense, and a paid supplier payment are never deleted by any path.
- No table gains a reversal column. A posted payable correction rides the `source_type`/`source_id` morph `018` already built.
- `supplier_payment_allocations` carries no `deleted_at` — it is append-only evidence of how money was applied, matching `019`'s treatment of `payment_allocations`.

## Scope

**In scope.** Expenses with a `draft` → `approved` → `paid` lifecycle, an expense account, an optional supplier and requesting employee, and a receipt attachment; supplier bills with priced lines, an optional purchase-order reference, payment-term-derived due dates, and a `draft` → `approved` → `partially_paid` → `paid` lifecycle; an advisory three-way match showing ordered, received, and billed quantities with variance flags; supplier payments with multi-bill allocation; ledger posting on bill approval, expense approval, expense payment, and supplier payment; an Accounts Payable subledger with per-supplier aging reconciled against the payable control account; `accounting.*` permission additions with separation of duties; audit logging of every state change; streamed CSV exports; English labels.

**Out of scope.** Everything ADR 0011 excludes, most importantly:

- **Any change to the Purchasing module.** No purchasing class is modified, no purchasing table gains a column, and no purchasing surface gains a payable, a bill, or a posting (D1). ADR 0006's prohibition is honoured by building elsewhere, not by relaxing it.
- **Inventory valuation, capitalisation of purchased goods, cost-of-goods-sold posting, landed-cost allocation, and moving-average or FIFO recalculation.** Excluded by ADR 0007 and restated by D3. A bill expenses; it does not value stock.
- **Supplier cost writeback.** `017` already writes received costs to supplier product references through its own service. This feature does not touch that path and does not add a second one.
- **Accounts receivable, the tax register, and customer refunds** — those are `021`. **General-ledger financial statements** — those are `020`.
- **Any API surface**, dashboard-facing or public. **No supplier-facing portal**, which the constitution excludes and which this feature does not relax.
- **Purchase requisitions, RFQs, supplier returns, and debit notes.** Excluded by ADR 0006 and not reopened.
- **Recurring expenses or recurring bills, scheduled payment runs, payment batching, and bank file generation.**
- **Bank accounts, bank reconciliation, and cash-flow forecasting.**
- **Input-tax filing or any statutory return.** D4 posts input tax to one account; it does not file anything.
- **Employee expense reimbursement as a payroll event.** An expense may record which employee requested it; settling it through payroll is the Employees module's salary path and is not wired here.
- **Multi-currency**, conversion, or revaluation. Single currency throughout.
- **An eighth posting caller.** D2 authorises three and no others.
- **No new dependency.** No Composer or npm package is added.

Two exclusions deserve emphasis because each is the most tempting thing to add quietly and the most damaging:

**The dependency direction is one-way.** Accounting reads Purchasing. Purchasing never reads Accounting, never learns what a bill is, and never gains a payable balance. An architecture test must enforce it, because the natural next change — showing a purchase order's billed amount on the purchase order — is exactly what ADR 0006 forbids and exactly what a helpful developer would add.

**A subledger may not adjust the ledger to make itself tie.** When the payable subledger and the payable control account disagree, the difference is displayed prominently and nothing is adjusted, matching `021` FR-014.

## User Scenarios & Testing

### User Story 1 — Enforce Payables Permissions and Separation of Duties (Priority: P1)

A System Admin grants an accountant the ability to record bills and expenses, and grants approval to the chief accountant only. Neither can approve their own work.

**Why this priority**: Every other story depends on the catalogue, and D6's separation is the control that makes the payable path trustworthy.

**Independent Test**: Seed the catalogue, assign roles, assert page and action availability per role.

**Acceptance Scenarios**:

1. **Given** the seeder has run, **When** a System Admin opens Expenses, Bills, and Accounts Payable, **Then** all three load.
2. **Given** a user holds the recording permission but not the approval permission, **When** they open a draft bill or expense, **Then** no Approve action is offered and calling the approval service directly is refused.
3. **Given** a user holds the approval permission, **When** they open a bill or expense they themselves recorded, **Then** approval is refused.
4. **Given** a user holds only the Reviewer role, **When** they open all three surfaces, **Then** each loads read-only with no record, approve, or pay action.
5. **Given** a user holds no payables permission, **When** they open any of the three, **Then** access is refused and the navigation links are absent.

---

### User Story 2 — Record, Approve, and Pay an Expense (Priority: P1)

An accountant records an office-rent expense against an expense account with a receipt attached. The chief accountant approves it. The accountant marks it paid once the money has left.

**Why this priority**: Expenses are the simplest complete payable — no supplier document, no match, no allocation — so they carry the lifecycle and posting rules with the least surrounding machinery. It is the smallest independently shippable slice.

**Independent Test**: Walk one expense through record, approve, and pay, asserting each guard and the resulting journal entry.

**Acceptance Scenarios**:

1. **Given** postable expense accounts exist, **When** the accountant records an expense with a date, account, amount, method, and description, **Then** it is created as a draft with a generated expense number.
2. **Given** a draft expense, **When** the accountant edits any field or attaches a receipt, **Then** the changes save.
3. **Given** a draft expense targeting a non-postable or inactive account, **When** it is approved, **Then** approval is refused naming the account.
4. **Given** an approved expense, **When** anyone tries to edit its amount, account, or date, **Then** the change is refused at both the service and the model layer.
5. **Given** a draft expense, **When** the chief accountant approves it, **Then** a posted journal entry debits the expense account and recoverable input tax and credits the payable control account, with `source` pointing at the expense.
6. **Given** an approved expense, **When** the accountant marks it paid, **Then** a posted journal entry debits the payable control account and credits the payment method's account, with `source` pointing at the expense.
7. **Given** an expense dated inside a closed fiscal period, **When** it is approved or paid, **Then** the posting is refused naming the period and the expense stays in its prior state.
8. **Given** a paid expense, **When** anyone tries to delete it, **Then** the deletion is refused.
9. **Given** a draft expense, **When** it is cancelled, **Then** it becomes cancelled and posts nothing.

---

### User Story 3 — Record a Supplier Bill Against a Purchase Order (Priority: P1)

An accountant records the supplier's invoice, references the purchase order it belongs to, and sees ordered, received, and billed quantities side by side with variances flagged before approving it.

**Why this priority**: The bill is the core payable document, and the match is the whole reason to reference a purchase order at all. It is also the only place this feature touches another module.

**Independent Test**: Create a purchase order, receive part of it through the existing inventory path, then bill it and assert the match columns and variance flags.

**Acceptance Scenarios**:

1. **Given** a transmitted purchase order exists, **When** the accountant records a bill referencing it, **Then** the bill's lines may be prefilled from the order's lines and each carries an expense account.
2. **Given** a bill line references a purchase-order line, **When** the bill is viewed, **Then** ordered, received, and billed quantities are shown for that line.
3. **Given** a bill line's billed quantity exceeds the received quantity, **When** the bill is viewed, **Then** an over-billing variance is flagged and the bill can still be approved (D5).
4. **Given** a bill line's unit price differs from the purchase-order line's, **When** the bill is viewed, **Then** a price variance is flagged showing both values.
5. **Given** a bill with no purchase-order reference, **When** it is recorded, **Then** it is accepted — not every payable has an order behind it.
6. **Given** a bill and a payment term, **When** it is recorded, **Then** its due date is derived from the term and the bill date.
7. **Given** a bill referencing a purchase order, **When** the purchase order is viewed, **Then** it shows no bill, no billed amount, and no payable — the dependency is one-way (D1).
8. **Given** a bill whose supplier reference duplicates an existing bill's for the same supplier, **When** it is saved, **Then** a validation error names the duplicate, so the same supplier invoice cannot be paid twice.

---

### User Story 4 — Approve a Bill so It Reaches the Ledger (Priority: P1)

A chief accountant reviews a bill's lines and variances and approves it, which recognises the liability.

**Why this priority**: Approval is where a bill becomes a debt and the ledger learns about it. It is one of D2's three posting callers.

**Independent Test**: Approve a bill and assert the journal entry's lines, accounts, and totals.

**Acceptance Scenarios**:

1. **Given** a draft bill with lines, **When** it is approved, **Then** a posted journal entry debits each line's account for its net amount, debits recoverable input tax for the tax total, and credits the payable control account for the grand total, with `source` pointing at the bill.
2. **Given** a bill whose lines do not sum to its stated totals, **When** it is approved, **Then** approval is refused reporting both figures.
3. **Given** a bill with no lines, **When** it is approved, **Then** approval is refused.
4. **Given** a bill line targeting a non-postable or inactive account, **When** the bill is approved, **Then** approval is refused naming the account.
5. **Given** a bill dated inside a closed fiscal period, **When** it is approved, **Then** approval is refused naming the period.
6. **Given** an approved bill, **When** anyone tries to edit its supplier, lines, or totals, **Then** the change is refused at both the service and the model layer.
7. **Given** two accountants approve the same draft bill concurrently, **When** both run, **Then** the second finds it already approved and is refused.

---

### User Story 5 — Pay Suppliers and Allocate Across Bills (Priority: P2)

An accountant records one payment to a supplier and allocates it across three of that supplier's approved bills.

**Why this priority**: It is how a payable is discharged, and it depends on bills existing. Multi-bill allocation is the realistic case, not the exception.

**Independent Test**: Approve three bills, pay them with one allocated payment, and assert each bill's paid amount, status, and the payment's journal entry.

**Acceptance Scenarios**:

1. **Given** approved bills for a supplier, **When** the accountant records a payment and allocates it across them, **Then** each bill's paid amount increases by its allocation and its status becomes partially paid or paid.
2. **Given** an allocation whose total exceeds the payment amount, **When** it is saved, **Then** it is refused reporting both figures.
3. **Given** an allocation to a bill exceeding that bill's remaining balance, **When** it is saved, **Then** it is refused.
4. **Given** a payment is recorded as paid, **When** the posting runs, **Then** a posted journal entry debits the payable control account and credits the payment method's account, with `source` pointing at the supplier payment.
5. **Given** a supplier payment dated inside a closed fiscal period, **When** it is paid, **Then** the posting is refused naming the period.
6. **Given** a payment whose posting fails, **When** the attempt completes, **Then** no allocation, no journal entry, and no bill status change remains.
7. **Given** a paid supplier payment, **When** anyone tries to edit or delete it, **Then** the change is refused.
8. **Given** a cancelled bill, **When** a payment is allocated to it, **Then** the allocation is refused.

---

### User Story 6 — Read the Payable Aging and Prove It Ties to the Ledger (Priority: P1)

An accountant opens Accounts Payable, reads each supplier's outstanding balance bucketed by age, and confirms the subledger total equals the general ledger's payable control account.

**Why this priority**: It is the surface the slot exists for, and the tie-out is what makes it evidence rather than a second opinion.

**Independent Test**: Approve bills and pay some, then assert per-supplier balances, bucket placement, and the tie-out.

**Acceptance Scenarios**:

1. **Given** approved bills with partial payments exist, **When** the accountant opens Accounts Payable, **Then** each supplier with a non-zero balance is listed with billed, paid, and outstanding totals.
2. **Given** any set of documents, **When** the aging is produced, **Then** the sum of all suppliers' outstanding balances equals the general ledger balance of the payable control account, displayed as an explicit proof.
3. **Given** the two differ, **When** the aging is produced, **Then** the difference is displayed prominently as an error and nothing is adjusted.
4. **Given** bills overdue by 15, 45, 75, and 120 days as of the report date, **When** the aging is produced, **Then** they fall in the 1–30, 31–60, 61–90, and over-90 buckets, each boundary belonging to the lower bucket.
5. **Given** a draft or cancelled bill, **When** the aging is produced, **Then** it is excluded.
6. **Given** an approved but unpaid expense, **When** the aging is produced, **Then** it appears in the payable position, because an approved expense is a debt.
7. **Given** a supplier opened from the aging, **When** their detail is read, **Then** each open bill is listed with number, supplier reference, date, due date, days overdue, total, paid, and remaining, and the remainders sum to the aging balance.

---

### User Story 7 — Export a Payables Surface (Priority: P2)

An accountant exports the aging or a supplier's detail for a month-end pack.

**Why this priority**: It is how the evidence leaves the system and depends on the surfaces existing.

**Independent Test**: Request each export and assert rows, scope statement, and permission gate.

**Acceptance Scenarios**:

1. **Given** a user holds the surface's view permission, **When** they export it, **Then** rows and totals match the screen exactly, including the tie-out proof.
2. **Given** a user lacks it, **When** they request the export directly, **Then** it is refused.
3. **Given** an empty surface, **When** it is exported, **Then** the file downloads with headers and zero data rows.

---

### Edge Cases

- **A bill referencing a purchase order that was never transmitted** — permitted with a flag; a draft order can legitimately be billed early, and refusing it would make the accountant work around the system.
- **A bill for a purchase order with nothing received** — permitted, fully flagged as an over-billing variance on every line (D5).
- **A purchase order billed twice** — permitted, because partial billing is normal, but the cumulative billed quantity across all bills is what the match compares, not this bill's alone.
- **The same supplier invoice number recorded twice for one supplier** — refused (US3 scenario 8). This is the single most valuable duplicate-payment control in the feature.
- **The same supplier invoice number used by two different suppliers** — permitted; uniqueness is per supplier, not global.
- **A bill whose lines sum correctly but whose tax total is inconsistent with its lines' tax** — refused at approval, reporting both.
- **A supplier payment allocated to zero bills** — refused; an unallocated outbound payment has no payable behind it.
- **A payment exactly equal to a bill's remaining balance** — settles it to paid; one minor unit more is refused.
- **Two payments each within a bill's remaining balance but together exceeding it** — the second is refused, because remaining balance is recomputed inside the allocating transaction.
- **An expense with no supplier** — permitted; petty cash and internal costs have no supplier.
- **An expense with a requesting employee who is later deleted** — the expense and its posting remain; the employee shows as deleted.
- **Rounding across bill lines** — the journal entry's debits must equal its credit to the minor unit, so line net amounts plus tax must sum exactly to the grand total; a bill that cannot balance is refused at approval rather than posted and reversed later.
- **A closed fiscal period reopened after an approval was refused** — the approval succeeds on retry; nothing is cached.
- **An empty system** — all three surfaces render zero rows and zero totals with holding tie-outs.

## Requirements

### Functional Requirements

**Permissions and navigation**

- **FR-001**: The system MUST add `accounting.expense.view`, `accounting.expense.manage`, `accounting.expense.approve`, `accounting.bill.view`, `accounting.bill.manage`, `accounting.bill.approve`, `accounting.supplier-payment.manage`, and `accounting.payable.view` to the existing `AccountingPermission` catalogue.
- **FR-002**: The system MUST NOT let any `manage` permission imply its corresponding `approve` permission (D6).
- **FR-003**: The system MUST refuse approval by the same user who recorded the bill or expense, enforced in the service.
- **FR-004**: The system MUST grant approval permissions to Chief Accountant only, recording permissions to Chief Accountant and Accountant, and all view permissions additionally to Reviewer.
- **FR-005**: The system MUST fill exactly the `expenses`, `bills`, and `accounts_payable` navigation slots.
- **FR-006**: The system MUST provide English labels for all three surfaces, every column, every status, every variance flag, and every tie-out proof.
- **FR-007**: The permission seeder MUST remain idempotent.

**Expenses**

- **FR-008**: The system MUST store expenses per §ERD Divergence Register E-1, with a generated unique expense number.
- **FR-009**: The system MUST permit a draft expense to be freely edited and deleted, and MUST permit a receipt attachment through Spatie Media Library per constitution Principle IV.
- **FR-010**: The system MUST refuse to approve an expense whose account is non-postable or inactive, naming the account.
- **FR-011**: The system MUST treat an approved or paid expense as immutable in its amount, account, date, and supplier, enforced in the service and again at the model layer.
- **FR-012**: The system MUST post an approved expense as a posted journal entry debiting the expense account for the net amount, debiting recoverable input tax for the tax amount, and crediting the payable control account for the total, with `source` pointing at the expense. The system MUST post its later payment as a second posted journal entry debiting the payable control account and crediting the payment method's account for the total, with `source` pointing at the expense.
- **FR-013**: The system MUST refuse to approve or pay an expense whose applicable posting date resolves to a closed fiscal period, naming the period.
- **FR-014**: The system MUST refuse to delete an approved or paid expense by any path.
- **FR-015**: The system MUST permit cancelling a draft expense, which posts nothing.

**Bills**

- **FR-016**: The system MUST store bills and bill lines per §ERD Divergence Register E-2 and E-3, with a generated unique bill number.
- **FR-017**: The system MUST reject a bill whose supplier reference duplicates an existing non-cancelled bill's for the same supplier, and MUST scope that uniqueness per supplier.
- **FR-018**: The system MUST derive a bill's due date from its payment term and bill date where a term is given.
- **FR-019**: The system MUST permit a bill line to reference a purchase-order line, and MUST permit a bill with no purchase-order reference at all.
- **FR-020**: The system MUST show, for a line referencing a purchase-order line, the ordered quantity, the cumulative received quantity from the existing inventory operation records, and the cumulative billed quantity across all bills referencing that line.
- **FR-021**: The system MUST flag a quantity variance and a unit-price variance against the referenced purchase-order line, and MUST NOT refuse the bill on either (D5).
- **FR-022**: The system MUST permit a draft bill and its lines to be freely edited and deleted.
- **FR-023**: The system MUST refuse to approve a bill with no lines, or whose lines' net and tax amounts do not sum exactly to its stated subtotal, tax total, and grand total, reporting both figures.
- **FR-024**: The system MUST refuse to approve a bill any of whose lines targets a non-postable or inactive account, naming the account.
- **FR-025**: The system MUST post an approved bill as a posted journal entry debiting each line's account for its net amount, debiting recoverable input tax for the tax total, and crediting the payable control account for the grand total, with `source` pointing at the bill (D2, D3, D4).
- **FR-026**: The system MUST refuse to approve a bill whose date resolves to a closed fiscal period, naming the period.
- **FR-027**: The system MUST treat an approved bill as immutable in its supplier, lines, and totals, enforced in the service and again at the model layer.
- **FR-028**: The system MUST refuse to delete an approved bill by any path.
- **FR-029**: The system MUST NOT debit an inventory account for any bill line (D3).

**Supplier payments**

- **FR-030**: The system MUST store supplier payments and their allocations per §ERD Divergence Register E-4 and E-5.
- **FR-031**: The system MUST refuse a payment whose allocations total more than its amount, reporting both.
- **FR-032**: The system MUST refuse an allocation exceeding its bill's remaining balance, and MUST recompute that balance inside the allocating transaction.
- **FR-033**: The system MUST refuse an allocation to a draft or cancelled bill, and MUST refuse a payment with no allocations.
- **FR-034**: The system MUST post a paid supplier payment as a posted journal entry debiting the payable control account and crediting the payment method's account, with `source` pointing at the payment.
- **FR-035**: The system MUST update each allocated bill's paid amount and derive its status as partially paid or paid.
- **FR-036**: The system MUST refuse to pay when the payment date resolves to a closed fiscal period, naming the period.
- **FR-037**: The system MUST leave no allocation, journal entry, or bill status change behind when a payment attempt fails.
- **FR-038**: The system MUST treat a paid supplier payment as immutable and undeletable.

**Accounts Payable (read-only)**

- **FR-039**: The system MUST compute each supplier's outstanding balance from approved bills less allocated payments, plus approved unpaid expenses attributable to that supplier, storing no balance column.
- **FR-040**: The system MUST exclude draft and cancelled bills and expenses.
- **FR-041**: The system MUST bucket outstanding amounts by age against each bill's due date as of the report date, into current, 1–30, 31–60, 61–90, and over-90 buckets, each boundary belonging to the lower bucket.
- **FR-042**: The system MUST reconcile the sum of all suppliers' outstanding balances against the general ledger balance of the payable control account, displaying that equality as an explicit proof.
- **FR-043**: The system MUST display any tie-out difference prominently as an error, and MUST NOT adjust, round, suppress, or plug it.
- **FR-044**: The system MUST offer a per-supplier detail whose remaining balances sum to that supplier's aging balance.
- **FR-045**: The system MUST include a soft-deleted supplier's documents and mark the supplier as deleted where it appears.

**Common behaviour and boundaries**

- **FR-046**: The system MUST compute all monetary aggregation on integer minor units and convert to a decimal string only at the presentation boundary.
- **FR-047**: The system MUST perform every approval and payment inside a database transaction, locking the row for the status transition.
- **FR-048**: The system MUST record an audit log entry for every bill, expense, and supplier-payment state change, attributed to the acting user, via `spatie/laravel-activitylog` per ADR 0005.
- **FR-049**: The system MUST render all three surfaces over an empty system as zero rows and zero totals, without error.
- **FR-050**: The system MUST aggregate in a number of queries that does not grow with the number of suppliers, bills, or payments.
- **FR-051**: The system MUST offer a streamed CSV export of each surface, gated on that surface's own view permission and enforced on the export request itself, stating the exported scope in the file, and writing no persistent export log.
- **FR-052**: The system MUST NOT modify any class, table, column, or surface in the Purchasing module, and MUST NOT expose a bill, a payable, a supplier payment, or a billed amount on any purchasing surface (D1).
- **FR-053**: The system MUST NOT create any posting caller beyond the four authorised by D2.
- **FR-054**: The system MUST NOT touch the existing supplier cost writeback path, and MUST NOT add a second one.
- **FR-055**: The system MUST NOT produce a trial balance, profit and loss, balance sheet, general ledger, or posting register, and MUST NOT build any receivable, tax-register, or customer-refund surface.
- **FR-056**: The system MUST leave `017`'s and `018`'s behaviour unchanged and their whole test suites passing.

### Key Entities

- **Expense** — a cost incurred without a supplier document, with an account, an approver, and a ledger posting on payment.
- **Bill** — a supplier's invoice: priced lines, an optional purchase-order reference, a due date, and a ledger posting on approval that recognises the liability.
- **BillLine** — one charge against one account, optionally matched to a purchase-order line for the advisory three-way comparison.
- **SupplierPayment** and **SupplierPaymentAllocation** — money sent to a supplier and how it was applied across their bills.
- **Payable position** — computed, never stored: approved bills and expenses less what has been paid, aged against due dates.
- Read without modification: **Supplier**, **PurchaseOrder**, **PurchaseOrderLine**, **InventoryOperation** received quantities, **PaymentTerm**, **ChartAccount**, **FiscalPeriod**, **JournalEntry**.

## Success Criteria

### Measurable Outcomes

- **SC-001**: The payable subledger total equals the general ledger balance of the payable control account in every test scenario, with the proof displayed.
- **SC-002**: A tie-out failure is displayed as an error and no figure is adjusted, proven by a test that deliberately breaks the tie.
- **SC-003**: An approved bill's journal entry balances to the minor unit — line nets plus input tax equal the payable credit — and a bill that cannot balance is refused at approval rather than posted.
- **SC-004**: The same supplier invoice number cannot be recorded twice for one supplier, and can be recorded once for each of two different suppliers.
- **SC-005**: A bill line's match shows ordered, cumulative received, and cumulative billed quantities, flags variance, and never refuses the bill.
- **SC-006**: No purchasing class, table, column, or surface is modified, and no purchasing surface exposes a bill, payable, or billed amount — proven by an architecture test asserting the one-way dependency.
- **SC-007**: A recorder cannot approve their own bill or expense, proven by policy tests and by Filament tests asserting the actions are absent.
- **SC-008**: An approved bill, approved expense, or paid supplier payment cannot be modified or deleted, proven at both the service and model layer.
- **SC-009**: An allocation cannot exceed its payment's amount or its bill's remaining balance, and two concurrent allocations cannot jointly overpay a bill.
- **SC-010**: Approval or payment into a closed fiscal period is impossible for bills, expenses, and supplier payments alike.
- **SC-011**: A failed posting leaves no journal entry, no allocation, and no status change anywhere.
- **SC-012**: No bill line ever debits an inventory account.
- **SC-013**: Aging buckets are correct at their exact 30, 60, and 90-day boundaries, and draft or cancelled documents never appear.
- **SC-014**: Bill approval, expense approval, expense payment, and supplier payment are the only new posting callers, proven by a test asserting no other path creates a journal entry.
- **SC-015**: All three surfaces render over an empty system with zero rows, zero totals, and holding tie-outs.
- **SC-016**: `composer test` passes with no new PHPStan baseline entries, and `017`'s and `018`'s suites pass unchanged.

## Assumptions

- **This feature is implementable today.** Both prerequisites, `017` and `018`, are built. It is the only remaining accounting specification with no unbuilt dependency, which makes it the natural first of the three to implement if the sales programme is not yet ready.
- **Purchased goods are expensed, not capitalised** (D3). The ledger therefore shows cost at bill date rather than at sale. This is a stated limitation of the phase, symmetric with `019`'s revenue-without-matched-cost limitation, and both resolve when inventory valuation is specified. It is recorded as an assumption rather than buried because an accountant reading the profit and loss will notice it immediately.
- **Input tax is recognised at bill approval** (D4). Constitution Principle III governs output tax only, and `Docs/PRD.md` §12's currency and tax question is unanswered. This is the assumption most likely to be revisited, and it is isolated to one seeded account and one posting line.
- **The three-way match is advisory** (D5). The approver is the control, not a validation rule. A blocking match would make over-delivery and partial billing unrecordable.
- **Aging buckets of current / 1–30 / 31–60 / 61–90 / over-90** mirror `021`'s receivable buckets so the two subledgers read alike. No document specifies buckets.
- **An approved expense is a debt** and therefore appears in the payable position even with no supplier. Approving a cost commits to paying it; excluding unpaid expenses would understate what is owed.
- **One payment can settle many bills, and one bill can be settled by many payments.** Both are normal supplier practice, which is why allocations are a table rather than a column.
- **Received quantities come from the existing inventory operation records** written by `017`'s receiving service. This feature computes from them and adds no stock-writing path of its own.
- **Single currency** and **English only**, consistent with every module shipped so far.

## Dependencies and Integration Points

**Depends on (built).** `017-purchasing-orders-suppliers` for suppliers, purchase orders, purchase-order lines, and the received quantities its receiving service records through the Inventory operation path. `018-chart-of-accounts-journals` for the ledger, `JournalPostingService`, fiscal periods, the `AccountingPermission` catalogue, and the accounting dashboard roles. `005-products-variants-warehouses-inventory` for product variants referenced by bill lines. ADR 0005's activity log. Constitution Principle IV's Media Library requirement for receipt attachments.

**Independent of.** `019-sales-lifecycle-payments-credits`, `020-accounting-financial-reports`, and `021-accounting-receivables-tax-refunds`, in all directions. It shares the ledger with all three and shares no table, permission, or surface with any of them. `payment_terms` is read by both this feature and `019`; whichever lands first creates it, and the other must not recreate it — this is the one table two specifications both need, and it is called out here so the second implementation reuses rather than duplicates.

**One-way integration with Purchasing.** `bill_lines` references `purchase_order_lines`; nothing in Purchasing references anything here. This direction is the entire content of the ADR 0006 amendment, and FR-052 with SC-006 exist to keep it one-way. The natural, forbidden next change is to show a purchase order's billed amount on the purchase order.

**Explicitly not integrated.** CRM, Support, Employees beyond naming a requesting employee, and Orders. No query joins to their tables and none gains a posting path.

**New dependencies.** None.
