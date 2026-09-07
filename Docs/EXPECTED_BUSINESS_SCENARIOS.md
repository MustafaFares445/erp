# Expected Business Scenarios — IERP

**Document type:** Functional discovery output (business scenario map)
**Perspective:** Senior ERP Functional Consultant / Business Process Analyst / Product Owner
**Status:** Discovery — describes *ideal* ERP behaviour, not current implementation
**Created:** 2026-09-03

---

## 0. How to read this document

This document answers one question: **"What business scenarios should exist in this ERP system?"**

It deliberately does **not** describe modules as CRUD. Every entry below is a *business workflow* — something a real person in a real company sets out to achieve, that starts with a business trigger and ends with a business outcome plus the evidence that proves it happened.

Each scenario uses this shape:

| Field | Meaning |
|---|---|
| **Trigger** | The real-world event that starts the work |
| **Actors** | Who participates, including the system itself and external parties |
| **Business flow** | The ordered business steps, crossing module boundaries freely |
| **Must hold** | Business rules and invariants that make the outcome trustworthy |
| **Evidence** | The durable records a controller or auditor can later point at |
| **Alternate paths** | Realistic ways the scenario diverges, and what the business expects then |

Scenario IDs are stable and are referenced by `CROSS_MODULE_BUSINESS_FLOWS.md` and `ERP_DOMAIN_MODEL.md`.

### Source basis

| Source | Role in this analysis |
|---|---|
| `Docs/PRD.md` | Business context, goals, functional requirements, business rules, scope boundaries |
| `Docs/SDD.md` | Per-feature actors, user stories, acceptance criteria, business rules |
| `Docs/database/ERD.md` | Entity vocabulary, lifecycle status catalogue, integrity and posting rules |
| `Docs/database/DFD.md` | Actor/data-store interaction and external system boundaries |
| `Docs/api/API_CONTRACT.md` | Actor surfaces, status-transition-as-explicit-action principle |
| `Docs/architecture/SYSTEM_ARCHITECTURE.md`, `Docs/architecture/COMPONENT_DESIGN.md` | Domain boundaries, shared services, side-effect model |
| `Docs/diagrams/SEQUENCE_DIAGRAMS.md` | Confirmed step ordering for core financial and inventory flows |
| `Docs/IMPLEMENTATION_PLAN.md` | Phase intent and acceptance criteria per module |
| `Docs/adr/0001`–`0011`, `specs/001`–`022` | Owner decisions, lifecycle detail, deliberate scope limits |
| `Docs/plans/WAREHOUSE_*` | Canonical inventory posting, traceability, and returns/condition lifecycle intent |

> **Documentary gap noted for the record:** no `SRS.md` exists in this repository, and `Docs/plans/WAREHOUSE_CANONICAL_ARCHITECTURE.md` records that the primary Product/Inventory SRS (`IERP_Product_Inventory_Module_SRS_AR.docx` / `IERP-SRS-PIM-001 v1.1`) is **not present in the checkout**. Inventory traceability scenarios below are therefore reconstructed from the in-repository derivative specifications and must be reconciled against that document when it is attached.

---

## 1. Actors and business roles

### 1.1 Confirmed system actors

| Actor | Business identity | Primary surface |
|---|---|---|
| System Admin | Owner of financial, inventory, and configuration truth | Dashboard |
| Customer | The buying party; a legal entity with a commercial profile | Customer app, or recorded on their behalf in the dashboard |
| Employee | The field-facing party executing plans, visits, and sales | Employee app, or recorded on their behalf in the dashboard |
| System | Automated actor: numbering, tax recognition, posting, derivation, notification | — |
| Supplier | External supplying party; answers, promises, ships, and bills | No portal (deliberate) |
| Payment provider | External collection channel | Webhook |
| AI transcription provider | External speech-to-text and keyword detection | Queue-side integration |
| Mail / push provider | External notification delivery | Queue-side integration |

### 1.2 Business roles that shape approval and separation of duties

Derived from the fixed dashboard roles across the ADR set. These are *business* roles, not technical permissions:

| Role | Owns the decision to… |
|---|---|
| System Admin | Override any control; approve a below-floor price; restore archived records |
| CRM Manager | Accept a customer into the commercial base; own lead and campaign quality |
| Pricing Manager | Set discount policy (tiers) and the products and customers it applies to |
| Purchasing Manager | Commit company money to a supplier above the auto-approval threshold |
| Accountant | Own the chart of accounts, posting correctness, and period integrity |
| Payables Officer | Approve a bill or expense — that is, recognise what we owe |
| Payments Officer | Record collection and disbursement |
| Employee Manager | Set what a field employee is measured on |
| Payroll Officer | Confirm a performance score and the salary it produces |
| Support Manager | Own ticket triage, SLA, and escalation |
| Technician | Execute service work and consume spare parts |
| Reviewer | Read-only assurance across every module, including audit trails |

### 1.3 Separation-of-duties principles the scenarios assume

1. **The party who records money moving is not the party who approves it moving.** Explicit for refunds and supplier payments; by convention for bills and expenses.
2. **The party who requests a price exception is not the party who grants it.** Below-floor pricing needs System Admin approval, recorded.
3. **The party who counts stock is not necessarily the party who confirms the correction.** Adjustment creation and adjustment confirmation are separate decisions.
4. **No AI output takes effect without a named human decision.** Transcripts, keyword-detected opportunities, and bonus suggestions are all proposals.
5. **The party who commits to a supplier above threshold is a manager, not the requester.**

---

## 2. Master Data Scenarios (MD)

Master data is not "reference tables". Each master record is a **commercial agreement or a physical fact** that downstream documents depend on and must not be able to silently contradict.

### MD-01 — Accept a new customer into the commercial base
**Trigger:** A qualified lead agrees to trade, or a company requests an account.
**Actors:** CRM Manager, System Admin, System.
**Business flow:**
1. CRM Manager captures the legal and commercial identity: company name, contact person, contact channels, tax identity, billing and delivery addresses.
2. System assigns a guaranteed-unique customer code that will appear on every downstream document.
3. CRM Manager records the commercial terms of the relationship: default payment term, price positioning (general pricing tier), and the language the customer is served in.
4. The customer is linked to a login identity if they will use the customer app; otherwise they exist as a dashboard-only commercial party.
5. The customer becomes eligible as a party on quotations, orders, invoices, tickets, maintenance records, campaigns, and visits.

**Must hold:**
- One customer profile per customer identity; a duplicate code or duplicate user link is refused with a field-level reason, never silently merged.
- A customer with no payment term inherits the system default, and the resolved term is *snapshotted* onto each invoice rather than read live.
- Credit limits are deliberately not part of the model; exposure is managed by receivables ageing and payment terms, not by a blocking limit.

**Evidence:** Customer profile with code; creation audit entry naming the acting user.

**Alternate paths:**
- *Customer already exists under a variant spelling* → the near-duplicate is surfaced at capture time so the operator can attach to the existing party.
- *Customer trades under several legal entities* → each entity is its own customer; a group relationship is reporting metadata, never a shared receivable.

### MD-02 — Deactivate a customer without destroying history
**Trigger:** The relationship ends, or the entity is dissolved.
**Actors:** CRM Manager, System Admin.
**Business flow:**
1. The operator deactivates or archives the customer.
2. All historical quotations, invoices, payments, tickets, and visits stay fully readable and reportable.
3. The customer stops being selectable as the party on any *new* document.
4. Customer-derived pricing eligibility ceases immediately — an inactive customer's tier no longer resolves.
5. Outstanding receivable balances remain on the ageing report until settled or written off through an approved document.

**Must hold:** Deactivation never deletes, never rewrites a posted document, and never changes a historical price.

**Evidence:** Archive and restore audit entries; unchanged historical documents.

**Alternate paths:** *Reactivation* → a System Admin restores the profile with its user link and full history intact, recorded as a restore.

### MD-03 — Onboard a supplier as a purchasing counterparty
**Trigger:** Sourcing identifies a supplier the company intends to buy from.
**Actors:** Purchasing Manager, System Admin.
**Business flow:**
1. The operator records supplier identity, contact channels, country, currency, and payment terms.
2. The operator records what the supplier supplies: per-variant supplier item numbers and expected purchase costs.
3. The supplier becomes eligible as the counterparty on purchase orders, supplier confirmations, bills, and supplier payments.
4. Supplier item references become the default cost source when a buyer raises a purchase order line.

**Must hold:**
- Supplier code is unique; a supplier referenced by any document cannot be hard-deleted.
- Supplier cost references are *defaults*, not truth — the actual received cost is written back after receipt so the next order starts from reality.
- There is no supplier-facing portal by design; every supplier answer enters the system through an admin who recorded it, and the record names them.

**Evidence:** Supplier profile; supplier product references with cost provenance.

**Alternate paths:** *Supplier is also a customer* → two independent parties; receivable and payable are never netted automatically.

### MD-04 — Hire an employee into the operating model
**Trigger:** A new field or office employee joins.
**Actors:** Employee Manager, Payroll Officer, System.
**Business flow:**
1. The Employee Manager records identity, job title, contact data, and app-access state.
2. System assigns a guaranteed-unique employee code.
3. The Payroll Officer chooses the compensation model: performance-only, where a commission target amount is the payable base, or base-plus-performance, where a base salary is the payable base.
4. The employee becomes assignable to monthly plans, tasks, visits, tickets, and service records, and becomes a valid author of quotations.
5. App access is granted or withheld independently of employment state.

**Must hold:**
- Exactly one of base salary or commission target is set; the compensation model is never ambiguous.
- The company's default employee is performance-compensated — base salary is the opt-in.
- Revoking app access never removes historical visits, GPS trails, or authored documents.

**Evidence:** Employee profile with code and compensation model; access-change audit entries.

**Alternate paths:** *Employee leaves mid-month* → the profile is archived and stays searchable; the month's plan is still scorable and payable for the period worked.

### MD-05 — Establish a warehouse as a custody boundary
**Trigger:** The company opens a stocking location, or separates custody of goods.
**Actors:** System Admin, Inventory Manager.
**Business flow:**
1. The operator defines the warehouse: name, code, address, and its role — main, branch, transit, quarantine, or service van.
2. The warehouse becomes a valid dimension for stock balances, receipts, deliveries, transfers, adjustments, reservations, and parts consumption.
3. Reorder thresholds may be set per variant per warehouse so low-stock work queues are location-aware.

**Must hold:**
- A warehouse is a **custody boundary**, not a reporting label: stock quantity is only ever meaningful as `(variant, warehouse, condition)`.
- A warehouse holding any stock or referenced by any movement cannot be deleted; it is closed instead, and closing requires its balances to be zero or transferred out.

**Evidence:** Warehouse record; stock balances keyed to it.

**Alternate paths:** *Bin-level detail needed* → locations remain a sub-dimension for putaway guidance and are deliberately **not** part of the balance key until approved; a container or package is never a balance.

### MD-06 — Define a payment term as a due-date policy
**Trigger:** Finance standardises how long customers may take to pay.
**Actors:** Accountant, System Admin.
**Business flow:**
1. The Accountant defines the term: net days, grace days, and whether it is the system default.
2. The term is assigned as a customer default and offered on quotations and orders.
3. On invoice issuance the term produces a concrete due date, and both the term reference and the resolved date are stored on the invoice.
4. Overdue status is *derived* from due date plus grace days — never stored — so changing policy never retroactively brands a historical invoice as late.

**Must hold:** A term's later edit must not change the due date of an already-issued invoice.

**Evidence:** Payment term record; invoice carrying its resolved due date and term reference.

**Alternate paths:** *Customer negotiates a one-off term* → the term is selected per document; the customer's default is a starting point, not a constraint.

### MD-07 — Configure tax policy so tax follows collection
**Trigger:** The company must charge and remit sales tax.
**Actors:** Accountant, System Admin.
**Business flow:**
1. The Accountant sets the default tax rate and the four posting accounts that revenue and tax flow through: receivable, revenue, deferred tax, and tax payable.
2. Quotation, order, invoice, and credit-note lines default to that rate, with a per-line override for exempt or differently-rated items.
3. Issuing an invoice credits **deferred** tax — never tax payable.
4. Collecting money moves the collected proportion of tax from deferred to payable.

**Must hold:**
- Tax is never recognised at invoice creation. This is the company's defining accounting rule.
- The same recognition logic serves every collection channel — manual, card, transfer — so a channel can never become a second tax path.

