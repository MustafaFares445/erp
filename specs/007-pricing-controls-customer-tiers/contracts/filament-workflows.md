# UI Contract: Pricing Administration Workflows

## Variant modal

- Catalog fields persist normally under catalog-management permission.
- Cost, markup, and minimum price are visible only to pricing viewers and editable only by pricing managers.
- Base price is visible as a disabled derived value and is never submitted.
- Create and edit actions pass pricing values to `ProductPricingService`.

## Customer tier assignments

- Dedicated read/list screen shows customer, general tier, status, and dates.
- Assignment action lists Customer accounts and active general tiers only.
- Submitting an assignment leaves one active general assignment for that customer.
- No bulk deletion or force-deletion action is exposed.

## Pricing tiers

- General and customer-specific tiers share the existing tier screen.
- Customer selection lists Customer accounts only.
- Create/edit actions call `ProductPricingService`.

## Histories and overrides

- Price history and floor override screens support list and detail views only.
- No create, edit, delete, bulk delete, restore, or force-delete actions are exposed.
- Both screens require pricing-view permission.

## Floor override action

- Requires variant, attempted price, reason, and optional Customer account.
- Available only to pricing managers.
- Displays domain validation errors without persisting partial data.
