# Settings & Shared Catalogs Domain

---
status: canonical
owner: settings
last_verified: 2026-10-03
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

## Configuration Ownership

There is no generic key/value settings store and no screen that creates arbitrary keys. Configuration is one of three things:

| Kind | Where it lives | Examples |
|---|---|---|
| Predefined setting | typed singleton tables (`sales_settings`, `purchase_settings`, `inventory_settings`) and the `BusinessConstraintKey` catalogue with its `business_constraints` overrides | default tax rate, posting accounts, purchase approval threshold, price-floor ceiling |
| Module master data | its own relational table and Filament resource | currencies, payment terms, payment methods, package types, units/categories/brands, SLA and warranty policies |
| Deployment concern | `config/` and environment | Stripe secret key, mail credentials |

Setup screens live in the module that owns them, not in a catch-all group:

- **Inventory**: Catalog Setup, Setup workspace (Package Types, Inventory Settings).
- **Accounting**: Setup workspace (Currencies, Payment Terms, Payment Methods, Tax & Posting Accounts).
- **Purchasing**: Purchasing Settings.
- **Support**: SLA Policies, Warranty Policies.
- **Administration** (the former System group): dashboard users, document and notification templates, notification deliveries and preferences, custom fields.

## Authorization

Module setup uses the owning module's permissions, so a module owner does not need System Admin. The currency catalogue uses `accounting.currency.view/manage` (previously `purchase.setting.manage`). Sales and purchasing settings stay System Admin only: they hold posting accounts, the default tax rate and the approval threshold.

## Sensitive Change Audit

Changes to sensitive columns on the three settings models are written to the activity log (log name `configuration`) with actor, old value and new value, through `AuditsSensitiveSettings`. The hook is on the model, so forms, seeders and commands are all audited. Sensitive columns: sales default tax percent, the six posting accounts, `stripe_enabled`, the purchase approval threshold and currency, and the inventory price-floor override ceiling.
