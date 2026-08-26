# Contract: Ledger Postings from Sales Documents

**Feature Directory**: `019-sales-lifecycle-payments-credits`

**Created**: 2026-08-23

This contract is exhaustive. **Exactly three events post to the general ledger, and no fourth exists** (FR-080, owner decision D6, ADR 0008 §Two reversals of ADR 0007). Anything not on this page posts nothing.

Every posting goes through `App\Services\Accounting\JournalPostingService::postNew()`, which validates balance, resolves and validates the fiscal period, and writes the `source` morph. The sales services construct lines; they validate neither balance nor period, because duplicating those rules would create a second place for them to live and the copy would drift (R-011).

## 0. Account Resolution

Accounts come from `sales_settings` and from the payment method, never from a hardcoded code. `SalesAccountResolver` fetches each needed account and refuses the posting if it is null, `is_postable = false`, or `is_active = false`, naming the account in the message (FR-007).

| Role | Source | Seeded default |
|---|---|---|
| Receivable | `sales_settings.receivable_account_id` | `1200 Accounts Receivable` |
| Revenue | `sales_settings.revenue_account_id` | `4100 Product Sales` |
| Deferred tax | `sales_settings.deferred_tax_account_id` | `2350 Deferred Sales Tax` **(new, R-007)** |
| Tax payable | `sales_settings.tax_payable_account_id` | `2300 Sales Tax Payable` |
| Customer deposits | `sales_settings.customer_deposits_account_id` | `2400 Customer Deposits` |
| Collection | `payment_methods.chart_account_id` | per method — `1100` or `1110` |

Resolution happens **inside** the posting transaction. An account made non-postable between page load and submit fails the posting rather than posting to it.

## 1. Invoice Issued — `InvoicePostingService::post()`

**Trigger**: `sales.invoice.issue` on a `draft` invoice. Atomic with the state change: `issued_at` is set and the entry is posted in one `DB::transaction()`. Any failure leaves the invoice a `draft` (FR-044).

**Entry** — `source` = the `Invoice`, `entry_date` = `invoice_date`, description names the invoice number and customer:

| Line | Account | Debit | Credit |
|---|---|---|---|
| 1 | Receivable | `grand_total` | |
| 2 | Revenue | | `subtotal` |
| 3 | Deferred tax | | `tax_total` |

Line 3 is omitted when `tax_total` is zero — `JournalPostingService` requires every line to carry exactly one of debit or credit, so a zero line is not merely noise but invalid. The entry then has two lines, which still satisfies the two-line minimum.

**Balance**: `grand_total = subtotal + tax_total` by construction from the lines, so the entry balances by the same arithmetic that produced the totals.

**The rule this posting exists to enforce**: line 3 credits **deferred** tax, never `2300 Sales Tax Payable`. Crediting tax payable here would recognise tax at issuance, which constitution Principle III forbids (FR-045). `I-9` in data-model.md §12 is the test that proves it, and it asserts on the account, not on the service.

## 2. Payment Collected — `PaymentPostingService::post()`

**Trigger**: `sales.payment.record`, after `PaymentAllocationService` has validated and written the allocations under each invoice's row lock (R-009). One transaction covers allocation, both entries below, and every invoice status update (FR-056, FR-068).

### 2a. Collection entry

`source` = the `Payment`, `entry_date` = `payment_date`:

