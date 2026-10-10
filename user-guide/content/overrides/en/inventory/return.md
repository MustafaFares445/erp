# Inventory Returns

<IerpPageSummary purpose="Handle customer and supplier stock returns while keeping refunds and credit notes separate." useWhen="Inspecting return quantities, disposition and final stock effects." owner="Inventory" />

## When to use this page

Inspecting return quantities, disposition and final stock effects.

## Typical tasks

- Follow the documented “Two distinct flows” instructions below.
- Follow the documented “Procedure” instructions below.
- Follow the documented “Financial work is separate” instructions below.

## Two distinct flows

A **Customer Return** receives goods back after an outbound delivery and requires evidence of the original delivery, actual received quantity and inspection/disposition. A **Supplier Return** sends inventory back to the vendor and requires valid source provenance and available quantities. Do not treat both flows as an identical stock increase.

## Procedure

1. Open **Inventory → Operations → Returns** and review the return type, origin and current status.
2. Start a new return from an eligible source document where offered; identify the warehouse, party and source operation.
3. For a Customer Return, capture quantities physically received and disposition: Saleable, Quarantine, Damaged or Supplier Return. Inspection may precede posting. A Supplier Return disposition initially keeps returned goods in quarantine until the separate supplier-return outbound process.
4. For a Supplier Return, verify the quantity being sent to the vendor against the eligible stock condition and warehouse.
5. Review Ready/Posted transitions and inspect stock movement references after posting. Block excess quantities, mismatched source and invalid status transitions.

## Financial work is separate

Posting a customer return does **not** automatically issue a Credit Note or Refund. Posting a Supplier Return does **not** automatically create an accounts-payable adjustment or supplier bill credit. Coordinate financial documents with Sales, Payments, Purchasing and Accounting only after reconciling the physical return.

Related: [Inventory Operations](/screens/inventory/inventory-operation), [Stock Movements](/screens/inventory/stock-movement).

## Reviewed dashboard screens

<IerpScreenshot id="inventory-return-index" title="Customer and supplier returns queue" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" imageSrc="/images/en/inventory/inventory-return-index.png" />

<IerpScreenshot id="inventory-return-view" title="Return source, status and financial follow-up" description="Reviewed dashboard screen in the isolated demo environment." path="Inventory → Operations" imageSrc="/images/en/inventory/inventory-return-view.png" />

## View: return posting and financial separation

The View panel explains the current lifecycle of customer/supplier returns (Draft, Ready, Posted, Cancelled). Review the source Delivery or Receipt, warehouse, lines, condition, reason and financial consequence. A posted return changes inventory according to return type and condition; credit notes or refunds are separate financial workflows.

