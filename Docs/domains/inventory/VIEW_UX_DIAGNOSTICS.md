# Inventory View UX and Diagnostic Rules

Status: implementation wave 1 completed in the current working tree, pending fresh browser screenshot capture.
Source of truth: current Laravel/Filament resources, services, policies and status enums. Do not regenerate this page solely from a historical guide.

## UX standard for inventory View pages

Every supported View page starts with a bilingual, read-only panel named Operational health and next steps / الحالة التشغيلية والإجراء التالي. The panel communicates the actual known state, the finding or blocker, and the next safe human action. The severity mapping is danger (blocking/inconsistency), warning (action/review needed), info (workflow guidance) and success (no blocker found by implemented checks).

This is decision support, NOT a mutation endpoint or proof that physical stock is correct. All stock movements continue through their existing guarded workflows. Hide or gate posting actions using the existing policies. Never provide an unconditional "all stock is healthy" conclusion from this panel.

## Implemented View coverage

- Warehouse: inactive flag, incomplete coordinates, reserved-on-hand inconsistency, zero available despite on-hand, stock-linked active alerts, Waiting outbound deliveries, incoming transfers in transit/partial receipt, pending review counts.
- Inventory Operations: empty Draft, Waiting, Ready, Ready Delivery missing Planned Shipment, transfers in transit/partially received, terminal states.
- Inventory Counts: zero lines, uncounted lines, recount flags, system-vs-counted variances and review state.
- Inventory Alerts: active/resolved guidance.
- Adjustments, Reservations, Returns, Receipt Corrections, Condition Changes and Shipments: source-state guidance with next-action safety wording.

Code: app/Filament/Components/InventoryViewDiagnostics.php; app/Services/Inventory/InventoryViewDiagnosticsService.php; lang/en/inventory_view_ux.php; lang/ar/inventory_view_ux.php. The panel is injected at the start of the owning Filament infolist; no model, database schema or posting service was changed.

## Operator-facing guide alignment

Updated the English and Arabic inventory guide override content for warehouse, operation, count, adjustment, alert, reservation, return, correction, condition change and shipment. Updated existing Arabic screen guides where present. The guides distinguish between display-only diagnosis and confirmation/posting operations.

## Verification performed

- php -l on the new service, Filament component, both language files and all ten modified Filament View schema files.
- Feature test InventoryViewDiagnosticsTest: 3 tests, 9 assertions passed.
- Existing ViewWarehouse Livewire rendering test: passed.
- Focused PHPStan on new service and component: no errors.
- git diff --check on core tracked files: passed.
- No commit, push, reset, mass rewrite or cleanup of other ongoing working-tree changes.

## Remaining release gates

1. Re-capture fresh screenshots for updated View screens in both English and Arabic via the existing isolated E2E guide workflow; do not assume old screenshots show the new panel.
2. Visually inspect both LTR/RTL pages at desktop/tablet/mobile widths, including empty / active / unhealthy samples and correct per-role actions.
3. Complete the remaining Inventory View surfaces (e.g. stock levels, lots, serialized units, products, imports, warehouse replenishment) with domain-specific diagnostics rather than a misleading generic "healthy" badge.
4. Run broader Filament/Inventory regression and final full project checks in an isolated environment, once concurrent dirty working-tree changes are reconciled.

## QA acceptance criteria

At least one fixture for each diagnosis and transition; correct severity and recommended action; no false positive "physically checked" assertion; no write from viewing; no bypass of permissions; links and headings match real Filament UI; EN/AR documentation and screenshots represent the same actual release state.

## Continuation QA — 2026-10-10

The following checks were completed against the actual local `dev` working tree while retaining unrelated uncommitted changes.

