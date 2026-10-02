# IERP Product Overview

---
status: canonical
owner: product
last_verified: 2026-10-02
verified_against: current Laravel/Filament implementation, domain services, tests and canonical domain docs
---

## What IERP Is

IERP is an integrated ERP application implemented as a Laravel/Filament modular monolith.

The current administration product covers:

- customer/CRM management;
- product catalog and pricing;
- inventory/warehouses;
- purchasing/suppliers;
- sales/quotations/orders/invoices/credits;
- customer and supplier payments/accounting;
- logistics/shipments;
- employee field-sales operations;
- support/maintenance/warranty;
- notifications;
- operational/financial reporting and audit visibility.

## Current User Channels

### Administration Dashboard

The implemented primary UI is the Filament 5 admin dashboard.

### Customer App

Customer App V1 has an approved product/design specification and current Pen source.

The backend contains many reusable domain capabilities, but the current runtime does not expose a complete customer `api/*` route surface.

See [Customer App V1](../apps/customer/README.md).

### Employee App

Employee Sales App V1 has an approved product/design specification and current Pen source.

Employee domain capabilities are substantially implemented, but the current runtime does not expose the complete employee mobile API.

See [Employee Sales App V1](../apps/employee/README.md).

## Core Business Model

IERP intentionally separates ownership of business facts:

- CRM owns customer/lead/campaign facts.
- Catalog owns product/pricing facts.
- Inventory owns physical stock.
- Purchasing owns supplier commercial commitments.
- Sales owns customer commercial documents.
- Logistics owns shipment/fulfillment execution.
- Payments owns collection/provider/allocation behavior.
- Accounting owns ledger truth.
- Employees owns employee planning/visits/performance.
- Support owns support/maintenance/warranty facts.
- Notifications owns notification delivery.
- Reporting reads domain facts without silently becoming a write path.

See [Domain Map](../architecture/DOMAIN_MAP.md).

## Key End-to-End Journeys

The main operational flows include:

- quotation -> order -> fulfillment -> shipment -> invoice -> payment;
- Sales shortage -> procurement -> receipt -> fulfillment;
- purchase order -> inbound -> stock receipt -> supplier bill/AP/payment;
- customer return -> Inventory return -> credit/refund;
- support ticket -> maintenance -> quotation/invoice/payment;
- warranty activation -> diagnosis/coverage/recovery;
- employee plan/task/visit -> voice/AI -> opportunity;
- campaign -> lead/customer -> sale;
- physical count/correction;
- fiscal-period close.

See [Canonical Business Flows](BUSINESS_FLOWS.md).

## Product Principles

- Server/domain services are authoritative for business rules.
- UI must not duplicate financial/stock calculations.
- Posted stock/accounting history is corrected through explicit reversal/correction paths.
- Cross-domain ownership is explicit.
- High-impact actions are auditable.
- Permissions are domain-scoped.
- Historical plans/specs are not current product truth once canonicalized.

## Documentation Navigation

Start at [Docs/README.md](../README.md).
