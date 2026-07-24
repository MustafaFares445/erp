# Tasks: Complete Excel Import Workflow

**Input**: Design documents from `specs/008-complete-excel-import-workflow/`

**Tests**: Required for validation, mixed application, idempotency, isolation, authorization, and private reports.

## Phase 1: Setup

- [x] T001 Inspect the existing import, receiving, attribute, receipt, identity, lot, policy, and Filament implementations
- [x] T002 Confirm Laravel 13 queue/transaction and Filament 5 private-file guidance using version-specific documentation

## Phase 2: Foundational

- [ ] T003 Add run and row state enums under `app/Enums/`
- [ ] T004 Add import outcome fields and compatibility updates in an additive migration under `database/migrations/`
- [ ] T005 Update import models and factories for typed states, counters, errors, results, and timestamps
- [ ] T006 Add a typed row-result data object under `app/Data/Inventory/`
- [ ] T007 Add mixed-workbook and workflow-schema tests in `tests/Feature/CatalogImportServiceTest.php`

## Phase 3: User Story 1 - Prepare and Validate a Complete Workbook

**Goal**: Generate dynamic templates and produce non-mutating row validation outcomes.

**Independent Test**: Parse catalog-only, serialized, lot, attribute, and invalid rows without domain writes.

- [ ] T008 [US1] Generate warehouse, quantity, and active attribute columns in `app/Services/Inventory/CatalogImportService.php`
- [ ] T009 [US1] Validate inventory context, quantities, tracking fields, warehouses, suppliers, and dynamic attributes
- [ ] T010 [US1] Implement explicit parse state transitions and ready-with-errors classification
- [ ] T011 [US1] Add parse job terminal failure handling in `app/Jobs/ParseCatalogImport.php`

## Phase 4: User Story 2 - Apply Valid Rows Independently

**Goal**: Apply all valid rows once even when invalid or runtime-failed rows exist.

**Independent Test**: Run and retry a mixed confirmation while isolating a deliberately failed receipt group.

- [ ] T012 [US2] Add the queued `ApplyCatalogImport` job
- [ ] T013 [US2] Replace synchronous confirmation with an atomic queue transition in `CatalogImportService`
- [ ] T014 [US2] Implement idempotent catalog-row transactions and warehouse/supplier receipt-group transactions
- [ ] T015 [US2] Persist runtime failures and recalculate terminal counters/status from row outcomes

## Phase 5: User Story 3 - Receive Identities, Lots, Expiry, and Attributes

**Goal**: Persist missing SRS data through existing models and receiving invariants.

**Independent Test**: Apply serialized and lot rows and verify identity, attribute, receipt, movement, stock, lot, and result identifiers.

- [ ] T016 [US3] Resolve or create attribute values by data type and sync variant assignments
- [ ] T017 [US3] Materialize receipt items and serialized units from import rows
- [ ] T018 [US3] Confirm each receipt through `InventoryReceivingService` and capture affected identifiers
- [ ] T019 [US3] Prove the importer does not write stock, lot, or movement tables directly

## Phase 6: Filament Results and Private Downloads

- [ ] T020 Add queueable confirmation for ready and ready-with-errors runs in `InventoryImportRunResource`
- [ ] T021 Display row runtime outcomes and complete run counters
- [ ] T022 Generate detailed and summary CSV reports on private storage
- [ ] T023 Add authorized detailed and summary download actions
- [ ] T024 Add Filament workflow tests in `tests/Feature/Filament/InventoryImportRunResourceTest.php`

## Phase 7: Polish

- [ ] T025 Update English administrator labels in `lang/en/admin.php`
- [ ] T026 Run the focused quickstart tests
- [ ] T027 Run `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyse`
- [ ] T028 Apply clean-code, test, and documentation guards and resolve findings
- [ ] T029 Mark tasks complete and commit Phase 008 as focused documentation and implementation commits

## Dependencies

- Foundation precedes all user stories.
- Parsing and validation precede queued application.
- Queued application precedes Filament result/download completion.
- Receipt materialization depends on catalog and attribute application.
- Polish follows all stories.

## Implementation Strategy

Deliver the explicit state/data foundation first, then parsing, then idempotent grouped application, then Filament reports. Keep each slice green under the focused Pest file before moving to the next slice.
