# Contract: Document Lifecycles

**Feature Directory**: `019-sales-lifecycle-payments-credits`

**Created**: 2026-08-23

State machines for the five documents, and the delivery stage they borrow. Each enum implements `canTransitionTo()`, following `OperationStage`'s precedent, so legality is a property of the enum rather than a set of `if` statements spread across services.

Two conventions hold everywhere and are not repeated per document:

- **A `draft` is freely editable and deletable. Every later state freezes the document's commercial content.** Freezing is enforced at both the service and model layer, following the `MaintenanceRecord` / `JournalEntry` defence-in-depth precedent.
- **A state reached by posting to the ledger is never left by deletion.** Correction is reversal or a credit note (Principle III).

---

## 1. Quotation — `QuotationStatus` (FR-019)

```
        ┌──────────────► cancelled ◄──────────┐
        │                    ▲                │
     draft ───send──► sent ──┤                │
                        │    │                │
              ┌─────────┼────┴────────┐       │
              ▼         ▼             ▼       │
          accepted   rejected      expired ───┘
              │
           convert
              │
              ▼
    converted_to_delivery
```

| From | To | Guard |
|---|---|---|
| `draft` | `sent` | ≥ 1 line; every line at or above its floor; `sales.quotation.manage` |
| `draft` | `cancelled` | `sales.quotation.manage` |
| `sent` | `accepted` | `expires_at` is null or ≥ today; `sales.quotation.decide` |
| `sent` | `rejected` | `sales.quotation.decide` |
| `sent` | `expired` | decision attempted past `expires_at` |
| `sent` | `cancelled` | `sales.quotation.manage` |
| `accepted` | `converted_to_delivery` | `converted_order_id` is null; `sales.quotation.convert` |
| `accepted` | `cancelled` | not yet converted |
| `rejected`, `expired`, `converted_to_delivery`, `cancelled` | — | terminal |

**Immutability**: from `sent` onward, `customer_id`, every line, and every total are frozen (FR-023).

**`expired` is both stored and derived.** Stored when a decision is attempted past expiry; derived for presentation on any `sent` quotation whose `expires_at` has passed. No scheduled command sweeps for expiry — this feature adds none, and a quotation that is functionally expired but stored as `sent` is still correctly refused acceptance by the guard above. The derived presentation exists so the list does not lie to the reader.

**Nothing here touches stock, in any transition** (FR-020, `I-1`).

---

## 2. Order — existing `status`, plus `payment_status` (FR-026)

The built `status` column keeps its values and its `'ready'` default. **This feature adds no transition to it and removes none.** Conversion creates an order that enters the existing flow exactly as a directly-created order does (FR-030).

`payment_status` is a **separate axis**, nullable, and null for every order that predates this feature:

```
null (pre-019) │ unpaid ──► partially_paid ──► paid
```

Derived from the invoices raised against the order's deliveries, not set by hand. An order with no invoice is `unpaid`.

Fulfillment state and money state are independent — an order can be `completed` and `unpaid`, or `pending_supplier_confirmation` and `unpaid`, and both are normal. This is why the ERD carries two columns and why collapsing them would be wrong.

**Blocking rule**: no invoice may be issued against an order awaiting a supplier's answer (FR-031). The existing `pending_reason` and `SupplierConfirmation` path is read, never modified.

**No transition posts to the ledger** (FR-032).

---

## 3. Delivery — existing `OperationStage`, unchanged

```
draft ──► waiting ──► ready ──► done
   └─────────┴──────────┴────► canceled
```

This is `App\Enums\OperationStage` as built, reached through the Delivery Notes surface. **The surface adds no state, no transition, and no guard**, and calls `InventoryOperationService::markReady()`, `dispatch()`, `complete()` and `cancel()` unchanged (FR-034).

`in_transit` is unreachable for a delivery — it belongs to internal transfers only, per `OperationStage::canTransitionTo()`. A delivery goes `ready → done` directly.

**The ERD's `delivery_notes` status catalogue** (`draft, confirmed, delivered, customer_confirmed_received, employee_confirmed_delivered, converted_to_invoice, cancelled`) **is not implemented.** Owner decision D3 removed the table it described; mapping its states onto `OperationStage` would invent states the operation cannot occupy. Where its states do have homes:

| ERD state | Where it actually lives |
|---|---|
| `draft`, `cancelled` | `OperationStage::Draft`, `OperationStage::Canceled` |
| `confirmed`, `delivered` | `OperationStage::Ready`, `OperationStage::Done` |
| `customer_confirmed_received`, `employee_confirmed_delivered` | The built `Shipment` model's `confirmed_by_type` / `confirmed_by_id` / `confirmed_at` |
| `converted_to_invoice` | Derived: an `Invoice` exists with this `inventory_operation_id` |

**Invoicing gate** (FR-036): stage is `done`, type is `delivery`, and no invoice already references it. The last is enforced by a unique index, not only by a check.

**No transition posts to the ledger, and none recognises tax** (FR-035).

