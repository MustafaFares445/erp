# Visits

## When to use this page

Use this screen to review its available records and supported actions.

## Typical tasks

- Follow the documented “Working with scheduled visits” instructions below.
- Follow the documented “Visit list” instructions below.
- Follow the documented “Create a visit” instructions below.

<IerpNavigationPath :items='["Employees", "Field", "Visits"]' />

Schedule customer visits, inspect field evidence and record administrative review. Employees owns the visit lifecycle; evidence may support a sales opportunity, but Sales still owns quotation and order decisions.

## Working with scheduled visits

1. Open **Employees → Visits** and search by visit reference, customer or employee. Filter by status, scheduled dates, location warnings or follow-up requirement.
2. Check the scheduled start/end and open the **Calendar** for date-based planning. The employee and customer identity must be correct before scheduling.
3. Create a visit with employee, customer, optional plan task or customer-owned serialized equipment, and start/end times.
4. A schedule conflict cannot be bypassed silently. Use **Override scheduling conflict** only with an approved reason when an intentional overlap is needed.
5. Follow progression **Scheduled → En Route → In Progress → Completed**. **Unable to Complete** and **Cancelled** are terminal alternatives.
6. Open details to review check-in/check-out coordinates, location accuracy/warnings, duration, outcome, notes and required follow-up. Use **Add / update review note** when authorized.
7. For a non-terminal visit, use **Reschedule** rather than editing timestamps directly. Follow-up is managed through an Employee plan task.

## Visit list

<IerpScreenshot id="employees-visit-index" title="Visit operations list" description="Locate visits by employee, customer, status and scheduled dates." path="Employees → Visits" />

Search and filter do not alter the visit. The list contains reference, employee, customer, type, scheduled start/end, status, duration, location warning and follow-up indicators.

## Create a visit

<IerpScreenshot id="employees-visit-create" title="Schedule a visit" description="Select an employee and customer, schedule times and check potential conflicts." path="Employees → Visits → Create" imageSrc="/images/en/employees/employees-visit-create.png" />

Serialized equipment is selectable only if it is currently in the same customer's custody. Never attach another customer's equipment.

## Calendar

<IerpScreenshot id="employees-visit-calendar" title="Visit calendar" description="Inspect visit assignments and timing in a calendar layout." path="Employees → Visits → Calendar" />

## Visit details and review

<IerpScreenshot id="employees-visit-view" title="Visit evidence and review" description="Review timing, GPS checks, outcome, linked equipment and follow-up." path="Employees → Visits → View" />

Check whether a location warning was raised; an override must be explicit and attributable. The review note is an administrative observation, not an automatic sales approval.

## Voice/AI and cross-module boundaries

Voice notes pass through pending, processing, transcribed or failed states; transcription may succeed or fail independently. AI keyword evidence is a **review suggestion**, not an approved sales opportunity. Only human-reviewed accepted/approved opportunities contribute to performance, and a transcription failure must never reverse a completed visit.

The employee mobile application is **design/planned integration**; the current deployed runtime has **no /api/employee endpoints**. Dashboard coverage does not establish mobile/API implementation.

Related: [Monthly Plans](/screens/employees/monthly-plan), [Tasks](/screens/employees/task), [Sales Opportunities](/screens/crm/sales-opportunity).
