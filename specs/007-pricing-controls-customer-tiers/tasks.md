# Tasks: Pricing Controls and Customer Tiers

**Input**: Design documents from `specs/007-pricing-controls-customer-tiers/`

**Tests**: Required for every behavior and Filament workflow in the approved feature specification.

## Phase 1: Setup

- [x] T001 Verify current pricing schema, policies, resources, and callers against `specs/007-pricing-controls-customer-tiers/data-model.md`
- [x] T002 Confirm Filament 5 action persistence, read-only fields, authorization, and testing APIs using version-specific documentation

## Phase 2: Foundational

- [x] T003 Create service-level regression tests in `tests/Feature/ProductPricingServiceTest.php`
- [x] T004 Refactor `app/Services/Inventory/PriceResolver.php` into read-only price resolution and floor validation
- [x] T005 Implement the pricing mutation boundary in `app/Services/Inventory/ProductPricingService.php`
- [x] T006 Update receiving and import callers in `app/Services/Inventory/InventoryReceivingService.php` and `app/Services/Inventory/CatalogImportService.php`

## Phase 3: User Story 1 - Maintain Audited Variant Prices

**Goal**: Derive base price and atomically record effective manual pricing changes.

**Independent Test**: Variant pricing changes produce a derived base, one history row, and one audit row; no-op saves produce none.

- [x] T007 [US1] Add variant pricing action tests in `tests/Feature/Filament/PricingControlsResourceTest.php`
- [x] T008 [US1] Make base price read-only and gate sensitive fields in `app/Filament/Resources/ProductVariants/ProductVariantResource.php`
- [x] T009 [US1] Route variant create/edit modal persistence through the pricing service in `app/Filament/Resources/ProductVariants/Pages/ManageProductVariants.php` and `app/Filament/Resources/ProductVariants/ProductVariantResource.php`

## Phase 4: User Story 2 - Assign Customer Pricing Tiers

**Goal**: Maintain one active general assignment and at most one active customer-specific tier.

**Independent Test**: Sequential assignments and specific-tier activations leave only the newest applicable records active and preserve price resolution precedence.

- [x] T010 [US2] Add assignment and tier invariant tests in `tests/Feature/ProductPricingServiceTest.php`
- [x] T011 [US2] Route tier create/edit actions through the pricing service in `app/Filament/Resources/PricingTiers/PricingTierResource.php`
- [x] T012 [US2] Create the dedicated assignment resource under `app/Filament/Resources/CustomerPricingTiers/`
- [x] T013 [US2] Add assignment authorization in `app/Policies/CustomerPricingTierPolicy.php`
- [x] T014 [US2] Register customer assignments in `app/Filament/AdminModuleRegistry.php`

## Phase 5: User Story 3 - Approve and Review Below-Floor Prices

**Goal**: Approve documented exceptions and expose immutable histories.

**Independent Test**: Authorized below-floor approval creates immutable override and audit records; viewers can inspect histories while unauthorized users cannot.

- [x] T015 [US3] Add floor-override immutability tests in `tests/Feature/ProductPricingServiceTest.php`
- [x] T016 [US3] Enforce persisted override immutability in `app/Models/PriceFloorOverride.php`
- [x] T017 [US3] Add the floor-approval action to `app/Filament/Resources/ProductVariants/Pages/ManageProductVariants.php`
- [x] T018 [US3] Create read-only price-history and floor-override resources under `app/Filament/Resources/PriceHistories/` and `app/Filament/Resources/PriceFloorOverrides/`
- [x] T019 [US3] Add read-only policies in `app/Policies/PriceHistoryPolicy.php` and `app/Policies/PriceFloorOverridePolicy.php`
- [x] T020 [US3] Register history and override screens in `app/Filament/AdminModuleRegistry.php`

## Phase 6: Polish and Cross-Cutting Concerns

- [x] T021 Add or update English labels in `lang/en/admin.php`
- [x] T022 Run focused Phase 007 tests from `specs/007-pricing-controls-customer-tiers/quickstart.md`
- [x] T023 Run `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyse`
- [ ] T024 Mark completed tasks and commit Phase 007 as focused documentation and implementation commits

## Dependencies

- Setup precedes foundational work.
- Foundational service extraction precedes all three user stories.
- User Story 1 can ship after foundational work.
- User Story 2 depends on the common pricing service but is otherwise independent of User Story 1 UI.
- User Story 3 depends on the common pricing service but is otherwise independent of User Story 2.
- Polish follows all selected stories.

## Parallel Opportunities

- Policy and resource tests can be prepared in parallel after the service contract exists.
- The two read-only resources are structurally independent.
- Registry labels and English labels can be updated after resource class names are fixed.

## Implementation Strategy

Deliver the service extraction and audited derived pricing first, then tier invariants, then immutable exception/history interfaces. Keep every slice green under focused Pest tests before the phase-wide static checks.