---

## 4. Invoice — `InvoiceStatus` (FR-041)

```
draft ──issue──► issued ──send──► sent
  │                │                │
  │                ├────────────────┴──► customer_received
  │                │                └──► employee_confirmed_received
  │                │
  │                ├──► partially_paid ──► paid
  │                └──► credited
  └──► cancelled
```

| From | To | Guard |
|---|---|---|
| `draft` | `issued` | ≥ 1 line; **no line with a null unit price** (FR-040); source order not pending supplier confirmation (FR-031); posting succeeds (posting.md §1); `sales.invoice.issue` |
| `draft` | `cancelled` | `sales.invoice.manage` |
| `issued` | `sent` | PDF exists; `sales.invoice.send` |
| `issued`, `sent` | `customer_received` / `employee_confirmed_received` | a confirmation of that type exists; `sales.invoice.confirm-receipt` |
| any issued state | `partially_paid` | `0 < paid_amount < grand_total − credited_amount` |
| any issued state | `paid` | `paid_amount >= grand_total − credited_amount` |
| any issued state | `credited` | credit notes cover the full remaining value (FR-067) |

**`overdue` is not in this machine.** It is derived — an issued, unpaid invoice past `due_date + grace_days` presents as overdue without any row being rewritten (FR-011). Storing it would need a scheduled sweep this feature does not add, and would let the stored value fall out of step with the date.

**An issued invoice is never `cancelled`.** Cancelling a claim the customer has received is what a credit note is for. Only a draft cancels.

**Immutability from `issued`** (FR-042): `customer_id`, every line, and every total frozen. **Never deletable from `issued`** (FR-043) — the model refuses `deleting`, so soft delete, force delete and cascade all refuse.

Status transitions driven by money (`partially_paid`, `paid`, `credited`) are written by `PaymentAllocationService` and `CreditNoteService` inside the invoice's row lock, never by a Filament action.

---

## 5. Payment — `PaymentStatus` (FR-052)

```
draft ──record──► posted ──reverse──► reversed
  │
  └──► cancelled
```

Deliberately shorter than the ERD's catalogue (`pending, processing, succeeded, failed, cancelled, refunded, partially_refunded`). Owner decision D9 defers Stripe, and `processing`, `failed`, `refunded` and `partially_refunded` are all states only an online channel reaches — a manual payment either was received or was not. Implementing unreachable states would be implementing a Stripe integration's state machine without the integration. When Stripe arrives it extends this enum; the manual states do not change.

| From | To | Guard |
|---|---|---|
| `draft` | `posted` | amount > 0; allocations valid under each invoice's lock; proof present if the method requires it; both entries post (posting.md §2); `sales.payment.record` |
| `draft` | `cancelled` | `sales.payment.record` |
| `posted` | `reversed` | not already reversed; `sales.payment.reverse` |

**Immutability from `posted`** (FR-060): amount, method, date, and every allocation frozen; never deletable. Reversal restores each affected invoice's `paid_amount`, `recognised_tax_amount` and `status`, and leaves the payment, its allocations and its recognition rows in place as history.

---

## 6. Credit Note — `CreditNoteStatus` (FR-063)

```
draft ──confirm──► confirmed ──reverse──► reversed
  │
  └──► cancelled
```

| From | To | Guard |
|---|---|---|
| `draft` | `confirmed` | ≥ 1 line; each line within its invoice line's uncredited remainder; document within the invoice's uncredited total; posting succeeds (posting.md §3); `sales.credit-note.confirm` |
| `draft` | `cancelled` | `sales.credit-note.manage` |
| `confirmed` | `reversed` | not already reversed; `sales.credit-note.reverse` |

**Immutability from `confirmed`** (FR-065): frozen and never deletable. Confirmation updates the invoice's `credited_amount` and possibly its status, inside the same transaction as the posting.

**No transition touches stock** (FR-070, `I-1`). A credit note is a financial correction; returned goods are a separate inventory concern this feature does not model.

---

## 7. Cross-Document Invariants

Properties of the machines together, each mapping to a test in data-model.md §12.

| # | Invariant | Enforced by |
|---|---|---|
| L-1 | A quotation converts to at most one order | Unique `converted_order_id` **and** unique `orders.quotation_id` |
| L-2 | A delivery operation is invoiced at most once | Unique `invoices.inventory_operation_id` |
| L-3 | An invoice reaches `issued` only through a posting that succeeded | One transaction (posting.md §1) |
| L-4 | An invoice's paid and credited amounts never exceed its grand total | Row lock (data-model.md §7) |
| L-5 | No document reaches a posting state without an open fiscal period | `JournalPostingService`, not re-checked here |
| L-6 | Stock changes only in §3, and only through `InventoryOperationService` | §1, §4, §5, §6 have no stock relationship at all |
| L-7 | Tax reaches `2300 Sales Tax Payable` only via §5 | posting.md §1 credits deferred tax; §2b is the only path to payable |
