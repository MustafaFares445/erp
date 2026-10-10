---
status: active-plan
owner: product
last_verified: 2026-10-08
---

# IERP Non-Technical User Guide Implementation Plan

## 1. Purpose

Build a public-facing, non-technical IERP user guide inside the existing repository.

The guide will teach administrators and operational users how to complete real business tasks in IERP without exposing implementation details such as Laravel classes, APIs, database tables, queues, jobs, migrations, or internal service names.

The guide is a separate VitePress application stored in the same Git repository. It is not a Filament page and has its own package, theme, content tree, search, localization, and build lifecycle.

### Current implementation

As of 2026-10-07, `user-guide/` is implemented as a bilingual VitePress web application. English is served from the guide root and Arabic from `/ar/`; Arabic uses RTL styling and the same Noto Sans Arabic family used by the dashboard. The guide now mirrors the dashboard's blue/slate/graphite visual system, status semantics, cards, tables, stepper vocabulary, spacing and responsive behavior.

The content layer is no longer a small hand-maintained module overview. A Laravel-backed manifest exporter inventories the current `AdminModuleRegistry`, workspaces, contextual resources and each registered Filament Resource page. The generator currently produces **111 bilingual screen/surface articles**, covers **264 unique registered Resource pages**, tracks **282 planned screenshot positions** and publishes **21 bilingual cross-module workflow guides**. Getting Started and Reference pages explain navigation, tables/search/filtering, statuses/actions, permissions, module ownership and current status vocabularies.

`composer test:guide` now checks that the code-derived admin manifest is current, every registered dashboard class/page remains covered, EN/AR articles remain paired, screenshot placeholder IDs remain valid, required workflow files exist, generated articles still match SHA-256 fingerprints of their Resource/Schemas/Tables/Pages source files, and the VitePress production build succeeds. Verified screenshots are embedded only after rendered QA; unverified locale positions retain screen-reference placeholders. `screenshots.manifest.json` now also records the real Filament route/page/model metadata required for later capture, and the optional capture pipeline resolves those targets against an isolated deterministic SQLite demo database without touching the developer's normal database.

The default VitePress base is `/guide/` and the production build target is `public/guide`, so the same web host can expose the guide at `/guide/`. The Filament user menu contains only a link to the configured `USER_GUIDE_URL` and opens the standalone guide in a new tab. A dedicated documentation host/subdomain remains a supported deployment option.

This plan remains active for actual screenshot capture and annotation, deeper editorial review of the highest-risk screen explanations, and release/deployment hardening. Stable page-header links from the ERP to matching guide articles are implemented.

### Current QA continuation

The catalog now contains **84 locale images**: 10 onboarding, 52 Sales and 22 Inventory. There are 41 positions with both planned locales and two onboarding positions with only English. Stock Levels and Stock Movements list/detail plus all seven Inventory Operations screens passed desktop EN/AR review; their operational articles use curated overrides. Further Inventory posting/correction/count/return workflows, Purchasing, Accounting, CRM, Employees and Support still require editorial and interaction QA.

The 2026-10-08 Inventory Operations batch fixed typed-list column/group/filter drift after Livewire updates, the combined list's unsupported creation link, and missing submit controls on the non-wizard transfer form. Arabic creation title and warehouse/party labels were corrected before approval. The capture target now includes the required internal-transfer query context. Browser review covered read-only/validation interactions and partial receipt evidence; no physical operation was posted. The curated article distinguishes source-driven receipt/delivery creation, Ready vs stock custody, warehouse dispatch vs customer receipt, partial transfers, locked purchase assignments, and correction/financial boundaries.

Contextual help after Livewire filter/customer updates is covered by bilingual regression tests and browser Sales dashboard filter/reset checks. The existing page-scoped resolver implementation is preserved. Quotation details now expose stored PDF preview/download controls; a browser download produced a valid PDF. Opening the preview link was exercised, but the browser surface did not expose a PDF viewer for visual review. Quotation CSV output and selected-view filtering pass automated checks; **browser download remains unverified** after an event timeout.

The 12 unresolved route/record targets are classified, not approved: 11 lack legitimate demo records, while Supplier Payment Edit is inaccessible because all demo payments are Paid and only Draft is editable. No records were fabricated to improve the capture count. These positions remain pending.

