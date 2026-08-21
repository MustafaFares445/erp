# Feature Specification: Inventory Widgets, Exports & Reports

**Feature Branch**: `feature/filament-inventory-dashboard`

**Created**: 2026-07-23

**Input**: User description: "Read the final Phase (FI-6 — Inventory Widgets, Exports, and Reports) from Docs/FILAMENT_INVENTORY_DASHBOARD_PLAN.md and create the spec per GitHub Spec Kit best practices."

## Overview

This feature completes the inventory dashboard by adding the **at-a-glance and take-away layers** on top of the data and write flows built in the earlier phases (FI-1 through FI-4). It is deliberately a *reading and extraction* phase — it observes and packages inventory data but never changes a stock balance. It has three related parts:

- **Inventory health widgets** — compact, always-current summaries an administrator sees on opening the dashboard: what is running low, how much stock value sits in each warehouse, the most recent stock movements, and how many draft documents are waiting to be confirmed. These are read-only aggregates computed from live data — never from a cached copy of stock.
- **Auditable exports** — the ability to take the stock-levels and movement-ledger lists away as generated files. Because these can be large, they are produced in the background and delivered through a secure, access-controlled download, and each export is recorded for audit. An optional bulk adjustment import is included as the lowest-priority, most-deferrable slice: it applies every imported row through the *same* sanctioned adjustment flow, never a direct balance edit.
- **Reporting linkage** — a read-only bridge from inventory data to the existing reports area, so inventory figures can feed reporting without this phase duplicating report logic or reaching across module boundaries to own cross-module numbers.

The core promise of this phase is *insight without risk*: administrators gain visibility, extraction, and reporting reach, while every guarantee from earlier phases holds — no widget, export, or import path can change a balance except through the trusted flows that record a movement and an audit entry.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See inventory health at a glance (Priority: P1)

An administrator opens the inventory dashboard and immediately sees a small set of summary widgets: a **low-stock** widget listing (and counting) variants whose available quantity is at or below their reorder level; a **stock-value-by-warehouse** widget showing the value of stock held in each warehouse; a **recent-movements** widget showing the latest stock movements; and a **pending-documents** widget counting draft adjustments and draft transfers still awaiting confirmation. Every widget is read-only and reflects current data — a change made moments earlier (a confirmed adjustment, a new low-stock variant) is reflected without stale caching.

**Why this priority**: The dashboard's headline value is a fast, trustworthy read on inventory health. It is safe by construction (it changes nothing) and delivers standalone value the moment the earlier data phases exist. It is the minimum viable slice of this phase.

**Independent Test**: With known stock data, open the dashboard and verify each widget's numbers match the underlying data (low-stock count and rows, per-warehouse stock value, latest movements, draft-document counts); then confirm an adjustment and reload, and verify the affected widgets reflect the change immediately rather than showing a cached figure.

**Acceptance Scenarios**:

1. **Given** variants whose available quantity is at or below their reorder level, **When** the administrator opens the dashboard, **Then** the low-stock widget shows the correct count and lists those variants, computed from current data (not a cache).
2. **Given** stock held across several warehouses, **When** the administrator views the stock-value widget, **Then** each warehouse's stock value is shown, computed through the trusted valuation logic.
3. **Given** recent stock movements exist, **When** the administrator views the recent-movements widget, **Then** the latest movements are listed read-only, newest first.
4. **Given** draft adjustments and draft transfers awaiting confirmation, **When** the administrator views the pending-documents widget, **Then** the counts of each match the number of draft documents.
5. **Given** no low-stock variants (or no recent movements, or no pending documents), **When** the administrator views the corresponding widget, **Then** it shows an empty/zero state cleanly rather than an error.

---

### User Story 2 - Export stock and movement data as auditable files (Priority: P2)

A permitted administrator exports the stock-levels list or the movement ledger to a file. Because these lists can be large, the file is generated in the background so the administrator is not blocked; when it is ready they retrieve it through a secure, access-controlled download. The exported content matches the data (and any filters) the administrator was viewing. Each export is recorded — who exported what and when — so extraction of inventory data is auditable. Only administrators with the export permission can trigger or retrieve an export.

**Why this priority**: Taking data away for offline analysis or sharing is a common, high-value need, and doing it safely (in the background, privately stored, access-controlled, and audited) matters because inventory data is sensitive. It depends on the underlying lists existing (FI-2) but is independent of the widgets, so it is prioritized second.

**Independent Test**: As an administrator with the export permission, trigger a movement-ledger export, confirm the request does not block the interface, confirm a file is produced and retrievable only through the access-controlled download, confirm its contents match the filtered view, and confirm an export record was written naming the user; then confirm an administrator without the export permission has no way to trigger or retrieve it.