**Evidence:** Tax settings with named posting accounts; tax recognition entries; the tax register.

**Alternate paths:** *A rate changes mid-year* → the new rate applies to new documents only; issued documents keep the rate they were issued with.

### MD-08 — Build a chart of accounts the accountant owns
**Trigger:** Finance sets up or restructures the ledger.
**Actors:** Accountant.
**Business flow:**
1. The Accountant defines account types — asset, liability, equity, income, expense — and a hierarchical account tree.
2. The Accountant marks which accounts are postable and which are structural roll-ups only.
3. The Accountant names the accounts automated posting uses: receivable, revenue, deferred tax, tax payable, payable control, cash and bank per payment method, and expense accounts.
4. Every automated posting reads those *named configuration references*, never a hardcoded account code.

**Must hold:**
- An account with postings cannot be deleted, and its type cannot change.
- A structural parent never receives a posting directly.
- The chart of accounts stays the accountant's to restructure without a code change.

**Evidence:** Account hierarchy; posting configuration.

**Alternate paths:** *Account must be retired* → it is deactivated; history keeps referencing it and reports keep resolving it.

### MD-09 — Open and close a fiscal period
**Trigger:** The accounting calendar advances.
**Actors:** Accountant.
**Business flow:**
1. The Accountant defines periods and their open or closed state.
2. Postings are refused into a closed period; the document must carry a date in an open period, or the period must be reopened by an authorised decision.
3. Closing a period is an explicit, audited act with a stated cut-off.

**Must hold:** A closed period's balances cannot change silently; any correction is a new dated document in an open period, linked to the original.

**Evidence:** Fiscal period records; refused-posting audit trail.

**Alternate paths:** *Late document arrives after close* → it posts to the current open period with an explicit reference to the original event date.

### MD-10 — Define payment methods as ledger-aware collection channels
**Trigger:** Finance decides how money may be received and paid.
**Actors:** Accountant, Payments Officer.
**Business flow:**
1. The Accountant defines each method — cash, bank transfer, cheque, card, custom — and the cash or bank account a collection through it debits.
2. The Accountant flags which methods require proof of payment before the collection can be recorded.
3. The method becomes selectable on customer payments, supplier payments, expense payments, and refunds.

**Must hold:** A cash receipt and a bank transfer must be distinguishable *to the ledger*, not just on screen — every method names its account.

**Evidence:** Payment method with its account and proof requirement.

**Alternate paths:** *Method retired* → deactivated; historical payments keep their method and account.

### MD-11 — Define units of measure and per-variant conversions
**Trigger:** The company buys in one unit and sells or stocks in another.
**Actors:** Inventory Manager, System Admin.
**Business flow:**
1. The operator defines named units within one physical family — mass, volume, count.
2. For each variant, the operator declares exactly one base unit and the permitted transaction units with their conversion to base.
3. Every document line is captured in a transaction unit and *normalised to base quantity* before it touches a balance.

**Must hold:**
- Balances, movements, and reservations exist only in base quantity.
- A variant's base unit becomes immutable once it has stock history — otherwise every historical quantity silently changes meaning.

**Evidence:** Unit definitions; per-variant unit conversions; normalised base quantity on every movement.

**Alternate paths:** *Conversion was wrong* → it is corrected forward with a documented adjustment, never by rewriting past quantities.

### MD-12 — Publish a discount policy as a pricing tier
**Trigger:** Sales agrees a discount structure with a customer, or for a product family.
**Actors:** Pricing Manager, System Admin, Reviewer.
**Business flow:**
1. The Pricing Manager creates a tier of one of three kinds: **general** (a percentage band a customer is assigned to), **customer-specific** (a percentage for one customer), or **product-scoped** (a percentage or fixed discount on named products, optionally for named customers, optionally with a validity window).
2. The Pricing Manager links products and eligible customers, and activates the tier.
3. Anyone authorised can *preview* what a given customer would pay for a given variant, and see which rule produced the price.
4. Every tier and assignment change is audited and reviewable.

**Must hold:**
- Price resolution is deterministic and **never stacks**: active customer-specific tier → lowest eligible product-scoped result → the customer's active general tier → base price. Equal product-scoped results break the tie on the lowest tier identifier.
- The resolved price carries its **provenance** onto the document line, so an author can see why a line costs what it does.
- No resolved price may fall below the variant's minimum price without an explicit, logged System Admin approval.

**Evidence:** Tier definitions; assignments; price preview with provenance; audit entries.

**Alternate paths:**
- *Two tiers would both apply* → the lowest eligible result wins, and the losing rules stay visible in the preview, so the outcome is explainable to the customer.
- *Tier expires mid-quotation* → an unsent quotation re-resolves; a sent quotation keeps its snapshotted prices.

---

## 3. Product Management Scenarios (PM)

The commercial thing you sell (a product) and the stockable thing you count (a variant) are different concepts. Every scenario below respects that split.

### PM-01 — Introduce a new product to the catalogue
**Trigger:** Procurement or product management adds a sellable item.
**Actors:** Product Manager, System Admin.
**Business flow:**
1. The operator creates the commercial product: names including Arabic, category, brand, manufacturer, country of origin, description, and marketing files.
2. The operator declares the product's commercial classification for reporting and browsing.
3. The product is not yet sellable — it has no stockable variant and therefore no price and no availability.
4. The operator creates at least one variant to make the product transactable.

**Must hold:** A product never holds a stock quantity. Stock is a variant fact.

**Evidence:** Product record with categorisation and media.

**Alternate paths:** *Single-variant product* → the system still creates one variant, so pricing, stock, and traceability have a home.

### PM-02 — Create a stockable variant with an explicit tracking profile
**Trigger:** A product needs a sellable, countable SKU.
**Actors:** Product Manager, Inventory Manager.
**Business flow:**
1. The operator creates the variant: SKU, barcode or GTIN, and the attribute values that distinguish it — size, colour, capacity, model.
2. The operator declares the **tracking profile** that governs how the variant is physically controlled:
   - base unit and quantity precision;
   - lot tracking off or required;
   - expiry off or required-on-receipt, which requires lot tracking;
   - serial tracking off or required;
   - outbound allocation style: FEFO suggested, or mandatory explicit operator allocation.
3. The operator sets the base price and the **minimum price (floor)**.
4. The variant becomes stockable, quotable, orderable, deliverable, and traceable.

**Must hold:**
- SKU is unique; barcode, GTIN, and UDI are *identifiers*, never quantities.
- Once the variant has stock history, base unit and tracking profile become immutable — otherwise historical balances and allocations lose meaning. The guard lives in the domain, not in a disabled form field.
- A serial-tracked variant means one physical unit per base quantity of one.

**Evidence:** Variant with identifiers, attribute values, tracking profile, base price, and floor.

**Alternate paths:**
- *Tracking must change* → permitted only with zero history, or by retiring the variant and introducing a successor.
- *Same physical good from two suppliers* → one variant with several supplier item references; supplier identity is procurement metadata, not a separate SKU.

### PM-03 — Define an attribute so variants are comparable
**Trigger:** A new product family needs a distinguishing dimension.
**Actors:** Product Manager.
**Business flow:** The operator defines the attribute and its permitted values, then assigns values to variants so customers and staff can filter and compare.
**Must hold:** An attribute value in use by a variant cannot be deleted, and renaming it must not silently redefine what a historical variant *was*.
**Evidence:** Attribute and value definitions; variant-to-value links.

### PM-04 — Price a variant and defend the floor
**Trigger:** Commercial pricing is set or revised.
**Actors:** Pricing Manager, System Admin.
**Business flow:**
1. The Pricing Manager sets or revises the base price and the minimum price.
2. Any quotation, order, or invoice line resolves its price through the tier rules (MD-12) and records the rule that produced it.
3. If a resolved or manually entered price falls below the floor, the sale is **blocked**.
4. A System Admin may approve the below-floor price explicitly; the approval records who, when, why, the floor, the approved price, and the tier provenance.

**Must hold:** The floor is a hard control with a named exception path, not a warning.

**Evidence:** Price history; price-floor override records with approver and reason.

**Alternate paths:** *Below-floor approval refused* → the line cannot be sent; the author must re-price or escalate.

### PM-05 — Answer "can we sell it, and from where?"
**Trigger:** A salesperson or customer asks for availability.
**Actors:** Employee, Customer, System.
**Business flow:**
1. The system reports, per warehouse: on-hand, reserved, and available — on-hand minus reserved — in base and display units.
2. The system reports lot and expiry position for expiry-controlled variants, and unit-level status for serial-tracked variants.
3. The system reports inbound coverage: quantity on approved or sent purchase orders and their expected dates.
4. The salesperson can therefore promise from stock, promise from inbound, or trigger procurement.

**Must hold:**
- Availability reduces on **reservation**, not on physical movement — so two salespeople cannot promise the same unit.
- Available quantity must never be negative except through an explicitly approved override.
- Damaged, quarantined, and expired stock is on-hand but **not saleable**; the availability answer must say which.

**Evidence:** Stock position by variant, warehouse, and condition; reservation list with sources; inbound coverage report.

### PM-06 — Find any product, variant, or device in one search
**Trigger:** Staff need an operational record fast, often from a partial identifier read off a label.
**Actors:** Any authorised user.
**Business flow:** One search covers English and Arabic names, SKU, barcode, brand, category, supplier, supplier item number, manufacturer, country ISO code and localised country name, plus serial and IoT identifiers for devices, and returns authorised view pages.
**Must hold:** Search never leaks records the user is not authorised to view, and never surfaces archived records as if current.
**Evidence:** Search result linking to the authoritative record.

### PM-07 — Retire a product from sale without erasing it
**Trigger:** A product is discontinued or superseded.
**Actors:** Product Manager.
**Business flow:**
1. The operator marks the product or variant discontinued: no new quotations, orders, or purchase orders.
2. Remaining stock stays sellable and deliverable until depleted, or is disposed through an explicit write-off.
3. Historical documents, service history, and device histories remain fully readable.
4. A successor variant may be declared so quoting and service can redirect.

**Must hold:** Retirement is never a delete; a variant with movements, allocations, or documents cannot be removed.

**Evidence:** Lifecycle state change with actor and date; unchanged history.

### PM-08 — Load or update the catalogue in bulk
**Trigger:** A supplier price list or an initial catalogue arrives as a spreadsheet.
**Actors:** Product Manager, System.
**Business flow:**
1. The operator uploads the file and maps columns to catalogue concepts.
2. The system validates every row *before* changing anything: identifier uniqueness, unit validity, tracking-profile coherence, price versus floor, and category and supplier existence.
3. The system presents a preview: creates, updates, and rejections with per-row reasons.
4. The operator commits; the import applies as one reviewable batch and, where it carries opening quantities, posts them through the canonical stock path — never as a direct balance write.
5. The system retains an import record so the batch can be explained and, if wrong, corrected forward.

**Must hold:** A partially valid file never leaves the catalogue half-changed, and an import that touches stock produces real movements like any other receipt.

**Evidence:** Import record with row-level outcomes; resulting movements linked to the import.

### PM-09 — Publish product content to the customer surface
**Trigger:** Customers must be able to browse and request what the company sells.
**Actors:** Product Manager, Customer.
**Business flow:** The operator marks a variant customer-visible; the customer browses with their *own* resolved price and an availability indication appropriate to disclose, and can request a quotation or an order from what they see.
**Must hold:** A customer sees only their own resolved price — never another customer's tier, never the floor, never cost.
**Evidence:** Visibility flags; customer-facing price resolution matching the dashboard preview for the same customer.

### PM-10 — Explain a device's whole life
**Trigger:** A customer, technician, or auditor asks what happened to *this* physical unit.
**Actors:** Stock viewer, Technician, Support Manager.
**Business flow:** From a serial or IoT identifier, the system shows the unit's current status and custody, its receipt, and an ordered timeline of every movement, condition change, delivery, service record, and warranty position.
**Must hold:**
- Every serialised receipt, transfer, adjustment, delivery, and condition change references the unit, so the timeline is complete by construction rather than by reconstruction.
- Unknown legacy status values normalise to an explicit "unknown" rather than being guessed.

**Evidence:** Device timeline assembled from movements, operations, service records, and warranty data.

---

## 4. Inventory Scenarios (IN)

**Governing principle:** stock quantity is only meaningful as `(product variant, warehouse, condition)` in base units, and **every stock-changing operation produces an immutable movement in the same transaction**. There is exactly one posting path; no workflow updates a balance and separately remembers to write its movement.

