---
title: Troubleshooting
description: Common reasons an IERP action may be unavailable or blocked.
---

# Troubleshooting

## I cannot see an action

Possible reasons:

- your role does not have the required permission;
- the record is in a status where the action is not valid;
- a prerequisite document or approval is missing;
- the feature is disabled by rollout configuration.

## I cannot confirm or release a Sales Order

Check that the customer is active, the lines are complete, and the order is in the expected status.

## I cannot receive a Purchase Order

Check that the purchasing flow has created the expected inbound/receipt work and that you are using the Inventory receiving action. Purchasing itself does not post stock.

## Stock does not match the warehouse

Use Inventory counts, adjustments, or correction workflows. Do not directly edit stock values.

## Payment cannot be posted

Check the payment method, proof requirements, amount, currency, and invoice allocation limits.

## Support work cannot close

Open loaners, external supplier repairs, required maintenance steps, or other unresolved child work can block closure.

## Still blocked?

Capture the record identifier, current status, action you expected, and the validation message shown by IERP. Share those details with your administrator or support team.
