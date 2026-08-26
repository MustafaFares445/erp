# Research: Sales Module Completion

**Feature Directory**: `019-sales-lifecycle-payments-credits` | **Date**: 2026-08-23 | **Plan**: [plan.md](./plan.md)

Phase 0 output. Every unknown in plan.md §Technical Context is resolved here; none reaches Phase 1 as `NEEDS CLARIFICATION`. Owner decisions D2–D9 are inputs to this research, not subjects of it.

---

## R-001 — Who owns the price on a quotation line

**Decision**: `PriceResolver::candidates()` supplies the default and `assertAtOrAboveFloor()` guards the override. The quotation stores the resulting `unit_price` and the `ResolvedPriceSource` it came from, and never recomputes it after the quotation is sent.

**Rationale**: `PriceResolver` already encodes the full tier precedence — customer-specific, then product-scoped cheapest, then general, then base — and `PricingTierDiscountCalculator` already applies the discount arithmetic. Reimplementing any part of that in a sales service would be a second pricing path, and pricing divergence is silent: two surfaces quoting different prices for the same customer looks like a data problem for months before anyone finds the second code path.

Storing the source alongside the amount matters for a reason FR-015 only implies: a sales officer looking at a line needs to know *why* it costs what it costs before deciding whether to discount it. `ResolvedPrice` already carries `source`, `pricingTier`, `discountType`, `discountValue` and `minimumPrice`, so the information is free.

**One real friction, resolved**: `PriceResolver::resolve()` takes a `?User`, but the ERD keys `quotations.customer_id` to `customer_profiles`. The resolver's tier lookups are keyed on `customer_user_id`, so it genuinely needs the `User`. `CustomerProfile` has a `user()` belongs-to, so the service resolves `$quotation->customer->user` and passes that. Changing `PriceResolver` to accept a profile was rejected: it would alter a class the CRM and catalog surfaces already call, for the convenience of a new caller.

**Alternatives considered**: Free-text price with no default — rejected, discards the whole pricing-tier feature at the one place it matters most. Recomputing the price at conversion or invoicing time — rejected, it would let a tier change silently reprice a document the customer already accepted, and FR-023 exists to prevent exactly that.

---

## R-002 — Where the floor guard applies, and where it must not

**Decision**: `assertAtOrAboveFloor()` runs when a quotation line is created or edited **while the quotation is a draft**, and nowhere else in the lifecycle.

**Rationale**: The floor is a control on what a salesperson may offer. Once an offer has been sent, accepted, converted, delivered and invoiced, re-applying the floor would refuse to invoice a price the company already committed to — turning a pricing policy into a data-integrity failure. The edge case in the spec ("a quotation's price floor changes after it was sent") is only benign because the guard is confined to drafting.

`PriceFloorOverride` exists and is honoured by whatever `ProductVariant::min_price` currently reflects; this feature reads the guard and does not reinterpret the override records.

**Alternatives considered**: Guard at every write — rejected as above. Guard only at send-time — rejected, it lets a draft accumulate below-floor lines and then fails as one opaque error at the end instead of at the line the author is editing.

---

## R-003 — Delivery Notes without a `delivery_notes` table

**Decision**: A Filament resource whose model is `InventoryOperation`, scoped in `getEloquentQuery()` to `operation_type = 'delivery'`, with its own navigation label, columns, infolist and actions. No new model, no new table, no new stock write.

**Rationale**: This is owner decision D3, and the codebase was already built for it. `InventoryOperation.source_document` is documented in its own docblock as *"a purchase order for a receipt, a sales delivery note for a delivery"*, and `OrderFulfillmentService` already writes `source_document_type => Order::class` on the delivery it raises. The delivery record exists; it has simply never had a Sales-facing surface.

A parallel table is worse than redundant. It would be a second delivery record able to disagree with the one that actually decrements stock, and reconciling them would require exactly the kind of divergent path Principle III prohibits.

