# Implementation Plan: Damaged Stock and Missing Alerts

**Branch**: `feature/filament-inventory-dashboard` | **Date**: 2026-07-25 | **Spec**: [spec.md](spec.md)

## Summary

Add a damaged balance dimension and route every balance mutation through one locking service. Add an atomic damage workflow, typed alerts with contextual read-only views, duplicate and missing-identity detection, and a daily reconciliation command.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13.21, Filament 5.7, Livewire 4.3

**Storage**: MySQL inventory stocks, movements, devices, imports, lots, transfers, alerts, and audit logs

**Testing**: Pest 4 service, command, migration, and Filament Livewire tests

**Target Platform**: System Administrator panel plus Laravel scheduler/worker runtime

**Constraints**: No dependency, public route, API, localization, or RTL changes; movements and audit history are append-only

## Constitution Check

- **Specification-first**: PASS. Balance, damage, and alert contracts precede implementation.
- **Modular monolith**: PASS. Balance, damage, identity, and alert responsibilities remain focused services.
- **Inventory integrity**: PASS. Locks and invariant validation occur inside existing service transactions.
- **Unified access**: PASS. Existing stock/adjustment permissions plus `AlertView` govern the panel.
- **Engineering discipline**: PASS. Every transition, denial, rollback, and reconciliation path is testable.
- **Scope boundary**: PASS. The approved Inventory Filament exception applies.

## Project Structure

```text
app/
|-- Console/Commands/ReconcileInventoryAlerts.php
|-- Data/Inventory/StockDamageData.php
|-- Enums/
|   |-- InventoryAlertSeverity.php
|   |-- InventoryAlertType.php
|   `-- MovementType.php
|-- Filament/Resources/
|   |-- InventoryAlerts/
|   `-- StockLevels/
|-- Models/
|-- Policies/
`-- Services/Inventory/
    |-- InventoryAlertService.php
    |-- InventoryBalanceService.php
    |-- InventoryDamageService.php
    `-- InventoryIdentityGuard.php

tests/Feature/
|-- Inventory/InventoryBalanceServiceTest.php
|-- Inventory/InventoryDamageServiceTest.php
|-- Inventory/InventoryAlertServiceTest.php
`-- Filament/InventoryAlertResourceTest.php
```

**Structure Decision**: Extend existing inventory folders; do not introduce a new top-level module.

## Complexity Tracking

No constitution violations.
