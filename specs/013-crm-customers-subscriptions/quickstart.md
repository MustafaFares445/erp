# Quickstart Validation: CRM Customers and Product-Scoped Pricing Tiers

## Preconditions

- Use a disposable/test database for fresh migration checks.
- Use the approved fresh database state; this implementation does not provide a legacy subscription-data conversion path.
- Seed the fixed dashboard roles and an admin user.
- Keep existing customer/pricing baseline tests green before product-scoped work.

## Baseline Commands

Run from the repository root:

    php artisan migrate:status
    php artisan route:list --path=admin/pricing-tiers --except-vendor
    php artisan route:list --path=admin/product-subscriptions --except-vendor
    php artisan test --compact tests/Feature/PricingServiceTest.php
    php artisan test --compact tests/Feature/ProductPricingServiceTest.php

Expected after implementation:

- pricing tier routes exist;
- no product-subscriptions route is listed;
- no Product Subscription tables/models/resources remain;
- baseline general, customer-specific, and base outcomes remain unchanged.

## Scenario 1: Customer Lifecycle

1. Create a profile for a customer-channel user.
2. Verify duplicate user/code rejection.
3. Search by code, company, account name, and email.
4. Deactivate and verify customer-derived pricing becomes ineligible.
5. Soft-delete and restore as System Admin.
6. Confirm lifecycle audit entries.
7. Confirm no payment-term field is shown or accepted by CRM.

## Scenario 2: Tier Types

1. Verify a migrated general tier retains its percentage and assignment.
2. Verify a migrated customer-specific tier retains its customer and precedence.
3. Create a product-scoped percentage tier.
4. Create a product-scoped fixed tier.
5. Verify type-specific fields and validation.
6. Reject duplicate names, invalid percentages/fixed amounts, and invalid dates.

## Scenario 3: Product and Customer Eligibility

1. Attempt product-scoped activation without products; expect refusal.
2. Link active products; reject duplicates and inactive targets.
3. Assign multiple active customers; reject duplicates/inactive profiles.
4. Verify restricted activation requires an active customer.
5. Verify public classification still requires customer assignment for price eligibility.
6. Remove one product/customer and confirm all other links remain.
7. Confirm relationship audit entries and transactional rollback on failure.

## Scenario 4: Pricing Precedence

Use base price 120:

1. General 5% only: expect 114.
2. Eligible product-scoped 10% plus general 5%: expect 108.
3. Product-scoped fixed 15 plus 10%: expect 108.
4. Equal product-scoped results: expect lowest tier ID.
5. Customer-specific 8% plus lower product-scoped result: expect 110.40 from customer-specific precedence.
6. Expire/deactivate/unassign/unlink product-scoped tiers: expect general or base fallback.
7. Confirm exactly one source and no stacking.

## Scenario 5: Floor Approval

1. Configure a winning tier below the variant minimum.
2. Verify preview shows the floor warning and writes nothing.
3. Verify non-admin approval is denied.
4. Verify System Admin approval requires a reason.
5. Confirm the approval records pricing-tier provenance.
6. Confirm tier edits write audit history but not product price history.

## Scenario 6: Roles and Navigation

Exercise System Admin, CRM Manager, Pricing Manager, and Reviewer against:

- customer and tier list/direct URLs;
- create/edit/lifecycle;
- product/customer link actions;
- preview;
- delete/restore;
- reports/audit;
- floor approval;
- fixed-role assignment.

Confirm Purchasing still owns supplier/purchase-order navigation and CRM owns Pricing Tiers, Price History, and Price Floor Overrides.

## Scenario 7: Reports and English

1. Filter Pricing Tiers by type, visibility, activity, validity, expiry, customer, and product.
2. Review customer assignments and tier eligibility.
3. Export through the existing report/export framework.
4. Filter Audit Log by tier/action/actor/date.
5. Confirm all new feature labels/messages/headings are English.
6. Confirm no subscription-named report or Arabic feature acceptance remains.

## Focused Automated Gate

Run affected suites first:

    php artisan test --compact tests/Feature/CustomerProfileResourceTest.php
    php artisan test --compact tests/Feature/CustomerProfileObserverTest.php
    php artisan test --compact tests/Feature/PricingServiceTest.php
    php artisan test --compact tests/Feature/ProductPricingServiceTest.php
    php artisan test --compact tests/Feature/Filament/PricingControlsResourceTest.php
    php artisan test --compact tests/Feature/InventoryReportServiceTest.php
    php artisan test --compact tests/Feature/InventoryReportResourceTest.php
    php artisan test --compact tests/Unit/AdminModuleRegistryTest.php

Add the new tier-domain, product-link, resolver, migration, permission, report, preview, and query-count files defined in tasks.md to this focused gate as they are created.

## Full Quality Gate

After focused tests pass:

    vendor/bin/pint --dirty --format agent
    vendor/bin/phpstan analyse --memory-limit=1G
    git diff --check
    composer test

composer test is successful only when Pint/Rector, PHPStan, 100% type coverage, parallel Pest, and serial 100% code coverage all finish with exit code 0.

## Implementation Verification Record (2026-08-02)

| Command or check | Result |
|---|---|
| `php artisan migrate:fresh --seed --env=testing` | Passed on the disposable testing database |
| Laravel Boost schema inspection | Passed: `pricing_tiers`, `pricing_tier_products`, and `customer_pricing_tiers` expose the unified columns, indexes, and foreign keys |
| `php artisan route:list --path=admin/pricing-tiers` | Passed: one `GET|HEAD` Filament resource route |
| `php artisan route:list --path=admin/product-subscriptions` | Passed: no matching route |
| Runtime symbol search under `app/`, `database/`, `routes/`, and `lang/` | Passed: no Product Subscription runtime implementation remains; only the shared customer-profile payment-term storage column remains outside CRM scope |
| `vendor/bin/pint --dirty --format agent` | Passed |
| `vendor/bin/phpstan analyse --memory-limit=1G` | Passed with 0 errors |
| `git diff --check` | Passed |
| `composer test` | Passed with exit code 0: Pint passed, Rector changed 0 files, PHPStan reported 0 errors, and Pest passed 602 tests with 3,328 assertions |
| Type coverage | Passed at 100.0% |
| Serial code coverage | Passed at 100.0% |

### Browser verification

The running `http://127.0.0.1:8000` dashboard was checked with the seeded System Admin account:

- `/admin/pricing-tiers` loaded as the English `Pricing Tiers` page and was the only CRM pricing-agreement navigation item.
- The create form exposed General, Customer-specific, and Product-scoped tier types. Product-scoped selection exposed Percentage and Fixed amount discounts, Public/Restricted visibility, and validity dates.
- The customer create form contained customer account, code, company name, address, and active state, with no payment-terms field.
- `/admin/product-subscriptions` returned `404 Not Found`.
- The browser console contained no warnings or errors during the verification.
- Fixed-role access and action boundaries were verified by the automated CRM authorization/resource tests included in the successful full suite.
