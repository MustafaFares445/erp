# Quickstart: Validating the Sales Module

**Feature Directory**: `019-sales-lifecycle-payments-credits` | **Date**: 2026-08-23 | **Plan**: [plan.md](./plan.md)

How to prove this feature works, phase by phase. Each section is runnable at the end of its phase — you do not need the whole feature to validate part of it, which is the point of the P1 → P3 boundaries.

Details are not repeated here. Postings live in [contracts/posting.md](./contracts/posting.md), tax arithmetic in [contracts/tax-recognition.md](./contracts/tax-recognition.md), states in [contracts/lifecycles.md](./contracts/lifecycles.md), schema in [data-model.md](./data-model.md).

## Prerequisites

- **The governance gate is closed.** ADR 0008 is **Accepted**, constitution 1.8.0 merged, ERD deviations recorded, PRD §11 updated. Nothing below is valid work until then — see plan.md §Phasing, Phase 0.
- Laravel Herd serves the app; no `artisan serve`.
- `barryvdh/laravel-dompdf` installed (owner decision D4, the only new dependency).
- A queue worker running for PDF and email validation, or `QUEUE_CONNECTION=sync` for a quick pass.

## Seed a working environment

```bash
php artisan migrate:fresh --seed
```

Then, per phase, the seeders this feature adds or extends:

```bash
php artisan db:seed --class=SalesPermissionSeeder
```

```bash
php artisan db:seed --class=ChartOfAccountsSeeder
```

The chart seeder is idempotent (`firstOrCreate` on `code`), so re-running it on an existing install adds `2350 Deferred Sales Tax` and leaves the other 27 accounts untouched — worth confirming rather than assuming, since it runs against live account data.

```bash
php artisan db:seed --class=SalesDemoSeeder
```

## Fast feedback while building

Run the phase's own tests, not the suite:

```bash
php artisan test --compact tests/Feature/Sales
```

```bash
php artisan test --compact --filter=TaxRecognition
```

Format before finalising any change:

```bash
vendor/bin/pint --dirty --format agent
```

## Phase 1 — Permissions and roles (US1)

No document needs to exist. Assign each role to a user and walk the matrix in [contracts/permissions.md](./contracts/permissions.md) §2.

What to look for beyond "pages load":

1. A **Sales Officer** sees Quotations but no Issue action on an invoice, and calling `InvoicePostingService::post()` directly is refused — the UI and the service agree.
2. A **Billing Officer** holds exactly one accounting permission (`journal-entry.post-from-source`) and **cannot reach any accounting page**, because `accounting.journal-entry.manage` is not granted, and a direct attempt to draft an unsourced manual entry is refused by the Gate. This is the leak test; see permissions.md §4.
3. Granting a System Admin the Sales Officer role **removes their admin bypass in every other module**. Verify in Inventory, CRM and Purchasing, not just in Sales — the narrowing is a real behavioural change to shipped code.

## Phase 2 — Settings and payment terms (US2)

```bash
php artisan test --compact --filter=PaymentTerm
```

- Create "Net 30" with 30 due days, 5 grace, default. Create a second default → the first stops being default. Exactly one remains.
- Try to select a non-postable or inactive account as the revenue posting account → refused.
- On the Sales Settings form, set the tax rate to 101, then to -1 → both refused by the field's `minValue`/`maxValue` rule, the same convention `PurchaseSettingResource` uses for its own bound.
- Confirm `2350 Deferred Sales Tax` exists and is postable.

## Phase 3 — Quotations (US3)

Quote a customer who is on a pricing tier.

- The line's unit price **defaults from the tier**, and the resolved source is shown. If it defaults to base price for a tiered customer, `PriceResolver` is being called without the customer's `User` — see research.md R-001.
- Override a price below the variant's floor → refused, floor stated.
- Send it. Now try to edit a line → refused.
- Record acceptance → status `accepted`, with the recording user and date stored.
- Set a quotation's expiry in the past, then try to accept → refused, presents as expired.

**The assertion that matters most in this phase**, because it is the one that silently passes if nobody checks:

```bash
php artisan test --compact --filter=QuotationTouchesNoStock
```

No reservation, no movement, no on-hand change, in any quotation state (`I-1`, SC-007). And no journal entry.

## Phase 4 — Order conversion (US4)

Convert the accepted quotation.

- Order totals **equal the quotation's exactly**. Then do it again with a quotation carrying the **same variant on two lines** — the order aggregates them into one line, and the totals must still match exactly (data-model.md §6). This is the only place in the feature where a stored figure is derived, so it is the only place a cent can go missing.
- Convert twice → refused, no second order.
- Convert a rejected, expired or draft quotation → refused.

**Then the regression check, which is the real deliverable of this phase:**

```bash
php artisan test --compact tests/Feature/DeliveryWarehouseAllocationServiceTest.php
```

```bash
php artisan test --compact --filter=OrderFulfillment
```

Every pre-existing fulfillment, shipment and delivery test must pass **unmodified** (`I-12`, FR-083). If one needed editing to accommodate the schema change, the change was not additive and R-004 was violated.

Also open an order created before this feature: it shows no prices, no payment status, and remains fully usable.

## Phase 5 — Delivery Notes (US5)

- Only `operation_type = delivery` operations appear. A receipt or internal transfer never does.
- Complete a delivery from this surface → stock decreases, one inventory movement per line.
- Compare that movement with one raised inside the Inventory module: **identical shape**. If it differs, a second stock path was created and Principle III is broken.
- Zero journal entries, zero tax recognition rows, after completion.
- Try to invoice an operation not in `done` → refused. Invoice one twice → refused.
- A **Sales Officer** cannot complete a delivery — that needs `InventoryPermission`, unchanged (permissions.md §1).

