# Tasks: Global Search, Device Tracking, and Expiry

## Phase 1: Setup

- [x] T001 Inspect catalog resources, policies, device/lot models, and all serialized movement writers
- [x] T002 Confirm Filament 5 global search, view page, repeatable infolist, sorting, filtering, and testing APIs

## Phase 2: Catalog Search

- [ ] T003 Add `CountryNameResolver` backed by PHP intl/ICU
- [ ] T004 Add authorized view pages and infolists for products and variants
- [ ] T005 Add relationship-aware global-search attributes and grouped country-code expansion
- [ ] T006 Test every catalog SRS search field and valid result destination

## Phase 3: Serialized Status and Movement Integrity

- [ ] T007 Add `SerializedInventoryUnitStatus` and normalize legacy status values
- [ ] T008 Cast the device model and replace receiving/import/transfer free-form statuses
- [ ] T009 Create one serialized receipt movement per device
- [ ] T010 Link serialized adjustment movements and enforce one-unit status/location transitions
- [ ] T011 Test receipt, transfer, and adjustment device movement/status rules

## Phase 4: Device Resource and Timeline

- [ ] T012 Add device movement/model relationships and `SerializedInventoryTimelineService`
- [ ] T013 Add synthetic historical receipt fallback without ledger writes
- [ ] T014 Create read-only serialized-unit list/view pages and timeline infolist
- [ ] T015 Add device global search and StockView policy
- [ ] T016 Test device search, timeline ordering, fallback, authorization, and absence of write actions

## Phase 5: Expiry Lots

- [ ] T017 Add derived available quantity, days remaining, and expiry state to `InventoryLot`
- [ ] T018 Create nearest-expiry read-only lot list/view pages
- [ ] T019 Add warehouse, product, and expiry-state filters
- [ ] T020 Add StockView policy and administrator registry entries
- [ ] T021 Test ordering, derived states, filters, authorization, and absence of write actions

## Phase 6: Polish

- [ ] T022 Update English administrator labels
- [ ] T023 Run focused tests, Pint, and PHPStan
- [ ] T024 Apply clean-code, test, and documentation guards
- [ ] T025 Mark tasks complete and commit Phase 009 in focused slices
