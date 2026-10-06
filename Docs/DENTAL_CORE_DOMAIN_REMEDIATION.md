# Dental ERP Core Domain Remediation

Status: implemented in `feat/dental-core-domain-remediation`.

This document records the implementation that now exists in code. It is intentionally scoped to the core dental ERP domains and does not redefine the Employee module.

## Product Foundation

- Products support Manufacturer and Brand independently, with optional manufacturer/brand relationships.
- Dental product operational profiles are explicit and separate from legacy product type labels.
- Variant tracking is explicit through tracking mode while remaining backward compatible with legacy lot/serial flags.
- Units of measure include optional stable codes, families, and precision.
- Packaging metadata lives on variant-UOM records and can carry packaging names and barcodes.
- Catalog administration includes manufacturer management.
- Product search and resource views expose manufacturer/brand context.
- Tracking, expiry, warranty/service, and UDI-style capabilities remain independent.

## Inventory Traceability

- Inventory lots support expiry buckets: Expired, 0–30, 31–60, 61–90, and healthy.
- FEFO selection is enforced for expiry-controlled stock, with explicit override handling where allowed.
- Inventory posting, adjustments, counts, damage, condition changes, returns, service consumption, and reservations preserve lot/serial requirements.
- Serialized inventory remains a first-class custody record.
- Reservations can retain Sales Order provenance and assigned lot/expiry information.
- Inventory views distinguish on-hand/available/reserved and tracked identity where applicable.
- The Inventory dashboard contains an actionable Expiring Lots queue showing product, lot, warehouse, quantity, expiry date, and days remaining.

## Purchasing

- Suppliers carry default commercial terms including currency, payment terms, and lead-time behavior.
- Supplier Product References support supplier item identity, purchase UOM, MOQ, lead time, preference, validity, availability, and currency/cost.
- Purchase Orders own commercial purchasing data and enforce supplier/currency consistency after lines exist.
- Supplier Confirmations capture line-level supplier commitments and promised dates.
- Rejected/partial commitments can drive procurement follow-up instead of silently completing demand.
- Goods receipts preserve lot/serial/expiry evidence and purchase-UOM conversion.
- Receiving remains an Inventory operation initiated from Purchasing documents.
- Supplier Confirmations remain routable but are removed from primary navigation; they are reached from Purchase Orders and purchasing work queues.

## Replenishment

- Warehouse replenishment policies support minimum stock, maximum stock, and a preferred supplier validated against active/current supplier-product references.
- Recommendation projections expose Available, Reserved, Incoming, Min, Max, Suggested Quantity, Supplier, and Lead Time.
- Available quantity follows the canonical inventory definition and already reflects reservations; incoming is added without double-subtracting reserved stock.
- Purchase Needs provides actionable replenishment recommendations.
- Selected replenishment requirements can create grouped Purchase Order drafts by supplier/currency.
- Purchase draft generation applies purchase-UOM conversion, supplier MOQ, lead time, and internal-transfer coverage before buying.

## Sales Fulfillment

- Customer commitment remains represented by the existing Sales Order/Order model rather than moving inventory on confirmation.
- Order availability is projected into explicit business states, including insufficient stock, awaiting purchase, awaiting supplier confirmation, partially available, and ready for delivery.
- Reservation records retain Sales Order provenance.
- Fulfillment allocates warehouse stock and tracked identities at the fulfillment stage.
- Lot/serial tracking works with modern tracking mode and legacy tracking projections.
- Existing legacy writers that explicitly set both tracking flags false remain compatible.

## Pricing

- Lightweight B2B Price Lists support:
  - direct customer assignment,
  - customer-group assignment,
  - customer default price list,
  - product-level prices,
  - variant-specific prices,
  - quantity breaks,
  - validity windows,
  - currency.
- Price resolution has deterministic priority and preserves price-list provenance downstream.
- Quantity breaks resolve on base quantity so pieces/boxes/other sales UOMs behave consistently.
- Customer commercial profiles include:
  - Customer Type,
  - Customer Group,
  - Default Currency,
  - Default Price List,
  - Default Payment Terms,
  - Assigned Sales Employee,
  - Billing Address,
  - Tax Registration Number.
- A dedicated Price Lists management resource provides assignments and quantity-pricing administration.

## Returns and After-Sales

- Customer returns preserve original delivery, lot, serial, and movement provenance.
- Customer inspection dispositions include:
  - Saleable,
  - Quarantine,
  - Damaged,
  - Supplier Return.
- Supplier Return disposition receives customer-returned stock into quarantine so the existing Supplier Return document can perform the outbound vendor return without a second inventory path.
- Supplier Returns consume saleable/quarantine/damaged stock using the canonical return posting service.
- Credit Notes can link to posted customer returns and exact returned quantities.
- Refunds link to confirmed credits/payment workflows and remain reachable from accounting documents while no longer appearing as a primary navigation item.
- Serialized equipment remains linked to customer custody, warranty entitlements, maintenance requests, movements, and delivery/return provenance.
- Warranty date correction is permissioned, requires a reason, synchronizes the serialized-unit snapshot, and writes activity history.
- Maintenance prioritizes known customer serialized equipment while still supporting external/unlinked serials.

## UX and Reporting

Primary domain navigation remains organized by the existing module dashboards. Substates do not need independent primary menu items.

- Supplier Confirmations: hidden from primary navigation; reached through Purchasing/Purchase Orders.
- Refunds: hidden from primary navigation; reached through Credit Notes/Payments/accounting workflows.
- Inventory expiry:
  - Inventory Lots provides Expired / 0–30 / 31–60 / 61–90 tabs.
  - Inventory Dashboard provides the warehouse-specific Expiring Lots work queue.
- Existing Reports infrastructure is reused rather than duplicated:
  - Inventory Stock Levels covers stock by warehouse and low-stock filtering.
  - Inventory Movements includes lot/serial provenance.
  - Expiry Lots covers expiring stock.
  - Devices covers serialized equipment.
  - Sales Customer Revenue covers sales by customer.
  - Purchasing Open Commitments / Receiving Performance / Cost Variance cover pending receipts and supplier purchasing execution.
  - Sales Returns Without Credit plus canonical Returns/Credit Note workflows cover financial return exceptions.

## Accounting Boundary

The remediation does not introduce a second accounting path.

- Inventory return posting does not fabricate financial documents.
- Customer return financial consequences are handled by Credit Notes/Refunds.
- Purchasing financial consequences continue through Supplier Bills/AP.
- Existing journal posting, payments, tax, credit-note, and refund services remain canonical.

## Compatibility and Boundaries

- Employee module behavior, visits, monthly plans, GPS, performance, salary, and employee-task lifecycle were not redesigned.
- Shared customer/employee references were changed only where required by owned core-domain functionality.
- Legacy product tracking flags remain supported during migration to explicit tracking modes.
- Existing mature Inventory, Purchasing, Sales, Accounting, Support, and Reporting implementations were extended rather than replaced.

## Verification

Focused regression coverage exercises:

- catalog/manufacturer/UOM/tracking,
- dental lot/serial/expiry traceability,
- Purchase Order and Supplier Confirmation flows,
- replenishment recommendation and PO draft generation,
- Sales Order availability/reservation/fulfillment,
- customer price lists and quantity pricing,
- customer/supplier returns,
- Credit Note linkage,
- warranty activation/correction,
- maintenance equipment linkage,
- Filament resource behavior.

Temporary `.chat-*` logs and diagnostic tests are development-only and must not be committed.
