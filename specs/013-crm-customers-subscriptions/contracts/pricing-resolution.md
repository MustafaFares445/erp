# Contract: Pricing Resolution

This is an internal domain contract. It does not add an HTTP endpoint.

## Input

- Existing `ProductVariant`
- Optional existing customer `User`
- Current business date from the application clock

Callers cannot nominate a tier or subscription. The resolver determines the
winner from persisted eligibility.

## Resolution Order

1. Load the variant base price and minimum price.
2. If no customer is supplied, return the base-price result.
3. If the user has no active, non-deleted customer profile, ignore all
   customer-derived sources and return base price.
4. Resolve the existing active customer-specific pricing tier. If present, use
   it and stop.
5. Load subscriptions that satisfy every eligibility rule:
   - active and not soft-deleted;
   - current date within the inclusive validity window;
   - assigned to the active customer profile;
   - linked to the variant's active product;
   - valid discount configuration.
6. Calculate each subscription candidate from the variant base price:
   - percentage: `base - round(base * percentage / 100, 2)`
   - fixed: `base - fixed amount`
7. Discard invalid zero or negative candidates.
8. Sort candidates by final amount ascending, then subscription ID ascending.
9. If a candidate exists, select the first and stop.
10. Resolve the existing active general customer tier assignment. If present,
    use it and stop.
11. Return base price.
12. Compare the winning amount to the existing variant minimum price and expose
    the floor result.

## Source Contract

| Source | `source` | `pricingTier` | `productSubscription` |
|---|---|---|---|
| Customer-specific tier | `customer_specific_tier` | Winning tier | null |
| Subscription | `subscription` | null | Winning subscription |
| General tier | `general_tier` | Winning tier | null |
| Base | `base` | null | null |

## No-Stacking Guarantee

The returned amount is calculated from one source and the base price. The
resolver never feeds one discounted amount into another discount.

## Floor Contract

- A result below `minimumPrice` is observable as `isBelowFloor = true`.
- A consumer must not use that amount in a mutable commercial operation without
  a matching explicit System Admin approval.
- The approval uses the existing floor-override service and table.
- If the source is a subscription, the approval records
  `product_subscription_id`.
- A non-empty reason is mandatory.

## Document-Immutability Contract

- The resolver calculates a new-document candidate only.
- The sales/accounting document flow persists the chosen unit price and source
  snapshot before confirmation.
- Confirmed documents read their stored values and do not call the resolver to
  rewrite prior lines.

## Compatibility Contract

- The existing resolver method remains available with the same inputs.
- Existing tier-only and base-only results keep their current `amount`.
- When no eligible subscription exists, all current pricing tests must continue
  to pass unchanged.
- Existing consumers of `ResolvedPrice::amount` and
  `ResolvedPrice::pricingTier` remain source-compatible.

## Worked Cases

| Base | Specific tier | Eligible subscriptions | General tier | Winner |
|---:|---|---|---|---|
| 120 | none | 10% | 5% | Subscription, 108 |
| 120 | 8% | fixed 20 | 5% | Specific tier, 110.40 |
| 120 | none | fixed 15 and 10% | 5% | 10% subscription, 108 |
| 120 | none | fixed 12 and 10% | 5% | Lower result wins; both 108, earliest subscription ID |
| 120 | none | expired 20% | 5% | General tier, 114 |
| 120 | none | none | none | Base, 120 |
