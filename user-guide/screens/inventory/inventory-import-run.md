---
title: "Catalog Imports"
module: inventory
pages: ["index"]
screenshots: ["inventory-inventory-import-run-index"]
lastVerified: 2026-10-07
---

# Catalog Imports

<IerpPageSummary purpose="Audit inventory import attempts, diagnostics and source rows." useWhen="Review, filter or act on the corresponding Inventory records according to stage and permission." owner="Inventory" />

## When to use this page

Review, filter or act on the corresponding Inventory records according to stage and permission.

## Typical tasks

- Follow the documented “Operator tasks” instructions below.
- Follow the documented “Business rules and troubleshooting” instructions below.
- Follow the documented “Screen references” instructions below.

## When to use
Audit inventory import attempts, diagnostics and source rows.

## Operator tasks
1. Open the Inventory navigation workspace and verify your current language, warehouse scope and selected record.
2. Use the list search, status filters or an appropriate detail tab to find the correct document.
3. Review quantities, units, source links, stage, ownership and permitted actions before editing.
4. Confirm the resulting record state and check stock movements after any separately authorized physical posting.

## Business rules and troubleshooting
Investigate failed rows and duplicates before retrying; successful imports do not imply a completed stock operation.

If the action is missing, check your role and the record lifecycle. Blank tables may reflect filters or missing demo records. A rendered route is not proof that a physical workflow has been completed.

## Screen references

<IerpScreenshot id="inventory-inventory-import-run-index" title="Catalog Imports — List page" description="Audit inventory import attempts, diagnostics and source rows." path="Inventory" />
