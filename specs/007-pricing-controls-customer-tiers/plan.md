# Implementation Plan: Pricing Controls and Customer Tiers

**Branch**: `feature/filament-inventory-dashboard` | **Date**: 2026-07-25 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/007-pricing-controls-customer-tiers/spec.md`

## Summary

Separate price resolution from mutation by retaining `PriceResolver` as a read-only resolver and introducing `ProductPricingService` as the only writer for variant pricing, tiers, assignments, and overrides. Filament actions split catalog data from pricing data, call the service in transactions, and provide dedicated assignment plus read-only history resources. Existing permissions, audit records, and schema are reused.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13.21, Filament 5.7, Livewire 4.3, Spatie Laravel Permission

**Storage**: MySQL using the existing product, tier, history, override, and audit tables

**Testing**: Pest 4 feature and Filament Livewire tests

**Target Platform**: Server-rendered System Administrator web panel

**Project Type**: Laravel modular monolith

**Performance Goals**: Price resolution and mutation remain constant-query operations; administrative lists paginate and eager-load displayed relations

**Constraints**: No public API, dependency, localization, or RTL changes; no direct price writers outside the pricing service; no new PHPStan baseline entries

**Scale/Scope**: Product variants and customer assignments in the existing single-company inventory module

## Constitution Check

### Pre-Design Gate

- **Specification-first**: PASS. The approved specification and complete data design precede code changes.
- **Modular monolith**: PASS. Business rules live in one inventory-domain service; Filament actions remain adapters.
- **Inventory integrity**: PASS. Pricing mutations use transactions and row locks, with atomic history and audit writes.
- **Unified access**: PASS. Existing inventory permissions and Spatie authorization are reused.
- **Queues**: PASS. All Phase 007 operations are short administrative transactions and do not require background jobs.
- **Engineering discipline**: PASS. Tests cover rules, authorization, idempotent no-op behavior, and UI persistence.
- **Scope boundary**: PASS. The approved Inventory Filament exception applies; public routes remain unchanged.

### Post-Design Gate

All gates remain PASS. No constitution exception or complexity justification is required.

## Project Structure

### Documentation

```text
specs/007-pricing-controls-customer-tiers/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── pricing-service.md
│   └── filament-workflows.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code

```text
app/
├── Data/Inventory/
│   ├── CustomerTierAssignmentData.php
│   ├── PriceFloorOverrideData.php
│   ├── PricingTierData.php
│   └── VariantPricingData.php
├── Filament/Resources/
│   ├── CustomerPricingTiers/
│   ├── PriceFloorOverrides/
│   ├── PriceHistories/
│   ├── PricingTiers/
│   └── ProductVariants/
├── Models/
├── Policies/
└── Services/Inventory/
    ├── PriceResolver.php
    └── ProductPricingService.php

tests/Feature/
├── Filament/PricingControlsResourceTest.php
└── ProductPricingServiceTest.php
```

**Structure Decision**: Extend the existing inventory-domain service and Filament resource layout. No new top-level module or dependency is introduced.

## Complexity Tracking

No constitution violations.
