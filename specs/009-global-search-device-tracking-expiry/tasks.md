# Tasks: Global Search, Device Tracking, and Expiry

## Phase 1: Setup

- [x] T001 Inspect catalog resources, policies, device/lot models, and all serialized movement writers
- [x] T002 Confirm Filament 5 global search, view page, repeatable infolist, sorting, filtering, and testing APIs

## Phase 2: Catalog Search

- [x] T003 Add `CountryNameResolver` backed by PHP intl/ICU
- [x] T004 Add authorized view pages and infolists for products and variants
- [x] T005 Add relationship-aware global-search attributes and grouped country-code expansion
- [x] T006 Test every catalog SRS search field and valid result destination

## Phase 3: Serialized Status and Movement Integrity

- [x] T007 Add `SerializedInventoryUnitStatus` and normalize legacy status values
- [x] T008 Cast the device model and replace receiving/import/transfer free-form statuses
- [x] T009 Create one serialized receipt movement per device
- [x] T010 Link serialized adjustment movements and enforce one-unit status/location transitions
- [x] T011 Test receipt, transfer, and adjustment device movement/status rules

## Phase 4: Device Resource and Timeline

- [x] T012 Add device movement/model relationships and `SerializedInventoryTimelineService`
- [x] T013 Add synthetic historical receipt fallback without ledger writes
- [x] T014 Create read-only serialized-unit list/view pages and timeline infolist
- [x] T015 Add device global search and StockView policy
- [x] T016 Test device search, timeline ordering, fallback, authorization, and absence of write actions

## Phase 5: Expiry Lots

- [x] T017 Add derived available quantity, days remaining, and expiry state to `InventoryLot`
- [x] T018 Create nearest-expiry read-only lot list/view pages
- [x] T019 Add warehouse, product, and expiry-state filters
- [x] T020 Add StockView policy and administrator registry entries
- [x] T021 Test ordering, derived states, filters, authorization, and absence of write actions

## Phase 6: Polish

- [x] T022 Update English administrator labels
- [x] T023 Run focused tests, Pint, and PHPStan
- [x] T024 Apply clean-code, test, and documentation guards
- [x] T025 Mark tasks complete and commit Phase 009 in focused slices
