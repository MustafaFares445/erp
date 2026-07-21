# Filament Inventory Dashboard Plan

## 0. Scope Note and PRD Conflict (Read First)

**This document deliberately adds scope that PRD.md currently lists as Out of Scope.**
PRD.md §10 ("Out of Scope") explicitly states *"Filament dashboard implementation."* This
plan intentionally supersedes that line **for the Inventory module only**. It is a
conscious scope addition, not an oversight and not a silent override.

Two facts justify treating this as a formal change rather than a rule break:

1. The codebase has **already adopted Filament v5** — `AdminPanelServiceProvider`,
   `AdminModuleRegistry`, `ModulePlaceholder`, and `lang/en/admin.php` are committed and
   define an `inventory` navigation group. The PRD text simply has not caught up with the
   code.
2. SDD.md §15 ("Assumptions") says the *"Dashboard framework is not locked, but API-first
   design supports React."* Choosing Filament resolves that open assumption for the admin
   surface.

> **Action required before implementation:** Update PRD.md §10 (remove or qualify the
> "Filament dashboard implementation" exclusion) and record the decision as a short ADR /
> change request. Until that happens, this plan is a *proposal grounded in existing code*,
> not an approved deviation. See [§10 Open Questions and PRD Conflicts](#10-open-questions-and-prd-conflicts).

**Module boundary for this plan.** Only the Inventory domain is covered: warehouses and
locations, product-variant stock, movements, adjustments, transfers, and reservations
(ERD groups *Inventory*). Products/variants (catalog), Sales, Accounting, and Payments are
treated as **related, read-only references** — a Filament Resource may *link to* a delivery
note or credit note, but must never reach into another module's write logic. Catalog
resources (`ProductResource`, `ProductVariantResource`), although the registry places them
in the same sidebar group, are **catalog prerequisites**, not part of this inventory plan.

---

## 1. Grounding in Existing Documentation and Code

| Source | What this plan takes from it |
|---|---|
| PRD.md §9 (Business Rules) | Inventory source of truth is `product_variant_id + warehouse_id`; **every stock-changing operation must create an inventory movement**; confirmed financial/inventory documents must not be physically deleted; System Admin and Employee are the inventory actors. |
| SYSTEM_ARCHITECTURE.md §3, §8 | Layering `Controllers → Form Requests → API Resources → Domain Services → Models → Jobs/Events`; Inventory is its own module; export generation is a queue candidate; do not cache stock balances (§9). |
| SDD.md §8 (Technical Compliance) | SOLID where practical, thin controllers, service/action classes, Form Requests, DB transactions for multi-step operations, queue jobs for async work, audit logs for sensitive actions. |
| ERD.md §6 | Table shapes for `warehouses`, `warehouse_locations`, `inventory_stocks`, `inventory_movements`, `inventory_adjustments` (+items), `stock_transfers` (+items), `stock_reservations`, `audit_logs`. Unique `(product_variant_id, warehouse_id)` on `inventory_stocks` (§7). |
| API_CONTRACT.md §8 | Existing `/api/dashboard/inventory/movements` (GET), `/adjustments` (POST), `/transfers` (POST), `/warehouses` (GET/POST). Filament and these endpoints **share the same domain services** — see §2.5. |
| IMPLEMENTATION_PLAN.md §7 | "Products and Inventory Phase" is the backend that this plan sits on top of. This plan reuses its phase format (Goal / Tasks / Dependencies / Acceptance Criteria). |
| COMPONENT_DESIGN.md §2, §6 | "Inventory Screens: Warehouses, stocks, movements, adjustments, transfers"; shared services include an **Inventory movement service**, **Status transition service**, and **Audit log service**. |
| CONFIGURATION.md | `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `FILESYSTEM_DISK=private`; DB engine (MySQL/PostgreSQL) **not yet confirmed**. |
| `app/Filament/AdminModuleRegistry.php` | Canonical, committed list of inventory Resources and their translation-key labels. **Used as the naming authority** below. |
| `app/Providers/Filament/AdminPanelServiceProvider.php` | Single session-auth panel `admin` at `/admin`; auto-discovers Resources/Pages; module-scoped sidebar. |
| `composer.json` | Installed: `filament/filament ^4`, `spatie/laravel-permission ^8`, `spatie/laravel-medialibrary ^11`, `spatie/laravel-data ^4`. **No** activity-log package — audit is the custom `audit_logs` table. |

### 1.1 Naming Reconciliation (Canonical vs Descriptive)

The task brief used descriptive names; the committed `AdminModuleRegistry` already reserves
different class names. **The registry wins** (CLAUDE.md: follow existing conventions). This
table maps them so there is no ambiguity during implementation:

| Task brief name | Canonical class (registry) | Namespace | Backing table |
|---|---|---|---|
| `WarehouseResource` | `WarehouseResource` | `App\Filament\Resources\Warehouses` | `warehouses` (+ `warehouse_locations`) |
| `ProductVariantStockResource` | `StockLevelResource` | `App\Filament\Resources\StockLevels` | `inventory_stocks` |
| `InventoryMovementResource` | `StockMovementResource` | `App\Filament\Resources\StockMovements` | `inventory_movements` |
| `StockAdjustmentResource` | `AdjustmentResource` | `App\Filament\Resources\Adjustments` | `inventory_adjustments` (+items) |
| `StockTransferResource` | `TransferResource` | `App\Filament\Resources\Transfers` | `stock_transfers` (+items) |
| _(returns)_ | `ReturnResource` | `App\Filament\Resources\Returns` | **none yet — see §10** |
| _(reservation views)_ | _(not registered yet)_ | proposed `App\Filament\Resources\Reservations` | `stock_reservations` — **see §10** |

Filament v4 uses nested resource directories (e.g. `app/Filament/Resources/Warehouses/WarehouseResource.php`
with `Pages/`, `Schemas/`, `Tables/`, `RelationManagers/` subfolders). Match the sibling
structure already present under `app/Filament/Resources/`.

### 1.2 How a Placeholder Becomes a Real Resource (No Wiring Needed)

The panel calls `discoverResources(in: app_path('Filament/Resources'), ...)`, and
`AdminModuleRegistry::resolveLink()` returns a real URL as soon as a class exists, is a
Filament `Resource`/`Page`, and passes `canAccess()`. Until then the sidebar item routes to
`ModulePlaceholder`. **Therefore: creating the Resource class + policy is sufficient — the
sidebar link, module scoping, and placeholder replacement happen automatically.** Only
reservations require touching the registry itself (§10).

---

## 2. Cross-Cutting Standards (Apply to Every Phase)

These rules are stated once here and referenced by each phase rather than repeated.

### 2.1 Service-Layer Mandate — Never Bypass the Domain
- Filament Resources, Actions, and RelationManagers **call existing domain services**
  (`InventoryMovementService`, `InventoryAdjustmentService`, `StockTransferService`,
  `ReservationService`, `StatusTransitionService`). They **must not** contain business
  logic, compute stock, or write to `inventory_stocks` / `inventory_movements` directly.
- A Filament page action is a thin adapter: collect input → hand a DTO/array to the service
  → surface the service's result or its domain exception as a UI notification.
- The Filament dashboard and the REST API in API_CONTRACT.md are two front doors onto the
  **same** services, guaranteeing identical rules regardless of surface.

### 2.2 Read Model vs Write Model
- `inventory_stocks` (**StockLevelResource**) and `inventory_movements`
  (**StockMovementResource**) are **read-only** in Filament. No create/edit/delete.
- Stock changes happen **only** through Adjustment, Transfer, Return, and Sales
  (delivery-note) flows, each of which goes through a service that writes the movement
  **and** updates the stock balance inside one DB transaction.
- **PRD invariant enforced in the UI:** because the only write paths are service calls that
  create a movement, there is no Filament path that changes a stock balance without a
  corresponding `inventory_movements` row.

### 2.3 Authorization (spatie/laravel-permission + Policies)
- The `admin` panel is the **System Admin** surface. Gate panel access to `user_type = admin`
  (extend `AdminPanelServiceProvider` auth or `User::canAccessPanel()`), plus granular
  `inventory.*` permissions so a warehouse-operator role can later be granted a subset.
- Every Resource declares a Laravel **Policy**; Filament reads it via `canViewAny`,
  `canCreate`, `canEdit`, `canDelete`, and custom abilities (`confirm`, `transfer`,
  `adjust`, `release`). Reuse the **same policies the API uses** — do not fork authorization
  into Filament.
- **Employee actor distinction (PRD §3):** Employees are inventory actors but operate
  through the **employee app API** (e.g. creating a delivery note during a visit), which
  calls the same services. They do **not** get the Filament dashboard by default. Whether
  any warehouse/employee role is granted dashboard access is an open question (§10).
- Suggested permission set: `inventory.warehouse.view|manage`, `inventory.stock.view`,
  `inventory.movement.view`, `inventory.adjustment.view|create|confirm`,
  `inventory.transfer.view|create|confirm`, `inventory.reservation.view|release`,
  `inventory.export`.

### 2.4 Non-Destructive / Audit Requirements
- **No hard deletes** on inventory records. Ledgers (`StockLevelResource`,
  `StockMovementResource`) expose **no delete action at all**.
- Documents (`AdjustmentResource`, `TransferResource`) may be soft-deleted **only while in
  `draft`**; once `confirmed` they are immutable (edit and delete hidden by policy).
- Audit logging is a **side effect of the service call** into `audit_logs`
  (actor, action, entity, old/new values, `source_channel = dashboard`). Filament must not
  create a second, parallel audit trail.

### 2.5 Validation Reuse
- Reuse the API's Form Request rule sets. Preferred pattern: centralize each operation's
  rules in a `spatie/laravel-data` Data object (already installed) or a static `rules()`
  method, then reference it from **both** the API Form Request and the Filament schema.
  Do not hand-duplicate validation in Filament components.

### 2.6 Queues, Storage, and Caching
- Long-running actions — table exports, bulk/CSV adjustment imports — dispatch **queued
  jobs** (Filament `ExportAction` with a queued `Exporter`), consistent with the
  `database` queue driver. A queue worker must be running.
- Export files are written to the **private** disk and served via signed/authorized
  downloads (SYSTEM_ARCHITECTURE §7).
- **Do not cache stock balances** in widgets or tables (SYSTEM_ARCHITECTURE §9,
  CONFIGURATION §5). Cache only stable lookups (warehouse lists).

### 2.7 Internationalization
- Add label + attribute keys under `lang/en/admin.php` (`admin.resources.*` keys already
  exist for warehouses, stock_levels, stock_movements, transfers, adjustments, returns).
- **`lang/ar/admin.php` is currently deleted** (see git status). PRD NFR "support Arabic
  content" requires restoring it with Arabic strings for every new inventory key.

### 2.8 Runtime Assumptions (from CONFIGURATION.md)
- The panel uses **session auth** (cookies/Livewire), already wired in
  `AdminPanelServiceProvider` — distinct from the token-based API. No change needed.
- DB engine (MySQL vs PostgreSQL) is **unconfirmed**; Filament is engine-agnostic, but the
  choice must be locked before migrations run (PRD §11 open question).

---

## 3. Phase FI-0 — Foundation and Guardrails

### Goal
Establish the inventory panel rails — access control, shared services binding, the
service-only mutation guarantee, and the read/write-model split — before any resource is
built, so no later phase can accidentally bypass the domain layer.

### Filament artifacts
- No user-facing Resource. Panel-level and shared scaffolding only:
  - Panel access gate (`user_type = admin` + `inventory.*` permissions) via
    `User::canAccessPanel()` / panel `authMiddleware`.
  - A thin `InventoryActionConcern` (or form/action helpers) that all inventory Actions use
    to call services and translate `DomainException`/validation failures into Filament
    notifications.
  - Base Policies scaffolding and permission seeding for the `inventory.*` set.

### Business rule enforcement
- Encodes §2.1–§2.4 as reusable building blocks so individual resources inherit them.

### Dependencies
- **Backend IMPLEMENTATION_PLAN.md §7 (Products and Inventory Phase)** must provide:
  models + migrations for all inventory tables; `InventoryMovementService`,
  `InventoryAdjustmentService`, `StockTransferService`, `ReservationService`,
  `StatusTransitionService`; Form Requests / Data objects; and Policies. **This plan cannot
  start until those exist** (today `app/` contains only the `User` model).
- spatie/laravel-permission roles/permissions seeded.

### Acceptance Criteria
- **Given** a user whose `user_type` is not `admin`, **when** they open `/admin`, **then**
  they are denied access to the panel.
- **Given** an admin without the `inventory.stock.view` permission, **when** they load the
  inventory group, **then** stock resources are hidden and their URLs return 403.
- **Given** a Filament inventory Action, **when** it executes, **then** it calls a domain
  service and performs **no** direct write to `inventory_stocks` or `inventory_movements`
  (verified by an architecture/Pest test asserting Filament classes do not reference those
  models for writes).

---

## 4. Phase FI-1 — Warehouses and Locations

### Goal
Deliver master-data management for warehouses and their locations/bins. This is the safe,
no-stock-mutation foundation every other resource references via `warehouse_id`.

### Resource: `WarehouseResource` (`warehouses`)
- **Form fields:** `name` (required), `code` (required, unique), `address` (textarea),
  `is_active` (toggle, default true).
- **Table columns:** `code`, `name`, `is_active` (badge), `locations_count`,
  `stocks_count` (read-only), `created_at`. **Filters:** `is_active`, trashed.
- **Search:** `code`, `name`.
- **Soft deletes:** enabled; deletion blocked (guarded in policy) when stock rows or
  movements reference the warehouse — deactivate instead.

### Relation managers
- `WarehouseLocationsRelationManager` (`warehouse_locations`): fields `name` (required),
  `code`, `is_active`; columns `code`, `name`, `is_active`.
- `StockLevelsRelationManager` (**read-only**, from `inventory_stocks`): variant, on-hand,
  reserved, available, reorder level — a per-warehouse view of Phase FI-2 data.

### Authorization
- `WarehousePolicy` mapped to `inventory.warehouse.view` / `inventory.warehouse.manage`.
  System Admin: full. Warehouse role (if enabled, §10): view + manage own locations.

### Business rule enforcement
- Pure master data — no stock mutation. Enforce unique `code`; block hard delete when
  referenced (§2.4).

### Dependencies
- Phase FI-0. Backend `Warehouse` / `WarehouseLocation` models + `WarehouseRequest` rules.

### Acceptance Criteria
- **Given** an admin with `inventory.warehouse.manage`, **when** they create a warehouse
  with a unique code, **then** it is saved and appears in the inventory sidebar group.
- **Given** a warehouse code that already exists, **when** the admin submits the form,
  **then** validation fails with a field-level error and no record is created.
- **Given** a warehouse that has stock or movement rows, **when** the admin attempts to
  delete it, **then** the delete is blocked and deactivation is offered instead.

---

## 5. Phase FI-2 — Stock Levels and Movements (Read-Only)

### Goal
Give admins accurate, read-only visibility of on-hand/reserved/available stock and the
immutable movement ledger — the read models that all write flows produce.

### Resource: `StockLevelResource` (`inventory_stocks`) — READ-ONLY
- **Table columns:** product variant (SKU + name), warehouse, `on_hand_quantity`,
  `reserved_quantity`, `available_quantity`, `reorder_level`, low-stock indicator
  (`available_quantity <= reorder_level`). **Filters:** warehouse, low-stock only,
  variant/product search.
- **No create/edit/delete.** `canCreate()` returns false; row actions are `view` only.
- **Header actions** deep-link to the sanctioned write paths: "New Adjustment"
  (`AdjustmentResource`) and "New Transfer" (`TransferResource`) — the UI makes clear these
  are the *only* ways to change a balance.
- Respects unique `(product_variant_id, warehouse_id)`.

### Resource: `StockMovementResource` (`inventory_movements`) — READ-ONLY LEDGER
- **Table columns:** `created_at`, product variant, warehouse, `movement_type` (badge:
  sale/return/adjustment/transfer/reservation), `quantity` (signed, colored ±),
  `source_type` + `source_id` (rendered as a read-only reference to the source document),
  `status`, `created_by`, `notes`. **Filters:** movement type, warehouse, variant, date
  range, source type.
- **Immutable:** no create/edit/delete actions anywhere.
- **Infolist (view page):** full movement detail with a link to the source document. When
  the source is a **delivery note / credit note (Sales module)**, render it as a
  **read-only cross-module link** — never an editable relation (§0 boundary).

### Authorization
- `StockPolicy` / `StockMovementPolicy` mapped to `inventory.stock.view` /
  `inventory.movement.view`; both are view-only for all roles.

### Business rule enforcement
- These resources are the *evidence* of §2.2. By exposing no writes, they make it
  impossible to alter a balance or rewrite the ledger from the dashboard.

### Dependencies
- Phase FI-1 (warehouses). Backend `InventoryStock` / `InventoryMovement` models.

### Acceptance Criteria
- **Given** an admin on the stock levels table, **when** they look for a create/edit/delete
  control, **then** none exists and stock can only be changed via adjustments/transfers.
- **Given** a variant whose `available_quantity` is at or below `reorder_level`, **when** the
  low-stock filter is applied, **then** that row is listed and flagged.
- **Given** a movement sourced from a delivery note, **when** the admin opens the movement,
  **then** the source is shown as a read-only link to that delivery note and cannot be
  edited from here.

---

## 6. Phase FI-3 — Stock Adjustments

### Goal
Let admins correct physical stock discrepancies through a draft → confirm workflow where
**confirmation is the sole stock-mutating act**, executed by the domain service inside a
transaction with an audit record.

### Resource: `AdjustmentResource` (`inventory_adjustments` + `inventory_adjustment_items`)
- **Form (draft only):** `warehouse` (required), `adjustment_number` (auto-generated by the
  service — read-only in UI), `reason` (required), plus the items repeater/relation.
- **Table columns:** `adjustment_number`, warehouse, `reason`, `status`
  (draft/pending/confirmed badge), `items_count`, `created_by`, `created_at`.
  **Filters:** status, warehouse, date range.

### Relation manager
- `AdjustmentItemsRelationManager` (`inventory_adjustment_items`): `product_variant`
  (required), `old_quantity` (**read-only**, pre-filled from current stock via the service),
  `new_quantity` (required, decimal ≥ 0), `difference` (**computed/read-only**). Editable
  only while the parent adjustment is `draft`.

### Actions
- **Confirm** (`draft → confirmed`): calls `InventoryAdjustmentService::confirm($adjustment)`,
  which in one transaction writes an `inventory_movements` row (`movement_type = adjustment`,
  `quantity = difference`) per item, updates `inventory_stocks`, and records an audit log.
  The Filament action passes the model to the service and shows success/domain-error
  notifications — it computes nothing itself.
- **Edit / Delete:** available only while `draft` (soft delete). Hidden once `confirmed`.

### Authorization
- `AdjustmentPolicy`: `viewAny/create` → `inventory.adjustment.create`; `confirm` →
  `inventory.adjustment.confirm` (may be a separate approver role — see §10 segregation of
  duties question).

### Business rule enforcement
- Enforces PRD "every stock-changing operation creates a movement" (§2.2) and
  non-destructive rule (§2.4). Confirming is idempotent/guarded by the
  `StatusTransitionService` so a confirmed adjustment cannot be re-confirmed.

### Dependencies
- Phases FI-1, FI-2. Backend `InventoryAdjustmentService`, `Adjustment*` models, adjustment
  Form Request rules.

### Acceptance Criteria
- **Given** a draft adjustment with item lines, **when** the admin confirms it, **then** a
  movement is created and the stock balance changes by exactly the sum of the item
  differences, inside a single transaction.
- **Given** a confirmed adjustment, **when** the admin views it, **then** edit and delete
  actions are absent and the record is immutable.
- **Given** the adjustment service throws a domain error (e.g. inactive warehouse), **when**
  confirm runs, **then** no movement or stock change occurs and the error is shown as a
  notification.

---

## 7. Phase FI-4 — Stock Transfers

### Goal
Move stock between warehouses through a controlled workflow that produces a paired
out/in movement set and validates source availability — never a bare balance edit.

### Resource: `TransferResource` (`stock_transfers` + `stock_transfer_items`)
- **Form (draft only):** `from_warehouse` (required), `to_warehouse` (required, **must
  differ** from source), `transfer_number` (auto-generated — read-only), `notes`, plus the
  items relation.
- **Table columns:** `transfer_number`, from → to (both warehouses), `status`,
  `items_count`, `created_by`, `created_at`. **Filters:** status, from/to warehouse, date.

### Relation manager
- `TransferItemsRelationManager` (`stock_transfer_items`): `product_variant` (required),
  `quantity` (required, decimal > 0). Editable only while `draft`.

### Actions
- **Confirm / Dispatch** (`draft → confirmed`): calls
  `StockTransferService::confirm($transfer)`, which in one transaction creates a **negative**
  movement out of the source warehouse and a **positive** movement into the destination for
  each item, updates both `inventory_stocks` rows, and writes an audit log. The service
  validates `available_quantity >= quantity` at the source and raises a domain error
  (surfaced as a 409-style notification) if not.
- Optional two-step **Dispatch → Receive** is noted as an enhancement (see §10 — the ERD has
  a single `status` field, so the default here is a single confirm).
- **Edit / Delete:** draft only (soft delete). Confirmed transfers immutable.

### Authorization
- `TransferPolicy`: `create` → `inventory.transfer.create`; `confirm` →
  `inventory.transfer.confirm`.

### Business rule enforcement
- Paired movements keep the ledger balanced and honor §2.2; availability check prevents
  negative stock; §2.4 keeps confirmed transfers non-destructive.

### Dependencies
- Phases FI-1, FI-2. Backend `StockTransferService`, `StockTransfer*` models, transfer Form
  Request rules.

### Acceptance Criteria
- **Given** a draft transfer of quantity Q for a variant, **when** it is confirmed with
  sufficient source stock, **then** source available stock decreases by Q, destination
  increases by Q, and two linked movements exist.
- **Given** a transfer whose source has less than Q available, **when** confirm runs,
  **then** it is rejected, no movement is created, and the shortfall is shown to the user.
- **Given** `from_warehouse` equals `to_warehouse`, **when** the form is submitted, **then**
  validation fails before any service call.

---

## 8. Phase FI-5 — Reservations and Returns (Conditional)

### Goal
Surface stock reservations produced by the sales flow for monitoring and manual release, and
(conditionally) handle inbound returns — **both depend on decisions flagged in §10 and
should not start until those are resolved.**

### Reservations — read + release (proposed `ReservationResource`, `stock_reservations`)
- **Status: blocked on a registry decision.** There is currently **no** reservation entry in
  `AdminModuleRegistry` or `lang/en/admin.php`. Implementing it requires adding an item to
  the `inventory` group in the registry plus translation keys (a small, reviewable registry
  change — call it out in the PR).
- **Table columns (read-only):** product variant, warehouse, `quantity`, `source_type` +
  `source_id` (quotation/order/delivery — read-only cross-module link), `expires_at`,
  `status`, `created_by`. **Filters:** status, warehouse, source type, expired.
- **Reservations are created by the Sales flow**, not in the dashboard. The only write action
  is **Release/Expire** → `ReservationService::release($reservation)`, which frees
  `reserved_quantity` (and records the movement/adjustment the service defines) in a
  transaction with an audit log. No manual create/edit.
- **Authorization:** `ReservationPolicy` → `inventory.reservation.view` /
  `inventory.reservation.release`.

### Returns — `ReturnResource` (registry-reserved, **currently unbacked**)
- **Status: blocked on an ERD/backend decision.** `AdminModuleRegistry` reserves
  `ReturnResource`, but **the ERD defines no `returns` table** — returns exist only as
  `movement_type = 'return'` on `inventory_movements` and conceptually relate to
  `credit_notes` (Sales). Options (pick one before building):
  1. Add a dedicated `stock_returns` (+items) table to the ERD/backend, then build a
     draft→confirm resource mirroring adjustments; **or**
  2. Treat "Returns" as a **filtered read-only view** of `StockMovementResource`
     (`movement_type = return`) with no separate resource; **or**
  3. Drive returns from the Sales/credit-note flow and keep only a read link here.
- **Recommendation:** defer `ReturnResource` to a follow-up; ship option (2) as an interim
  read-only view so the reserved sidebar slot is not broken.

### Dependencies
- Phases FI-2 (movements read model). Backend `ReservationService` and, for returns, an
  ERD decision. Registry + lang additions for reservations.

### Acceptance Criteria
- **Given** a reservation created by an accepted quotation, **when** the admin releases it,
  **then** `reserved_quantity` decreases via the service, an audit log is written, and the
  dashboard offers no way to hand-edit the reservation.
- **Given** the returns backing store is undecided, **when** this phase is planned, **then**
  `ReturnResource` remains deferred and the interim returns view is read-only over
  `movement_type = return` (no balance-changing action).

---

## 9. Phase FI-6 — Inventory Widgets, Exports, and Reports

### Goal
Provide at-a-glance inventory health on the dashboard and queued, auditable exports —
without caching stock or duplicating report logic.

### Widgets (on `Dashboard` or an Inventory overview page)
- `LowStockWidget` — count + table of variants where `available_quantity <= reorder_level`.
- `StockValueByWarehouseWidget` — stat per warehouse (on-hand × cost, computed via a service).
- `RecentMovementsWidget` — latest N `inventory_movements` (read-only).
- `PendingDocumentsWidget` — counts of `draft` adjustments/transfers awaiting confirmation.
- All widgets are **read-only aggregates and must not cache stock balances** (§2.6).

### Exports and bulk actions
- Table `ExportAction`s on `StockLevelResource` and `StockMovementResource` use a **queued**
  `Exporter`, write to the **private** disk, and expose a signed download — aligning with the
  `export generation` queue candidate (SYSTEM_ARCHITECTURE §8) and `export_logs` (ERD).
- Optional **bulk adjustment import** (CSV) dispatches a queued job that funnels each row
  through `InventoryAdjustmentService` — never a direct bulk `UPDATE` on stock.

### Reports linkage
- `InventoryReportResource` already sits in the registry's **Reports** group; this phase
  links inventory data to it (read-only) rather than duplicating report queries. It relates
  to, but does not own, cross-module figures — keep the module boundary.

### Authorization
- Widgets respect the underlying `inventory.stock.view` / `inventory.movement.view`
  permissions; exports require `inventory.export`.

### Dependencies
- Phases FI-2 through FI-4. Backend export/reporting services; running queue worker;
  private disk configured.

### Acceptance Criteria
- **Given** low-stock variants exist, **when** the admin opens the dashboard, **then** the
  low-stock widget shows the correct count without reading from a cache.
- **Given** an admin triggers a stock movement export, **when** the action runs, **then** a
  queued job produces a file on the private disk and an `export_logs` row, delivered via a
  signed download.
- **Given** a bulk adjustment import, **when** it is processed, **then** each row is applied
  through the adjustment service (creating movements) and failures are reported per row
  without partial silent corruption.

---

## 10. Open Questions and PRD Conflicts

| # | Item | Type | Recommended resolution |
|---|---|---|---|
| 1 | PRD.md §10 lists "Filament dashboard implementation" as Out of Scope, yet Filament is already committed in code and this plan builds on it. | **PRD conflict** | Update PRD §10 and record an ADR approving the Filament admin dashboard for Inventory. Treat this plan as a proposal until then. |
| 2 | Task-brief resource names differ from committed `AdminModuleRegistry` names. | Naming conflict | Use registry names as canonical (§1.1 mapping). No new names invented. |
| 3 | Reservations have **no** entry in `AdminModuleRegistry` or `lang/en/admin.php`. | Missing scaffold | Add a `reservations` item to the registry's `inventory` group + en/ar labels before Phase FI-5. |
| 4 | `ReturnResource` is registry-reserved but the **ERD has no returns table**. | ERD gap | Decide among: new `stock_returns` table, read-only movement view, or credit-note-driven returns (§8). Default to the read-only view; defer the full resource. |
| 5 | API_CONTRACT.md exposes `/api/dashboard/inventory/*`; Filament talks to services directly, not via those endpoints. | Architecture clarification | Keep both surfaces on the **same domain services** (§2.1, §2.5). API endpoints remain valid for non-Filament clients; Filament does not depend on them. |
| 6 | DB engine (MySQL vs PostgreSQL) unconfirmed (PRD §11). | Config open question | Lock the engine before migrations; Filament is engine-agnostic. |
| 7 | Whether any Employee/warehouse role gets Filament dashboard access, or inventory stays API-only for employees (PRD §3 lists Employee as an inventory actor). | Authorization scope | Default: dashboard = System Admin only; employees affect stock via employee-app API through the same services. Confirm if a warehouse role needs panel access. |
| 8 | `lang/ar/admin.php` was deleted; PRD NFR requires Arabic content. | i18n gap | Restore `lang/ar/admin.php` with Arabic strings for all inventory keys. |
| 9 | Transfer workflow: single **confirm** vs two-step **dispatch → receive** (ERD has one `status` field). | Workflow decision | Default to single confirm; if in-transit tracking is needed, extend `stock_transfers.status` and add a receive step. |
| 10 | Should confirming an adjustment/transfer require a **separate approver** (segregation of duties)? | Control decision | Model `confirm` as a distinct permission (§6, §7) so this can be enforced by role if required. |
| 11 | Whole plan hard-depends on IMPLEMENTATION_PLAN.md §7 backend (models/services/policies) that **do not exist yet** (`app/` has only `User`). | Sequencing dependency | Do not start Phase FI-1 until §7 delivers the inventory models, services, Form Requests, and policies. |

---

## 11. Phase Sequence and Dependency Summary

| Phase | Name | Depends on | Blocking open questions |
|---|---|---|---|
| FI-0 | Foundation and Guardrails | Backend §7 (models/services/policies), spatie permissions | #1, #7, #11 |
| FI-1 | Warehouses and Locations | FI-0 | — |
| FI-2 | Stock Levels and Movements (read-only) | FI-1 | — |
| FI-3 | Stock Adjustments | FI-1, FI-2 | #10 |
| FI-4 | Stock Transfers | FI-1, FI-2 | #9, #10 |
| FI-5 | Reservations and Returns (conditional) | FI-2 | #3, #4 |
| FI-6 | Widgets, Exports, Reports | FI-2, FI-3, FI-4 | — |

**Guiding principle for all phases:** the Filament dashboard is a *thin presentation layer*
over the Inventory domain services. It surfaces and orchestrates; it never owns stock,
recomputes balances, writes movements directly, hard-deletes records, or forks
authorization. Every stock change flows through a service that writes a movement and an
audit log inside a transaction — exactly as the PRD, SDD, and ERD require.