**Acceptance Scenarios**:

1. **Given** an administrator with the export permission on the stock-levels or movement list, **When** they trigger an export, **Then** the file is generated in the background without blocking the interface and is retrievable when ready.
2. **Given** a completed export, **When** the administrator downloads it, **Then** the download is served through a secure, access-controlled link and the file contents match the data and filters that were in view.
3. **Given** an export is triggered, **When** it runs, **Then** an export record is written capturing the acting user, what was exported, and when.
4. **Given** an administrator without the export permission, **When** they view the stock or movement lists, **Then** no control to export is available and any direct attempt to retrieve an export is refused.
5. **Given** a stored export file, **When** it is accessed, **Then** it is served from private storage and never exposed through an unauthenticated public location.

---

### User Story 3 - Bulk-import stock adjustments through the sanctioned flow (Priority: P3, optional)

A permitted administrator uploads a data file describing many stock corrections at once. Rather than touching balances directly, the system applies **each row through the same sanctioned adjustment flow** used for a single adjustment — so every applied row produces a movement and updates the balance inside the trusted logic. The import runs in the background, and when it finishes the administrator sees a per-row outcome: which rows were applied and which failed and why. A failure in some rows never silently corrupts stock or leaves a partial, unexplained change.

**Why this priority**: Bulk correction is a genuine efficiency for large counts, but it is the most complex and least essential slice of this phase and is explicitly optional in the plan — so it is prioritized last and may be deferred without affecting the widgets or exports.

**Independent Test**: Upload a file with a mix of valid and invalid rows, let the import run, and verify that every valid row was applied through the adjustment flow (each producing a movement and a balance change), every invalid row is reported individually with a reason, and no direct bulk balance update occurred and no partial silent corruption remains.

**Acceptance Scenarios**:

1. **Given** an uploaded file of stock corrections, **When** the import runs, **Then** each row is applied through the sanctioned adjustment flow (creating a movement and updating the balance), never through a direct bulk balance update.
2. **Given** a file containing both valid and invalid rows, **When** the import completes, **Then** valid rows are applied and invalid rows are reported individually with a reason, with no silent partial corruption.
3. **Given** the import runs on a large file, **When** it is processing, **Then** it runs in the background without blocking the interface and reports its outcome when finished.
4. **Given** an administrator lacking the adjustment-create (and, where required, apply) permission, **When** they attempt a bulk import, **Then** it is refused consistent with the single-adjustment permissions.

---

### User Story 4 - Reach inventory reporting without duplicating it (Priority: P4)

An administrator navigates from inventory data to the existing reporting area to see inventory-related reports. This phase provides a read-only linkage into that existing reports area rather than re-implementing report queries inside inventory, and it does not take ownership of cross-module figures — it relates to them, respecting the inventory module boundary.

**Why this priority**: Connecting inventory to reporting is valuable but is the least urgent slice and is mostly linkage rather than new capability, so it is prioritized last. It depends on the reporting area already existing.

**Independent Test**: From the inventory area, follow the linkage to the reports area and confirm inventory figures are surfaced read-only there, and confirm no report query logic was duplicated inside inventory and no cross-module figure is edited or owned from inventory.

**Acceptance Scenarios**:

1. **Given** the existing reports area, **When** an administrator follows the inventory reporting linkage, **Then** inventory data is presented read-only there without inventory duplicating the report logic.
2. **Given** a report that combines cross-module figures, **When** it is viewed via the linkage, **Then** inventory relates to but does not own or edit those figures, preserving the module boundary.

---

### Edge Cases

- **Empty data sets**: With no low-stock variants, no recent movements, or no pending documents, each widget shows a clean empty/zero state rather than an error or a blank panel.
- **Live data, never cached**: A stock change confirmed moments earlier is reflected in the widgets on next view; widgets must not show a stale cached balance. (Plan §2.6.)
- **Large export**: An export of a very large list runs in the background and does not freeze or time out the interface; the administrator can continue working and retrieve the file when ready.
- **Export access control**: A stored export file is never reachable through an unauthenticated public location; only a permitted administrator can retrieve it through the secure link.
- **Concurrent exports**: Two exports triggered close together each produce their own file and their own export record without overwriting one another.
- **Bulk import partial failure**: When some rows are invalid, valid rows are still applied through the adjustment flow and invalid rows are individually reported; the operation never leaves an unexplained partial change or performs a direct bulk balance write.
- **Bulk import deferred**: Because the bulk import is optional, its absence does not block the widgets, exports, or reporting linkage.
- **Stock valuation without a cost**: If a variant lacks a cost figure, the stock-value widget handles it predictably (treated per the trusted valuation logic) rather than failing the whole widget.
- **Permission-scoped widgets**: A widget whose underlying data an administrator may not view is hidden from that administrator rather than showing forbidden figures.

