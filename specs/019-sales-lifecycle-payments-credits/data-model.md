# Data Model: Sales Module Completion

**Feature Directory**: `019-sales-lifecycle-payments-credits` | **Date**: 2026-08-23 | **Plan**: [plan.md](./plan.md)

Phase 1 output. Twelve new tables, two altered in place, one seeded row. Every deviation from `Docs/database/ERD.md` is registered in [spec.md](./spec.md) §ERD Divergence Register and must be written into the ERD before implementation begins.

Conventions inherited from the existing schema, applied throughout without repeating them per table: `id` big-increment primary key; `created_at`/`updated_at`; `created_by`/`updated_by` nullable FKs to `users` with `nullOnDelete` on every document table, via the `TracksBlameable` concern; `deleted_at` only where stated; every FK indexed; `restrictOnDelete` on a reference a document depends on, `cascadeOnDelete` on a line whose parent owns it entirely; money as `decimal(15,2)`; quantity as `decimal(15,3)`.

---

## 1. `sales_settings` — singleton configuration

Follows the `inventory_settings` and `purchase_settings` precedent: exactly one row, fetched by `SalesSetting::current()` with `firstOrCreate`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `default_tax_percent` | decimal(5,2) | No | 0.00 | FR-006: 0–100 inclusive |
| `default_quotation_validity_days` | unsigned integer | No | 30 | Seeds a new quotation's expiry |
| `receivable_account_id` | FK `chart_accounts` | Yes | null | FR-005 |
| `revenue_account_id` | FK `chart_accounts` | Yes | null | FR-005 |
| `deferred_tax_account_id` | FK `chart_accounts` | Yes | null | Holds tax billed and not yet collected |
| `tax_payable_account_id` | FK `chart_accounts` | Yes | null | Receives tax on collection only |
| `customer_deposits_account_id` | FK `chart_accounts` | Yes | null | FR-055 unallocated remainder |

**Rules.** All five account references are nullable at rest and **required at posting time**: `SalesAccountResolver` refuses a posting whose needed account is null, non-postable, or inactive, naming the account in the message (FR-007). Nullable at rest because a fresh install has no configuration and must still be able to run migrations and open the page; required at posting because a posting with a missing account has no correct behaviour. All five FKs are `restrictOnDelete` — spec 018 already forbids deleting an account with posted lines, and this closes the case where the account is configured but not yet used.

`customer_deposits_account_id` is not in the spec's §ERD Divergence Register row for E-7, which names four accounts. It is the fifth, required by FR-055, and the ERD write-up must carry all five.

---

## 2. `payment_terms` — per ERD

| Column | Type | Null | Default |
|---|---|---|---|
| `name` | varchar(100) | No | |
| `due_days` | integer | No | 0 |
| `grace_days` | integer | No | 0 |
| `discount_percent` | decimal(5,2) | Yes | null |
| `is_default` | boolean | No | false |
| `deleted_at` | timestamp | Yes | null |

**Rules.** `name` unique, matching the plain-unique convention `pricing_tiers.name` already established for a soft-deletable named reference in this codebase. FR-009: at most one `is_default` at a time, enforced in the service by clearing the incumbent inside the same transaction — not by a partial unique index, which MySQL does not support. FR-010: `due_date = invoice_date + due_days`. FR-011: overdue once `today > due_date + grace_days` and the invoice is not fully paid. FR-012: referenced by an invoice ⇒ not deletable. `discount_percent` is stored per the ERD and **not applied** anywhere in this feature — early-settlement discounting is not specified and is not invented here.

---

## 3. `payment_methods` — per ERD, plus two columns (E-8)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `name` | varchar(100) | No | | Unique over non-deleted |
| `type` | varchar(50) | No | | Per ERD: `cash` / `bank_transfer` / `cheque` / `custom` / `stripe`. Retained though only the manual four are reachable this feature (D9) |
| `is_online` | boolean | No | false | Per ERD. Always `false` this feature — no online method can be created while D9 holds — kept so a future Stripe method needs no migration |
| `is_active` | boolean | No | true | |
| `requires_proof` | boolean | No | false | **Added.** FR-053 |
| `chart_account_id` | FK `chart_accounts` | No | | **Added.** The account a collection debits |
| `deleted_at` | timestamp | Yes | null | |

