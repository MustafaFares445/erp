# Support & Maintenance Business Rules

---
status: canonical
owner: support
last_verified: 2026-10-02
verified_against: Support services/enums/permissions/tests
---

## Ticket State

Ticket statuses are Pending, Pending Payment, Live, Assigned, In Progress, Waiting Customer, Resolved, Closed and Cancelled.

Priority is Low/Normal/High/Urgent. Triage can resolve the service path as Remote Support, Maintenance or On-Site Visit.

Lifecycle, assignment, messages and SLA timing are separate services.

## SLA

SLA service reacts to ticket creation/live/waiting/resume/priority changes and maintains breach flags. Waiting-customer state can pause/resume SLA behavior through the domain service.

## Ticket Payment

Support owns the ticket payment-link/business relationship. Provider transaction execution belongs to Payments. Settlement is coordinated through dedicated ticket/provider settlement services.

## Maintenance

Maintenance status is Open, Diagnosing, Awaiting Approval, Ready For Repair, In Progress, Quality Assurance, Closed or Cancelled.

Maintenance may originate from a ticket or standalone request. Labour/third-party cost recording and billing classification use dedicated services.

Chargeable maintenance can create Sales-owned quotation/invoice documents rather than implementing a parallel billing document inside Support.

## Installation & Commissioning

An Installation-kind maintenance request may carry one `EquipmentInstallation` for serialized equipment that is in the same customer's custody (and, when a shipment is given, was delivered by that arrived shipment). Only one non-rejected, non-cancelled installation may exist per unit. A default checklist is created with the installation; commissioning can pass only after installation is completed and every check is Passed or Not applicable, and can be retried after a failure. Customer acceptance or rejection is allowed only after commissioning has passed, once, and rejection needs a reason. Closed or cancelled requests are frozen. Support records service facts only and never changes custody.

The delivery shipment must be Arrived and confirmed, must have been delivered to the same customer, and must include the request's serialized unit; `EquipmentInstallationService::eligibleShipments()` defines that set for both the picker and the domain guard. The commissioning failure reason and the customer rejection reason/timestamp are stored on the installation and stay visible after later state changes.

Evidence (photos, commissioning documents, signed acceptance) is attached through `EquipmentInstallationEvidenceService` into the private `installation-photos`, `commissioning-documents` and `customer-acceptance` media collections (local disk; JPEG, PNG, WebP or PDF, 10 MB each) and is served only by authenticated, policy-checked routes. All installation abilities are denied while `SUPPORT_EQUIPMENT_INSTALLATION_ENABLED` is false; historical rows remain.

Milestones notify through the Notification dispatcher: scheduling notifies the customer and the assigned technician; completion, passed commissioning and customer acceptance/rejection notify the customer and installation managers; failed commissioning notifies the technician and installation managers. Each recipient receives one delivery per channel and the customer's language is used.

## Calibration

A Calibration-kind maintenance request may carry one `EquipmentCalibration` for serialized equipment in the same customer's custody. Calibration is its own maintenance kind (never an Inspection). `EquipmentCalibrationService` owns the rules: `start()` creates the calibration with its measurement template (each measurement needs a minimum and/or maximum limit and may be mandatory or optional), `recordMeasurement()` evaluates the actual value against those limits (inclusive), `complete()` records a Passed or Passed-with-adjustment result, `fail()` records a Failed result with a mandatory reason, and `issueCertificate()` stores the certificate number and expiry.

A calibration can pass only when it has at least one measurement, every mandatory measurement is recorded and in tolerance, the calibrated equipment still matches the request, and the next due date (when given) is after the calibration date. Failing may optionally raise a Corrective follow-up repair request for the same equipment, which requires the actor to be allowed to create maintenance requests. A certificate can be issued only for a passed calibration; re-issuing keeps the replaced number in the audit trail. Two calibrations of one unit cannot be open at the same time, and a closed or cancelled maintenance request is immutable for every calibration change.