## Requirements *(mandatory)*

> **Inheritance note**: This feature inherits the panel access gate, granular inventory permissions, shared authorization policies, service-only mutation guarantee, non-destructive/audit rules, and validation-reuse approach established in the foundation phase (FI-0, spec 001); the read-only stock and movement data from FI-2 (spec 002); and the sanctioned adjustment and transfer write flows from FI-3/FI-4 (specs 003/004). It does not redefine them. Requirements below add widgets, exports, an optional bulk import, and reporting linkage on top of that foundation.

### Functional Requirements — Inventory Health Widgets

- **FR-001**: The system MUST present a low-stock widget that counts and lists product variants whose available quantity is at or below their reorder level, computed from current data.
- **FR-002**: The system MUST present a stock-value-by-warehouse widget showing the value of stock held in each warehouse, computed through the trusted valuation logic.
- **FR-003**: The system MUST present a recent-movements widget listing the latest stock movements read-only, newest first.
- **FR-004**: The system MUST present a pending-documents widget counting draft adjustments and draft transfers still awaiting confirmation.
- **FR-005**: The system MUST compute all widgets as read-only aggregates from live data and MUST NOT cache stock balances.
- **FR-006**: The system MUST show a clean empty/zero state for any widget whose underlying data set is empty, rather than an error.
- **FR-007**: The system MUST respect the underlying view permissions for each widget, hiding a widget from any administrator who may not view its data.

### Functional Requirements — Exports

- **FR-008**: The system MUST let administrators with the export permission export the stock-levels list and the movement-ledger list.
- **FR-009**: The system MUST generate exports in the background so the interface is not blocked, and MUST make the resulting file retrievable when it is ready.
- **FR-010**: The system MUST store export files privately and serve them only through a secure, access-controlled download — never an unauthenticated public location.
- **FR-011**: The system MUST make an export's contents match the data and any active filters the administrator was viewing.
- **FR-012**: The system MUST record each export, capturing the acting user, what was exported, and when.
- **FR-013**: The system MUST gate exporting behind a distinct export permission and hide the export control from, and refuse retrieval by, administrators who lack it.

### Functional Requirements — Bulk Adjustment Import (optional)

- **FR-014**: The system SHOULD, where bulk import is provided, apply every imported stock-correction row through the sanctioned single-adjustment flow (producing a movement and updating the balance through the trusted logic), and MUST NOT perform a direct bulk balance update.
- **FR-015**: The system MUST, where bulk import is provided, run the import in the background and report a per-row outcome identifying which rows were applied and which failed and why.
- **FR-016**: The system MUST, where bulk import is provided, never leave a silent partial corruption: invalid rows are reported individually and do not quietly alter stock.
- **FR-017**: The system MUST, where bulk import is provided, gate it behind the same adjustment permissions as a single adjustment (create, and apply where required).

### Functional Requirements — Reporting Linkage

- **FR-018**: The system MUST link inventory data read-only into the existing reports area rather than duplicating report query logic inside inventory.
- **FR-019**: The system MUST keep the module boundary: inventory relates to cross-module report figures but does not own or edit them.

### Functional Requirements — Non-Destruction

- **FR-020**: The system MUST guarantee that no widget, export, or reporting-linkage path can change a stock balance; the only balance changes remain those through the sanctioned adjustment and transfer flows.

### Key Entities *(include if feature involves data)*

