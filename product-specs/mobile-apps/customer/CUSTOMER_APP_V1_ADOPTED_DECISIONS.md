# Customer App V1 — Adopted Decisions

**Status:** Adopted product decisions; language choice remains open.  
**Date:** 2026-09-19

## Account and access

1. Customer may self-register from the mobile app using the same required data and validation as the current `join-us` flow.
2. Admin may also create the customer account, and must create a unique username used for login.
3. Guest mode is available without an account.
4. A registered customer whose account is still under review may enter the same restricted browsing experience as Guest.
5. Guest and Under Review users may browse products but:
   - cannot see customer prices;
   - cannot add items to quote cart;
   - cannot submit quotations/orders;
   - cannot access account-owned commercial/support records.
6. Active customers may use multiple devices.
7. Normal contact/delivery details may be editable directly; legal/company identity fields and legal documents use an admin-approved change-request flow.

## Catalog and commercial flow

8. Product Catalog is part of V1.
9. Prices are visible only to active authenticated customers and must always be resolved server-side with the customer's pricing rules.
10. Primary product action is Add to Quote Request Cart.
11. Customer submits one Request for Quotation containing selected variants, quantities, delivery context, and notes.
12. Sales reviews the request and creates the formal Quotation.
13. Customer may Accept, Reject with reason, or Request Changes.
14. V1 order flow is quotation-led: accepted quotation becomes the source for Sales Order creation.
15. Backend/Admin should be designed so selected trusted/contract customers can later be granted Direct Order capability through an explicit customer-level policy/setting, without changing the default flow.
## Delivery, invoices and payment

16. Customer may confirm shipment arrival from the app.
17. Delivery confirmation requires confirmation plus one or more uploaded delivery photos.
18. Delivery evidence records customer, timestamp, photos, shipment/order relationship, and source channel.
19. There is no "Confirm Invoice Received" action.
20. Invoice is an electronic system document. Once issued, it appears automatically in Admin and Customer App and may be viewed/downloaded.
21. Payment Proof upload is not part of the primary payment flow.
22. Customer payments use Stripe as the online payment provider.
23. Stripe payment must be integrated with the ERP Payment/Accounting domain rather than treated as an isolated mobile receipt.
24. Support charges also use Stripe.

## Returns and after-sales

25. V1 includes a separate customer Return Request workflow.
26. A Return Request does not directly post inventory. Admin/Logistics reviews it and converts/links it to the internal Inventory Return process.
27. Customer can create Support Tickets.
28. For support, customer may select owned serialized equipment from My Equipment or identify external equipment; Support validates during triage.
29. Customer does not choose internal support Priority. UI captures business impact; backend/Support maps it to internal priority/SLA.
30. Maintenance is available inside the Support area rather than as a main bottom-navigation item.
31. My Equipment & Warranty is a clear customer-facing section within Support.
32. Push Notifications are required in V1.

## Remaining product decision

33. Initial language scope is not yet explicitly decided: English, Arabic, or Arabic + English.
