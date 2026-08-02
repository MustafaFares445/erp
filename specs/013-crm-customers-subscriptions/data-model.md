# Data Model: CRM Customers and Product-Scoped Pricing Tiers

**Date**: 2026-08-02

## Reuse and Change Classification

| Entity or table | Treatment |
|---|---|
| `users` | Keep as customer/dashboard identity |
| `customer_profiles` | Keep; remove payment-term input from CRM, retain the shared nullable column |
| `products`, `product_variants` | Keep as product target and base/minimum-price source |
| `pricing_tiers` | Extend with explicit type and product-scoped discount/lifecycle fields |
| `customer_pricing_tiers` | Reuse for general and product-scoped customer assignments |
| `pricing_tier_products` | Add as the only new relationship table |
| `price_histories` | Keep for variant price-setting history only |
| `price_floor_overrides` | Extend with nullable pricing-tier provenance |
| `audit_logs` | Keep as the single immutable audit store |
| Spatie role/permission tables | Update fixed permission data only |
| Product Subscription tables | Not created by the fresh schema; obsolete creation migrations are removed |

## Pricing Tier

**Table**: `pricing_tiers`

| Field | Type | Rules |
|---|---|---|
| `id` | unsigned big integer | Primary key; tie-breaker for equal product-scoped candidates |
| `name` | string, 150 | Required; unique including soft-deleted rows |
| `tier_type` | string, 30 | `general`, `customer_specific`, or `product_scoped` |
| `discount_type` | string, 20 | `percentage` or `fixed`; fixed allowed only for product-scoped tiers |
| `discount_value` | decimal, 15,2 | Percentage 0–100; fixed greater than zero |
| `customer_user_id` | unsigned big integer, nullable | Required only for customer-specific tiers |
| `visibility` | string, 20, nullable | `public` or `restricted`; product-scoped only |
| `valid_from` | date, nullable | Inclusive start; product-scoped only |
| `valid_until` | date, nullable | Inclusive end; not before start; product-scoped only |
| `is_active` | boolean | Existing active switch |
| `created_by`, `updated_by` | unsigned big integer, nullable | Existing blameable users |
| timestamps / `deleted_at` | timestamps | Existing lifecycle fields |

**Fresh-schema definition**:

- The original pricing-tier creation migration defines these columns directly.
- General tiers default to `tier_type = general` and `discount_type = percentage`.
- No `discount_percent` compatibility column or data-conversion migration is retained because the implementation target was explicitly reset with fresh migrations.

**Indexes and constraints**:

- unique tier name;
- `(tier_type, is_active, deleted_at)`;
- `(is_active, valid_from, valid_until, deleted_at)`;
- `(visibility, is_active, deleted_at)`;
- existing customer and blameable foreign keys.

## Tier Product Link

**Table**: `pricing_tier_products`

| Field | Type | Rules |
|---|---|---|
| `pricing_tier_id` | unsigned big integer | Required; cascade when the tier is physically removed outside the dashboard |
| `product_id` | unsigned big integer | Required; restrict physical product deletion |
| timestamps | timestamps | Record link time |

**Constraints**:

- unique `(pricing_tier_id, product_id)`;
- reverse lookup `(product_id, pricing_tier_id)`.

**Behavior**:

- Only product-scoped tiers may have product links.
- New links require active, non-deleted products.
- Eligibility expands a product link to that product's active variants.
- Link/unlink writes one audit event against the tier.

## Customer Tier Assignment

**Table**: `customer_pricing_tiers` (existing)

No new customer/tier pivot is created.

Existing identity columns are retained:

| Field | Type | Rules |
|---|---|---|
| `customer_user_id` | unsigned big integer | Customer-channel `users.id`; the linked active `CustomerProfile` determines CRM eligibility |
| `pricing_tier_id` | unsigned big integer | Assigned general or product-scoped tier |
| `is_active` | boolean | Assignment lifecycle flag |
| timestamps | timestamps | Existing assignment history |

**Rules**:

- General assignment: one active general assignment per customer; assigning another general tier deactivates only the previous general assignment.
- Product-scoped assignment: multiple active assignments may coexist; assignment/unassignment is independent.
- Customer-specific tier: continues to use `pricing_tiers.customer_user_id` and does not create a pivot row.
- New assignments require a customer-channel user with an active, non-deleted customer profile.
- Customer deactivation keeps history but makes every assignment ineligible.

## Customer Profile

No replacement table or resource is created.

CRM writable fields:

- `user_id`
- `customer_code`
- `company_name`
- `address`
- `is_active`

`default_payment_term_id` remains nullable in storage for the future shared sales/accounting module but is not accepted, displayed, validated, or documented as a CRM capability.

## Price Floor Approval

**Table**: `price_floor_overrides`

Add:

| Field | Type | Rules |
|---|---|---|
| `pricing_tier_id` | unsigned big integer, nullable | Winning tier provenance; null for base-only approvals; indexed; restrict physical deletion |

Remove the unfinished `product_subscription_id` column. All existing approval fields and System Admin/reason rules remain unchanged.

## Resolved Price Value Object

The existing result keeps its public inputs and existing `amount` and `pricingTier` compatibility.

| Field | Meaning |
|---|---|
| `amount` | Final candidate amount |
| `pricingTier` | Winning tier for every tier source; null for base |
| `source` | `customer_specific_tier`, `product_scoped_tier`, `general_tier`, or `base` |
| `baseAmount` | Variant base price used for calculation |
| `discountType` / `discountValue` | Winning configured discount or null for base |
| `discountAmount` | Base minus final amount |
| `minimumPrice` | Variant floor |
| `isBelowFloor` | Whether explicit approval is required |

No `ProductSubscription` property or subscription source remains.

## State and Eligibility

Derived tier state:

- `deleted`: soft-deleted;
- `inactive`: not deleted and inactive;
- `scheduled`: active product-scoped tier before `valid_from`;
- `expired`: active product-scoped tier after `valid_until`;
- `active`: active, not deleted, and within any validity window.

Product-scoped activation requires:

- valid discount and date range;
- at least one active linked product;
- at least one active assigned customer when visibility is restricted.

Price eligibility additionally requires:

- active, non-deleted customer profile and linked user;
- active tier and applicable dates;
- active assignment for general/product-scoped tiers;
- active linked product and variant for product-scoped tiers;
- a positive calculated result.

## Deletion, Concurrency, and Cleanup

- Dashboard actions use soft deletion and expose no force delete.
- Product-scoped restoration forces the tier inactive; existing general/customer-specific restoration behavior remains compatible.
- Unique database constraints are authoritative for name, product-link, and customer-assignment races.
- Tier lifecycle and relationship mutations run transactionally with audit writes.
- Fresh migration history contains no Product Subscription table or provenance column, so no destructive legacy cleanup migration is part of this implementation.
