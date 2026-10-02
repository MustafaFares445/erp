# IERP Glossary

---
status: canonical
owner: product
last_verified: 2026-10-02
verified_against: canonical domain documentation and current model/service naming
---

**Base UOM** - Canonical unit used to compare/store normalized physical quantities.

**Transaction UOM** - Unit selected on a commercial/physical document; its conversion is snapshotted for historical meaning.

**On Hand** - Quantity physically in warehouse custody.

**Reserved** - On-hand quantity committed to outbound demand but not yet removed from custody.

**Available** - On-hand minus reserved and damaged quantities.

**Inventory Operation** - Canonical physical Receipt, Delivery or Internal Transfer workflow.

**Purchase Inbound** - Purchasing-side aggregate/provenance for accepted incoming PO quantities and warehouse allocation.

**Sales Procurement Requirement** - Sales-owned shortage fact linking released customer demand to Purchasing sourcing.

**Customer Deposit** - Posted customer money that is not yet allocated to an issued Invoice.

**Payment Allocation** - Evidence that part of a Payment settles an issued Invoice.

**Deferred Sales Tax** - In the current sales accounting model, invoiced sales tax that has not yet been recognized as payable through collection.

**Tax Recognition** - Transfer of the appropriate tax amount from deferred tax to tax payable as customer collection is recognized.

**Credit Note** - Sales financial correction/credit document, separate from physical stock return.

**Inventory Return** - Physical customer/supplier goods-return workflow, separate from Credit Note/Refund.

**Refund** - Accounting-controlled payout of available customer credit/deposit.

**Maintenance Record** - Support-owned service/repair work record that can be warranty-covered, quoted, invoiced or settled through ticket payment according to its billing path.

**Warranty Entitlement** - Support-owned record of warranty coverage for eligible equipment/serial.

**Sales Plan** - Employee planning period containing field-sales work/task expectations.

**Customer Visit** - Employee field visit with timing/GPS/outcome/voice/AI-related evidence.

**Canonical Documentation** - Documentation verified against current implementation and allowed to describe current behavior.

**Active Plan** - Future-state implementation document; it must not be described as already implemented.
