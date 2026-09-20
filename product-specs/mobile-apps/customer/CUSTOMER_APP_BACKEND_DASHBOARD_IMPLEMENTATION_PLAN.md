# Customer App V1 — Backend Domain & Filament Dashboard Implementation Plan

**Status:** Implementation plan  
**Date:** 2026-09-19  
**Target branch:** `dev`  
**Scope:** Laravel domain/backend structure, services, persistence, events/jobs, accounting integration, and Filament dashboard only.  
**Explicitly excluded:** mobile API routes/controllers/resources/auth tokens, Flutter code, public customer endpoints, Stripe webhook HTTP controller/route.

## 1. Objective

Prepare the existing ERP backend and Filament dashboard for the approved Customer App V1 business behavior without implementing the customer-facing API yet.

The implementation must preserve the current ERP domain rules and make the future mobile/API layer thin: future controllers should call these services rather than reimplement commercial, accounting, support, shipment, or return logic.

## 2. Authoritative inputs

Read before implementation:
- `product-specs/mobile-apps/customer/CUSTOMER_APP_CURRENT_CODE_FINDINGS.md`
- `product-specs/mobile-apps/customer/CUSTOMER_APP_V1_ADOPTED_DECISIONS.md`
- `product-specs/mobile-apps/customer/CUSTOMER_APP_PAYMENT_AND_INVOICE_ARCHITECTURE.md`
- `product-specs/mobile-apps/customer/CUSTOMER_APP_BEHAVIOR_DRAFT.md`

Current code is authoritative when documentation conflicts with implemented invariants.
## 3. Current-code constraints to preserve

- `CustomerOnboardingService` already creates `UserType::Customer + CustomerProfile` atomically for Join Us.
- Admin `CreateCustomer` currently creates only `CustomerProfile` and expects an existing Customer user.
- `PriceResolver` already owns customer-specific pricing.
- `QuotationService` owns quotation pricing/lifecycle.
- `QuotationConversionService` converts accepted quotations into confirmed orders.
- `OrderWorkflowService` derives customer-facing fulfillment/payment milestones.
- `ShipmentService` owns arrival confirmation and warranty activation.
- `InvoiceService` creates invoices from completed deliveries and posts issued invoices.
- `PaymentService`, `PaymentPostingService`, `PaymentAllocationService`, and `TaxRecognitionService` own customer payment accounting.
- Unallocated posted payments already credit Customer Deposits.
- Posted `PaymentAllocation` rows are immutable.
- `TicketTriageService` owns warranty, billing decision, and PendingPayment/Live transition.
- `InventoryReturnService` owns physical customer returns and must remain internal.
- No Stripe SDK/provider integration currently exists.
- No customer API implementation is part of this plan.

## 4. Design principles

1. Extend existing domain services; do not create parallel customer-only business logic.
2. Keep authorization at dashboard/API boundaries and invariants inside domain services.
3. Use immutable evidence for decisions, payments, delivery confirmation, and financial postings.
4. Never trust prices, totals, customer IDs, or payable amounts from a future client.
5. Preserve historical records when introducing new statuses/relationships.
6. New provider operations must be idempotent.
7. Do not mutate posted accounting documents to “make Stripe fit.”
8. Filament actions must remain thin adapters over services.
# Phase 1 — Customer Account Provisioning & Review

## 5. Add explicit customer review state

Introduce `CustomerApprovalStatus`:
- Pending
- ChangesRequested
- Approved
- Rejected

Add to `customer_profiles`:
- `approval_status`
- `reviewed_by` nullable FK to users
- `reviewed_at` nullable datetime
- `review_note` nullable text
- `allow_direct_orders` boolean default false

Backfill:
- existing active customers -> Approved
- existing inactive, non-deleted customers -> Pending
- keep `is_active` for operational compatibility

Invariant:
- Approved normally implies `is_active=true`
- Pending/ChangesRequested/Rejected normally imply `is_active=false`
- approval changes must go through a service, not direct dashboard field writes.

## 6. Unify account creation

Create/refactor a `CustomerAccountProvisioningService` that can create:
- Customer `User`
- CustomerProfile
- customer code
- legal/company details
- contact/accountant details
- location
- onboarding documents

Use a transaction and preserve the existing customer-code uniqueness behavior.
The service should support source context:
- `join_us`
- `dashboard`

