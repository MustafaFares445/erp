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

### Module dashboards

Each module's landing page extends `App\Filament\Pages\ModuleDashboard`, so all seven share one layout.

**Filter card.** A date-range preset sits at the top:
- presets: today, last 7 or 30 days, this or last month, this quarter, this year, or a custom range;
- the default is the last 30 days;
- the module's own filters follow (see the table below);
- a *Reset filters* header action clears the card;
- the filters are kept in the URL and the session.

**Widget grid.** Below the filters is a two-column grid:
1. One row of at most four KPI cards. Period figures show the change against the equal-length previous window, plus a sparkline of the selected window. Live queues show the current count.
2. Two charts side by side.
3. Two tables side by side, 5 rows per page.
4. On Support only, one full-width table.

Rules that apply to every dashboard:
- **Pairs.** Pairs are declared in `getDashboardWidgets()`. When a user cannot see one half of a pair, the other half takes the full row.
- **Shared period logic.** Widgets read the filters through `InteractsWithDashboardFilters`. Bucketing and the comparison window come from `App\Support\Dashboard\DashboardPeriod`.
- **No polling.** Widgets refresh when the filters change, not on a timer.
- **Formatting.** Money is shown in the default currency through `MoneyFormatter`. Quantities go through `QuantityFormatter`.
- **Translations.** All strings live in `lang/{en,ar}/dashboards.php`.
- **Work queues ignore the date range.** Tables that are current-state queues (attention lists, dormant leads, overdue tasks, upcoming deliveries or maintenance) still honour the module filters.

| Dashboard | Module filters | KPI cards |
| --- | --- | --- |
| Sales | Salesperson, customer | Confirmed order value, confirmed orders, average order value, quote → order conversion |
| Accounting | — | Receivables outstanding (bad debt in period), payables outstanding (billed in period), net tax position, drafts awaiting action |
| CRM | Lead source | New customers, active customers, new leads, lead conversion (share of the window's new leads now converted) |
| Employees | Employee | Tasks completed, customer visits, open tasks (overdue count), opportunities awaiting review |
| Inventory | Warehouse | Stock value, needs reorder, replenishment requirements (or unresolved alerts without replenishment access), documents awaiting action |
| Purchasing | Supplier | PO spend in the default currency (no cross-currency summation), needs sourcing, awaiting approval, overdue deliveries |
| Support | Assignee, priority | Tickets opened, tickets resolved, open tickets, SLA at risk |

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
