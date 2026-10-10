---
status: canonical
owner: engineering
last_verified: 2026-10-08
verified_against: user-guide/package.json, scripts/user-guide-manifest.php, user-guide/scripts/generate-content.py, user-guide/scripts/validate-content.mjs
---

# User Guide Maintenance

The public operational guide under `user-guide/` is a separate VitePress product. Do not hand-maintain the generated screen catalog.

## Content ownership

Two content types exist.

### Curated content

Edit these deliberately:

- `user-guide/getting-started/`
- `user-guide/ar/getting-started/`
- workflow definitions and manual behavior overrides inside `user-guide/scripts/generate-content.py`
- `user-guide/content/resource-purpose.json` — authored EN/AR business purpose for every documented dashboard surface
- `user-guide/content/overrides/{en,ar}/{module}/{slug}.md` — reviewed article bodies for screens that need specific operator instructions; generated frontmatter and coverage metadata remain registry-owned
- glossary/troubleshooting articles
- VitePress theme/components/configuration

### Generated dashboard coverage

These are derived from the current Filament dashboard and should be regenerated:

- `user-guide/screens/**`
- `user-guide/ar/screens/**`
- module index pages under those trees
- generated sidebar definitions
- `user-guide/content/admin-manifest.json`
- `user-guide/content/coverage.json`
- `user-guide/content/screenshots.manifest.json`

The generator inspects the current `AdminModuleRegistry`, each registered Resource page and the PHP files under each Resource directory. It extracts form controls, infolist entries, table columns, filters, actions, relation managers, dashboard widgets and selected status vocabularies.

Generated facts are only the technical layer. User-facing purpose and business meaning are supplied by the authored resource-purpose catalog and manual overrides. A guide page must explain what the operator is trying to accomplish, when the page is used, the normal tasks, lifecycle effect and ownership boundary; a schema/table dump by itself is not considered complete documentation.

Authored override files are build inputs, excluded from VitePress routing and search with `srcExclude`. Publish their content through the generated screen article only. The built-output gate rejects duplicate pages under `content/overrides/`. Verified screenshot components in an override must include their locale-specific `imageSrc`, as required by the content gate.

## Regenerate after dashboard changes

Run:

```bash
npm --prefix user-guide run docs:generate
```

This first refreshes the Laravel-backed admin manifest and then rebuilds generated content.

After regeneration run:

```bash
composer test:guide
```

The guide quality gate verifies:

- the admin manifest still matches the current Filament registry;
- every dashboard class has an English and Arabic screen article;
- every documented surface has authored English and Arabic business-purpose text in `resource-purpose.json`;
- every screen article contains manual-style usage guidance and typical operator tasks in both locales;
- every unique registered Filament Resource page is represented;
- every required screen-reference position is rendered in both locales;
- required workflow guides exist and link steps back to their owning screens;
- generated articles still match fingerprints of their Resource/Schemas/Tables/Pages source files;
- generated article bodies still match their authored overrides, when present;
- the full VitePress production build succeeds;
- every root-absolute link emitted by the built HTML remains under the configured `/guide/` base, preventing raw HTML cards from bypassing VitePress base handling.

A source fingerprint failure means the UI implementation changed after the last content generation. A built-link validation failure means a documentation link was emitted outside the configured guide base and would produce a 404 on the same-host deployment.

## Screenshot workflow

Verified screenshots are introduced phase by phase after rendered dashboard QA. Unverified positions retain their existing screen-reference placeholder.

`user-guide/content/screenshots.manifest.json` is the capture queue. Every entry records:

- stable screenshot ID;
- module/resource/page;
- target article;
- English and Arabic caption context;
- planned locale coverage;
- viewport;
- theme;
- capture status.

`user-guide/content/verified-screenshots.json` maps stable screenshot IDs to verified locale-specific image paths under `/images/en/` and `/images/ar/`. `IerpScreenshot` resolves this mapping, prefixes the configured guide base with `withBase`, and still accepts an explicit `imageSrc`. Missing locale images retain the compact **Dashboard screen** reference.

The generator copies verified image paths into the screenshot manifest. A position becomes `captured` only when every planned locale has an image; a partially captured position stays `planned` and retains its existing locale paths. Regeneration must never remove approved image links. `composer test:guide` checks IDs, locale paths, source image existence, regeneration metadata and built image references.