The dashboard flow must require:
- account name
- unique username
- unique login email
- initial password
- company details
- customer contact/location data

Do not let Filament directly create CustomerProfile before the User exists.

Refactor `CustomerOnboardingService` to reuse the shared provisioning internals rather than duplicating user/profile creation.

## 7. Filament Customer creation changes

Replace the current “Customer account” existing-user select on create with an Account section:
- Name
- Username
- Login email
- Initial password
- Confirm password

On edit:
- show linked username/login email
- allow safe account maintenance through explicit actions/forms
- never silently replace `user_id`

Add review section/actions:
- Approve
- Request Changes
- Reject
- Reactivate where business rules allow

Record reviewer, timestamp, reason/note, and activity log.

Add `allow_direct_orders` toggle under a clearly named commercial capability section, default off.
# Phase 2 — Legal/Company Change Requests

## 8. Add CustomerProfileChangeRequest domain

Create:
- `CustomerProfileChangeRequest`
- `CustomerProfileChangeRequestStatus` enum
- factory/tests

Suggested fields:
- customer_id
- requested_by_user_id nullable
- status: Pending/Approved/Rejected/Cancelled
- requested_changes JSON
- reason/note nullable
- reviewed_by nullable
- reviewed_at nullable
- review_note nullable
- applied_at nullable
- source_channel

Use a private media collection for replacement legal documents.

The model is a proposal/evidence record; it must not directly modify CustomerProfile on save.

## 9. Change request service

Create `CustomerProfileChangeRequestService`:
- create proposal
- approve and atomically apply permitted changes
- reject
- cancel before review
- prevent re-approval/rejection
- audit every transition

Separate fields into:
- direct-edit fields: normal contact/delivery data
- approval-required fields: legal/company identity and legal documents

No customer API entry point in this phase; service and dashboard are prepared for future callers.
## 10. Filament change-request review

Add a Customer Change Requests resource or relation manager.

Admin must see:
- customer
- old values
- requested values
- old/new legal document evidence
- status
- requester/source
- timestamps

Actions:
- Approve
- Reject

Approval must call the service and update CustomerProfile + documents transactionally as far as database operations allow.

# Phase 3 — Quote Request Cart Domain

## 11. Add customer quotation-request models

Create:
- `CustomerQuotationRequest`
- `CustomerQuotationRequestLine`
- `CustomerQuotationRequestStatus`

Suggested statuses:
- Submitted
- UnderReview
- Quoted
- Rejected
- Cancelled

Header fields:
- request number
- customer_id
- delivery_address_id nullable
- notes
- status
- submitted_at
- reviewed_by/reviewed_at
- resulting_quotation_id nullable
- source_channel
Line fields:
- product_variant_id
- requested_quantity
- requested_unit_id
- customer_note nullable
- product/variant descriptive snapshot if needed for historical readability

Do **not** store a client-trusted sales price on the request.

## 12. Quotation request service

Create `CustomerQuotationRequestService`:
- submit(customer, lines, context)
- markUnderReview(actor)
- reject(actor, reason)
- convertToQuotation(actor)

Validation:
- customer approved/active
- variant operational
- sales unit valid
- quantity positive
- delivery address belongs to customer when provided

Conversion:
- call existing `QuotationService`
- let `PriceResolver` resolve current customer pricing
- create quotation lines from requested variants/quantities
- link request <-> quotation
- transition request to Quoted
- audit source and actor

Do not duplicate quotation totals/tax/pricing logic.
## 13. Filament Quote Requests

Add a Filament resource:
- List
- View
- optional Create-on-behalf-of-customer for phone/manual requests
- no arbitrary editing after Submitted

List columns:
- request number
- customer
- item count
- submitted_at
- status
- linked quotation

View:
- customer details
- requested delivery address
- lines
- notes
- status history/audit context

Actions:
- Start Review
- Convert to Quotation
- Reject

Conversion redirects to the created quotation.

# Phase 4 — Customer Quotation Responses

## 14. Preserve immutable customer decision evidence

Add an append-only `QuotationResponse` model rather than relying only on mutable projection columns.

Fields:
- quotation_id
- response_type
- note nullable
- responded_by_user_id nullable
- recorded_by_user_id nullable
- responded_at
- source_channel
Response types:
- Accepted
- Rejected
- ChangesRequested

