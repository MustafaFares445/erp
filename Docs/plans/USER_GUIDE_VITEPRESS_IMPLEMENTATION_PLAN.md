---
status: active-plan
owner: product
last_verified: 2026-10-02
---

# IERP Non-Technical User Guide Implementation Plan

## 1. Purpose

Build a public-facing, non-technical IERP user guide inside the existing repository.

The guide will teach administrators and operational users how to complete real business tasks in IERP without exposing implementation details such as Laravel classes, APIs, database tables, queues, jobs, migrations, or internal service names.

The guide will be a separate VitePress application stored in the same Git repository and deployed independently from the ERP dashboard.

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
│   ├── .vitepress/
│   ├── public/
│   ├── en/
│   └── ar/
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
lastVerified: 2026-10-02
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
After the first manual documentation pass, add an optional repeatable screenshot workflow using local demo/seed data and browser automation.

The automation should:
- boot or target the local application;
- authenticate with a documentation/demo account;
- navigate to deterministic records;
- capture agreed viewport sizes;
- write images to predictable paths;
- avoid destructive production-like actions;
- fail clearly if a selector/label changes.

Do not make screenshot automation a prerequisite for the first guide release.

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
- direct links from contextual help in the ERP later.

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

Phase two should add non-intrusive help links from the dashboard to the user guide.

Examples:
- a Help action in page headers;
- a ? icon near complex concepts;
- a Learn more link in high-impact confirmation dialogs;
- a global User Guide link in the user menu.

Deep links should point to stable guide URLs rather than generic home pages.

Contextual help must not replace concise inline helper text in the application.

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

Add a dedicated CI job triggered when:
- `user-guide/**` changes;
- guide validation scripts change.

CI should:
1. install the guide's Node dependencies;
2. run content validation;
3. run VitePress build;
4. fail on broken links or build errors;
5. optionally upload the static build as an artifact.

The user-guide build must not be required to boot Laravel or connect to the database.
## 23. Deployment Plan

Deploy the built VitePress site separately from the ERP application.

Recommended public shape:

```text
ERP dashboard:      https://erp.example.com
User documentation: https://docs.example.com
```

Deployment options may include a static host, CDN-backed object storage, GitHub Pages, Cloudflare Pages, Netlify, Vercel, or the existing hosting stack.

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
- create `user-guide/`;
- install VitePress;
- configure English/Arabic locales;
- configure theme and branding;
- create navigation;
- create article templates;
- create validation rules;
- add CI build.

### Phase 1 - New User Onboarding
Create Getting Started, glossary, status explanation, navigation, tables/search/filtering, common actions, and help pages.

### Phase 2 - Core Revenue Flow
Document:
- customers;
- quotations;
- sales orders;
- fulfillment/shipment;
- invoices;
- payments;
- credit notes;
- customer returns/refunds;
- complete customer-sale workflow.

This is the highest-priority operational section.

### Phase 3 - Purchasing and Inventory
Document suppliers, purchase orders, confirmations, receipts, supplier billing/payment, stock levels, movements, transfers, adjustments, counts, and purchasing-shortage workflows.

### Phase 4 - Accounting
Document the accounting UI from a user perspective, including AR/AP, journal entries, tax behavior, period controls, and reports.
### Phase 5 - CRM, Employees, Support, Maintenance
Document daily operational workflows and cross-links to Sales/Accounting where those processes produce commercial consequences.

### Phase 6 - Reports and Settings
Document report interpretation, export behavior, business configuration, users/permissions, currencies, taxes, units, payment methods, and document templates.

### Phase 7 - Contextual Help
Add stable User Guide links from the dashboard and selected complex forms/actions.

### Phase 8 - Mobile Guides
Only after customer and employee apps are production-ready, publish their onboarding, navigation, and task documentation.

## 26. Proposed First Release Scope

The first useful release does not need every screen.

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
### Phase 5 - CRM, Employees, Support, Maintenance
Document daily operational workflows and cross-links to Sales/Accounting where those processes produce commercial consequences.

### Phase 6 - Reports and Settings
Document report interpretation, export behavior, business configuration, users/permissions, currencies, taxes, units, payment methods, and document templates.

### Phase 7 - Contextual Help
Add stable User Guide links from the dashboard and selected complex forms/actions.

### Phase 8 - Mobile Guides
Only after customer and employee apps are production-ready, publish their onboarding, navigation, and task documentation.

## 26. Proposed First Release Scope

The first useful release does not need every screen.

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
8. `feat(help): add contextual user-guide links`
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

Start with Phase 0 and Phase 1, then document the complete customer-sale workflow before expanding module-by-module.

The guide should become useful early rather than waiting for every module to be documented.

The first implementation task after approving this plan is to scaffold `user-guide/` as an isolated VitePress package and create the bilingual navigation/content templates without changing application behavior.
