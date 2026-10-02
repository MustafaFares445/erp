# Settings & Shared Catalogs Domain

---
status: canonical
owner: settings
last_verified: 2026-10-02
verified_against: app/Services/Settings, Settings tests, currency/business-constraint resources
---

## Purpose

Settings owns shared configuration that multiple domains consume rather than duplicating business limits/currencies inside each module.

## Main Code Anchors

- `BusinessConstraints.php`
- `BusinessConstraintService.php`
- `ConstraintGuard.php`
- `CurrencyCatalogService.php`
- business-constraint enums/models
- Currency Filament resource and shared settings resources

## Boundary

A setting defines shared configuration; the consuming domain still owns the business action. Settings should not implement Sales, Purchasing, Accounting or Inventory workflows itself.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
