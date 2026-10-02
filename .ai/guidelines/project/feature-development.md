# AI Feature Development Standard

These rules govern AI-assisted changes in this application. They apply to every coding agent working in the repository.

1. **Discover before changing.** Read the current implementation, tests, canonical domain docs and sibling files before writing code. Reuse existing conventions/services instead of introducing parallel ones.
2. **Prefer version-specific documentation.** Use Laravel Boost `search-docs` or installed-package documentation before relying on remembered Laravel, Filament, Livewire or Pest syntax.
3. **Make reviewable changes.** Keep behavioral work focused. Do not bundle unrelated mechanical refactors with feature changes.
4. **Use explicit types and fail early.** Type-hint parameters/properties/returns and reject invalid state rather than silently normalizing business errors.
5. **Test every behavior change.** New/changed behavior requires Pest feature/unit coverage; bug fixes require a regression test.
6. **Keep architecture enforceable through code/tests/static analysis/CI**, not documentation alone. The canonical gates are documented in `Docs/onboarding/TESTING_AND_QUALITY.md`.
7. **Improve legacy debt incrementally.** New PHPStan baseline entries are forbidden merely to make a change pass; the baseline should only shrink.
8. **Never weaken quality gates.** Do not lower PHPStan, architecture, type-coverage or code-coverage requirements or delete meaningful tests to get green.
9. **Use the repository coverage command.** Run `composer test:coverage`; CI currently uses PCOV. Local coverage may use the driver available on that machine, but the repository command/threshold is authoritative.
10. **Keep documentation current.** Update canonical docs when behavior, lifecycle, permissions, domain ownership, public/mobile contracts or operations change.
11. **Respect the working tree.** Do not reset/stash/discard/reformat unrelated changes.
