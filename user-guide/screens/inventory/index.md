---
title: "Inventory"
module: inventory
lastVerified: 2026-10-07
---

# Inventory

Inventory owns physical stock truth: warehouses, quantities, tracked lots and serials, receipts, deliveries, transfers, counts, returns, reservations and replenishment.

## How to use this module

Start from the module dashboard to understand queues and exceptions, then open the screen that owns the record or action you need. The structure below follows the dashboard module switcher and sidebar.

## Dashboard screens

<div class="module-grid">
<a class="module-card" href="/guide/screens/inventory/dashboard"><strong>Inventory Dashboard</strong><span>Use the Inventory Dashboard to monitor stock value, warehouse movement, low/expiring stock, replenishment and operations awaiting action.</span></a>
<a class="module-card" href="/guide/screens/inventory/stock-level"><strong>Stock</strong><span>Review on-hand, reserved and available quantity by warehouse/product without directly editing stock balances.</span></a>
<a class="module-card" href="/guide/screens/inventory/product"><strong>Products</strong><span>Maintain catalog product identity, classification and shared attributes while keeping variant, supplier and physical-stock facts separate.</span></a>
<a class="module-card" href="/guide/screens/inventory/inventory-lot"><strong>Inventory Lots</strong><span>Track lot-controlled stock, expiry, condition and movement provenance for products that require batch identity.</span></a>
<a class="module-card" href="/guide/screens/inventory/serialized-inventory-unit"><strong>Serialized Devices</strong><span>Track each serialized unit as a unique physical identity through warehouse custody, delivery, customer ownership and service history.</span></a>
<a class="module-card" href="/guide/screens/inventory/stock-movement"><strong>Stock Movements</strong><span>Inspect the immutable inventory movement trail to understand why, where and from which source document stock changed.</span></a>
<a class="module-card" href="/guide/screens/inventory/inventory-import-run"><strong>Catalog Imports</strong><span>Review catalog/import runs, their processing result and errors before relying on imported product data.</span></a>
<a class="module-card" href="/guide/screens/inventory/inventory-report"><strong>Inventory Reports</strong><span>Analyze inventory facts and exceptions without changing stock; corrections remain controlled Inventory operations.</span></a>
<a class="module-card" href="/guide/screens/inventory/purchase-inbound"><strong>Inbound</strong><span>Track expected inbound purchasing work and warehouse allocations before physical receipt is posted by Inventory.</span></a>
<a class="module-card" href="/guide/screens/inventory/inventory-operation"><strong>Receipts</strong><span>Execute controlled receipts, deliveries and internal transfers while preserving source document, tracking and movement history.</span></a>
<a class="module-card" href="/guide/screens/inventory/barcode-workbench"><strong>Barcode Workbench</strong><span>Use barcode-driven lookup and operation assistance to identify products/serials quickly while still following the owning Inventory workflow.</span></a>
<a class="module-card" href="/guide/screens/inventory/outbound-fulfillment"><strong>Outbound</strong><span>Plan and execute outbound customer fulfillment against released demand before delivery stock posting and shipment progress.</span></a>
<a class="module-card" href="/guide/screens/inventory/shipment"><strong>Shipments</strong><span>Track planned shipments from warehouse dispatch through in-transit and arrival confirmation without becoming the stock ledger.</span></a>
<a class="module-card" href="/guide/screens/inventory/package"><strong>Packages</strong><span>Organize shipment contents into packages and preserve package-level logistics context for outbound work.</span></a>
<a class="module-card" href="/guide/screens/inventory/adjustment"><strong>Adjustments</strong><span>Post authorized stock adjustments for controlled variances instead of manually editing Stock Levels.</span></a>
<a class="module-card" href="/guide/screens/inventory/inventory-count"><strong>Inventory Counts / Cycle Counts</strong><span>Run physical/cycle counts, compare counted quantity with system snapshots and turn approved variance into auditable correction.</span></a>
<a class="module-card" href="/guide/screens/inventory/return"><strong>Returns</strong><span>Inspect and post physical customer returns, their tracking provenance and disposition before separate financial credit/refund work.</span></a>
<a class="module-card" href="/guide/screens/inventory/inventory-correction"><strong>Receipt Corrections</strong><span>Correct previously posted inventory history through an explicit compensating/correction flow instead of rewriting the original movement.</span></a>
<a class="module-card" href="/guide/screens/inventory/inventory-condition-change"><strong>Condition Changes</strong><span>Move stock between saleable, quarantine, damaged or other supported conditions with an auditable reason and tracking context.</span></a>
<a class="module-card" href="/guide/screens/inventory/inventory-reservation"><strong>Reservations</strong><span>Review stock reserved for downstream demand and understand which source record currently claims available quantity.</span></a>
<a class="module-card" href="/guide/screens/inventory/inventory-alert"><strong>Planning & Alerts</strong><span>Work inventory exception and planning alerts such as low stock or replenishment need, then open the owning action to resolve them.</span></a>
<a class="module-card" href="/guide/screens/inventory/warehouse-replenishment-policy"><strong>Replenishment Policies</strong><span>Define warehouse/product replenishment policy such as minimum/maximum targets and preferred supply behavior used to generate recommendations.</span></a>
<a class="module-card" href="/guide/screens/inventory/warehouse"><strong>Warehouses</strong><span>Maintain warehouse master locations and open their stock, movement and reservation context without editing quantities directly.</span></a>
<a class="module-card" href="/guide/screens/inventory/catalog-setup"><strong>Catalog Setup</strong><span>Configure catalog support data needed by products and variants before those records are used operationally.</span></a>
<a class="module-card" href="/guide/screens/inventory/package-type"><strong>Inventory Setup</strong><span>Maintain reusable package-type definitions used by logistics and Inventory packaging workflows.</span></a>
<a class="module-card" href="/guide/screens/inventory/inventory-setting"><strong>Inventory Settings</strong><span>Configure Inventory behavior that affects future operations, reservations, tracking and planning without rewriting posted movements.</span></a>
<a class="module-card" href="/guide/screens/inventory/product-variant"><strong>Product Variant</strong><span>Maintain the concrete SKU-level identity, identifiers and operational attributes beneath a product.</span></a>
</div>

## Related workflows

- [Tracked Inventory Lifecycle](/workflows/tracked-inventory)
- [Warehouse Replenishment](/workflows/warehouse-replenishment)
- [Inventory Transfer](/workflows/inventory-transfer)
- [Physical Count](/workflows/physical-count)
- [Inventory Correction](/workflows/inventory-correction)
- [Customer Return to Refund](/workflows/customer-return-to-refund)

## Ownership rule

Confirmed inventory actions change physical stock truth and keep warehouse, lot, serial and source-document provenance.
