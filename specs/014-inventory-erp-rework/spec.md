# Feature Specification: Inventory Module ERP-Pattern Rework

**Feature Branch**: `014-inventory-erp-rework`

**Created**: 2026-07-27

**Status**: Draft

**Input**: User description: "Rework the inventory module to follow the Aureus-ERP UX pattern: unify Receipts/Deliveries/Internal Transfers into one InventoryOperation model with a uniform Draft-Waiting-Ready-Done-Canceled state machine, fold product variants into a tabbed product record, add Spatie Media Library product images, add package types and instances, and consolidate 15 navigation items into 4 menus. Warehouse remains the stock balance grain. Requirements source: IERP_Product_Inventory_Module_SRS_AR.docx."

**Requirements source**: `IERP_Product_Inventory_Module_SRS_AR.docx` (Arabic SRS, Product & Inventory module). Where the SRS and the reference ERP screenshots disagree, the SRS wins on *what the business needs*; the ERP pattern wins on *how it is presented*.

**Relationship to prior features**: This is a rework of the surface delivered by features 001–012, not a greenfield build. No previously accepted business capability may be lost. Where this spec conflicts with an earlier spec, this spec supersedes it for presentation and operation-document structure only; domain rules from 001–012 remain in force.

## Clarifications

### Session 2026-07-27

- Q: Should the reworked module use warehouse or location as the stock balance grain? → A: Warehouse grain. Balances stay keyed on product variant + warehouse. Location remains an optional annotation on movements and document lines. Adopt only the ERP's navigation, state machine, and screen layout. No constitution amendment required.
- Q: Which pages fold into the product record as tabs, and which stay standalone? → A: Product record gains View / Edit / Attributes / Variants / Vendors / Quantities / IN-OUT tabs plus media on Edit. The standalone Product Variants page is removed from navigation. Cross-product Stock Levels and Stock Movements stay as their own pages, because SRS §3.10 and §3.14 need warehouse-wide views a per-product tab cannot serve.
- Q: How should the Operations menu be structured? → A: One operation document with an operation type of Receipt, Delivery, or Internal Transfer, sharing a single Draft → Waiting → Ready → Done → Canceled lifecycle and one line-items editor. Quantity Adjustments and Scraps form a second section. Deliveries link to existing sales delivery notes rather than duplicating them. Dropships are excluded — absent from the SRS, and the supplier portal is out of scope per the constitution.
- Q: What does the package feature mean here? → A: Package types as configuration, plus named package instances tied to a warehouse and optional location. Instances annotate stock and movement lines; they never become a separate balance grain.
- Q: How far should the cleanup go? → A: Consolidate the user interface, keep all domain logic. Retire the standalone Reservations, Returns, Alerts and Exports pages into tabs, filters or row actions. Services, tables and data stay intact.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - One predictable lifecycle for every warehouse operation (Priority: P1)

The system administrator receives goods from a supplier, ships goods to a customer, and moves goods between warehouses. Today each of these is a differently shaped document with its own vocabulary and its own set of stages, so the administrator has to relearn the screen every time. After this change all three are the same document with the same five-stage lifecycle, the same line editor, and the same confirmation step — only the operation type differs.

**Why this priority**: This is the single largest source of the "hard to understand" complaint. It also carries the highest correctness risk, so it must land first and be proven before anything is layered on top. Every other story in this feature is cosmetic by comparison.

**Independent Test**: Create one operation of each type, walk each through its full lifecycle, and confirm that the stage names, the line editor, the confirmation prompt, and the resulting stock ledger entries follow one identical pattern. Deliverable on its own even if no other story ships.

**Acceptance Scenarios**:

1. **Given** a draft operation of any type, **When** the administrator views it, **Then** the same lifecycle bar is displayed in the same order — Draft, Waiting, Ready, Done, Canceled — with the current stage highlighted; an internal transfer additionally shows an In Transit stage between Ready and Done, and no other stage differs by type.
2. **Given** a receipt operation in Draft with valid lines, **When** the administrator confirms it, **Then** the operation reaches Done and the destination warehouse balance increases by exactly the confirmed quantity.
3. **Given** an outbound operation whose source warehouse has insufficient available quantity, **When** the administrator attempts to make it Ready, **Then** the operation is held at Waiting with a message naming the product and the shortfall, and no balance changes.
4. **Given** an internal transfer that has been dispatched from the source warehouse but not yet received, **When** the administrator views stock for that product, **Then** the quantity appears as in transit, is absent from the source's on-hand, and is absent from the destination's on-hand.
5. **Given** a dispatched internal transfer, **When** the destination confirms receipt, **Then** the destination on-hand increases, the in-transit quantity returns to zero, and both sides of the move are traceable from one place.
6. **Given** any operation in Done, **When** the administrator attempts to edit or delete it, **Then** the attempt is refused and the administrator is directed to a correcting operation instead.
7. **Given** an operation in Draft, Waiting or Ready, **When** the administrator cancels it, **Then** the operation reaches Canceled, any reserved quantity is released, and no on-hand balance is affected.
8. **Given** an operation about to be confirmed, **When** the confirmation prompt appears, **Then** it states the resulting balance change per line before the administrator commits (SRS §5.1).

---

### User Story 2 - The product record is the single place to understand a product (Priority: P2)

The system administrator opens a product and sees everything about it in one place: its details, its attributes, its variants, its suppliers and their prices, its quantity in every warehouse, and its full movement history — each on its own tab of the same record, rather than scattered across separate pages that must be found and filtered by hand.

**Why this priority**: Directly delivers the "extend the product show page" and "remove the variants page" asks, and removes two top-level pages. Depends on nothing in Story 1, so it can proceed in parallel.

**Independent Test**: Open any product and confirm all seven tabs are present, populated, and scoped to that product; confirm the standalone Product Variants page no longer appears in navigation and that every capability it offered is reachable from the Variants tab.

**Acceptance Scenarios**:

1. **Given** a product with variants, **When** the administrator opens it, **Then** tabs for details, editing, attributes, variants, vendors, quantities and movement history are shown, each scoped to that product.
2. **Given** the administrator is on the Variants tab, **When** they create, edit or archive a variant, **Then** it succeeds without leaving the product record.
3. **Given** the standalone Product Variants page existed before, **When** the administrator looks in navigation, **Then** it is gone, and any saved link to it redirects to the parent product's Variants tab.
4. **Given** a product stocked in several warehouses, **When** the administrator opens the Quantities tab, **Then** on-hand, reserved, available, in-transit and damaged quantities are shown per warehouse, with a total (SRS §3.10).
5. **Given** a product supplied by more than one supplier under different names, numbers and countries, **When** the administrator opens the Vendors tab, **Then** every external reference is listed against this one product, with purchase price and currency (SRS §3.6).
6. **Given** the administrator lacks permission to see purchase prices, **When** they open the Vendors tab, **Then** price columns are hidden while the rest of the tab remains usable (SRS §5.3).

---

### User Story 3 - Products carry images (Priority: P3)

The system administrator uploads one main image and optional additional images for a product or variant, and thereafter recognises products visually in every list instead of reading names.

**Why this priority**: Explicitly requested, and currently absent from the entire codebase. Self-contained and low-risk, but delivers no operational capability on its own, so it ranks below the structural work.

**Independent Test**: Upload images to a product and a variant, confirm they render in list and detail views, survive a reload, and can be reordered and removed.

**Acceptance Scenarios**:

1. **Given** a product with no image, **When** the administrator uploads one, **Then** it becomes the main image and appears in the product list.
2. **Given** a product with several images, **When** the administrator reorders or removes them, **Then** the change is reflected everywhere the product is shown.
3. **Given** a variant with its own image, **When** the variant is displayed, **Then** its own image is shown; when it has none, the parent product's main image is shown instead.
4. **Given** an upload that is not a supported image type or exceeds the size limit, **When** the administrator submits it, **Then** it is rejected with a message naming the reason, and existing images are untouched.

