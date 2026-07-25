# Implementation Plan: Complete Inventory Reports and Exports

**Branch**: `feature/filament-inventory-dashboard` | **Date**: 2026-07-25 | **Spec**: [spec.md](spec.md)

## Summary

Replace report-specific query duplication with one typed report service, expand the Filament report area into permission-aware read-only report tabs, and extend the existing queued private Excel pipeline to every SRS report type. Use available quantity for usable valuation and expose damaged stock separately.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13.21, Filament 5.7, Livewire 4.3, OpenSpout

**Storage**: Existing inventory, catalog, pricing, supplier, import, movement, device, export, and audit tables

**Testing**: Pest 4 service, workbook, authorization, widget, and Filament Livewire tests

**Target Platform**: System Administrator panel plus queue worker

**Constraints**: No dependency, public route, API, localization, currency-conversion, or destructive data changes

## Constitution Check

- **Specification-first**: PASS. Query, filter, permission, and export contracts precede implementation.
- **Modular monolith**: PASS. Reporting reads existing domain models through one inventory reporting service.
- **Inventory integrity**: PASS. The phase adds no stock write path.
- **Unified access**: PASS. Report, source, pricing, and export permissions are rechecked at every boundary.
- **Engineering discipline**: PASS. Table/export equivalence, chunking, privacy, and denials are testable.
- **Scope boundary**: PASS. The approved Inventory Filament exception applies.

## Project Structure

```text
app/
|-- Enums/InventoryReportType.php
|-- Filament/Resources/
|   |-- InventoryExports/
|   `-- InventoryReports/
|-- Models/InventoryExport.php
`-- Services/Inventory/
    |-- InventoryExportService.php
    `-- InventoryReportService.php

tests/Feature/
|-- InventoryReportServiceTest.php
|-- InventoryExportServiceTest.php
|-- InventoryReportResourceTest.php
`-- Filament/DashboardPageTest.php
```

**Structure Decision**: Extend the existing reporting and export folders. No new top-level application directory or database table is required.

## Complexity Tracking

One report page switches between typed Eloquent queries. Each query remains model-native, filterable, paginated by Filament, and chunked by the exporter.
