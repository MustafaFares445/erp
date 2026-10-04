# Support & Maintenance Workflows

---
status: canonical
owner: support
last_verified: 2026-10-02
verified_against: Support services/tests
---

## Ticket

`Intake -> triage -> Pending Payment or Live -> Assigned -> In Progress -> Waiting Customer/Resume -> Resolved -> Closed`

Exact allowed transitions remain enforced by `TicketLifecycleService`.

## Chargeable Ticket

`Ticket -> TicketPaymentLink -> provider PaymentTransaction -> settlement -> ticket/maintenance billing continuation`

Payments executes provider money movement; Support owns the support-state consequence.

## Maintenance

`Ticket/Standalone -> Maintenance Open -> Diagnose -> coverage/billing decision -> approval/repair -> QA -> Closed`

Chargeable work can generate Sales quotation/invoice through `MaintenanceBillingService`.

## Service Parts

`Service Record -> consume part -> Inventory-owned stock effect`

Reversal uses the dedicated reverse path.

## Warranty

`Shipment arrival -> warranty activation -> diagnosis -> coverage decision -> repair/replacement/recovery`

Posted customer-return/replacement behavior updates entitlement through explicit warranty services.

## Installation

`Delivered unit -> Installation maintenance request -> start installation (checklist) -> complete installation (activates installation-trigger warranty) -> commissioning pass/fail (pass activates commissioning-trigger warranty) -> customer acceptance/rejection`

The Installation & Commissioning panel on the maintenance request drives this through `EquipmentInstallationService` in one workspace (shipment, installation, checklist, commissioning, customer acceptance, warranty activation) with the current step, next action and blocker. Field Service labels installation visits by deriving the purpose from the visit's maintenance request and links to the same installation record; it does not duplicate the workflow.

## Calibration

`Calibration maintenance request (ad hoc or raised by a calibration schedule) -> start calibration (measurement template) -> record measurements -> complete (passed / passed with adjustment) or fail (optional follow-up repair) -> issue certificate -> next due date`

The Calibration panel on the maintenance request drives this through `EquipmentCalibrationService` in one workspace (previous calibration, measurements, result, certificate, next due date, technician or external provider, evidence) with the current step, next action and blocker. Field Service labels calibration visits by their request kind and shows the measurements, result and certificate in a context modal linked to the same workspace. The Support dashboard lists equipment whose calibration is due soon, overdue or failed as an operational queue (not a headline KPI).

## Loaner Equipment

`Corrective request -> reserve loaner -> issue to customer (Inventory: warehouse -> customer custody) -> record return with inspected condition (Inventory: customer -> warehouse) -> request can close`

## Supplier Repair (RMA)

`Request -> approve -> ship to supplier (Inventory: warehouse -> supplier custody) -> received by supplier -> repairing -> repaired -> receive back (Inventory: supplier -> warehouse)` or `... -> replacement approved -> replacement received (linked, warranty follows the replacement rule)`

Both live inside the maintenance request (*Temporary Replacement Equipment* and *Supplier Repair (RMA)* tabs). Equipment 360 shows the loaner and loan history, and the replaced-by / replacement-for relationship. The Support dashboard lists overdue loaners and equipment waiting for a supplier as operational queues.

## Preventive Maintenance

`Schedule -> generate occurrences -> due/missed/skip -> linked maintenance execution -> completion`

A schedule's type (Preventive, Inspection or Calibration) decides the kind of maintenance request each due occurrence raises; Calibration schedules notify with the calibration-due template.