---

### User Story 4 - Goods can be grouped into named packages (Priority: P4)

The system administrator defines package types such as Box or Carton, then creates named packages that sit in a warehouse and hold goods. Stock and movement lines can record which package the goods are in.

**Why this priority**: Explicitly requested and visible in the reference screenshots, but the SRS treats packaging only as a unit of measure. It is genuinely new capability rather than a rework, so it ranks last among the additive stories.

**Independent Test**: Define a package type, create a package in a warehouse, attach it to a stock line and to a movement line, and confirm it appears as a column wherever those lines are listed.

**Acceptance Scenarios**:

1. **Given** no package types exist, **When** the administrator creates one, **Then** it becomes selectable when creating packages.
2. **Given** a package type exists, **When** the administrator creates a package naming it and a warehouse, **Then** the package is listed with its name, type and location.
3. **Given** a package exists, **When** the administrator records an operation line or adjustment line against it, **Then** the package is shown on that line and the line remains linked to it thereafter.
4. **Given** a package is referenced by any stock or movement line, **When** the administrator attempts to delete it, **Then** deletion is refused and the referencing records are named.
5. **Given** a package is assigned to a warehouse, **When** stock balances are calculated, **Then** the package changes nothing about them — it annotates lines only.

---

### User Story 5 - Four menus instead of fifteen entries (Priority: P5)

The system administrator finds any inventory screen through four clearly named menus — Operations, Products, Reporting, Configurations — instead of scanning a flat list of fifteen entries across four unlabelled sections.

**Why this priority**: The visible payoff of the whole rework, but it can only be finished once the pages it groups exist in their new form. Sequenced last for that reason, not because it matters least.

**Independent Test**: Confirm the inventory area exposes exactly four top-level menus, that every capability available before is reachable within two clicks, and that no retired page is orphaned.

**Acceptance Scenarios**:

1. **Given** the administrator opens the inventory area, **When** the navigation renders, **Then** exactly four top-level menus are shown.
2. **Given** any capability that existed before this change, **When** the administrator looks for it, **Then** it is reachable in at most two clicks from a top-level menu.
3. **Given** a retired standalone page such as Reservations or Returns, **When** the administrator follows an old link to it, **Then** they arrive at the tab, filter or action that now provides it rather than an error.
4. **Given** the administrator lacks permission for a screen, **When** navigation renders, **Then** that entry is absent and no empty menu is displayed.
5. **Given** the interface is in Arabic, **When** navigation and the operation screens render, **Then** they read right-to-left with translated labels (SRS §5.1).

---

### Edge Cases

- An outbound operation is made Ready, reserving stock; the same stock is then needed by a second outbound operation. The second is held at Waiting rather than over-committing — available quantity must never go negative (SRS §4).
- An internal transfer is dispatched, then the destination warehouse is deactivated before receipt. The in-transit quantity must remain visible and receivable; it must not be stranded or silently absorbed.
- An operation in Done is later found to be wrong. Correction happens through a new opposing operation; the original stays intact, because records used in past transactions are never deleted (SRS §4).
- Two administrators confirm the same operation at the same moment. Exactly one confirmation takes effect and the other is told the operation has already been processed; the balance must not move twice.
- A receipt line carries a serial number already recorded on another unit. The line is rejected naming the duplicate, and the rest of the operation is preserved for correction (SRS §3.4, §4).
- A product is set to Inactive or Coming Soon while it still appears on a draft operation. The operation cannot advance past Draft until the line is removed or the product is reactivated (SRS §3.3).
- A quantity is entered with more decimal places than the unit of measure allows. It is rejected or rounded by a stated rule, never silently truncated (SRS §3.5).
- A package is moved to a different warehouse while it still holds goods. Either the goods move with it as a recorded movement, or the move is refused — the package's location must never contradict the location of the goods it holds.
- An image upload fails part-way. The product record is unchanged and the administrator can retry.
- A variant is deleted from the Variants tab while it holds stock or appears in past movements. Deletion is refused, matching the existing rule that used records are never removed.

