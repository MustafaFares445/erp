# Implementation Plan: Global Search, Device Tracking, and Expiry

**Branch**: `feature/filament-inventory-dashboard` | **Date**: 2026-07-25 | **Spec**: [spec.md](spec.md)

## Summary

Add view destinations and relationship-aware global search to products and variants, with ICU-backed localized country-code expansion. Add read-only device and lot resources, a typed serialized-status enum, serialized movement association in receiving/transfer/adjustment services, and a timeline service that derives only missing historical receipt events.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13.21, Filament 5.7, Livewire 4.3, PHP intl/ICU

**Storage**: MySQL using existing catalog, receipt, device, lot, stock, transfer, adjustment, and movement tables

**Testing**: Pest 4 feature and Filament Livewire tests

**Target Platform**: Server-rendered System Administrator panel

**Constraints**: Read-only resources; no public API, Composer dependency, localization, or RTL changes; historical ledger rows are immutable

## Constitution Check

- **Specification-first**: PASS. Search, status, timeline, and lot-state contracts precede implementation.
- **Modular monolith**: PASS. Country and timeline rules live in focused services; resources remain adapters.
- **Inventory integrity**: PASS. Movement writers remain in transactional inventory services.
- **Unified access**: PASS. Existing `CatalogView` and `StockView` permissions are reused.
- **Engineering discipline**: PASS. Search fields, status transitions, timeline fallback, ordering, filters, and denial paths receive tests.
- **Scope boundary**: PASS. The approved Inventory Filament exception applies.

## Project Structure

```text
app/
|-- Enums/SerializedInventoryUnitStatus.php
|-- Filament/Resources/
|   |-- InventoryLots/
|   |-- Products/
|   |-- ProductVariants/
|   `-- SerializedInventoryUnits/
|-- Models/
|-- Policies/
`-- Services/Inventory/
    |-- CountryNameResolver.php
    `-- SerializedInventoryTimelineService.php

tests/Feature/
|-- CatalogGlobalSearchTest.php
|-- SerializedInventoryTrackingTest.php
`-- Filament/InventoryLotResourceTest.php
```

**Structure Decision**: Extend existing inventory and Filament folders with two read-only resources and two focused lookup services.

## Complexity Tracking

No constitution violations.