**Rules.** `chart_account_id` is `restrictOnDelete` and must be postable and active at posting time. FR-012: referenced by a payment ⇒ not deletable. Changing a method's account does **not** rewrite history — posted entries keep the account they hit, which is a property of the ledger's immutability rather than something this table enforces. The Filament form **refuses to create or activate** a method with `type = stripe` or `is_online = true` — accepting one would be building the online channel D9 defers under a different name. `is_online` is validated by the resource, not by a database check, matching how the other in-scope/out-of-scope boundaries in this feature are enforced.

---

## 4. `quotations` / `quotation_lines`

`quotations`:

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `quotation_number` | varchar(100) | No | | Unique **including soft-deleted** (R-005) |
| `customer_id` | FK `customer_profiles` | No | | restrictOnDelete |
| `employee_id` | FK `employee_profiles` | Yes | null | Owning salesperson |
| `sales_opportunity_id` | FK `sales_opportunities` | Yes | null | **Unique** — at most one quotation per opportunity (FR-025) |
| `payment_term_id` | FK `payment_terms` | Yes | null | restrictOnDelete |
| `issue_date` | date | No | | |
| `expires_at` | date | Yes | null | Defaults from settings validity |
| `notes` | text | Yes | null | **Added**, not in ERD — carries a sales opportunity's summary across on FR-025 creation, distinct from `decision_note` (the customer's recorded answer) |
| `subtotal` / `tax_total` / `grand_total` | decimal(15,2) | No | 0.00 | Stored, FR-018 |
| `status` | varchar(30) | No | draft | Real lifecycle, indexed |
| `sent_at` | timestamp | Yes | null | |
| `decided_at` | date | Yes | null | FR-021 |
| `decision_note` | text | Yes | null | |
| `decided_by` | FK `users` | Yes | null | Who recorded the answer |
| `converted_order_id` | FK `orders` | Yes | null | FR-024, unique |
| `deleted_at` | timestamp | Yes | null | Drafts only |

`quotation_lines` (`quotation_items` in the ERD; named `_lines` for consistency with the built `order_lines` and `inventory_operation_lines`):

| Column | Type | Null | Notes |
|---|---|---|---|
| `quotation_id` | FK | No | cascadeOnDelete |
| `product_variant_id` | FK | No | restrictOnDelete |
| `description` | text | Yes | |
| `quantity` | decimal(15,3) | No | > 0 |
| `unit_price` | decimal(15,2) | No | ≥ 0 |
| `tax_amount` | decimal(15,2) | No | ≥ 0, default 0 |
| `line_total` | decimal(15,2) | No | |
| `resolved_price_source` | varchar(40) | Yes | The `ResolvedPriceSource` the default came from (FR-015) |
| `sort_order` | unsigned integer | No | default 0 (E-9) |

**Rules.**

- `line_total = round(quantity × unit_price, 2) + tax_amount`. Document totals are the sum of their lines, recomputed on any line change **while the status is `draft`** and never after (FR-018, FR-023).
- `tax_amount` defaults to `round(quantity × unit_price × settings.default_tax_percent ÷ 100, 2)`, overridable per line (FR-017, R-014).
- `unit_price` guarded by `PriceResolver::assertAtOrAboveFloor()` on write **while draft only** (R-002).
- **No unique constraint on `(quotation_id, product_variant_id)`.** The built `order_lines` has one; a quotation must not, because the same variant legitimately appears twice at different prices or with different descriptions. This asymmetry with `order_lines` is deliberate and is the reason conversion (§6) aggregates by variant.
- `converted_order_id` unique enforces FR-024 at the database level, not only in the service.
- **A quotation has no stock relationship of any kind** — no reservation, no movement, no lot. FR-020 is enforced by the schema containing nothing to enforce.

