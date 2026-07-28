# Phase 0 Research: Warehouses, Locations & Read-Only Stock Visibility

All unknowns from the Technical Context are resolved below. Each entry records the decision, the rationale, and the alternatives rejected. This phase builds on FI-0's research (Filament v5, panel gate, permission catalogue, policy trait, action adapter) and does not re-litigate those.

## R1. Reconciling the FI-0 "no direct stock writes" architecture guard with read-only resources

- **Problem**: FI-0 added a Pest `arch()` expectation that classes in `App\Filament` must **not** use `App\Models\InventoryStock` or `App\Models\InventoryMovement` (FI-0 research R7, SC-004). FI-2's read-only `StockLevelResource` and `StockMovementResource` legitimately reference those models (as their `$model` and in queries) purely to **read** them. Left unchanged, the guard would fail the build the instant these resources are created — a false positive, because reading a ledger is not the danger the guard exists to prevent.
- **Decision**: **Refine** the guard so its intent — *no Filament code path writes stock balances or the movement ledger* — is expressed precisely:
  1. Keep the blanket "must not use `InventoryStock`/`InventoryMovement`" ban for **every** `App\Filament` class **except** the two sanctioned read-only namespaces `App\Filament\Resources\StockLevels` and `App\Filament\Resources\StockMovements` (and their `Pages`/`Tables`/`Schemas` children).
  2. Independently prove those two resources cannot write, via feature tests asserting `canCreate() === false`, no `EditAction`/`DeleteAction`/bulk-delete is registered, and the policies deny `create`/`update`/`delete` (R5). "No write actions present" + "policy denies writes" together guarantee no write path, which pure namespace arch cannot express on its own.
- **Rationale**: The FI-0 guard was deliberately broad because *no* Filament class needed those models yet; FI-0 research explicitly anticipated "the first Filament class to import one will fail the build." That trip-wire did its job — it forces this conscious refinement now, rather than silently allowing a write. Scoping the exception to exactly the read-only surfaces (which by construction have no write actions) keeps the protection everywhere a write could actually be introduced (adjustments/transfers in FI-3/FI-4 live in *other* namespaces and remain fully banned from touching these models directly — they must go through services).
- **Alternatives rejected**:
  - *Delete the guard* — loses the no-write protection entirely; fails Principle III enforceability and SC-004's spirit.
  - *Leave the guard unchanged and avoid referencing the model* (e.g. raw query builder / DB facade in the resources) — obfuscates legitimate reads, loses Eloquent relationships/casts, and is a worse design chosen only to dodge a test.
  - *Rewrite the guard to detect write method calls (`save`/`create`/`update`/`delete`)* — Pest `arch()` operates on namespace/dependency graphs, not call-site semantics; it cannot reliably distinguish a read query from a write. The namespace-exception + behavior-test combination is the honest, enforceable expression.

## R2. No `AdminModuleRegistry` edit required

