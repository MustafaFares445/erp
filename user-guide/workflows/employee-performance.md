---
title: "Employee Performance and Salary"
type: workflow
lastVerified: 2026-10-07
---

# Employee Performance and Salary

Turn completed operational evidence into performance review and salary-calculation context.

<IerpWorkflow :steps='["Plan", "Tasks / Visits", "Approved Outcomes", "Performance", "Salary Calculation"]' />

## Modules involved

Employees.

## When to use this workflow

Use this workflow when you need to complete **Employee Performance and Salary** end to end while preserving the responsibility boundaries between modules.

## Before you start

- Confirm required master records such as customer, supplier, product or equipment are active.
- Confirm your role has permission for the high-impact actions in each module.
- Review currency, UOM, warehouse and payment-term context when they apply.
- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.

## Step by step

### 1. Plan

Set the employee's period objectives/tasks before field execution. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Plan](/screens/employees/monthly-plan)

Plan an employee's monthly sales/activity objectives and tasks before field execution and performance evaluation.

### 2. Tasks / Visits

Execute or follow the assigned task and record completion/cancellation through its lifecycle. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Tasks / Visits](/screens/employees/task)

Manage employee work items and follow-up tasks through pending, in-progress, completed or cancelled states.

### 3. Approved Outcomes

Execute the scheduled customer visit through travel, in-progress work, outcome and evidence. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Approved Outcomes](/screens/employees/visit)

Track field visits from scheduling and travel through check-in, execution, outcome, evidence and follow-up.

### 4. Performance

Review calculated performance evidence from completed operational work rather than entering a manual score in isolation. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Performance](/screens/employees/performance)

Review employee performance factors derived from completed operational evidence rather than free-form manual scoring alone.

### 5. Salary Calculation

Review the salary result generated from the configured compensation/performance context before confirmation. Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.

**Work in:** [Salary Calculation](/screens/employees/salary-calculation)

Calculate and review salary/compensation results from the implemented employee rules and move calculations through confirmation states.

## Important business rules

- Follow-up tasks are excluded from the base plan-task performance denominator.
- Only approved opportunity outcomes contribute where that performance factor applies.

## Exceptions and blocked states

- If the next action is missing, verify permissions, status and prerequisites.
- Do not skip a step by forcing a status; use the dedicated workflow action.
- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.
- Corrections after posting use correction/reversal paths instead of deleting history.

## Related screens

- [Employees](/screens/employees/)

## Expected result

The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion.
