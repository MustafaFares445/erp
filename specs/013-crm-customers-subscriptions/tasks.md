---

description: "Dependency-ordered implementation tasks for CRM Customers and Product Subscriptions"

---

# Tasks: CRM Customers and Product Subscriptions

**Input**: Design documents from `specs/013-crm-customers-subscriptions/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`,
`contracts/dashboard-ui.md`, `contracts/pricing-resolution.md`, `quickstart.md`

**Tests**: Required. Project rules and the feature specification require a Pest
test for every behavior change and a regression test for every bug fix.

**Organization**: Tasks are grouped by user story. Existing Customers, Pricing
Tiers, Customer Pricing Tiers, Price History, Price Floor Overrides, Audit Logs,
reports, products, and permissions are extended in place. Only the missing
subscription domain is created.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can be executed in parallel after its phase prerequisites because it
  changes different files and does not depend on another incomplete task in the
  same phase.
- **[US1]–[US5]**: Maps the task to the corresponding user story in `spec.md`.
- All new Laravel files must be generated with the appropriate
  `php artisan make:* --no-interaction` command before editing.
- Tests in each story phase are written first and confirmed failing for the
  expected reason before implementation begins.

## Phase 1: Setup and Governance Gate

**Purpose**: Revalidate the live repository, synchronize the canonical
documentation, and remove the CRM Filament constitution blocker before
production-code changes.

- [X] T001 Re-run file, route, schema, package, and test discovery and update the verified baseline and any newly implemented reuse targets in `specs/013-crm-customers-subscriptions/research.md`
- [X] T002 Create and approve the CRM dashboard decision record in `Docs/adr/0002-filament-crm-dashboard.md`, covering `/admin`, dashboard-only scope, existing Customer/Pricing reuse, and excluded customer-app/recurring-billing work
- [X] T003 Amend the Filament scope exception and constitution version after ADR approval in `.specify/memory/constitution.md`
- [X] T004 [P] Add customer-subscription scope, fixed roles, visibility semantics, and pricing precedence to `Docs/PRD.md` and `Docs/SDD.md`
- [X] T005 [P] Add only the three subscription tables, floor-override provenance, and eligibility data flow to `Docs/database/ERD.md` and `Docs/database/DFD.md`
- [X] T006 [P] Add the resolver/activation/assignment sequences and delivery/test workstream references to `Docs/diagrams/SEQUENCE_DIAGRAMS.md`, `Docs/IMPLEMENTATION_PLAN.md`, and `Docs/TESTING_STRATEGY.md`
- [X] T007 Search Laravel 13, Filament 5, Eloquent relationship, policy/bulk-action, and Pest 4 documentation with Laravel Boost and record version-specific implementation constraints in `specs/013-crm-customers-subscriptions/research.md`
- [X] T008 Reconcile the canonical documents with `specs/013-crm-customers-subscriptions/spec.md`, `specs/013-crm-customers-subscriptions/plan.md`, and `specs/013-crm-customers-subscriptions/checklists/requirements.md`, then mark the governance gate satisfied

**Checkpoint**: Production-code work may begin only after T002–T008 are
approved and internally consistent.

---

## Phase 2: Foundational Authorization

**Purpose**: Establish the shared CRM permission catalogue and fixed-role
mapping required by every user story without creating another access-control
system.

**Critical**: This phase blocks all user-story phases.

- [X] T009 [P] Write failing permission catalogue and fixed-role mapping tests in `tests/Feature/Filament/CrmPermissionSeederTest.php`
- [X] T010 [P] Write failing enum uniqueness and floor-approval permission tests in `tests/Unit/CrmPermissionTest.php`
- [X] T011 [P] Define the canonical CRM permission values and typed `values()` catalogue in `app/Enums/CrmPermission.php`
- [X] T012 [P] Add the distinct System Admin price-floor approval ability to the existing catalogue in `app/Enums/InventoryPermission.php`
- [X] T013 Implement idempotent System Admin, CRM Manager, Pricing Manager, and Reviewer permission mappings without removing unrelated permissions in `database/seeders/CrmPermissionSeeder.php`
- [X] T014 Register the CRM permission seeder in normal and standalone demo seeding flows in `database/seeders/DatabaseSeeder.php` and `database/seeders/InventoryDemoSeeder.php`
- [X] T015 Add reusable CRM permission checks for model and bulk policy methods in `app/Policies/Concerns/ChecksCrmPermissions.php`
- [X] T016 Run the focused foundational tests in `tests/Feature/Filament/CrmPermissionSeederTest.php` and `tests/Unit/CrmPermissionTest.php`