### IN-01 — Receive goods against a purchase order
**Trigger:** A supplier delivery arrives at a warehouse.
**Actors:** Warehouse Operator, System, Purchasing.
**Business flow:**
1. The operator opens a receipt against the purchase order; ordered, already-received, and outstanding quantities per line are visible.
2. The operator counts what physically arrived, in the receiving unit, and records lot numbers and expiry dates where the variant requires them, and serial or IoT identifiers where required.
3. The operator records condition per received quantity: saleable, or straight to quarantine or damaged.
4. On confirmation, in one transaction, the system normalises to base quantity, creates or extends lot identities, registers serialised units into custody, increases the balance for the right condition, and writes one movement per changed grain.
5. Purchase order line received quantities advance under lock, and the actual received unit cost is written back to the supplier reference.
6. The purchase order becomes partially received or received.

**Must hold:**
- Cumulative received quantity may never exceed ordered quantity; the excess is refused, not absorbed.
- A lot-tracked variant cannot be received without a lot; an expiry-controlled variant cannot be received without an expiry date; a serial-tracked variant receives exactly one unit per identifier.
- Concurrent receipts against the same line cannot over-receive.
- Receiving creates no accounting entry by itself; what we owe is recognised by the supplier bill (PU-09).

**Evidence:** Receipt operation with lines; lot identities; serialised units in custody; movements; updated purchase order line quantities; cost write-back.

**Alternate paths:**
- *Short delivery* → the order stays partially received; a human decides later whether to await the balance or short-close it with a reason.
- *Over-delivery* → refused at the line; the buyer must amend the order or reject the excess physically.
- *Wrong goods* → received to quarantine and dispositioned (IN-14), or refused at the door with nothing posted.

### IN-02 — Receive goods with no purchase order
**Trigger:** A sample, a free-of-charge replacement, a customer-owned item for service, or an opening balance.
**Actors:** Warehouse Operator, System Admin.
**Business flow:** The operator raises a receipt that names its business reason and, where relevant, the owning party; the same traceability requirements apply; the goods land in the condition and custody the reason implies.
**Must hold:** Customer-owned goods held for service must be distinguishable from company stock and must never become saleable availability.
**Evidence:** Receipt with stated reason and ownership; movements.

### IN-03 — Reserve stock so a promise is real
**Trigger:** An outbound document becomes a commitment — a quotation is accepted, an order is confirmed, or a delivery is made ready.
**Actors:** Sales, System.
**Business flow:**
1. The system creates a source-linked reservation naming the document that caused it, the variant, the warehouse, the quantity, and its expiry.
2. For tracked variants, the reservation carries **allocations**: which lots, which serialised units.
3. Available quantity drops; on-hand does not move.
4. The reservation is later consumed by delivery, released by cancellation, or expires.

**Must hold:**
- The aggregate reserved quantity always equals the sum of active allocations — a reconciliation invariant, not a report.
- A reservation is consumed, released, or expired **exactly once**.
- A serialised unit can be actively allocated to at most one reservation.
- Reservations are never hand-created or hand-edited; they originate from documents.

**Evidence:** Reservation with source reference, allocations, status, and expiry; reconciliation report showing zero divergence.

**Alternate paths:**
- *Reservation expires* → stock frees automatically and the sales document is flagged as no longer covered.
- *Stale hold* → an authorised operator releases it through the sanctioned release action, audited, and availability rises immediately.

### IN-04 — Deliver goods to a customer
**Trigger:** An accepted order is ready to ship, or an employee delivers during a visit.
**Actors:** Warehouse Operator, Employee, System.
**Business flow:**
1. The operator builds the delivery from the order: lines, quantities, source warehouse.
2. For tracked variants, the operator confirms the specific lots and serialised units leaving — FEFO suggested for expiry-controlled lots, explicit allocation where the profile demands it.
3. On confirmation, in one transaction, the reservation is consumed, the balance decreases for the right condition, serialised custody transfers out, and movements are written.
4. Shipment tracking is recorded separately, and arrival is confirmed by the customer or by the delivering employee.
5. The completed delivery becomes invoiceable.

**Must hold:**
- Delivery **decreases stock and recognises no tax**. This is the company's second defining accounting rule.
- Confirmation fails if stock is insufficient — no negative balances by accident.
- A delivery is invoiced at most once.
- Shipment tracking never posts stock; custody transfer is the stock event.

**Evidence:** Delivery operation with allocation detail; consumed reservations; movements; shipment record with confirmation identity and timestamp.

**Alternate paths:**
- *Short pick* → deliver what exists and leave the order partially fulfilled, transfer in first (IN-05), or procure (flow F-02).
- *Customer refuses at the door* → the goods come back as a customer return with inspection (IN-13), never as a quiet reversal.

### IN-05 — Transfer stock between warehouses
**Trigger:** Demand sits where the stock is not.
**Actors:** Warehouse Operators at both ends, System.
**Business flow:**
1. The operator raises a transfer naming source and destination warehouses and the lines with their allocations.
2. Dispatch decreases source custody and puts quantity in transit; movements are written.
3. Receipt at destination increases destination custody; movements are written; lot identities and serialised units retain their identity across the move.
4. Any difference between dispatched and received quantity is recorded as a **discrepancy with a reason and a disposition**, never silently absorbed.

**Must hold:**
- In-transit quantity is visible and belongs to neither end's saleable availability.
- Lot and serial identity survives a transfer; a transfer never creates or destroys traceability.
- Cancelling a transfer still in transit is an explicit decision with a stated outcome for the in-transit quantity.

**Evidence:** Transfer document; two sets of movements; in-transit position; discrepancy records.

**Alternate paths:** *Goods lost in transit* → the shortage is recorded as a discrepancy and written off through an approved adjustment, with the loss visible in reporting.

### IN-06 — Correct stock after a physical count
**Trigger:** A cycle count or stocktake finds system and reality disagreeing.
**Actors:** Warehouse Operator (counts), Inventory Manager (confirms).
**Business flow:**
1. The operator opens a count for a scope: a whole warehouse, a category, a variant set, or a lot.
2. The operator records counted quantities. For tracked variants the count is at lot and serial identity — not just an aggregate number.
3. The system computes variance per grain and shows its value.
4. A separate authorised person confirms the correction; on confirmation the balance moves to the counted quantity and movements are written with before and after values and the stated reason.

**Must hold:**
- Creating and confirming an adjustment are separate decisions.
- A tracked variant cannot be adjusted without naming the lot or serial identity — otherwise aggregate and lot balances silently diverge.
- Every adjustment carries a reason from a controlled list plus free text, and a historical adjustment is immutable.

**Evidence:** Adjustment document with counted-versus-system detail; confirmation identity; movements with before and after values.

**Alternate paths:** *Variance exceeds a materiality threshold* → escalate for a recount before confirmation.

### IN-07 — Move stock to a damaged condition
**Trigger:** Goods are found broken, contaminated, or otherwise unsaleable.
**Actors:** Warehouse Operator, Inventory Manager.
**Business flow:** The operator records the damage against specific lots or units with a cause; the quantity moves from saleable to damaged **condition** in the same warehouse; availability drops; on-hand does not.
**Must hold:** Damage is a *condition change*, not a disappearance. Physical removal is a separate, explicit disposal decision.
**Evidence:** Condition-change document; movements with before and after condition; damaged-stock work queue and alerts.

### IN-08 — Recover stock from damaged to saleable
**Trigger:** Inspection or rework finds the goods are fine.
**Actors:** Inventory Manager.
**Business flow:** The operator records recovery against the same lots or units; the condition reverses; availability rises; the reversal references the original damage record.
**Must hold:** Recovery is a new linked document, never an edit of the damage record.
**Evidence:** Recovery document referencing the damage; movements.

### IN-09 — Dispose of stock and accept the loss
**Trigger:** Damaged, expired, or obsolete stock must physically leave.
**Actors:** Inventory Manager, Accountant.
**Business flow:** An authorised person disposes named lots or units with a reason and, where required, disposal evidence; on-hand decreases; movements are written; the write-off value is reportable so finance can recognise the loss.
**Must hold:** Disposal always names what physically went, is never automatic, and is never reversible by edit — only by a compensating receipt if goods reappear.
**Evidence:** Disposal document with authorisation and evidence; movements; write-off value in reporting.

### IN-10 — Work the expiry queue
**Trigger:** Time passes.
**Actors:** Inventory Manager, System.
**Business flow:**
1. The system maintains a lot list ordered by nearest expiry, with days remaining, usable quantity, derived state — expired, expiring within the alert horizon, healthy, or no-expiry — and warehouse and product filters.
2. Expiring lots are surfaced as a work queue and pushed as alerts.
3. Outbound allocation suggests FEFO for expiry-controlled lots so the oldest usable stock leaves first.
4. Expired lots become non-saleable and route to disposal (IN-09) or supplier return (IN-15).

**Must hold:** Expired quantity is never quietly saleable; undated legacy lots stay visible but sort last rather than being treated as fresh.

**Evidence:** Expiry queue; alert history; FEFO suggestion recorded against the allocation actually chosen.

### IN-11 — Work the low-stock queue
**Trigger:** A balance crosses its reorder level.
**Actors:** Inventory Manager, Purchasing Manager, System.
**Business flow:** The system raises low-stock signals per variant per warehouse against reorder levels, showing on-hand, available, inbound coverage, and recent consumption, so a buyer can raise a purchase order (PU-02) or a transfer (IN-05) as a judgement call.
**Must hold:** Purchasing is not triggered automatically; the signal informs a human decision.
**Evidence:** Low-stock queue; the purchase order or transfer created in response, traceable to the signal.

### IN-12 — Consume stock for internal use
**Trigger:** A service record needs a spare part, or a department consumes goods internally.
**Actors:** Technician, Warehouse Operator.
**Business flow:** The consuming document names the variant, the warehouse, the quantity, and — for tracked variants — the lot and serialised unit; the balance decreases through the canonical posting path; the movement is linked back to the consuming document so cost lands on the right job.
**Must hold:**
- Internal consumption is never a bare stock decrement; it always carries its consuming document.
- Tracked stock cannot be consumed without allocations.
- Reversal compensates the **full** recorded quantity; a smaller correction is a full reversal followed by a new, smaller consumption.

**Evidence:** Consumption record paired with the movement it produced; reversal paired with its compensating movement.

### IN-13 — Take back goods from a customer
**Trigger:** A customer returns delivered goods — wrong item, damaged, over-supplied, or within a return window.
**Actors:** Warehouse Operator, Support or Sales, Inventory Manager.
**Business flow:**
1. The operator raises a **customer return document** referencing the delivery it reverses, with a reason per line.
2. The system validates that what is being returned was actually delivered, in that quantity, to that customer, from those lots or units — and refuses duplicate or over-returns.
3. Goods are inspected and given a **disposition**: back to saleable, to quarantine, to damaged, or onward to a supplier return.
4. On confirmation an inbound return movement is posted at the right condition; serialised custody comes back; lot identity is preserved.
5. Any financial consequence is a **separate, explicit** decision: a credit note (SL-12) or a refund (AC-12). The return posts no financial entry by itself.

**Must hold:**
- Return quantity per line is capped at delivered minus already returned.
- A return is never an adjustment, and a credit note is never a stock return; the two are linked but distinct documents.
- Customer-retained goods — credit without physical return — must be representable, with the stock consequence explicitly nil.

**Evidence:** Return document with source delivery, inspection outcome, disposition, and movements; the linked credit note or refund, if any.

**Alternate paths:**
- *Goods never come back* → credit note only; no movement.
- *Returned goods are unsaleable* → disposition to damaged or disposal, so the credit does not silently restore saleable availability.

### IN-14 — Disposition quarantined stock
**Trigger:** Stock sits in quarantine after receipt, return, or an inspection hold.
**Actors:** Inventory Manager, Quality reviewer.
**Business flow:** A named person inspects and decides: release to saleable, downgrade to damaged, dispose, or return to supplier; the decision, the inspector, and the reason are recorded; the condition change posts through the canonical path.
**Must hold:** Quarantine is a real condition with a decision owner and an ageing report — never a parking space that silently becomes saleable.
**Evidence:** Disposition decision with inspector and reason; movements.

