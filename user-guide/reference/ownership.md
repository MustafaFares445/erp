---
title: Module Ownership
lastVerified: 2026-10-07
---

# Module Ownership

IERP is a modular monolith, but the user experience is intentionally organized around business ownership.

## Core ownership rules

| Business fact | Owning area | Practical meaning |
| --- | --- | --- |
| Customer commercial document | Sales | Quotations, Sales Orders, invoices and credits follow Sales lifecycle rules. |
| Supplier commercial commitment | Purchasing | Purchase Orders, supplier confirmation and sourcing belong to Purchasing. |
| Physical stock truth | Inventory | On-hand/reserved quantities, lots, serials, custody and physical posting belong to Inventory. |
| Ledger truth | Accounting | Journal, receivable/payable and posted financial truth belong to Accounting. |
| Collection/allocation/provider money movement | Payments | Money receipt, allocation and provider settlement are handled through payment workflows. |
| Customer relationship | CRM | Leads, opportunities, customer context and interactions belong to CRM. |
| Employee planning and execution | Employees | Plans, tasks, visits, reviewed opportunities and performance belong to Employees. |
| Service coordination | Support & Maintenance | Tickets, SLA, warranty, service and maintenance coordination belong to Support. |
| Reporting | Reports | Reports read facts; they do not silently repair them. |

## Why this matters

A page can **start** work in another module without owning the final effect.

Examples:

- Purchasing can create receiving work, but only Inventory posts received stock.
- Support can create a chargeable quotation/invoice path, but Sales/Payments/Accounting own the financial document and settlement.
- Sales can create demand, but warehouse lot/serial selection and physical delivery remain Inventory responsibilities.
- CRM can convert an approved return request into an Inventory Return, but approval itself does not move stock.
- Reports can show a discrepancy but the correction must happen in the source module.

## How to decide where to continue

When you reach a step that changes another kind of business truth, move to the module that owns it.

<IerpWorkflow :steps='["Commercial intent", "Owning transaction", "Physical / financial execution", "Posted truth", "Reporting"]' />

This prevents duplicate processes and keeps audit history understandable.