Retained findings from earlier QA still need follow-up: Arabic global-menu guide locale, SQLite quotation table search, expired quotation display/action consistency, converted quotation customer-decision summary, and Arabic Requote contrast. This continuation does not certify the entire guide or every previously captured business state as production-ready.

## 2. Primary Outcomes

- Give a new user a clear onboarding path from first login to daily operation.
- Explain IERP by business task and workflow, not by code or database structure.
- Cover the implemented administration dashboard first.
- Support English and Arabic from the information-architecture level.
- Provide screenshots and annotated examples for important actions.
- Explain the business consequence of important actions before the user performs them.
- Keep documentation synchronized with implemented behavior.
- Make the guide searchable and easy to browse by both module and task.
## 3. Key Architecture Decision

Use the same repository, but create a self-contained documentation application:

```text
ierp-new/
├── app/
├── database/
├── resources/
├── Docs/                  # Internal/canonical developer documentation
├── user-guide/            # Public non-technical documentation site
│   ├── package.json
│   ├── .vitepress/            # theme, components, generated sidebars
│   ├── content/               # non-public coverage/screenshot/admin manifests
│   ├── scripts/               # content generation and validation
│   ├── getting-started/       # curated English onboarding
│   ├── workflows/             # generated/curated English process guides
│   ├── screens/               # generated English dashboard-screen articles
│   ├── reference/             # statuses, ownership and reference material
│   └── ar/                    # Arabic semantic mirror
├── public/guide/              # generated build output, gitignored
└── ...
```

Do not place public user-guide pages under `Docs/`.

`Docs/` remains the canonical engineering/product reference for developers and agents. `user-guide/` is a separate user-facing product with different language, structure, screenshots, and release concerns.

The VitePress package should have its own `package.json` so its dependencies and build scripts do not interfere with the Laravel application's existing Vite/Tailwind setup.

## 4. Source-of-Truth Policy

User-guide content must be derived from:
1. Current implemented Filament screens and actions.
2. Current domain services and executable tests when behavior is not obvious from the UI.
3. Accepted ADRs.
4. Canonical `Docs/product`, `Docs/domains`, and `Docs/reference` documentation.
5. Approved UI designs only when they match current implementation.

Never publish a planned behavior as currently available.

The current repository explicitly distinguishes implemented dashboard behavior from planned mobile/API behavior. The same rule must apply to the user guide.

For each article, the author must verify:
- the navigation path exists;
- the action exists;
- visible labels match the current UI;
- statuses match the current lifecycle;
- described side effects actually occur;
- permission restrictions are accurate;
- screenshots match the current release.

## 5. Explicit Non-Goals

The public guide must not contain:
- REST endpoints;
- controller or service class names;
- model or table names;
- migrations;
- queue/job implementation details;
- Laravel or Filament implementation guidance;
- deployment instructions;
- internal architecture diagrams;
- test implementation details;
- secrets/configuration keys;
- developer setup instructions.
## 6. Target Audiences

### 6.1 Administration Dashboard User

Primary launch audience.

Typical goals:
- manage customers and suppliers;
- create quotations and orders;
- fulfill and invoice sales;
- receive and pay supplier purchases;
- manage warehouse stock;
- record payments;
- review accounting results;
- manage CRM, employees, support, maintenance, and reports;
- configure business settings.

### 6.2 Customer App User

Prepare the documentation structure now, but do not publish operational guides until the customer app and its required backend behavior are production-ready.

### 6.3 Employee App User

Prepare the documentation structure now, but do not publish operational guides until the employee app and its required backend behavior are production-ready.

## 7. Documentation Principles

1. Start from what the user wants to accomplish.
2. Explain business meaning before steps.
3. Use the exact labels visible in the product.
4. Keep one primary task per article.
5. Explain prerequisites before instructions.
6. Explain the result after instructions.
7. Explain downstream business impact for financial, inventory, purchasing, and status-changing actions.
8. Use screenshots where they materially reduce ambiguity.
9. Avoid screenshots for trivial steps that are already obvious from text.
10. Include troubleshooting for realistic user errors.
11. Link to the next logical task.
12. Keep language operational and concise.
13. Never assume the reader understands ERP terminology.
14. Define unavoidable accounting/inventory terms in plain language.
15. Treat RTL as a first-class layout, not a translation afterthought.

## 8. User Guide Information Architecture

The top-level navigation should be:

```text
Home
Getting Started
Business Workflows
Sales
Purchasing
Inventory
Accounting
CRM
Employees
Support & Maintenance
Reports
Settings
Glossary
Troubleshooting
```