### IN-15 — Send goods back to a supplier
**Trigger:** Received goods are defective, wrong, or over-delivered.
**Actors:** Purchasing Manager, Warehouse Operator.
**Business flow:**
1. The operator raises a **supplier return document** referencing the receipt and, where applicable, the customer return that produced the goods.
2. Named lots or units leave custody; outbound movements are posted; the return carries an expected outcome — replacement, credit, or refund.
3. Any financial consequence, such as a supplier credit or debit note, is a **separate, explicit** accounting decision, never created implicitly by the shipment.

**Must hold:** The stock leaves and the money conversation is tracked, but no financial document is fabricated by the warehouse.

**Evidence:** Supplier return document; movements; the linked supplier credit, when it arrives.

### IN-16 — Correct a posted stock document
**Trigger:** A confirmed receipt, delivery, or transfer is discovered to be wrong.
**Actors:** Inventory Manager, System Admin.
**Business flow:** The posted document is never edited. The operator raises the right *linked* correction — a compensating correction of the receipt, a customer return against the delivery, or a transfer cancellation or shortage record — which references the original and posts its own movements.
**Must hold:** Every post-commit change is a new document with a reference to the original. No path enables destructive history rewrite.
**Evidence:** Correction document linked to the original; both sets of movements intact.

### IN-17 — Prove the stock ledger reconciles
**Trigger:** Period close, audit, or a suspected divergence.
**Actors:** Inventory Manager, Reviewer, Accountant.
**Business flow:** The system proves, and reports: aggregate balance equals the sum of lot balances; aggregate reserved equals the sum of active allocations; serialised unit custody agrees with the aggregate; the movement ledger replays to the current balance for any variant, warehouse, and condition.
**Must hold:** A divergence is reported **prominently as an error** and is never rounded, suppressed, adjusted away, or plugged. The movement ledger is the audit record; the balance is the read model.
**Evidence:** Reconciliation report with explicit pass or fail per invariant.

### IN-18 — Answer "where is my stock and why can't I sell it?"
**Trigger:** Available is lower than on-hand and someone needs to know why.
**Actors:** Any authorised user.
**Business flow:** The stock position explains the gap by naming its causes: reserved quantity with the documents holding it, in-transit quantity with its transfers, and quarantined, damaged, or expired quantity with its condition records.
**Must hold:** Availability is always *explainable* to a named cause; "unavailable" is never an unexplained number.
**Evidence:** Stock position with cause breakdown and drill-through to the holding documents.

---

## 5. CRM Scenarios (CR)

### CR-01 — Capture a lead
**Trigger:** An inbound enquiry, a campaign response, a referral, an exhibition contact, or a field observation.
**Actors:** CRM Manager, Employee, System.
**Business flow:** The operator captures name, company, contact channels, and **source**, and the lead enters a qualification pipeline: new → contacted → qualified → converted or disqualified.
**Must hold:** Source is mandatory, because lead source is the only way to judge which acquisition channel works.
**Evidence:** Lead with source and pipeline state.
**Alternate paths:** *Lead already exists as a customer* → the enquiry is recorded as an interaction on the customer, not as a duplicate lead.

### CR-02 — Work a lead through activities
**Trigger:** The lead needs progressing.
**Actors:** Employee, CRM Manager.
**Business flow:** Every touch — call, email, meeting, field visit, demo — is recorded as a dated interaction naming the employee, with notes and outcome, and the lead's pipeline state advances or the lead is disqualified with a reason.
**Must hold:** Pipeline state changes only through a recorded interaction, so a stage move is always explainable. A dormant lead surfaces on a no-activity report rather than dying silently.
**Evidence:** Interaction history; stage-change trail with reasons.

### CR-03 — Convert a lead into a customer
**Trigger:** The lead agrees to trade.
**Actors:** CRM Manager.
**Business flow:** The lead becomes a customer (MD-01); the lead's interaction history follows onto the customer so first-sale context survives; the lead is marked converted and points at the customer it became.
**Must hold:** Conversion never loses history, and the lead is never duplicated as a second party.
**Evidence:** Converted lead with a link to its customer; migrated interaction history.

### CR-04 — Manage an opportunity toward a quotation
**Trigger:** A named need with an estimated value appears — from a lead, an existing customer, a field visit, or an AI-detected voice-note signal.
**Actors:** Employee, Sales Manager, System.
**Business flow:**
1. The operator records the opportunity: party, need, estimated value, expected close date, stage, and origin.
2. Activities progress it: qualification, needs analysis, demo, proposal.
3. The opportunity produces a quotation and carries its summary onto it, so the quotation knows where it came from.
4. The opportunity closes won when the quotation is accepted, or closed lost with a reason.

**Must hold:**
- An opportunity's origin is never lost — especially an AI-detected origin, which must remain visibly AI-originated and human-approved.
- Closing lost requires a reason from a controlled list, so the loss report means something.

**Evidence:** Opportunity with origin, stage history, value, and the quotation it produced.

### CR-05 — Log a customer interaction against an existing relationship
**Trigger:** Any contact with an existing customer.
**Actors:** Employee, Support, CRM Manager.
**Business flow:** The interaction is recorded against the customer with type, employee, timestamp, and notes, and is visible on one customer timeline together with quotations, orders, invoices, payments, tickets, visits, and maintenance.
**Must hold:** One customer, one timeline — the account manager should never have to assemble the relationship from five screens.
**Evidence:** Unified customer activity timeline.

### CR-06 — Run a marketing campaign
**Trigger:** Marketing decides to reach a segment.
**Actors:** CRM Manager, System, external delivery provider.
**Business flow:**
1. The operator defines the campaign: name, channel, content, schedule.
2. The operator builds the recipient list from customers and leads by segment criteria.
3. The system sends on schedule and records per-recipient send outcome.
4. Responses — opened, clicked, replied, interested, unsubscribed — are recorded per recipient.
5. Interested responses become leads or opportunities and stay attributable back to the campaign.

**Must hold:**
- Unsubscribe and consent state is respected on every send; a suppressed recipient is never contacted again by that channel.
- Send failures are recorded, not silently dropped.

**Evidence:** Campaign with recipients, send log, and response log; the leads and opportunities attributed to it.

### CR-07 — Measure the funnel end to end
**Trigger:** Management asks which acquisition works.
**Actors:** CRM Manager, System Admin, Reviewer.
**Business flow:** Report leads by source and stage, conversion rates at each stage, campaign response rates, opportunity pipeline value and age, and — critically — **campaign and source through to invoiced and collected revenue**.
**Must hold:** The funnel must be traceable to money, not stop at "interested". Every stage transition is dated so ageing and velocity are computable.
**Evidence:** Funnel report joining CRM origin to sales and collection outcomes.

### CR-08 — Explain a customer's price to their face
**Trigger:** A customer challenges a price, or an internal reviewer audits discounting.
**Actors:** Pricing Manager, CRM Manager, Reviewer.
**Business flow:** For a customer and variant, the system shows the resolved price, the rule that produced it, the rules that lost and why, the floor position, and — where the floor was breached — the approval that permitted it.
**Must hold:** Every price is explainable and non-stacked, and every exception has a named approver.
**Evidence:** Price preview with provenance; floor override records; pricing-decision audit review.

---

## 6. Sales Scenarios (SL)

**Governing lifecycle:** Quotation → Order → Delivery → Invoice → Payment, with Credit Note as the non-destructive correction path.

### SL-01 — Turn an opportunity into a quotation
**Trigger:** An opportunity reaches proposal stage, or a customer requests a quotation directly.
**Actors:** Employee, System Admin, Customer, System.
**Business flow:**
1. The author selects the customer; the customer's payment term and pricing position pre-populate.
2. The author adds lines by variant and quantity; each line's price resolves through the tier rules and records its provenance; each line's tax defaults from policy with a per-line override.
3. The system computes subtotal, tax total, and grand total, and stamps a validity period from policy.
4. The author may check availability inline and see what can be promised from stock versus inbound.
5. The author sends the quotation; a PDF is produced and can be emailed.

**Must hold:**
- A quotation **never touches stock** in any state. It is a commercial offer.
- No line may go below the variant floor without a recorded System Admin approval.
- Sending snapshots prices and totals; a later tier or base-price change never rewrites a sent quotation.
- The same variant may legitimately appear twice at different prices before sending.

**Evidence:** Quotation with numbered lines, price provenance, totals, validity, and sent timestamp; PDF; audit entry.

**Alternate paths:** *Customer wants options* → alternative quotations are separate documents, each independently decidable.

### SL-02 — Record the customer's decision on a quotation
**Trigger:** The customer accepts or rejects.
**Actors:** Customer (deciding), Employee or System Admin (recording), System.
**Business flow:**
1. The decision is recorded against the quotation with who recorded it, when, and the customer's stated reason.
2. Acceptance moves the quotation to accepted and makes it convertible.
3. Rejection closes it with a reason and closes the originating opportunity as lost.
4. A decision attempted after the validity date is refused and the quotation is marked expired.

**Must hold:**
- A quotation's decision is recorded **exactly once**, and both the decider's identity and the recorder's identity are preserved.
- Expiry is enforced on decision *and* derived for display, so a stale offer never looks live.

**Evidence:** Decision with recorder, timestamp, and note; expiry state.

**Alternate paths:**
- *Expired but the customer still wants it* → a new quotation is raised, optionally copied, so the price re-resolves against today's policy.
- *Partial acceptance* → a revised quotation for the accepted scope; the original is rejected or superseded, never silently trimmed.

### SL-03 — Convert an accepted quotation into an order
**Trigger:** The quotation is accepted.
**Actors:** Sales, System.
**Business flow:**
1. The system creates one order per quotation, carrying the customer, the payment term, the priced lines, and the totals.
2. The order becomes the fulfilment and money-tracking spine: it carries a **fulfilment state** and an independent **payment state**.
3. Where a line has no stock coverage, the order enters a pending state with an explicit reason and routes to supplier confirmation or procurement.

**Must hold:**
- One order per quotation — never two.
- Fulfilment state and payment state are separate axes; an order can be completed and unpaid at once.
- The order itself posts nothing to the ledger.

**Evidence:** Order linked to its quotation; priced lines; two independent state axes.

**Alternate paths:** *Customer orders without a quotation* → a direct order is valid; prices still resolve through the tier rules and floor control still applies.

### SL-04 — Ask a supplier whether a pending order can be supplied
**Trigger:** An accepted order cannot be covered from stock.
**Actors:** System Admin or Purchasing Manager, Supplier (externally), System.
**Business flow:**
1. The operator records a supplier confirmation request against the order.
2. The operator contacts the supplier outside the system and records the answer: confirmed with a promised date, or rejected, with notes.
3. A confirmed answer moves the order forward and triggers a purchase order (PU-02) or reserves inbound coverage.
4. A rejected answer routes to an alternative supplier, a substitute variant, or cancellation with the customer informed.

**Must hold:**
- Supplier answers are **append-only**: once confirmed or rejected the answer is immutable, and a correction is a *new* confirmation so the original survives as evidence.
- A promised date may not precede the document's order date.
- There is no supplier portal; a human always records the answer, and the record names them.

**Evidence:** Append-only supplier confirmation history with promised dates and notes; order state and pending reason.

### SL-05 — Fulfil an order from stock
**Trigger:** The order is covered and ready to ship.
**Actors:** Warehouse Operator, Employee, System.
**Business flow:** Reservations are created (IN-03) and consumed by a confirmed delivery (IN-04); the order's fulfilment state advances to delivering and then completed; partial fulfilment leaves the balance outstanding and visible.
**Must hold:** Delivery decreases stock and recognises no tax; an order is never marked complete while any line remains undelivered without an explicit short-close decision.
**Evidence:** Delivery operations linked to the order; consumed reservations; order fulfilment state.

### SL-06 — Issue an invoice for what was delivered
**Trigger:** A delivery completes.
**Actors:** Accountant or System Admin, System.
**Business flow:**
1. The operator creates the invoice from the completed delivery; lines, quantities, and prices carry from the delivered document.
2. The payment term resolves a concrete due date, stored with the term reference.
3. Issuing the invoice posts **one balanced entry: debit receivable, credit revenue, credit deferred tax** — never tax payable.
4. A PDF is generated, and the invoice can be emailed to the customer.
5. The customer's receipt of the invoice can be confirmed, with a signature captured as evidence.
6. The invoice becomes the receivable that ageing, reminders, and collection work from.