**Two consequences worth stating plainly.** First, the surface inherits `OperationStage` (`draft → waiting → ready → done | canceled`), not the ERD's `delivery_notes` status catalogue (`draft, confirmed, delivered, customer_confirmed_received, …`). The ERD catalogue is not implemented and is recorded as superseded by D3 — attempting to map one onto the other would invent states the operation cannot be in. Second, `customer_confirmed_received` and `employee_confirmed_delivered` have no home on the delivery: the built `Shipment` model already carries `confirmed_by_type`/`confirmed_by_id`/`confirmed_at` for delivery confirmation, and invoice-side receipt confirmation is a separate concern (FR-049). Neither is re-invented here.

**Alternatives considered**: A database view — rejected, Filament resources want an Eloquent model and a view adds a migration-managed object for no gain. A `DeliveryNote` model extending `InventoryOperation` with a global scope — rejected, two models over one table with different scopes is a well-known source of surprising query behaviour, and `newModelQuery()` bypasses the scope silently.

---

## R-004 — Extending `orders` without breaking six services

**Decision**: Two additive migrations. `orders` gains `quotation_id` (nullable FK), `payment_term_id` (nullable FK), `subtotal`/`tax_total`/`grand_total` (`decimal(15,2)`, nullable) and `payment_status` (nullable string). `order_lines` gains `unit_price`/`tax_amount`/`line_total` (`decimal(15,2)`, nullable). No column is renamed, dropped, or made non-nullable, and no data is backfilled.

**Rationale**: `orders` and `order_lines` carry live rows read by `OrderFulfillmentService`, `DeliveryWarehouseAllocationService`, `WarehouseStockService`, the `Shipment` flow, the `SupplierConfirmation` `confirmable` morph, and the `InventoryOperation.source_document` morph. Any non-additive change risks all six.

**Nullable rather than `default 0` is a correctness decision, not a convenience.** A pre-existing order line has no price because pricing did not exist when it was created. Writing `0.00` would assert the goods were free, and that assertion would then flow into any future revenue figure that read historical orders. `null` says "unknown", which is true. The cost is a guard at invoicing time (FR-040): a line with a null price requires a manual price before the invoice can be issued. That is the correct place to pay the cost — at the point a human is already deciding what to bill.

**`supplier_id` is deliberately not added**, though the ERD lists it. Spec 017 already records a supplier's answer against a customer order through the `SupplierConfirmation` `confirmable` morph. A scalar FK would be a second source for the same fact, able to disagree with the confirmation history.

**Alternatives considered**: A `sales_orders` table — rejected at 018 as D2; it would orphan the existing `source_document` morph. Backfilling prices from `ProductVariant::base_price` — rejected, it fabricates financial history from today's catalogue.

---

## R-005 — Document numbering

**Decision** One `DocumentNumberGenerator` in `App\Services\Sales`, parameterised by prefix and model, using the established mechanism: read `max(number)` under `lockForUpdate()` inside the creating transaction, increment, format `%s%06d`. Prefixes `QT-`, `INV-`, `PAY-`, `CN-`. A unique index on each number column, including over soft-deleted rows.

**Rationale**: Four generators in this codebase already solve this — `operation_number`, `order_number`, `purchase_order_number`, `entry_number` — and `PurchaseOrderNumberGenerator`'s docblock explicitly says a fifth approach would be gratuitous divergence. A UUID would be race-free without the lock and is rejected for the same reason it was there: a number has to be readable aloud to a customer.

**One deviation from precedent**: a single parameterised generator rather than four near-identical classes. Four documents in one feature makes the duplication obvious in a way it was not when each module added one. `withTrashed()` in the max query is what stops a number being reissued after a draft is deleted — and drafts *are* deletable here (FR-043, FR-065), so this is load-bearing rather than defensive.

**Alternatives considered**: A shared `document_sequences` table — rejected, it adds a table and a second failure mode to a problem a row lock already solves. Per-year sequences — rejected, not specified anywhere in the canonical set.

---

## R-006 — Generated PDFs: Media Library, not a file table

**Decision**: Invoice and credit-note PDFs are Media Library attachments on their document, in a `singleFile()`-per-version collection on the `local` disk. No `invoice_files` table.

