# Contract: Product Record

**Feature**: `specs/014-inventory-erp-rework` | Covers US2, FR-019 → FR-025

---

## Structure

`ProductResource` gains `getRecordSubNavigation()`, rendering the horizontal tab bar. Each tab is
a registered page with a `/{record}/...` route, so every tab is deep-linkable
([R-003](../research.md)).

| Tab | Page type | Relation | Notes |
|---|---|---|---|
| View | `ViewRecord` | — | Summary infolist |
| Edit | `EditRecord` | — | Hosts the media field (see [packages-and-media.md](./packages-and-media.md)) |
| Attributes | `ManageRelatedRecords` | `productAttributeValues` | |
| Variants | `ManageRelatedRecords` | `variants` | Replaces the standalone resource |
| Vendors | `ManageRelatedRecords` | `supplierProductReferences` | Price columns permission-gated |
| Quantities | `ManageRelatedRecords` | `stocks` | Read-only; reuses `StockLevelsTable` |
| IN/OUT | `ManageRelatedRecords` | `movements` | Read-only; reuses `StockMovementsTable` |

These relations must **not** also appear in `getRelations()` — Filament treats relation pages and
relation managers as alternatives, and registering both renders each twice.

---

## Guarantees

- **G-1** Every tab is scoped to the open product. No tab can display another product's rows.
- **G-2** Quantities shows on-hand, reserved, available, in-transit and damaged per warehouse,
  plus a total row (FR-022, SRS §3.10).
- **G-3** Vendors lists every external reference against this one product — supplier, supplier
  product name and number, country, purchase price, currency (FR-023, SRS §3.6). This is the
  screen that makes "one product, many supplier names" visible, so it must never paginate a
  supplier out of view without an obvious control.
- **G-4** Purchase price, unit cost and markup are hidden without the pricing-view permission,
  and the rest of the tab stays usable (FR-024, SRS §5.3).
- **G-5** Quantities and IN/OUT eager-load their relations. Neither may issue a query per row.
- **G-6** Variants deletion is refused where the variant holds stock or appears in movements,
  matching the existing rule that used records are never removed.

---

## Read-only tabs

Quantities and IN/OUT are strictly read-only here. Stock is changed through operations and
adjustments only, never by editing a balance from a product tab. This also keeps the tabs inside
the `ArchTest` allowlist, since they reuse the already-excepted `StockLevels` and `StockMovements`
table definitions rather than introducing a new namespace that touches `InventoryStock`.

---

## Retiring the variants page

- `ProductVariantResource` is removed from `AdminModuleRegistry`, so it stops appearing in
  navigation.
- Its routes stay registered and redirect to the parent product's Variants tab
  (FR-021, acceptance scenario 2.3).
- The resource class and its table and schema definitions are **not** deleted — the Variants tab
  reuses them (A-006).
- Global search entries for variants continue to resolve, landing on the parent product's
  Variants tab.

---

## Cross-product screens that stay

Stock Levels and Stock Movements remain top-level under Reporting. A per-product tab cannot
answer "what is in this warehouse" or "show me every movement last month", which SRS §3.10 and
§3.14 require (FR-025). The product tabs are a filtered view of the same data, not a replacement.