**Must hold:**
- A delivery is invoiced **at most once**.
- Once issued, the invoice's customer, lines, and totals are immutable, and the document is never deletable by any path.
- Revenue is recognised on issuance; **tax is not**.
- Overdue is derived from due date plus grace days — never stored.

**Evidence:** Invoice with source delivery, resolved due date, and totals; the balanced journal entry; PDF; email log; receipt confirmation with signature.

**Alternate paths:**
- *Consolidated invoicing* → one invoice covering several completed deliveries for the same customer, each delivery still invoiced only once.
- *Wrong invoice issued* → never edited; corrected by credit note (SL-12), optionally followed by a corrected invoice.

### SL-07 — Chase an overdue receivable
**Trigger:** An invoice passes due date plus grace.
**Actors:** System, Accountant, Employee.
**Business flow:** The invoice derives as overdue; a reminder is sent to the customer through the configured channel; the ageing report buckets it; the account manager sees it on the customer timeline and can escalate.
**Must hold:** A reminder is never sent for an invoice already settled or credited, and every send is logged so the collection conversation is evidenced.
**Evidence:** Derived overdue status; reminder and email log; ageing report.

### SL-08 — Collect money and recognise tax proportionally
**Trigger:** The customer pays — in cash, by transfer, by cheque, or by card.
**Actors:** Customer, Payments Officer, payment provider, System.
**Business flow:**
1. The collection is recorded with method, amount, date, reference, and proof where the method requires it. An online collection enters through the provider's confirmation, processed **idempotently**.
2. The payment is allocated across one or more invoices; allocation total may not exceed the payment.
3. For each allocation, the system recognises tax **in proportion to the collected share** of that invoice and writes a tax recognition entry.
4. Posting debits the method's cash or bank account, credits receivable, and moves the recognised tax from deferred to payable.
5. The invoice's paid amount advances and its state derives to partially paid or paid.

**Must hold:**
- Tax is recognised **only** on collection, and partial collection recognises tax proportionally.
- The allocation that settles an invoice recognises the exact remaining tax, so the sum across an invoice's collections equals its tax total with **no rounding drift**.
- Every channel uses the same posting and recognition logic; a channel is a record, never a second code path.
- Tax recognition entries are append-only.
- Paid amount may never exceed the grand total after credits.

**Evidence:** Payment with method and proof; allocations; tax recognition entries; balanced journal entry; invoice paid amount and derived state.

**Alternate paths:**
- *Overpayment* → the excess becomes an available credit balance, refundable only through the approved refund path (AC-12), never a negative payment.
- *Payment covers several invoices* → one payment, several allocations, several tax recognition entries summing to the allocated total.
- *Provider retries a callback* → idempotency means one payment record, one recognition, one posting.
- *Payment bounces* → reversed by an explicit compensating document that un-recognises the tax it recognised; never deleted.

### SL-09 — Prepay before delivery
**Trigger:** Terms require money up front.
**Actors:** Payments Officer, Customer, System.
**Business flow:** The collection is recorded against the order as a customer advance and held as a liability; on invoice issuance the advance is applied to the invoice and tax is recognised for the applied amount at that moment.
**Must hold:** An advance is a liability until there is an invoice to apply it to; tax recognition still follows collection-against-an-invoice, never an unapplied deposit.
**Evidence:** Advance record; application to the invoice; tax recognised on application.

### SL-10 — Confirm the customer received the invoice
**Trigger:** Delivery of the invoice document itself.
**Actors:** Employee, Customer, System.
**Business flow:** The employee or customer confirms receipt; a signature is captured and attached to the confirmation; the invoice reflects the confirmed-received state.
**Must hold:** Receipt confirmation is evidence of *delivery of the document*, never evidence of payment, and never a posting event.
**Evidence:** Invoice confirmation with signer identity, timestamp, and signature attachment.

### SL-11 — Cancel before commitment
**Trigger:** A quotation or an order is abandoned before delivery.
**Actors:** Sales, System Admin.
**Business flow:** The document is cancelled with a reason; any reservations it holds are released and availability rises immediately; the originating opportunity closes lost.
**Must hold:** Cancellation of an uncommitted document is permitted and audited; cancellation of a *delivered* order is not — that path is return plus credit note.
**Evidence:** Cancellation with reason; released reservations; audit entry.

### SL-12 — Correct or reverse an invoice with a credit note
**Trigger:** An invoice is wrong, goods came back, a discount was agreed late, or the sale is reversed.
**Actors:** Accountant, System Admin.
**Business flow:**
1. The operator raises a credit note against the invoice with a reason from a controlled category, and lines that pair with the invoice's lines.
2. Each credited quantity is capped at that invoice line's uncredited remainder, and the document total at the invoice's uncredited total.
3. Confirming the credit note posts **one balanced entry: debit revenue; debit deferred tax and/or tax payable, split by the ratio of that invoice's tax already recognised; credit receivable**.
4. The invoice's credited amount advances and its state derives to credited where fully reversed.
5. A PDF is produced and can be sent.
6. Any physical return is a **separate** inventory document (IN-13); any money going back is a **separate** refund (AC-12).

**Must hold:**
- Confirmed financial documents are corrected, never deleted. A draft credit note may be edited or discarded; a confirmed one is immutable.
- The tax split must never be produced by independently rounding both tax lines — that can unbalance the entry.
- A credit note is **not** a stock return and must never be treated as one.

**Evidence:** Credit note with reason category, capped lines, and the balanced reversal entry; PDF; the linked return and refund, if any.

**Alternate paths:**
- *Cancel and reissue* → a full credit note, then a corrected new invoice, both linked, so the trail shows what was wrong and what replaced it.
- *Goods retained by the customer* → credit note only, with the stock consequence explicitly nil.

### SL-13 — Serve a customer through their own app
**Trigger:** A customer wants self-service.
**Actors:** Customer, System.
**Business flow:** The customer logs in; browses products at their own resolved prices; requests a quotation or an order; tracks the status of quotations, orders, deliveries, and invoices; receives reminders; pays online; opens support and maintenance requests; and sees their own documents only.
**Must hold:**
- A customer may only ever see and act on their own records.
- A customer's decision made in-app and a customer's decision recorded by staff must produce the **same** business record, so the flow does not fork by channel.

**Evidence:** Customer-scoped document access; customer-originated documents; notification log.

### SL-14 — Sell during a field visit
**Trigger:** An employee closes business on site.
**Actors:** Employee, Customer, System.
**Business flow:** The employee raises a quotation on the spot at authorised prices, records the customer's decision, and — where the goods are on the van or at the local warehouse — creates the delivery, all attached to the visit that produced them.
**Must hold:** Field-created documents obey exactly the same floor controls, availability checks, reservation rules, and posting rules as dashboard-created ones. The van is a warehouse.
**Evidence:** Quotation and delivery linked to the visit and the employee; movements from the van warehouse.

### SL-15 — Report the sales engine
**Trigger:** Management review.
**Actors:** System Admin, Reviewer.
**Business flow:** Report quotation volume and win rate by employee, customer, and product; conversion velocity through each lifecycle stage; delivered-not-invoiced exposure; invoiced-not-collected ageing; recognised versus deferred tax; margin where cost is known; and discount and floor-override incidence.
**Must hold:** **Delivered-not-invoiced** and **invoiced-not-collected** must be first-class reports — they are the two places money silently leaks in a Quotation → Delivery → Invoice → Payment model.
**Evidence:** Sales, receivable ageing, tax register, and exception reports.

### SL-16 — Reprice or re-quote after a policy change
**Trigger:** Prices, tiers, or tax rates change while offers are open.
**Actors:** Pricing Manager, Sales.
**Business flow:** Open drafts re-resolve to current policy on next edit; sent quotations keep their snapshot; a customer who wants the old price needs a decision, and a customer who wants a new price gets a new quotation.
**Must hold:** A sent offer is a commitment; policy changes never rewrite it retroactively.
**Evidence:** Snapshotted prices on sent documents; new documents carrying new resolution.

---

## 7. Purchasing Scenarios (PU)

### PU-01 — Qualify and maintain a supplier relationship
**Trigger:** Sourcing needs a new or revised supply arrangement.
**Actors:** Purchasing Manager.
**Business flow:** Beyond MD-03, the buyer maintains what the supplier supplies, at what cost, with what lead time, under what payment terms — and reviews actual received cost and delivery performance to decide whether to keep buying there.
**Must hold:** Cost references are defaults refreshed by reality through received-cost write-back, never a frozen price list.
**Evidence:** Supplier product references with cost provenance and last-received cost.

### PU-02 — Raise a purchase order as a commitment to spend
**Trigger:** A low-stock signal, a confirmed customer back-order, a project need, or a planned buy.
**Actors:** Buyer, System.
**Business flow:**
1. The buyer creates a draft order for one supplier delivered to one warehouse, in one document currency.
2. The buyer adds lines: variant, ordering unit, quantity, and unit cost defaulted from the supplier reference, with per-line expected dates.
3. The system computes line and document totals and assigns a document number.
4. Where the order exists to cover a customer order, the link is recorded so coverage is traceable.

**Must hold:**
- All lines share the document currency; no implicit conversion.
- A purchase order commits money but creates **no** accounting entry and **no** stock.
- Each variant-and-unit combination appears at most once per order, so received quantity can never be ambiguously attributed.

**Evidence:** Draft purchase order with lines, cost provenance, totals, and coverage links.

### PU-03 — Approve spending against a threshold
**Trigger:** A buyer submits a purchase order.
**Actors:** Buyer, Purchasing Manager, System.
**Business flow:**
1. The buyer submits the order for approval; the submitter and time are recorded.
2. Orders at or below the configured threshold auto-approve on submission, with the approver recorded as the submitter so the record is honest about what happened.
3. Orders above the threshold require an explicit Purchasing Manager decision: approve, or reject with a reason.
4. An order in a currency other than the threshold currency always routes to explicit approval — no conversion is performed.

**Must hold:**
- A threshold of zero means everything needs explicit approval — the safe default until the owner sets a value.
- Approval identity and timestamp are written by the system, never editable on a form.

**Evidence:** Submission and approval or rejection identities, timestamps, and rejection reasons.

**Alternate paths:** *Rejected* → returns to draft for revision with the reason visible, or is cancelled.

### PU-04 — Send the order and freeze the commitment
**Trigger:** The order is approved and goes to the supplier.
**Actors:** Buyer, System.
**Business flow:** The order is marked sent with a timestamp; from that moment supplier, warehouse, currency, lines, quantities, and costs are **immutable**; a change requires cancellation and a new order, or a documented amendment.
**Must hold:** Transmission is the immutability boundary. What the supplier was told is what the system holds.
**Evidence:** Sent timestamp; immutable order content; the document sent.

### PU-05 — Record the supplier's acknowledgement
**Trigger:** The supplier answers.
**Actors:** Buyer (recording), Supplier (externally).
**Business flow:** The buyer records the supplier's confirmation against the purchase order — confirmed with a promised date, or rejected — with notes; the promised date drives expediting and the inbound coverage the sales side relies on.
**Must hold:** Append-only, as SL-04. Corrections are new confirmations.
**Evidence:** Confirmation history with promised dates.

### PU-06 — Receive against the order
**Trigger:** Goods arrive.
**Actors:** Warehouse Operator, System.
**Business flow:** As IN-01, with the purchasing consequences: received quantities advance, actual cost is written back, and the order becomes partially received or received.
**Must hold:** Purchasing never writes stock itself; all received stock is posted through the canonical inventory path, and the receipt names the purchase order as its source document.
**Evidence:** Receipt operation referencing the purchase order; updated line quantities; cost write-back.

### PU-07 — Close a short-received order deliberately
**Trigger:** The supplier will not deliver the balance.
**Actors:** Purchasing Manager.
**Business flow:** A human short-closes the order with a stated reason; the outstanding quantity is abandoned explicitly and stops appearing as inbound coverage; any customer order depending on that coverage is flagged.
**Must hold:** Short-close is **never automatic** — abandoning a commitment is a business decision with an owner.
**Evidence:** Closure timestamp, actor, and reason; recomputed inbound coverage; flagged dependent orders.

### PU-08 — Cancel a purchase order
**Trigger:** The need disappears before receipt.
**Actors:** Purchasing Manager.
**Business flow:** The order is cancelled with a reason; inbound coverage disappears; dependent customer orders are flagged. An order with any received quantity cannot be cancelled — it is short-closed instead.
**Must hold:** History is preserved; a partially received order never pretends it never existed.
**Evidence:** Cancellation timestamp, actor, and reason.

