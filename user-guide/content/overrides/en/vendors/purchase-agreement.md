# Purchase Agreements

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Operator steps and checks” instructions below.
- Follow the documented “Screen references” instructions below.
- Follow the documented “List” instructions below.

> Ownership: Purchasing records commercial supplier commitments; Inventory posts physical quantities; Accounting and Payments manage AP and settlement.

## Operator steps and checks

Use Purchase Agreements for supplier-specific prices and terms that may guide later sourcing. Create a **Draft** for an active supplier with active currency, start/end dates and at least one line. A line requires an **active Supplier Product Reference** and an active purchase UOM for the selected variant. Price cannot be negative; optional MOQ must be positive and lead time nonnegative; duplicate variant+unit combinations are rejected.

**Activate** only a valid, non-expired Draft. The service blocks overlapping active agreements covering the same supplier, product, unit and currency. Review status and dates before cancellation or expiry; changing an agreement does not post inventory or an AP bill. Open the related Purchase Order separately to order goods.

## Screen references

### List

<IerpScreenshot id="vendors-purchase-agreement-index" title="Purchase Agreements — List" description="Purchasing operator screen; check record state and permissions before acting." path="Purchasing → Purchase Agreements" />

### Create

<IerpScreenshot id="vendors-purchase-agreement-create" title="Purchase Agreements — Create" description="Purchasing operator screen; check record state and permissions before acting." path="Purchasing → Purchase Agreements" />

### Record details

<IerpScreenshot id="vendors-purchase-agreement-view" title="Purchase Agreements — Record details" description="Purchasing operator screen; check record state and permissions before acting." path="Purchasing → Purchase Agreements" />

See also: [Procure to Pay](/workflows/procure-to-pay).
