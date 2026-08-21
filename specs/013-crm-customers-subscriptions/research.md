# Research: CRM Customers and Product-Scoped Pricing Tiers

**Date**: 2026-08-02

**Basis**: Supplied dashboard SRS, product-owner revisions, current Laravel/Filament source, live MySQL schema, route discovery, Git reachability, and Composer gate output.

## Decision 1: Pricing Tiers Are the Only Discount-Agreement Surface

**Decision**: Extend the existing `PricingTier` domain and `/admin/pricing-tiers` screen. Remove the standalone Product Subscription resource, route, model, services, reports, permissions, translations, and tests.

**Rationale**: Customers, tiers, assignments, pricing, floor approval, audit, and reports already exist. A second discount-agreement domain duplicates those capabilities and creates conflicting precedence.

**Alternatives considered**:

- Keep a hidden Product Subscription resource: rejected because direct routes and parallel runtime rules would remain.
- Rename only the page: rejected because the duplicate model, tables, services, and reports would remain.

## Decision 2: Add an Explicit Tier Type

**Decision**: Pricing tiers use `general`, `customer_specific`, or `product_scoped`.

- Existing rows with `customer_user_id` become `customer_specific`.
- Existing rows without `customer_user_id` become `general`.
- Product-scoped rows add product targeting, customer eligibility, optional dates, visibility, and percentage/fixed discount configuration.

**Rationale**: An explicit type prevents behavior from being inferred from a changing set of relationships and supports clear validation and filtering.

## Decision 3: Normalize Discount Configuration Without Breaking Existing Tiers

**Decision**: Rename the existing percentage value to a generic `discount_value` and add `discount_type`.

- Fresh general/customer-specific rows use `percentage`; no legacy value backfill is required after the approved database reset.
- General and customer-specific tiers remain percentage-only in this feature.
- Product-scoped tiers may be percentage or fixed amount.

**Rationale**: One representation avoids two competing discount columns while preserving existing behavior.

## Decision 4: Reuse Customer Tier Assignments

**Decision**: Reuse `customer_pricing_tiers` for general and product-scoped assignments.

- One active general assignment is allowed per customer.
- Multiple product-scoped assignments may coexist.
- Customer-specific tiers continue to use their direct customer link.
- Assignment eligibility requires an active, non-deleted customer profile.

**Rationale**: The table already stores unique customer/tier assignments and active state. A second customer-assignment table is unnecessary.

## Decision 5: Add Only a Tier-to-Product Pivot

**Decision**: Add `pricing_tier_products(pricing_tier_id, product_id, timestamps)` with a composite unique key and reverse lookup index.

**Rationale**: Product-level targeting matches the SRS and expands naturally to active variants without duplicating catalog data.

## Decision 6: Keep One Resolver and One Result Contract

**Decision**: Extend the existing `PriceResolver` and keep its public input signature.

Resolution order:

1. active customer-specific tier;
2. eligible product-scoped tier;
3. active assigned general tier;
4. variant base price.

Product-scoped ties use lowest final amount then lowest tier ID. Exactly one source is applied.

**Rationale**: Existing callers remain source-compatible and every commercial price uses one deterministic rule set.

## Decision 7: Store Tier Provenance on Floor Approvals

**Decision**: Add nullable `pricing_tier_id` to the existing `price_floor_overrides` table and remove the unfinished subscription provenance column.

**Rationale**: The winning source is now always a pricing tier or base price. A generic tier reference supports all tier types.

## Decision 8: Move Tier Mutations Into One Transactional Service

**Decision**: Use a dedicated pricing-tier service for lifecycle, validation, product links, customer assignments, and audit writes. Keep variant pricing and floor approval in the existing product-pricing service.

**Rationale**: This removes the subscription service without overloading dashboard callbacks or duplicating pricing responsibilities.

## Decision 9: Rename Permissions, Reports, and Audit Events

**Decision**:

- Replace `crm.subscription.*` abilities with granular `crm.pricing-tier.*` abilities.
- Preserve fixed roles and existing inventory-pricing permissions.
- Extend the existing Pricing Tiers and Customer Assignments reports and add one Pricing Tier Eligibility report if expiry/status analysis requires a separate view.
- Use `pricing.tier.*` audit actions and the existing audit store.

**Rationale**: User-visible and authorization terminology must match the only remaining domain.

## Decision 10: Exclude Customer Payment Terms From CRM

**Decision**: Remove payment-term acceptance criteria, tasks, UI plans, and CRM writable input. Retain the existing nullable database column and global sales/accounting documentation because payment terms are a separate shared capability.

**Rationale**: The product owner removed payment terms from this module, not from the ERP as a whole.

## Decision 11: English-Only Feature Delivery

**Decision**: Add and test English strings only for this feature revision. Remove subscription-specific Arabic additions and Arabic CRM acceptance tests; leave unrelated global Arabic resources intact.

**Rationale**: This matches the requested phase boundary without globally disabling localization.

## Decision 12: Remove Legacy Runtime Artifacts Safely

**Verified state**:

- The subscription commits are local to `codex/crm-customer-subscriptions` and are not reachable from a remote branch or tag.
- The pre-implementation database snapshot captured on 2026-08-02 contained zero subscription definitions, links, customer assignments, subscription-linked floor approvals, subscription audit rows, and non-null customer payment-term values. A later verification attempt could not reconnect to local MySQL, so implementation must repeat the guard against the deployment target rather than rely on this snapshot.

**Decision**:

- Remove the local-only creation and provenance migrations from the final tree.
- Do not add a legacy cleanup migration because the user confirmed a fresh migration reset before implementation.
- Fresh installations create only the pricing-tier schema.

**Rationale**: This removes obsolete runtime structures without silently discarding unexpected data in another environment.

## Decision 13: Preserve Navigation Outside CRM

**Decision**: Keep pricing controls in CRM and restore the Purchasing module as the owner of suppliers, purchase orders, and supplier confirmations.

**Rationale**: The pre-implementation branch had removed Purchasing while its regression test and prior navigation contract still expected it; restoring ownership resolved that unrelated full-gate regression.

## Verified Composer Baseline and Completion

The 2026-08-02 documentation pass ran the actual commands without changing production code:

- `composer test`: failed in `rector --dry-run` on one blank-line change and two obsolete `ReflectionProperty::setAccessible(true)` calls.
- `composer test:types`: passed.
- `composer test:type-coverage`: passed at 100.0%.
- `composer test:unit`: 577 passed, 2 failed, 3,250 assertions.
- `composer test:coverage`: reached the same two failures after the serial run and therefore produced no valid 100% code-coverage result.

The two functional baseline failures were the obsolete Arabic Product Subscription assertion and the Purchasing navigation expectation.

After implementation on 2026-08-02, the exact `composer test` command completed with exit code 0. Pint, Rector, and PHPStan passed; Pest passed 602 tests with 3,328 assertions; type coverage and serial code coverage both reached 100.0% without changing thresholds or adding baseline entries.