## Requirements *(mandatory)*

### Functional Requirements

#### Unified warehouse operations

- **FR-001**: System MUST represent supplier receipts, customer deliveries and inter-warehouse transfers as one kind of operation document distinguished by an operation type.
- **FR-002**: System MUST apply one lifecycle to every operation type, with the stages Draft, Waiting, Ready, Done and Canceled. An internal transfer MUST additionally pass through an In Transit stage between Ready and Done, because it is the only operation with two custodians. No stage other than In Transit may differ by operation type.
- **FR-003**: System MUST change a warehouse's on-hand balance only at the moment that warehouse's custody of the goods changes — the destination gains at Done for every type, the source loses at Done for a delivery, and the source loses at In Transit for an internal transfer. No other stage may alter on-hand quantity.
- **FR-004**: System MUST hold an operation at Waiting whenever it cannot proceed because required quantity is unavailable or because it depends on another operation that is not yet Done, and MUST state which product and which blocking condition applies.
- **FR-005**: System MUST reserve the required quantity when an outbound operation becomes Ready, and MUST release that reservation if the operation is later canceled.
- **FR-006**: System MUST prevent available quantity from becoming negative through any operation (SRS §4).
- **FR-007**: System MUST require two separate confirmations on a single inter-warehouse transfer document — one releasing goods from the source, one receiving them at the destination — and MUST keep the quantity visible as in transit between those two confirmations, counted against neither warehouse's on-hand (SRS §3.12).
- **FR-008**: System MUST make operations in Done immutable and undeletable, and MUST offer a correcting operation as the route to fix them (SRS §4).
- **FR-009**: System MUST allow cancellation only from Draft, Waiting or Ready, and MUST leave on-hand balances unchanged when cancelling.
- **FR-010**: System MUST show the resulting balance change for every line before an operation is confirmed (SRS §5.1).
- **FR-011**: System MUST record a stock movement for every balance change, carrying date, product, quantity, warehouse, user, reason and the originating operation (SRS §3.14, Constitution Principle III).
- **FR-012**: System MUST allow an operation to reference an originating commercial document — a purchase order for a receipt, a sales delivery note for a delivery — and MUST display that reference on the operation.
- **FR-013**: System MUST create the stock-moving delivery operation from an existing sales delivery note rather than requiring the same delivery to be entered twice.
- **FR-014**: System MUST record customer returns as inbound operations that are distinguishable from supplier receipts in the movement ledger (SRS §3.14).
- **FR-015**: System MUST let each operation line optionally record a warehouse location, without that location affecting how balances are kept.
- **FR-016**: System MUST support serial-number-tracked goods on operation lines, enforcing that a serial number identifies exactly one unit (SRS §3.4, §4).
- **FR-017**: System MUST provide quantity adjustment and scrap as their own operations, each requiring a reason and recording the quantity before and after (SRS §3.13).
- **FR-018**: System MUST NOT provide a dropship operation type in this feature.

#### Product record

- **FR-019**: System MUST present a product as a single record with tabs for details, editing, attributes, variants, vendors, quantities and movement history.
- **FR-020**: System MUST allow variants to be listed, created, edited and archived from within the product record.
- **FR-021**: System MUST remove the standalone product variants page from navigation and redirect any existing link to the parent product's Variants tab.
- **FR-022**: System MUST show, on the quantities tab, the on-hand, reserved, available, in-transit and damaged quantity for each warehouse holding the product, plus a total (SRS §3.10).
- **FR-023**: System MUST show, on the vendors tab, every supplier reference for the product — supplier name, supplier product name and number, country, purchase price and currency — all attached to this one product rather than to duplicates of it (SRS §3.6).
- **FR-024**: System MUST hide purchase price and markup from users without the corresponding permission, while leaving the rest of the record usable (SRS §5.3).
- **FR-025**: System MUST keep warehouse-wide stock levels and the full movement log reachable as their own screens, independent of any single product (SRS §3.10, §3.14).

