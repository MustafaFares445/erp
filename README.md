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

The setup script installs Composer dependencies, creates `.env` when missing, generates the app key, runs migrations, builds the Laravel frontend, installs the isolated `user-guide/` package, and builds the standalone guide to `/guide/`.

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
composer test:guide
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

User-facing operational documentation lives separately in `user-guide/`. The guide is generated against the current Filament module/resource registry so its screen coverage stays measurable.

To regenerate content after dashboard navigation/resources/pages change:

```bash
npm --prefix user-guide run docs:generate
```

To run it locally:

```bash
cd user-guide
npm install
npm run docs:dev
```

To validate registry coverage, bilingual articles, screenshot placeholders, workflows, links and the production build:

```bash
composer test:guide
```

The production build is emitted to `public/guide` and served under `/guide/` by default.

## Applications

- Admin: current Filament dashboard.
- Customer App V1: documented under `Docs/apps/customer/`, with visual source `design/customer-app-v1.pen`.
- Employee Sales App V1: documented under `Docs/apps/employee/`, with visual source `design/employee-sales-app-v1.pen`.

The runtime now exposes customer authentication plus a rollout-gated Support/Maintenance API under `/api/customer`. There is still no general-purpose ERP API or Employee mobile API, so mobile designs and service capabilities outside the registered routes must not be treated as implemented HTTP exposure.

IERP now includes a standalone bilingual VitePress **User Guide** under `user-guide/`. It is a separate web application from Filament and builds to the `/guide/` route by default. Its generated dashboard inventory currently covers all registered Filament resource pages, module/workspace surfaces and the core cross-module workflows, with planned screenshot slots tracked separately until real captures are added. The dashboard user-menu item only links to that documentation site; it does not render documentation inside Filament. Set `USER_GUIDE_URL` when the guide is deployed on a dedicated documentation domain.

## Agent Development

Repository entry points:

- `AGENTS.md`
- `CLAUDE.md`
- `.ai/guidelines/project/feature-development.md`

Agents should follow `Docs/README.md`, the owning domain documentation, relevant ADRs and current tests before changing behavior.
