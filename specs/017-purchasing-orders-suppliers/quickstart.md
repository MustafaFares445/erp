# Quickstart: Validating the Purchasing Dashboard

**Feature**: `specs/017-purchasing-orders-suppliers` | **Date**: 2026-08-18

How to prove this feature works end to end. Scenarios map to the spec's seven user stories and run in story order — each later scenario builds on records the earlier ones created.

---

## Prerequisites

```bash
php artisan migrate
```

```bash
php artisan db:seed --class=PurchasePermissionSeeder
```

```bash
php artisan db:seed --class=PurchasingDemoSeeder
```

Local Xdebug is configured for coverage (`php.ini`, `xdebug.mode = develop,debug,coverage`), so `composer test:coverage` works without extra setup.

The demo seeder depends on `InventoryDemoSeeder` for suppliers, variants, units, and warehouses. Run the full `php artisan db:seed` if starting from an empty database.

---

## Scenario 1 — Roles and permissions (US1, run first)

```bash
php artisan test --compact --filter=PurchasePermission
```

```bash
php artisan test --compact --filter=CrossModulePermissionLeak
```

**Manual walkthrough**:

1. Sign in as `System Admin`; confirm every Purchasing surface (Purchase Orders, Supplier Confirmations, Suppliers, Supplier Product References, reports, audit) is reachable with every action available.
2. Sign in as `Purchasing Manager`; confirm approve, send, cancel, close, and supplier management are available, and record restoration and threshold editing are denied.
3. Sign in as `Purchasing Officer`; confirm drafting, submitting, confirming, and receiving are available, and approve, send, cancel, and close are hidden **and** refused when called directly.
4. Sign in as `Reviewer`; confirm every list and report is readable and no write action is offered.
5. As `Purchasing Officer`, attempt to open `/admin/inventory-operations`, `/admin/adjustments`, `/admin/stock-levels`, `/admin/customers`, `/admin/employees`, and `/admin/tickets` — all must deny.

**Regression check — this feature changes other modules.** Adding two cases to `DashboardRole` narrows the admin bypass everywhere:

```bash
php artisan test --compact --filter="InventoryPolicyAuthorization|CrmPolicy|EmployeePolicy|SupportPolicy"
```

---

## Scenario 2 — Draft a purchase order with defaulted costs (US2)

```bash
php artisan test --compact --filter=PurchaseOrderDraft
```

**Manual walkthrough**:

1. Open Purchasing → Purchase Orders → Create.
2. Choose a supplier that has product references seeded, a destination warehouse, currency, and today's order date.
3. Add a line for a variant that **has** a reference: confirm unit cost, currency, and supplier item number populate automatically.
4. Add a line for a variant with **no** reference: confirm cost defaults to zero and is editable.
5. Change a quantity: confirm the line total and document total both update.
6. Add a duplicate variant-and-unit line: confirm it is rejected.
7. Enter a zero quantity and a negative cost: confirm both are rejected with field-level messages.
8. Save, then confirm a unique purchase-order number was assigned.
9. Search the list by that number and by supplier; filter by status and date range.

---

## Scenario 3 — Approval and transmission (US3)

```bash
php artisan test --compact --filter=PurchaseOrderApproval
```

**Manual walkthrough**:

1. As `System Admin`, set the approval threshold (Purchasing settings) to a value above your smallest draft's total and below your largest.
2. As `Purchasing Officer`, submit the small order: confirm it goes straight to **approved** and records the submitter as approver.
3. Submit the large order: confirm it goes to **pending approval** and cannot be sent.
4. As the same `Purchasing Officer`, attempt to approve the large order: confirm denial (separation of duties).
5. As `Purchasing Manager`, approve it: confirm approver and timestamp are recorded.
6. Mark both orders **sent**: confirm the transmission timestamp appears and all fields become read-only.
7. Attempt to edit a sent order's line through the UI, then attempt the same through `php artisan tinker` calling the service directly: confirm both are refused.
8. Raise the threshold above the pending order's total on a **new** pending order: confirm it stays pending — no retroactive approval.
9. Cancel a sent order with no receipts: confirm it is cancelled with the reason stored.

---

## Scenario 4 — Supplier confirmations (US4)

