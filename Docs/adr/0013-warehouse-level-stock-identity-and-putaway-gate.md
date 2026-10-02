# ADR 0013: Warehouse-Level Stock Identity and Putaway Gate

**Status**: Accepted

**Date**: 2026-10-03

**Deciders**: Project Owner

**Related**: `Docs/adr/0012-origin-domain-owns-business-facts.md`, `Docs/architecture/DATA_ARCHITECTURE.md`, `database/migrations/2026_07_28_042305_remove_warehouse_locations_from_inventory.php`

## Context

The Inventory domain previously contained `warehouse_locations` and `warehouse_location_id` references across movements, lots, serialized units, adjustments, operation lines, and packages. Migration `2026_07_28_042305_remove_warehouse_locations_from_inventory.php` deliberately removed that physical-location identity from the active data model.

Current stock, reservation, lot/serial, package, count, receipt, delivery, transfer, and replenishment workflows therefore reason at **warehouse custody** level. Reintroducing a Putaway UI or a location selector without restoring location to the canonical stock identity would create a second physical truth that Inventory services do not enforce.

AureusERP-style putaway rules are useful only when locations are first-class custody dimensions. Copying the UI while leaving IERP warehouse-level would violate ADR 0012 because the UI would appear to own a routing fact that canonical Inventory balances and movements do not own.

## Decision

IERP keeps **warehouse-level stock identity** for the current architecture. Putaway and bin/location routing are explicitly gated and must not be implemented as a standalone UI feature.

The following remain intentionally absent until this ADR is amended:

- `warehouse_locations` as an active operational table;
- `warehouse_location_id` on stock-affecting records;
- putaway-rule configuration and automatic location assignment;
- location-level availability, reservation, replenishment, counting, or transfer reports;
- any Filament location selector that implies physical stock is tracked below warehouse level.

A future location/putaway program must change the underlying custody model first and ship as one coherent migration/program, not as incremental convenience fields.

## Required Preconditions for Reintroduction

Before location support can be accepted, a replacement design must define and test all of the following together:

1. **Stock identity** — whether balances are keyed by warehouse + location + variant + condition and how lot/serial grains participate.
2. **Reservations** — whether reservations bind to a location immediately or only during picking, including concurrency and reallocation rules.
3. **Receipts and putaway** — receiving custody versus final storage custody, partial putaway, and whether dock/staging locations are materialized.
4. **Deliveries and picking** — source-location allocation, FEFO/lot/serial constraints, and shortage behavior.
5. **Internal transfers** — warehouse-to-warehouse versus intra-warehouse moves and when custody changes.
6. **Packages** — whether a package owns one location, can contain mixed-location contents, and what moving a package implies.
7. **Physical counts** — count scope, blind-count sheets, serial/lot grains, and variance posting at location level.
8. **Replenishment** — distinction between warehouse replenishment and bin replenishment, including min/max policies.
9. **History and migration** — deterministic backfill for all existing warehouse stock into initial locations without inventing false historical precision.
10. **Permissions and audit** — who may configure locations, override putaway, and move stock intra-warehouse.
11. **Reporting** — location-level availability, ageing, valuation implications, and reconciliation with warehouse totals.
12. **Architecture tests** — every stock mutation must still pass through canonical Inventory services; no resource/controller may write balances or movements directly.

## Enforcement

Until this ADR is amended, architecture tests assert that `warehouse_locations` does not exist and that canonical stock-affecting tables do not regain a `warehouse_location_id` column. A future accepted location program must update this ADR and the tests in the same change set.

## Consequences

**Positive.** Inventory has one physical truth: warehouse custody. Barcode, receipts, deliveries, transfers, counts, reservations, lots/serials, and packages cannot disagree with a decorative location layer.

**Negative.** Advanced WMS capabilities such as directed putaway, bin picking, zone replenishment, and bin-level cycle counts remain unavailable until the larger custody redesign is approved and implemented.

**Trade-off.** This deliberately prefers a smaller coherent inventory model over a superficially richer UI whose location data would not be enforced by stock accounting.
