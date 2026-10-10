---
title: "Packages"
module: inventory
pages: ["index", "create", "view", "edit"]
screenshots: ["inventory-package-index", "inventory-package-create", "inventory-package-view", "inventory-package-edit"]
lastVerified: 2026-10-07
---

# Packages

<IerpPageSummary purpose="Maintain physical packing containers for warehouse shipment preparation without directly changing inventory balances." useWhen="Prepare a package, inspect its warehouse and type, or edit a permitted packing record before shipment." owner="Inventory" />

## When to use this page

Prepare a package, inspect its warehouse and type, or edit a permitted packing record before shipment.

## Typical tasks

- Follow the documented “Before you begin” instructions below.
- Follow the documented “Find and prepare a package” instructions below.
- Follow the documented “What changes stock?” instructions below.

## Before you begin

A package must have a **Name**, an active **Package Type**, and an active **Warehouse**. Package types describe reusable packaging definitions; package records represent individual containers. Your permissions determine whether Create, Edit and Delete are available.

## Find and prepare a package

1. Open **Inventory → Outbound → Packages** to review the searchable table, package type, warehouse and active state.
2. Select **Create Package**, enter a meaningful name, choose a package type and warehouse, then save. Creating a package by itself does not receive, reserve, dispatch or transfer stock.
3. Open **View** to confirm the saved package identity and its warehouse before assigning it to an operation.
4. Use **Edit** to maintain the name, type and active state if your role allows. The warehouse field is locked when the package is already referenced by recorded work.

## What changes stock?

**A package association is not a physical stock movement.** Physical quantities change only through confirmed inventory operations. Package assignment must not alter on-hand, reserved or available quantities. A package used by operation lines or movements cannot be deleted normally; do not bypass these protections or move a referenced package to another warehouse by directly editing it.

## When a page looks empty

An empty Packages list means no matching package records are visible under the current filters and permissions. A demo screenshot of View or Edit must use a genuine saved package linked to an active type and warehouse. Do not treat an HTTP response or an empty form as proof of a completed lifecycle.

## Screens

<IerpScreenshot id="inventory-package-index" title="Package list" description="Find packages by name, type and warehouse; review the active state." path="Inventory → Outbound → Packages" />

<IerpScreenshot id="inventory-package-create" title="Create package" description="Choose an active package type and warehouse before saving." path="Inventory → Outbound → Packages → Create" />

<IerpScreenshot id="inventory-package-view" title="Package details" description="Inspect an actual saved package and its warehouse association." path="Inventory → Outbound → Packages → View" />

<IerpScreenshot id="inventory-package-edit" title="Edit permitted package" description="Review editable fields and the warehouse movement guard." path="Inventory → Outbound → Packages → Edit" />
