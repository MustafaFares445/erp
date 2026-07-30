# Research: CRM Customers and Product Subscriptions

**Date**: 2026-07-29

**Basis**: Supplied dashboard SRS, current source and database audit, current
routes, focused tests, project constitution, and version-specific Laravel 13 /
Filament 5 documentation.

## Decision 1: Extend the current customer profile

**Decision**: Keep `CustomerProfile`, `customer_profiles`, `CustomerResource`,
its factory, observer, and existing route. Add only missing fields, permissions,
relations, actions, and tests.

**Rationale**: The profile already enforces one customer profile per customer
user, has a unique customer code, active state, blameable fields, soft deletion,
search, filters, and dashboard CRUD.

**Alternatives considered**:

- A new CRM customer table or resource: rejected because it would create two
  customer identities and break current references.
- Storing subscription customers directly as unrelated users: rejected because
  it would bypass the profile's active lifecycle.

## Decision 2: Do not rebuild existing pricing controls

**Decision**: Preserve the existing `PricingTier`,
`CustomerPricingTier`, `PriceHistory`, `PriceFloorOverride`,
`ProductPricingService`, and their dashboard resources.

**Rationale**: These are already implemented and tested. Subscription pricing is
one new candidate within the existing resolution chain, not another pricing
system.

**Alternatives considered**:

- Subscription-specific pricing tiers: rejected as duplicate configuration.
- A second subscription price resolver used only by CRM: rejected because sales
  and dashboard previews could disagree.
- Recording subscription changes in `price_histories`: rejected because that
  table represents product-variant price-setting history, not discount-agreement
  configuration.

## Decision 3: Add only the missing subscription persistence

**Decision**: Add three tables:

1. `product_subscriptions`
2. `product_subscription_products`
3. `customer_product_subscriptions`

Add a nullable `product_subscription_id` provenance field to the existing
`price_floor_overrides`.

**Rationale**: No subscription persistence exists. Product and customer
identities, pricing tiers, audit logs, and floor approvals already exist.

**Alternatives considered**:

- JSON arrays of customers/products on a subscription: rejected because they
  weaken referential integrity, uniqueness, filtering, and reporting.
- A variant-level link table: rejected because the approved scope links
  subscriptions to products and applies to active variants.
- A new floor-override table: rejected because the existing table and approval
  workflow already represent the same business event.

## Decision 4: Link assignments to customer profiles

**Decision**: `customer_product_subscriptions` references
`customer_profiles.id`. The resolver receives the existing customer `User` and
loads its active profile before checking subscription assignments.

**Rationale**: Entitlement depends on `customer_profiles.is_active` and the
profile is the canonical commercial customer. Existing pricing tiers continue
to use `customer_user_id`; the resolver bridges both without migrating them.

**Alternatives considered**:

- Reference `users.id`: compatible with existing tiers but makes subscription
  eligibility repeatedly reconstruct profile state and permits non-customer
  users unless guarded everywhere.
- Migrate existing pricing tiers to profile IDs: rejected as unrelated,
  high-risk churn.

## Decision 5: Link subscriptions to products

**Decision**: `product_subscription_products` references `products.id`. An
eligible link covers all active, non-deleted variants of that product.

**Rationale**: This matches the SRS wording and avoids duplicating links for
every variant while still pricing from each variant's own base and minimum
price.

**Alternatives considered**:

- Category-wide, all-product, or exclusion rules: rejected as unapproved scope.
- Copying product data into subscription rows: rejected as catalog duplication.

## Decision 6: Derive subscription status

**Decision**: Persist `is_active`, `valid_from`, `valid_until`, and
`deleted_at`; derive the display state as inactive, scheduled, active, expired,
or deleted.

**Rationale**: A separate persisted status can drift from dates and activity.
Validity boundaries are inclusive business dates.

**Alternatives considered**:

- Persist a status enum alongside dates: rejected because it creates redundant
  state.
- Background jobs that change status at midnight: rejected because derived
  status is deterministic and does not require a scheduler.

## Decision 7: Use one transactional mutation service

**Decision**: Add `ProductSubscriptionService` as the only writer for
subscription lifecycle, product links, and customer assignments. It uses
transactions, database locking for conflicting changes, unique constraints as
the final concurrency guard, and `AuditLogger`.

**Rationale**: Existing pricing configuration already follows the service-based
mutation pattern through `ProductPricingService`.

**Alternatives considered**:

- Direct writes from dashboard page callbacks: rejected because rules and audit
  behavior would be duplicated across screens.
- Model observers as the sole relationship audit mechanism: rejected because
  attach/detach operations do not reliably express the business action through
  ordinary model events.

## Decision 8: Extend the current price resolver additively

**Decision**: Keep `PriceResolver::resolve(ProductVariant, ?User)` as the single
entry point. Insert the subscription candidate between the customer-specific
tier and general tier. Extend `ResolvedPrice` additively while preserving its
existing `amount` and `pricingTier` properties.

**Rationale**: Current callers and tests depend on the existing resolver and
DTO. Additive fields permit provenance without breaking existing behavior.

**Alternatives considered**:

- Change the method signature to accept a profile or subscription: rejected
  because it would break callers and allow a caller to force a non-winning
  source.
- Return an unrelated subscription DTO: rejected because all price sources must
  have one observable contract.

## Decision 9: Resolve multiple subscriptions deterministically