Later, when production-ready:
```text
Customer App
Employee App
```
## 9. Getting Started Section

Create:
- Welcome to IERP
- Who should use this guide?
- Sign in
- Understand the dashboard
- Understand the sidebar
- Search and filters
- Tables, pagination, and saved views
- Record detail pages
- Status badges
- Notifications
- Common actions
- Confirmations and warnings
- Language and RTL usage
- How to get help

The goal is that a new user can understand the interaction model before learning individual modules.

## 10. Business Workflows Section

Workflow guides are the most important onboarding material.

Initial workflow pages:
- Customer sale from quotation to payment
- Sale when stock is unavailable
- Purchase from supplier to stock receipt and supplier payment
- Customer return to credit/refund
- Partial payment and remaining balance
- Support ticket to maintenance and billing
- Warranty / covered maintenance flow
- Employee visit to reviewed sales opportunity
- Inventory count and correction
- Month/fiscal-period operational close where applicable
Each workflow page should contain:
- business goal;
- actors involved;
- prerequisites;
- visual lifecycle;
- step-by-step summary;
- what changes at each step;
- important blocking conditions;
- links to detailed task pages;
- completion criteria.

Example visual style:

```text
Quotation
   ↓
Customer acceptance
   ↓
Sales order
   ↓
Fulfillment / shipment
   ↓
Invoice
   ↓
Payment
```

Do not reproduce implementation events or domain-service names from internal documentation.

## 11. Module Structure

### Sales
- Sales overview
- Customers in the sales process
- Quotations
- Sales orders
- Fulfillment / shipments
- Invoices
- Payments
- Credit notes
- Customer returns
- Refund-related user flows
- Common sales statuses
- Sales troubleshooting

### Purchasing
- Purchasing overview
- Suppliers
- Purchase orders
- Supplier confirmation
- Sending / activating a purchase order
- Receiving purchased goods
- Supplier bills
- Accounts payable payment flow
- Short close / cancelled supplier fulfillment
- Purchasing troubleshooting

### Inventory
- Inventory overview
- Products and variants
- Warehouses
- Stock levels
- Stock movements
- Receiving stock
- Delivering stock
- Stock transfers
- Stock adjustments
- Physical count
- Returns
- Reservations / availability concepts
- Inventory troubleshooting
### Accounting
- Accounting overview for non-accountants
- Chart of accounts: what the user needs to know
- Journal entries
- Accounts receivable
- Accounts payable
- Customer payments
- Supplier payments
- Taxes
- Expenses
- Credit and refund concepts
- Financial reports
- Period status / closing concepts
- Accounting troubleshooting

### CRM
- CRM overview
- Customers
- Leads
- Opportunities
- Activities
- Campaigns
- Converting activity into sales work
- CRM troubleshooting

### Employees
- Employee records
- Monthly plans
- Tasks
- Customer visits
- Performance
- Salary calculations
- Voice note / AI review behavior only when exposed to the relevant user
### Support & Maintenance
- Support overview
- Tickets
- Triage
- Assignment
- Maintenance requests
- Service records
- Chargeable maintenance
- Warranty / covered work
- Customer responsibility
- Closing work
- Support troubleshooting

### Reports
- Reports overview
- Operational reports
- Sales reports
- Inventory reports
- Financial reports
- Employee reports
- Filters
- Exporting
- Reading totals and date ranges

### Settings
- Settings overview
- Currencies
- Units
- Payment terms
- Payment methods
- Tax definitions
- Document templates
- Users and permissions
- Other user-visible business settings
## 12. Required Article Types

### 12.1 Concept Page

Used for concepts such as Payment Terms, Credit Notes, Stock Reservations, or Accounts Receivable.

Structure:
1. What it is
2. Why IERP uses it
3. When you will see it
4. Simple example
5. Related tasks

### 12.2 Task Guide

Used for a specific action such as Create a quotation or Receive a purchase order.

Structure:
1. Goal
2. When to use it
3. Before you start
4. Navigation path
5. Steps
6. Screenshot(s)
7. What happens next
8. Business impact
9. Common problems
10. Related guides

### 12.3 Workflow Guide

Used for cross-module journeys.

Structure:
1. Business scenario
2. Visual lifecycle
3. Roles involved
4. Main steps
5. Decision points
6. Blocking conditions
7. Result
8. Detailed guides