**Checkpoint**: The fixed roles and permission names are authoritative and
available to policies; no custom role or permission tables exist.

---

## Phase 3: User Story 1 - Maintain Customer Profiles (Priority: P1)

**Goal**: Harden the existing Customer Profile implementation for granular
roles, restoration audit, and bulk authorization without creating a new
customer model, table, resource, or route.

**Independent Test**: Create, search, update, deactivate, soft-delete, and
restore a customer profile without any subscription record; verify duplicate
identity rejection and the role boundaries.

### Tests for User Story 1

- [X] T017 [P] [US1] Extend customer resource tests for fixed roles, duplicate user/code rejection, search/filter behavior, direct URLs, and record/bulk restore denial in `tests/Feature/CustomerProfileResourceTest.php`
- [X] T018 [P] [US1] Write failing create/update/deactivate/delete/restore audit event tests in `tests/Feature/CustomerProfileObserverTest.php`

### Implementation for User Story 1

- [X] T019 [US1] Replace the broad `isAdmin()` checks with CRM view/manage/restore and matching `deleteAny`/`restoreAny` abilities in `app/Policies/CustomerProfilePolicy.php`
- [X] T020 [US1] Add restoration audit handling while preserving the current create/update/deactivate/delete event names in `app/Observers/CustomerProfileObserver.php`
- [X] T021 [US1] Make existing Customer record and bulk delete/restore actions obey the granular policy without changing routes or duplicating screens in `app/Filament/Resources/Customers/Tables/CustomersTable.php`
- [X] T022 [US1] Recheck for a canonical `PaymentTerm` model/table; if present, bind its shared selector in `app/Filament/Resources/Customers/Schemas/CustomerForm.php`, otherwise record the deferred dependency in `specs/013-crm-customers-subscriptions/research.md` and create no CRM payment-term implementation
- [X] T023 [US1] Run and pass the independent customer lifecycle tests in `tests/Feature/CustomerProfileResourceTest.php` and `tests/Feature/CustomerProfileObserverTest.php`

**Checkpoint**: User Story 1 is independently deployable customer hardening;
all existing customer identity and routes remain canonical.

---

## Phase 4: User Story 2 - Define a Product Subscription (Priority: P1)

**Goal**: Add the missing subscription definition, validation, lifecycle, raw
discount calculation, and dashboard CRUD while retaining existing pricing
controls.

**Independent Test**: Create percentage and fixed subscriptions, calculate the
120 → 108 and 120 → 105 examples, reject invalid values/dates/activation,
soft-delete, restore inactive, and preview definition details without assigning
a customer.

### Tests for User Story 2

- [X] T024 [P] [US2] Write failing schema, cast, derived-state, scope, relationship, soft-delete, and uniqueness tests in `tests/Feature/ProductSubscriptionModelTest.php`
- [X] T025 [P] [US2] Write failing scalar lifecycle, validation, activation, transaction, restore-inactive, and audit tests in `tests/Feature/ProductSubscriptionServiceTest.php`
- [X] T026 [P] [US2] Write failing percentage/fixed calculation, rounding, and zero/negative result tests in `tests/Unit/SubscriptionDiscountCalculatorTest.php`
- [X] T027 [P] [US2] Write failing Filament list/create/view/edit/search/filter/trashed and direct-action tests in `tests/Feature/Filament/ProductSubscriptionResourceTest.php`

### Data Model and Domain for User Story 2

