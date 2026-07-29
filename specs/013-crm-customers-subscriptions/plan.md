# Implementation Plan: CRM Customers and Product Subscriptions

**Spec-Kit Feature**: `013-crm-customers-subscriptions`
**Date**: 2026-07-29
**Spec**: [spec.md](spec.md)

**Input**: The supplied dashboard SRS, corrected feature specification,
product-owner decisions, and a current code/schema/route/test audit.

## Summary

Extend the existing IERP customer and pricing implementation with one missing
domain: product discount subscriptions. The implementation keeps the current
Customer, Pricing Tier, Customer Pricing Tier, Price History, Price Floor
Override, Audit Log, product catalog, permissions, and report infrastructure.
It adds only subscription definitions and their product/customer links, then
inserts one deterministic subscription candidate into the current price
resolver.

The feature remains dashboard-only. It introduces no customer app, website,
public API, recurring billing, second pricing engine, second customer entity,
or duplicate audit/report storage.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13.23.0, Filament 5.7.3, Livewire 4.3.3,
Spatie Laravel Permission 8.3.0, existing application audit and pricing
services

**Storage**: MySQL through Eloquent; three new normalized subscription tables
and one nullable provenance column on the existing floor-override table

**Testing**: Pest 4.7.5 / PHPUnit 12.5.30, Filament/Livewire feature tests,
architecture tests, PHPStan/Larastan 3.10.0, Pint, Rector, Composer CI gate

**Target Platform**: Existing Laravel application and `/admin` Filament
dashboard

**Project Type**: Laravel modular monolith with server-rendered dashboard

**Performance Goals**:

- Paginated customer/subscription lists without N+1 queries.
- Price preview and resolution use a bounded number of queries independent of
  the number of candidate subscriptions.
- Indexed filtering by customer, product, activity, visibility, and validity.
- No unbounded relationship options loaded into dashboard forms.

**Constraints**:

- Preserve current route and public class contracts where possible.
- No new package dependencies.
- No new PHPStan baseline entries.
- No discount stacking.
- Minimum price and confirmed-document immutability are non-negotiable.
- Relationship and bulk actions require explicit server-side authorization.
- Existing unrelated worktree changes must not be overwritten or staged.

**Scale/Scope**: Existing ERP database scale; all dashboard lists are paginated
and all many-to-many selectors are searchable. The design supports large
customer/product link sets without materializing all options.

## Constitution Check

### Pre-design gate

| Principle | Result | Evidence / required action |
|---|---|---|
| I. Specification-First | Conditional pass | Feature spec and data model are complete. Update PRD, SDD, ERD, implementation/testing docs, and add the CRM dashboard ADR before production code. |
| II. Modular Monolith | Pass | Business mutations live in a subscription service; pricing remains in the existing inventory pricing domain. |
| III. Financial Integrity | Pass | Existing resolver/floor workflow is extended, mutations are transactional, and confirmed documents are not repriced. |
| IV. Unified Access | Pass | Existing Spatie roles/permissions are extended; no custom role system. |
| V. AI Isolation | Not applicable | No AI behavior. |
| VI. Engineering Discipline | Pass | Every behavior has a focused Pest test and existing quality gates remain unchanged. |

### Governance blocker

The constitution currently approves Filament only for Inventory. The product
owner has selected the existing `/admin` dashboard for CRM, so implementation
must begin with:

1. A CRM-specific Filament ADR using the next available ADR number.
2. A constitution minor-version amendment extending the approved exception.
3. Matching PRD/SDD scope updates.

Design may proceed, but production-code tasks must not start until these
governance artifacts are approved and merged with this feature.

### Post-design gate

The Phase 1 design introduces no second UI, API, pricing path, customer
identity, access-control store, audit store, or reporting engine. The remaining
conditional gate is the documented CRM Filament exception above.

## Current Implementation Inventory

The action column is authoritative for avoiding duplicate features.

