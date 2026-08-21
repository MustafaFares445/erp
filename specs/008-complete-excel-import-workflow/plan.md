# Implementation Plan: Complete Excel Import Workflow

**Branch**: `feature/filament-inventory-dashboard` | **Date**: 2026-07-25 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/008-complete-excel-import-workflow/spec.md`

## Summary

Extend the existing OpenSpout-based private import with explicit enums, additive run/item outcome fields, dynamic active-attribute columns, queued confirmation, and idempotent row application. Catalog-only rows are applied independently; inventory rows are grouped by warehouse and supplier, materialized as draft receipts, and confirmed exclusively through `InventoryReceivingService`.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13.21, Filament 5.7, Livewire 4.3, OpenSpout 4

**Storage**: MySQL plus the private `local` filesystem disk

**Testing**: Pest 4 feature and Filament Livewire tests with queue and storage fakes

**Target Platform**: Server-rendered System Administrator web panel and Laravel queue workers

**Project Type**: Laravel modular monolith

**Performance Goals**: Stream workbook parsing and apply rows in bounded chunks/groups without loading the workbook or all historical results into memory

**Constraints**: No public API, dependency, localization, or RTL changes; importer cannot write balances, lots, or movements directly; no new PHPStan baseline entries

**Scale/Scope**: Administrative catalog and inventory workbooks using existing product, warehouse, supplier, receiving, and attribute models

## Constitution Check

### Pre-Design Gate

- **Specification-first**: PASS. Persisted state and transitions are designed before code.
- **Modular monolith**: PASS. Import orchestration remains an inventory-domain service; jobs and Filament actions are adapters.
- **Inventory integrity**: PASS. Stock-changing rows use independent transactions and the receiving service.
- **Unified access and private media**: PASS. Existing permissions and private storage are reused.
- **Queues**: PASS. Parsing and applying run in queued jobs.
- **Engineering discipline**: PASS. Mixed-row, idempotency, rollback, authorization, and storage behaviors receive Pest coverage.
- **Scope boundary**: PASS. The approved Inventory Filament exception applies; public contracts remain unchanged.

### Post-Design Gate

All gates remain PASS. The deliberate per-group transactions isolate failures while retaining inventory integrity inside every committed group.

## Project Structure

### Documentation

```text
specs/008-complete-excel-import-workflow/
|-- spec.md
|-- plan.md
|-- research.md
|-- data-model.md
|-- quickstart.md
|-- contracts/
|   |-- import-state-machine.md
|   `-- workbook-contract.md
|-- checklists/requirements.md
`-- tasks.md
```

### Source Code

```text
app/
|-- Data/Inventory/
|-- Enums/
|-- Filament/Resources/InventoryImportRuns/
|-- Jobs/
|-- Models/
`-- Services/Inventory/

database/migrations/
tests/Feature/
```

**Structure Decision**: Extend the existing inventory import service, jobs, models, and resource. Introduce a typed row-result object and focused validation, catalog, application, and report services rather than adding a new top-level module.

## Complexity Tracking

No constitution violations.