- [X] T028 [US2] Create the subscription definition schema, indexes, blameable foreign keys, and soft deletion in `database/migrations/2026_07_29_000001_create_product_subscriptions_table.php`
- [X] T029 [P] [US2] Create the unique subscription-to-product pivot and reverse lookup index in `database/migrations/2026_07_29_000002_create_product_subscription_products_table.php`
- [X] T030 [P] [US2] Create the unique subscription-to-customer-profile pivot and eligibility index for the next story in `database/migrations/2026_07_29_000003_create_customer_product_subscriptions_table.php`
- [X] T031 [P] [US2] Define percentage/fixed discount and public/restricted visibility enums in `app/Enums/ProductSubscriptionDiscountType.php` and `app/Enums/ProductSubscriptionVisibility.php`
- [X] T032 [US2] Implement casts, blameable fields, soft deletion, product/customer relationships, derived status, and validity/expiry scopes in `app/Models/ProductSubscription.php`
- [X] T033 [US2] Add percentage/fixed, public/restricted, inactive/active/scheduled/expired factory states in `database/factories/ProductSubscriptionFactory.php`
- [X] T034 [US2] Implement subscription record and bulk abilities using the fixed CRM permissions in `app/Policies/ProductSubscriptionPolicy.php`
- [X] T035 [US2] Audit create/update/activate/deactivate/delete/restore through the existing logger in `app/Observers/ProductSubscriptionObserver.php`
- [X] T036 [US2] Implement the pure percentage/fixed candidate calculator and two-decimal money rounding in `app/Services/Crm/SubscriptionDiscountCalculator.php`
- [X] T037 [US2] Implement transactional create/update/product-link/activate/deactivate/delete/restore operations, date/discount validation, activation locking, and restore-inactive behavior in `app/Services/Crm/ProductSubscriptionService.php`

### Dashboard for User Story 2

- [X] T038 [US2] Create the single CRM Product Subscription resource and pages in `app/Filament/Resources/ProductSubscriptions/ProductSubscriptionResource.php`, `app/Filament/Resources/ProductSubscriptions/Pages/ListProductSubscriptions.php`, `app/Filament/Resources/ProductSubscriptions/Pages/CreateProductSubscription.php`, `app/Filament/Resources/ProductSubscriptions/Pages/ViewProductSubscription.php`, and `app/Filament/Resources/ProductSubscriptions/Pages/EditProductSubscription.php`
- [X] T039 [US2] Implement the unique name, discount, visibility, validity, and inactive-by-default form contract in `app/Filament/Resources/ProductSubscriptions/Schemas/ProductSubscriptionForm.php`
- [X] T040 [US2] Implement searchable columns, derived status, validity/visibility/trashed filters, eager counts, and pagination in `app/Filament/Resources/ProductSubscriptions/Tables/ProductSubscriptionsTable.php`
- [X] T041 [US2] Route create/edit/list/view lifecycle mutations through `ProductSubscriptionService` in `app/Filament/Resources/ProductSubscriptions/Pages/CreateProductSubscription.php`, `app/Filament/Resources/ProductSubscriptions/Pages/EditProductSubscription.php`, `app/Filament/Resources/ProductSubscriptions/Pages/ListProductSubscriptions.php`, and `app/Filament/Resources/ProductSubscriptions/Pages/ViewProductSubscription.php`, then pass `tests/Feature/Filament/ProductSubscriptionResourceTest.php`

**Checkpoint**: User Story 2 provides one auditable subscription definition
resource; it does not introduce customer entitlement or alter the price
resolver yet.

---

## Phase 5: User Story 3 - Link Products and Customers (Priority: P1)

**Goal**: Manage unique product links and active-customer assignments
transactionally and expose them through explicitly authorized relationship
managers.

**Independent Test**: Attach several products/customers, reject duplicate and
inactive targets, detach one link without affecting others, retain an
assignment when a customer is deactivated, and verify audit history.

### Tests for User Story 3

- [ ] T042 [P] [US3] Write failing transactional link/assignment, duplicate-race, inactive-customer, detach-isolation, and relationship audit tests in `tests/Feature/ProductSubscriptionRelationshipServiceTest.php`
- [ ] T043 [P] [US3] Write failing attach/detach/search/bulk authorization and bounded-option-query tests in `tests/Feature/Filament/ProductSubscriptionRelationManagerTest.php`

### Implementation for User Story 3

