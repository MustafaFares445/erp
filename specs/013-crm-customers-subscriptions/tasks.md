# Tasks: CRM Customers and Product-Scoped Pricing Tiers

**Input**: Design documents from specs/013-crm-customers-subscriptions/

**Prerequisites**: spec.md, plan.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Tests are mandatory for every behavior change. Write each behavior test first and confirm it fails for the intended reason.

**Format**: Task IDs are sequential; [P] means independent files; [US#] maps to a user story.

## Phase 1: Documentation and Governance Gate

**Purpose**: Make the revised Pricing Tier design authoritative before production code.

- [x] T001 Reconcile the revised feature spec, plan, research, data model, contracts, quickstart, and checklist in specs/013-crm-customers-subscriptions/
- [x] T002 Replace the superseded completed subscription task list with this dependency-ordered implementation list in specs/013-crm-customers-subscriptions/tasks.md
- [x] T003 [P] Revise the dashboard decision and constitution exception in Docs/adr/0002-filament-crm-dashboard.md and .specify/memory/constitution.md
- [x] T004 [P] Replace subscription terminology and payment-term CRM scope in Docs/PRD.md and Docs/SDD.md
- [x] T005 [P] Replace the subscription schema/data flow with pricing-tier extensions in Docs/database/ERD.md and Docs/database/DFD.md
- [x] T006 [P] Update the runtime boundary and delivery/test workstream in Docs/architecture/SYSTEM_ARCHITECTURE.md, Docs/api/API_CONTRACT.md, Docs/IMPLEMENTATION_PLAN.md, and Docs/TESTING_STRATEGY.md
- [x] T007 Run Spec-Kit cross-artifact analysis and documentation guard review; resolve every critical/high finding in the files updated by T001–T006

**Checkpoint**: Documentation names Pricing Tiers as the only discount-agreement domain and contains no active CRM payment-term, Arabic, or standalone Product Subscription requirement.

---

## Phase 2: Foundational Schema, Types, and Permissions

**Purpose**: Establish the shared data and authorization contracts that block every story.

- [x] T008 [P] Write fresh pricing-tier schema and obsolete-table absence tests in tests/Feature/PricingTierMigrationTest.php and tests/Feature/ProductSubscriptionRemovalTest.php
- [x] T009 [P] Write enum/catalogue/fixed-role mapping tests in tests/Unit/PricingTierTypeTest.php and tests/Unit/CrmPermissionTest.php
- [x] T010 Create tier-type, discount-type, and visibility enums in app/Enums/PricingTierType.php, app/Enums/PricingTierDiscountType.php, and app/Enums/PricingTierVisibility.php
- [x] T011 Define unified pricing_tiers fields/indexes directly in database/migrations/2026_07_24_110251_create_pricing_tiers_table.php
- [x] T012 Create pricing_tier_products with unique/reverse indexes in database/migrations/2026_07_24_110252_create_pricing_tier_products_table.php
- [x] T013 Add nullable pricing_tier_id floor provenance directly in database/migrations/2026_07_24_110255_create_price_floor_overrides_table.php
- [x] T014 Remove obsolete Product Subscription creation and provenance migrations from the approved fresh migration history
- [x] T015 Verify fresh migration order creates no Product Subscription schema and requires no destructive cleanup/conversion migration
- [x] T016 Replace crm.subscription permissions with crm.pricing-tier permissions in app/Enums/CrmPermission.php
- [x] T017 Update fixed-role mappings and exact obsolete permission cleanup in database/seeders/CrmPermissionSeeder.php
- [x] T018 Run and pass tests/Feature/PricingTierMigrationTest.php, tests/Unit/PricingTierTypeTest.php, tests/Unit/CrmPermissionTest.php, and tests/Feature/Filament/CrmPermissionSeederTest.php

**Checkpoint**: The approved fresh schema exposes the target tier fields/pivot/provenance, creates no obsolete subscription tables, and fixed roles use no subscription permission.

---

## Phase 3: User Story 1 - Maintain Customer Profiles (Priority: P1)

**Goal**: Preserve customer lifecycle and remove payment-term input from CRM.

**Independent Test**: Complete customer create/search/update/deactivate/delete/restore without a pricing tier or payment-term control.

### Tests

- [x] T019 [P] [US1] Update customer resource tests for English-only fields and absence of payment-term input in tests/Feature/CustomerProfileResourceTest.php
- [x] T020 [P] [US1] Preserve lifecycle and restore audit coverage in tests/Feature/CustomerProfileObserverTest.php

### Implementation

- [x] T021 [US1] Remove default_payment_term_id from CRM mass-assignable fields while retaining the storage column in app/Models/CustomerProfile.php
- [x] T022 [US1] Ensure the customer form/infolist contains no payment-term field in app/Filament/Resources/Customers/Schemas/CustomerForm.php and app/Filament/Resources/Customers/Schemas/CustomerInfolist.php
- [x] T023 [US1] Preserve customer granular policy and audited restore behavior in app/Policies/CustomerProfilePolicy.php and app/Observers/CustomerProfileObserver.php
- [x] T024 [US1] Run and pass tests/Feature/CustomerProfileResourceTest.php and tests/Feature/CustomerProfileObserverTest.php

**Checkpoint**: Customer lifecycle works independently and CRM neither displays nor writes payment terms.

---

## Phase 4: User Story 2 - Manage Pricing Tiers in One Place (Priority: P1)

**Goal**: Implement general, customer-specific, and product-scoped tiers through one transactional domain.

**Independent Test**: Create/edit/activate/link/assign/delete/restore each applicable tier type without Filament.

### Tests

- [x] T025 [P] [US2] Rewrite model/factory/state-scope tests in tests/Feature/PricingTierModelTest.php
- [x] T026 [P] [US2] Write lifecycle/validation/audit tests in tests/Feature/PricingTierServiceTest.php
- [x] T027 [P] [US2] Write product-link and customer-assignment transaction/race tests in tests/Feature/PricingTierRelationshipServiceTest.php
- [x] T028 [P] [US2] Update existing tier compatibility tests in tests/Feature/ProductPricingServiceTest.php

### Implementation

- [x] T029 [US2] Extend PricingTier fillable/casts/relationships/scopes/status in app/Models/PricingTier.php
- [x] T030 [P] [US2] Add tier/product relations to app/Models/Product.php and customer assignment scopes to app/Models/CustomerPricingTier.php
- [x] T031 [P] [US2] Update pricing-tier factory states in database/factories/PricingTierFactory.php
- [x] T032 [US2] Expand the typed write contract in app/Data/Inventory/PricingTierData.php
- [x] T033 [US2] Implement save/validation/lifecycle/locking/audit in app/Services/Inventory/PricingTierService.php
- [x] T034 [US2] Implement transactional product sync and product-scoped customer assignment in app/Services/Inventory/PricingTierService.php
- [x] T035 [US2] Restrict general assignment replacement to other general tiers and allow multiple product-scoped assignments in app/Services/Inventory/PricingTierService.php
- [x] T036 [US2] Move existing tier mutation callers from ProductPricingService to PricingTierService in app/Filament/Resources/PricingTiers/PricingTierResource.php
- [x] T037 [US2] Keep variant pricing/floor responsibilities and remove tier lifecycle methods from app/Services/Inventory/ProductPricingService.php
- [x] T038 [US2] Run and pass tests/Feature/PricingTierModelTest.php, tests/Feature/PricingTierServiceTest.php, tests/Feature/PricingTierRelationshipServiceTest.php, and tests/Feature/ProductPricingServiceTest.php

**Checkpoint**: All tier types and relationships work through the generic tier domain and create audit—not price-history—records.

---

## Phase 5: User Story 3 - Resolve Effective Customer Price (Priority: P1)

**Goal**: Resolve one source with the documented precedence and floor provenance.

**Independent Test**: Exercise the complete specific/product-scoped/general/base matrix without the dashboard.

### Tests

- [x] T039 [P] [US3] Replace subscription resolver tests with tier-source matrix tests in tests/Feature/PricingTierPriceResolverTest.php
- [x] T040 [P] [US3] Add fixed/percentage/tie/invalid-candidate tests in tests/Unit/PricingTierDiscountCalculatorTest.php and tests/Feature/PricingTierPriceResolverTest.php
- [x] T041 [P] [US3] Add floor approval/provenance/authorization tests in tests/Feature/ProductPricingServiceTest.php
- [x] T042 [P] [US3] Preserve no-price-history and persisted-document pricing boundaries in tests/Feature/PricingServiceTest.php and the existing sales/accounting suites

### Implementation

- [x] T043 [US3] Replace the subscription source with product_scoped_tier in app/Enums/ResolvedPriceSource.php
- [x] T044 [US3] Remove the subscription property and expose complete tier discount/floor data in app/Data/Inventory/ResolvedPrice.php
- [x] T045 [US3] Implement active-profile gating, eligibility, precedence, tie-break, and no-stacking in app/Services/Inventory/PriceResolver.php
- [x] T046 [US3] Replace subscription provenance with pricingTierId in app/Data/Inventory/PriceFloorOverrideData.php and app/Models/PriceFloorOverride.php
- [x] T047 [US3] Persist and audit tier provenance in app/Services/Inventory/ProductPricingService.php
- [x] T048 [US3] Update floor infolist/table/filter terminology in app/Filament/Resources/PriceFloorOverrides/
- [x] T049 [US3] Run and pass tests/Feature/PricingTierPriceResolverTest.php, tests/Unit/PricingTierDiscountCalculatorTest.php, tests/Feature/PricingServiceTest.php, and tests/Feature/ProductPricingServiceTest.php

**Checkpoint**: Every tested overlap returns one source, floor control records the tier, and existing fallback pricing remains compatible.

---

## Phase 6: User Story 4 - Govern and Review Pricing (Priority: P2)

**Goal**: Deliver the single Pricing Tiers UI, fixed-role matrix, reports, audit, and English presentation.

**Independent Test**: Use every role against navigation, direct URLs, row/modal/bulk actions, preview, reports, audit, restore, and floor approval.

### Tests

- [x] T050 [P] [US4] Rewrite Filament tier form/table/action/route tests in tests/Feature/Filament/PricingTierProductScopeResourceTest.php
- [x] T051 [P] [US4] Write product/customer modal action and server-authorization tests in tests/Feature/Filament/PricingTierRelationshipActionTest.php
- [x] T052 [P] [US4] Replace preview tests in tests/Feature/Filament/PricingTierPricePreviewTest.php
- [x] T053 [P] [US4] Rewrite fixed-role matrix tests in tests/Feature/Filament/CrmAuthorizationTest.php
- [x] T054 [P] [US4] Replace subscription reports with tier report tests in tests/Feature/PricingTierReportTest.php
- [x] T055 [P] [US4] Update English-label/no-subscription-route tests in tests/Feature/CrmEnglishLabelsTest.php and tests/Unit/AdminModuleRegistryTest.php
- [x] T056 [P] [US4] Rewrite list/action/preview/report query-count tests in tests/Feature/Filament/PricingTierQueryCountTest.php

### Implementation

- [x] T057 [US4] Add conditional type-specific form fields, columns, filters, and counts in app/Filament/Resources/PricingTiers/PricingTierResource.php
- [x] T058 [US4] Add authorized product/customer/activation/preview modal actions in app/Filament/Resources/PricingTiers/PricingTierResource.php and app/Filament/Resources/PricingTiers/Pages/ManagePricingTiers.php
- [x] T059 [US4] Update PricingTierPolicy for fixed CRM and existing inventory permissions in app/Policies/PricingTierPolicy.php
- [x] T060 [US4] Remove subscription report cases and add/extend tier report cases in app/Enums/InventoryReportType.php and app/Services/Inventory/InventoryReportService.php
- [x] T061 [US4] Update report filters/tables/format/export sheets in app/Filament/Resources/InventoryReports/, app/Services/Inventory/InventoryReportFormatter.php, and app/Services/Inventory/InventoryExportService.php
- [x] T062 [US4] Replace subscription English keys with pricing-tier keys in lang/en/admin.php and remove feature-specific subscription additions from lang/ar/admin.php
- [x] T063 [US4] Keep Pricing Tiers/Price History/Floor Overrides in CRM and restore Purchasing ownership in app/Filament/AdminModuleRegistry.php
- [x] T064 [US4] Keep generic Audit Log and dashboard-role resources aligned with renamed tier actions/permissions in app/Filament/Resources/AuditLogs/ and app/Filament/Resources/DashboardUsers/
- [x] T065 [US4] Run and pass all tests listed in T050–T056 plus tests/Feature/Filament/AuditLogResourceTest.php and tests/Feature/Filament/DashboardUserRoleResourceTest.php

**Checkpoint**: /admin/pricing-tiers is the only feature surface and every role/report/audit path matches the English contract.

---

## Phase 7: Obsolete-Code Removal and Full Verification

**Purpose**: Remove the parallel domain and prove the final tree and CI-equivalent gate.

- [x] T066 Delete ProductSubscription models/enums/factory/observer/policy/service/calculator under app/ and database/factories/
- [x] T067 Delete ProductSubscriptions Filament resource/pages/schemas/tables/relation managers and the customer-side subscription relation manager under app/Filament/Resources/
- [x] T068 Delete or rewrite every subscription behavior test under tests/ so only pricing-tier behavior and explicit removal assertions remain
- [x] T069 Remove obsolete subscription imports/relationships/report/permission/language references across app/, database/, tests/, and lang/
- [x] T070 Add architectural removal assertions for no ProductSubscription runtime class/route in tests/Unit/ArchTest.php, tests/Feature/ProductSubscriptionRemovalTest.php, and tests/Unit/AdminModuleRegistryTest.php
- [x] T071 Fix the three verified Rector findings in app/Filament/Resources/InventoryOperations/Pages/ViewInventoryOperation.php, tests/Feature/Inventory/ProductMediaTest.php, and tests/Feature/Inventory/ProductVariantMediaFallbackTest.php
- [x] T072 Run targeted customer/tier/resolver/floor/report/authorization/audit/navigation suites from specs/013-crm-customers-subscriptions/quickstart.md
- [x] T073 Run php artisan migrate:fresh --seed on a disposable test database and verify routes/schema with Artisan and Laravel Boost
- [x] T074 Run vendor/bin/pint --dirty --format agent, vendor/bin/phpstan analyse --memory-limit=1G, type coverage, and git diff --check without adding baseline entries
- [x] T075 Run the complete composer test gate and require exit code 0 with 100% type coverage and 100% serial code coverage
- [x] T076 Validate /admin/pricing-tiers in the browser for System Admin and through the automated authorization matrix for each fixed role; confirm /admin/product-subscriptions does not resolve
- [x] T077 Record exact successful commands/counts/coverage/routes/schema/browser outcomes in specs/013-crm-customers-subscriptions/quickstart.md
- [x] T078 Re-run Spec-Kit analysis, docs guard, clean-code guard, and test guard; resolve all blocking findings before delivery

---

## Dependencies and Implementation Strategy

1. Phase 1 documentation is the gate for every code task.
2. Phase 2 schema/types/permissions blocks all stories.
3. US1 can proceed independently after Phase 2.
4. US2 blocks US3 and the tier UI portions of US4.
5. US3 blocks preview/floor/report completion in US4.
6. Phase 7 starts only after all selected user stories pass their focused checkpoints.

Suggested MVP: Phases 1–4, demonstrating customer maintenance and product-scoped tier lifecycle/relationships before resolver/UI integration.

Parallel work is limited to tasks marked [P]; tasks touching the same resource/service/report files remain sequential.

## Completion Rule

Do not mark T075–T078 complete or claim delivery while composer test is nonzero, coverage is incomplete, ProductSubscription runtime symbols/routes remain, CRM exposes payment terms, or documentation still describes the superseded standalone design.
