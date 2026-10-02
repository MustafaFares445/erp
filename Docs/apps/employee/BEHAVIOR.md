# Employee Sales App V1 Behavior

---
status: canonical
owner: employee-app
last_verified: 2026-10-02
verified_against: approved employee V1 specification and current backend audit
---

## Account / Access

V1 expects:
- admin-created employee account;
- temporary password/first-login password change;
- one active bound device;
- support/admin reset rather than self-service device bypass;
- inactive/archived employee blocked;
- server-side employee ownership for all data.

The current mobile API/device-binding exposure is not yet implemented; see Backend Dependencies.

## Home

Home prioritizes:
- active plan;
- today tasks;
- today visits;
- active visit;
- performance snapshot;
- unread notifications.

## Plans / Tasks

Employee sees only their plan/task scope.

Task state:
`Pending -> In Progress -> Completed`, with Cancelled controlled by backend/admin rules.

Employee app must not invent unsupported transitions such as reopen/back-to-pending.

## Visits

Visit state:
`Planned -> In Progress -> Completed`, with Missed as the alternate operational result.

Visit detail includes customer/location/schedule context and map access.

## Check In

Approved V1 behavior requires server validation of:
- network availability for check-in submission;
- location permission/data;
- configured customer coordinates;
- geofence radius;
- visit ownership/status.

Successful check-in starts active-visit timing and periodic GPS capture.

## GPS

GPS trail belongs only to an active visit.

Current backend already has `VisitGpsLog` persistence and Filament trail display.

Approved V1 expects:
- periodic append-only points;
- local queue during temporary network loss;
- upload on reconnection;
- no new GPS points after check-out.

The API write path/batch endpoint remains future integration work.

## Voice / AI Review

1. Employee may enter outcome manually or upload Voice Note.
2. Audio is transcribed.
3. AI/keyword processing may suggest outcome/products/opportunity evidence.
4. Suggested content remains draft.
5. Employee reviews/edits.
6. Employee explicitly confirms opportunity/products or chooses No Opportunity.
7. AI failure must not block manual completion.

Current backend includes voice-note intake, transcription abstraction, OpenAI Whisper implementation, fake test transcriber and keyword/opportunity review services.

## Check Out

Check-out is blocked until required visit completion facts are satisfied:
- active visit;
- confirmed outcome;
- opportunity review/No Opportunity;
- required GPS synchronization according to final API contract.

Successful checkout records the authoritative server timestamp and ends active tracking.

## Opportunities / Quotations

Employee sees only permitted Sales Opportunities.

Employee may:
- review opportunity status/context;
- edit allowed fields through backend rules;
- confirm/edit AI product suggestions;
- create Draft Quotation for customer;
- create quotation from eligible opportunity;
- prefill confirmed products;
- review quantity/UOM/server-resolved price;
- send/requote only when lifecycle permissions allow.

Prices/currency/price-floor rules remain server-side.

No Inventory/Warehouse/Delivery execution appears in Employee V1.

## Performance / Salary / Bonus

The app displays server-calculated:
- performance;
- task/visit/schedule/work-time measures available to the employee;
- salary calculation;
- bonus/final salary;
- calculation/confirmation status.

The mobile client never recomputes performance or pay.

## Visit Review Conversation

Approved V1 calls for a chronological two-way visit-review conversation:
- original admin review is not edited by employee;
- replies are appended as separate messages;
- read/unread behavior;
- admin and employee notifications;
- audit trail.

This conversation model/API is not established by the current Employee domain audit and remains backend integration work unless implemented by the active plan/change.

## Notifications

V1 requires in-app + mobile push for task/visit/opportunity/quotation/performance/salary/security events.

Each notification should deep-link to the correct owned record.

Sensitive data should not be embedded in push body.

## Security / Ownership

Future Employee API must derive employee identity from the authenticated account; never trust a client-supplied `employee_id`.

Required guarantees:
- active/not archived;
- record belongs to employee scope;
- lifecycle validation server-side;
- private media authorization;
- GPS append-only;
- check-in/check-out/final submit idempotency;
- device binding enforced for active sessions when implemented;
- sensitive app actions audited with an employee-app source channel.

## Screen Inventory

Required screens:

- Splash / Session Check
- Login
- First Password Change
- Device Rejected / Contact Support
- Home
- Current Plan
- Tasks List / Details
- Visits List / Details
- Check In confirmation and error states
- Active Visit
- Voice Recorder / upload state
- AI Processing
- Review & Confirm Outcome / Products
- Check Out confirmation
- Completed Visit Summary
- Sales Opportunities List / Details
- Quotations List / Details
- Create/Edit Draft Quotation
- Performance Details
- Salary & Bonus Details
- Notifications
- Visit Review Conversation
- Profile / Security

The Pen file is the visual source for the exact current screen composition.