- **Inventory Health Widget (aggregate)**: A derived, read-only summary computed from live inventory data — low-stock, stock value by warehouse, recent movements, and pending-document counts. Not stored; recomputed from current data on each view.
- **Export Record**: The audit trace of an extraction, capturing the acting user, what was exported, and when. Referenced when reviewing who took inventory data away.
- **Export File**: The generated take-away file, stored privately and retrievable only through a secure, access-controlled download.
- **Stock Level**: The current per-variant-and-warehouse balance (from FI-2) that widgets summarize and exports extract; never changed by this phase.
- **Stock Movement**: The immutable ledger entry (from FI-2) that the recent-movements widget summarizes and the movement export extracts.
- **Stock Adjustment / Stock Transfer (draft)**: The draft documents (from FI-3/FI-4) counted by the pending-documents widget; the optional bulk import creates adjustments through the sanctioned adjustment flow.
- **Warehouse**: The facility (from FI-1) that stock value is grouped by; referenced read-only.
- **Product Variant (with cost)**: The catalog item (referenced read-only) that low-stock and stock-value computations concern; its cost, used for valuation, is owned by the catalog/costing module, not by inventory.
- **Inventory Report**: The existing report surface (in the reports area) that inventory links into read-only, without duplicating its logic.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Each widget's figures match the underlying live data 100% of the time — verified against a known data set — with 0 reliance on a cached stock balance.
- **SC-002**: A stock change confirmed immediately before viewing is reflected in the affected widgets on the next view in 100% of cases (no stale figure).
- **SC-003**: Every widget renders a clean empty/zero state (not an error) when its data set is empty.
- **SC-004**: An administrator with the export permission can export the stock or movement list and, in 100% of cases, receive a background-generated file whose contents match the filtered view and an export record naming them.
- **SC-005**: 100% of export files are retrievable only through the secure, access-controlled download and 0% are reachable through an unauthenticated public location.
- **SC-006**: An administrator without the export permission has 0 available paths to trigger or retrieve an export.
- **SC-007**: Where bulk import is provided, 100% of applied rows produce a movement through the sanctioned adjustment flow, 0 rows are applied via a direct bulk balance update, and 100% of failed rows are individually reported with a reason.
- **SC-008**: Inventory reporting linkage duplicates 0 report queries inside inventory and edits 0 cross-module figures.
- **SC-009**: Across all of this phase's paths, 0 stock balances are changed except through the sanctioned adjustment and transfer flows.

## Assumptions

- **Earlier phases delivered first**: The access gate and granular permissions from FI-0 (spec 001), the read-only stock and movement data from FI-2 (spec 002), and the adjustment/transfer write flows from FI-3/FI-4 (specs 003/004) are in place. This phase reads and packages what they produce.
- **Background processing and private storage are available**: Exports and the optional bulk import rely on background processing being operational and on a private storage location for generated files, consistent with the project's configured infrastructure. (Plan §2.6.)
- **Stock is never cached in this phase**: Widgets and summaries read current data directly; no stock balance is cached. Only stable lookups (e.g., warehouse lists) may be cached. (Plan §2.6, §9.)
- **Valuation cost is sourced read-only**: The cost used for stock valuation is owned by the catalog/costing module and referenced read-only; inventory does not compute or own product cost.
- **Bulk import is optional and deferrable**: Per the plan, bulk adjustment import is an optional slice (lowest priority) and may be deferred without affecting the widgets, exports, or reporting linkage. When built, it reuses the single-adjustment flow and permissions rather than introducing a new write path.
- **Exporting is a distinct permission**: The ability to export is gated separately from the ability to view, so extraction of sensitive inventory data can be granted independently. (Plan §2.3.)
- **The reports area already exists**: A reports surface already exists outside the inventory module; this phase links inventory into it read-only rather than creating a new reporting system.
- **Access scope defaults to System Administrator only**: Consistent with the foundation phase; whether any warehouse/operator role receives widget/export access later remains deferred. (Plan Open Question #7.)

## Out of Scope

- Any path that changes a stock balance — widgets, exports, and reporting are read-only; the optional bulk import only reuses the existing sanctioned adjustment flow and adds no new write path.
- Creating new report definitions or owning cross-module report figures — this phase provides read-only linkage to the existing reports area only.
- Caching stock balances for performance — explicitly disallowed for stock figures.
- Managing product variants, product cost/costing, or any other catalog data — referenced read-only and owned by other modules.
- Stock adjustments (FI-3), transfers (FI-4), and reservations/returns (FI-5) as flows in their own right — this phase summarizes and extracts their data but does not redefine them.
- Introducing or changing authorization mechanisms, the audit trail infrastructure, or the authentication mechanism — all inherited unchanged from the foundation phase.

## Dependencies

- The completed visibility phase (FI-2, spec 002): the read-only stock and movement data that widgets summarize and exports extract.
- The completed write-flow phases (FI-3, spec 003; FI-4, spec 004): the adjustment and transfer documents counted by the pending-documents widget, and the adjustment flow reused by the optional bulk import.
- The foundation phase (FI-0, spec 001): the panel access gate, granular permissions (including a distinct export permission), and the service-only mutation guardrails.
- The shared trusted valuation, export, and reporting domain logic (backend Products-and-Inventory phase) that computes stock value, generates export files, writes export records, and exposes inventory figures to reporting.
- Operational background processing and a private storage location for generated export/import files.
- The existing reports area that inventory links into read-only.
- The project's standard role/permission system, with the inventory export permission seeded in the foundation phase.
