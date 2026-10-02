# Development Workflow

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: repository structure, composer scripts, CI and canonical agent workflow
---

## Before Editing

1. Check branch and working-tree status.
2. Read [Docs/README.md](../README.md).
3. Identify the owning domain using [Domain Map](../architecture/DOMAIN_MAP.md).
4. Read that domain's rules/workflows/tests.
5. Inspect existing code, sibling implementations and tests.
6. Read relevant ADRs.
7. Use an active plan only for unfinished work.

## Implementation

- Extend the existing domain service path rather than creating parallel business logic.
- Keep Filament/controllers as orchestration/UI layers.
- Preserve explicit domain ownership of stock, ledger, payments and commercial documents.
- Use current Laravel/Filament/Pest conventions.
- Add/adjust regression tests for behavior changes.
- Update canonical documentation when business behavior changes.

## Quality Loop

During development, run focused tests first.

Typical focused command:

```bash
php artisan test --compact tests/Feature/<Domain>/<Test>.php
```

Before completion, run the applicable project quality gates documented in [Testing & Quality](TESTING_AND_QUALITY.md).

## Database Changes

- Use Laravel migrations.
- Preserve historical data/provenance.
- Avoid destructive migration shortcuts for committed accounting/inventory history.
- Add factories/seed behavior where the project convention requires it.
- MySQL-specific concurrency behavior must be tested with the dedicated acceptance setup when relevant.

## Documentation Rule

Do not create another standalone â€œfinalâ€, â€œv2â€, findings, or implementation-summary Markdown file when the knowledge belongs in an existing canonical page.

Use:
- domain docs for current rules;
- ADR for durable architectural decisions;
- `Docs/plans/` only for active future work;
- Git history for completed plans.

## Git Safety

Do not discard, reset, stash, reformat or commit unrelated work.

Keep mechanical formatting/refactors separate from behavior where practical.
