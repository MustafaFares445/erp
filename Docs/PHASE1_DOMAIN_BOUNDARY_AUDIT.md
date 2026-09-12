# Phase 1 Domain Boundary Audit

**Status:** Phase 1 implementation record

**Canonical base:** `dev`

**Scope:** Purchasing → Inventory / Logistics boundary cleanup after Phase 0.

## 1. Authorization decision

Inventory authorization remains permission-based. Phase 1 does not add a parallel `DashboardRole::InventoryManager` authorization path.

The following permissions are now first-class Inventory permissions:

- `inventory.replenishment_policy.view`
- `inventory.replenishment_policy.manage`
- `inventory.inbound.allocate`

`WarehouseReplenishmentPolicyPolicy` uses the dedicated replenishment permissions, and inbound warehouse allocation is checked both in the Filament action and in `PurchaseInboundService` so UI bypass cannot grant Purchasing users Inventory-owned allocation authority.

## 2. Pricing namespace audit

The Phase 1 plan required a case-by-case read before moving pricing services out of `App\Services\Inventory`. The audit result is:

### `ProductPricingService`

Owns product-variant selling-price governance: base price, minimum price, markup, price-change approval, and price-floor overrides. It no longer receives procurement cost from Inventory receipts and has no supplier/PO/receipt writeback path.

**Decision:** retain for now. Moving it would be a broad mechanical namespace/API refactor without correcting a current procurement-ownership violation. A future dedicated Catalog/Commercial Pricing extraction may move it together with its data objects, policies, Filament surfaces, and callers.

### `PriceResolver`

Resolves customer-facing sale price from base price, customer-specific/general/product-scoped tiers, discounts, and minimum-price floors.

**Decision:** commercial selling-price logic, but not a Purchasing/Inventory ownership leak. Retain until a coordinated commercial-pricing extraction is explicitly authorized.

### `PricingTierService`

Manages customer/product pricing-tier relationships and is intentionally shared with CRM permission checks.

**Decision:** commercial/CRM-adjacent logic. Retain in place for Phase 1; do not perform a partial namespace move that leaves models, permissions, Filament resources, and callers split across domains.

### `PricingTierDiscountCalculator`

Pure selling-price discount calculation used by `PriceResolver`.

**Decision:** retain with the pricing cluster until that cluster is moved as one deliberate refactor.

## 3. Procurement price isolation

Phase 1 strengthens the architecture gate across the whole `app/Services/Inventory` namespace. Inventory services may continue to contain sale/catalog pricing concepts already authorized by the existing product-pricing feature, but they must not regain procurement ownership such as:

- `SupplierProductReference` writes;
- `purchase_cost`;
- `last_received_unit_cost`;
- supplier/procurement price fields.

Accepted supplier price remains Purchasing-owned, and physical receiving remains quantity/custody-only.

## 4. Exit gate

Phase 1 is considered complete when:

1. replenishment policy view/manage permissions are dedicated;
2. inbound allocation requires `inventory.inbound.allocate` in both UI and service layer;
3. no `InventoryManager` role is introduced as a second authorization path;
4. the full Inventory service namespace is guarded against procurement monetary leakage;
5. the pricing-service audit above is recorded so later phases do not perform an unreviewed namespace sweep.
