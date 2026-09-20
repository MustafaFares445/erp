# Information Architecture & Navigation

## 1. Primary app structure

Recommended mobile navigation for the pen.dev build:

1. Home
2. Tasks
3. Visits
4. Sales
5. Notifications

Profile / Security is accessed from the employee avatar in the top app bar.

### Why this differs visually from the approved six-item list

The approved behavior names six top-level destinations including Account. This UI handoff keeps every feature but recommends five persistent bottom destinations and moves Account behind the always-visible avatar.

This is a UX presentation recommendation, not a change to business behavior. It reduces bottom-bar crowding and keeps the most frequent field-sales destinations one tap away.

If the product owner wants exact six-item bottom navigation, pen.dev should create a second navigation variant for comparison rather than dropping any destination.

## 2. Top-level hierarchy

Home
- Active plan summary
- Today tasks
- Today visits
- Active visit
- Performance snapshot
- Unread notifications
Tasks
- Today
- Upcoming
- Overdue
- Completed
- Task details
- Start task
- Complete task

Visits
- Today
- Upcoming
- Completed
- Visit details
- Check In
- Active visit
- Voice notes
- AI review
- Check Out
- Completed visit summary
- Visit review conversation

Sales
- Opportunities
- Opportunity details
- Quotations
- Quotation details
- Create/Edit quotation
- Requote where permitted

Notifications
- All
- Unread
- Deep-link destination

Profile / Security
- Employee details
- Performance
- Salary & bonus
- Device binding status
- Change password
- Logout
## 3. Navigation rules

- Bottom navigation remains visible on top-level lists and Home.
- Hide bottom navigation during focused workflows: First Login, Check In confirmation, Active Visit, AI Review, Check Out confirmation, Create/Edit Quotation.
- Back navigation must never silently discard entered visit data or quotation edits.
- Active Visit is a protected flow: leaving it returns to the same active visit state.
- A notification deep link opens the target record, preserving the correct parent section.
- Completed visit content is read-only except the admin conversation reply area.
- Profile is not a work section and should not compete with Tasks/Visits/Sales for persistent bottom-nav space.

## 4. Global top app bar

Default:
- Screen title.
- Employee avatar on the trailing side.
- Optional contextual icon only when needed.

Home:
- Greeting + employee first name.
- Avatar.
- Optional notification shortcut only if the bottom navigation is temporarily hidden.

Active Visit:
- Customer name.
- Running duration.
- GPS tracking status indicator.
- No unrelated global actions.

## 5. Navigation naming

Use short nouns:
- Home
- Tasks
- Visits
- Sales
- Notifications
- Profile

Avoid technical backend terms such as PlanTask, CustomerVisit, SalesOpportunityStatus, or Device Binding in end-user labels.
