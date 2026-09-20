# Codex Implementation Prompt — Customer App Backend Domain & Filament Dashboard

You are working as a Principal Laravel Engineer / ERP Domain Architect in:

- Workspace: `ierp-new`
- Project root: `C:\laragon\www\ierp-new`
- Target branch: `dev`

Your mission is to implement the backend-domain and Filament-dashboard changes required for the approved Customer App V1 behavior.

## Mandatory source files

Read these before changing code:

1. `product-specs/mobile-apps/customer/CUSTOMER_APP_BACKEND_DASHBOARD_IMPLEMENTATION_PLAN.md`
2. `product-specs/mobile-apps/customer/CUSTOMER_APP_V1_ADOPTED_DECISIONS.md`
3. `product-specs/mobile-apps/customer/CUSTOMER_APP_PAYMENT_AND_INVOICE_ARCHITECTURE.md`
4. `product-specs/mobile-apps/customer/CUSTOMER_APP_CURRENT_CODE_FINDINGS.md`
5. Current implementation under `app/`, `database/`, `tests/`, and Filament resources.

The implementation plan is the execution contract. Current code remains authoritative for existing invariants.
## Hard scope boundary

Implement ONLY:
- domain models/enums/migrations
- domain services
- accounting/payment-provider core
- events/jobs where appropriate
- Filament resources/pages/actions/forms/infolists/tables
- settings/diagnostics
- factories/seeders when required
- tests
- documentation consistency needed by these changes

DO NOT implement:
- `routes/api.php`
- customer API routes
- mobile authentication/token endpoints
- Sanctum customer auth
- API controllers
- API Resources/transformers
- guest catalog HTTP endpoints
- customer quotation/order/payment endpoints
- Stripe webhook HTTP controller/route
- push-token endpoints
- Flutter/mobile UI

Build the domain so future API controllers can remain thin adapters.
## Worktree safety

Before changing anything:
1. Show current git branch.
2. Show `git status --short --branch`.
3. Identify existing modified/untracked files that are not part of this mission.
4. Do not revert, overwrite, stash, or discard concurrent work.
5. Re-read a file immediately before editing it if it is already modified.

The repository may contain parallel work in CRM, Inventory, Orders, timeline UI, tests, and documentation.

Do not merge branches, reset the worktree, or commit unrelated files unless explicitly requested.

## Engineering rules

- Follow existing Laravel 13 / PHP 8.4 conventions.
- Follow existing Filament 5 patterns.
- Keep strict types.
- Prefer enums/value objects over magic strings.
- Preserve existing service ownership instead of duplicating logic.
- Keep Filament actions thin.
- Use transactions and row locks for state-changing financial/commercial workflows.
- Make external-provider settlement idempotent.
- Preserve audit trails and historical evidence.
- Never trust client-style IDs, prices, totals, currencies, or payable amounts.
- Never mutate posted accounting evidence merely to simplify a new workflow.
- Do not weaken existing authorization or accounting guards.
- Reuse `PriceResolver`, `QuotationService`, `QuotationConversionService`, `ShipmentService`, `InvoiceService`, `PaymentService`, `PaymentPostingService`, `PaymentAllocationService`, `TaxRecognitionService`, `TicketTriageService`, and `InventoryReturnService` where their responsibilities already apply.
- Do not create a second pricing engine, order lifecycle, inventory return engine, or ticket billing engine.

## Required behavior

Implement the approved domain foundation for:

1. Customer self-registration/admin provisioning consistency, including username.
2. Customer approval/review lifecycle.
3. Legal/company change requests requiring admin approval.
4. Customer quotation requests / quote-cart backend domain.
5. Quotation Accept / Reject / Request Changes evidence and revision flow.
6. Customer direct-order capability flag, default disabled.
7. Customer shipment confirmation evidence with required delivery photos.
8. Electronic invoice behavior with no customer receipt-confirmation dependency.
9. Stripe provider transaction domain and service abstractions.
10. Stripe-backed payment settlement into existing ERP Payment/accounting services.
11. Pre-invoice payments as Customer Deposits.
12. Automatic later application of Customer Deposits to issued invoices.
13. Correct accounting transfer: Debit Customer Deposits / Credit Accounts Receivable.
14. Correct tax recognition and invoice/order balance synchronization.
15. Stripe-aware refunds without bypassing ERP Refund approval.
16. Stripe settlement for chargeable Support Tickets.
17. Customer-reported support impact separated from internal priority.
18. Customer Return Request domain before internal Inventory Return.
19. Filament reconciliation/operations UI for all new workflows.
20. Customer 360 visibility across commercial, financial, shipment, support, warranty, and return records.

## Stripe rule

Do not make the mobile/app success screen the payment authority.

The provider transaction state is separate from ERP `Payment.status`.
A verified provider-success path may settle exactly one ERP Payment.