#### Product media

- **FR-026**: System MUST let an administrator attach images to a product and to a variant, designating one as the main image.
- **FR-027**: System MUST display the main image wherever products or variants are listed, falling back to the parent product's main image when a variant has none.
- **FR-028**: System MUST allow images to be reordered and removed.
- **FR-029**: System MUST reject uploads that are not a supported image type or that exceed the configured size limit, naming the reason and leaving existing images intact.
- **FR-030**: System MUST store all product and variant imagery through the application's standard media handling rather than a feature-specific file table (Constitution Principle IV).

#### Packages

- **FR-031**: System MUST let an administrator define package types.
- **FR-032**: System MUST let an administrator create named packages, each belonging to a warehouse and optionally to a location within it.
- **FR-033**: System MUST let stock lines, operation lines and adjustment lines optionally record the package the goods are in, and MUST display that package wherever those lines are listed.
- **FR-034**: System MUST NOT treat a package as a source of stock balances; packages annotate lines only.
- **FR-035**: System MUST refuse deletion of a package or package type that is still referenced, naming the referencing records.

#### Navigation and consolidation

- **FR-036**: System MUST group every inventory screen under exactly four top-level menus: Operations, Products, Reporting, Configurations.
- **FR-037**: System MUST keep every capability available before this change reachable within two clicks of a top-level menu.
- **FR-038**: System MUST relocate the standalone reservations, returns, alerts and exports screens into tabs, filters or row actions on the screens that remain, without removing the underlying capability or data.
- **FR-039**: System MUST hide navigation entries the current user is not permitted to open, and MUST NOT render an empty menu.
- **FR-040**: System MUST render all new and reworked screens in both Arabic and English, right-to-left in Arabic (SRS §5.1).
- **FR-041**: System MUST preserve every business rule established by features 001–012, including identity uniqueness, base price derivation from purchase price and markup, customer pricing tier precedence, spreadsheet import with per-row error reporting, expiry tracking, and price and status history (SRS §3.4, §3.7, §3.8, §3.9, §4).

### Key Entities

- **Inventory Operation**: One warehouse movement document, one per physical movement. Carries an operation type (Receipt, Delivery, Internal Transfer), a lifecycle stage, a source warehouse and/or destination warehouse as the type requires, a scheduled date, a responsible user, an optional reference to an originating commercial document, the timestamps of its dispatch and receipt confirmations, and a set of lines.
- **Inventory Operation Line**: One product variant and quantity on an operation, with its unit of measure, optional warehouse location, optional package, optional lot, and optional serialised unit.
- **Package Type**: A named kind of container, such as Box or Carton. Configuration data.
- **Package**: A named physical container of a given type, sited at a warehouse and optionally a location. Referenced by stock and movement lines; holds no balance of its own.
- **Product Media**: Images attached to a product or a variant, ordered, with one designated main image.
- **Inventory Stock** *(existing, unchanged)*: The balance of a product variant in a warehouse — on-hand, reserved, available, and reorder level. Remains the sole source of truth for quantity.
- **Inventory Movement** *(existing, unchanged)*: The immutable ledger entry recording every balance change and its cause.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An administrator who has performed one operation type can complete a different operation type without consulting help, because all three follow the same steps — verified by all three types exposing an identical stage sequence and confirmation flow.
- **SC-002**: Every inventory screen is reachable within two clicks from one of four top-level menus, down from fifteen flat entries today.
- **SC-003**: Recording a supplier receipt of ten lines takes under three minutes from an empty draft to a confirmed operation.
- **SC-004**: Stock balances reconcile exactly with the movement ledger after every operation type, with zero unexplained variance across the full acceptance run.
- **SC-005**: Goods dispatched between warehouses remain visible as in transit for the entire interval between dispatch and receipt, never disappearing from reporting.
- **SC-006**: All information about a product — details, attributes, variants, suppliers, quantities, movements, images — is reachable from the product record without leaving it.
- **SC-007**: Every capability available before this change remains available after it, confirmed by the acceptance criteria of features 001–012 continuing to pass.
- **SC-008**: No operation can drive available quantity below zero, and no confirmed operation can be edited or deleted, across the full negative-path test set.
- **SC-009**: Every screen introduced or reworked renders correctly in Arabic right-to-left and in English.
- **SC-010**: Purchase price and markup remain invisible to users without the corresponding permission on every screen that could expose them.