**Decision**: Calculate every eligible subscription candidate from the
variant's existing base price, sort by final amount ascending and subscription
ID ascending, and select the first.

**Rationale**: This is the product-owner decision: lowest final price wins, and
the earliest identifier breaks ties.

**Alternatives considered**:

- Highest percentage or largest nominal discount: rejected because fixed and
  percentage discounts are not directly comparable.
- Most recently assigned or newest subscription: rejected because it is less
  stable and was not approved.
- Stacking: explicitly prohibited.

## Decision 10: Reuse the existing floor approval

**Decision**: `PriceResolver` continues to expose/block the below-floor result.
`ProductPricingService::approveFloorOverride()` remains the approval writer and
is extended to accept optional subscription provenance. Only a System Admin
with the explicit floor-override permission may approve.

**Rationale**: The current table already records variant, customer, attempted
price, floor, actor, time, and reason.

**Alternatives considered**:

- Clamp the price to the floor silently: rejected because it hides the winning
  commercial agreement and changes the approved rule.
- Auto-approve for CRM or Pricing Managers: rejected by the approved role
  decision.

## Decision 11: Reuse Spatie permission infrastructure

**Decision**: Add CRM permission enum values and a CRM permission/role seeder
that uses the existing Spatie tables and `User::HasRoles`. Define fixed roles in
code; provide only System Admin role assignment for dashboard-channel users.

**Rationale**: The constitution prohibits a custom access-control system.

**Alternatives considered**:

- Booleans on users or subscriptions: rejected as a parallel authorization
  system.
- A full permission editor: rejected because the approved scope is fixed roles.

## Decision 12: Authorize relationship and bulk actions explicitly

**Decision**: Every attach, detach, activation, deletion, restore, and bulk
action calls an explicit ability or service authorization. Policies include
`viewAny`, `view`, create/update/delete/restore and matching `*Any` methods where
bulk actions exist.

**Rationale**: Filament 5 attach/detach actions do not automatically consult
model policies, and bulk delete/restore uses `deleteAny`/`restoreAny`.

**Alternatives considered**:

- Rely on hidden buttons: rejected because direct page/action calls still need
  server-side authorization.
- Reuse the current `isAdmin()`-only customer policy unchanged: rejected because
  it cannot express the four approved roles.

## Decision 13: Extend existing reporting and audit infrastructure

**Decision**:

- Add subscription report types and queries to the current report framework
  used by pricing tiers, customer assignments, and floor overrides.
- Use the existing `audit_logs` and `AuditLogger`.
- If a generic Audit Log dashboard resource exists when implementation starts,
  extend it with CRM filters. If it is still absent, add one reusable read-only
  Audit Log resource rather than a subscription-specific log screen.

**Rationale**: The code already provides report filtering/export conventions and
immutable audit storage.

**Alternatives considered**:

- A second CRM export engine: rejected as duplicate reporting infrastructure.
- A subscription audit table: rejected as duplicate audit storage.

## Decision 14: Treat payment terms as a shared dependency

**Decision**: Do not add a CRM-only payment-term table or model. Connect the
existing `customer_profiles.default_payment_term_id` field to the canonical
shared Payment Terms module when that table/model exists. Until then, keep the
field unavailable in the form and record the dependency.

**Rationale**: Payment terms are shared by sales, purchasing, accounting, and
customers. The current database has the customer field but no canonical
`payment_terms` table.

**Alternatives considered**:

- Free-text payment terms: rejected because it loses referential consistency.
- A CRM-specific lookup: rejected as guaranteed future duplication.

## Decision 15: Use the existing dashboard after governance approval

**Decision**: Implement the feature in the current `/admin` dashboard and CRM
navigation group only after the canonical documentation and a CRM-specific ADR
record the approved Filament exception.

**Rationale**: The application already has a real Customer resource in the
dashboard, but the current constitution's written exception names Inventory
only. The product owner approved using the existing dashboard for this feature;
the documentation must be synchronized before production code.

**Alternatives considered**:

- Build a second dashboard: rejected as duplicate UI and architecture.
- Build an API/customer app: rejected by the supplied dashboard-only SRS.

## Verified Baseline

- Revalidated on 2026-07-30: PHP 8.4, Laravel 13.23.0, Filament 5.7.3,
  Livewire 4.3.3, and Pest 4.7.5.
- `/admin` currently exposes the Customer, Customer Pricing Tier, Pricing Tier,
  Price History, and Price Floor Override resources. No Product Subscription
  route exists.
- The live schema has no table whose name contains `subscription`; the shared
  Payment Terms table is also absent.
- Existing source reuse targets are `CustomerProfile`, `CustomerProfilePolicy`,
  `CustomerProfileObserver`, `PriceResolver`, `ProductPricingService`,
  `PriceFloorOverride`, `AuditLogger`, and `InventoryPermissionSeeder`.
- Laravel Boost documentation confirms Eloquent relationship creation,
  database-backed factory tests, and Filament relationship repeaters/relation
  managers for the installed versions. Bulk authorization remains an explicit
  policy concern.
- The worktree was clean before the governance documentation edits. Feature
  changes remain isolated to the CRM workstream.

## Deferred Shared Dependency

The 2026-07-30 schema recheck again found no `payment_terms` table. The
existing nullable `default_payment_term_id` remains hidden from the Customer
form; this CRM feature creates neither a payment-term model nor a parallel
lookup table.