```bash
php artisan test --compact --filter=SupplierConfirmation
```

**Manual walkthrough**:

1. On a sent purchase order, record a confirmation as **confirmed** with a promised date: confirm the date appears on the order.
2. Record a second confirmation as **rejected**: confirm the order is flagged supplier-rejected but stays `sent`.
3. Confirm the full history is listed chronologically with the latest answer surfaced as current.
4. Attempt to edit an answered confirmation: confirm refusal.
5. Enter a promised date earlier than the order date: confirm rejection.
6. On a customer order, mark it pending supplier confirmation with a reason and raise a confirmation; record **confirmed**, then verify the order moves to supplier-confirmed. Repeat on a second order with **rejected** and verify supplier-rejected.
7. Filter the confirmations list by status, supplier, and target document type.

---

## Scenario 5 — Receiving (US5, the integration proof)

```bash
php artisan test --compact --filter=PurchaseOrderReceiving
```

```bash
php artisan test --compact --filter=PurchasingArchitecture
```

The architecture test is the mechanical proof of SC-002 — it fails if any purchasing class references `InventoryStock`, `InventoryMovement`, or a balance writer.

**Manual walkthrough**:

1. On a sent purchase order, choose **Receive**: confirm a draft Inventory receipt operation opens, pre-filled with the supplier, destination warehouse, and remaining quantities.
2. Reduce one line's quantity to a partial amount and complete the operation.
3. Return to the purchase order: confirm received quantity increased, status is **partially received**, and stock rose by exactly the received amount.
4. Check Inventory → Stock Movements: confirm the movements were created by the Inventory service in its normal form.
5. Start a second receipt: confirm it pre-fills only the remaining quantity.
6. Raise one line above the remaining quantity and attempt completion: confirm rejection naming the offending line.
7. Complete the receipt at the correct quantity: confirm the order moves to **received** and the Receive action disappears.
8. On a different partially received order, short-close with a reason: confirm status **closed** and no further receipt possible.
9. Create a receipt then cancel it before completion: confirm received quantities are unchanged and a new receipt can start.
10. Complete a receipt at a unit cost differing from the order's: confirm both costs and the variance appear on the line.
11. Receive a lot-tracked and a serial-tracked variant: confirm the existing Inventory capture behaves exactly as it does for a manual receipt.

---

## Scenario 6 — Supplier product references and cost writeback (US6)

```bash
php artisan test --compact --filter=SupplierProductReference
```

**Manual walkthrough**:

1. Open Purchasing → Supplier Product References; search by supplier, variant SKU, supplier item number, and manufacturer.
2. Create a second active reference for a supplier-and-variant pair that already has one: confirm rejection.
3. After Scenario 5 step 10, open the affected reference: confirm its purchase cost now equals the received cost.
4. Check the audit trail for that reference: confirm the previous cost is recorded.
5. Receive a variant with no reference for that supplier: confirm a new active reference was created.
6. Deactivate a reference, then draft a new order line for that pair: confirm no cost defaults.

---

## Scenario 7 — Reports and audit (US7)

```bash
php artisan test --compact --filter=PurchasingReport
```

**Manual walkthrough**:

1. Open the open-commitments report: confirm it excludes cancelled, closed, and fully received orders, and that the total reconciles against ordered-minus-received across the remaining orders.
2. Open the receiving-performance report: confirm promised versus actual dates appear per supplier.
3. Open the cost-variance report: confirm the line from Scenario 5 step 10 appears with its variance.
4. Export each report and confirm the file matches the on-screen figures.
5. As `Purchasing Officer` (no report permission), attempt each report and its export: confirm denial on both.
6. Open a purchase order's audit trail: confirm every transition from Scenarios 2–5 is listed with actor and timestamp, with no unattributed entries.

---

## Full gate

Run before considering the feature complete:

```bash
composer test
```

This mirrors `.github/workflows/tests.yml`. Per project convention, do not re-run the suite single-worker after a passing parallel run.

Individual gates, if isolating a failure:

```bash
vendor/bin/pint --dirty --format agent
```

```bash
composer test:types
```

```bash
composer test:coverage
```

Coverage and type-coverage thresholds are both 100 and must not be lowered to accommodate this feature.