**Rationale**: Constitution Principle IV is explicit — generated PDFs MUST use Media Library, and custom per-feature file tables MUST NOT be created absent a proven need. `invoice_files` is exactly such a table.

**The precedent that appears to point the other way, and why it does not.** `InventoryExport` carries a `file_path` column for a generated file, and so does `EmployeeReportExport`. Those are not file tables: they are *job* records — a queued export with a type, filters, a status and a failure reason, whose output file is incidental to the record's purpose. An `invoice_files` row would have no purpose except to be a file. The distinction is the one Principle IV is drawing, and following the export precedent here would be reading a local convention over an explicit constitutional rule.

**Regeneration semantics.** FR-047 requires regeneration to replace the current file *and keep the previous one*. `singleFile()` deletes the old media on add, so it cannot express this alone. The collection is therefore not `singleFile()`; instead each generation adds a new media item and the newest by `created_at` is "current". This is also what makes the audit requirement in FR-076 meaningful — a regenerated invoice PDF that silently destroyed its predecessor would leave the log referring to a file that no longer exists.

**Alternatives considered**: `invoice_files` per the ERD — rejected by Principle IV, recorded as ERD deviation E-4. Rendering on demand with no stored file — rejected, an issued invoice's PDF is evidence and must not change when the template does.

---

## R-007 — Why `2350 Deferred Sales Tax` is not optional

**Decision**: Add one row to `ChartOfAccountsSeeder`: `2350 Deferred Sales Tax` under `2000 Liabilities`, alongside the existing `2300 Sales Tax Payable`.

**Rationale**: Principle III requires tax to be recognised only on collection. Invoice issuance must therefore credit *something* for the tax that is billed and not yet collected, and that something cannot be `2300 Sales Tax Payable` — crediting it is precisely what "recognising tax at issuance" means. The seeded chart has no other candidate. Without this account the correct posting is unrepresentable, so the naive implementation is also the constitutionally forbidden one.

This is additive reference data in a seeder, not a schema change and not an ERD divergence. It follows the same reasoning that made `account_types` seeded rather than user-created in 018: the accounting requirement is universal, so there is no legitimate reason for the module to work without it.

**Alternatives considered**: Post the tax to `2300` at issuance and reverse the unrecognised part — rejected, it reports a liability that is not yet owed, in direct violation of Principle III. Omit tax from the issuance entry so receivable equals subtotal only — rejected, the receivable would no longer equal what the customer owes, breaking the subledger tie-out that SC-003 measures. Let the accountant pick any liability account in settings with no seeded default — rejected, a fresh install would then fail to issue its first invoice.

---

## R-008 — Proportional tax recognition without rounding drift

**Decision**: Per allocation, `recognised = round(allocation ÷ invoice.grand_total × invoice.tax_total, 2)`. The allocation that brings the invoice's outstanding balance to zero instead recognises `invoice.tax_total − sum(already recognised)`, absorbing the entire residue.

**Rationale**: Rounding each allocation independently does not sum to the invoice's tax total — an invoice of 1050.00 with 50.00 tax settled in three payments of 350.00 recognises 16.67 three times, which is 50.01. Left alone, every invoice in the system leaves a cent or two stranded in the deferred-tax account forever, and the account never returns to zero for fully-collected business. FR-058 exists because of this and SC-004 measures it.

**Keying absorption to the settling allocation rather than to a running remainder** is a design decision made during this research, not a restatement of the spec. A running-remainder approach ("recognise the remainder if this is the last one *so far*") is order-dependent and wrong under concurrency. "Is the invoice now fully settled?" is a property of the invoice after this allocation, evaluated under the same row lock that validated the allocation, and is therefore order-independent.

**A consequence to accept rather than hide**: the residue is absorbed by whichever payment happens to settle the invoice, so that payment's recognised tax is not exactly proportional to it. This is standard practice and it is the only choice that keeps the invoice-level total exact. The invoice, not the payment, is the unit the statutory figure is reported at.