### 12.4 Troubleshooting Page

Structure:
1. Symptom
2. Likely reason
3. What the user should check
4. Safe resolution
5. When to contact an administrator

## 13. Standard Task Article Template

Every task article should use a consistent frontmatter contract.

Example:

```yaml
---
title: Create a quotation
description: Create and prepare a customer quotation.
module: Sales
audience: admin
status: published
lastVerified: 2026-10-07
---
```

The rendered article should then follow:
- What this does
- When to use it
- Before you start
- Steps
- What happens after completion
- Important effects
- Common problems
- Related guides

Do not expose internal verification metadata in the rendered body unless useful to the user.

## 14. Screenshot Strategy

Screenshots must be treated as maintained documentation assets.

Store them under language-aware paths such as:

```text
user-guide/public/images/en/sales/quotations/
user-guide/public/images/ar/sales/quotations/
```

Rules:
- use realistic but non-sensitive demo data;
- never capture production customer data;
- keep browser chrome out unless required;
- crop to the relevant area;
- capture at a consistent desktop viewport;
- capture RTL separately when UI structure materially changes;
- use numbered callouts for dense transactional forms;
- add descriptive alt text;
- avoid embedding labels into the image when translated text would make maintenance difficult;
- replace outdated screenshots when UI labels or layout change.

For high-risk actions, include a screenshot of the confirmation dialog and the impact summary.

## 15. Screenshot Automation Phase

**Automation infrastructure implemented; approved capture coverage is partial.**

The repository now has an optional repeatable screenshot workflow that:
- creates `tmp/user-guide-demo.sqlite` and runs deterministic Demo seed data with external delivery/transcription behavior disabled;
- refuses to resolve capture records against any database other than that isolated SQLite file;
- authenticates with the seeded documentation/demo administrator;
- derives URLs from the current registered Filament routes instead of hard-coded assumptions;
- resolves record pages against an accessible record state (`canView` / `canEdit`) instead of blindly using the first ID;
- supports English and Arabic browser checks;
- uses the locally installed Edge/Chrome executable through `playwright-core`;
- defaults to non-writing plan/check modes;
- requires `IERP_GUIDE_CAPTURE_CONFIRM=1` before capture mode can write image files.

At the current verification point, **270 of 282** tracked screenshot positions resolve to a real route plus an accessible deterministic Demo record where required. The remaining 12 are explicitly reported because DemoMonth currently has no accessible record for those exact View/Edit states; the pipeline does not invent fixtures or capture 403 pages.

The verified catalog currently embeds 84 locale images across onboarding, Sales and two Inventory batches. Further capture and annotation remain active work; route readiness alone does not approve an image.

## 16. VitePress Application Structure

Recommended structure:

```text
user-guide/
├── package.json
├── package-lock.json
├── .vitepress/
│   ├── config.mts
│   ├── theme/
│   │   ├── index.ts
│   │   └── custom.css
│   └── sidebar/
│       ├── en.ts
│       └── ar.ts
├── public/
│   ├── logo/
│   └── images/
├── en/
│   ├── index.md
│   ├── getting-started/
│   ├── workflows/
│   ├── sales/
│   ├── purchasing/
│   ├── inventory/
│   ├── accounting/
│   ├── crm/
│   ├── employees/
│   ├── support/
│   ├── reports/
│   ├── settings/
│   ├── glossary.md
│   └── troubleshooting/
└── ar/
    └── same semantic structure
```

Keep English and Arabic page paths semantically paired where possible.

## 17. Localization and RTL

VitePress locale configuration must support:
- English as LTR;
- Arabic as RTL;
- localized navigation labels;
- localized sidebar labels;
- localized search UI;
- locale switcher;
- correct Arabic typography and line height;
- mirrored directional icons where appropriate.

Translations must be meaning-preserving, not literal word-for-word translations of ERP terminology.
Maintain a glossary for terms that need consistent bilingual naming, for example:
- Quotation
- Sales Order
- Delivery / Shipment
- Invoice
- Credit Note
- Purchase Order
- Supplier Bill
- Stock Adjustment
- Journal Entry
- Accounts Receivable
- Accounts Payable
- Payment Terms
- Refund

## 18. Search and Discovery

The guide must support:
- full-text search;
- module navigation;
- task-based navigation;
- previous/next links;
- related guides;
- direct links from contextual help in the ERP.