- [ ] T044 [P] [US3] Add subscription relationships and active-entitlement helpers to the existing customer and product models in `app/Models/CustomerProfile.php` and `app/Models/Product.php`
- [ ] T045 [US3] Extend transactional product synchronization and implement customer assign/unassign, unique-race handling, inactive/deleted-customer rejection, and relationship audit diffs in `app/Services/Crm/ProductSubscriptionService.php`
- [ ] T046 [P] [US3] Implement searchable attach/detach and explicit record/bulk authorization for active products in `app/Filament/Resources/ProductSubscriptions/RelationManagers/ProductsRelationManager.php`
- [ ] T047 [P] [US3] Implement searchable attach/detach and explicit record/bulk authorization for active customer profiles in `app/Filament/Resources/ProductSubscriptions/RelationManagers/CustomersRelationManager.php`
- [ ] T048 [P] [US3] Add the existing Customer resource's read/manage subscription relationship manager without adding another customer page in `app/Filament/Resources/Customers/RelationManagers/ProductSubscriptionsRelationManager.php` and `app/Filament/Resources/Customers/CustomerResource.php`
- [ ] T049 [US3] Recheck activation under lock so restricted subscriptions require at least one active assignment and all subscriptions require a linked active product in `app/Services/Crm/ProductSubscriptionService.php`
- [ ] T050 [US3] Prevent full in-memory product/customer option loading and verify relationship query counts in `tests/Feature/Filament/ProductSubscriptionRelationManagerTest.php`
- [ ] T051 [US3] Run and pass the independent link/assignment tests in `tests/Feature/ProductSubscriptionRelationshipServiceTest.php` and `tests/Feature/Filament/ProductSubscriptionRelationManagerTest.php`

**Checkpoint**: User Story 3 supplies current entitlement links without
duplicating products/customers or deleting history when a customer becomes
inactive.

---

## Phase 6: User Story 4 - Resolve the Effective Customer Price (Priority: P1)

**Goal**: Extend the existing resolver with deterministic subscription
precedence, complete provenance, the current price floor, and a read-only
dashboard preview.

**Independent Test**: Evaluate every specific-tier/subscription/general-tier/
base combination, multiple subscription tie-breaking, eligibility exclusions,
floor approval, and no-subscription regression behavior.

### Tests for User Story 4

- [ ] T052 [P] [US4] Write failing precedence, eligibility, no-stacking, lowest-price, tie-ID, provenance, and existing tier/base regression tests in `tests/Feature/SubscriptionPriceResolverTest.php`
- [ ] T053 [P] [US4] Extend floor approval tests for the distinct permission, required reason, subscription provenance, and audit payload in `tests/Feature/ProductPricingServiceTest.php`
- [ ] T054 [P] [US4] Write failing read-only preview candidate/winner/floor-warning and no-mutation tests in `tests/Feature/Filament/ProductSubscriptionPricePreviewTest.php`

### Pricing and Floor Implementation for User Story 4

- [ ] T055 [P] [US4] Add nullable indexed subscription provenance to the existing floor approval table in `database/migrations/2026_07_29_000004_add_product_subscription_id_to_price_floor_overrides_table.php`
- [ ] T056 [P] [US4] Define customer-specific-tier, subscription, general-tier, and base result sources in `app/Enums/ResolvedPriceSource.php`
- [ ] T057 [US4] Extend the existing value object additively with base/source/discount/subscription/floor fields while preserving `amount` and `pricingTier` in `app/Data/Inventory/ResolvedPrice.php`
- [ ] T058 [US4] Insert one eager-loaded eligible subscription candidate between specific and general tiers, select lowest amount then lowest subscription ID, and preserve existing fallbacks in `app/Services/Inventory/PriceResolver.php`
- [ ] T059 [US4] Add the optional subscription field and relationships to the current approval data/model contract in `app/Data/Inventory/PriceFloorOverrideData.php` and `app/Models/PriceFloorOverride.php`
- [ ] T060 [US4] Require the explicit System Admin floor-approval permission and persist/audit subscription provenance through the existing writer in `app/Services/Inventory/ProductPricingService.php`
- [ ] T061 [US4] Display and filter optional subscription provenance without changing read-only semantics in `app/Filament/Resources/PriceFloorOverrides/Schemas/PriceFloorOverrideInfolist.php` and `app/Filament/Resources/PriceFloorOverrides/Tables/PriceFloorOverridesTable.php`
- [ ] T062 [US4] Implement the read-only customer/product/variant preview action and candidate breakdown in `app/Filament/Resources/ProductSubscriptions/Pages/ViewProductSubscription.php`
- [ ] T063 [US4] Verify there is still no confirmed-document resolver consumer; record the integration boundary in `specs/013-crm-customers-subscriptions/research.md` and add a resolver side-effect/non-price-history regression in `tests/Feature/PricingServiceTest.php`
- [ ] T064 [US4] Assert subscription edits create audit entries but never `price_histories` rows in `tests/Feature/ProductSubscriptionServiceTest.php`
- [ ] T065 [US4] Run and pass all resolver, preview, floor, and existing pricing regressions in `tests/Feature/SubscriptionPriceResolverTest.php`, `tests/Feature/PricingServiceTest.php`, `tests/Feature/ProductPricingServiceTest.php`, and `tests/Feature/Filament/ProductSubscriptionPricePreviewTest.php`

