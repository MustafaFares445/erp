---
title: "Employee Field Sales"
type: workflow
lastVerified: 2026-10-07
---

# Employee Field Sales

Execute an employee plan through tasks and visits, capture outcomes, follow-up and approved sales opportunities, then continue into Sales.

<IerpWorkflow :steps='["Monthly Plan", "Task", "Visit", "Customer", "Sales Action", "Outcome", "Follow-up", "Opportunity / Quotation"]' />

## Modules involved

Employees, CRM, Sales.

## When to use this workflow

Use this workflow when you need to complete **Employee Field Sales** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Monthly Plan

Set the employee's period objectives/tasks before field execution. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Monthly Plan](/screens/employees/monthly-plan)

Plan an employee's monthly sales/activity objectives and tasks before field execution and performance evaluation.

### 2. Task

Execute or follow the assigned task and record completion/cancellation through its lifecycle. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Task](/screens/employees/task)

Manage employee work items and follow-up tasks through pending, in-progress, completed or cancelled states.

### 3. Visit

Execute the scheduled customer visit through travel, in-progress work, outcome and evidence. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Visit](/screens/employees/visit)

Track field visits from scheduling and travel through check-in, execution, outcome, evidence and follow-up.

### 4. Customer

Confirm the customer profile and commercial defaults are ready for the next business document. Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.

**Work in:** [Customer](/screens/crm/customer)

Maintain the customer master profile and review approval, commercial defaults, addresses, interactions, sales, service and equipment context in one place.

### 5. Sales Action

Execute the scheduled customer visit through travel, in-progress work, outcome and evidence. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Sales Action](/screens/employees/visit)

Track field visits from scheduling and travel through check-in, execution, outcome, evidence and follow-up.

### 6. Outcome

Execute the scheduled customer visit through travel, in-progress work, outcome and evidence. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Outcome](/screens/employees/visit)

Track field visits from scheduling and travel through check-in, execution, outcome, evidence and follow-up.

### 7. Follow-up

Execute or follow the assigned task and record completion/cancellation through its lifecycle. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Follow-up](/screens/employees/task)

Manage employee work items and follow-up tasks through pending, in-progress, completed or cancelled states.

### 8. Opportunity / Quotation

Review the commercial opportunity stage/value and progress it toward quotation or closure. Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.

**Work in:** [Opportunity / Quotation](/screens/crm/sales-opportunity)

Track active revenue opportunities and their sales stage until won/lost or converted into the appropriate commercial follow-up.

## Important business rules

- Visit lifecycle is Scheduled → En Route → In Progress → Completed, with Unable to Complete and Cancelled terminal alternatives.
- Only approved employee sales opportunities contribute to the opportunity performance factor.
- Employee-created quotations still follow the normal Sales lifecycle.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Employees](/screens/employees/)
- [CRM](/screens/crm/)
- [Sales](/screens/sales/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
