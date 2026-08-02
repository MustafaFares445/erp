# ADR 0002: Adopt the Existing Filament Dashboard for CRM Customers and Pricing Tiers

**Status**: Accepted

**Date**: 2026-08-02

**Deciders**: Project Owner

**Related**: `specs/013-crm-customers-subscriptions/spec.md`, `Docs/PRD.md`, `Docs/SDD.md`, and the IERP Constitution Product Scope & Boundaries section

## Context

The existing `/admin` Filament panel already provides the canonical Customer,
Pricing Tiers, Price History, and Price Floor Overrides surfaces. An unfinished
Product Subscriptions resource introduced a second pricing-management surface
and a parallel discount domain. The project owner has directed that this
concept be removed and that product-scoped discount behavior be incorporated
into Pricing Tiers instead.

The CRM work must not create a second customer identity, pricing engine, audit
store, reporting store, customer application, public API, or payment-term
workflow.

## Decision

Use the existing `/admin` Filament dashboard for CRM customer management and
pricing-tier administration. `/admin/pricing-tiers` is the only pricing-tier
management surface. Product-scoped discounts are represented as a pricing-tier
type and use the existing customer-tier assignment infrastructure. The
standalone `/admin/product-subscriptions` resource and the
`ProductSubscription` runtime domain are not part of the target architecture.

All pricing-tier mutations are routed through the pricing domain service, and
all price decisions use the existing resolver. Resolution is deterministic and
non-stacking: customer-specific tier, eligible product-scoped tier, assigned
general tier, then product base price. Equal product-scoped results are broken
by the lowest pricing-tier identifier. Price-floor approvals retain the winning
pricing-tier provenance.

This approval is limited to dashboard operations: customer profile maintenance,
pricing-tier definitions, product/customer assignments, read-only price
preview, fixed-role administration, reporting, and audit review. The feature is
English-only for this phase. Customer payment terms are outside this CRM module
and remain owned by the future Sales and Accounting payment-term workflow.

It excludes a customer-facing interface, public API, recurring billing,
renewals, invoices, payments, tax behavior, and a general permission editor.

## Consequences

- The constitution continues to permit the narrow CRM-specific Filament
  dashboard exception introduced in version 1.3.0.
- Existing Customer, Pricing Tiers, price-floor approval, audit, report, and
  Spatie permission infrastructure remain canonical and are extended in place.
- Product-scoped pricing does not introduce subscription routes, models,
  services, permissions, translations, reports, or database ownership.
- The approved implementation baseline is a fresh database; subscription-era
  creation/provenance migrations are removed and no legacy conversion path is
  implied by this ADR.
- Any future Filament dashboard exception for another module still requires its
  own ADR.
