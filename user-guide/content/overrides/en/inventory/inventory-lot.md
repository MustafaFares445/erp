# Inventory lots and expiry tracking

<IerpPageSummary purpose="Inspect lot-controlled stock, expiry risk, physical balances, reservations and traceability without directly editing historic movements." useWhen="An operator needs to verify the exact batch, expiry window or source of stock availability." owner="Inventory" />

## When to use this page

An operator needs to verify the exact batch, expiry window or source of stock availability.

## Typical tasks

- Follow the documented “Find a lot” instructions below.
- Follow the documented “Review lot details” instructions below.
- Follow the documented “Expiration rules” instructions below.

## Find a lot

Open **Inventory → Stock → Inventory Lots** and search by lot number or product SKU. Filter by warehouse, product or expiry window when investigating a shortage, expiration alert or customer complaint.

The lot list shows physical, saleable, quarantined, damaged, reserved and available quantities. The same lot may have balances in several warehouses. Do not assume all physical stock is available to reserve.

## Review lot details

1. Open a specific lot and confirm the original identifier, normalized lot number, product SKU and expiry date.
2. Compare **physical on-hand**, **saleable**, **quarantined**, **damaged**, **reserved** and **available** quantities, using the selected warehouse where relevant.
3. Read **Lot Traceability** for the dated movement, warehouse, transaction unit, base quantity change, condition before/after, source document and counterparty.
4. Follow the source document to verify how stock entered or left custody. Do not edit a posted movement from the lot detail page.

## Expiration rules

Expired stock is not freely selectable for outbound work. An expiring lot may still be physically on hand, but usable quantity depends on expiry policy, stock condition and reservation allocation. Normal picking should follow earliest-expiring-first (FEFO), with any permitted exception explicitly authorized and audited.

The **expiry window** labels indicate when a lot approaches its configured alert thresholds. They do not by themselves post a condition change or remove stock. Use documented condition-change/correction paths for actual stock effects.

## Troubleshooting

If a lot is missing, check normalized identifier, warehouse filter and any retained movement history. A blank timeline may mean the batch has not received a relevant posted movement. A lingering loading indicator is not evidence of completed rendering; wait for final data before approving documentation images.

## Screens

<IerpScreenshot id="inventory-inventory-lot-index" title="Inventory lots and expiry filters" description="Find lots by identity and stock/expiry state." path="Inventory → Stock → Inventory Lots" />

<IerpScreenshot id="inventory-inventory-lot-view" title="Lot identity, balances and movement traceability" description="Confirm lot custody and source history before choosing an action." path="Inventory → Stock → Inventory Lots → View" />