Rules:
- only Sent quotation can receive a new customer response
- expired quotation cannot be accepted
- Reject requires reason
- ChangesRequested requires useful note
- one decisive current response per sent quotation lifecycle
- history remains append-only

Keep existing quotation decision columns for compatibility/projection where useful.

## 15. Extend quotation status safely

Add `ChangesRequested` to `QuotationStatus`.

Recommended transitions:
- Draft -> Sent / Cancelled
- Sent -> Accepted / Rejected / Expired / ChangesRequested / Cancelled
- ChangesRequested -> no content mutation; create a requote/new draft
- Accepted -> ConvertedToDelivery / Cancelled under existing rules

The original sent quotation remains frozen.

When changes are requested:
- create response evidence
- mark original ChangesRequested
- Sales uses Requote/Create Revision to create a new Draft linked via `requoted_from_id`
- re-resolve current prices

Do not edit the original sent quotation lines.
## 16. Refactor quotation decision orchestration

Introduce a service such as `QuotationResponseService` that owns:
- accepted response
- rejected response
- changes-requested response
- audit metadata
- event dispatch
- opportunity close-won/close-lost behavior where appropriate

Refactor existing dashboard `QuotationService::recordDecision` or its caller to reuse this orchestration.

Important:
- ChangesRequested must **not** close the opportunity lost.
- Accepted should preserve existing close-won behavior.
- Rejected should preserve existing close-lost behavior.

## 17. Filament quotation changes

Update Quotation actions/infolist:
- Record Accepted
- Record Rejected
- Record Changes Requested
- View response history
- show source: dashboard / future customer app
- show who responded vs who recorded it
- prominent “Create Revised Quotation” action for ChangesRequested
- retain Convert to Order action only when Accepted

Add linked Quote Request where present.
# Phase 5 — Order Commercial Capability Preparation

## 18. Direct-order capability flag

Use `customer_profiles.allow_direct_orders` as an explicit commercial capability.

For V1:
- default false
- quotation-led ordering remains default
- no customer direct-order API is implemented

Add a reusable service/policy helper such as `CustomerOrderingPolicyService`:
- canRequestQuotation(customer)
- canDirectOrder(customer)

This prepares future API behavior without implementing it.

Dashboard should display the capability clearly on Customer view/edit.

# Phase 6 — Shipment Arrival Evidence

## 19. Require photos for customer-confirmed arrival

Current `ShipmentService::confirmByCustomer()` confirms arrival without evidence.

Introduce a dedicated service boundary for customer arrival evidence, e.g. `ShipmentArrivalConfirmationService`, while preserving current admin/system confirmation paths.

Customer confirmation rules:
- shipment must be InTransit
- completed delivery must exist
- customer must own the shipment/order
- at least one delivery photo required
- confirmation is idempotent
Use a dedicated private media collection on Shipment or a dedicated confirmation evidence model.

Preferred design:
- `ShipmentArrivalConfirmation` append-only record
- shipment_id
- customer_id
- confirmed_by_user_id nullable
- confirmed_at
- source_channel
- optional note
- media collection: `delivery-confirmation-photos`

Then:
- mark Shipment Arrived through existing shipment domain logic
- preserve `confirmed_by_type/id/at` projection fields
- activate warranty once
- never overwrite confirmation evidence

## 20. Filament shipment changes

Shipment View must show:
- confirmation source
- customer/company
- confirmation timestamp
- delivery evidence photo gallery
- linked order/delivery
- warranty activation result/summary if useful

Admin confirmation should not require customer photos.

Do not reuse generic shipment attachments as customer delivery evidence if that makes provenance ambiguous.
# Phase 7 — Electronic Invoice Behavior

## 21. Remove customer receipt confirmation from product flow

Do not add or require a customer “Confirm Invoice Received” action.

Preserve existing receipt-confirmation data/service for legacy/internal evidence if currently used; do not delete it casually.

Update Filament Invoice presentation so the primary customer-facing/accounting facts are:
- Issued/Sent lifecycle
- total
- credited
- paid
- outstanding
- electronic PDF/document availability
- related deliveries/order
- payment allocations

De-emphasize the Receipt Confirmation section unless needed by internal users.

