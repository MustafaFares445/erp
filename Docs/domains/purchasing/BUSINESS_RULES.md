# Purchasing Business Rules

---
status: canonical
owner: purchasing
last_verified: 2026-10-02
verified_against: purchase-order approval/acceptance/inbound/receiving/procurement services and tests
---

## Purchase Order Lifecycle

Statuses include `Draft`, `Pending Approval`, `Accepted`, `Rejected`, `Partially Received`, `Received`, `Closed`, and `Cancelled`.

The current reject action records the reason and returns the order to Draft for revision.

## Submission / Approval

- commercial data must be complete before submission;
- the approval threshold is evaluated at submission;
- auto-approval requires matching threshold currency and a positive threshold;
- otherwise explicit approval is required;
- ordinary self-approval is blocked by maker/checker; System Admin has the documented exemption;
- transition methods row-lock the PO to prevent double approval.

## Send Is the Activation Boundary

Approval alone does not provision downstream execution.

The first Send of an accepted PO:

- records `sent_at`;
- ensures a Purchase Inbound exists;
- creates supplier-confirmation work when required;
- writes back supplier costs;
- asks Accounting to ensure a Draft Bill exists;
- synchronizes replenishment coverage;
- emits `PurchaseOrderAccepted`.

Re-send does not duplicate the first-send provisioning.

## Receiving Boundary

Purchasing establishes receipt provenance and quantity ceilings. `PurchaseOrderReceivingService` creates Inventory receipt operations.

**Inventory is the only domain that posts stock.**

Draft/Ready receipts are commitments, not physical receipts. Purchase Inbound received state counts only completed Inventory receipt lines.

## Inbound State

Derived states are Awaiting Allocation, Awaiting Receipt, Partially Received, Received, and Cancelled.

Allocation is exact base quantity across warehouses; completed physical receipts determine receipt progress.

## Close / Cancel

- short-close requires no open receipt;
- cancellation is blocked after completed receipt;
- cancellation is blocked while an open receipt exists;
- cancellation is blocked by an active non-draft/non-cancelled Accounting Bill;
- eligible draft bills are cancelled with PO cancellation;
- outstanding Sales procurement is requeued on cancel/short-close.

## Sales-Driven Procurement

Open Sales procurement requirements may become Purchasing draft POs only for suppliers with active matching supplier-product references in the selected currency.

The current implementation creates one draft PO per open requirement so provenance remains one-to-one.

## Authorization

Permissions separately cover order view/manage/submit/approve/send/receive/close/cancel, supplier/confirmation/reference management, settings, reports, audit and restore.
