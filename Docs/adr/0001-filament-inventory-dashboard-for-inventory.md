# ADR 0001: Adopt Filament Admin Dashboard for the Inventory Module

**Status**: Accepted

**Date**: 2026-07-22

**Deciders**: Project Owner

**Related**: historical PRD §10 (removed; Git history), Constitution §"Product Scope & Boundaries", `Docs/domains/inventory/README.md`, `Docs/domains/inventory/README.md`

## Context

The canonical specification set previously listed the Filament dashboard as out of scope in two places:

- **Historical PRD §10 (removed; Git history)** — "Filament dashboard implementation."
- **Constitution → Product Scope & Boundaries** — "a Filament dashboard dependency" among the items out of scope unless the project owner approves an exception in writing.

Meanwhile, Filament has already been adopted in committed code: `AdminPanelServiceProvider`, `AdminModuleRegistry`, `ModulePlaceholder`, and `lang/en/admin.php` exist and define an `inventory` navigation group. historical SDD §15 (removed; Git history) left the dashboard framework unlocked ("API-first design supports React"). The specification text had therefore fallen behind the code, producing a governance conflict flagged in the historical Inventory dashboard plan (removed; Git history).

The constitution's amendment procedure requires recorded project-owner approval before an out-of-scope boundary is changed. This ADR is that record.

## Decision

Adopt Filament as the **System Admin dashboard framework for the Inventory module**, resolving the historical SDD §15 (removed; Git history) open assumption for the admin surface. Specifically:

1. The Filament admin dashboard is **in scope for the Inventory module** (warehouses, locations, stock levels, movements, adjustments, transfers, reservations, and inventory widgets/exports/reports), as recorded by this ADR and now documented in `Docs/domains/inventory/`.
2. The dashboard is a **thin presentation layer** over the existing inventory domain services. It never owns stock, recomputes balances, writes movements directly, hard-deletes records, or forks authorization. Every stock change flows through a domain service that writes a movement and an audit log inside a transaction — exactly as Constitution Principle III (Financial & Inventory Integrity) requires.
3. This approval is **scoped to Inventory only**. Extending the Filament dashboard to other modules (sales, accounting, payments, CRM, etc.) requires a separate ADR.
4. The dashboard remains **System Admin only** by default; employees continue to affect inventory through the employee-app API against the same domain services (Constitution Principle IV; Plan Open Question #7).

## Consequences

- **Historical PRD §10** was updated at the time to qualify the "Filament dashboard implementation" exclusion so it no longer bars the Inventory admin dashboard.
- **The constitution** is amended (version 1.1.0 → 1.2.0) to qualify the Filament out-of-scope entry and reference this ADR; the Sync Impact Report and "Last Amended" date are updated accordingly.
- **`historical Spec Kit 001 (removed; Git history)`** is unblocked: its former "proposal until approved" governance assumption is now satisfied and points to this ADR.
- The general prohibition on a Filament dashboard **dependency for other modules remains in force**; this is a narrow, module-scoped exception, not a blanket reversal.
- Because the decision only sanctions an existing architectural direction and adds no new runtime coupling to core flows, no rollback plan beyond reverting these document edits is required.