## 22. Derived payment state

Continue using `InvoiceBalanceService` rather than inventing a Paid invoice lifecycle status.

“Paid / Partially Paid / Outstanding” remains a derived balance axis.

Order `payment_status` continues to be derived from issued invoices.
# Phase 8 — Stripe Provider Core

## 23. Dependency and configuration

Use the official Stripe PHP SDK (`stripe/stripe-php`) unless a repository-wide standard is introduced before implementation.

Do not introduce Laravel Cashier unless subscriptions/customer billing abstractions are actually needed.

Add environment/config entries for:
- Stripe secret key
- Stripe publishable key if later needed by client
- webhook secret placeholder for future integration
- enabled/test-mode indicators as appropriate

Secrets must remain in environment/config, never database plaintext or Filament forms.

Filament may display configuration readiness, never secret values.

## 24. Extend payment method domain

Add Stripe as a supported `PaymentMethod.type`.

A Stripe payment method must:
- be active
- use `requires_proof=false`
- point to the correct Stripe/bank collection chart account

Update Payment Method Filament options and seed/configuration strategy.
## 25. Add provider transaction model

Create `PaymentTransaction` (or equivalent provider-neutral name).

Suggested fields:
- customer_id
- payment_id nullable
- provider
- purpose_type / purpose_id (Order, Invoice, TicketPaymentLink)
- provider_customer_reference nullable
- checkout_session_id nullable unique
- payment_intent_id nullable unique
- provider_charge_id nullable
- amount_minor
- currency
- status
- idempotency_key unique
- last_provider_event_id nullable
- failure_code/message nullable
- succeeded_at/cancelled_at/refunded_at nullable
- metadata JSON
- timestamps

Status enum:
- Pending
- RequiresAction
- Succeeded
- Failed
- Cancelled
- PartiallyRefunded
- Refunded

Provider transaction history is evidence; do not collapse it into Payment.status.
## 26. Stripe service layer, no HTTP endpoints

Create service classes that can be called later by API/webhook adapters:
- `StripeCheckoutService`
- `StripePaymentReconciliationService`
- `StripeRefundService`
- provider payload/value objects as needed

This phase may implement SDK calls/services and unit/feature tests around them, but MUST NOT add:
- API route
- mobile controller
- customer auth endpoint
- webhook HTTP route/controller

If network calls are difficult to test, wrap Stripe SDK behind a small provider interface and fake it in tests.

## 27. Payment purpose rules

Support these payment purposes:
- Order prepayment/deposit
- outstanding Invoice
- chargeable Support Ticket

Server-side services determine:
- payable amount
- currency
- whether payment is currently allowed

Never accept the final payable amount from a future client as authoritative.
# Phase 9 — Provider Settlement into ERP Payments

## 28. Separate provider confirmation from ERP posting

A successful provider transaction must settle exactly once into ERP `Payment`.

Create a `ProviderPaymentSettlementService` that:
- locks transaction
- verifies Succeeded state
- checks no existing linked Payment
- creates/posts ERP Payment
- uses Stripe payment method
- stores provider reference
- allocates immediately when paying an issued invoice
- leaves remainder as Customer Deposit when not allocated
- links transaction to Payment
- audits source_channel=stripe
- is idempotent

Do not set `Payment::Posted` by direct model update.

## 29. Accounting authorization refactor

Current `JournalPostingService` requires a real `User` and performs Gate checks.

Automated Stripe settlement must not:
- impersonate a random admin
- call `auth()`
- bypass Gate through model writes

Refactor carefully so:
- dashboard/manual operations remain user-authorized
- trusted document/provider orchestration can invoke an internal posting engine through an explicit system/integration context
- audit still identifies source channel/provider
- created_by/updated_by schema constraints remain valid
Before changing JournalPostingService, inspect migrations and existing architecture tests.

Preferred direction:
- extract non-authorization ledger posting engine from current private internals
- keep current public user-authorized facade behavior intact
- add a narrow trusted source-document posting path for provider/accounting orchestration

Do not weaken manual journal permissions.

# Phase 10 — Customer Deposit Application

## 30. Add deposit allocation orchestration

Current behavior correctly posts unallocated money to Customer Deposits.

Missing behavior: a posted prepayment must later be consumed when an invoice is issued.