The next calibration due date is stored on the calibration: explicitly, or derived from the calibration schedule that raised the request (or the unit's active calibration schedule). Support records service facts only; custody, stock and accounting are untouched.

Certificates and evidence are attached through `EquipmentCalibrationEvidenceService` into the private `calibration-certificates` and `calibration-evidence` media collections (local disk; JPEG, PNG, WebP or PDF, 10 MB each) and are served only by authenticated, policy-checked routes. All calibration abilities, the panel, the Equipment 360 section, the dashboard queue, new calibration schedules and calibration due-raising are inactive while `SUPPORT_CALIBRATION_ENABLED=false`; existing data is retained.

Completed calibrations notify the customer and calibration managers; failed calibrations notify the technician and calibration managers; a due calibration schedule notifies the schedule owner through the `calibration.due` template. Each recipient receives one delivery per channel in their language.

Maintenance schedules carry a `maintenance_kind` (Preventive, Inspection or Calibration; Corrective work never recurs). Generated requests inherit the schedule kind, and the kind is locked after the schedule is created.

## Loaner Equipment

While a customer's unit is repaired, a Corrective maintenance request may reserve a loaner: a unit of the same product that is available, saleable and held in a warehouse, never the customer's own unit and never one that already has an active loan. `EquipmentLoanService` records the loan (reserved, issued, returned, cancelled); an issued loan needs a future expected return date, and the request cannot be closed or cancelled while a loan is reserved or issued.

Support never writes serialized custody. Issuing and returning go through `InventoryEquipmentLoanService`, which validates the unit under a lock and posts a `loan_issue` / `loan_return` movement (source `equipment_loan`) through the inventory posting service: warehouse custody to the customer's temporary custody and back, with the return inspected as saleable, damaged or quarantined. These two steps need the Inventory permission `inventory.loan.manage` in addition to the Support permission, so Support permission alone can never move custody. Recording a return stays possible after the request is closed. Lot-tracked units cannot be loaned.

`support:loaners:notify-overdue` (daily) notifies the customer and loan managers once per overdue issued loan.

## Supplier Repair (RMA)

Equipment that cannot be repaired in-house goes to its supplier through a contextual section of the maintenance request (`maintenance_external_repairs`), not a separate module. `ExternalRepairService` owns the status path: requested, approved, shipped to supplier, received by supplier, repairing, then either repaired and returned to the company, or replacement approved and replacement received; a request can be cancelled before shipping. Illegal jumps are rejected.

The unit must be with the maintenance request's customer or in a warehouse when the repair is requested, and must be held in a warehouse (received through the normal Inventory return flow) before it can be shipped. Shipping and receiving back go through `InventorySupplierCustodyService` (`supplier_repair_out` / `supplier_repair_in` movements; supplier custody and the Returned to Supplier status are reused) and need `inventory.supplier-custody.manage` in addition to the Support permission.

A replacement unit is registered by Inventory and delivered to the same customer; Support then links it (same product, same customer custody, used once) and carries the original unit's active warranty to it through the existing replacement rule. Recovery stays with the existing warranty-recovery claim: the repair links the request's claim and records how it was settled (cash recovery, credit note, replacement unit, parts replacement or rejected). A request cannot be closed or cancelled while a supplier repair is open.

Both features are off by default (`SUPPORT_LOANER_EQUIPMENT_ENABLED`, `SUPPORT_EXTERNAL_REPAIR_ENABLED`); when off, their panels, queues, notifications and abilities are inactive and the data is retained.

## Parts

Service-record parts are consumed/reversed through `ServiceRecordPartService`, which must integrate with Inventory-owned stock behavior.

## Warranty

Warranty status resolution is explicit. Entitlements use Pending Activation, Active, Ended or Cancelled states.

Warranty activation is linked to shipment fulfillment. Customer returns/replacements can end or replace entitlement through explicit services.

Entitlements whose policy start trigger is Installation or Commissioning stay Pending Activation after delivery. They start (and the serialized-unit warranty cache is refreshed) only when the owning installation is completed, or when commissioning passes, respectively; a failed commissioning never activates, and activation is idempotent. `WarrantyEntitlement` stays authoritative.

Warranty recovery is tracked separately through Draft/Submitted/Approved/Partially Received/Received/Rejected/Cancelled.

## Permissions

Support permissions separately cover ticket manage/assign/work/message/payment settlement, SLA, maintenance/service execution, parts consume/reverse, costs/billing, schedules, diagnosis, warranty decisions/override/policies/recovery, reports and audit.
