# AI Feature Development Standard

These rules govern how AI-assisted changes are made in this application. They apply to every agent (Claude Code, Codex, Cursor, GitHub Copilot, Gemini CLI, OpenCode) working in this repo.

1. **Discover before changing.** Read the existing implementation, its tests, and sibling files before writing new code. Reuse existing conventions, helpers, and components instead of introducing parallel ones.
2. **Prefer version-specific documentation over remembered syntax.** Use Laravel Boost's `search-docs` tool (or the installed package's own docs) before relying on training-data knowledge, especially for Filament, Livewire, and Pest APIs.
3. **Make small, reviewable changes.** One logical change per commit/PR. Do not bundle mechanical refactors (Rector, Pint) with behavioral changes.
4. **Use explicit types and fail early.** Type-hint all parameters, properties, and return values. Prefer throwing/validating early over silently tolerating invalid state.
5. **Test every behavior change.** New or changed behavior must ship with a Pest feature or unit test. Bug fixes must include a regression test.
6. **Keep architecture enforceable through code, tests, static analysis, and CI**, not through documentation alone:
   - `vendor/bin/pint --dirty` for formatting.
   - `vendor/bin/phpstan analyse` for static analysis (see `phpstan.neon` / `phpstan-baseline.neon`).
   - `vendor/bin/pest` (including `tests/Unit/ArchTest.php`) for architecture and behavior.
   - `composer test` mirrors the CI gate in `.github/workflows/tests.yml`.
7. **Improve legacy code incrementally.** New PHPStan baseline entries are forbidden; the baseline may only shrink. When a change touches a file with existing baseline entries, remove the entries that no longer apply.
8. **Never weaken quality gates to make a build pass.** Do not lower PHPStan level, remove architecture rules, skip tests, or inflate type/test coverage thresholds just to get CI green — fix the underlying issue or, if truly out of scope, leave it documented and unbaselined-only-when-safe.
9. **Xdebug provides local code coverage.** The local PHP install has Xdebug loaded with `xdebug.mode = develop,debug,coverage` (see `php.ini`), matching the `coverage: xdebug` setup already used in `.github/workflows/tests.yml`. Run `composer test:coverage` (`pest --coverage`) to generate a coverage report locally before relying on CI for coverage feedback.