- Added `tests/Feature/Inventory/Diagnostics/InventoryLotAndSerialDiagnoserTest.php`: expired saleable lot, quarantined/damaged quantities, inconsistent serialized warehouse custody, and Pending serialized unit. **4 passed, 12 assertions**.
- The complete `tests/Feature/Inventory/Diagnostics` suite passed after aligning the Shipment View rendering test with the canonical registered `Shipments\\Pages\\ViewShipment` route. **59 passed, 171 assertions**. The obsolete `ShipmentAttachments\\Pages\\ViewShipmentAttachment` is not a registered Filament route and must not be treated as a live UI surface.
- Refreshed generated English/Arabic screen content and source fingerprints for **27 Inventory resources only**; avoided rewriting generated documentation belonging to other modules. Inventory no longer appears among `docs:validate` stale-content failures.
- The **global** `docs:validate` still reports stale material from Sales, Purchasing, CRM, Employees and Support. This is outside this isolated Inventory update and remains a separate integration gate.
- Verified four core View routes in both locales on a guard-verified, cloned SQLite QA database with a private server on port 8012: Stock Levels, Inventory Lots, Serialized Units, Warehouses. **8/8 HTTP 200 and contextual guide-link checks passed**.
- Captured **8 new PNG screenshots**, each `1440x1100`, from the actual EN/AR Filament pages after browser settle and content checks. Promoted only these four IDs in `verified-screenshots.json` and `screenshots.manifest.json`; no screenshots from other modules were changed.
- Screenshot files were checked for PNG validity and minimum size; this does **not** substitute for a separate human pixel-level visual approval.
- The dedicated QA database is `tmp/inventory-view-qa-isolated.sqlite`; it was created using SQLite backup from the existing screenshot demo and is independent of the main development database. Test logs remain under ignored `storage/logs`.
- No commit or push was made.

### Remaining release work

Capture and approve the other changed Inventory View screens in both languages; verify responsive layout on mobile and tablet; add any missing domain-specific QA for still-uncovered diagnosers; run broader Inventory and Filament regression checks, and coordinate whole-project User Guide regeneration with other agents to avoid overwriting concurrent work.

### Completed Inventory View screenshot rollout — same session

- Inventory-only scoped content sync: 27 Inventory resource entries regenerated without touching generated articles from other modules.
- Captured and promoted **20 distinct Inventory View page IDs**, with English and Arabic screenshots for each: **40 PNG images** at 1440×1100. The screenshots passed browser render/stability and PNG file signature/dimension/size checks; no manual design-signoff is claimed.
- View coverage: warehouse, stock level, lot, serialized unit, product, stock movement, purchase inbound, inventory operation, outbound fulfillment, shipment, adjustment, inventory count, return, receipt correction, condition change, reservation, alert, package type, product variant, package.
- The package screenshot used a single **isolated QA package record** in `tmp/inventory-view-qa-isolated.sqlite`, with `SAFE_SQLITE_VERIFIED`. The normal shared demo screenshot target manifest was not modified; a temporary isolated capture script/target was removed after success.
- Refreshed `user-guide/content/verified-screenshots.json` and `user-guide/content/screenshots.manifest.json` for these specific IDs, retaining all unrelated entries.
- PHP/Pest: **59/59 Inventory Diagnostics tests passed (171 assertions)**. One obsolete unregistered ShipmentAttachments test target was corrected to the canonical registered Shipments View; no Shipment Service changes.
- Global `docs:validate` still has stale **non-Inventory** generated content. Do not overwrite other teams’ concurrent generated changes to mask that failure.
- Remaining: mobile/tablet screen-by-screen review, final visual signoff, comprehensive Inventory end-to-end work and whole-project gates after concurrent work is reconciled.

### Final acceptance after Inventory Lot panel remediation — 2026-10-10