The home page should prominently answer:
- I am new to IERP
- I want to sell to a customer
- I want to buy from a supplier
- I want to receive stock
- I want to record a payment
- I want to correct a stock quantity
- I want to handle a return
- I want to handle a support request

## 19. Contextual Help Integration

The dashboard has a global **User Guide** item in the authenticated user menu that opens the standalone VitePress site using `USER_GUIDE_URL`.

**Stable page-level contextual help is implemented.** A single Filament `PAGE_HEADER_ACTIONS_AFTER` integration resolves the current registered Resource/Page through `AdminModuleRegistry` and opens the corresponding bilingual guide article in a new tab. English routes resolve to `/screens/<module>/<screen>` and Arabic routes to `/ar/screens/<module>/<screen>`. Unknown or unmapped routes render no contextual link rather than guessing a URL.

Contextual header hooks use concrete Filament page scopes from the centralized resolver, preserving the article through Livewire updates without depending on the update route or referrer. Route-based resolution remains available for other callers.

Possible later refinement is limited to concept-level links near complex fields or Learn more links inside selected high-impact confirmation dialogs. Contextual help must not replace concise inline helper text in the application.

## 20. Content Verification Process

Every article must pass a behavior verification checklist before publishing:

- [ ] Feature exists in the current UI.
- [ ] Navigation path is correct.
- [ ] Required permissions are understood.
- [ ] Labels match the UI.
- [ ] Status names match current behavior.
- [ ] Prerequisites are complete.
- [ ] Main happy path was manually reproduced.
- [ ] Blocking/error states are documented where useful.
- [ ] Financial/inventory consequences are correct.
- [ ] Screenshots match the current release.
- [ ] No technical implementation details leaked into the user guide.
- [ ] English and Arabic terminology is consistent.
- [ ] Related links resolve.

## 21. Automated Quality Checks

Add a guide-specific validation script that checks:
- internal links;
- missing images;
- duplicate slugs;
- required frontmatter;
- invalid status values;
- stale or missing `lastVerified`;
- English/Arabic page pairing where required;
- prohibited technical terms when they indicate accidental implementation leakage;
- VitePress production build.

Do not mix this validator into `scripts/docs-check.php` initially.

Reason: internal canonical docs and public user docs have different rules and lifecycles.

Suggested commands:

```text
cd user-guide
npm ci
npm run docs:dev
npm run docs:build
npm run docs:preview
npm run docs:check
```

Actual script names can be finalized during implementation.

## 22. CI Plan

The repository quality job now installs the guide's Node dependencies and runs `composer test:guide`.

The guide gate:
1. boots the Laravel application without requiring a production database connection and derives the current dashboard manifest from the module registry/resources;
2. fails when `user-guide/content/admin-manifest.json` is stale;
3. validates EN/AR screen-article parity;
4. verifies every unique registered Resource page has generated coverage and a screenshot placeholder;
5. verifies the required workflow library;
6. runs the VitePress production build and fails on build/content errors.

The VitePress renderer itself remains a static application. Laravel bootstrapping is used only by the validation/generation step so documentation cannot silently drift from the dashboard structure.
## 23. Deployment Plan

The VitePress application is deployment-independent from Filament even when both are served by the same host.

Default repository shape:

```text
ERP dashboard:      https://erp.example.com/admin
User documentation: https://erp.example.com/guide/
```

The guide may instead be published on a dedicated documentation host such as `https://docs.example.com`; set `USER_GUIDE_URL` accordingly. Deployment options may include the existing web host, a static host, CDN-backed object storage, GitHub Pages, Cloudflare Pages, Netlify, or Vercel.

The final choice is an infrastructure decision and should not change the content structure.

Required deployment properties:
- HTTPS;
- stable URLs;
- automatic build from the selected release branch;
- cache invalidation on deploy;
- searchable static output;
- custom 404 page;
- no exposure of source-only/internal documents.

## 24. Versioning Strategy

For the first release, document only the currently deployed IERP version.

Do not introduce multiple documentation versions until the product actually maintains supported parallel versions.

When versioning becomes necessary, add:
- version selector;
- archived read-only guide builds;
- clear current/stable marker.
## 25. Content Release Strategy