Create `CustomerDepositApplicationService`.

It must:
- lock invoice and candidate posted payments
- select unallocated customer deposit amounts deterministically, oldest first unless business rules say otherwise
- ensure same customer/currency
- never exceed invoice outstanding
- create allocation evidence
- update invoice paid balance through existing balance logic
- sync order payment status
- recognize tax through existing tax service
- post the deposit transfer journal
Required journal when applying an existing deposit:
- Debit Customer Deposits
- Credit Accounts Receivable

The service must be transactional and idempotent.

Because posted Payment allocations are immutable, add new allocations only; never edit/delete old ones.

## 31. Trigger deposit application

Hook deposit application into the invoice issuance workflow after the invoice is successfully issued/posted.

Prefer explicit orchestration or an event/listener with strong transactional guarantees.

Do not hide critical financial failures in a fire-and-forget queue.

If automatic application fails:
- invoice remains issued
- failure is surfaced/logged
- dashboard shows reconciliation warning
- safe retry is available

## 32. Partial payments and multiple invoices

Support:
- full prepayment
- partial deposit
- multiple deposits
- partial delivery invoices
- consolidated invoices
- remaining balance paid later

Allocation must never exceed:
- unallocated posted customer deposit
- invoice outstanding amount.
# Phase 11 — Stripe Refund Integration Core

## 33. Provider-aware refunds

Existing ERP Refund remains the business authority.

Add provider reference fields or a linked provider refund transaction model.

`StripeRefundService` should:
- accept only an ERP-approved refund eligible for Stripe
- identify original successful Stripe PaymentTransaction
- request provider refund
- record provider refund state/reference
- mark ERP Refund Paid only after confirmed provider success
- support partial refund where ERP rules permit
- be idempotent

No refund HTTP/API endpoint in this phase.

## 34. Filament refund/payment reconciliation

Enhance existing payment/refund dashboards to show:
- provider
- Stripe PaymentIntent/session/charge references
- linked ERP Payment
- linked Order/Invoice/Ticket
- provider amount/currency
- provider status
- ERP status
- reconciliation mismatch
- refund references

Never expose card data.

# Phase 12 — Payment Reconciliation Dashboard

## 35. New Payment Transactions resource

Create a Filament resource for provider transactions.

List filters:
- provider status
- ERP settlement status
- customer
- purpose
- date
- mismatch/error

View sections:
- provider identifiers
- customer
- purpose link
- amount/currency
- provider lifecycle
- ERP Payment link
- invoice allocations/customer-deposit remainder
- errors
- event/reconciliation metadata
Safe actions:
- Reconcile/Retry Settlement when provider success is already verified
- Refresh provider state if implemented safely
- Open linked records

Do not provide “Force Successful” or arbitrary provider-state mutation.

## 36. Payments dashboard changes

Update Payment infolist/table:
- provider source
- linked PaymentTransaction
- allocated amount
- customer deposit remainder
- provider reference
- purpose
- reconciliation state

Keep current accounting/tax sections.

# Phase 13 — Chargeable Support via Stripe
## 37. Preserve Ticket triage ownership

`TicketTriageService` remains responsible for deciding:
- no charge
- charge waived
- payment required

When payment is required:
- create/retain TicketPaymentLink
- keep Ticket in PendingPayment
- provider transaction can later target the TicketPaymentLink

Do not make Stripe decide ticket pricing.

## 38. Settle ticket from provider service

Refactor `TicketPaymentService` so settlement can be orchestrated from a verified provider transaction without duplicating lifecycle rules.

On successful settlement:
- mark TicketPaymentLink settled
- persist Stripe/provider reference
- transition Ticket PendingPayment -> Live
- start SLA via existing SlaService
- audit source_channel=stripe

Existing dashboard manual settlement, if retained, must be clearly distinguished from Stripe provider settlement.

## 39. Ticket dashboard changes

Show:
- payment required amount/currency
- provider transaction status
- Stripe reference
- settlement timestamp
- payment failure/retry state

Triage form continues to own amount/currency decision.

# Phase 14 — Customer Support Impact

## 40. Separate customer impact from internal priority

Add a customer-facing impact enum, e.g.:
- ServiceUnavailable
- Degraded
- GeneralQuestion
Store optional `customer_impact` on Ticket.

