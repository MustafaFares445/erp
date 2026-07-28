# Contract: Packages and Media

**Feature**: `specs/014-inventory-erp-rework` | Covers US3 and US4, FR-026 → FR-035

---

## Part A — Media

### Approach

Filament's native `FileUpload` and `ImageColumn`, wired to `spatie/laravel-medialibrary` in the
model layer. **No new Composer dependency** — `filament/spatie-laravel-media-library-plugin` is
not installed and adding it needs project-owner approval ([R-004](../research.md)).

`spatie/laravel-medialibrary ^11.23` is already required and `config/media-library.php` is already
published, but **no model implements `HasMedia` today**. This is greenfield, and it closes a
standing omission against Constitution Principle IV.

### Model contract

| Model | Implements | Collection | Behaviour |
|---|---|---|---|
| `Product` | `HasMedia` + `InteractsWithMedia` | `images` | Single-file main image plus ordered gallery |
| `ProductVariant` | `HasMedia` + `InteractsWithMedia` | `images` | Same; empty falls back to the parent product's main image |

A `thumb` conversion is registered for list rendering.

### Form contract

`FileUpload::make('images')` configured `->image()->multiple()->reorderable()->appendFiles()`
with a size cap and accepted mimes. Because the media-library plugin is absent, the page needs an
explicit save hook translating field state into media-collection operations — add, reorder,
remove. **This hook is the one hand-written seam in the media work and must be covered by tests**:
a save that adds, a save that reorders, a save that removes, and a save that changes nothing.

### Guarantees

- **G-1** First image in collection order is the main image (FR-026).
- **G-2** Lists render the main image; a variant with no image shows its parent's (FR-027).
- **G-3** Reorder and remove propagate everywhere the product appears (FR-028).
- **G-4** Unsupported mime or oversize upload is rejected naming the reason, and **existing images
  are left intact** (FR-029). A failed upload must never partially mutate the collection.
- **G-5** No feature-specific file table is created (FR-030, Principle IV).

### Security

Filament's documentation notes that `InteractsWithSchemas` exposes Livewire's upload RPC methods
on every page using it, whether or not that page has an upload field. Pages hosting uploads
should use the `RestrictsFileUploadsToSchemaComponents` trait so uploads to arbitrary property
paths are rejected.

---

## Part B — Packages

### Screens

| Screen | Menu | Contents |
|---|---|---|
| Packages | Products | Name · Package Type · Warehouse · Location |
| Package Types | Configurations | Name · Code · Active |

A Package column is added wherever stock or movement lines are listed: operation lines,
adjustment lines, quantity views and move lines.

### Guarantees

- **G-6** A package **never** holds a balance. There is no quantity column, and adding one is out
  of scope (FR-034, [R-005](../research.md)). Balances remain solely on `inventory_stocks`, keyed
  by product variant and warehouse.
- **G-7** Every package foreign key is nullable and additive. With no package set anywhere, every
  balance, query and report behaves exactly as before this feature.
- **G-8** Deleting a referenced package or package type is refused, and the referencing records
  are named (FR-035).
- **G-9** A package's location must belong to its warehouse; a line's package must belong to the
  warehouse the line affects (V-11, V-15).
- **G-10** Moving a package between warehouses while goods reference it is refused unless the
  goods move with it as a recorded movement (spec Edge Cases).

### Explicitly excluded

- **Company attribution.** The reference screenshots show a Company column on Packages. This
  application has no multi-company concept and introducing one is out of scope (A-004).
- **Packaging as a unit of measure.** SRS §3.5 treats a box or pack as a unit of measure, which
  the existing `Unit` model already covers. Packages are physical containers, a different concept.
  Neither replaces the other.

### The test that matters most

A test proving balances are **identical** with and without packages attached. It is the guard
against G-6 eroding into a second source of quantity truth, which is exactly the drift
Constitution Principle III exists to prevent.