### Phase 0 - Foundation
Implemented:
- `user-guide/` exists as an isolated package;
- VitePress 1.6.4 is installed with its own lockfile;
- English/Arabic locale navigation and RTL styling are configured;
- the guide reuses the dashboard's blue/slate/graphite identity and semantic component vocabulary;
- local search, module navigation, page outlines and the static `/guide/` build are working;
- reusable guide components exist for screenshots, navigation paths, statuses, workflow steppers and page summaries;
- code-derived admin, coverage and screenshot manifests are implemented;
- generated screen/workflow content and bilingual parity validation are implemented;
- CI executes the guide validation/build gate;
- the Filament user menu links externally through `USER_GUIDE_URL`.

The deterministic **capture infrastructure** is implemented: isolated SQLite demo preparation, route/model-aware target resolution, permission-aware record selection, English/Arabic browser checking, and guarded Playwright/Edge capture commands are available. Capture/editorial review remains partial: the verified catalog includes 84 locale images, while the remaining positions require independent UI QA.

### Phase 1 - New User Onboarding
**Implemented baseline.** Getting Started now covers the dashboard shell, module switcher, sidebar/workspaces, global search, tables, filters, saved views, statuses, actions, confirmations, permissions, language/RTL, appearance and help. Reference pages cover module ownership and current status vocabularies.

### Phase 2 - Core Revenue Flow
**Implemented baseline.** Customers, quotations, Sales Orders, fulfillment/shipment, invoices, payments, payment transactions, credit notes, customer returns/refunds and Quote-to-Cash are covered by bilingual screen articles and workflow guides. Screen articles include actual registered list/create/view/edit/specialized pages and extracted form/table/action context.

### Phase 3 - Purchasing and Inventory
**Implemented baseline.** Supplier sourcing, purchase needs/RFQs/agreements/orders/confirmations, inbounds/receipts, supplier billing/payment, stock, lots/serials, movements, transfers, adjustments, counts, returns, corrections, reservations, replenishment, warehouses and supporting tools are represented in the guide. Procure-to-Pay, shortage procurement, replenishment, transfer, count/correction and tracked-inventory workflows are published.

### Phase 4 - Accounting
**Implemented baseline.** Accounting Dashboard, chart of accounts, journals, bank statements, periods, AR/AP, bills, supplier payments, expenses, refunds, taxes, financial reports and accounting setup surfaces are covered. AR, AP and period-close workflows provide non-technical cross-module context.

### Phase 5 - CRM, Employees, Support, Maintenance
**Implemented baseline.** Current CRM, employee planning/visit/performance and Support/Maintenance surfaces are covered, including the advanced support configuration workspaces. Lead-to-Sale, customer pricing, employee field sales/performance, support case, maintenance, warranty, field service and equipment lifecycle workflows are published.

### Phase 6 - Reports and Settings
**Implemented baseline.** Reports Center, audit, system users, document/notification templates, custom fields and shared configuration surfaces are covered by the registry-derived screen inventory. Articles explain how to interpret and act on source facts rather than modifying report output.

### Phase 7 - Contextual Help
Keep the global dashboard link pointed at the standalone guide and add stable article-level links from selected complex forms/actions.

### Phase 8 - Mobile Guides
Only after customer and employee apps are production-ready, publish their onboarding, navigation, and task documentation.

## 26. Proposed First Release Scope

The implementation has exceeded the original minimum launch scope: every currently registered Filament Resource page is now represented in generated bilingual screen coverage. The release checklist below remains useful as the editorial/screenshot priority order rather than as a coverage limit.

Minimum launch set:
- User Guide home page
- Getting Started
- Dashboard/navigation
- Search/filter/table basics
- Status basics
- Customer sale end-to-end workflow
- Create/send quotation
- Convert/release sales work
- Fulfillment/shipment
- Create/issue invoice
- Record/understand payment
- Create purchase order
- Receive purchased stock
- Stock transfer
- Stock adjustment
- Customer return / credit / refund overview
- Support ticket basics
- Payment terms
- Currencies
- Users and permissions
- Glossary
- Troubleshooting landing page

## 27. User-Guide UX Requirements

The documentation site should feel like part of IERP, not like developer docs.

Use:
- IERP logo and restrained brand styling;
- clear typography;
- wide readable content area;
- strong search;
- sticky sidebar on desktop;
- mobile-friendly navigation;
- breadcrumbs;
- page table of contents;
- next/previous navigation;
- callout boxes for warning, result, and prerequisite;
- lightweight workflow diagrams;
- large readable screenshots;
- accessible contrast;
- keyboard-accessible navigation.