| Requirement area | Current implementation | Action |
|---|---|---|
| Customer identity/profile | `CustomerProfile`, `customer_profiles`, `CustomerResource`, form/table/pages/factory/observer | **Extend existing** |
| Customer search/filter/soft delete | Existing Customer table and resource actions | **Keep and test; extend only missing role/restore audit behavior** |
| Default payment term field | Column exists; canonical table/model absent | **Wait for shared Payment Terms dependency; never create CRM-specific duplicate** |
| Customer-specific tiers | `PricingTier` with `customer_user_id` | **Keep as-is** |
| General customer tier | `CustomerPricingTier` and assignment resource | **Keep as-is** |
| Product prices/floors | `ProductVariant` pricing fields and `ProductPricingService` | **Keep and extend service only for provenance** |
| Price resolution | `PriceResolver` and `ResolvedPrice` | **Extend additively** |
| Product price history | `PriceHistory` and read-only resource | **Keep as-is; do not put subscription changes here** |
| Floor approval | `PriceFloorOverride`, service method, read-only resource | **Extend existing row with optional subscription source** |
| Audit storage | `AuditLogger`, `AuditLog`, `audit_logs` | **Reuse; add missing restore/relationship events and generic UI only if absent** |
| Pricing reports | `InventoryReportType`, `InventoryReportService`, existing report resource | **Extend existing framework** |
| Access control | Spatie permission tables, `HasRoles`, policies, inventory permission enum/seeder | **Extend with fixed CRM permissions/roles** |
| Products/variants | Existing catalog models/resources | **Keep as-is and link by foreign key** |
| Arabic/RTL | Existing translation files and panel RTL configuration | **Extend translations** |
| Product subscriptions | No model/table/resource/service/routes | **New implementation** |
| Subscription product links | Absent | **New implementation** |
| Subscription customer assignments | Absent | **New implementation** |

## Anti-Duplication Checkpoint

At the start of every implementation work package:

1. Re-run file, route, and schema discovery for the target capability.
2. If another feature has added the capability since this plan, convert the task
   from “create” to “extend” and preserve its public contract.
3. Never create a second resource/service/table because a planned name is now
   occupied.
4. Add changes only through the canonical existing service when one exists.
5. Stage and review only feature-owned files; preserve the current unrelated
   dirty worktree.

## Project Structure

### Documentation

```text
specs/013-crm-customers-subscriptions/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── dashboard-ui.md
│   └── pricing-resolution.md
└── checklists/
    └── requirements.md
```

### Existing source to extend

```text
app/
├── Data/Inventory/ResolvedPrice.php
├── Enums/
│   ├── InventoryPermission.php
│   └── InventoryReportType.php
├── Filament/Resources/
│   ├── Customers/
│   ├── CustomerPricingTiers/
│   ├── InventoryReports/
│   ├── PriceFloorOverrides/
│   ├── PriceHistories/
│   ├── PricingTiers/
│   └── ProductVariants/
├── Models/
│   ├── CustomerProfile.php
│   ├── CustomerPricingTier.php
│   ├── PriceFloorOverride.php
│   ├── PriceHistory.php
│   └── PricingTier.php
├── Observers/CustomerProfileObserver.php
├── Policies/
│   ├── CustomerProfilePolicy.php
│   ├── CustomerPricingTierPolicy.php
│   ├── PriceFloorOverridePolicy.php
│   ├── PriceHistoryPolicy.php
│   └── PricingTierPolicy.php
└── Services/
    ├── Audit/AuditLogger.php
    └── Inventory/
        ├── InventoryReportService.php
        ├── PriceResolver.php
        └── ProductPricingService.php
```

### New source, limited to missing capability

```text
app/
├── Enums/
│   ├── CrmPermission.php
│   ├── ProductSubscriptionDiscountType.php
│   ├── ProductSubscriptionVisibility.php
│   └── ResolvedPriceSource.php
├── Filament/Resources/ProductSubscriptions/
│   ├── Pages/
│   ├── RelationManagers/
│   ├── Schemas/
│   ├── Tables/
│   └── ProductSubscriptionResource.php
├── Models/ProductSubscription.php
├── Observers/ProductSubscriptionObserver.php
├── Policies/ProductSubscriptionPolicy.php
└── Services/Crm/
    ├── ProductSubscriptionService.php
    └── SubscriptionDiscountCalculator.php

database/
├── factories/ProductSubscriptionFactory.php
├── migrations/
│   ├── *_create_product_subscriptions_table.php
│   ├── *_create_product_subscription_products_table.php
│   ├── *_create_customer_product_subscriptions_table.php
│   └── *_add_product_subscription_id_to_price_floor_overrides_table.php
└── seeders/CrmPermissionSeeder.php
```

If a generic `AuditLogResource` is still absent at implementation time, add one
under the existing System/Reports navigation conventions. Do not add a
subscription-specific audit resource.

**Structure Decision**: Use the repository's existing Laravel, Filament, model,
policy, service, factory, migration, seeder, language, and Pest structures. No
new top-level application folder is introduced.

