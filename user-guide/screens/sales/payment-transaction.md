---
title: "Payment Transactions"
module: sales
pages: ["index", "view"]
screenshots: ["sales-payment-transaction-index", "sales-payment-transaction-view"]
lastVerified: 2026-10-07
---

# Payment Transactions

<IerpNavigationPath :items='["Sales", "Billing & Payments", "Payment Transactions"]' />

<IerpPageSummary purpose="Inspect provider-facing or immutable payment transaction events and their current processing/refund state without rewriting payment history." useWhen="You need to work with payment transactions records or follow their lifecycle." owner="Sales" />

## Overview

Inspect provider-facing or immutable payment transaction events and their current processing/refund state without rewriting payment history.

Sales controls the customer commercial lifecycle from quotation and confirmed demand through fulfillment, billing, collection and credit correction.

## How to open it

Use the dashboard module switcher and follow **Sales → Billing & Payments → Payment Transactions**. The exact visible options depend on your role.

## Screens in this area

| Screen | What you use it for |
| --- | --- |
| List page | The main Payment Transactions list. Use it to search, filter, sort, open records and run the actions available to your role. |
| Record details | The Payment Transactions detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions. |

## When to use this page

Use **Payment Transactions** when your work belongs to this Sales responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.

## Typical tasks

- Find an existing record using search, views and filters, then open it from the working list.
- Review the record's current status, source relationships, totals/evidence and related work from the detail page.
- Move the record through its workflow using actions such as **Refresh Status**, **Retry Settlement** rather than changing status fields manually.

## Verified operating behavior

- Payment Transactions expose provider-side payment state separately from IERP settlement state. A provider success is not the same thing as a successfully created/allocated ERP payment.
- Refresh Status reconciles an unsettled transaction with the configured provider. The action never writes Succeeded by hand; provider reconciliation is the source of provider status.
- Retry Settlement is available only when the provider transaction is Succeeded but ERP settlement is still incomplete. It retries the internal settlement without pretending the provider charged the customer again.
- Use the Requires Attention filter to find provider/ERP settlement mismatches. Review purpose, provider identifiers, failure information, linked ERP payment and settlement description before retrying.

## List page

<IerpScreenshot id="sales-payment-transaction-index" title="Payment Transactions — List page" description="The main Payment Transactions list. Use it to search, filter, sort, open records and run the actions available to your role." path="Sales → Billing & Payments → Payment Transactions" imageSrc="/images/en/sales/sales-payment-transaction-index.png" />

The main Payment Transactions list. Use it to search, filter, sort, open records and run the actions available to your role.

### Provider state and ERP settlement

The list intentionally shows **Provider status** beside **ERP settlement**. A transaction can have succeeded at the provider while still requiring ERP settlement.

Use **Refresh provider status** to reconcile an unsettled transaction with the provider. Use **Complete ERP settlement** only for a provider-Succeeded transaction that has not finished internal settlement. Neither action lets an operator type or force Succeeded manually.

The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.

### Main table information

The list can expose the following record information, depending on permissions and table configuration:

- **Checkout Session**
- **Customer Company Name**
- **Purpose Type**
- **Amount**
- **Provider**
- **Status**
- **Settlement State**
- **Payment Payment Number**
- **Created At**

### Filters

Use filters to narrow the working set without changing records:

- **Status** (SelectFilter)
- **Purpose Type** (Filter)
- **Mismatch** (Filter)

## Record details

<IerpScreenshot id="sales-payment-transaction-view" title="Payment Transactions — Record details" description="The Payment Transactions detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions." path="Sales → Billing & Payments → Payment Transactions → Record details" imageSrc="/images/en/sales/sales-payment-transaction-view.png" />

The Payment Transactions detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions.

### Reconciliation evidence

The detail screen keeps provider identifiers, amount, purpose, failure information, last provider event, idempotency key, settlement description and any linked ERP Payment together. Use the linked Payment to inspect allocations/accounting after settlement.

### Information shown on the detail screen

The detail/infolist implementation can expose:

- **Provider**
- **Amount**
- **Customer Company Name**
- **Status**
- **Purpose Display**
- **Purpose Amount**
- **Failure Code**
- **Failure Message**
- **Checkout Session**
- **Payment Intent**
- **Provider Charge**
- **Last Provider Event**
- **Idempotency Key**
- **Settlement State**
- **Settlement Description**
- **Payment Payment Number**
- **Payment Status**
- **Allocated Amount**
- **Customer Deposit**
- **Created At**
- **Succeeded At**
- **Cancelled At**
- **Refunded At**

## Actions and lifecycle controls

The implementation currently exposes action definitions including:
- **Refresh Status** — executes this named workflow action after validating the current status, permission and prerequisites.
- **Retry Settlement** — retries the previously failed transition using the current validated source data.

High-impact actions can change stock, money, approvals or lifecycle state. Read the confirmation dialog before continuing.

## Statuses used by this record

The current status vocabulary is:

<IerpStatus tone="warning">Pending</IerpStatus> <IerpStatus tone="neutral">Requires Action</IerpStatus> <IerpStatus tone="success">Succeeded</IerpStatus> <IerpStatus tone="danger">Failed</IerpStatus> <IerpStatus tone="danger">Cancelled</IerpStatus> <IerpStatus tone="warning">Partially Refunded</IerpStatus> <IerpStatus tone="neutral">Refunded</IerpStatus>

Use the action buttons exposed by the record to move between statuses. The presence of a status does not mean every user may perform the transition.

## Business effect

Sales may create or update downstream fulfillment, invoice, payment and accounting work. Stock changes only through Inventory actions.

## Common reasons an action is unavailable

- Your role does not have the required permission.
- The record is not in the status required by that action.
- A prerequisite approval, source document or configuration is missing.
- A posted or confirmed transaction is intentionally immutable and needs a correction or reversal workflow.
- A dependent module still has unfinished work, such as receiving, payment, maintenance or fulfillment.

## Related workflows

- [Quote To Cash](/workflows/quote-to-cash)
- [Customer Return To Refund](/workflows/customer-return-to-refund)
- [Sales Shortage Procurement](/workflows/sales-shortage-procurement)