### PU-09 — Record what the supplier says we owe
**Trigger:** A supplier invoice arrives.
**Actors:** Payables Officer, Accountant.
**Business flow:**
1. The operator records the bill: supplier, the supplier's own invoice number, dates, payment term, and priced lines each carrying its expense or asset account.
2. Where a line references a purchase order line, the system shows ordered, cumulative received, and cumulative billed quantities and **flags** quantity and price variances.
3. The approver reviews the variance and approves or holds. Approval recognises the payable: debit the line accounts, credit the payable control account, and recognise purchase tax per policy.
4. The bill's due date derives from the payment term and drives the payables ageing.

**Must hold:**
- The supplier's own invoice number is unique per supplier — this is the primary **duplicate-payment control**.
- The three-way match is **advisory, not blocking**: the approver is the control. A blocking rule would make a legitimate over-delivery or partial bill unrecordable and push the accountant outside the system.
- Approval identity is separate from the recorder's.

**Evidence:** Bill with lines and accounts; match variance display; approval identity; balanced payable entry.

**Alternate paths:** *Bill precedes receipt* → recordable, with the match showing nothing received yet, so the approver sees exactly that risk.

### PU-10 — Pay a supplier
**Trigger:** Bills fall due and cash is available.
**Actors:** Payments Officer, Accountant.
**Business flow:** The payment is recorded with method, date, and reference, and **allocated across one or more bills**; posting debits the payable control account and credits the method's cash or bank account; each bill's paid amount advances and its state derives to partially paid or paid.
**Must hold:** Allocation total may not exceed the payment; supplier payments are a separate document family from customer payments and are never netted against receivables automatically.
**Evidence:** Supplier payment with allocations; balanced entry; bill states.

### PU-11 — Record and pay an expense without a supplier bill
**Trigger:** A staff expense, a petty-cash purchase, a utility charge.
**Actors:** Requesting Employee, Payables Officer, Payments Officer.
**Business flow:** The expense is captured with its expense account, optional supplier and requesting employee, and a receipt attachment; approval recognises the payable; payment clears it.
**Must hold:** Approval and payment are separate decisions with separate owners, and a receipt attachment is the evidence an auditor will ask for.
**Evidence:** Expense with account, approval identity, and receipt attachment; the two postings.

### PU-12 — Prove the payables subledger against the ledger
**Trigger:** Period close or audit.
**Actors:** Accountant, Reviewer.
**Business flow:** The system computes, per supplier, billed, paid, and outstanding amounts, aged against bill due dates with per-supplier drill-down, and **reconciles the total against the payable control account** as an explicit displayed proof.
**Must hold:** The subledger is **computed, never stored** — a stored balance is a cache able to disagree with the documents. Any difference is shown prominently as an error and is never adjusted, rounded, suppressed, or plugged.
**Evidence:** Payables ageing with reconciliation proof and named difference, if any.

---

## 8. Accounting Scenarios (AC)

**Governing principles:** the ledger is the consequence of business documents, not a parallel truth; exactly the named document events post automatically; revenue on issuance, **tax on collection**; nothing confirmed is ever deleted.

### AC-01 — Post a manual journal entry
**Trigger:** An accounting event with no source document — an accrual, a reclassification, an opening entry.
**Actors:** Accountant.
**Business flow:** The accountant drafts the entry with balanced debit and credit lines against postable accounts, dated into an open period, with a narrative; posting validates the balance and locks the entry.
**Must hold:**
- Debits must equal credits before posting; an unbalanced entry cannot post.
- A posted entry is immutable; correction is a **reversing entry** that references the original.
- Only postable leaf accounts receive lines.

**Evidence:** Journal entry with balanced lines, narrative, and poster identity; any reversing entry linked to it.

### AC-02 — Recognise revenue when an invoice is issued
**Trigger:** SL-06.
**Actors:** System.
**Business flow:** One balanced entry: debit receivable, credit revenue, credit deferred tax, source-linked to the invoice.
**Must hold:** Tax payable is never credited here. Revenue and tax are deliberately decoupled in time.
**Evidence:** Journal entry source-linked to the invoice.

### AC-03 — Recognise tax when money is collected
**Trigger:** SL-08.
**Actors:** System.
**Business flow:** For each allocation: debit the method's cash or bank account, credit receivable, and move the collected proportion of tax from deferred to payable, writing an append-only tax recognition entry.
**Must hold:** Proportional recognition, exact remainder on settlement, zero rounding drift, one recognition per invoice-and-payment allocation, and no recognition for a zero-tax invoice.
**Evidence:** Tax recognition entries; balanced entry; the tax register reconciling to the tax payable account.

### AC-04 — Reverse revenue and tax on a confirmed credit note
**Trigger:** SL-12.
**Actors:** System.
**Business flow:** One balanced entry: debit revenue; debit deferred tax and/or tax payable, split by the ratio of that invoice's tax already recognised; credit receivable.
**Must hold:** The split is computed so the entry balances exactly — never by independently rounding both tax lines.
**Evidence:** Journal entry source-linked to the credit note.

### AC-05 — Maintain a receivables subledger that proves itself
**Trigger:** Ongoing credit control; period close.
**Actors:** Accountant, Reviewer.
**Business flow:** Per customer: invoiced, collected, credited, and outstanding amounts, aged against due dates with drill-down to documents, reconciled against the receivable control account as an explicit displayed proof.
**Must hold:** Derived, never stored. Any difference is shown as an error, never plugged.
**Evidence:** Receivables ageing with reconciliation proof.

### AC-06 — Maintain the tax register
**Trigger:** A tax filing period ends.
**Actors:** Accountant.
**Business flow:** The register lists, per period: tax charged on issued invoices as deferred, tax recognised on collections as payable, tax reversed by credit notes and refunds, and purchase tax recognised on approved bills — reconciling to the deferred tax and tax payable accounts.
**Must hold:** The register must reconcile to the ledger and must make the deferred-versus-payable distinction explicit, because that distinction *is* the company's tax policy.
**Evidence:** Tax register with reconciliation to both tax accounts.

### AC-07 — Recognise what we owe
**Trigger:** PU-09 or PU-11 approval.
**Actors:** System.
**Business flow:** Debit the bill or expense line accounts; credit the payable control account; recognise purchase tax per policy; source-link the entry.
**Must hold:** Recognition happens at **approval**, not at recording — an unapproved bill is not yet a liability.
**Evidence:** Journal entry source-linked to the bill or expense.

### AC-08 — Clear a payable
**Trigger:** PU-10 or PU-11 payment.
**Actors:** System.
**Business flow:** Debit the payable control account; credit the method's cash or bank account; source-link the entry; advance the bill or expense state.
**Evidence:** Journal entry source-linked to the payment.

### AC-09 — Report the financial position
**Trigger:** Management or statutory reporting.
**Actors:** Accountant, Reviewer.
**Business flow:** From the ledger: a trial balance that balances; a general ledger drill-down by account and period; a profit and loss for a period; a balance sheet as at a date — each reconciling to the same journal lines.
**Must hold:** Reports are read-only derivations of the ledger; no report ever adjusts a balance, and every report figure drills through to the documents behind it.
**Evidence:** Trial balance, general ledger, profit and loss, and balance sheet, each drill-through capable.

### AC-10 — Close a period safely
**Trigger:** The month or year ends.
**Actors:** Accountant.
**Business flow:** The accountant runs the reconciliations (AC-05, AC-06, PU-12, IN-17), resolves exceptions, verifies the trial balance, then closes the period explicitly; postings into a closed period are refused thereafter.
**Must hold:** A period cannot be closed while a mandatory reconciliation shows a difference. Closing is audited, and reopening requires an authorised decision.
**Evidence:** Reconciliation pack; close record with actor and cut-off.

### AC-11 — Trace any figure to its cause
**Trigger:** An auditor asks why a number is what it is.
**Actors:** Reviewer, Accountant, auditor.
**Business flow:** From any ledger line, navigate to its source document; from any document, see every entry it produced; from any balance, replay the lines that built it.
**Must hold:** Every automated entry is source-linked by document type and identity. There are no orphan postings and no unexplained balances.
**Evidence:** Source-linked journal entries; two-way document-to-entry navigation.

### AC-12 — Refund money to a customer
**Trigger:** A confirmed credit note or an overpayment leaves the customer in credit and they want the cash back.
**Actors:** Payments Officer (records), Accountant or System Admin (approves), System.
**Business flow:**
1. The operator records the refund: customer, amount, method, date, and the credit it draws on.
2. A **different** authorised person approves it.
3. Payment posts: the refund leaves the cash or bank account, and the **recognised tax is reversed proportionally**, because refunding collected money must un-recognise the tax that collection recognised.
4. The customer's available credit balance decreases.

**Must hold:**
- Recording and approving a refund are **separate permissions held by different roles** — money leaving the business is the one operation whose reversal is never free.
- A refund may **only** be paid against an available credit balance computed from confirmed credit notes and overpayments, and may never exceed it. Otherwise the refund surface becomes a way to move money out with no document behind it.
- A refund is **never** modelled as a negative collection — that would corrupt every sum built on collections, including proportional tax recognition.

**Evidence:** Refund with an approver distinct from the recorder; balanced posting; proportional tax reversal; recomputed credit balance.

### AC-13 — Write off an uncollectable receivable
**Trigger:** Collection efforts are exhausted.
**Actors:** Accountant, System Admin.
**Business flow:** An authorised write-off document reverses the receivable against a bad-debt expense account, with a reason and an approver, handling the associated deferred tax per policy; the invoice shows as written off, never as paid.
**Must hold:** Write-off never masquerades as collection, and never recognises tax as if money arrived.
**Evidence:** Write-off document with approver and reason; balanced entry; invoice state.

### AC-14 — Recognise inventory value and cost of sales
**Trigger:** Goods are received and later delivered.
**Actors:** Accountant, System.
**Business flow:** Receipt values inventory as an asset at received cost; delivery relieves inventory and recognises cost of goods sold against the revenue the invoice recognised; write-offs and disposals expense the loss; the inventory account reconciles to the valued stock position.
**Must hold:** Margin is only real when cost lands in the same period as revenue. The valuation basis must be stated, applied consistently, and reconcilable to the stock ledger.
**Evidence:** Valued stock position reconciling to the inventory account; cost-of-sales entries linked to deliveries.

> *Documentary note:* the current documentation set explicitly defers inventory valuation and cost-of-goods-sold posting. This scenario is included because an ERP that reports margin without it reports revenue only — it is a named gap, not an oversight.

### AC-15 — Handle a foreign-currency transaction
**Trigger:** A supplier bills, or a customer pays, in another currency.
**Actors:** Accountant.
**Business flow:** The document holds its transaction currency and rate; the ledger holds the functional-currency amount; settlement at a different rate produces an explicit exchange gain or loss; open balances are revalued at period end per policy.
**Must hold:** A rate is snapshotted onto the document; the ledger is never silently mixed-currency; gains and losses are recognised explicitly, not absorbed into revenue or expense.
**Evidence:** Document currency and rate; functional-currency postings; exchange difference entries.

> *Documentary note:* multi-currency is currently out of scope, yet purchase orders already carry a document currency and a threshold currency that refuses conversion. That is a coherent interim position; this scenario records the target behaviour.

### AC-16 — Keep automated posting narrow and named
**Trigger:** A new document type wants to post to the ledger.
**Actors:** Accountant, System Admin, Product Owner.
**Business flow:** The set of documents that post automatically is an explicit, named list with an owner decision behind each entry. Adding a posting path is a deliberate governance act, not a side effect of building a feature.
**Must hold:** Every automated posting caller is named and source-linked. No document acquires a posting path implicitly.
**Evidence:** The named posting-event list; source-linked entries proving nothing else posts.

---

## 9. Employee Scenarios (EM)

### EM-01 — Build a monthly plan an employee is measured on
**Trigger:** A new month, or a new employee.
**Actors:** Employee Manager.
**Business flow:**
1. The manager creates one plan per employee per month with targets and four **weighted evaluation factors** — task completion, visit completion, schedule adherence, and work-time adherence — whose weights must sum to exactly 100.
2. The manager adds tasks and planned visits inside the plan.
3. The plan is activated and becomes the employee's operating agenda and the basis of their score.

