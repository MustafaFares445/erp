# Tasks: Damaged Stock and Missing Alerts

## Phase 1: Setup

- [x] T001 Inspect stock schema, balance writers, alerts, permissions, scheduler, and Filament action conventions
- [x] T002 Confirm Laravel 13 locking/migration/scheduler and Filament 5 action APIs

## Phase 2: Balance Foundation

- [x] T003 Add `damaged_quantity` and alert metadata migrations
- [x] T004 Add balance, alert-type, alert-severity, and movement enums/casts
- [x] T005 Implement locking `InventoryBalanceService` with all invariants
- [x] T006 Refactor receiving, transfers, adjustments, and reservation release to use the balance service
- [x] T007 Test every balance equation, invalid amount, reserved/damaged boundary, and rollback

## Phase 3: Damage Workflow

- [x] T008 Add typed damage input and `InventoryDamageService`
- [x] T009 Implement damage, recovery, and disposal movements/audits
- [x] T010 Implement serialized target validation and status/location transitions
- [x] T011 Add authorized Filament stock actions with impact preview and confirmation
- [x] T012 Display damaged quantity in stock list/detail views
- [x] T013 Test operations, authorization, atomicity, device linkage, and action forms

## Phase 4: Alert Engine

- [x] T014 Extend `InventoryAlertService` with typed activation/resolution and contexts
- [x] T015 Implement out-of-stock versus low-stock exclusivity
- [x] T016 Implement import-error and missing-device-identity synchronization
- [x] T017 Implement shared SKU/Serial/IoT duplicate guard and caller integration
- [x] T018 Add alert-view permission and read-only resource
- [x] T019 Add authorized originating-record links and filters
- [x] T020 Test activation, update, resolution, duplicate attempts, and identity reconciliation

## Phase 5: Scheduled Reconciliation

- [x] T021 Add reconciliation command for stock, expiry, transfers, imports, and identities
- [x] T022 Register one daily schedule
- [x] T023 Test command idempotency and schedule registration

## Phase 6: Polish

- [x] T024 Update administrator labels and registry
- [x] T025 Run focused tests, Pint, and PHPStan
- [x] T026 Apply clean-code, test, security, and documentation guards
- [x] T027 Mark tasks complete and commit Phase 010 in focused slices
