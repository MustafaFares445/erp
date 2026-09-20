# User Flows — Employee Sales App V1

These flows translate the approved behavior into screen-to-screen journeys for pen.dev.

## Flow A — First Login and Device Binding

```text
Launch
 -> Session check
 -> Login
 -> Credentials valid?
    -> No: inline error, remain on Login
    -> Yes: Employee active?
       -> No: Access blocked
       -> Yes: Temporary password?
          -> Yes: Force Change Password
             -> Password changed
             -> Bind current device
             -> Home
          -> No: Device already bound?
             -> Same device: Home
             -> Different device: Device Rejected / Contact Support
```

UI requirements:
- Device rejection must not expose technical identifiers.
- Explain that the account is limited to one active device.
- Provide support contact CTA, not a self-service reset.
- Never allow the employee to skip first-password change.

## Flow B — Daily Work Entry

```text
Home
 -> Active plan summary
 -> Today Tasks / Today Visits
 -> Select task or visit
```

Task path:
```text
Task Details
 -> Pending? Start Task
 -> In Progress
 -> Complete Task
 -> Completed read-only state
```

Employee cannot Cancel, Reopen, or move Back to Pending.

Visit path:
```text
Visit Details
 -> Review customer + location + schedule
 -> Open Maps OR Check In
```

## Flow C — Check In

```text
Visit Details -> Check In -> connectivity/location/geofence validation -> Active Visit
```
Check In validation sequence:
- Internet unavailable -> Connection Required.
- Location permission denied -> Permission Required.
- Customer coordinates missing -> Location Not Configured.
- Employee outside geofence -> Outside Visit Area.
- All checks pass -> confirmation -> server Check In -> Active Visit.

After successful Check In:
- Start running visit duration.
- Start periodic GPS collection.
- Show persistent GPS tracking state.
- Check In action disappears.

## Flow D — Active Visit, Voice, AI and Final Review

```text
Active Visit
 -> Add outcome manually OR record Voice Note
 -> Upload voice
 -> AI processing
 -> Ready: transcript + suggested outcome + suggested products
    OR Failed: manual outcome + manual product selection
 -> Review & Confirm
 -> Edit outcome
 -> Add/remove products
 -> Confirm opportunity OR No Opportunity
 -> Explicit employee confirmation
 -> Ready for Check Out
```

AI content remains draft until employee confirmation. Voice-processing failure never blocks manual completion.

## Flow E — Check Out

```text
Review & Confirm
 -> Continue to Check Out
 -> Validate internet + confirmed outcome + opportunity review + GPS sync
 -> Check Out confirmation
 -> Server records checked_out_at
 -> Stop GPS
 -> Visit Completed
 -> Completed Visit Summary
```

If validation fails, navigate to the exact missing requirement instead of showing a generic error.

## Flow F — Opportunity to Quotation

```text
Sales -> Opportunities -> Opportunity Details
 -> Quotable?
    -> No: show review/status and unavailable quotation action
    -> Yes: Create Quotation
       -> Prefill customer
       -> Prefill confirmed opportunity products when available
       -> Review quantity/unit/server-resolved price
       -> Save Draft
       -> Quotation Details
       -> Send when permitted
```

Direct quotation is also available from Sales -> Quotations -> New Quotation. No warehouse, delivery, or stock actions appear here.

## Flow G — Notifications and Deep Links

Push or in-app notification -> open -> mark read -> deep-link to Task, Visit, Conversation, Opportunity, Quotation, Performance, or Salary details.

## Flow H — Admin Review Conversation

```text
Completed Visit -> Review Conversation
 -> Read admin message
 -> Reply
 -> Append new chronological message
 -> Admin gets new-message indicator
```

Employee cannot edit the original admin review message.

## Flow I — Performance, Salary and Bonus

Home performance card or Profile -> Performance Details -> Salary & Bonus Details.

All values are server-calculated and scoped to the authenticated employee.

## Flow J — Interruption and Resume

During an Active Visit:
- Backgrounding the app preserves the active visit.
- Reopening returns to the Active Visit.
- Temporary network loss may queue GPS points locally.
- Network restoration uploads queued GPS points.
- Check Out stays blocked until required sync succeeds.
- Logout during an active visit requires an explicit warning and must not silently complete the visit or stop the visit record.
