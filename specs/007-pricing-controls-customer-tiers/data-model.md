# Data Model: Pricing Controls and Customer Tiers

## ProductVariant

Existing pricing fields:

- `cost_price`: nullable non-negative decimal.
- `markup_percent`: nullable percentage from 0 through 100; the inventory default is used when omitted.
- `base_price`: derived decimal equal to cost multiplied by one plus the markup percentage.
- `min_price`: nullable non-negative selling floor.

Every effective pricing update locks the row, computes the full target snapshot, compares it with the current normalized snapshot, and writes only when the values differ.

## PriceHistory

Existing immutable-by-interface snapshot fields:

- Variant relationship.
- Cost, base, minimum, and effective markup.
- User who made the change.
- Creation time.

Exactly one row is created after each effective variant pricing change in the same transaction as the variant and audit update.

## PricingTier

Existing fields:

- Name.
- Discount percentage from 0 through 100.
- Optional customer relationship; null denotes a general tier.
- Active flag.
- Soft deletion and blameable fields.

When a customer-specific tier becomes active, all other active customer-specific tiers for that customer are deactivated in the same locked transaction.

## CustomerPricingTier

Existing fields:

- Customer relationship.
- General pricing-tier relationship.
- Active flag.
- Timestamps.

Assignment transition:

```text
No assignment or Active tier A
        |
        | assign tier B
        v
Tier A inactive + Tier B active
```

Reassigning a previously used tier reactivates its existing row rather than violating the existing customer/tier uniqueness constraint.

## PriceFloorOverride

Existing fields:

- Variant.
- Optional customer.
- Attempted price.
- Captured minimum price.
- Approver and approval time.
- Required reason.

The service permits creation only for a price below the current floor. Persisted records reject update, delete, force-delete, and restore operations.

## AuditLog

Existing polymorphic reference stores actions for effective pricing changes, tier changes, assignments, and overrides. Audit writes participate in their parent transaction.