## Implementation Work Packages

### WP0 - Governance and documentation synchronization

**Purpose**: Remove the constitution blocker before production code.

Tasks:

1. Add the next CRM Filament ADR recording:
   - current Customer dashboard implementation;
   - product-owner approval to use `/admin`;
   - dashboard-only scope;
   - no API/customer-app requirement;
   - continued reuse of Inventory pricing controls.
2. Amend the constitution's Product Scope boundary and version.
3. Update `Docs/PRD.md` with dashboard customer/subscription scope and roles.
4. Update `Docs/SDD.md` with lifecycle, relationships, resolver precedence, and
   permission behavior.
5. Update `Docs/database/ERD.md` with only the three new tables and the existing
   floor-override extension.
6. Update affected implementation/testing/sequence documentation only where the
   canonical set requires it.
7. Verify the feature spec, plan, data model, and canonical docs agree.

**Acceptance**:

- CRM Filament usage is explicitly approved.
- No canonical document describes a customer-facing subscription interface or
  recurring billing.
- The documented precedence is specific tier → subscription → general tier →
  base.

### WP1 - Permission foundation and existing customer hardening

**Purpose**: Extend current authorization and customer behavior without
creating a new customer implementation.

Tasks:

1. Add `CrmPermission` values for:
   - customer view/manage/restore;
   - subscription view/manage/discount-manage/link-manage/restore;
   - price preview;
   - CRM report view;
   - CRM audit view;
   - fixed dashboard-role assignment.
2. Add one explicit floor-approval permission to the existing pricing
   permission enum rather than placing floor control in CRM.
3. Add an idempotent `CrmPermissionSeeder` that:
   - creates the permissions;
   - creates/updates fixed roles System Admin, CRM Manager, Pricing Manager,
     Reviewer;
   - assigns only the approved permission set to each role;
   - does not remove unrelated module permissions;
   - gives System Admin the new permissions.
4. Invoke the seeder from the existing database seeding flow.
5. Replace `CustomerProfilePolicy`'s broad admin-only checks with permission
   checks for view/manage/restore and matching bulk abilities.
6. Extend `CustomerProfileObserver` to audit restore and verify deactivate,
   delete, and update actions produce one clear event each.
7. Add the `productSubscriptions()` relationship to `CustomerProfile`.
8. Extend the existing Customer resource:
   - keep the same route/pages;
   - add subscription relation display/actions according to role;
   - keep search, active, and trashed filters;
   - add the shared payment-term selector only if the canonical shared model
     exists.
9. Add a fixed-role assignment screen/action for dashboard-channel users only
   if no generic safe user-role assignment surface exists at implementation
   time.

**Acceptance**:

- No new customer table/model/resource/route.
- Reviewer and Pricing Manager cannot mutate customers.
- CRM Manager cannot restore.
- System Admin can restore and the restore is audited.
- Relationship/bulk actions enforce the same permissions as record actions.

### WP2 - Subscription domain and persistence

**Purpose**: Implement only the missing subscription data and mutation rules.

Tasks:

1. Create the four migrations defined in [data-model.md](data-model.md), with
   foreign keys, unique constraints, and query indexes.
2. Create typed enums for discount type and visibility.
3. Create `ProductSubscription` with:
   - casts for enums, dates, boolean, and decimal value;
   - blameable fields and soft deletion;
   - product and customer-profile relationships;
   - query scopes for current/expired/scheduled/near-expiry eligibility;
   - derived display state;
   - no force-delete behavior.
4. Create a factory with percentage/fixed, public/restricted, active/scheduled/
   expired states.
5. Create `ProductSubscriptionPolicy` implementing the fixed role matrix,
   including bulk abilities.
6. Create `ProductSubscriptionObserver` using the existing `AuditLogger` for
   create/update/activate/deactivate/delete/restore.
7. Create `ProductSubscriptionService` as the only dashboard writer:
   - create/update scalar terms;
   - validate discount/date rules;
   - synchronize product links transactionally;
   - synchronize customer assignments transactionally;
   - reject inactive/deleted customers;
   - activate only after rechecking products and restricted assignments under
     lock;
   - deactivate;
   - soft-delete;
   - restore and force inactive;
   - log link/unlink changes through existing audit storage.
8. Convert database unique-key races to clear domain/validation errors.

**Acceptance**:

- Only three new domain tables exist.
- Duplicate product/customer links fail at both application and database levels.
- Failed multi-record changes roll back completely.
- Restored subscriptions are inactive.
- Relationship and lifecycle changes are audited with before/after data.

