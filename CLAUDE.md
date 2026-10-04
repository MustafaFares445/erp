# IERP Claude / Laravel Boost Entry Point

Read `AGENTS.md` first. It is the shared agent entry point.

Then follow:

1. `Docs/README.md`
2. `Docs/onboarding/AGENT_WORKFLOW.md`
3. the relevant domain documentation
4. current code/tests
5. relevant ADRs
6. active plan only for unfinished behavior

## Laravel Boost

When Laravel Boost tools are available:

- prefer `search-docs` for installed-version Laravel/Filament/Livewire/Pest syntax;
- inspect current routes/config/database schema using project tools before guessing;
- prefer existing Artisan commands and tests over ad-hoc verification scripts;
- use current project URLs/environment rather than assuming Laravel Herd or another local server is in use.

## PHP / Laravel Conventions

- explicit parameter and return types;
- curly braces on control structures;
- use Laravel/Eloquent conventions and existing sibling patterns;
- use factories in tests;
- most behavior tests should be Pest feature tests;
- do not add dependencies or new base folders without a real project need.

## Verification

Before selecting a test scope, read/use:

```text
.agents/skills/ierp-test-selection/SKILL.md
```

During iteration, run the exact regression test first. Before completion, run the owning domain suite and escalate according to risk/cross-domain impact. `composer test:fast` is the broad local regression path without coverage; the expensive `composer test` / coverage gate is not required after every small edit but remains authoritative for full verification and CI.

Use the generic `pest-testing` skill for Pest syntax and `test-guard` to review test changes.

If PHP files are modified, apply the repository's Pint/Rector conventions without formatting unrelated user work.

## Documentation

Current behavior belongs in canonical docs under `Docs/`.

Do not use removed PRD/SDD/ERD/API-contract files or old implementation plans as runtime truth.

The current stack, architecture, APIs and domain boundaries are indexed from `Docs/README.md`.
