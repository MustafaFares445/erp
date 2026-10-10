---
title: "Equipment 360"
module: support
pages: ["index", "view"]
screenshots: ["support-support-equipment-index", "support-support-equipment-view"]
lastVerified: 2026-10-07
---

# Equipment 360: serialized customer service history

<IerpPageSummary purpose="Review one serialized unit's custody, installation, calibration, warranty eligibility and ongoing service history in a single read-only context." useWhen="Support must verify the equipment behind a ticket or maintenance request." owner="Support and Maintenance" />

## When to use this page

Support must verify the equipment behind a ticket or maintenance request.

## Typical tasks

- Follow the documented “Equipment is context, not an inventory transaction” instructions below.
- Follow the documented “Follow the equipment lifecycle” instructions below.
- Follow the documented “What to do when data conflicts” instructions below.

## Equipment is context, not an inventory transaction

Equipment 360 traces a unit's serial/product and current custody, linked customer, installation and commissioning, calibration, preventive maintenance and service/loaner history. It does **not** replace Inventory's posted movement records or let Support invent customer ownership.

<IerpScreenshot id="support-support-equipment-index" title="Equipment 360 catalog" description="Find the serialized unit, its customer custody and service condition before raising work." path="Support and Maintenance → Equipment 360" />

Use search and filters to locate the exact serial. Confirm whether it was actually delivered and which customer currently holds it. Do not link a ticket to a similar product name without the matching serial.

## Follow the equipment lifecycle

<IerpScreenshot id="support-support-equipment-view" title="Equipment 360 history" description="Review shipment, installation, warranty activation, calibration and other linked maintenance evidence." path="Equipment 360 → View" imageSrc="/images/en/support/support-support-equipment-view.png" />

1. **Custody and shipment:** read the delivery and customer history; physical movement belongs to Inventory/Sales logistics.
2. **Installation:** start from an authorized installation Maintenance Request; complete the checklist and commissioning, then record customer acceptance or rejection. Warranty triggers may depend on verified arrival, installation or commissioning.
3. **Calibration:** inspect the most recent result, measured values and certificate, technician/provider and next due date. A failed calibration is not a successful certificate; request follow-up work when needed.
4. **Preventive service:** compare recurrence due dates with completed service records; a generated occurrence does not prove service execution.
5. **Loaner/RMA:** when enabled, inspect checked-out equipment and supplier return status. Loaner issue/return and supplier shipment impact Inventory custody.

## What to do when data conflicts

If the record is assigned to the wrong customer or has an inconsistent serial, stop coverage or installation assumptions and reconcile the owning Inventory/Shipment history. Use the linked authorized Maintenance Request for repair, diagnosis or warranty reclassification; do not overwrite historical evidence here.

Related: [Tickets](/screens/support/ticket), [Maintenance Requests](/screens/support/maintenance-request), [Maintenance Schedules](/screens/support/maintenance-schedule).
