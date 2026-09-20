# IERP Employee Mobile UI Skill

Use this skill whenever designing or editing the IERP Employee Sales App V1 in pen.dev.

## Sources

Before design work, read:
- `../../EMPLOYEE_APP_V1_APPROVED_SPEC.md`
- `../01_INFORMATION_ARCHITECTURE_AND_NAVIGATION.md`
- `../02_USER_FLOWS.md`
- `../03_SCREEN_SPECIFICATIONS.md`
- `../04_DESIGN_SYSTEM_AND_COMPONENTS.md`
- `../05_STATES_ERRORS_AND_MICROCOPY.md`

The approved spec controls business behavior. UI docs control presentation.

## Scope

The mobile app is for Sales Field Employees only.

Never add:
- Support.
- Maintenance.
- Inventory.
- Warehouse.
- Logistics.
- Tracking outside active visits.
- Offline Check In/Out.
- Admin-only employee/task transitions.

## Product principles

1. Optimize for field work, not admin reporting.
2. Show the next required action clearly.
3. Preserve employee context when interrupted.
4. Make active visit status unmistakable.
5. Treat AI as assistive draft content, never automatic truth.
6. Keep server-owned calculations and validations out of the mobile UI logic.
7. Use plain business language, not backend enum/model names.
8. Make location tracking transparent while active.
9. Keep security/device rules understandable without exposing technical identifiers.
10. Keep quotation workflow sales-only.

## Design-system rules

- Use pen.dev variables for tokens.
- Reuse component origins and instances.
- Use slots where content containers need flexible children.
- Do not duplicate one-off buttons/cards when a reusable component exists.
- Keep semantic layer names.
- Use consistent state components.
- Prefer 390 x 844 as the primary phone frame.
- Keep layouts responsive and RTL-ready.
- Light theme first.

## Navigation

Main recommendation:
Home / Tasks / Visits / Sales / Notifications.

Profile/Security opens from avatar.

If asked to follow the approved navigation literally, create the six-item bottom-navigation variant separately rather than silently replacing the recommended design.

## Visit workflow invariant

Visit Details -> Check In -> Active Visit -> Voice/Outcome -> AI Processing -> Review & Confirm -> Check Out -> Completed Summary.

Do not skip Review & Confirm.
## Check In invariant

Check In requires:
- Internet.
- Location permission.
- Customer coordinates.
- Employee within geofence.

Successful Check In:
- creates server check-in time,
- starts active visit,
- starts periodic GPS trail.

## Check Out invariant

Check Out requires:
- Internet.
- Confirmed outcome.
- Opportunity/product review or explicit No Opportunity.
- Required GPS sync.

Successful Check Out:
- creates server checkout time,
- ends GPS tracking,
- completes visit.

## AI invariant

Show:
- transcript,
- suggested outcome,
- suggested products.

Employee can edit outcome and product selection.
Use visible labels distinguishing AI suggestion from Confirmed by you.

## Quotation invariant

Employee may create and manage permitted quotations.
Pricing is server resolved.
Do not add stock/warehouse controls.

## Review behavior

When a request conflicts with these invariants, preserve the approved behavior and make only the requested visual/layout changes unless the user explicitly changes the product spec.
