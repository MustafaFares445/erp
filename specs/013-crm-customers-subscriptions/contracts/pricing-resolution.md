# Contract: Pricing Resolution

This is an internal domain contract. It adds no HTTP endpoint.

## Input

- Existing ProductVariant
- Optional existing customer User
- Current application business date

The existing PriceResolver resolve input signature remains unchanged. Callers do not nominate a tier; the resolver selects the persisted eligible source.

## Resolution Order

1. Read variant base/minimum price.
2. If no customer is supplied, return base.
3. If the customer has no active, non-deleted customer profile, return base.
4. Resolve the active customer-specific tier and stop when found.
5. Load active, current product-scoped tiers assigned to the customer and linked to the active product.
6. Calculate each product-scoped candidate from base price, excluding invalid non-positive results.
7. Sort product-scoped candidates by final amount ascending, then tier ID ascending; use the first when present.
8. Resolve the customer's active assigned general tier when no earlier source wins.
9. Otherwise return base.
10. Expose whether the winner is below the existing minimum-price floor.

## Source Contract

| Source | source value | pricing tier |
|---|---|---|
| Customer-specific tier | customer_specific_tier | Winning tier |
| Product-scoped tier | product_scoped_tier | Winning tier |
| General tier | general_tier | Winning tier |
| Base | base | null |

No subscription source or subscription property remains.

## Calculation Contract

- Percentage: base minus the rounded percentage discount.
- Fixed: base minus the fixed amount.
- Product-scoped fixed candidates at or below zero are invalid.
- One source is calculated from base; discounted values are never compounded.

## Floor Contract

- The result reports whether explicit approval is required.
- A mutable commercial operation cannot use a below-floor result without an authorized approval.
- Approval requires a non-empty reason and records the pricing tier when a tier produced the amount.
- Preview remains read-only and creates no approval.

## Document-Immutability Contract

Confirmed document lines read their stored unit prices. Later tier, assignment, product-link, validity, or base-price changes do not rewrite confirmed documents.

## Compatibility Contract

- Existing callers keep the same resolver input signature.
- Existing amount and pricing-tier result properties remain available.
- Existing general/customer-specific/base outcomes remain unchanged when no product-scoped tier is eligible.
- Tier configuration changes create audit history, not product price-history rows.

## Worked Cases

| Base | Specific | Eligible product-scoped | General | Winner |
|---:|---|---|---|---|
| 120 | none | 10% | 5% | Product-scoped tier, 108 |
| 120 | 8% | fixed 20 | 5% | Customer-specific tier, 110.40 |
| 120 | none | fixed 15 and 10% | 5% | Product-scoped 10%, 108 |
| 120 | none | fixed 12 and 10% | 5% | Equal 108; earliest tier ID |
| 120 | none | expired 20% | 5% | General tier, 114 |
| 120 | none | none | none | Base, 120 |
