# Customer App — Payment & Invoice Architecture

**Status:** Recommended architecture based on current code and adopted Stripe decision.  
**Date:** 2026-09-19

## 1. Core principle

Stripe payment and ERP Invoice are related, but they are not the same object and must not be forced into one lifecycle.

- Stripe answers: did money collection succeed?
- ERP Payment answers: what customer payment was recognised in the ERP?
- ERP Invoice answers: what amount was formally billed for delivered goods/services?
- Payment Allocation answers: which issued invoices the recognised payment settles.

## 2. Current ERP behavior that should be preserved

Current sales invoices are generally created from completed deliveries.

Therefore a customer can legitimately pay before the final ERP Invoice exists.

The current accounting code already supports this situation:
- collected money is debited to the collection account;
- any amount allocated to issued invoices credits Accounts Receivable;
- any unallocated remainder credits Customer Deposits.

This makes Customer Deposits the correct accounting bridge for Stripe prepayments.

## 3. Recommended Stripe purchase flow

For the normal quotation-led sale:

```text
Quote Request
 -> Sales issues Quotation
 -> Customer Accepts
 -> Sales Order created/confirmed
 -> Checkout amount determined
 -> Stripe Checkout Session / payment flow
 -> Stripe confirms successful payment by webhook
 -> ERP Payment posted
 -> If no invoice exists yet: amount goes to Customer Deposits
 -> Fulfillment / Shipment / Delivery
 -> ERP Invoice issued electronically
 -> Existing customer deposit is automatically applied to that invoice
 -> Remaining unpaid invoice amount, if any, stays outstanding
```
## 4. Stripe integration boundary

Do not trust the mobile success screen as the source of truth.

The app may show a pending/success UI, but ERP posting occurs only after a verified Stripe server-side event.

Store Stripe references on an ERP payment-attempt/payment-transaction record, including:
- Stripe Checkout Session ID / PaymentIntent ID as appropriate
- customer profile
- order / quotation / invoice context
- amount
- currency
- status
- last Stripe event
- timestamps

Use idempotency so retries and duplicate webhooks cannot create duplicate ERP Payments.

## 5. New backend pieces required

Recommended new domain records/services:
- `PaymentTransaction` or `StripePaymentAttempt` for provider lifecycle.
- Stripe Checkout/session service.
- Stripe webhook controller/service.
- Provider-to-ERP payment reconciliation.
- Customer-deposit application service.
- Refund integration.
- Admin reconciliation view.

Do not store card details in IERP.

## 6. Missing accounting bridge

Current PaymentPostingService correctly creates an unallocated Customer Deposit when payment arrives before an invoice.

However, the current PaymentAllocationService is designed around allocation during/after Payment posting and only adjusts invoice balances.

A prepaid Stripe amount that was already posted to Customer Deposits needs a later accounting transfer when an Invoice is issued.

Required posting when applying existing deposit:
- Debit Customer Deposits.
- Credit Accounts Receivable.
- Create payment/deposit allocation evidence against the Invoice.
- Update invoice amount_paid / outstanding.
- Update Order payment status.

This must be idempotent and transactional.
## 7. Invoice behavior in the customer app

There is no customer "Confirm Invoice Received" action.

When an Invoice becomes Issued/Sent:
- it appears automatically in the Customer App;
- it appears in Admin;
- PDF/details are available;
- the app shows total, credited, paid, and outstanding;
- notification may be sent;
- viewing/downloading is enough for customer UX.

Invoice lifecycle remains separate from payment lifecycle.

"Paid" should be a derived balance state, not a manual invoice status transition.

## 8. Paying an already-issued invoice

If an issued invoice has an outstanding balance:
- customer can open Invoice Details;
- tap Pay Now;
- backend creates/reuses a Stripe checkout/payment session for the outstanding amount;
- successful webhook creates/posts ERP Payment;
- payment is allocated directly to that Invoice;
- invoice balance becomes partial/paid according to the amount.

The server must calculate the amount and currency. The client never supplies a trusted payable total.

## 9. Paying before invoice issuance

For an accepted quotation/order requiring advance/full payment:
- Pay Now belongs on the Order/payment step, not on a nonexistent invoice.
- successful Stripe collection posts as Customer Deposit;
- Order should show "Payment received / deposit received" separately from invoice status;
- when invoice is issued later, the deposit is consumed automatically.

This avoids creating fake invoices just to collect money.
## 10. Partial payment and payment terms

The architecture should support:
- full prepayment;
- deposit/advance percentage;
- remaining balance after invoice;
- multiple payments;
- multiple invoices for partial deliveries;
- consolidated invoices.

The customer UI should only show actions allowed by the order/payment-term policy returned by the server.

## 11. Failed, cancelled and pending payments

Stripe states must not be mapped directly to ERP Posted payment until money is confirmed.

UI states:
- Payment pending.
- Authentication/action required.
- Payment failed.
- Payment cancelled.
- Payment successful.

Only confirmed successful collection creates/posts the ERP Payment.

## 12. Refunds

Refund initiation remains controlled by ERP business rules.

When an approved ERP Refund is to be returned through Stripe:
- identify the original eligible Stripe transaction;
- create Stripe refund;
- confirm provider result through webhook/API;
- then mark the ERP refund paid and preserve provider reference.

Do not mark an ERP refund Paid merely because the mobile app requested one.
## 13. Chargeable Support

Chargeable Support uses the same payment infrastructure.

Ticket triage determines:
- payment required;
- amount;
- currency.

Customer sees Pay Now on the ticket.
Successful Stripe payment:
- settles the ticket payment obligation;
- moves the ticket from Pending Payment to the service-ready state through domain service rules;
- records provider reference and audit trail.

The existing TicketPaymentLink can be retained as the commercial link object but needs real Stripe provider integration rather than manual settlement.

## 14. Dashboard additions

Admin should have:
- Stripe/payment transaction list.
- provider status and ERP status side by side.
- linked Customer / Order / Invoice / Ticket.
- payment amount/currency.
- succeeded/failed/refunded state.
- reconciliation warnings.
- retry/reconcile action where safe.
- Customer Deposits balance/application visibility.
- webhook/event history or diagnostic summary.
- refund references.
- no manual "force success" action that bypasses provider evidence.

## 15. Customer UI summary

Order:
- payment requirement.
- amount due now.
- Pay Now.
- paid/deposit amount.
- payment history.

Invoice:
- invoice total.
- paid.
- credits.
- outstanding.
- Pay Now only when outstanding > 0 and payment is permitted.
- PDF/view.

Support Ticket:
- amount due if chargeable.
- Pay Now.
- payment status.

No Payment Proof upload in the normal V1 flow.
