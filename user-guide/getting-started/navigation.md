---
title: Dashboard Navigation
lastVerified: 2026-10-07
---

# Dashboard Navigation

The IERP Desktop Edition is a dense ERP workspace. The shell stays consistent as you move between modules.

<IerpScreenshot id="global-dashboard-shell" title="IERP dashboard shell" description="The topbar, module switcher, sidebar, page header and content workspace." path="Admin dashboard" />

## Topbar

The topbar stays visible while you work. It contains:

- the IERP brand;
- the **module switcher**;
- global search where supported;
- language/locale controls;
- appearance controls;
- notifications and user menu items provided by the panel.

## Module switcher

On wide screens, modules appear as topbar tabs. Below the desktop breakpoint, the same module list collapses into a dropdown so the topbar does not wrap.

Changing module does **not** change the meaning of shared records. It changes the active workspace and sidebar to the section responsible for the next task.

<IerpScreenshot id="global-module-switcher" title="Module switcher" description="The topbar tabs used to move between Sales, Accounting, Inventory, Purchasing, CRM, Employees, Support, Reports and Administration." path="Topbar" />

## Module sidebar

The sidebar is scoped to the active module. Items are grouped by business purpose, for example Sales can separate Selling, Fulfillment, Billing and Reports.

Some sidebar entries are **workspaces**. Opening one workspace can reveal tabs such as Stock Levels, Products, Lots, Serials and Stock Movements without creating five unrelated navigation groups.

<IerpScreenshot id="global-sidebar" title="Module sidebar" description="Section groups and workspace links for the active module." path="Active module → Sidebar" />

## Global search

Global search only finds resources that explicitly expose searchable attributes. Typical searchable values include:

- document number;
- customer or supplier name/code;
- contact values;
- notes where enabled.

Search is a navigation aid; it does not replace resource-specific table filters.

<IerpScreenshot id="global-search" title="Global search" description="Search across resources that expose globally searchable business attributes." path="Topbar → Search" />

## Page header

A page header usually shows:

- page/resource title;
- breadcrumbs/context;
- status where relevant;
- primary action;
- secondary actions;
- workflow transitions.

IERP uses one visually obvious primary action. Destructive actions are intentionally softer until the confirmation dialog.

## Language and RTL

The dashboard supports English and Arabic. Arabic uses RTL layout and the same semantic component structure; direction-specific icons and text arrows are mirrored appropriately.

## Light and dark mode

Dark mode is a neutral graphite palette. Blue remains an accent for active navigation, primary actions and informational state; surfaces themselves do not become blue.

## Continue

Next: [Tables, Search & Filters](/getting-started/tables-search-filters).
