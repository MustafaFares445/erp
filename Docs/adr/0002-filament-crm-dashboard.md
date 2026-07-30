# ADR 0002: Adopt the Existing Filament Dashboard for CRM Customers and Product Subscriptions

**Status**: Accepted

**Date**: 2026-07-30

**Deciders**: Project Owner

**Related**: `specs/013-crm-customers-subscriptions/spec.md`, `Docs/PRD.md`, `Docs/SDD.md`, and the IERP Constitution Product Scope & Boundaries section

## Context

The existing `/admin` Filament panel already provides the canonical Customer,
Pricing Tier, Customer Pricing Tier, Price History, and Price Floor Override
surfaces. The constitution previously approved this dashboard exception only
for Inventory. The CRM Customers and Product Subscriptions feature needs the
same dashboard surface, but it must not create a second customer identity,
pricing engine, audit store, reporting store, customer application, or API.

## Decision

Use the existing `/admin` Filament dashboard for CRM customer management and
product subscription administration. The feature extends the existing Customer
and pricing controls, adds one Product Subscriptions resource, and routes all
subscription mutations through its domain service.

This approval is limited to dashboard operations: customer profile maintenance,
subscription definitions, product/customer assignments, read-only price
preview, fixed-role administration, reporting, and audit review. It excludes a
customer-facing interface, public API, recurring billing, renewals, invoices,
payments, and a general permission editor.

## Consequences

- The constitution permits a CRM-specific Filament dashboard exception and is
  amended from version 1.2.0 to 1.3.0.
- Existing Customer, pricing, floor approval, audit, report, and Spatie
  permission infrastructure remain canonical and are extended in place.
- Subscription price resolution has one precedence chain: customer-specific
  tier, eligible subscription, general tier, then base price.
- Any future Filament dashboard exception for another module still requires its
  own ADR.