Avoid:
- code-first visual styling;
- terminal examples in user-facing pages;
- API terminology;
- oversized marketing layouts;
- decorative animation;
- dense technical diagrams.

## 28. Business-Impact Callout Standard

For actions that change money, stock, or lifecycle state, use a standard callout.
Example:

> **What happens when you confirm?**
> The document becomes confirmed and the next workflow step becomes available. If this action changes stock or financial records, the exact user-visible effect is stated here.

Use this pattern for:
- purchase order activation;
- stock receipt completion;
- delivery completion;
- invoice issue;
- payment posting;
- credit note confirmation;
- refund execution;
- stock adjustment completion;
- period closing;
- support billing actions.

## 29. Demo Data Strategy

Create or reuse deterministic demo records for documentation.

Data should include:
- sample customer;
- sample supplier;
- several products and variants;
- at least two warehouses;
- quotation/order/invoice examples;
- purchase order/receipt examples;
- partial payment example;
- return/credit example;
- support/maintenance example;
- employee/visit example.

Never use production data in screenshots.

Demo names should be obviously fictional but realistic enough to explain the workflow.

## 30. Ownership and Maintenance

Recommended ownership model:
- Product/business owner: correctness of business explanation.
- Engineering owner: correctness against current implementation.
- Documentation owner/editor: clarity, screenshots, links, terminology.
- Translator/reviewer: Arabic language quality and terminology consistency.

A feature is not documentation-complete when user-visible behavior changes until the affected guide page has been reviewed.

## 31. Definition of Done for Each Module

A module is documentation-complete when:
- overview exists;
- primary daily tasks are documented;
- important statuses are explained;
- end-to-end workflow links exist;
- financial/inventory impacts are described where relevant;
- screenshots exist for non-obvious interactions;
- common blocking states are documented;
- English version is reviewed;
- Arabic version is reviewed;
- links/build checks pass;
- content was verified against the current release.

## 32. Repository Safety During Implementation

The current working tree may contain unrelated application changes.

When implementing this plan:
- do not reset or overwrite unrelated changes;
- limit guide work to `user-guide/`, guide-specific CI/scripts, and intentional contextual-help integration;
- stage guide changes selectively;
- keep documentation commits focused;
- do not commit generated VitePress output unless the selected deployment platform requires it.

## 33. Proposed Commit Sequence

1. `docs(user-guide): scaffold VitePress site`
2. `docs(user-guide): add onboarding and content standards`
3. `docs(user-guide): document sales workflows`
4. `docs(user-guide): document purchasing and inventory`
5. `docs(user-guide): document accounting operations`
6. `docs(user-guide): document CRM employees and support`
7. `docs(user-guide): document reports and settings`
8. `feat(help): expand contextual user-guide links`
9. `ci(user-guide): validate and build documentation`

Exact commit boundaries may be adjusted, but application changes and user-guide content should remain reviewable.

## 34. Acceptance Criteria for the Overall Project

The user-guide project is successful when:

- A new administrator can find a task without knowing which internal module owns it.
- A new administrator can follow the primary sale workflow without developer assistance.
- A warehouse user can understand receiving, delivery, transfer, and adjustment consequences.
- A purchasing user can follow purchase order to receipt/bill/payment.
- A finance user can understand user-visible invoice/payment/credit/refund behavior.
- Search returns useful task pages using common business terms.
- Every published guide corresponds to implemented behavior.
- No page requires knowledge of Laravel, APIs, database design, or source code.
- English and Arabic navigation work correctly.
- VitePress builds successfully in CI.
- User-guide deployment is independent from ERP deployment.
- The guide can later be split into another repository without changing its content model.

## 35. Recommended Implementation Order

Phases 0 through 6 now have baseline implementation coverage: onboarding, every current dashboard module/surface, current registered Resource pages, module dashboards, status vocabulary, 21 cross-module workflows, bilingual navigation and automated guide validation are present.

The next implementation order is:
1. continue rendered QA and capture for the remaining screenshot positions using deterministic non-production demo data;
2. manually editorial-review the highest-risk commercial/stock/financial/support articles against the live UI and exact labels;
3. add contextual article deep links from selected complex/high-impact dashboard actions;
4. add mobile guide sections only when their production runtime/API exposure is ready.

The generated coverage gate remains the safety net: new dashboard classes/pages must not silently ship without corresponding documentation coverage.
