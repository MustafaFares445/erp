# Customer App — Current Code Findings

**Date:** 2026-09-19  
**Branch reviewed:** `dev`  
**Source of truth:** current Laravel code, models, services, policies, routes, and Filament behavior.  
**Old SRS/spec files were not used to invent behavior.**

## 1. Customer account

The system already has `UserType::Customer` and `CustomerProfile`.

A customer profile contains:
- customer code
- company name/email/phone
- country/city/address
- latitude/longitude
- accountant contact
- primary contact
- active/inactive state
- private onboarding documents

Customer users cannot access the Filament admin panel. The User model explicitly expects Customer and Employee users to use separate app-specific APIs.

## 2. Registration already exists

Public `/join-us` registration currently creates both User and CustomerProfile.

Registration requires account credentials, company/contact/location data, and five documents:
license, tax certificate, passport, personal identity, and accommodation.
New self-registered profiles are created with `is_active=false`.

This means an administrative review/activation step already exists conceptually before the customer can become operational.

## 3. Current API gap

There is no registered `routes/api.php` in the current application bootstrap.

There is no complete customer-mobile authentication/API layer.

Existing Order, Quotation, Ticket, Payment, and Invoice policies target dashboard/admin/employee permissions, not customer ownership.

Customer mobile APIs therefore need separate ownership authorization based on the authenticated customer's CustomerProfile.

## 4. Products and customer pricing

Products and variants already support:
- English and Arabic names
- descriptions
- category and brand
- public images
- SKU/barcode
- units
- active/inactive status
- warranty duration
- base/minimum pricing

`PriceResolver` supports customer-specific, product-scoped, and general pricing tiers for active customers.
The current backend therefore has enough pricing logic to expose a customer-specific catalog, but it has no customer catalog API today.

## 5. Quotations

CustomerProfile has a direct relation to quotations.

Quotation contains customer, employee, opportunity, payment terms, lines, totals, status, dates, notes, and private quotation PDF media.

Current statuses:
Draft, Sent, Accepted, Rejected, Expired, ConvertedToDelivery, Cancelled.

Important current rule:
`QuotationDecision` explicitly states that there is currently no customer-facing accept/reject route. Admin or employee records the customer's decision on the customer's behalf.

Therefore customer accept/reject must be treated as a product change, not assumed from current code.

## 6. Orders and fulfillment

CustomerProfile has Orders.

Order contains customer, delivery address, commercial status, payment status, totals, quotation reference, scheduling and delivery information.

Commercial statuses:
Draft, Confirmed, Released, Closed, Cancelled.
`OrderWorkflowService` already derives customer-friendly business milestones such as:
- Awaiting Release
- Supply Blocked
- Awaiting Logistics Allocation
- Partially Allocated
- Ready to Dispatch
- In Transit
- Invoice Pending
- Payment Pending
- Delivered
- Closed / Cancelled

It also derives fulfillment percentage, quantities, invoice totals, paid/credited values, and outstanding receivable.

This is a strong basis for the customer order-tracking UI.

## 7. Shipments and delivery confirmation

Orders have Shipments with tracking numbers and statuses:
Planned, In Transit, Arrived, Cancelled.

The Shipment model already supports `confirmByCustomer(CustomerProfile)`.

A customer-confirmed arrival records customer identity and confirmation time.

Confirmed arrival is also the trigger used by warranty activation for serialized products.

## 8. Delivery addresses

CustomerProfile has multiple CustomerDeliveryAddress records with label, address, country/city, coordinates, contact person, active/default flags.
## 9. Invoices and receivables

CustomerProfile has invoices.

Invoice contains:
- invoice number
- order or maintenance reference
- issue/due dates
- lines and totals
- amount paid
- credited amount
- outstanding amount
- status
- private invoice PDF

Issued invoices are immutable for commercial fields.

The invoice model/service supports receipt evidence and has a `CustomerReceived` confirmation type.

The current confirmation service is still protected by dashboard policy, so customer-side invoice acknowledgement needs a customer API/ownership path.

## 10. Payments

CustomerProfile has payments and payment allocations.

Payment supports:
- payment method
- amount/currency
- external reference
- payment date
- notes
- private payment proof
- allocations to invoices
- Posted/Reversed lifecycle

The current PaymentService is a dashboard/admin workflow. It creates manual payment records and posts accounting effects server-side.
There is no general online customer payment gateway in the current PaymentService.

A customer-app payment feature therefore needs a clear product decision:
- online payment provider,
- payment-link redirect,
- or customer upload of payment proof for admin verification.

## 11. Credit notes, refunds and returns

CustomerProfile has credit notes and refunds.

The inventory domain supports customer returns only against a completed customer delivery and preserves exact product/lot/serial provenance.

Customer return posting is an internal inventory-controlled workflow and can generate credit-note consequences.

There is no current lightweight "customer return request" API/model.

A mobile return experience should therefore submit a request that administration/logistics reviews, rather than allowing the customer app to post inventory returns directly.

## 12. Support tickets

CustomerProfile has Tickets.

Ticket supports:
- customer ownership
- type and priority
- title and description
- attachments
- assignment to employee
- warranty snapshot
- chargeable/non-chargeable outcome
- payment hold
- SLA state
- resolution summary
- append-only conversation messages
Ticket types are:
- Software Issue
- Hardware Issue
- General Support
- Maintenance Request

Ticket lifecycle:
Pending -> Pending Payment / Live -> Assigned -> In Progress -> Waiting Customer -> Resolved -> Closed, with cancellation/reopen paths controlled by support services.

Current policies are dashboard/support-role policies. Customer create/view/message authorization does not exist yet.

## 13. Warranty and customer equipment

Serialized products delivered to the customer can carry:
- serial number
- product variant
- warranty start
- warranty expiry
- customer custody

Warranty is activated from confirmed shipment arrival.

Support triage can identify:
- equipment sold by IERP to this customer
- external equipment
- Covered / Expired / Not Covered / Not Applicable / Unknown warranty state

For equipment sold by IERP, warranty ownership is checked against current customer custody.

## 14. Chargeable support

Support triage can decide that a ticket requires payment.

A TicketPaymentLink contains amount, currency, status and optional payment URL/reference.

The current TicketPaymentService explicitly has no Stripe/payment-provider integration and settlement is performed through protected support/admin logic.
## 15. Notifications

The notification domain already contains customer-relevant events including:
- Invoice Issued
- Payment Received
- Quotation Decided / Expired
- Ticket Updated
- Maintenance Record Billed
- Maintenance Due
- Invoice overdue reminders

Database and Mail notification infrastructure exists.

SMS and WhatsApp currently fail when no provider is configured.

There is no current mobile push device-token infrastructure.

## 16. Core conclusion

The current backend already models most customer business records needed for a strong customer app.

The main missing layer is not the business database; it is the customer channel:
- authentication/token API
- customer ownership authorization
- customer-safe resources
- customer actions such as shipment confirmation
- optional quotation decision
- optional catalog/order request
- support ticket intake/messages
- optional payment flow
- push notifications
