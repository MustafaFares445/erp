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