**Lifecycle** (FR-019) — see [contracts/lifecycles.md](./contracts/lifecycles.md):

```
draft ──► sent ──► accepted ──► converted_to_delivery
  │        │  └──► rejected
  │        │  └──► expired
  └────────┴──► cancelled          (any non-terminal state)
```

`expired` is a stored status set when a decision is attempted past `expires_at`, **and** a derived presentation for a `sent` quotation whose `expires_at` has passed — the same dual treatment `overdue` gets on invoices. Nothing scheduled sweeps for expiry; this feature adds no scheduled command.

---

## 5. `orders` / `order_lines` — altered in place (E-1, E-2, R-004)

**Added to `orders`**, all nullable, nothing renamed or dropped:

| Column | Type | Notes |
|---|---|---|
| `quotation_id` | FK `quotations` nullable | Unique — one order per quotation |
| `payment_term_id` | FK `payment_terms` nullable | restrictOnDelete |
| `subtotal` / `tax_total` / `grand_total` | decimal(15,2) nullable | Null ⇒ order predates pricing |
| `payment_status` | varchar(30) nullable, indexed | |

**Added to `order_lines`**, all nullable: `unit_price`, `tax_amount`, `line_total` — `decimal(15,2)`.

**Not added**: `supplier_id`. Spec 017 records a supplier's answer through the `SupplierConfirmation` `confirmable` morph; a scalar FK would be a competing source for the same fact.

**Rules.**

- **No backfill.** Pre-existing rows keep `null` prices, which is the truthful value (R-004). FR-040 makes a null price a blocking condition at invoicing, which is where a human is already deciding what to bill.
- The existing `status` column keeps its built values and its `'ready'` default. `payment_status` is a separate axis — fulfillment state and money state are independent, which is why the ERD carries both.
- **`orders` posts nothing to the ledger, in any state** (FR-032). Neither does the `Shipment` flow.
- The `quotation_id` ↔ `quotations.converted_order_id` pair is redundant by design: each side's unique index independently prevents a double conversion, and the service writes both inside one transaction.

---

## 6. Conversion: quotation → order (FR-029)

Not a table. Recorded here because it is where the two schemas above meet and where the one lossy step in the feature lives.

`QuotationConversionService::convert()` in one transaction: assert `status = accepted` and `converted_order_id is null`; create the order with the quotation's `customer_id`, `payment_term_id` and totals copied verbatim; create order lines; set `quotation.status = converted_to_delivery` and both link columns.

**The one aggregation.** `order_lines` has a unique `(order_id, product_variant_id)` index (built, pre-existing). A quotation may carry the same variant on two lines. Conversion therefore aggregates duplicate variants into one order line: quantities sum, `tax_amount` and `line_total` sum, and `unit_price` becomes `round(line_total_sum − tax_sum) ÷ quantity_sum` to two places.

This is the only place in the feature where a stored figure is derived rather than copied, so it is the only place a rounding difference can appear. The invariant that protects the money: **the order's document totals are copied from the quotation, never recomputed from the aggregated lines.** A sub-cent aggregation difference can therefore land in a line's unit price but can never change what the customer owes. A test asserts order totals equal quotation totals exactly for a quotation with duplicate variants.

Dropping the built unique index was the alternative and was rejected — it is load-bearing for `DeliveryWarehouseAllocationService`'s per-variant allocation.

---

## 7. `invoices` / `invoice_lines` / `invoice_confirmations`

`invoices`:

| Column | Type | Null | Notes |
|---|---|---|---|
| `invoice_number` | varchar(100) | No | Unique including soft-deleted |
| `customer_id` | FK `customer_profiles` | No | restrictOnDelete |
| `inventory_operation_id` | FK `inventory_operations` | Yes | **The source delivery** — E-3's replacement for `delivery_note_id`. Unique: FR-036 |
| `order_id` | FK `orders` | Yes | Convenience denormalisation of the operation's source document |
| `payment_term_id` | FK `payment_terms` | Yes | restrictOnDelete |
| `invoice_date` / `due_date` | date | No | FR-010 |
| `subtotal` / `tax_total` / `grand_total` | decimal(15,2) | No | default 0 |
| `paid_amount` | decimal(15,2) | No | default 0 |
| `credited_amount` | decimal(15,2) | No | default 0. **Added**, not in ERD |
| `recognised_tax_amount` | decimal(15,2) | No | default 0. **Added**, not in ERD |
| `status` | varchar(30) | No | indexed |
| `issued_at` / `sent_at` | timestamp | Yes | |
| `deleted_at` | timestamp | Yes | Drafts only |

Two added columns need justifying, since both are derivable:

- **`credited_amount`** is the sum of confirmed credit notes against this invoice. FR-064 caps further credits against it and FR-050 caps `paid_amount` by it; both checks run inside the invoice's row lock, and deriving it there would mean aggregating `credit_note_lines` under that lock on every allocation.
- **`recognised_tax_amount`** is the sum of this invoice's tax recognition entries. R-008's residue absorption and R-010's reversal split both read it inside a lock, for the same reason.

Both are maintained only by the services that write their sources, inside the same transaction, and a feature test asserts each equals its derived aggregate after every scenario. Storing a derivable figure is a real cost; the alternative was a second aggregate query inside a hot lock, which R-009 already rejected in the allocation case.

`invoice_lines`: `invoice_id` (cascade), `product_variant_id` (nullable per ERD — service lines have no variant), `description` (**required**, per ERD), `quantity`, `unit_price`, `tax_amount`, `line_total`, `order_line_id` (nullable, provenance for FR-040), `sort_order` (E-9). Same arithmetic as quotation lines. No unique index on variant.

`invoice_confirmations` (E-10): `invoice_id` (cascade), `confirmed_by_user_id` (restrict), `confirmation_type` (`customer_received` | `employee_confirmed_received`), `confirmed_at`, `notes`. **No `signature_path`** — the signature is a Media Library attachment on the confirmation. **No `deleted_at`, no `updated_by`**: append-only (FR-049), enforced at the model layer by refusing `updating` and `deleting`, following the `MaintenanceRecord` defence-in-depth precedent.

**Rules.**

- `inventory_operation_id` unique ⇒ each delivery invoiced at most once (FR-036). The service additionally asserts the operation's `stage = done` and `operation_type = delivery`.
- **Immutability on issue** (FR-042): once `issued_at` is set, the model refuses changes to `customer_id`, any line, and any total. Enforced at both the service and model layer, per 018's precedent.
- **Never deletable once issued** (FR-043): the model refuses `deleting` when `issued_at` is non-null, so soft delete, force delete, and cascade all refuse.
- `paid_amount ≤ grand_total − credited_amount` (FR-050), checked under the row lock.
- `status` is stored; **`overdue` is derived, not stored** — an issued, unpaid invoice past due + grace presents as overdue without a scheduled job rewriting rows (FR-011).
- **Media collections**: `invoice-pdf` (multiple items, newest is current — R-006).

**Lifecycle** (FR-041):

```
draft ──► issued ──► sent ──► customer_received | employee_confirmed_received
            │
            ├──► partially_paid ──► paid
            └──► credited
draft ──► cancelled          (issued invoices are cancelled only via credit note)
```

---

## 8. `payments` / `payment_allocations` / `manual_payment_records` / `tax_recognition_entries`

`payments`: `payment_number` (unique incl. soft-deleted), `customer_id` (restrict), `payment_method_id` (restrict), `amount` decimal(15,2) > 0, `currency` char(3) default the app's single configured currency (per ERD; not user-editable — multi-currency is out of scope for the whole module, not only here), `source` varchar(50) default `manual` (per ERD; the only value this feature can ever write, per D9), `payment_date` date, `external_reference` varchar(255) nullable, `notes` text nullable, `status` varchar(30) indexed, `posted_at` timestamp nullable, `reversed_at` timestamp nullable, `reversed_by` FK users nullable, `deleted_at` (drafts only).

