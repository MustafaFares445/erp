# IERP Agent Entry Point

This file is intentionally short. Shared project guidance lives in the canonical documentation so agents do not receive several conflicting copies of the same rules.

## Required Read Order

Before behavior changes:

1. Read `Docs/README.md`.
2. Read `Docs/onboarding/AGENT_WORKFLOW.md`.
3. Identify the owner in `Docs/architecture/DOMAIN_MAP.md`.
4. Read the relevant `Docs/domains/<domain>/` pages.
5. Read relevant accepted ADRs.
6. Inspect the current code and tests.
7. Read an active plan/spec only when implementing unfinished work.

For cross-domain work, also read `Docs/product/BUSINESS_FLOWS.md`.

## Current Stack

- PHP 8.4
- Laravel 13
- Filament 5
- Livewire 3
- Pest 4 / PHPUnit 12
- Larastan 3
- Laravel Boost 2
- Rector 2
- Pint 1

## Engineering Rules

The shared AI development standard is `.ai/guidelines/project/feature-development.md`.

Key requirements:

- Discover before changing.
- Reuse existing domain services and conventions.
- Prefer installed/version-specific documentation; use Laravel Boost `search-docs` when available before relying on remembered Laravel/Filament/Livewire/Pest syntax.
- Every behavior change requires regression coverage.
- New PHPStan baseline debt is forbidden.
- Never weaken type/coverage/static-analysis/architecture gates to make a build pass.
- Do not discard or rewrite unrelated working-tree changes.
- Update canonical documentation when a business invariant, lifecycle, permission, cross-domain effect, public/mobile contract or operational requirement changes.

## Quality Gates

See `Docs/onboarding/TESTING_AND_QUALITY.md`.

The full project gate is:

```bash
composer test
```

Current CI requires 100% type coverage and 100% code coverage.

## Architecture Non-Negotiables

- Inventory owns physical stock mutation.
- Accounting owns the general ledger.
- Payments owns collection/allocation/provider behavior.
- Origin domains own their business facts.
- Posted stock/accounting history is corrected through explicit correction/reversal paths.
- Customer/employee ownership must be derived server-side.
- AI output remains reviewable evidence/suggestion until a human/domain rule confirms it.

## API Reality

Do not infer endpoints from old plans or service classes.

At the 2026-10-02 documentation audit, the runtime route table has no `api/*` routes. Use `Docs/reference/API.md` and active mobile plans for the current distinction between implemented domain capability and future API exposure.

## Documentation Creation

Do not create another standalone findings/final/v2/implementation-summary Markdown file when the information belongs in an existing canonical page.

Use:
- canonical domain/product/architecture docs for current truth;
- ADRs for durable decisions;
- `Docs/plans/` for active future work only;
- Git history for completed plans.