- **Decision**: Do **not** modify `AdminModuleRegistry`. Create the resource classes at the exact namespaces it already imports: `App\Filament\Resources\Warehouses\WarehouseResource`, `App\Filament\Resources\StockLevels\StockLevelResource`, `App\Filament\Resources\StockMovements\StockMovementResource`.
- **Rationale**: The registry already declares all three classes and the panel `discoverResources()` auto-registers them; `resolveLink()` returns a real URL as soon as the class exists, is a Filament `Resource`, and passes `canAccess()` (plan §1.2). Placeholders are replaced automatically. Only reservations/returns (FI-5) would require a registry edit, and those are out of scope here.
- **Alternatives rejected**: Adding explicit navigation entries — redundant with auto-discovery and risks duplicate sidebar items (the registry's `navigationItems()` already skips items whose class resolves).

## R3. Backend models & migrations ownership (self-contained)

- **Decision**: This feature creates the four inventory models + engine-agnostic migrations (`warehouses`, `warehouse_locations`, `inventory_stocks`, `inventory_movements`) faithfully to ERD §6, plus a minimal `product_variants` (+ parent `products`) catalog stub, and warehouse validation. **No inventory domain service is created.**
- **Rationale**: Project-owner decision (plan Complexity Tracking). FI-1 is pure master-data CRUD and FI-2 is read-only — neither performs a stock mutation, so the service layer (`InventoryMovementService` et al.) is genuinely not needed until the FI-3/FI-4 write flows. ERD §6 finalizes the table design, satisfying Principle I. Models are plain Eloquent (relationships, casts, soft deletes) with no business logic, honoring Principle II's "business rules in services" (there are none to place).
- **Catalog stub bound**: `product_variants` carries only the columns needed now — `id`, `product_id` (FK), `sku`, `name`, `is_active`, timestamps, `deleted_at` — and a minimal `products` parent (`id`, `name`, `is_active`, timestamps, `deleted_at`) to satisfy the NOT NULL FK. Attributes, values, pricing tiers, categories, and product files are **out of scope** and owned by catalog spec 005, which extends these tables additively.
- **Alternatives rejected**: Nullable/omitted variant FK (deviates from ERD §6); full catalog build (scope explosion beyond FI-1/FI-2); waiting for spec 005 (blocks the whole track — see plan Complexity Tracking).

## R4. `available_quantity` is a stored column, not computed at read time

- **Decision**: Treat `available_quantity` as the stored `decimal(15,3)` column defined in ERD §6 (`inventory_stocks.available_quantity`, "Computed available quantity"). The read-only stock view **displays** it; it does not recompute `on_hand − reserved` on the fly.
- **Rationale**: ERD makes it a persisted column that the (future) domain services maintain on every stock change. Recomputing in the read model would (a) duplicate service logic in the presentation layer (Principle II violation) and (b) risk divergence from the service-maintained value. Reading the stored column is the correct read-model behavior and keeps the "no balance computation in Filament" rule intact.
- **Low-stock rule**: flag when `reorder_level IS NOT NULL AND available_quantity <= reorder_level`. `reorder_level` is nullable (ERD) — a null threshold means "no reorder configured," so such rows are **never** low-stock. The boundary is inclusive (`<=`), per spec edge case.
- **Alternatives rejected**: Computing available at render time — see rationale; treating null `reorder_level` as 0 — would falsely flag every zero-available row as low-stock.

## R5. Three policies delegating to the FI-0 trait

- **Decision**: Add `WarehousePolicy`, `InventoryStockPolicy`, `InventoryMovementPolicy`, each `use`-ing `App\Policies\Concerns\ChecksInventoryPermissions` (FI-0) and implementing `inventoryPermissionMap()`:
  - **WarehousePolicy**: `viewAny`/`view` → `inventory.warehouse.view`; `create`/`update`/`restore` → `inventory.warehouse.manage`; `delete` → `inventory.warehouse.manage` **and** a guard returning `false` when the warehouse is referenced by any `inventory_stocks` or `inventory_movements` row (blocks removal, offers deactivation instead — FR-005); `forceDelete` → `false` (no hard delete — FR-006).
  - **InventoryStockPolicy**: `viewAny`/`view` → `inventory.stock.view`; `create`/`update`/`delete`/`restore`/`forceDelete` → `false` (deny-by-default via the trait's unmapped-ability rule — realizes read-only, FR-010).
  - **InventoryMovementPolicy**: `viewAny`/`view` → `inventory.movement.view`; every write ability → `false` (immutable ledger, FR-015).
- **Rationale**: Reuses the FI-0 pattern verbatim (Principle IV, no forked ACL). Deny-by-default for unmapped abilities is exactly how "no delete on ledgers" is achieved without special cases (FI-0 research R5). Filament v5 auto-reads these policies for resource CRUD and navigation visibility (confirmed via docs: resource authorization + action authorization).
- **Warehouse delete guard placement**: the referenced-check lives in the policy's `delete()` (a pure authorization decision that Filament reads to hide/disable the delete action), not in a Filament component — keeping the rule reusable by the future API surface too.
- **Alternatives rejected**: Per-resource ad-hoc `can()` calls (drift); putting the referenced-check only in the Filament UI (not reusable, bypassable by API); Filament Shield (unapproved dependency).

## R6. Read-only resource mechanics in Filament v5

- **Decision**: For `StockLevelResource` and `StockMovementResource`: override `canCreate(): bool => false`; register **no** `CreateAction`/`EditAction`/`DeleteAction`/`BulkAction` (delete); provide only a `ViewAction` and a `View` page (infolist for movements). Movements use an **infolist** (`Filament\Schemas\Schema`) on the view page rather than a disabled form (confirmed via v5 docs "Using an infolist instead of a disabled form"). The per-warehouse `StockLevelsRelationManager` relies on Filament v5's default **read-only mode** on the View/Edit page (docs confirm relation managers hide create/edit/delete by default on the View page; we keep that default and add none).
- **Rationale**: Matches the framework's sanctioned read-only patterns; avoids fighting the framework. Infolists are the idiomatic read-only detail surface and are directly testable (`assertSchemaStateSet`, `assertOk`).
- **Alternatives rejected**: Disabled forms for detail (less clear intent, still form-shaped); custom Blade views (reinvents infolist, harder to test).

## R7. Movement type as a backed enum

- **Decision**: Introduce `App\Enums\MovementType { Sale, Return, Adjustment, Transfer, Reservation }` (backed string), used to render the `movement_type` column as a colored badge and to power the type filter. `inventory_movements.movement_type` stays a `varchar(50)` column (ERD §6) cast to the enum on the model.
- **Rationale**: TitleCase enum keys per PHP guidelines; backed enum satisfies 100% type coverage and gives a single source for the badge/filter option list. ERD keeps the column a string for portability; the enum is an application-layer convenience, matching how FI-0 handled `UserType`.
- **Alternatives rejected**: Free-string handling in the UI (no type safety, drift in option lists); a DB-native enum column (reduces engine portability — same reasoning as FI-0 R10).

## R8. Signed quantity display

- **Decision**: `inventory_movements.quantity` is a signed `decimal(15,3)` (ERD: "Positive or negative movement"). Display it with an explicit sign and color (increase vs. decrease) in the table via a formatted text column; do not store a separate direction flag.
- **Rationale**: ERD already encodes direction in the sign, so a derived presentation (sign + color) is a pure display concern with no data duplication. Satisfies FR-016 / spec US3 scenario 2.
- **Alternatives rejected**: A separate `direction` column (redundant with the sign, not in ERD).

## R9. `created_by` / `updated_by` population

- **Decision**: Populate `created_by`/`updated_by` on `warehouses` and `warehouse_locations` from the authenticated user. Use a small reusable mechanism (a model `booted()` hook or a shared `TracksBlameable` trait setting the columns from `auth()->id()` on `creating`/`updating`). `inventory_stocks` has no blame columns (ERD); `inventory_movements` blame columns are written by the future services, not here.
- **Rationale**: Keeps the audit-adjacent columns accurate for master data without a Filament-specific hack, and reusable by the API. Master-data authorship is not a "sensitive stock action," so no `audit_logs` entry is due (Principle VI scopes audit to sensitive actions; none here).
- **Alternatives rejected**: Setting blame columns only in the Filament form (not reusable, bypassable); leaving them null (loses authorship the ERD provides for).

## R10. Internationalization scope (unchanged posture from FI-0)

- **Decision**: Add English attribute/column label keys for warehouses, stock levels, and movements to `lang/en/admin.php` (the `admin.resources.*` group labels already exist). Do **not** restore `lang/ar/admin.php`.
- **Rationale**: Consistent with FI-0 R9 — Arabic restoration (Open Question #8) is a cross-cutting task spanning every phase's keys and would exceed this feature's scope and the "small, reviewable change" rule.
- **Alternatives rejected**: Restoring `lang/ar` now (scope creep); hard-coding English strings in components (bypasses the existing i18n structure).

## R11. Database engine (unchanged from FI-0)

- **Decision**: Engine unconfirmed (Open Question #6); write all migrations with engine-agnostic Blueprint methods (`decimal(15,3)`, `string()`, `boolean()`, `foreignId()->constrained()`, `softDeletes()`, `unique([...])`), test on SQLite as the repo does.
- **Rationale**: Filament/Eloquent migrations are engine-agnostic for these shapes; locking the engine is out of scope. `decimal(15,3)` and composite unique indexes behave consistently across MySQL/PostgreSQL/SQLite.
- **Alternatives rejected**: Engine-specific types (reduces portability, complicates the pending engine decision).

## Resolved dependencies summary

- **Now satisfied within this feature**: inventory models/migrations (warehouses, locations, stocks, movements), a minimal catalog stub (product_variants + products), warehouse validation, factories, policies — all owned here per the project-owner decision.
- **Still deferred (correctly out of scope)**: inventory **domain services** (FI-3/FI-4 write flows), full catalog (spec 005), adjustments/transfers/reservations/returns (FI-3–FI-5), widgets/exports (FI-6), `lang/ar` restoration (Open Question #8), engine lock (Open Question #6).
- **Inherited, unchanged**: FI-0 panel gate, `inventory.*` permission catalogue + seeder, `ChecksInventoryPermissions` trait, `InteractsWithInventoryServices` adapter (unused here — no write actions yet), and the (now refined) architecture guard.