Do not let future customer input write `TicketPriority` directly.

Create `TicketPriorityResolver` or mapping service that proposes/defaults internal priority from:
- customer impact
- ticket type
- SLA policy
- optional admin override

Support staff retain final internal priority control.

Update Ticket Filament:
- show customer-reported impact
- show internal priority separately
- triage can adjust internal priority.

# Phase 15 — Customer Return Requests

## 41. Add pre-inventory return request domain

Create:
- `CustomerReturnRequest`
- `CustomerReturnRequestLine`
- `CustomerReturnRequestStatus`

Suggested statuses:
- Submitted
- UnderReview
- Approved
- Rejected
- Converted
- Cancelled

Header:
- request number
- customer_id
- order_id/delivery_id
- reason
- notes
- status
- submitted_at
- reviewed_by/reviewed_at
- review_note
- converted_inventory_return_id nullable
- source_channel

Lines:
- original delivery line
- product variant
- requested quantity
- serial/lot references where applicable
- customer reason
- evidence photos/media

This request does **not** move stock.

## 42. Return request service

Create `CustomerReturnRequestService`:
- submit
- startReview
- approve/reject
- convertToInventoryReturn

Conversion must call existing `InventoryReturnService::createCustomerReturn()` and then use its existing line/allocation rules.

Do not duplicate stock posting, inspection, disposition, lot, or serial logic.

## 43. Filament Return Requests

Create separate resource before internal Inventory Returns.
Admin sees:
- customer/order/delivery
- requested items
- requested quantities
- evidence photos
- reason
- review status
- linked Inventory Return

Actions:
- Start Review
- Approve / Convert to Inventory Return
- Reject

After conversion, inventory staff continue in existing Returns resource.

# Phase 16 — Notifications & Domain Events

## 44. Add domain events now, delivery adapters later

Create/extend events for:
- CustomerAccountApproved
- CustomerAccountChangesRequested
- CustomerQuotationRequestSubmitted
- QuotationChangesRequested
- QuotationAccepted
- ShipmentCustomerConfirmed
- PaymentTransactionSucceeded/Failed
- CustomerDepositApplied
- CustomerReturnRequestSubmitted/Updated
- TicketCustomerImpactRecorded

Reuse existing NotificationDispatcher where appropriate for admin/database/mail notifications.

Do not implement mobile push-token API in this phase.

It is acceptable to define notification event keys/templates so the future mobile adapter can consume them.

# Phase 17 — Filament Customer 360 View

## 45. Upgrade Customer view

Add/extend relation managers or sections for:
- account/review state
- direct-order capability
- change requests
- quote requests
- quotations/responses
- orders
- shipments/delivery evidence
- invoices
- payments/deposits
- Stripe transactions
- return requests
- tickets
- maintenance
- equipment/warranty

Keep the page scannable; use tabs/relations rather than one giant infolist.

# Phase 18 — Settings & Operational Controls

## 46. Sales/payment settings

Add configuration for business behavior, not secrets:
- Stripe enabled
- default Stripe payment method
- auto-apply customer deposits enabled
- optional minimum/maximum online payment rules if needed
- quotation-request default behavior
- direct-order capability remains customer-specific
Stripe secret/webhook credentials remain environment/config only.

Add dashboard diagnostics:
- Stripe configured/not configured
- selected payment method valid/invalid
- payment method chart account configured
- Customer Deposits account configured
- Accounts Receivable configured

Do not save secret keys in database.

# Phase 19 — Migration & Backfill Strategy

## 47. Migration rules

Project is pre-production and may run `migrate:fresh --seed`, but migrations must still be deterministic and production-safe in structure.

Use:
- foreign keys
- unique provider references
- indexes on status/customer/purpose/provider IDs
- enum-backed strings consistent with project style
- decimal/minor-unit policy consistently per domain
Backfill carefully:
- customer approval status
- any source-channel defaults
- existing quotation decisions into response records only if a safe deterministic migration is possible; otherwise provide a one-time command/service and document it
- existing payments remain provider=null/manual
- existing shipment confirmations remain valid without customer photo evidence; new rule applies only to new customer-confirmed arrivals

Do not rewrite historical financial totals.

# Phase 20 — Testing Strategy

## 48. Unit/domain tests

