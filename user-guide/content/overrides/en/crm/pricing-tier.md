# Pricing Tiers

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Managing tiers” instructions below.
- Follow the documented “Pricing tier workspace” instructions below.

<IerpNavigationPath :items='["CRM","Pricing","Pricing Tiers"]' />

Use pricing tiers to manage customer discounts and product-specific pricing applicability without overriding minimum-price controls. The pricing services enforce discount ceilings, validity periods, eligibility and approvals.

## Managing tiers

1. Open **Pricing Tiers** to inspect type, discount, linked customer, active status, validity dates and product/customer assignment counts.
2. Select **Add pricing tier** and choose **General**, **Customer-specific** or **Product-scoped**. Different types expose different customer, product, visibility and date fields.
3. Review the configured maximum percentage discount. When a constraint requires approval, use the authorized reason/approval action; do not split a discount to bypass the ceiling.
4. Use **Assign general tier** for eligible customers, or **Manage products** / **Manage customers** for product-scoped tiers.
5. Use **Preview price** to inspect resolved base price, discount and floor. Preview is not permission to sell below minimum price.
6. Use **Activate**, **Deactivate**, **Edit** or **Restore** only with the appropriate role. Do not retroactively alter past Sales quotations.

## Pricing tier workspace

<IerpScreenshot id="crm-pricing-tier-index" title="Pricing tier management" description="Create tier discounts, control validity, assign products/customers and preview prices." path="CRM → Pricing → Pricing Tiers" />

The isolated demo may contain no configured tier records. An empty list is not an application error. Confirm tier activity, validity, eligibility, constraints and floor approvals when a resolved price differs from expectation.

Related: [Customers](/screens/crm/customer), [Price Change Requests](/screens/crm/price-history), [Price Floor Overrides](/screens/crm/price-floor-override).