If payment occurs before an invoice exists:
- post it as Customer Deposit.

If an issued invoice already exists:
- allocate the payment to the invoice.

When a later invoice is issued:
- automatically apply eligible existing customer deposits.
Do not install Laravel Cashier unless the current architecture genuinely requires subscription/billing abstractions.
Prefer the official Stripe PHP SDK behind a narrow provider abstraction.

Do not store card data or Stripe secrets in database records.

## Invoice rule

Do not add a customer “Confirm Invoice Received” requirement.

An issued invoice is an electronic ERP document:
- visible in Admin
- available for future Customer App consumption
- downloadable as PDF
- balance derived from total, credits, and payments

Keep payment balance separate from invoice lifecycle status.

## Implementation method

Work batch-by-batch in the exact order defined in the implementation plan.

For each batch:
1. Inspect current models/services/Filament/tests involved.
2. Identify invariants and existing conventions.
3. Implement migrations/models/enums.
4. Implement/refactor services.
5. Implement Filament UI.
6. Add focused tests.
7. Run focused tests and static analysis for touched code.
8. Fix regressions before moving to the next batch.
Do not attempt all phases in one giant uncontrolled edit.

If a plan item conflicts with a current invariant:
- preserve the invariant,
- adapt the implementation,
- document the reason,
- do not silently bypass it.

## Important dashboard requirements

### Customers
Admin customer creation must create `User + CustomerProfile` as one workflow.
Do not require administrators to pre-create a Customer User and then select it.
Require unique username and login email.
Expose approval state, review actions, and direct-order capability.

### Quote Requests
Add operational Filament screens for submitted quote requests and conversion to real Quotations.
Pricing must be resolved by existing server pricing rules during conversion.

### Quotations
Show customer-response history and support Request Changes without editing frozen Sent quotations.
Create revisions/requotes instead.

### Shipments
Show customer confirmation source, time, and delivery-photo evidence.

### Payments
Show provider transaction, ERP payment, allocations, customer deposit remainder, and reconciliation state.
### Invoices
Show total, paid, credited, outstanding, related deliveries, and payment/deposit allocation.
De-emphasize legacy receipt-confirmation UI; do not delete historical evidence without a migration reason.

### Support
Keep Triage responsible for warranty/service-path/payment-required decisions.
Show customer impact separately from internal priority.
Show Stripe/provider settlement status for chargeable service.

### Returns
Customer Return Requests must be separate from the internal Inventory Return resource.
Only conversion should enter the existing inventory return workflow.

## Accounting safety

Before refactoring `JournalPostingService`, inspect:
- authorization model
- source-document posting permissions
- audit expectations
- created_by/updated_by constraints
- architecture tests

Do not impersonate an arbitrary admin for automated provider settlement.

Create a narrow trusted orchestration/internal posting path while preserving existing dashboard/manual authorization behavior.

All deposit application, payment settlement, and refund operations must be:
- transactional
- idempotent
- auditable
- retry-safe
## Migration compatibility

The project is pre-production and may use `php artisan migrate:fresh --seed`, but migrations still need proper foreign keys, indexes, uniqueness, and deterministic seed behavior.

Do not rewrite historical financial totals.
Do not invalidate old shipment confirmations because new customer confirmations require photos.
Existing manual payments remain valid and provider-neutral.

## Testing requirements

Add focused tests for every domain transition and Filament action introduced.

At minimum cover:
- customer provisioning and username uniqueness
- approval/review lifecycle
- legal change requests
- quote request conversion/pricing
- quotation response/revision rules
- shipment photo-evidence confirmation
- Stripe/provider transaction idempotency
- one provider success -> one ERP Payment
- prepayment -> Customer Deposit
- deposit -> invoice allocation
- Dr Deposit / Cr AR posting
- tax recognition
- partial/multi-payment behavior
- refund idempotency
- support payment settlement
- return-request conversion
- Filament resource actions and displays
## Validation gates

Run focused tests throughout the work.

Before declaring completion, run:
- `composer test:lint`
- `composer test:types`
- relevant targeted Pest suites
- `composer test`

The final state must preserve the repository's 100% type/code coverage expectations and all tests must be green.

If the full suite exposes unrelated concurrent-work failures, clearly separate those from failures caused by this implementation and do not modify unrelated code without evidence.

## Completion report

At the end provide:
1. Implemented batches/phases.
2. Migrations/models/enums added.
3. Services added/refactored.
4. Filament resources/actions changed.
5. Stripe/payment/accounting behavior implemented.
6. Tests added.
7. Commands run and exact results.
8. Remaining backend/dashboard gaps.
9. Explicit confirmation that no customer/mobile API routes/controllers were implemented.
10. Git status and list of files changed by this mission.

Do not stop at scaffolding. Complete each started batch to production-quality behavior and tests before moving forward.
