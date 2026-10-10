---
title: Statuses & Actions
lastVerified: 2026-10-07
---

# Statuses & Actions

Statuses describe where a record is in its lifecycle. Actions are the controlled transitions that move it forward, backward or into an exception path.

<IerpScreenshot id="global-status-actions" title="Statuses and workflow actions" description="The record status, available header action and an expiry notice on a demo quotation." path="Record details → Header actions" />

## Status colors

<IerpStatus tone="neutral">Draft / inactive / cancelled</IerpStatus>
<IerpStatus tone="primary">Active / in progress / sent</IerpStatus>
<IerpStatus tone="warning">Pending / awaiting / partial</IerpStatus>
<IerpStatus tone="success">Approved / completed / paid</IerpStatus>
<IerpStatus tone="danger">Rejected / failed / blocked</IerpStatus>

Color never carries the meaning alone; the status label remains visible.

## Actions are not ordinary edits

Examples of workflow actions include:

- Send
- Submit
- Approve
- Confirm
- Release
- Post
- Complete
- Resolve
- Close
- Cancel
- Reverse/Correct.

These actions can validate:

- permissions;
- record status;
- source-document relationships;
- stock availability/tracking;
- currency/UOM compatibility;
- payment allocation;
- fiscal period;
- open child work.

## Confirmations

High-impact actions use confirmation dialogs. Read:

1. what record will change;
2. what downstream work can be created;
3. whether the action is reversible;
4. whether a reason/evidence is required.

## Posted history

When a transaction has crossed a posting boundary, IERP prefers:

- correction;
- reversal;
- return;
- credit note;
- refund;
- compensating journal entry.

It does not treat destructive editing of historical truth as normal workflow.

## Partial states

A partial state is real work still in progress. Examples include:

- partially received purchase order;
- partially allocated payment;
- partially available sales order;
- supplier confirmation with only some committed quantity.

Do not close a process only because one child document completed.

## Next

Use [Business Workflows](/workflows/) to see how statuses connect across modules.