**Alternatives considered**: Banker's rounding per allocation — rejected, reduces drift without eliminating it. Store recognised tax to four decimals — rejected, the ledger is two-decimal and the drift would simply move to the posting. Recognise all tax on first payment — rejected, contradicts "partial payments recognize tax proportionally" verbatim.

---

## R-009 — Allocation validation and balance derivation are one operation

**Decision**: `PaymentAllocationService` locks each target invoice with `lockForUpdate()`, derives its outstanding balance from `grand_total − paid_amount − credited_total` inside that lock, validates the allocation against it, writes the allocation, updates `paid_amount` and `status`, and returns whether the invoice is now settled. No separate `InvoiceBalanceService`.

**Rationale**: The plan's first draft had both. They read the same rows under the same lock, and splitting them means either taking the lock twice or passing a lock-scoped balance across a service boundary where its freshness is no longer guaranteed. The concurrent-overallocation edge case in the spec is only refusable if validation and write are inside one lock; a balance service that answered "outstanding is 500" and then let a caller write 500 twice is exactly the race the edge case describes.

Returning "is now settled" from the same call is what makes R-008's residue absorption safe — the tax service does not have to re-derive settlement from data that may have moved.

**Alternatives considered**: A read-only balance accessor on the `Invoice` model for display — kept, and explicitly *not* used for validation. Optimistic locking on a version column — rejected, it turns a refusal into a retry loop for no benefit at this concurrency.

---

## R-010 — Credit note reversal split by recognition ratio

**Decision**: When a credit note is confirmed against an invoice, its tax is debited across `2350 Deferred Sales Tax` and `2300 Sales Tax Payable` in proportion to how much of that invoice's tax is currently recognised: `recognisedShare = invoice.recognisedTax ÷ invoice.tax_total`.

**Rationale**: The two accounts hold the same invoice's tax at different stages. Crediting the customer without reversing from the right one leaves a permanent misstatement — reversing only from deferred tax on a fully paid invoice would drive deferred tax negative while leaving tax payable overstated. The split follows the invoice's actual state at confirmation time, which is the only defensible basis.

**Rounding**: the deferred portion is `round(creditTax × recognisedShare, 2)` and the payable portion is `creditTax − deferredPortion`, so the two always sum to the credit's tax exactly and the entry balances. Deriving both by independent rounding would occasionally produce an unbalanced entry, which `JournalPostingService` would refuse — turning a rounding detail into a user-visible failure.

**Alternatives considered**: Always reverse from deferred tax — rejected, wrong on any paid invoice. Always reverse from tax payable — rejected, wrong on any unpaid invoice, and it would recognise tax on money never collected. Refuse to credit a paid invoice — rejected, it is a normal business event and the spec's edge cases require it to be recorded rather than blocked.

---

## R-011 — Posting is atomic with the state change, and posting failures are not partial

**Decision**: Each of the three posting events wraps its state change and its `JournalPostingService::postNew()` call in one `DB::transaction()`. A closed fiscal period, a non-postable configured account, or an unbalanced entry aborts the whole transaction, leaving the document in its prior state.

**Rationale**: Principle III requires accounting operations to be transactional, and FR-044 requires an invoice that fails to post to remain a draft. The failure mode this prevents is the worst available: an issued invoice with no ledger entry is invisible to accounting while being final to the customer.

`JournalPostingService` already validates balance and period, and already refuses to post into a closed period — so the sales services must not re-validate either. Duplicating those checks would create two places where the rule lives, and the copy would drift.

**Alternatives considered**: Post asynchronously via a queued job — rejected, it makes "issued but unposted" a normal reachable state. Pre-validate the period before issuing to give a friendlier error — rejected as a *substitute* for the transaction; acceptable as a UI hint, but the transaction remains the guarantee.

---

## R-012 — Keeping one posting path when Stripe does not exist yet

**Decision**: `PaymentPostingService` and `TaxRecognitionService` take a `Payment` and know nothing about how the money arrived. Channel-specific data lives on the payment's method and on the manual-payment detail; neither service branches on it. An architecture test asserts that neither class contains a channel identifier and that no other class calls `JournalPostingService` for a payment.