## Assumptions

- **A-001**: Warehouse remains the stock balance grain. Locations stay an optional annotation on movements and document lines, exactly as the pending location migration already describes. No constitution amendment is sought.
- **A-002**: **A physical transfer is one document, with an In Transit stage between Ready and Done.** *Decided 2026-07-27, reversing an earlier proposal to model transfers as a linked pair of operations.* The governing invariant is stated in FR-003: a warehouse's balance changes when that warehouse's custody changes. In-transit quantity is what has left the source but not yet reached the destination — belonging to neither on-hand balance, exactly as SRS §3.12 requires. Reasons for preferring this over paired operations: (1) the reference ERP achieves in-transit through two chained documents moving stock via a *transit location*, and location-grain was ruled out, so borrowing the two-document shape would incur its cost without the mechanism that justifies it; (2) splitting historical transfer records into pairs is a balance-affecting data migration against a NON-NEGOTIABLE constitutional principle, whereas renaming lifecycle stages is not; (3) the existing dispatch and receive services, policy and tests keep their shape; (4) one document per physical movement is fewer concepts for the administrator, and reducing concepts is the point of this feature.
- **A-003**: Media scope is product and variant images only — one main image plus a gallery. Document attachments on operations are out of scope; the SRS asks for none.
- **A-004**: Packages carry no company attribution. The reference screenshots show a Company column, but this application has no multi-company concept and introducing one is out of scope.
- **A-005**: Dropship is excluded. It appears nowhere in the SRS, and the constitution places supplier-facing flows out of scope.
- **A-006**: Cleanup is confined to the user interface. No domain service is deleted, no table is dropped, and no permission is removed, because features 005, 006 and 011 bind acceptance criteria to them. Reducing that surface is a separate exercise requiring its own audit.
- **A-007**: Customer returns reuse the inbound operation type with a distinguishing reason rather than gaining their own operation type, keeping the type list at three.
- **A-008**: Scrap continues to use the existing damage handling and its existing movement types; this feature gives it a dedicated screen, not new mechanics.
- **A-009**: Sales delivery notes remain the commercial record; the inventory delivery operation is the stock record generated from and linked to them. Neither duplicates the other.
- **A-010**: Quantity precision follows the existing three-decimal convention already established on stock quantities, satisfying the SRS §3.5 requirement for decimal quantities.
- **A-011**: The in-flight uncommitted work on the current branch — the catalog setup consolidation and the location annotation migration — is expected to land before or with this feature. This spec assumes that state as its baseline.

## Dependencies

- Features 001–012 supply the domain services, tables and business rules this rework presents; all remain in force.
- The pending location annotation migration must land, since operation lines reference warehouse locations.
- The sales delivery note capability must exist for the delivery operation type to link to it.
- The application's standard media handling must be enabled, as it is currently configured but unused by any model.

## Out of Scope

- Changing the stock balance grain from warehouse to location.
- Dropship operations.
- Multi-company attribution on packages or operations.
- Deleting domain services, permissions, report types or database tables.
- Document attachments on operations.
- A supplier-facing portal, and the accounting treatment of supplier purchases (SRS §6).
- Customer application and employee application screens (SRS §1.2, §6).