### WP3 - Existing pricing integration

**Purpose**: Insert subscription pricing into the current resolver without
breaking existing tiers or callers.

Tasks:

1. Add `SubscriptionDiscountCalculator` as a pure typed calculator:
   - percentage and fixed candidates;
   - currency rounding consistent with current product prices;
   - zero/negative rejection;
   - discount amount output.
2. Add `ResolvedPriceSource`.
3. Extend `ResolvedPrice` additively with the provenance fields in
   [data-model.md](data-model.md); preserve `amount` and `pricingTier`.
4. Extend `PriceResolver::resolve()` in place:
   - keep the existing input contract;
   - keep customer-specific tier lookup first;
   - load eligible subscriptions in one query;
   - calculate candidates without N+1 queries;
   - choose lowest final price then lowest subscription ID;
   - keep general tier and base fallback unchanged;
   - expose the existing floor result.
5. Extend `PriceFloorOverrideData`, `PriceFloorOverride`, and
   `ProductPricingService::approveFloorOverride()` with optional subscription
   provenance.
6. Add a distinct System Admin floor-approval authorization check.
7. Extend the current Price Floor Override resource table/infolist/filter to
   show the subscription source when present.
8. Leave `PriceHistory`, its model, table, and resource semantics unchanged.
9. Identify all current resolver consumers and verify they use stored prices
   for confirmed documents; add regression coverage rather than refactoring
   unrelated sales code.

**Acceptance**:

- With no eligible subscription, current resolver results are byte-for-byte
  equivalent for existing public fields.
- Exactly one source wins.
- Customer-specific tier precedence wins even when a subscription has a lower
  numerical result.
- Multiple subscriptions use the approved deterministic rule.
- Every below-floor subscription result is blocked unless the existing approval
  flow is completed by System Admin.
- Confirmed documents are not repriced.

### WP4 - Dashboard resource integration

**Purpose**: Add one missing Product Subscriptions resource and extend current
screens.

Tasks:

1. Create `ProductSubscriptionResource` in CRM navigation with list/create/view/
   edit pages using the project resource conventions.
2. Build the form:
   - unique name;
   - discount type/value with reactive rules;
   - visibility;
   - validity window;
   - inactive by default;
   - clear activation prerequisites.
3. Build the table:
   - columns and filters defined in `contracts/dashboard-ui.md`;
   - eager-loaded counts;
   - searchable relationship filters;
   - pagination and trashed filter.
4. Route every create/edit/activate/deactivate/delete/restore action through
   `ProductSubscriptionService`.
5. Add Product and Customer relation managers:
   - searchable attach selection;
   - explicit attach/detach/bulk authorization;
   - no duplicate options;
   - no full in-memory option lists.
6. Add the read-only price preview action:
   - customer → linked product → active variant;
   - show all candidates and the winner;
   - show floor warning;
   - never create approval or mutate a document.
7. Extend the current Customer view/edit pages with subscription relationships,
   following the same permission matrix.
8. Add additive subscription context to current Product/Product Variant views
   only where it helps identify eligible agreements; do not duplicate the
   Product Subscription editor there.
9. Register navigation through the existing `AdminModuleRegistry` conventions
   if explicit registry metadata is required.

**Acceptance**:

- Exactly one Product Subscriptions resource/route exists.
- Existing customer and purchasing pricing URLs remain unchanged.
- All mutations use the service; no direct relationship write from a page
  callback.
- Direct action invocation cannot bypass policy checks.
- List/detail queries pass focused N+1/query-count tests.

### WP5 - Reports, audit review, and localization

**Purpose**: Complete reviewability by extending existing infrastructure.

Tasks:

1. Extend `InventoryReportType` with subscription definitions, assignments, and
   eligibility/expiry reporting types.
2. Extend `InventoryReportService` and the current report filters/table:
   - subscription name/status/visibility;
   - customer and product;
   - validity and near-expiry ranges;
   - current eligibility;
   - permission-gated query scopes.
3. Reuse the existing export/formatter flow if these report types are
   exportable; do not add a CRM export queue/table.
4. Re-scan for a generic `AuditLogResource`:
   - extend it with CRM entity/action filters if present;
   - otherwise add one reusable read-only resource backed by `AuditLog`.
5. Add customer/subscription deep links to filtered audit results where the
   resource supports them.
6. Add English and Arabic keys in `lang/en/admin.php` and `lang/ar/admin.php`.
7. Replace hardcoded labels touched in Customer and new subscription/pricing
   UI with translations.