**Rationale**: Principle IV forbids divergent code paths per payment channel by name. D9 defers Stripe, which defers the temptation rather than removing it — the natural shape when Stripe arrives is `if ($channel === 'stripe')`, and by then the manual path will be the one with all the tests. The constraint has to be structural and asserted now, while there is only one channel and the assertion is trivially true.

**What this buys concretely**: a future Stripe feature adds a webhook, a `stripe_payment_records` row, and a `PaymentMethod` whose collection account is the Stripe clearing account. It adds no posting code and no tax code.

**Alternatives considered**: An interface per channel with a shared abstract base — rejected, it is the divergent path with extra ceremony; the base class becomes the place channel-specific behaviour hides. Build Stripe now — rejected by D9 and by the absence of any customer-facing surface to initiate a payment from.

---

## R-013 — Quotation decision recorded by an admin, not by a customer

**Decision**: A `recordDecision()` service call capturing outcome, decision date, note and the recording user, callable only from the dashboard by a holder of the decision permission. No public route, no signed link, no token.

**Rationale**: Owner decision D8, and it follows ADR 0006's supplier-confirmation precedent exactly: the customer's answer arrives by phone or email and the dashboard is where it is recorded. The alternative would be this feature's only unauthenticated surface, and every ADR to date has excluded public routes.

**Separating the decision permission from the manage permission** (FR-072) is not granularity for its own sake. Recording "the customer accepted" is an assertion about a third party that commits the company to a price; drafting a quotation is not. The same reasoning 018 applied to separating `journal-entry.manage` from `journal-entry.post`.

**Alternatives considered**: A signed public accept/reject link, like the existing signed voice-note media route — genuinely viable and rejected by D8; it would need its own ADR justification and belongs with `010-customer-app-flows`.

---

## R-014 — One tax rate, and why the per-line amount is what gets stored

**Decision**: `sales_settings.default_tax_percent` supplies the rate; each line stores a computed `tax_amount` that the author may override. No rate is stored on the line.

**Rationale**: Owner decision D7. Storing the *amount* rather than the rate is what makes the decision cheap to revisit: if mixed statutory rates later require a `tax_definitions` catalogue, every historical line already carries the money figure that was actually billed, and no backfill is needed to interpret it. Storing a rate per line instead would leave historical lines pointing at a rate table that did not exist when they were written.

Zero-rated and exempt items are expressed today by overriding the line's tax to `0.00`. That is less expressive than a rate catalogue and is recorded in the spec's Assumptions as the limitation it is.

**Alternatives considered**: A `tax_definitions` table — the more capable design, rejected by D7; it has no ERD table and the navigation item stays a placeholder. A rate column per line — rejected as above. A rate on the customer profile — rejected, not specified anywhere and it conflates a customer attribute with a product-and-jurisdiction one.

---

## R-015 — Which existing test guards what, after this feature

**Decision**: `tests/Feature/Accounting/NoAutomaticPostingTest.php` is tightened, never relaxed. All six existing assertions stay true and unmodified — orders, deliveries, ticket payments, inventory adjustments, the demo-seeder sweep, and the structural observer/listener scan. One assertion is added: the ledger's `source_type` values are exactly `Invoice`, `Payment` and `CreditNote`, and nothing else.

**Rationale**: This is the mechanism keeping ADR 0006's Purchasing prohibition and ADR 0004's ticket-payment exclusion honest, and FR-080 requires it to get stronger rather than weaker. Two details make it survive intact:

- The three posting services are called from **Filament actions**, never from observers or listeners. The structural scan of `app/Observers` and `app/Listeners` for `JournalPostingService`/`JournalEntry` therefore keeps passing and keeps meaning something.
- The demo-seeder sweep asserts an empty ledger after `InventoryDemoSeeder` and `SupportDemoSeeder`. A new `SalesDemoSeeder` **must not** be added to that test, and the sweep must keep naming only the seeders whose modules post nothing.

**Alternatives considered**: Deleting the test now that posting is authorised — rejected outright; it would remove the only enforcement of two live ADR prohibitions. Rewriting it as "some documents post" — rejected, an assertion that cannot fail is not a test.
