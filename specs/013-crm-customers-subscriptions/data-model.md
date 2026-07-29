# Data Model: CRM Customers and Product Subscriptions

**Date**: 2026-07-29

## Reuse and Change Classification

| Entity or table | Classification | Planned treatment |
|---|---|---|
| `users` | Keep | Reuse customer and dashboard-user identity |
| `customer_profiles` | Extend conditionally | Keep current data; add shared payment-term FK only when its canonical table exists |
| `products` | Keep | Subscription product target |
| `product_variants` | Keep | Source of base and minimum price |
| `pricing_tiers` | Keep | Existing customer-specific and general tier definitions |
| `customer_pricing_tiers` | Keep | Existing general-tier assignment |
| `price_histories` | Keep | Remains variant-price configuration history only |
| `price_floor_overrides` | Extend | Add nullable subscription provenance |
| `audit_logs` | Keep | Store all customer/subscription activity |
| Spatie role/permission tables | Extend through data | Add fixed CRM permissions and roles, no new access-control tables |
| `product_subscriptions` | New | Subscription definition and lifecycle |
| `product_subscription_products` | New | Unique subscription-to-product links |
| `customer_product_subscriptions` | New | Unique subscription-to-customer-profile assignments |

## Product Subscription

**Table**: `product_subscriptions`

| Field | Type | Rules |
|---|---|---|
| `id` | unsigned big integer | Primary key; also the deterministic tie-breaker |
| `name` | string, 150 | Required; unique including soft-deleted rows |
| `discount_type` | string, 20 | `percentage` or `fixed` |
| `discount_value` | decimal, 15,2 | Required; greater than zero; percentage no greater than 100 |
| `visibility` | string, 20 | `public` or `restricted` |
| `is_active` | boolean | Defaults to false |
| `valid_from` | date, nullable | Inclusive start date |
| `valid_until` | date, nullable | Inclusive end date; not earlier than `valid_from` |
| `created_by` | unsigned big integer | Required dashboard actor; restrict on delete |
| `updated_by` | unsigned big integer, nullable | Latest dashboard actor; null on delete |
| `created_at` / `updated_at` | timestamps | Standard timestamps |
| `deleted_at` | timestamp, nullable | Soft deletion |

**Indexes**:

- Unique: `name`
- Query support: `(is_active, valid_from, valid_until, deleted_at)`
- Query support: `(visibility, is_active, deleted_at)`
- Foreign-key indexes for `created_by` and `updated_by`

**Derived display state**:

- `deleted`: `deleted_at` is not null
- `inactive`: not deleted and `is_active = false`
- `scheduled`: active and current business date is before `valid_from`
- `expired`: active and current business date is after `valid_until`
- `active`: active, not deleted, and current date is within the inclusive
  validity window

The derived display state is not stored.

## Subscription Product Link

**Table**: `product_subscription_products`

| Field | Type | Rules |
|---|---|---|
| `product_subscription_id` | unsigned big integer | Required; references subscription; cascade on delete only for test/administrative hard deletion |
| `product_id` | unsigned big integer | Required; references product; restrict on delete |
| `created_at` / `updated_at` | timestamps | Records link time |

**Keys and indexes**:

- Composite unique: `(product_subscription_id, product_id)`
- Reverse lookup index: `(product_id, product_subscription_id)`

**Behavior**:

- A link is selectable only for active, non-deleted products.
- Price eligibility expands the product link to its active, non-deleted
  variants.
- An inactive/deleted product is ignored for new pricing but the link remains.
- Link/unlink activity is written to `audit_logs` against the parent
  subscription.

## Customer Subscription Assignment

**Table**: `customer_product_subscriptions`

| Field | Type | Rules |
|---|---|---|
| `product_subscription_id` | unsigned big integer | Required; references subscription |
| `customer_profile_id` | unsigned big integer | Required; references customer profile; restrict on delete |
| `created_at` / `updated_at` | timestamps | Records assignment time |

**Keys and indexes**:

- Composite unique: `(product_subscription_id, customer_profile_id)`
- Eligibility lookup: `(customer_profile_id, product_subscription_id)`

**Behavior**:

- New assignments require an active, non-deleted customer profile whose linked
  user is a customer-channel user.
- Customer deactivation or soft deletion does not remove the assignment; it
  makes the assignment ineligible.
- Detachment removes current entitlement and writes the before/after
  relationship change to `audit_logs`.

## Existing Customer Profile

No replacement table or resource is created.

Current fields retained:

- `user_id`
- `customer_code`
- `company_name`
- `address`
- `default_payment_term_id`
- `is_active`
- blameable fields, timestamps, and soft deletion

Planned relationship additions:

- `productSubscriptions()` many-to-many through
  `customer_product_subscriptions`
- A query scope for active subscription eligibility

Payment-term rule:

- If the shared `payment_terms` table/model exists, add the foreign key using a
  forward migration and expose the shared selector.
- If it does not exist, do not create a parallel CRM table; leave the field
  nullable and keep the UI hidden until the shared prerequisite is delivered.

## Existing Price Floor Approval

**Table**: `price_floor_overrides`

Add:

| Field | Type | Rules |
|---|---|---|
| `product_subscription_id` | unsigned big integer, nullable | Identifies the winning subscription; null for tier/base approvals; indexed; restrict on hard delete |

All current fields and behavior remain:

- `product_variant_id`
- `customer_user_id`
- `attempted_price`
- `min_price`
- `approved_by`
- `approved_at`
- `reason`

## Resolved Price Value Object

The existing value object remains the public result. Existing fields are
preserved:

- `amount`
- `pricingTier`

Additive fields:

| Field | Meaning |
|---|---|
| `basePrice` | Variant base price used for the calculation |
| `source` | `customer_specific_tier`, `subscription`, `general_tier`, or `base` |
| `sourceId` | Winning tier or subscription ID; null for base |
| `productSubscription` | Winning subscription model or null |
| `discountType` | `percentage`, `fixed`, or null |
| `discountValue` | Configured value or null |
| `discountAmount` | Difference between base and final amount |
| `minimumPrice` | Variant floor |
| `isBelowFloor` | Whether approval is required |

Compatibility rule:

- Existing callers reading `amount` or `pricingTier` continue to work.
- For a subscription result, `pricingTier` is null and
  `productSubscription` is populated.
- For a base-price result, both source models are null.

## State Transitions

```text
inactive --activate with products and valid restrictions--> active
active -----------------------------------------------> inactive
inactive/active --------------------------------------> soft-deleted
soft-deleted -----------------------------------------> restored inactive
```

Activation guards:

- At least one linked active product.
- Valid date range.
- Percentage/fixed discount is valid for every previewed variant.
- Restricted subscriptions have at least one active customer assignment.

Eligibility is evaluated at read time and additionally requires:

- Active, non-deleted customer profile.
- Assignment between the customer profile and subscription.
- Active, non-deleted product and variant.
- Current business date within subscription validity.

## Deletion Rules

- Customers and subscriptions use soft deletion.
- Force deletion is not exposed by the feature.
- Product and customer links are not cascaded by soft deletion.
- Existing history and audit rows are never physically removed by feature
  actions.
- A restored subscription is forced to inactive.

## Concurrency and Integrity

- Database unique constraints are authoritative for duplicate links.
- Mutation service operations run in transactions.
- Activation locks the subscription and rechecks product/customer prerequisites.
- Link and assignment synchronization uses transactional relationship
  operations.
- A duplicate-key race is converted to a clear validation/domain error.