- Fixed the Inventory Lot Filament Infolist to render the read-only `InventoryViewDiagnostics::section()` before identity, balances, and traceability.
- Added a Livewire regression assertion for the actual `ViewInventoryLot` route to require the localized operational diagnosis panel.
- Re-ran the authoritative focused suites: `php artisan test tests/Feature/Inventory/InventoryViewDiagnosticsTest.php tests/Feature/Inventory/Diagnostics --no-ansi` → **67 passed, 372 assertions** (`storage/logs/inventory-diagnostics-final-run.log`).
- Re-captured the Inventory Lot View in EN and AR after the panel change: **2/2 successful**, updated the Inventory Lot bilingual guide and its code fingerprint; previous full Inventory View screenshot coverage remains **20 routes / 40 PNG images**.
- Browser-responsive check after remediation: Inventory Lot View on **390px phone** and **768px tablet**, in **EN and AR**, **4/4 successful**, HTTP 200, diagnostic panel visible, and **0px document horizontal overflow** (`storage/logs/inventory-lot-responsive-final.log`). The earlier four-page comparison also showed no document-level horizontal overflow for Warehouse, Stock Level, Lot, or Serialized Unit.
- Final guide production build plus `docs:validate-built`: **successful**, **305 HTML pages**, **53,407 guide links**, **316 image references** checked (`storage/logs/inventory-guide-post-lot-final.log`).
- Whole-project `docs:validate` remains **blocked by generated content from non-Inventory modules**. Resolve only during coordinated integration; do not mass-regenerate other agents' work.
- These scoped checks do not certify full-project tests, total code coverage, accessibility, or manual pixel-level design review. There has been **no commit or push**.

### Human-reviewed Inventory diagnostic translations (2026-10-10)

Inventory View diagnostics use read-only bilingual wording from the Laravel language files:

- `lang/{en,ar}/inventory_view_ux.php`: original reviewed labels and descriptions, with highest precedence.
- `lang/{en,ar}/inventory_view_ux_reviewed.php`: 102 manually authored titles and explanations for operational states previously represented by generic phrases.
- `lang/{en,ar}/inventory_view_ux_extended.php`: remaining diagnostic terms, metrics, actions, facts, checks, and limitations referenced in the actual services.
- These are presentation-only changes: existing permissions, stock quantities, posting, and workflow state transitions are unchanged.
- `tests/Feature/Inventory/Diagnostics/InventoryDiagnosticTranslationCoverageTest.php` checks key availability, bilingual interpolation parity, and the absence of generic fallback wording from the reviewed cases.

An operator-facing finding should describe the observable state, the reason it needs attention, and a safe next action. Displayed recommendations never authorize or trigger a stock movement. Checks cannot prove physical inventory accuracy without a physical count.

Capture fresh EN/AR guide screenshots after the wording changes. Scope generated guide updates to Inventory: unrelated domains have ongoing work and their generated pages must not be overwritten.

### Localization and guide verification — 2026-10-10 (continuation)

- Corrected 102 previously generic Inventory diagnostic rules with hand-authored English and Arabic operator-facing titles and explanations. The base locale texts remain authoritative; reviewed rules are merged over extended fallback vocabulary.
- Added a repeatable Pest regression assertion for the 102 messages, their Arabic content and lack of generic placeholder prose, alongside literal key coverage and bilingual interpolation consistency.
- Final focused test command: `php artisan test tests/Feature/Inventory/InventoryViewDiagnosticsTest.php tests/Feature/Inventory/Diagnostics --no-ansi` — **70 passed, 4,976 assertions**, see ignored `storage/logs/inventory-i18n-reviewed-regression.log`.
- Captured **20 Inventory View routes / 40 EN and AR PNGs**, each 1440x1100, on the isolated browser QA database. Capture batches returned **18/18**, **20/20**, **2/2** success; image file headers, minimum sizes and manifest / verified-screen references were checked.
- The separate package capture used the existing isolated QA package record; the shared screenshot target resolver was not changed.
- `npm run docs:build && npm run docs:validate-built` passed: **305 HTML pages, 53,407 /guide/ links, 316 image references checked**; see `storage/logs/inventory-i18n-final-guide-build.log`.
- No changes to Inventory posting, reservations, permissions, counts, migrations, accounting, or operational services were made in this localization wave. No commit, push or git reset.
- Outstanding release gates: full-project quality suites, fresh manual pixel/RTL visual approval for all screens, and cross-domain `docs:validate` after other agent-generated content is reconciled. The built-guide validation above is not a substitute for those gates.