Use the isolated `tmp/user-guide-demo.sqlite` environment for dashboard screenshots. Its serve script points contextual help to the guide on `https://ierp-new.test/guide/`, because the Artisan demo server does not provide VitePress clean-URL routing. Capture readiness establishes route/record resolution only; it is not a substitute for rendered screen QA. Keep application bugs and blocked locale captures in the phase report rather than publishing an image of a failing screen.

Contextual page-header help is registered against concrete Filament page scopes through the centralized resolver. Do not derive that header link solely from the HTTP route: a Livewire update uses a different route. `UserGuideLinkTest` exercises customer selection and Sales dashboard filter/reset updates in both locales.

The current verified catalog contains 84 locale images: 10 onboarding, 52 Sales and 22 Inventory images. Inventory coverage includes Stock Levels/Stock Movements list/detail and all seven Inventory Operations positions (combined list, receipts, deliveries, transfers, create, view and edit), at 1440 × 1100 in light mode, reviewed independently in English and Arabic. Their article bodies are curated overrides; regeneration preserves the image catalog and authored guidance.

Inventory Operations creation requires `capture_query.operation_type = internal_transfer`. The generator records that context separately from the registered route URI, the resolver appends it to the capture URL, and the content gate rejects its omission. A route without path parameters can still require query context; route readiness alone must not approve its page.

Phase 3 checked receipt search, typed view changes, a Draft purchase receipt's locked source fields, the completed receipt correction dialog and partial transfer receipt quantities. Three behavioral defects were corrected: type-specific table configuration was lost after Livewire updates, the combined list linked to unsupported generic creation, and the non-wizard transfer form omitted submit controls. Arabic creation title and warehouse/party labels were localized. No receipt, dispatch, cancellation or correction was posted during these browser checks.

The isolated resolver still reports 12 unavailable targets. Eleven positions lack records: Bank Statement View, Package View/Edit, Price History View and seven Support/Knowledge Edit pages. Supplier Payment Edit has a state restriction instead: all eight demo payments are Paid, and only Draft payments are editable. Keep these valid page positions pending until legitimate demo scenarios provide the required states; do not fabricate or reopen paid records for a screenshot.

Quotation CSV content and selected-view filtering have automated regression coverage in `SalesDocumentExportTest`. Browser capture currently times out waiting for its download event, so the browser result remains `unverified-download`. Quotation PDF download was verified through the real page link; the page exposes separate preview/download controls after generation.

Baseline capture rules:

1. Capture each registered routable page at least once.
2. Capture materially different tabs/states where one route contains multiple operational surfaces.
3. Use deterministic demo data.
4. Avoid real customer or employee personal data.
5. Prefer the light theme baseline at 1440 × 1100 unless the manifest says otherwise.
6. Add dark/RTL-specific captures only where they demonstrate materially different layout or behavior.
7. Do not crop away the page header when the action/status context matters.
8. Capture confirmation dialogs for irreversible/high-impact actions separately when they are added to the manifest.

## Updating workflows

Workflow articles are generated from the `WORKFLOWS` catalog in `generate-content.py`.

Each workflow must describe:

- business goal;
- participating modules;
- prerequisites;
- ordered steps;
- ownership boundaries;
- blocking/partial states;
- correction behavior;
- completion criteria;
- links back to screen documentation.

Do not describe a planned mobile/API feature as an implemented dashboard workflow.

## Design system

The public guide deliberately mirrors the dashboard identity:

- primary blue `#2563EB`;
- slate light surfaces;
- graphite dark surfaces;
- Inter for English;
- Noto Sans Arabic for Arabic;
- matching status tones, cards, tables, steppers, borders, radius and density.

The VitePress theme reimplements these semantics in Vue/CSS; it does not import Blade components into the standalone documentation application.

The documentation shell is module-first like an ERP manual: top-level modules are always visible, their internal sections are collapsible, module/task cards carry icon cues, and the article outline remains separate on the opposite side. The workflow stepper is isolated from Markdown ordered-list styling and must remain readable for long 7–10 step processes through horizontal overflow rather than label overlap.
