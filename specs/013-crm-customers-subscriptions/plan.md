# Implementation Plan: CRM Customers and Product-Scoped Pricing Tiers

**Spec-Kit Feature**: 013-crm-customers-subscriptions (historical directory identifier)

**Branch**: codex/crm-customer-subscriptions

**Date**: 2026-08-02

**Spec**: [spec.md](spec.md)

## Summary

Replace the partially implemented standalone Product Subscription domain with product-scoped behavior inside the existing Pricing Tier model, service boundary, report framework, and /admin/pricing-tiers screen. Preserve existing general/customer-specific tier behavior, minimum-price-floor control, price history boundaries, fixed roles, and confirmed-document immutability.

The implementation removes the standalone route/runtime code, delivers new feature strings in English only, and removes customer payment-term work from CRM.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13.23, Filament 5.7, Livewire 4.3, Spatie Laravel Permission 8, Spatie Laravel Data 4

**Storage**: MySQL; existing pricing, customer, catalog, audit, and permission tables

**Testing**: Pest 4.7, PHPUnit 12.5, PHPStan/Larastan 3.10, Pint 1.29, Rector 2.5, Xdebug serial coverage

**Target Platform**: Existing /admin dashboard served by the configured local Laravel site

**Project Type**: Laravel modular monolith with Filament admin resources

**Constraints**:

- no public/customer API or recurring billing;
- no second pricing, assignment, audit, report, or permission store;
- no new PHPStan baseline entries;
- no reduction of 100% type/code coverage thresholds;
- preserve unrelated dirty worktree changes;
- source documentation must be synchronized before production code.

## Constitution Check

| Principle | Result | Treatment |
|---|---|---|
| I. Specification-First | Pass after WP0 | Revised spec/data/contracts/tasks and canonical docs become the source of intent before code |
| II. Modular Monolith | Pass | Pricing rules remain in typed domain services; Filament actions remain adapters |
| III. Financial & Inventory Integrity | Pass | One deterministic resolver, minimum-price floor, transactional mutations, confirmed prices unchanged |
| IV. Unified Access | Pass | Existing Spatie roles/permissions are updated; no custom access store |
| V. AI Isolation | Not applicable | No AI flow |
| VI. Engineering Discipline | Pass when WP7 completes | Business rules, sensitive actions, and complete Composer gate are tested |

The dashboard exception remains authorized by ADR 0002, revised to name CRM Customers and Pricing Tiers rather than a standalone subscription resource.

## Verified Pre-Implementation State

- /admin/pricing-tiers exists as one Filament manage page.
- `/admin/product-subscriptions` exposed four direct routes before implementation and was scheduled for removal.
- Pricing tiers currently store percentage discount, optional direct customer, active state, blameable fields, and soft deletion.
- customer_pricing_tiers already stores unique customer-user/tier pairs with an active-state flag.
- The pre-implementation database snapshot captured on 2026-08-02 contained zero rows in all subscription tables, zero subscription-linked floor approvals/audit rows, and zero non-null customer payment-term values.
- Subscription commits are local and not reachable from any remote branch/tag.
- The pre-implementation composer test was not green: Rector reported three changes; PHPStan and 100% type coverage passed; parallel/serial Pest each failed the same two tests.

## Verified Implementation State

- `/admin/pricing-tiers` is the single CRM pricing-agreement surface and includes general, customer-specific, and product-scoped tier types.
- `/admin/product-subscriptions` has no registered route and returns 404 in the running dashboard.
- The CRM customer form and navigation contain no customer payment-term field or feature.
- Fresh testing migration and seeding complete successfully with only the unified pricing-tier schema.
- `composer test` exits successfully with Pint, Rector, PHPStan, 602 passing tests, 3,328 assertions, 100.0% type coverage, and 100.0% serial code coverage.

## Target Data and Internal Contracts

### Pricing tiers

Extend pricing_tiers with:

- tier_type: general, customer_specific, product_scoped;
- discount_type: percentage or fixed;
- discount_value decimal(15,2), replacing discount_percent;
- nullable visibility, valid_from, valid_until for product-scoped tiers;
- unique name and eligibility indexes.

Backfill existing rows without changing amounts:

- direct customer rows become customer_specific;
- other rows become general;
- all existing discounts become percentage.