**Checkpoint**: User Story 4 produces exactly one auditable source, retains
current tier behavior when no subscription applies, and never silently bypasses
the existing floor.

---

## Phase 7: User Story 5 - Govern and Review the Feature (Priority: P2)

**Goal**: Complete the fixed-role matrix, role assignment, reports, generic
audit review, Arabic/RTL behavior, and query-safe navigation.

**Independent Test**: Exercise each role through direct pages, record actions,
relationship/bulk actions, role assignment, floor approval, reports, audit
filters, and Arabic dashboard rendering.

### Tests for User Story 5

- [ ] T066 [P] [US5] Write failing four-role page/record/relationship/bulk/restore/floor/role-assignment matrix tests in `tests/Feature/Filament/CrmAuthorizationTest.php`
- [ ] T067 [P] [US5] Write failing subscription definition/assignment/eligibility/expiry report query and permission tests in `tests/Feature/ProductSubscriptionReportTest.php`
- [ ] T068 [P] [US5] Write failing read-only audit list/view/filter/direct-access tests in `tests/Feature/Filament/AuditLogResourceTest.php`
- [ ] T069 [P] [US5] Write failing fixed dashboard-role assignment scope and permission tests in `tests/Feature/Filament/DashboardUserRoleResourceTest.php`
- [ ] T070 [P] [US5] Write failing English/Arabic labels, validation messages, navigation, status, and RTL tests in `tests/Feature/CrmLocalisationTest.php`

### Authorization and Role Administration for User Story 5

- [ ] T071 [US5] Finalize customer/subscription policy methods and explicit relation/bulk authorization against the fixed matrix in `app/Policies/CustomerProfilePolicy.php`, `app/Policies/ProductSubscriptionPolicy.php`, `app/Filament/Resources/ProductSubscriptions/RelationManagers/ProductsRelationManager.php`, `app/Filament/Resources/ProductSubscriptions/RelationManagers/CustomersRelationManager.php`, and `app/Filament/Resources/Customers/RelationManagers/ProductSubscriptionsRelationManager.php`
- [ ] T072 [US5] Implement transactional fixed-role assignment for dashboard-channel users without exposing arbitrary permissions in `app/Services/Identity/DashboardRoleAssignmentService.php`
- [ ] T073 [US5] Create the scoped dashboard-user role assignment resource in `app/Filament/Resources/DashboardUsers/DashboardUserResource.php`, `app/Filament/Resources/DashboardUsers/Pages/ListDashboardUsers.php`, `app/Filament/Resources/DashboardUsers/Pages/EditDashboardUser.php`, `app/Filament/Resources/DashboardUsers/Schemas/DashboardUserRoleForm.php`, and `app/Filament/Resources/DashboardUsers/Tables/DashboardUsersTable.php`

### Reports and Audit for User Story 5

- [ ] T074 [P] [US5] Add subscription definition, assignment, and eligibility/expiry types with correct source permissions to `app/Enums/InventoryReportType.php`
- [ ] T075 [US5] Add permission-gated, eager-loaded subscription report queries and filters to the existing framework in `app/Services/Inventory/InventoryReportService.php`
- [ ] T076 [US5] Expose the new report types through the current filter/table/format flow in `app/Filament/Resources/InventoryReports/Tables/InventoryReportFilters.php`, `app/Filament/Resources/InventoryReports/Tables/InventoryReportsTable.php`, and `app/Services/Inventory/InventoryReportFormatter.php`
- [ ] T077 [P] [US5] Create a CRM-audit-view-only policy over the existing audit model in `app/Policies/AuditLogPolicy.php`
- [ ] T078 [US5] Create one reusable read-only Audit Log resource with entity/action/actor/date filters in `app/Filament/Resources/AuditLogs/AuditLogResource.php`, `app/Filament/Resources/AuditLogs/Pages/ListAuditLogs.php`, `app/Filament/Resources/AuditLogs/Pages/ViewAuditLog.php`, `app/Filament/Resources/AuditLogs/Schemas/AuditLogInfolist.php`, and `app/Filament/Resources/AuditLogs/Tables/AuditLogsTable.php`

