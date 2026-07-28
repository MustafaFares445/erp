# Internal Contract: ProductPricingService

The service is the only internal writer for pricing-domain state.

## Variant pricing

Input: variant, `VariantPricingData` containing nullable cost, markup, and minimum price, and actor.

Output: refreshed variant.

Guarantees:

- Locks the variant.
- Uses default markup when input is absent.
- Derives base price; callers cannot supply it.
- Writes history and audit records only for an effective change.
- Commits all three records together or rolls all three back.

## Tier persistence

Input: optional existing tier, `PricingTierData` containing name, discount, optional customer identifier, and active flag, and actor.

Output: persisted tier.

Guarantees:

- Customer-specific tiers accept Customer accounts only.
- Activating a specific tier deactivates other specific tiers for that customer.
- General tiers have no customer.
- Audit records effective changes.

## General assignment

Input: customer, active general tier, actor.

Output: active assignment.

Guarantees:

- Rejects non-customer accounts, inactive tiers, and customer-specific tiers.
- Deactivates every other active assignment for the customer.
- Reactivates an existing customer/tier row when applicable.
- Records the transition in audit history.

## Floor approval

Input: `PriceFloorOverrideData` containing variant identifier, attempted price, required reason, and optional customer identifier, and actor.

Output: immutable floor-override record.

Guarantees:

- Requires pricing-management authorization.
- Rejects prices at or above the floor.
- Rejects a non-customer optional customer.
- Captures the current floor and approval time.
- Creates the audit record atomically.
