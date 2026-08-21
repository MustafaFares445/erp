# Contract: Inventory Operations

**Feature**: `specs/014-inventory-erp-rework` | Covers US1, FR-001 → FR-018

The interface here is the admin panel plus the domain service behind it. There is no public HTTP
API for this feature; the contract is the service surface and the screen behaviour it guarantees.

---

## Preconditions

- **P-1 — Single stock path for deliveries.** The constitution states delivery notes affect
  inventory. Once the Delivery operation exists, a sales delivery note MUST affect inventory
  through that operation and through nothing else. Before wiring FR-013, audit the existing
  delivery-note path and remove or redirect it. Failing this, stock moves twice. This is the
  single highest-consequence integration point in the feature.
- **P-2 — Architecture rule.** `tests/Unit/ArchTest.php` forbids `App\Filament` from using
  `InventoryStock` or `InventoryMovement` outside an allowlist that does **not** include the new
  operation namespace. All reads go through `Warehouse::currentOnHand()` /
  `currentAvailable()`; all writes go through the service below. Do not add an exception.
- **P-3 — Transactions.** Every stage transition that touches stock runs inside a database
  transaction with a row lock on the operation, per Constitution Principle III and the pattern
  already used by `StockTransferService`.

---

## Service surface: `InventoryOperationService`

Each method is transactional, writes an audit entry, and returns the refreshed operation.
All reject with a domain exception rather than silently no-op.

| Method | Guard | Effect |
|---|---|---|
| `markReady(op)` | stage ∈ {Draft, Waiting}; lines present; all products active | Reserves outbound quantity. Insufficient available → stage becomes `Waiting`, naming product and shortfall. No on-hand change. |
| `dispatch(op)` | type = InternalTransfer; stage = Ready | Source on-hand decreases; movement written; `dispatched_at` set; stage → `InTransit`. |
| `complete(op)` | receipt/delivery: stage = Ready · transfer: stage = InTransit | Destination gains (receipt, transfer) or source loses (delivery); movement written; `completed_at` set; stage → `Done`. |
| `cancel(op, reason)` | stage ∈ {Draft, Waiting, Ready, InTransit} | Releases reservations. From `InTransit`, restores source on-hand with a compensating movement. Stage → `Canceled`. |
| `previewEffect(op)` | any pre-terminal stage | Returns per-line before/after balances. Read-only. Backs FR-010. |

### Guarantees

- **G-1** Stock changes only at the stages in the custody rule — never at `Draft`, `Waiting`, or
  `Ready`.
- **G-2** Every balance change writes exactly one `inventory_movements` row carrying date,
  variant, quantity, warehouse, user, reason and the originating operation (FR-011).
- **G-3** `available_quantity` never goes below zero on any path (FR-006, SRS §4).
- **G-4** `Done` and `Canceled` are terminal. Edit and delete are refused (FR-008).
- **G-5** Concurrent confirmation of the same operation: exactly one succeeds, the other is told
  it is already processed. Enforced by the row lock in P-3.
- **G-6** `dispatch()` is rejected for any type other than internal transfer (V-03).

---

## Screen contract

### Operation list (Receipts · Deliveries · Internal Transfers)

Three navigation entries, one resource, each pre-filtered by `operation_type`.

Columns: Reference · Contact (supplier or customer) · Scheduled At · Source Document · Stage
badge · row actions. Stage badge colours are consistent across all three lists.

### Operation form

- **General**: counterparty, operation type, source and/or destination warehouse per §1 of the
  data model, scheduled date.
- **Operations tab**: the line editor — Product · Package · Demand · Unit · Picked · remove.
  Identical component for all three types.
- **Additional tab**: responsible, scheduled at, source document.
- **Note tab**: free text.

The stage bar renders `Draft → Waiting → Ready → Done → Canceled`, with `InTransit` inserted
between `Ready` and `Done` for internal transfers only. No other per-type difference is permitted
anywhere in the form.

### Confirmation

Any transition calling `complete()` or `dispatch()` first shows `previewEffect()` output — the
resulting balance per line — and requires explicit confirmation (FR-010, SRS §5.1).

---

## Error contract

| Condition | Behaviour |
|---|---|
| Insufficient available quantity | Stage held at `Waiting`; message names product and shortfall; no balance change |
| Duplicate serial number on a line | That line rejected naming the duplicate; remaining lines preserved for correction |
| Inactive or Coming Soon product on a line | Cannot leave `Draft`; message names the product |
| Quantity precision exceeds the unit's decimals | Rejected, never silently truncated |
| Location or package not belonging to the line's warehouse | Rejected naming the mismatch |
| Edit or delete attempted on `Done` | Refused; user directed to raise a correcting operation |
| Concurrent confirmation | Loser told the operation is already processed |

All messages resolve through translation keys and render right-to-left in Arabic (FR-040).

---

## Permissions

Extends `InventoryPermission`; **nothing is removed** (A-006). Existing receipt and transfer
permission cases keep working during the dual-write window.

| Capability | Gate |
|---|---|
| View operations | view permission for the corresponding type |
| Create / edit draft | create permission |
| `markReady`, `dispatch`, `complete`, `cancel` | confirm permission |
| See `unit_cost` on lines | pricing-view permission (SRS §5.3) |

---

## Retired surfaces

| Was | Becomes |
|---|---|
| `InventoryReceiptResource` | Receipts entry on the operation resource |
| `TransferResource` | Internal Transfers entry on the operation resource |
| `ReturnResource` | Filter on Receipts (FR-014, A-007) |
| `StockReservationResource` | Filter, plus the product Quantities tab |

Routes stay registered and redirect (FR-021, [R-007](../research.md)). Underlying services,
tables and data are untouched (A-006).