### Relationships

- Add pricing_tier_products with unique tier/product pairs and reverse lookup.
- Reuse customer_pricing_tiers for general and product-scoped customer assignments.
- Keep direct customer_user_id for customer-specific tiers.
- Add nullable pricing_tier_id provenance to price_floor_overrides.

### Resolver

Keep the existing resolve input signature and existing amount/pricing-tier result compatibility. Replace the subscription source with product_scoped_tier and populate pricingTier for every tier result.

Precedence:

1. customer-specific tier;
2. lowest eligible product-scoped result, then lowest tier ID;
3. assigned general tier;
4. base price.

### Permissions

Replace subscription abilities with:

- crm.pricing-tier.view;
- crm.pricing-tier.manage;
- crm.pricing-tier.discount.manage;
- crm.pricing-tier.link.manage;
- crm.pricing-tier.restore.

Keep customer, price-preview, report, audit, dashboard-role, inventory-pricing, and floor-approval abilities. Update only the four fixed role mappings and remove exact obsolete permission assignments.

## Project Structure

### Documentation

Update as one consistent set:

- this feature's spec, plan, research, data model, contracts, quickstart, checklist, and tasks;
- ADR 0002 and the constitution exception wording;
- PRD, SDD, ERD, DFD, API contract, system architecture, implementation plan, and testing strategy.

### Existing source to extend

- PricingTier model/resource, customer-tier assignment, pricing services, resolver/result, floor approval, reports/export/formatter, permission seeder, English language file, and related tests.

### Source to remove

- ProductSubscription model/factory/enums/observer/policy/service/calculator;
- ProductSubscriptions Filament resource/pages/schemas/tables/relation managers;
- Customer ProductSubscriptions relation manager;
- subscription report cases, permission cases, runtime provenance, translations, and tests;
- local-only subscription creation migrations and the unfinished subscription floor-provenance migration.

## Implementation Work Packages

### WP0 - Documentation and governance gate

1. Replace subscription terminology and standalone-screen requirements across every feature artifact.
2. Regenerate tasks.md from the revised spec/plan; do not leave completed subscription tasks as future intent.
3. Revise ADR 0002 and the constitution scope exception to approve customer/pricing-tier administration only.
4. Synchronize canonical product, architecture, data-flow, implementation, API, and testing docs.
5. Remove CRM payment-term requirements while retaining global sales/accounting payment-term documentation.
6. Run Spec-Kit analysis and documentation guard checks; resolve all critical/high findings before WP1.

### WP1 - Schema and legacy cleanup

1. Write migration tests before migration implementation.
2. Define the unified pricing_tiers columns directly in the original fresh creation migration.
3. Create pricing_tier_products and add price_floor_overrides.pricing_tier_id directly in fresh migration order.
4. Remove local-only legacy creation/provenance migrations so fresh installs never create obsolete tables.
5. Do not introduce a destructive cleanup or conversion migration; the approved implementation baseline is a fresh database.
6. Remove default_payment_term_id from CRM mass-assignable input while leaving the nullable shared column in storage.

Checkpoint: fresh and upgraded test schemas contain pricing-tier structures and no runtime subscription tables.

### WP2 - Pricing-tier domain

1. Add typed tier-type, discount-type, and visibility enums.
2. Extend PricingTier casts/relationships/scopes/derived status and factory states.
3. Expand PricingTierData to carry type-specific fields.
4. Create one transactional PricingTierService for save, activate/deactivate, delete/restore, product sync, customer assignment, validation, locking, and audit.
5. Preserve one active specific tier and one active general assignment per customer; allow multiple product-scoped assignments.
6. Enforce product-scoped activation, date, customer, product, and fixed-price rules at the service boundary and database uniqueness at persistence.

Checkpoint: tier lifecycle and relationships work without Filament and produce no price-history rows.

### WP3 - Resolver and floor integration

1. Remove the subscription model/calculator dependency from PriceResolver.
2. Implement the documented precedence, eligibility, no-stacking, and tie-break rules.
3. Replace the subscription property/source in ResolvedPrice and ResolvedPriceSource.
4. Persist/filter/display pricing_tier_id through floor-approval DTO/model/service/resource.
5. Keep below-floor use blocked without explicit System Admin permission and reason.
6. Prove confirmed-document pricing remains outside resolver mutation and tier edits create no price-history rows.

