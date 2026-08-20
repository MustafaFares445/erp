# Contract: Purchasing Permissions and Roles

**Feature**: `017-purchasing-orders-suppliers` | **Date**: 2026-08-18

This contract is the single source of truth for the `purchase.*` permission catalogue and its role mapping. It is consumed by `App\Enums\PurchasePermission`, `Database\Seeders\PurchasePermissionSeeder`, and `App\Policies\Concerns\ChecksPurchasePermissions`, following the shape established by `/specs/016-support-maintenance-dashboard/contracts/permissions.md`.

Guard: `web`.

---

## 1. Permission Catalogue

| Permission | Grants |
|---|---|
| `purchase.order.view` | See purchase orders, their lines, confirmation history, and linked receipts |
| `purchase.order.manage` | Create and edit draft purchase orders; delete (archive) a draft |
| `purchase.order.submit` | Submit a draft for approval; below-threshold submissions auto-approve |
| `purchase.order.approve` | Approve or reject an above-threshold order |
| `purchase.order.send` | Mark an approved order as transmitted to the supplier |
| `purchase.order.cancel` | Cancel an order that has no completed receipt |
| `purchase.order.close` | Short-close a partially received order, abandoning the remainder |
| `purchase.order.receive` | Initiate and complete a receipt against a purchase order |
| `purchase.confirmation.view` | See supplier confirmations and their history |
| `purchase.confirmation.record` | Record a supplier's answer against a purchase order or customer order |
| `purchase.supplier.view` | See suppliers |
| `purchase.supplier.manage` | Create and edit suppliers |
| `purchase.product-reference.view` | See supplier product references |
| `purchase.product-reference.manage` | Create and edit supplier product references |
| `purchase.setting.manage` | Edit the approval threshold |
| `purchase.record.restore` | Restore a soft-deleted purchasing record |
| `purchase.report.view` | Open and export purchasing reports |
| `purchase.audit.view` | Review the purchasing audit trail |

`purchase.order.receive` is deliberately its own permission rather than an alias of any `inventory.*` permission. FR-008 requires a purchasing user to receive against a purchase order **without** gaining access to Inventory Operations, Adjustments, or Stock Levels.

---

## 2. Fixed Roles

Two new cases are added to `App\Enums\DashboardRole`: `PurchasingManager` (`'Purchasing Manager'`) and `PurchasingOfficer` (`'Purchasing Officer'`). `SystemAdmin` and `Reviewer` already exist and are reused.

| Permission | System Admin | Purchasing Manager | Purchasing Officer | Reviewer |
|---|:---:|:---:|:---:|:---:|
| `purchase.order.view` | ✅ | ✅ | ✅ | ✅ |
| `purchase.order.manage` | ✅ | ✅ | ✅ | — |
| `purchase.order.submit` | ✅ | ✅ | ✅ | — |
| `purchase.order.approve` | ✅ | ✅ | — | — |
| `purchase.order.send` | ✅ | ✅ | — | — |
| `purchase.order.cancel` | ✅ | ✅ | — | — |
| `purchase.order.close` | ✅ | ✅ | — | — |
| `purchase.order.receive` | ✅ | ✅ | ✅ | — |
| `purchase.confirmation.view` | ✅ | ✅ | ✅ | ✅ |
| `purchase.confirmation.record` | ✅ | ✅ | ✅ | — |
| `purchase.supplier.view` | ✅ | ✅ | ✅ | ✅ |
| `purchase.supplier.manage` | ✅ | ✅ | — | — |
| `purchase.product-reference.view` | ✅ | ✅ | ✅ | ✅ |
| `purchase.product-reference.manage` | ✅ | ✅ | — | — |
| `purchase.setting.manage` | ✅ | — | — | — |
| `purchase.record.restore` | ✅ | — | — | — |
| `purchase.report.view` | ✅ | ✅ | — | ✅ |
| `purchase.audit.view` | ✅ | ✅ | — | ✅ |

This mapping realises spec User Story 1 scenarios 1–4 exactly.

---

## 3. Rules Beyond the Matrix

- **R-A — Separation of duties.** Holding `purchase.order.approve` is not sufficient to approve an order you submitted yourself. The service refuses when `submitted_by === $actor->id`, unless the actor is System Admin (R-005, FR-022).
- **R-B — Threshold bypass.** Below-threshold submissions auto-approve under `purchase.order.submit` alone; `purchase.order.approve` is not consulted (FR-020).
- **R-C — Immutability outranks permission.** No permission permits editing a purchase order once `sent_at` is set. `purchase.order.manage` grants editing of drafts only (V-06, FR-025).
- **R-D — Cancellation outranks permission.** `purchase.order.cancel` does not permit cancelling an order with a completed receipt; the actor is directed to `purchase.order.close` (V-13, FR-026).
- **R-E — Answered confirmations are immutable.** `purchase.confirmation.record` creates new confirmations; it never edits an answered one (V-11, FR-031).
- **R-F — No force delete.** `ChecksPurchasePermissions::forceDelete()` returns `false` unconditionally, matching `ChecksSupportPermissions` (FR-009).
- **R-G — Dual checkpoint.** Every ability is checked at the Filament page/action layer **and** inside the service. A direct service call that bypasses a hidden button is denied identically (FR-007).

---

## 4. Admin Bypass Semantics

`ChecksPurchasePermissions::authorizePurchaseAbility()` follows the established shape:

```
if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
    return true;
}

return $user->can($permission);
```

An admin who holds **no** fixed dashboard role keeps full bypass. An admin who has been given a fixed role is governed by that role's grants.

### Cross-module regression risk

Adding `PurchasingManager` and `PurchasingOfficer` to `DashboardRole::fixedRoleNames()` changes behaviour in **every** module that uses this idiom — Inventory, CRM, Employees, and Support all call it. An admin user who is also given a purchasing role loses bypass in those modules too.

This is the intended design (see `DashboardRole`'s own docblock: *"adding one module's fixed role automatically narrows every other module's bypass"*), but it is a real behavioural change to shipped code. The existing authorization test suites for all four modules must be run as part of this feature, not assumed unaffected.

---

## 5. Seeder

`PurchasePermissionSeeder` mirrors `SupportPermissionSeeder` exactly: forget cached permissions, `Permission::findOrCreate()` every catalogue value, forget again, then `Role::findOrCreate()` and `givePermissionTo()` per the §2 matrix. It is registered in `DatabaseSeeder::run()` after `SupportPermissionSeeder`.

System Admin receives `PurchasePermission::values()` in full rather than an enumerated list, so a newly added permission is never silently withheld from the admin role.