**Must hold:**
- Weights sum to exactly 100 — an employee must never be measured against an incoherent scale.
- One active plan per employee per month.
- A plan's weights and targets are snapshotted into the score it produces, so a later plan edit cannot silently rewrite history.

**Evidence:** Plan with weights, targets, tasks, and planned visits; activation audit.

**Alternate paths:** *Copy last month's plan* → the copy is an **independent** record with **no execution history carried over**, to another employee or another month.

### EM-02 — Execute and track a task
**Trigger:** A task in an active plan.
**Actors:** Employee, Employee Manager.
**Business flow:** The task carries mandatory start and end dates and an optional customer link; the employee advances it pending → in progress → completed, or it is cancelled; every transition is appended to an immutable status history with actor and timestamp.
**Must hold:** The status history is **append-only** — the record of how work actually progressed is never rewritten. An overdue task surfaces on a work queue rather than expiring quietly.
**Evidence:** Task with dates, customer link, and append-only status history.

### EM-03 — Plan and execute a customer visit
**Trigger:** A planned visit, or an unplanned field call.
**Actors:** Employee, Customer, System.
**Business flow:**
1. The visit is planned against a customer with a purpose and a window, or created ad hoc in the field.
2. The employee checks in — capturing location and time — conducts the visit, records notes and attachments such as photos and signed documents, and checks out.
3. Visit duration derives from check-in and check-out, feeding work-time adherence.
4. The visit may produce a quotation, a delivery, an opportunity, a ticket, or a maintenance request, all linked to it.
5. The visit closes as completed, or is marked missed with a reason.

**Must hold:**
- A field-recorded visit's captured data is **not editable** by a reviewer; a reviewer may only *add a review note*. Field evidence stays field evidence.
- The recording channel — dashboard versus field — is explicit, so an office-entered visit is never mistaken for a GPS-verified one.
- A visit with no check-out is incomplete and surfaces as an exception rather than scoring as complete.

**Evidence:** Visit with check-in and check-out times, location, channel, notes, attachments, derived duration, and any documents it produced.

### EM-04 — Verify where an employee actually was
**Trigger:** Assurance, a customer dispute, or an expense query.
**Actors:** Reviewer, Employee Manager.
**Business flow:** The visit's GPS trail is reviewable as an ordered set of positions with timestamps, shown against the customer's location, and the check-in position is compared to the expected location so a mismatch is visible.
**Must hold:** GPS is evidence: append-only, never editable, and its absence is recorded as absence rather than assumed compliance. Location data is visible only to authorised reviewers.
**Evidence:** GPS log with timestamps; check-in-versus-expected-location comparison; review notes.

### EM-05 — Capture a voice note in the field
**Trigger:** The employee has something to record and no time to type.
**Actors:** Employee, System.
**Business flow:** The employee records audio against the visit; the file is stored privately; a transcription job is queued; the note's state moves pending → processing → transcribed, or failed.
**Must hold:**
- **AI failure must never block visit completion.** A failed transcription is recorded on the transcription record and changes nothing about the visit, the score, or the salary.
- Audio is private, served only through authenticated, signed access.

**Evidence:** Voice note with state; the audio as a private attachment.

### EM-06 — Review a transcription honestly
**Trigger:** A transcription completes.
**Actors:** Reviewer, System.
**Business flow:** The reviewer sees the transcript text alongside a **labelled confidence indicator** whose source is explicit — provider-reported, derived from log-probabilities, or unavailable — and can act on it or dismiss it.
**Must hold:** **Confidence is never fabricated.** A missing confidence value is shown as unavailable and is never defaulted to zero or to a plausible-looking number.
**Evidence:** Transcript with confidence value and confidence *source* label.

### EM-07 — Turn an AI signal into a real opportunity
**Trigger:** Keyword rules match a transcript.
**Actors:** System (proposes), Reviewer or Sales Manager (decides).
**Business flow:**
1. The system creates a **sales opportunity draft** referencing the transcript, the matched rules, and the visit.
2. A human reviews it and approves or rejects it with a decision note.
3. An approved draft becomes a CRM opportunity (CR-04) and may produce a quotation (SL-01) that carries the opportunity's summary forward.
4. A rejected draft is retained as evidence with its reason, so keyword rules can be tuned.

**Must hold:** **No AI output takes effect without an explicit, recorded human decision.** The draft's AI origin remains visible on everything it produces.

**Evidence:** Draft with matched rules and transcript reference; human decision with identity and note; the opportunity and quotation it became.

### EM-08 — Tune the keyword rules
**Trigger:** Drafts are too noisy or too sparse.
**Actors:** Sales Manager, System Admin.
**Business flow:** The operator maintains the keyword rules; a rule change affects only *future* detection; historical drafts keep the rules that produced them.
**Must hold:** Rule edits never retroactively re-explain a historical draft.
**Evidence:** Rule definitions with versions; drafts naming the rules that matched them.

### EM-09 — Score performance transparently
**Trigger:** A plan period ends.
**Actors:** Payroll Officer, System.
**Business flow:**
1. The system computes each factor's score from the facts it owns: task completion from task status, visit completion from executed visits, schedule adherence from task due dates versus completion, and work-time adherence from check-in and check-out durations against the required threshold.
2. The weighted total is computed and stored with a **full breakdown**: per factor numerator, denominator, ratio, weight, and contribution, plus the effective thresholds and any excluded-visit counts.
3. The Payroll Officer previews the score before anything is confirmed.

**Must hold:**
- Scoring is deterministic and reproducible; the breakdown snapshots its inputs, so a later plan or configuration change cannot silently alter a historical score.
- Display metrics, such as a raw task-completion ratio, are kept distinct from the weighted score that drives money.
- Only facts the module owns feed the score — no attendance or shift system is invented to fill a gap.

**Evidence:** Performance score with full calculation breakdown and snapshotted thresholds.

### EM-10 — Calculate and confirm salary
**Trigger:** Payroll for the period.
**Actors:** Payroll Officer, System Admin, System.
**Business flow:**
1. The system resolves the payable base — base salary or commission target, per the employee's compensation model — and **copies it onto the calculation**.
2. Final pay equals payable base multiplied by performance percent, plus approved bonuses.
3. The Payroll Officer previews, then confirms.
4. A correction is a **recalculation that supersedes** the prior row, never an edit and never a delete.

**Must hold:**
- The payable base is copied at calculation time, so a historical salary stays reproducible after the employee's profile changes.
- Only **approved** bonuses contribute.
- A confirmed calculation transitions only to superseded, and only through a fresh recalculation. Rows are never physically deleted.

**Evidence:** Salary calculation with payable base, performance percent, bonus, final amount, and confirmer identity; supersession chain.

### EM-11 — Approve a bonus
**Trigger:** Exceptional performance, or an AI-suggested bonus.
**Actors:** Employee Manager (suggests), System Admin (approves).
**Business flow:** A bonus suggestion is raised against the employee and plan, optionally referencing the opportunity draft that justifies it, with an amount and a reason; an authorised person approves or rejects it with a decision note; approved and rejected are terminal.
**Must hold:** Only approved suggestions affect pay, and an AI-suggested bonus is a proposal like any other AI output.
**Evidence:** Bonus suggestion with reason, decision identity, and note.

### EM-12 — Report on the field force
**Trigger:** Management review.
**Actors:** Employee Manager, Payroll Officer, Reviewer.
**Business flow:** Report plan completion by employee and month, overdue tasks, unexecuted planned visits, visit volume and duration, performance and salary summaries, voice-note and AI-draft throughput with approval rates, and the sales the field force actually produced.
**Must hold:** Every reported figure traces to the plan, task, visit, or score that produced it, and to the snapshotted inputs behind it.
**Evidence:** Employee performance, plan-completion, exception, and field-sales reports.

---

## 10. Support Scenarios (SU)

### SU-01 — Log a support request
**Trigger:** A customer reports a problem, by any channel.
**Actors:** Customer, Support Agent, System.
**Business flow:** The ticket is captured with customer, type — software, hardware, general, or maintenance request — title, description, attachments, and **priority**; the system assigns a ticket number; the ticket enters triage.
**Must hold:** A ticket logged by an agent on the customer's behalf and a ticket the customer raised themselves are the **same business record** — the channel is metadata, not a fork.
**Evidence:** Numbered ticket with type, priority, description, attachments, and origin channel.

### SU-02 — Gate work on a chargeable ticket
**Trigger:** The requested support is billable.
**Actors:** Support Manager, Customer, Payments Officer.
**Business flow:** The ticket is flagged chargeable and enters pending-payment with a payment link or a recorded settlement expectation; work does not start; on settlement the ticket goes live and the SLA clock starts.
**Must hold:** A chargeable ticket does not consume support capacity before settlement, and the settlement record is auditable regardless of channel.
**Evidence:** Chargeable flag; payment link or settlement record; the live transition and its timestamp.

> *Documentary note:* the current documentation deliberately builds this flow without a gateway and without any accounting posting, so ticket revenue is not yet recognised. Ideal behaviour recognises ticket revenue through the same invoice, collection, and tax path as any other sale.

### SU-03 — Run the SLA clock fairly
**Trigger:** A ticket goes live.
**Actors:** System, Support Manager.
**Business flow:**
1. Response and resolution targets are **snapshotted** from the SLA policy for that priority at clock start.
2. Response and resolution due times are computed from the live timestamp.
3. First response is stamped once, by the first customer-visible agent message.
4. Waiting on the customer **pauses** the clock, and accumulated wait time extends the resolution due time.
5. Breaches are flagged and, once flagged, stay flagged.

**Must hold:**
- Targets are snapshotted, never recomputed from a live policy join — editing the SLA policy must never rewrite an in-flight ticket's due times.
- The clock runs on continuous calendar time; only the customer-wait pause suspends it.
- Breach flags are sticky, so a late recovery does not erase the fact that the target was missed.

**Evidence:** Snapshotted targets; due times; first-response, pause, and breach timestamps.

### SU-04 — Assign and reassign ownership
**Trigger:** Triage, escalation, absence, or specialisation.
**Actors:** Support Manager, Agent.
**Business flow:** The ticket is assigned to an employee, with the assignment appended to an assignment history recording who assigned whom, when, and why; reassignment appends rather than overwrites.
**Must hold:** Ownership history is append-only, so accountability at any past moment is answerable.
**Evidence:** Assignment history.

### SU-05 — Hold the conversation in one place
**Trigger:** Any exchange about the ticket.
**Actors:** Customer, Agent.
**Business flow:** Internal notes and customer-visible messages live on one thread with attachments; visibility is explicit per message; the first customer-visible agent message sets first response.
**Must hold:** An internal note must never be exposed to the customer. Attachments are private and access-scoped to the ticket.
**Evidence:** Message thread with per-message visibility and attachments.

### SU-06 — Resolve and close
**Trigger:** The problem is fixed.
**Actors:** Agent, Customer, Support Manager.
**Business flow:** The agent records the resolution and moves the ticket to resolved, stamping the resolution time; the customer confirms or reopens; closure follows confirmation or a defined quiet period.
**Must hold:** Reopening clears the resolution stamp but **not** the sticky breach flags, and the reopen is visible in history. Resolution requires a recorded resolution, not just a status change.
**Evidence:** Resolution text and timestamp; confirmation or reopen; closure.

### SU-07 — Continue a ticket without losing the thread
**Trigger:** A resolved issue recurs, or a ticket splits into distinct problems.
**Actors:** Support Manager.
**Business flow:** A new ticket is created that explicitly references the prior ticket it continues or supersedes, so history reads as one chain rather than unrelated incidents.
**Must hold:** Continuation is an explicit link; a recurring problem must be visible as recurring.
**Evidence:** Continuation reference between tickets; recurrence reporting.

### SU-08 — Escalate a ticket into field or maintenance work
**Trigger:** The issue needs hands on equipment.
**Actors:** Support Manager, Technician.
**Business flow:** The ticket raises a maintenance request (MT-01) carrying the customer, the product, the serial, and the problem, or generates a plan task or visit for a field employee; the ticket stays the customer-facing thread while the work is tracked in its own document.
**Must hold:** The customer sees one conversation; internally the work has its own document, cost, and parts. The two stay linked in both directions.
**Evidence:** Ticket-to-maintenance link; ticket-to-task or visit link.

---

## 11. Maintenance Scenarios (MT)

