# Customer App V1 Behavior

---
status: canonical
owner: customer-app
last_verified: 2026-10-02
verified_against: CUSTOMER_APP_V1_ADOPTED_DECISIONS.md plus current backend-domain audit
---

## Account and Access

- Customer may self-register using the same business data/validation as the established join-us onboarding flow.
- Admin may also provision a customer account and assign a unique login username.
- Guest mode is allowed.
- An account still Under Review uses the same restricted browsing experience as Guest.
- Guest/Under Review may browse products but cannot:
  - see customer-specific prices;
  - add to Quote Request cart;
  - submit quotations/orders;
  - access account-owned commercial/support records.
- Active customers may use multiple devices.
- Normal contact/delivery details may be edited directly where backend rules permit.
- Legal/company identity fields and legal documents use the approval-based change-request flow.

The initial language scope remains an unresolved product decision in the adopted decisions document.

## Product Catalog

Active authenticated customers may see server-resolved customer pricing.

The app must never calculate final customer price locally or expose internal cost, supplier reference, warehouse balance, markup/floor internals.

Primary V1 commercial CTA: **Add to Quote Request Cart**.

## Quote Request

1. Customer selects variants/quantities.
2. Adds delivery context/notes.
3. Submits one Customer Quotation Request.
4. Sales reviews it.
5. Sales creates the formal Quotation.
6. Customer may Accept, Reject with reason, or Request Changes.
7. Accepted quotation becomes the source for normal Sales Order creation.

Default V1 is quotation-led.

The backend supports a customer-level direct-order policy for trusted customers, but the customer API exposure for that path is not currently implemented.

## Orders and Fulfillment

Customer sees only their own commercial records.

The app should communicate customer-facing progress without leaking internal procurement/warehouse detail.

Shipment arrival may be confirmed by the customer.

Delivery confirmation requires:
- explicit confirmation;
- one or more uploaded delivery photos/evidence;
- persisted customer/timestamp/shipment/order/source-channel provenance.

Arrival confirmation must map to the shipment service rather than directly closing the Sales Order.

## Invoices

There is no customer â€œConfirm Invoice Receivedâ€ action.

When an Invoice is issued:
- it becomes visible automatically;
- customer may view/download the electronic document;
- UI shows total, credits, paid amount and outstanding amount;
- financial state is derived from ERP balance facts.

Invoice lifecycle and payment lifecycle remain separate.

## Payments

Primary V1 online provider: Stripe.

The mobile success screen is never the accounting source of truth.

Server determines amount/currency and provider settlement creates/reconciles ERP payment behavior.

Supported conceptual purposes:
- Order prepayment/deposit.
- Issued Invoice outstanding amount.
- Chargeable Support Ticket.

A payment collected before invoice issuance becomes Customer Deposit. When the invoice exists, eligible deposits are applied through the ERP allocation/accounting bridge.

No payment-proof upload is part of the normal V1 Stripe flow.

## Returns

Customer Return Request is separate from Inventory Return.

1. Customer requests return against their own completed delivery.
2. CRM/admin reviews request.
3. Approved request converts to Draft Inventory Return.
4. Conversion does not move stock.
5. Inventory inspection/posting owns the physical result.
6. Sales Credit Note/refund remains a separate financial workflow.

## Support

Customer may create Support Tickets.

Customer selects:
- owned serialized equipment from My Equipment; or
- external equipment details.

Customer communicates business impact; they do not choose internal ticket priority.

Backend/Support resolves internal priority/SLA and service path.

Maintenance is inside Support rather than a primary bottom-navigation destination.

â€œMy Equipment & Warrantyâ€ is a clear customer-facing Support section.

## Notifications

Push Notifications are an adopted V1 requirement.

Notification deep links should open the relevant customer-owned record without exposing sensitive detail in the push body.

Current backend Notification delivery does not by itself prove that a mobile push-token/provider channel is implemented; see backend dependencies.

## Security / Ownership

A future Customer API must derive the CustomerProfile from the authenticated principal.

The client must not be trusted to choose `customer_id` for ownership.

Every customer-facing query/action must enforce:
- account approval/active state where required;
- ownership of quotation/order/shipment/invoice/payment/return/ticket/equipment;
- server-side pricing/payment totals;
- domain lifecycle rules;
- private-media authorization.

## Screen Inventory

The canonical behavior requires at least these UX surfaces:

- Splash / Session Check
- Guest browsing entry
- Login
- Join Us / registration
- Registration received / Under Review
- Home
- Product Catalog
- Product Details
- Quote Request Cart
- Submit Quote Request
- Quote Requests
- Quotations List / Details / Accept / Reject / Request Changes
- Orders List / Details
- Shipment Details / Confirm Arrival / Evidence
- Invoices List / Details / PDF
- Payment status / Pay Now / history
- Credit Notes / Refund status
- Return Requests / details
- Support Tickets / details / messages
- Chargeable Support payment state
- My Equipment & Warranty
- Maintenance details
- Notifications
- Profile / company/account
- Delivery Addresses
- Profile/legal Change Requests
- Security / password / logout

Visual grouping is defined in the Pen source; business availability is defined here.
