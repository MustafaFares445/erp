# Contract: Proportional Tax Recognition on Collection

**Feature Directory**: `019-sales-lifecycle-payments-credits`

**Created**: 2026-08-23

Implements constitution Principle III's two tax sentences — *"Tax MUST be recognized only when payment is collected; partial payments recognize tax proportionally"* — and `Docs/IMPLEMENTATION_PLAN.md` §9's single acceptance criterion. Owned by `App\Services\Payments\TaxRecognitionService`. Verified by FR-057, FR-058, SC-004 and SC-009.

## 1. When Tax Is Recognised

Never at invoice issuance. Only when an allocation of a payment is applied to an invoice.

| Moment | Deferred tax | Tax payable |
|---|---|---|
| Invoice issued | credited `tax_total` | untouched |
| Allocation applied | debited `recognised` | credited `recognised` |
| Invoice fully collected | net zero for this invoice | holds the invoice's full `tax_total` |
| Payment reversed | credited back | debited back |
| Credit note confirmed | debited `deferredPortion` | debited `recognisedPortion` |

The invariant this table encodes: **an invoice's tax lives in exactly one of the two accounts at any moment, split by how much of the invoice has been collected.** Their sum, per invoice, is always the invoice's `tax_total` less anything credited.

## 2. The Calculation

For an allocation of `A` against an invoice `I`:

```
proportional = round(A ÷ I.grand_total × I.tax_total, 2)
```

`I.grand_total` is never zero for an issued invoice with tax, so no guard is needed there; an invoice with `tax_total = 0` yields `0` and is handled by §4.

## 3. Residue Absorption

The calculation in §2 is applied to every allocation **except the one that settles the invoice**. That one instead recognises the exact remainder:

```
settling      = (I.paid_amount + A) >= (I.grand_total - I.credited_amount)
recognised    = settling
                  ? I.tax_total - I.recognised_tax_amount
                  : proportional
```

`settling` is evaluated **after** this allocation is applied, **inside the same `lockForUpdate()`** on the invoice that validated the allocation (R-009). This is what makes the rule order-independent and concurrency-safe: it asks "is the invoice now settled?", a property of the invoice, rather than "is this the last payment so far?", a property of history that two concurrent allocations would answer differently.

### Why this is necessary rather than tidy

Independent rounding does not sum to the total. An invoice of `1050.00` with `50.00` tax, settled in three payments of `350.00`:

| Payment | Proportional | Running total |
|---|---|---|
| 350.00 | 16.67 | 16.67 |
| 350.00 | 16.67 | 33.34 |
| 350.00 | 16.67 | **50.01** ✗ |

One cent too much recognised, and one cent stranded negative in deferred tax — permanently, for that invoice, and for a large fraction of every invoice the business ever issues. The account never returns to zero for fully collected work, so the drift is not self-correcting and grows monotonically.

With absorption:

| Payment | Recognised | Running total |
|---|---|---|
| 350.00 | 16.67 | 16.67 |
| 350.00 | 16.67 | 33.34 |
| 350.00 (settles) | `50.00 − 33.34` = **16.66** | **50.00** ✓ |

### The cost, stated rather than hidden

The settling payment's recognised tax is not exactly proportional to it — 16.66 against a strictly proportional 16.67. This is deliberate and is the standard treatment: the statutory figure is reported per **invoice**, and the invoice-level total is exact. Any scheme that makes every payment exactly proportional makes the invoice total inexact, and only one of those two can be true.

## 4. Edge Cases

| Case | Behaviour |
|---|---|
| `I.tax_total = 0` | `recognised = 0`. **No** recognition row, **no** journal entry — a zero entry would be unbalanced and carries no information |
| Allocation is `0` | Refused upstream: `payment_allocations.amount` must be > 0 |
| Over-allocation | Refused upstream by `PaymentAllocationService` under the row lock; recognition is never reached |
| One payment, several invoices | One recognition row and one entry **per allocation**, each settling-tested against its own invoice independently |
| Invoice credited after partial collection | `I.credited_amount` reduces the settling threshold, so a smaller payment can settle the invoice and absorb the residue. This is why `settling` compares against `grand_total − credited_amount`, not `grand_total` |
| Invoice credited to zero with tax already recognised | No allocation follows, so no recognition occurs. The recognised portion is reversed by the credit note itself (posting.md §3), not here |
| Payment reversed | Compensating recognition rows written, `I.recognised_tax_amount` restored, both journal entries reversed. Originals survive (FR-062) |
| Invoice settled, then reversed, then re-collected | Absorption re-evaluates from the restored `recognised_tax_amount`, so the second settlement is exact again |

## 5. Records Written

Per allocation with `recognised > 0`, one `tax_recognition_entries` row:

| Column | Value |
|---|---|
| `invoice_id` | the allocated invoice |
| `payment_id` | the payment |
| `journal_entry_id` | the posted recognition entry |
| `payment_amount` | the **allocation** amount, not the payment's total |
| `recognised_tax_amount` | `recognised` from §3 |
| `recognition_date` | `payment.payment_date` |

`payment_amount` holding the allocation rather than the payment total is worth stating, because the ERD's column name suggests otherwise: a payment split across three invoices writes three rows whose `payment_amount` values sum to the allocated total, not three rows each carrying the full payment. Storing the payment total on each would make the rows individually meaningless and collectively triple-counted.

Unique on `(invoice_id, payment_id)`. Append-only — no `deleted_at`, no `updated_by`, and the model refuses `updating` and `deleting` (FR-062).

`invoices.recognised_tax_amount` is incremented in the same transaction. It is a stored aggregate of these rows, kept because §3 reads it inside a hot lock (data-model.md §7), and a test asserts it equals `sum(tax_recognition_entries.recognised_tax_amount)` after every scenario (`I-4`).

## 6. Tests This Contract Requires

| Scenario | Asserts |
|---|---|
| Half payment on a 1050.00 / 50.00 invoice | `recognised = 25.00`; deferred tax debited 25.00; tax payable credited 25.00; invoice `partially_paid` |
| Then the remaining half | total recognised `= 50.00` exactly; invoice `paid` |
| Three equal thirds of 1050.00 / 50.00 | `16.67, 16.67, 16.66`; total `50.00`; **no** residue in deferred tax for that invoice |
| Uneven split, several orderings of the same allocations | total is `50.00` in **every** ordering |
| Zero-tax invoice paid in full | no recognition row, no recognition entry, invoice `paid` |
| One 1000.00 payment allocated 600/300 across two invoices | two recognition rows, each proportional to its own invoice; 100.00 to customer deposits |
| Concurrent allocations that would over-allocate | one refused; recognised tax consistent with the one that succeeded |
| Payment reversed after settling | `recognised_tax_amount` back to 0; both entries reversed; original rows still present |
| Credit note on a half-collected invoice | tax reversal split 50/50 between deferred and payable; entry balances |
| Invoice issued and never paid | tax payable movement is **exactly zero** (`I-9`, SC-009) |

The last row is the one that proves Principle III's tax-timing rule, and it asserts on the account's movement rather than on any service's behaviour — so it keeps holding even if the implementation is later restructured.