| Line | Account | Debit | Credit |
|---|---|---|---|
| 1 | Collection (the method's account) | `payment.amount` | |
| 2 | Receivable | | `sum(allocations)` |
| 3 | Customer deposits | | `amount − sum(allocations)` |

Line 3 is omitted when the payment is fully allocated, which is the common case. When present it is the unallocated remainder (FR-055) — money received that is not yet applied to a claim, which is a liability, not revenue and not a receivable reduction.

**Balance**: line 1 equals lines 2 + 3 by the definition of the remainder.

### 2b. Tax recognition entry

Posted **per allocation**, one entry each, `source` = the `TaxRecognitionEntry`:

| Line | Account | Debit | Credit |
|---|---|---|---|
| 1 | Deferred tax | `recognised` | |
| 2 | Tax payable | | `recognised` |

`recognised` is computed by [tax-recognition.md](./tax-recognition.md). An allocation whose `recognised` is zero — an invoice with no tax — posts **no** entry and writes **no** recognition row; a zero-value entry would be an unbalanced two-line entry and would carry no information.

Tax recognition is deliberately a **separate entry per allocation** rather than extra lines on 2a. A payment settling three invoices produces one collection entry and up to three recognition entries, each traceable to the invoice whose tax it recognised. Folding them into 2a would make the tax total attributable to the payment but not to any invoice, and the statutory figure is reported per invoice.

## 3. Credit Note Confirmed — `CreditNotePostingService::confirm()`

**Trigger**: `sales.credit-note.confirm` on a `draft` credit note. Atomic with `confirmed_at`, with the invoice's `credited_amount` update, and with the invoice's status transition (FR-066).

**Entry** — `source` = the `CreditNote`, `entry_date` = `issue_date`:

| Line | Account | Debit | Credit |
|---|---|---|---|
| 1 | Revenue | `subtotal` | |
| 2 | Deferred tax | `deferredPortion` | |
| 3 | Tax payable | `recognisedPortion` | |
| 4 | Receivable | | `grand_total` |

The tax split follows the invoice's recognition ratio at confirmation time (R-010):

```
recognisedShare   = invoice.tax_total > 0
                      ? invoice.recognised_tax_amount ÷ invoice.tax_total
                      : 0
recognisedPortion = round(creditNote.tax_total × recognisedShare, 2)
deferredPortion   = creditNote.tax_total − recognisedPortion
```

Lines 2 and 3 are each omitted when their amount is zero — which is the normal case at both extremes: an unpaid invoice omits line 3, a fully paid one omits line 2.

**Balance**: `deferredPortion + recognisedPortion = creditNote.tax_total` exactly, because the second is derived by subtraction rather than by a second rounding. `subtotal + tax_total = grand_total` by construction. Deriving both portions by independent rounding would occasionally produce an unbalanced entry that `JournalPostingService` would refuse — turning a rounding detail into a user-visible failure.

**A standalone credit note** (`invoice_id` is null, permitted by the ERD) has no recognition ratio to consult. `recognisedShare` is 0, so its whole tax reverses from deferred tax.

## 4. Reversals

Reversal posts no new *kind* of entry. It calls `JournalPostingService::reverse()`, which mirrors the original's lines and links the reversal to it through the same `source` morph 018 built. This feature adds no reversal-specific accounting.

| Action | Reverses | Also restores |
|---|---|---|
| `PaymentService::reverse()` | The collection entry and every recognition entry | Each invoice's `paid_amount`, `recognised_tax_amount` and `status`; compensating recognition rows are written, the originals survive |
| `CreditNoteService::reverse()` | The confirmation entry | The invoice's `credited_amount` and `status` |

Neither deletes anything. The original payment, its allocations, its recognition rows, and the credit note all survive as history, per Principle III's prohibition on physically deleting a confirmed financial document (FR-060, FR-065).

`JournalPostingService::reverse()` already refuses a second reversal of the same entry (`EntryAlreadyReversed`), so double-reversal is prevented by the accounting layer rather than re-checked here.

## 5. What Does Not Post

Exhaustive, and each line is an assertion a test makes rather than an omission:

| Event | Posts | Authority |
|---|---|---|
| Quotation created, sent, decided, converted | nothing | FR-020, FR-032 |
| Order created, priced, fulfilled, shipped | nothing | FR-032 |
| Delivery operation readied, dispatched, completed, cancelled | nothing | FR-035 |
| Stock movement, adjustment, transfer, reservation | nothing | ADR 0007 |
| Purchase order approved, sent, received | nothing | **ADR 0006, unrelaxed** |
| Supplier confirmation recorded | nothing | ADR 0006 |
| Ticket payment link settled | nothing | **ADR 0004, unrelaxed** |
| Spare part consumed on a service record | nothing | ADR 0004 |
| Invoice PDF generated or regenerated | nothing | — |
| Invoice email sent | nothing | — |
| Invoice receipt confirmed | nothing | — |
| Invoice becoming overdue | nothing | derived state, no row is written |
| Payment or credit note **drafted** | nothing | posting is on record/confirm |

**No cost of goods sold is posted anywhere.** A delivery reduces stock and touches no account, so the ledger in this phase carries revenue without matched cost. ADR 0007 excludes cost accounting and inventory valuation; ADR 0008 does not relax that. This is recorded in spec §Assumptions as an accepted limitation of the phase rather than absorbed silently.

## 6. Enforcement

`tests/Feature/Accounting/NoAutomaticPostingTest.php` is **tightened, never relaxed** (R-015, FR-080). All six existing assertions stay and stay unmodified. Two are added:

1. `JournalEntry::query()->distinct()->pluck('source_type')` contains only `Invoice::class`, `Payment::class` and `CreditNote::class`, after a scenario that exercises every document in the system.
2. The structural scan of `app/Observers` and `app/Listeners` for `JournalPostingService` and `JournalEntry` keeps passing — because all three posting services are called from **Filament actions**, never from an observer or a listener. This is a design constraint on the implementation, not an observation about it.

The existing demo-seeder sweep asserts an empty ledger after `InventoryDemoSeeder` and `SupportDemoSeeder`. **`SalesDemoSeeder` must not be added to that test.** The sweep's value is that it names only the seeders whose modules post nothing; adding a seeder that does post would silently convert a strong assertion into a weaker one.
