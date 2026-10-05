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
- Pest 5 / PHPUnit 13
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

## Test Selection for Agents

Before choosing tests for any code change, use the project skill:

```text
.agents/skills/ierp-test-selection/SKILL.md
```

Use the generic `testing-best-practices` skill for test design/review, and `test-guard` after writing or editing tests.

Agents must run the smallest exact regression while iterating, then the owning domain suite before completion. Escalate to `composer test:fast`, MySQL acceptance, or the full Composer/coverage gate only when the change scope/risk requires it. Do not run the entire coverage gate after every small edit.

## Quality Gates

See `Docs/onboarding/TESTING_AND_QUALITY.md`.

Fast broad local regression:

```bash
composer test:fast
```

The full project gate is:

```bash
composer test
```

Current CI requires 100% type coverage and 100% code coverage. The full gate remains authoritative even though focused/domain tests are preferred during iteration.

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

===

<laravel-boost-guidelines>
=== .ai/project/feature-development rules ===

# AI Feature Development Standard

These rules govern AI-assisted changes in this application. They apply to every coding agent working in the repository.

1. **Discover before changing.** Read the current implementation, tests, canonical domain docs and sibling files before writing code. Reuse existing conventions/services instead of introducing parallel ones.
2. **Prefer version-specific documentation.** Use Laravel Boost `search-docs` or installed-package documentation before relying on remembered Laravel, Filament, Livewire or Pest syntax.
3. **Make reviewable changes.** Keep behavioral work focused. Do not bundle unrelated mechanical refactors with feature changes.
4. **Use explicit types and fail early.** Type-hint parameters/properties/returns and reject invalid state rather than silently normalizing business errors.
5. **Test every behavior change.** New/changed behavior requires Pest feature/unit coverage; bug fixes require a regression test.
6. **Select test scope by domain and risk.** Use `.agents/skills/ierp-test-selection/SKILL.md`. During iteration run the exact regression test first, then the owning domain suite. Escalate to `composer test:fast`, MySQL acceptance, or the full gate only when shared/cross-domain/critical/infrastructure impact requires it.
7. **Keep architecture enforceable through code/tests/static analysis/CI**, not documentation alone. The canonical gates are documented in `Docs/onboarding/TESTING_AND_QUALITY.md`.
8. **Improve legacy debt incrementally.** New PHPStan baseline entries are forbidden merely to make a change pass; the baseline should only shrink.
9. **Never weaken quality gates.** Do not lower PHPStan, architecture, type-coverage or code-coverage requirements or delete meaningful tests to get green.
10. **Use coverage deliberately.** `composer test:coverage` remains the authoritative 100% code-coverage command, but it is not the default command after every small edit. Full coverage belongs to the completion/CI tier defined by the test-selection policy.
11. **Use the generic testing skills correctly.** Use `testing-best-practices` for Laravel test design/review, version-specific docs for Pest syntax, and `test-guard` after writing or editing tests. Project-specific suite selection comes from `ierp-test-selection`.
12. **Keep documentation current.** Update canonical docs when behavior, lifecycle, permissions, domain ownership, public/mobile contracts or operations change.
13. **Respect the working tree.** Do not reset/stash/discard/reformat unrelated changes.
14. **Report verification honestly.** State the exact tests/domain suites/gates actually run. Never say “all tests pass” unless the relevant full suite was executed.

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== spatie/guidelines-skills/core rules ===

# Project Coding Guidelines

- This codebase follows Spatie's coding guidelines.
- Always activate the `spatie-laravel-php` skill when writing, editing, reviewing, or formatting Laravel or PHP code.
- Always activate the `spatie-javascript` skill when writing, editing, reviewing, or formatting JavaScript or TypeScript code.
- Always activate the `spatie-version-control` skill when creating commits, branches, or managing Git operations.
- Always activate the `spatie-security` skill when configuring security, signing commits, reviewing authentication, or setting up servers and databases.

</laravel-boost-guidelines>
