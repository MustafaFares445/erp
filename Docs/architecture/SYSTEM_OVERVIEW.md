# System Overview

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: composer.json, current app/services/resources/routes and canonical domain docs
---

## Runtime Shape

IERP is a Laravel 13 modular monolith running on PHP 8.4+ with Filament 5 as the current administration UI.

The application keeps business logic in domain services/models/policies and uses Filament resources/pages/actions as the administrative interaction layer.

## Major Layers

### UI / Delivery

- Filament dashboard/resources/pages/actions.
- Web controllers for explicitly exposed web/media behavior.
- Console commands/jobs.
- Future Customer/Employee mobile API adapters.

### Domain / Application Services

Business workflows live primarily in `app/Services/<Domain>/`.

Important examples:
- Inventory posting/operations.
- Purchasing PO/inbound.
- Sales quotation/order/invoice.
- Payments allocation/provider.
- Accounting journal/document.
- CRM onboarding/leads.
- Employees visits/AI/performance.
- Support ticket/maintenance/warranty.

### Persistence

Eloquent models + Laravel migrations on a relational database.

Business history relies heavily on:
- status/lifecycle fields;
- provenance foreign keys/morphs;
- append-only evidence/log records where appropriate;
- Spatie activity log for audit history;
- Media Library for private/public document/media collections.

### Events / Listeners

Cross-domain boundaries use events/listeners where appropriate.

Examples:
- released Sales order -> procurement synchronization;
- completed Inventory receipt -> Purchasing/Sales procurement advancement;
- completed delivery -> Shipment In Transit;
- Shipment Arrived -> Sales completion-window refresh.

See [Integrations](INTEGRATIONS.md) and [Canonical Business Flows](../product/BUSINESS_FLOWS.md).

## Current Route Boundary

The current route files are `routes/web.php` and `routes/console.php`.

At the 2026-10-02 audit, the runtime has no `api/*` routes.

Service-level capabilities such as Stripe checkout or Employee AI therefore do not imply a currently exposed mobile API.

## Domain Boundaries

See [Domain Map](DOMAIN_MAP.md).

## Quality Tooling

Current project tooling includes Pest, Larastan/PHPStan, Pint, Laravel Boost and project coverage/type-coverage gates.

See [Agent Workflow](../onboarding/AGENT_WORKFLOW.md).
