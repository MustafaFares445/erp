# iERP

iERP is a Laravel 13 + Filament 5 ERP modular monolith covering CRM, catalog/pricing, inventory, purchasing, sales, logistics, payments, accounting, employees, support/maintenance, notifications and reporting.

## Requirements

- PHP 8.4+
- Composer 2
- Node.js + npm
- A configured relational database for normal application development
- Required PHP extensions for the selected database/filesystem/providers

Normal automated tests default to in-memory SQLite; production-like warehouse/concurrency acceptance uses MySQL.

## Setup

Recommended:

```bash
composer run setup
```

The setup script installs Composer dependencies, creates `.env` when missing, generates the app key, runs migrations, installs npm packages and builds frontend assets.

For detailed setup and environment notes, read [Local Setup](Docs/onboarding/LOCAL_SETUP.md).

## Development

```bash
composer run dev
```

If Laragon, Herd or another local environment already serves the project, use the processes appropriate to that environment instead of starting a duplicate web server.

## Testing and Quality

Useful commands:

```bash
composer test:fast
composer test:docs
composer test:types
composer test:type-coverage
composer test:coverage
composer test
```

The project requires 100% type coverage and 100% code coverage in CI.

See [Testing & Quality](Docs/onboarding/TESTING_AND_QUALITY.md).

## Documentation

Start at [IERP Documentation](Docs/README.md).

Useful entry points:

- [Product Overview](Docs/product/PRODUCT_OVERVIEW.md)
- [System Overview](Docs/architecture/SYSTEM_OVERVIEW.md)
- [Domain Map](Docs/architecture/DOMAIN_MAP.md)
- [Canonical Business Flows](Docs/product/BUSINESS_FLOWS.md)
- [Agent Workflow](Docs/onboarding/AGENT_WORKFLOW.md)
- [ADRs](Docs/adr/README.md)

For implemented behavior, current code/tests and canonical domain docs take precedence over historical Spec Kit artifacts.

## Applications

- Admin: current Filament dashboard.
- Customer App V1: documented under `Docs/apps/customer/`, with visual source `design/customer-app-v1.pen`.
- Employee Sales App V1: documented under `Docs/apps/employee/`, with visual source `design/employee-sales-app-v1.pen`.

At the 2026-10-02 documentation audit, the runtime route table has no `api/*` routes. Mobile designs/service capabilities must not be treated as implemented API exposure.

## Agent Development

Repository entry points:

- `AGENTS.md`
- `CLAUDE.md`
- `.ai/guidelines/project/feature-development.md`

Agents should follow `Docs/README.md`, the owning domain documentation, relevant ADRs and current tests before changing behavior.