### Navigation and Localization for User Story 5

- [ ] T079 [US5] Register Product Subscriptions, Dashboard User Roles, and the generic Audit Log with existing navigation/placeholder/link conventions in `app/Filament/AdminModuleRegistry.php`
- [ ] T080 [P] [US5] Add English and Arabic CRM subscription, role, report, audit, action, status, and error keys in `lang/en/admin.php` and `lang/ar/admin.php`
- [ ] T081 [US5] Run and pass the complete role/report/audit/localization suite in `tests/Feature/Filament/CrmAuthorizationTest.php`, `tests/Feature/ProductSubscriptionReportTest.php`, `tests/Feature/Filament/AuditLogResourceTest.php`, `tests/Feature/Filament/DashboardUserRoleResourceTest.php`, and `tests/Feature/CrmLocalisationTest.php`

**Checkpoint**: Every route and action follows the fixed matrix, reports and
audit reuse current storage/frameworks, and the feature is usable in Arabic
RTL.

---

## Phase 8: Polish and Cross-Cutting Verification

**Purpose**: Validate performance, documentation consistency, code quality, and
full non-regression without touching unrelated worktree changes.

- [ ] T082 [P] Add subscription list, relation-manager, preview, and report query-count regression coverage in `tests/Feature/Filament/ProductSubscriptionQueryCountTest.php`
- [ ] T083 Re-run Spec-Kit consistency review and update resolved notes/checklists in `specs/013-crm-customers-subscriptions/spec.md`, `specs/013-crm-customers-subscriptions/plan.md`, `specs/013-crm-customers-subscriptions/tasks.md`, and `specs/013-crm-customers-subscriptions/checklists/requirements.md`
- [ ] T084 Execute every manual and automated scenario and record verified outcomes in `specs/013-crm-customers-subscriptions/quickstart.md`
- [ ] T085 Format all changed PHP with `vendor/bin/pint --dirty --format agent` and review the resulting feature-owned files listed in `specs/013-crm-customers-subscriptions/plan.md`
- [ ] T086 Run focused and full PHPStan analysis, add no baseline entries, and remove only resolved touched-file entries from `phpstan-baseline.neon`
- [ ] T087 Run the CI-equivalent `composer test` gate and verify the baseline customer/pricing suites referenced in `specs/013-crm-customers-subscriptions/quickstart.md`
- [ ] T088 Review `git diff`, routes, schema, and test output against the anti-duplication/non-deliverables sections and record the final verification summary in `specs/013-crm-customers-subscriptions/quickstart.md`

---

## Dependencies and Execution Order

### Phase Dependencies

- **Phase 1 — Setup/Governance**: Starts immediately; production code is blocked
  until the ADR and canonical documents are approved.
- **Phase 2 — Foundational Authorization**: Depends on Phase 1 and blocks every
  user story.
- **US1 — Customer Profiles**: Depends on Phase 2; otherwise independent.
- **US2 — Subscription Definition**: Depends on Phase 2; otherwise independent
  from US1 because it reuses the already-existing Product catalog.
- **US3 — Product/Customer Links**: Depends on US2's subscription model, pivots,
  policy, and service. It reuses the existing Customer Profile from US1/current
  code but does not require new customer identity.
- **US4 — Pricing Resolution**: Depends on US2 and US3 eligibility data. Its
  calculator and floor migration tests may begin after US2.
- **US5 — Governance/Review**: Depends on US1–US4 so the complete role matrix,
  reports, audit filters, and localization can cover all actions.
- **Phase 8 — Polish**: Depends on all selected user stories.

### User Story Dependency Graph

