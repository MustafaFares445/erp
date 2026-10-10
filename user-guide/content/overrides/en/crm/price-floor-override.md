# Price Floor Overrides

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Audit a price exception” instructions below.
- Follow the documented “Override register” instructions below.
- Follow the documented “View an approval” instructions below.

<IerpNavigationPath :items='["CRM","Pricing","Price Floor Overrides"]' />

A price-floor override records an explicit approval for a specific exception below the configured minimum price. It retains customer context, attempted price, captured floor, reason, approver and time.

## Audit a price exception

1. Open the override register, then inspect variant SKU, pricing tier, customer, attempted price and captured minimum.
2. Open **View** and verify the approved context and who granted approval, when and why.
3. Match the exception to the exact product/customer situation. It does not automatically authorize unrelated discounts or override other Sales constraints.
4. If a price or approval is wrong, use the owning pricing service and its correction process, never rewrite historical evidence.

## Override register

<IerpScreenshot id="crm-price-floor-override-index" title="Minimum-price exceptions" description="Review variant, customer, tier and price-floor comparison." path="CRM → Pricing → Price Floor Overrides" />

## View an approval

<IerpScreenshot id="crm-price-floor-override-view" title="Price floor approval evidence" description="Inspect attempted price, recorded floor, approver, time and justification." path="CRM → Pricing → Price Floor Overrides → View" />

These pages are an audit surface. Viewing a decision changes neither a Sales quotation nor an Accounting balance.

Related: [Pricing Tiers](/screens/crm/pricing-tier), [Price Change Requests](/screens/crm/price-history).