Cover:
- customer provisioning creates User/Profile atomically and unique username
- approval state invariants
- change request approve/reject idempotency
- quote request validation/conversion
- PriceResolver remains source of quotation prices
- quotation Accept/Reject/ChangesRequested transitions
- changes request does not close opportunity lost
- revised quotation re-resolves prices
- direct-order capability default false
- customer shipment confirmation requires photos
- warranty activation happens once
- provider transaction idempotency
- duplicate Stripe reference rejection
- provider settlement creates one ERP Payment only
- invoice payment allocates directly
- pre-invoice payment becomes customer deposit
- deposit application posts Dr Deposit / Cr AR
- deposit application updates invoice/order balance
- deposit application cannot exceed outstanding
- tax recognition works when deposit later applies
- provider refund idempotency
- Ticket PendingPayment -> Live only after settlement
- customer impact does not directly set priority without resolver
- return request conversion uses InventoryReturnService

## 49. Filament feature tests

Cover:
- admin can create customer account with username/password in one flow
- existing-user select is not required for new customer creation
- approval/request-changes/reject actions
- direct-order toggle
- quote request list/view/convert
- quotation response history and revision action
- shipment confirmation evidence display
- payment transaction resource/reconciliation
- invoice outstanding/paid/customer deposit visibility
- ticket Stripe payment state
- return request conversion
- Customer 360 relations

## 50. Regression gates

Run focused tests continuously, then:
- `composer test:lint`
- `composer test:types`
- relevant Pest suites
- full `composer test` when implementation stabilizes

Preserve the project’s existing 100% type-coverage/code-coverage expectations.

# Phase 21 — Implementation Order

Recommended execution batches:
### Batch 1 — Customer foundation
Approval status, provisioning service, admin creation form, change-request domain/dashboard.

### Batch 2 — Commercial intake
Quote Request models/service/resource, direct-order capability.

### Batch 3 — Quotation response
Response evidence, ChangesRequested status, revision flow, Filament actions.

### Batch 4 — Shipment evidence
Customer arrival evidence + photos + dashboard.

### Batch 5 — Payment provider foundation
Stripe SDK/config, PaymentTransaction, Stripe service abstractions, payment method support.

### Batch 6 — Accounting bridge
Provider settlement, customer deposits, later invoice application, tax recognition, balance sync.

### Batch 7 — Payment dashboards/refunds
Reconciliation resource, payment/invoice visibility, provider refund core.

### Batch 8 — Support
Customer impact, Stripe ticket settlement, dashboard.
### Batch 9 — Return requests
Request domain/service/dashboard and conversion to InventoryReturn.

### Batch 10 — Customer 360, notifications, hardening
Relations, events/templates, backfills, tests, documentation consistency.

# Phase 22 — Explicit Non-Goals

Do NOT implement in this work:
- `routes/api.php`
- Sanctum/mobile token authentication
- customer API controllers
- API resources/transformers
- mobile registration endpoint
- guest catalog endpoint
- customer price endpoint
- customer quote-cart endpoint
- customer quotation decision endpoint
- mobile Stripe checkout endpoint
- Stripe webhook HTTP controller/route
- customer shipment-confirm endpoint
- customer support endpoint
- customer return endpoint
- push-token endpoint
- Flutter/UI code

Domain services must be designed so those adapters can be added later without moving business rules.

# Phase 23 — Completion Criteria

The backend/dashboard work is complete when:
1. Admin can provision and review customer accounts with real login username.
2. Legal change requests have an auditable approval workflow.
3. Quote Requests can be reviewed and converted to correctly priced Quotations.
4. Quotation response supports Accept/Reject/Request Changes with immutable evidence.
5. Customer-confirmed shipment arrival supports required photo evidence.
6. Invoice remains electronic with no customer receipt-confirmation dependency.
7. Stripe transaction/domain services exist without customer API/webhook routes.
8. Provider settlement integrates with ERP Payment accounting safely and idempotently.
9. Prepayments post to Customer Deposits and later apply to invoices correctly.
10. Chargeable Support can be settled through the provider orchestration.
11. Return Requests are distinct from internal Inventory Returns.
12. Filament exposes all new operational/reconciliation workflows.
13. Existing commercial/accounting/inventory invariants remain green.
14. Tests, PHPStan, lint, type coverage, and project coverage gates pass.