```text
Setup/Governance
    └── Foundational Permissions
        ├── US1 Customer Profiles
        └── US2 Subscription Definition
            └── US3 Product/Customer Links
                └── US4 Effective Price Resolution
                    └── US5 Governance and Review
                        └── Polish and Full Gate
```

### Within Each User Story

1. Write the story's Pest tests.
2. Run them and confirm they fail for the missing behavior, not boot/setup
   errors.
3. Create/extend models and data contracts.
4. Implement service/domain behavior.
5. Implement Filament/resource integration.
6. Run the story tests plus affected existing regressions.
7. Do not proceed past the checkpoint while failures remain.

## Parallel Opportunities

### Setup

- T004, T005, and T006 edit separate canonical documentation surfaces after the
  ADR decision is fixed.

### User Story 1

- T017 and T018 cover separate customer resource and observer test files.

### User Story 2

- T024–T027 can be written in parallel.
- T029, T030, and T031 can be prepared in parallel after T028 fixes the parent
  table contract.
- Resource page scaffolding may begin after T032/T034 while service behavior is
  completed in T037.

### User Story 3

- T042 and T043 can be written in parallel.
- T046, T047, and T048 modify separate relation manager/resource files after
  T044–T045 stabilize relationships and service methods.

### User Story 4

- T052–T054 can be written in parallel.
- T055 and T056 modify independent migration/enum files.
- Floor resource display work in T061 can proceed alongside preview work in
  T062 after the underlying data/service contracts are stable.

### User Story 5

- T066–T070 can be written in parallel.
- The role-assignment, report, audit, and translation streams use separate files
  and may proceed in parallel after their tests and shared policies are fixed.

## Parallel Execution Examples

### User Story 1

```text
Task T017: Customer resource and authorization tests
Task T018: Customer audit observer tests
```

### User Story 2

```text
Task T024: ProductSubscription model tests
Task T025: ProductSubscription service tests
Task T026: Discount calculator tests
Task T027: Filament resource tests
```

### User Story 3

```text
Task T046: Products relation manager
Task T047: Customers relation manager
Task T048: Existing Customer subscription relation manager
```

### User Story 4

```text
Task T055: Floor-override provenance migration
Task T056: Resolved price source enum
Task T061: Floor-override display/filter extension
Task T062: Subscription price preview action
```

### User Story 5

```text
Task T072-T073: Fixed dashboard-role assignment stream
Task T074-T076: Existing report framework extension stream
Task T077-T078: Existing audit storage review UI stream
Task T080: English/Arabic localization stream
```

## Implementation Strategy

### Smallest Safe Increment

1. Complete Setup/Governance.
2. Complete Foundational Authorization.
3. Complete US1.
4. Stop and validate that existing Customer behavior is preserved under the new
   fixed permissions.

US1 is independently deployable hardening, but it does not deliver the new
subscription capability.

### Meaningful Feature MVP

1. Complete Setup/Governance and Foundational Authorization.
2. Deliver US1 customer hardening.
3. Deliver US2 subscription definition.
4. Stop and demonstrate definition, validation, lifecycle, restore, and audit.

US3 and US4 are required before subscriptions grant customer pricing
entitlement. Do not describe the MVP as price-integrated until both are
complete.

### Incremental Delivery

1. US1: Existing customer lifecycle hardened.
2. US2: Subscription definitions available.
3. US3: Product/customer entitlement links available.
4. US4: Deterministic price resolution and floor integration available.
5. US5: Complete role administration, reports, audit review, and Arabic RTL.
6. Phase 8: Full release gate.

## Notes

- Re-run the anti-duplication checkpoint before every phase; if a planned file
  now exists, extend it rather than creating a sibling implementation.
- Do not add a Payment Terms table/model unless the canonical shared feature is
  present and explicitly in scope.
- Do not add customer/mobile/website/API subscription interfaces.
- Do not add recurring billing, renewal, invoice, payment, or tax behavior.
- Do not add subscription-specific price history, floor approval, audit, report,
  export, customer, product, role, or permission storage.
- Preserve `PriceResolver::resolve(ProductVariant, ?User)`,
  `ResolvedPrice::amount`, and `ResolvedPrice::pricingTier` compatibility.
- Existing unrelated worktree modifications belong to the user and must remain
  untouched.