**`invoice_id` is dropped** (E-11), though the ERD carries it directly on `payments` alongside the separate `payment_allocations` table. Keeping both would be two sources for the same fact — a single-invoice field next to a multi-invoice allocation table — exactly the shape E-1 rejected for `orders.supplier_id`. FR-054 requires allocation across *one or more* invoices, which `payment_allocations` alone expresses correctly; a `payments.invoice_id` column would be meaningless for a split payment and redundant for a single-invoice one.

`payment_allocations`: `payment_id` (cascade), `invoice_id` (restrict), `amount` decimal(15,2) > 0, unique `(payment_id, invoice_id)`. No `deleted_at`.

`manual_payment_records`: `payment_id` (cascade, unique), `reference` varchar(255) nullable, `received_at` timestamp nullable. **No `proof_file_path`** — the proof is a Media Library attachment on the payment (`payment-proof`), per Principle IV and the same reasoning as E-4.

> This table exists per the ERD but carries almost nothing once the proof moves to media. It is kept rather than folded into `payments` because it is the extension point D9 anticipates: a future `stripe_payment_records` sits beside it, and a payment's channel detail then has a consistent shape rather than one channel living in the parent table and the other in a child.

`tax_recognition_entries`: `invoice_id` (restrict), `payment_id` (restrict), `journal_entry_id` (FK `journal_entries`, nullable per ERD, restrict), `payment_amount` decimal(15,2), `recognised_tax_amount` decimal(15,2), `recognition_date` date, unique `(invoice_id, payment_id)`. **No `deleted_at`, no `updated_by`** — append-only (FR-062), model refuses `updating` and `deleting`.

**Rules.**

- `sum(allocations) ≤ payment.amount` (FR-054). Remainder = `amount − sum(allocations)`, posted to customer deposits (FR-055).
- Per allocation: `amount ≤ invoice.grand_total − invoice.paid_amount − invoice.credited_amount`, validated and written inside one `lockForUpdate()` on the invoice (R-009).
- Tax per allocation: `round(amount ÷ invoice.grand_total × invoice.tax_total, 2)`, except the allocation that settles the invoice, which recognises `invoice.tax_total − invoice.recognised_tax_amount` (R-008, FR-058).
- **Reversal, not deletion** (FR-060): `reverse()` reverses both journal entries via `JournalPostingService::reverse()`, writes compensating tax recognition, and restores every affected invoice's `paid_amount`, `recognised_tax_amount` and `status`. The original payment, its allocations and its recognition entries all survive as history.
- A payment whose method has `requires_proof` cannot be recorded without media in `payment-proof` (FR-053).

---

## 9. `credit_notes` / `credit_note_lines`

`credit_notes`: `credit_note_number` (unique incl. soft-deleted), `invoice_id` (FK, **nullable** per ERD — standalone credit notes are permitted, restrict), `customer_id` (restrict), `reason` text **required**, `issue_date` date, `subtotal`/`tax_total`/`grand_total` decimal(15,2) default 0, `status` varchar(30) indexed, `confirmed_at` timestamp nullable, `reversed_at` timestamp nullable, `deleted_at` (drafts only).

`credit_note_lines`: `credit_note_id` (cascade), `invoice_line_id` (nullable, restrict), `description` required, `quantity`, `unit_price`, `tax_amount`, `line_total`, `sort_order` (E-9).

**Rules.**

- Per line: `quantity ≤ invoice_line.quantity − sum(confirmed credit line quantities against it)` (FR-064).
- Per document: `grand_total ≤ invoice.grand_total − invoice.credited_amount` (FR-064), checked under the invoice's row lock at confirmation.
- Immutable once `confirmed_at` is set; never deletable once confirmed (FR-065), model-enforced like invoices.
- Confirmation sets `invoice.credited_amount += grand_total` and sets `invoice.status = credited` when the invoice's remaining value reaches zero (FR-067); otherwise the invoice's status continues to follow its balance.
- **No stock relationship of any kind** (FR-070). Returned goods are an inventory concern this feature does not model.
- Media collection `credit-note-pdf`, same semantics as the invoice's.