Checkpoint: every matrix case resolves one auditable source and respects the floor.

### WP4 - Single Pricing Tiers dashboard

1. Keep only /admin/pricing-tiers; add no second resource.
2. Extend the existing table/form with tier type, conditional discount/visibility/date/customer fields, status/count columns, and documented filters.
3. Add authorized modal actions for product links, product-scoped customer assignments, activation/deactivation, and read-only preview.
4. Keep the general-tier assignment action but scope its deactivation to other general assignments only.
5. Remove customer-side subscription relation UI and every Product Subscription route/resource.
6. Restore Purchasing navigation for suppliers/purchase orders/supplier confirmations while keeping pricing controls in CRM.

Checkpoint: direct route discovery has no product-subscriptions route and every tier workflow is reachable from Pricing Tiers.

### WP5 - Authorization, reports, audit, and English

1. Replace subscription permission enum values and fixed-role mappings with pricing-tier values.
2. Make policy/service/action checks support existing inventory-pricing actors and the fixed CRM matrix.
3. Remove subscription report types and extend Pricing Tiers/Customer Assignments; add Pricing Tier Eligibility only where needed.
4. Rename export sheets, filters, columns, and audit actions to pricing-tier terminology.
5. Add/update English labels and validation messages; remove feature-specific Arabic additions/tests without changing unrelated global Arabic files.
6. Keep Audit Log and dashboard-role resources shared and read-only/granular as already designed.

Checkpoint: all four roles pass direct, record, relationship, bulk, report, audit, preview, restore, and floor tests.

### WP6 - Remove obsolete code and references

1. Delete all runtime ProductSubscription classes, relationships, imports, tests, report cases, permission cases, and language keys.
2. Search app, database, tests, lang, feature artifacts, and canonical docs for obsolete terms.
3. Allow legacy names only in historical documentation explaining the removal and architectural absence tests; no migration or runtime symbol may remain.
4. Confirm no duplicate tier, resolver, assignment, audit, report, or permission implementation was introduced.

### WP7 - Verification and delivery gate

1. Fix the three current Rector findings without unrelated refactoring.
2. Replace obsolete subscription/Arabic tests with pricing-tier tests and restore the Purchasing navigation regression contract.
3. Run focused customer, tier-domain, resolver, floor, report, permission, audit, and Filament tests.
4. Run migrate:fresh --seed on a disposable test database, route/schema discovery, and manual /admin/pricing-tiers validation.
5. Run vendor/bin/pint --dirty --format agent, PHPStan with no new baseline, and git diff --check.
6. Run composer test once focused gates are green. Completion requires exit code 0, 100% type coverage, 100% code coverage, and no skipped/deleted quality gates.
7. Record exact commands, exit codes, test/assertion counts, coverage, routes, schema, and browser outcomes in quickstart.md.

## Dependency Order

WP0 -> WP1 -> WP2 -> WP3 -> WP4 -> WP5 -> WP6 -> WP7

Tests for each work package are written before its behavior. Reports/UI may begin after the WP2 interfaces are stable, but WP7 remains the only completion gate.

## Migration and Rollback

- Apply this migration history only to a fresh database, as approved for this implementation.
- The original creation migrations define pricing tiers, product links, assignments, and floor provenance in dependency order.
- Rollback uses the normal migration `down()` sequence; no subscription-era schema or data conversion is attempted.
- Any future deployment against a populated legacy subscription database requires a separately specified conversion project.

## Explicit Non-Deliverables

- Standalone Product Subscriptions page/model/API/table/report.
- Customer payment-term selector or CRM payment-term dependency.
- Arabic feature acceptance in this phase.
- Customer-facing pricing/subscription UI.
- Recurring billing, category/all-products rules, discount stacking, or a general permission editor.
- New audit, price-history, floor-approval, customer, product, or report stores.

## Completion Definition

The feature is complete only when documentation and code contain one Pricing Tier domain, /admin/product-subscriptions is absent, current general/customer-specific prices are unchanged, product-scoped behavior passes its full matrix, payment terms are absent from CRM, and composer test finishes successfully at its existing thresholds.