8. Verify RTL, status wording, currency/percentage formatting, validation
   messages, and notifications.

**Acceptance**:

- No new audit or export storage.
- Price History still contains variant price changes only.
- Audit search retrieves every required customer/subscription action.
- Subscription reports use existing pagination/filter/export conventions.
- New and touched UI is usable in English and Arabic RTL.

### WP6 - Test and quality gates

**Purpose**: Prove new behavior and non-regression.

Test groups:

1. **Schema/model**
   - enum casts, relationships, derived states, scopes;
   - unique constraints and foreign keys;
   - restore-inactive behavior.
2. **Mutation service**
   - valid create/update/link/assign;
   - invalid discounts/dates;
   - activation prerequisites;
   - atomic rollback;
   - duplicate race handling;
   - audit records.
3. **Price resolver**
   - current specific/general/base cases unchanged;
   - percentage/fixed examples;
   - validity/customer/product eligibility;
   - no stacking;
   - lowest result/tie ID;
   - floor block/approval/provenance;
   - confirmed-document non-repricing.
4. **Authorization**
   - four-role matrix;
   - direct pages;
   - record actions;
   - relation attach/detach;
   - bulk actions;
   - fixed-role assignment;
   - floor approval.
5. **Filament**
   - create/edit/view/list;
   - table search/filter/pagination/trashed;
   - relationship managers;
   - preview output and notifications;
   - query-count/N+1 checks.
6. **Reports/audit/i18n**
   - report filters and permissions;
   - audit completeness;
   - English/Arabic labels and RTL.
7. **Regression**
   - existing customer/pricing resource tests;
   - existing `PricingServiceTest`, `ProductPricingServiceTest`,
     `PricingControlsResourceTest`, and report tests.

Verification order:

1. Run focused new and regression tests.
2. Run `vendor/bin/pint --dirty --format agent`.
3. Run relevant PHPStan scope, then full `vendor/bin/phpstan analyse`.
4. Run the project's architecture/type/test gates.
5. Run `composer test` as the CI-equivalent final gate.
6. Confirm no new baseline entries or weakened thresholds.

**Acceptance**:

- All focused and full gates pass.
- Existing 25-test/116-assertion customer/pricing baseline remains green or is
  intentionally expanded without lost coverage.
- No unrelated dirty file is modified by feature implementation.

## Dependency Order

```text
WP0 Governance
  └── WP1 Permissions + Customer extension
        └── WP2 Subscription persistence/service
              ├── WP3 Pricing integration
              └── WP4 Dashboard resource
                    └── WP5 Reports/Audit/Arabic
                          └── WP6 Full verification
```

WP3 calculator/value-object work may proceed alongside WP4 layout work only
after WP2's model/service contracts are stable. Final dashboard preview waits
for WP3.

## Migration and Deployment Order

1. Deploy governance/documentation with or before code.
2. Apply subscription definition and pivot migrations.
3. Apply floor-override provenance migration.
4. Run idempotent CRM permission/role seeding.
5. Deploy application code.
6. Warm/clear application caches through the normal deployment process.
7. Validate the role matrix and pricing examples in production-like staging.

Backward compatibility:

- New tables are additive.
- Floor provenance is nullable.
- Existing pricing results do not require subscription rows.
- Existing routes remain stable.

Rollback:

- Disable new navigation and subscription activation before code rollback.
- Existing tier/base resolution continues when subscription code is absent or
  disabled.
- Do not drop subscription/audit data during an operational rollback; schema
  removal requires a separately approved data-retention decision.

## Explicit Non-Deliverables

- No `customers_v2` or CRM-specific customer model.
- No subscription pricing-tier clone.
- No subscription price-history table.
- No subscription floor-override table.
- No subscription audit-log table.
- No second reporting/export engine.
- No payment-term catalog owned by CRM.
- No customer/mobile/website/API interface.
- No recurring billing, renewals, subscription invoices, payments, or tax.
- No broad refactor of existing purchasing or inventory screens.

## Complexity Tracking

| Conditional exception | Why needed | Simpler alternative rejected because |
|---|---|---|
| CRM uses the existing Filament dashboard despite the current Inventory-only exception | The Customer resource already exists there and the product owner explicitly selected one dashboard | A second dashboard would duplicate navigation, authorization, and screens; an API/customer app is outside the supplied SRS |

This exception is resolved through WP0, not silently accepted in code.