## Phase 6 — Invoices (US6)

- Create from the completed delivery → lines reflect **delivered** quantities and carry prices from the originating order line.
- Invoice a pre-019 order → each line demands a manual price before issuing (FR-040).
- Create a manual service invoice with no delivery → accepted, touches no stock.
- Dated 2026-09-01 with Net 30 → due 2026-10-01. Override to 2026-08-31 → refused.
- Edit a draft freely. Issue it. Now edit → refused. Delete → refused, by every path including force delete.
- PDF job → attached as media. Regenerate → new current file, **previous one retained** (R-006).
- Fail the email job → invoice status unchanged, failure visible.
- Record a receipt with a signature → stored; then try to edit or delete the confirmation → refused (append-only).
- Leave an issued invoice unpaid past due + grace → presents as overdue, with no row rewritten.

## Phase 7 — Invoice posting (US7)

Issue an invoice with subtotal 1000.00 and tax 50.00.

- One posted entry: Dr receivable 1050.00, Cr revenue 1000.00, Cr **deferred tax** 50.00.
- **`2300 Sales Tax Payable` has zero movement.** This is the Principle III check and it asserts on the account, not the service (`I-9`, SC-009).
- The entry links back to the invoice; the invoice surfaces the entry.
- Close the fiscal period covering the invoice date, then issue → refused, invoice **stays draft**, no entry exists.
- Null the revenue account in settings, then issue → refused, message names the missing configuration.
- Make the configured revenue account non-postable, then issue → refused, message names the account.

## Phase 8 — Payments and tax recognition (US8)

The arithmetic is the deliverable here. Work the table in [contracts/tax-recognition.md](./contracts/tax-recognition.md) §6.

- 525.00 against the 1050.00 invoice → paid 525.00, `partially_paid`; Dr bank 525.00 / Cr receivable 525.00; recognition of **25.00**, Dr deferred / Cr payable.
- Remaining 525.00 → total recognised **exactly 50.00**, invoice `paid`.
- **Three equal thirds of 1050.00**: 16.67, 16.67, **16.66**. Total 50.00. If you see 50.01, residue absorption is keyed to a running remainder instead of to settlement — research.md R-008.
- Run the same allocations in a different order → still 50.00. Order-independence is the point.
- A method requiring proof, with no file → refused.
- 1000.00 allocated 600/300 across two invoices → 100.00 to customer deposits; two recognition rows, each proportional to **its own** invoice.
- Allocation above an invoice's balance → refused. Allocations summing above the payment → refused.
- Two concurrent allocations that would over-allocate → one refused, not both applied.
- Edit or delete a posted payment → refused. Reverse it → both entries reversed, tax recognition reversed, every affected invoice restored, originals still present.

```bash
php artisan test --compact --filter=PaymentChannelIsolation
```

Neither payment service mentions a channel, and nothing else posts a payment (`I-11`, FR-061). This is trivially true today with one channel — which is exactly why it is asserted now.

## Phase 9 — Credit notes (US9)

- Draft against two invoice lines with a reason → accepted. Exceed a line's uncredited remainder → refused. Exceed the invoice's uncredited total → refused.
- Confirm against an **unpaid** invoice → Dr revenue, Dr deferred tax, Cr receivable. No tax-payable line.
- Confirm against a **half-paid** invoice → tax splits 50/50 between deferred and payable, and the entry balances (R-010).
- Confirm against a **fully paid** invoice → reversal comes entirely from tax payable; receivable goes negative, leaving a customer credit balance. **Recorded, not blocked.**
- Full credit → invoice `credited`. Partial credit → invoice status keeps following its balance.
- Edit or delete a confirmed note → refused. Reverse it → invoice restored.
- Zero stock change, in every case.

## Phase 10 — The whole-system checks

These are the ones that fail last and matter most.

```bash
php artisan test --compact tests/Feature/Accounting/NoAutomaticPostingTest.php
```

All six original assertions still pass **unmodified**, plus the new one: the ledger's only source types are `Invoice`, `Payment`, `CreditNote`. If any original assertion needed editing, something started posting that must not.

Confirm by inspection that `SalesDemoSeeder` was **not** added to that file's demo-seeder sweep (R-015). Adding it would turn a strong assertion into a weaker one without failing anything.

```bash
php artisan test --compact tests/Unit/ArchTest.php
```

No sales service calls `auth()` (FR-077).

```bash
php artisan test --compact tests/Unit/AdminModuleRegistryTest.php
```

All six Sales items and both new System items resolve; every out-of-scope item still resolves to the placeholder (FR-001, FR-003).

Then the full gate:

```bash
composer test
```

Pint, PHPStan with **no new baseline entries**, type coverage, and the suite at its existing minimum coverage threshold (SC-010). A new baseline entry is a failure, not a finding.

## Manual walk-through

```bash
php artisan db:seed --class=SalesDemoSeeder
```

Open `/admin`, switch to Sales in the top bar, and walk the six items in order: Quotations → Orders → Delivery Notes → Invoices → Payments → Credit Notes. Then open Accounting → Chart of Accounts and read the balances.

The one-screen proof that the feature is correct: **`2350 Deferred Sales Tax` holds exactly the tax of what has been invoiced and not collected, and `2300 Sales Tax Payable` holds exactly the tax of what has been collected.** If those two numbers are right, the module's hardest rule is right.