**Lifecycle**: `draft → confirmed → reversed`, and `draft → cancelled`. Reversal is permission-separated from confirmation (FR-068).

---

## 10. Chart of accounts — one seeded row

`ChartOfAccountsSeeder` gains `'2350' => 'Deferred Sales Tax'` under `2000 Liabilities` (R-007). The seeder is idempotent (`firstOrCreate` on `code`), so re-running it on an existing install adds the account without touching the other 27.

Accounts used by this feature, all pre-existing except `2350`:

| Code | Name | Role |
|---|---|---|
| 1100 / 1110 | Cash on Hand / Bank Account | Candidate collection accounts on a payment method |
| 1200 | Accounts Receivable | The claim |
| 2300 | Sales Tax Payable | Tax **after** collection |
| 2350 | Deferred Sales Tax | **New.** Tax billed, not yet collected |
| 2400 | Customer Deposits | Unallocated payment remainder |
| 4100 | Product Sales | Revenue |

---

## 11. Relationship summary

```
CustomerProfile ─1─┬─*─ Quotation ─1──1─ Order ─*─ InventoryOperation (delivery)
                   │        │                              │
                   │        └── QuotationLine              └─1──1─ Invoice ─*─ InvoiceLine
                   │                                                 │
                   ├─*─ Invoice ────────────────────────────────────┤├─*─ InvoiceConfirmation ─*─ Media
                   │                                                 │
                   ├─*─ Payment ─*─ PaymentAllocation ──────────────┤│
                   │       ├─1──1─ ManualPaymentRecord              ││
                   │       └─*─ Media (proof)                       ││
                   │                                                 │
                   └─*─ CreditNote ─*─ CreditNoteLine ─?─ InvoiceLine┘
                                                                     │
       TaxRecognitionEntry ─*─┬─ Invoice ─────────────────────────────┘
                              ├─ Payment
                              └─ JournalEntry

JournalEntry.source ──morphTo──► Invoice | Payment | CreditNote     ← and nothing else (FR-080)
```

`SalesOpportunity ─?─ Quotation` (FR-025) and `EmployeeProfile ─?─ Quotation` are the two Employees-module links.

---

## 12. Invariants a test must prove, not merely a comment assert

Collected so `tasks.md` can map each to a named test rather than trusting the prose above.

| # | Invariant | Source |
|---|---|---|
| I-1 | No quotation, in any state, has any stock reservation, movement, or on-hand effect | FR-020, SC-007 |
| I-2 | `JournalEntry.source_type` is only `Invoice`, `Payment`, `CreditNote` | FR-080, SC-008 |
| I-3 | An issued invoice, a posted payment and a confirmed credit note cannot be updated or deleted by any path | FR-043/060/065, SC-005 |
| I-4 | Per invoice, `recognised_tax_amount == sum(tax_recognition_entries)`, and `== tax_total` once fully paid | FR-058, SC-004 |
| I-5 | Per invoice, `credited_amount == sum(confirmed credit note grand totals)` | FR-064 |
| I-6 | `paid_amount ≤ grand_total − credited_amount`, always | FR-050 |
| I-7 | Order totals equal source-quotation totals exactly, including when duplicate variants aggregate | FR-029, §6 |
| I-8 | Every posting is balanced and lands in an open period, or the document's state change did not happen | FR-044, R-011 |
| I-9 | `2300 Sales Tax Payable` receives no movement from invoice issuance | FR-045, SC-009 |
| I-10 | Every stock change in the lifecycle has a matching inventory movement raised by `InventoryOperationService` | FR-034, SC-006 |
| I-11 | Neither payment service references a payment channel; nothing else posts a payment | FR-061, R-012 |
| I-12 | Every pre-existing order fulfillment, shipment and delivery test passes unchanged | FR-083 |
