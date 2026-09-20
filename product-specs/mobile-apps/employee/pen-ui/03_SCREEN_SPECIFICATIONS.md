# Screen Specifications

Use these as screen contracts for the pen.dev canvas. Every screen should have default, loading, empty/error where relevant.

## A. Authentication & Security

### A01 — Splash / Session Check
Purpose: restore an existing valid employee session.
Content: logo/brand mark, minimal progress state.
Outcomes: Home, First Password Change, Login, or Device Rejected.

### A02 — Login
Content:
- Employee email/username field.
- Password field with reveal control.
- Sign In primary CTA.
- Support help entry.
States: default, submitting, invalid credentials, inactive account, network error.

### A03 — First Password Change
Content:
- Explanation that temporary password must be replaced.
- New password.
- Confirm password.
- Password requirements.
- Continue CTA.
Rule: no skip/back into the app.

### A04 — Device Rejected
Content:
- Clear one-device-account explanation.
- Current attempt cannot continue.
- Contact Support CTA.
- Sign out / return to login.
Do not display raw device fingerprint or internal IDs.
## B. Home, Plan and Tasks

### B01 — Home
Priority order:
1. Active Visit banner, when present.
2. Next visit / next task CTA.
3. Today's visits.
4. Today's tasks.
5. Active plan progress.
6. Performance snapshot.
7. Unread/admin-message indicator.

### B02 — Current Plan
Read-only.
Show plan name, month, status, required visit minutes, task progress, visit progress, and performance summary.

### B03 — Tasks List
Tabs/filters: Today, Upcoming, Overdue, Completed.
Task card: title, customer, due time/date, status, urgency indicator.

### B04 — Task Details
Show title, description, customer, schedule, status, completion date/history summary.
Actions:
- Pending -> Start Task.
- InProgress -> Complete Task.
- Completed -> no state-changing CTA.
Never show Cancel, Reopen, or Back to Pending.

## C. Visits

### C01 — Visits List
Sections: Today, Upcoming, Completed.
Visit card: customer, scheduled time, status, address summary, task relation, duration if completed.
### C02 — Visit Details / Pre-Check-In
Show:
- Customer/company identity.
- Contact actions.
- Address.
- Mini map.
- Scheduled time.
- Linked task.
- Visit status.
- Required duration.
Actions:
- Open in Maps.
- Check In.

### C03 — Check-In Confirmation
Focused modal/full screen.
Show customer, current distance/inside-area state, tracking disclosure, and Confirm Check In.
Validation states link to the dedicated error treatments.

### C04 — Active Visit
Most important operational screen.
Persistent header:
- Customer.
- Running elapsed duration.
- GPS tracking status.
Body:
- Visit objective/task.
- Outcome draft.
- Voice notes.
- Attachments.
- AI processing card.
- Sales opportunity draft.
Sticky CTA: Review Visit / Continue.

### C05 — Voice Recorder
Large record control, timer, pause/stop as supported, cancel, save/upload.
States: ready, recording, upload, uploaded, failed.
Explain that audio is used to prepare visit outcome and sales suggestions.
### C06 — AI Processing
Show each voice note and status: Processing, Ready, Failed.
Do not block the rest of the visit.
When ready, CTA opens Review & Confirm.

### C07 — Review & Confirm
Sections:
1. Transcript, collapsible.
2. Outcome suggestion with editable final outcome.
3. Suggested products.
4. Add product from catalog.
5. Opportunity details where applicable.
6. No Opportunity option.
7. Confirmation checkbox/control.
Primary CTA: Continue to Check Out.
Clearly distinguish "AI suggested" from "Confirmed by you".

### C08 — Product Picker
Search/filter product catalog.
Product rows show business-friendly name, variant/SKU where useful, availability of selection only—not warehouse controls.
Multi-select/add to opportunity.

### C09 — Check-Out Confirmation
Summary:
- Customer.
- Elapsed visit duration.
- Outcome confirmed.
- Number of opportunities/products.
- GPS sync status.
Primary CTA: Complete Visit.
Require internet and successful prerequisite validation.

### C10 — Completed Visit Summary
Read-only visit result:
- Check In / Check Out.
- Duration.
- Outcome.
- Confirmed products/opportunities.
- Voice note/transcript status.
- Attachment count.
- Quotation link if created.
- Admin conversation entry.
### C11 — Visit Conversation
Chronological messages.
Differentiate Admin and You.
Composer at bottom with Send.
Unread separator and timestamps.
Original admin review content remains intact.

## D. Sales

### D01 — Sales Hub
Two primary sections/cards:
- Opportunities.
- Quotations.
Also show recent items and actionable statuses.

### D02 — Opportunities List
Filters: status/stage, customer, recent.
Card: title, customer, stage, review status, estimated value, expected close date.

### D03 — Opportunity Details
Show customer, originating visit, AI/manual origin indicator, final summary, confirmed products, value/currency, stage, review status.
CTA Create Quotation only when backend rules allow.

### D04 — Quotations List
Filters: Draft, Sent, Accepted, Rejected, Expired.
Card: quotation number, customer, amount, status, issue/expiry date.

### D05 — Create/Edit Quotation
Fields/sections:
- Customer.
- Linked opportunity, if any.
- Quotation lines.
- Quantity/unit.
- Server-resolved price.
- Notes.
- Validity/expiry where editable.
Actions: Save Draft, Send when allowed.
Never expose stock-movement actions.
### D06 — Quotation Details
Show number, customer, lines, totals, status timeline, issue/expiry, linked opportunity.
Actions are status-dependent: Edit Draft, Send, Requote where allowed.

## E. Performance, Notifications and Profile

### E01 — Performance Details
Show total score and four server-calculated components:
- Task completion.
- Visit completion.
- Schedule adherence.
- Work-time adherence.
Use progress visuals plus exact values.

### E02 — Salary & Bonus
Show employee-only calculation:
- Base/payable salary.
- Performance percent.
- Bonus amount.
- Final salary.
- Calculation/approval status.
Historical periods may be listed read-only.

### E03 — Notifications
All / Unread filter.
Rows: icon/category, title, concise body, time, unread mark.
Tap deep-links to target record.

### E04 — Profile / Security
Show employee identity, employee code, job title, phone/email, device status.
Actions: Change Password, Logout.
Device reset is not self-service.

## F. Required utility states

Design reusable full-screen or inline states for:
- Loading/skeleton.
- Empty list.
- No active plan.
- No tasks today.
- No visits today.
- Network required.
- Location permission required.
- Customer location missing.
- Outside geofence.
- GPS temporarily waiting for sync.
- Upload failed.
- AI processing failed.
- Server validation conflict.
- Session expired.