### MT-01 — Raise a maintenance request against real equipment
**Trigger:** A customer reports equipment trouble, or preventive maintenance is due.
**Actors:** Customer, Support Manager, Technician, System.
**Business flow:**
1. The request is raised from a ticket or standalone, naming the customer, the product variant, the serial number, and the problem.
2. Where the serial matches a **serialised inventory unit** the company sold, the request links to that unit, inheriting its full history (PM-10).
3. **Warranty status** is recorded explicitly — covered with an expiry date, expired, or unknown — and drives whether the work is chargeable.
4. The request enters open and is scheduled.

**Must hold:**
- Warranty status is explicit and never guessed; "unknown" is a legitimate, visible state that prompts investigation.
- A covered warranty requires an expiry date, so coverage is verifiable.
- Linking to a serialised unit happens only on a real identifier match — never on a fuzzy guess.

**Evidence:** Maintenance record with equipment identity, serialised-unit link, warranty status and expiry, and source ticket.

### MT-02 — Schedule and execute service work
**Trigger:** A maintenance request needs doing.
**Actors:** Support Manager, Technician.
**Business flow:** One or more **service records** are created under the request, each with a technician, a due date, and a description; the technician executes and records findings and time; the service record advances to completed or is cancelled with a reason.
**Must hold:** A maintenance request is closed only when its service records are resolved, and work assignment and work history stay distinct from the customer-facing ticket.
**Evidence:** Service records with technician, due date, findings, and status history.

### MT-03 — Consume spare parts traceably
**Trigger:** The technician fits a part.
**Actors:** Technician, System.
**Business flow:**
1. The technician records the part: variant, warehouse including a van warehouse, quantity, and — for tracked variants — the lot and serialised unit.
2. Stock decreases **through the canonical inventory posting path**, and the consumption record is paired with the movement it produced.
3. The part's cost attaches to the service record, building the job's cost.
4. Where the part is a serialised unit, its custody transfers to the customer's equipment and appears in that equipment's history.

**Must hold:**
- Consumption **never** writes a stock balance directly; the variant-and-warehouse pair remains the sole stock truth and every consumption produces a movement.
- Tracked stock cannot be consumed without allocations.
- The consumption record is immutable once written, except for its reversal fields, set at most once.

**Evidence:** Parts consumption paired with its movement; cost on the service record; updated device history.

### MT-04 — Reverse a wrongly recorded consumption
**Trigger:** The wrong part, the wrong quantity, or the wrong warehouse was recorded.
**Actors:** Technician, Support Manager.
**Business flow:** The consumption is reversed **in full**, producing a compensating movement that returns the stock; a correction to a smaller amount is a full reversal followed by a new, smaller consumption.
**Must hold:** **Full reversal only** — there is no partial-reversal path and no edit path for quantity. The reversal names its actor and its compensating movement.
**Evidence:** Reversal timestamp and actor; compensating movement; the replacement consumption, if any.

### MT-05 — Cost a maintenance job
**Trigger:** Work completes, or management reviews service profitability.
**Actors:** Support Manager, Accountant.
**Business flow:** The job's cost accumulates parts cost, labour time at a rate, and any third-party cost; the job's revenue is either nil for warranty-covered work, the chargeable ticket settlement, or an invoice raised for the work; margin per job, per equipment, and per customer becomes reportable.
**Must hold:** Warranty-covered work has a real cost and zero revenue, and that must be *visible* — free-of-charge service is a cost centre, not a blank record.
**Evidence:** Job cost breakdown; linked revenue document or explicit warranty-covered marker; service margin report.

### MT-06 — Bill chargeable maintenance
**Trigger:** The work is not warranty-covered.
**Actors:** Accountant, Customer.
**Business flow:** Completed service work with its parts and labour produces a quotation or a direct invoice; from there it follows the standard invoice → collection → proportional tax recognition path (SL-06, SL-08).
**Must hold:** Service revenue uses the **same** invoicing, collection, and tax-recognition machinery as goods revenue. A second revenue path would be a second tax policy.
**Evidence:** Invoice linked to the maintenance record; standard postings; standard tax recognition.

### MT-07 — Run a preventive maintenance programme
**Trigger:** A serviceable unit reaches a time or usage interval.
**Actors:** Support Manager, System, Customer.
**Business flow:** Serviceable equipment carries a maintenance schedule; the system raises due requests in advance; scheduling groups them by customer or geography; completion resets the interval and appends to the equipment's service history.
**Must hold:** A missed preventive service is visible as missed, and the equipment's history is complete enough to answer a warranty claim.
**Evidence:** Schedule; raised requests; completion history per unit.

### MT-08 — Answer a warranty claim from evidence
**Trigger:** A customer claims warranty coverage.
**Actors:** Support Manager, Reviewer.
**Business flow:** From the serial, the system shows the sale — delivery and invoice — the warranty terms and expiry, every prior service record, every part fitted, and every condition change, so the claim is decided on evidence and the decision is recorded with a reason.
**Must hold:** The equipment's whole life is answerable from one place, and the claim decision and its reason are retained.
**Evidence:** Device history joining sale, warranty, service, parts, and conditions; claim decision record.

---

## 12. Cross-Cutting Scenarios (XC)

### XC-01 — Number every business document predictably
**Trigger:** Any document is created.
**Actors:** System.
**Business flow:** Each document family has its own sequence with a readable format; numbers are unique, gapless within a series, and never reused — including across soft-deleted records.
**Must hold:** A number, once issued, identifies that document forever. Gaps are explainable, and cancelled documents keep their numbers.
**Evidence:** Unique document numbers per family, including over soft-deleted rows.

### XC-02 — Audit every sensitive action
**Trigger:** Any financial, inventory, pricing, payroll, or access-affecting change.
**Actors:** System.
**Business flow:** The system records who did what, to which record, when, from which channel, with before and after values, and the business reason where one is required.
**Must hold:** Audit records are append-only and are never editable or deletable from any surface, including by a System Admin. An action the audit trail cannot explain is a defect.
**Evidence:** Audit log queryable by actor, record, action, date, and channel.

### XC-03 — Notify the right party at the right moment
**Trigger:** A business event with an interested party — invoice issued, payment received, quotation decided, task assigned, visit due, ticket updated, SLA at risk, stock low, lot expiring, approval pending.
**Actors:** System, Customer, Employee, System Admin.
**Business flow:** Notifications are produced from templates through the party's channel, delivery is attempted asynchronously, and every attempt and outcome is logged.
**Must hold:** A failed delivery is recorded and retried, never silently dropped. Notification volume is managed so alerts stay meaningful. A notification is never the source of truth — it references the document.
**Evidence:** Notification records; per-channel delivery logs with outcomes.

### XC-04 — Report across the whole business
**Trigger:** Operational or management need.
**Actors:** System Admin, Reviewer, Accountant.
**Business flow:** Reports cover sales, receivables, payables, payments, tax, inventory position and movement, purchasing commitments, employees, tickets and SLA, maintenance, and CRM — each filterable, exportable, and drill-through capable to the documents behind every figure.
**Must hold:** No report may compute a business figure by a rule that disagrees with the document that owns it. Long exports run asynchronously and are retained with their parameters so a figure can be reproduced.
**Evidence:** Report definitions; export records with parameters and requester.

### XC-05 — Enforce who may do what, everywhere
**Trigger:** Any action attempt.
**Actors:** System.
**Business flow:** Authorisation is checked at both page-open and action-execution time, on every surface, including bulk actions and direct service calls that bypass the interface.
**Must hold:** Hiding a control is **not** authorisation. Customers reach only their own records; employees reach only their own work and permitted sales operations; role boundaries hold identically across every path to the same action.
**Evidence:** Authorisation decisions consistent across interface, bulk, and service paths; refusal audit entries.

### XC-06 — Protect and serve sensitive files
**Trigger:** Any attachment: invoice PDF, payment proof, ticket attachment, visit photo, voice note, signature, expense receipt, disposal evidence.
**Actors:** System, authorised users.
**Business flow:** Files are stored privately and served only through authenticated, access-scoped, time-limited links, with mime type, size, and extension validated on upload.
**Must hold:** No sensitive file is ever reachable by a public path, and access is scoped to the record's own access rules.
**Evidence:** Private storage; signed access; upload validation.

### XC-07 — Keep financial and inventory operations atomic
**Trigger:** Any multi-step financial or stock operation.
**Actors:** System.
**Business flow:** Quotation conversion, delivery confirmation, invoice issuance, payment posting, tax recognition, stock transfer, adjustment confirmation, credit-note confirmation, refund posting, and parts consumption each complete entirely or not at all, with affected rows locked in a deterministic order.
**Must hold:** No operation can leave a balance changed without its movement, an invoice issued without its posting, or a payment recorded without its tax recognition. External callbacks are idempotent.
**Evidence:** Reconciliation invariants holding under concurrency; idempotent callback handling.

### XC-08 — Serve Arabic and English content correctly
**Trigger:** Users and customers work in both languages.
**Actors:** All.
**Business flow:** Product and customer-facing content carries both languages; documents and notifications render in the recipient's language; search matches either script; errors are understandable in the user's language.
**Must hold:** Arabic is a first-class content language, not a translation afterthought, and search must match it as reliably as English.
**Evidence:** Bilingual content fields; language-aware documents, notifications, and search.

---

## 13. Scenario Index

| Domain | IDs | Count |
|---|---|---|
| Master Data | MD-01 … MD-12 | 12 |
| Product Management | PM-01 … PM-10 | 10 |
| Inventory | IN-01 … IN-18 | 18 |
| CRM | CR-01 … CR-08 | 8 |
| Sales | SL-01 … SL-16 | 16 |
| Purchasing | PU-01 … PU-12 | 12 |
| Accounting | AC-01 … AC-16 | 16 |
| Employees | EM-01 … EM-12 | 12 |
| Support | SU-01 … SU-08 | 8 |
| Maintenance | MT-01 … MT-08 | 8 |
| Cross-cutting | XC-01 … XC-08 | 8 |
| **Total** | | **128** |

---

## 14. Ideal behaviours the documentation currently defers

These are named here so the gap between *ideal ERP behaviour* and *documented scope* is explicit and reviewable. This is a reading of the documentation's own scope statements, not an analysis of code.

| # | Ideal behaviour | Scenarios affected | Documented position |
|---|---|---|---|
| 1 | Inventory valuation and cost-of-goods-sold posting | AC-14, MT-05, SL-15 | Explicitly out of scope; a delivery posts nothing to the ledger, so margin is not derivable |
| 2 | Multi-currency with conversion and revaluation | AC-15, PU-02 | Out of scope; purchase orders hold a currency but refuse conversion, routing cross-currency approvals to a human |
| 3 | Ticket-payment revenue, tax, and accounting | SU-02, MT-06 | Settlement is recorded but creates no journal entry, no tax recognition, and no revenue |
| 4 | Customer-facing and employee-facing APIs and apps | SL-13, SL-14, EM-03, EM-05, SU-01 | Deferred; customer and employee actions are recorded on their behalf in the dashboard |
| 5 | Online payment gateway | SL-08, SU-02 | Deferred to a later channel; the same posting and recognition service must serve it when added |
| 6 | Supplier returns and debit notes | IN-15 | Out of scope in Purchasing; targeted by the warehouse returns and condition phase |
| 7 | Canonical customer return document with inspection and disposition | IN-13, IN-14 | Targeted but not yet authoritative; the interim returns view is explicitly superseded |
| 8 | Landed cost, moving-average or FIFO cost recalculation, supplier scoring, automatic reorder purchasing | AC-14, PU-01, IN-11 | Out of scope |
| 9 | Bank accounts and bank reconciliation | AC-09, AC-10 | Out of scope |
| 10 | Year-end close rolling income and expense into retained earnings | AC-10 | Out of scope |
| 11 | Budgets, forecasting, recurring journals, multi-step journal approval | AC-01, AC-09 | Out of scope |
| 12 | Sales commission calculation | EM-09, EM-10 | Out of scope; performance-based salary is the built compensation mechanism |
| 13 | Customer credit limits | MD-01, SL-03 | Explicitly not required; exposure is managed by ageing and payment terms |
| 14 | Bin or location as part of the stock balance key | MD-05, IN-01 | Deliberately excluded from the balance key until approved |
| 15 | Purchase requisitions and RFQs | PU-02 | Out of scope |
| 16 | Attendance, shift, and working-hours tracking | EM-09 | Explicitly not added; schedule and work-time factors derive only from task due dates and visit timestamps |

---

*End of document.*
