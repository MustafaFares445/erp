<?php

declare(strict_types=1);

// Human-reviewed operational explanations; read-only guidance, not workflow rules.
return [
    'adjustment' => [
        'confirmed' => [
            'title' => 'Adjustment posted',
            'explanation' => 'The approved adjustment is recorded. Review its linked inventory movements rather than editing the posted document.',
        ],
        'corrected' => [
            'title' => 'Adjustment corrected through a linked record',
            'explanation' => 'A correcting transaction is linked to this adjustment. Review both records to understand the net stock effect.',
        ],
        'corrects' => [
            'title' => 'Adjustment is a correction',
            'explanation' => 'This adjustment refers to another record. Check the original posting and correction reason before further action.',
        ],
        'empty' => [
            'title' => 'Adjustment has no product lines',
            'explanation' => 'There are no product quantities to reconcile in this adjustment. Add valid lines while it is still editable.',
        ],
        'inactive_warehouse' => [
            'title' => 'Adjustment warehouse is inactive',
            'explanation' => 'The referenced warehouse is inactive. Check its status before attempting to approve inventory changes.',
        ],
        'ready_to_confirm' => [
            'title' => 'Adjustment is ready for independent confirmation',
            'explanation' => 'The draft passed these checks. Another authorized reviewer must confirm it before the stock change is posted.',
        ],
    ],
    'alert' => [
        'condition_cleared' => [
            'title' => 'Alert condition is no longer present',
            'explanation' => 'The condition that originally raised the alert was not found in the current check. Review the alert history before resolving it.',
        ],
        'resolved' => [
            'title' => 'Alert was resolved',
            'explanation' => 'The alert is marked resolved. Its original evidence remains available for audit; no new stock action is required by this message.',
        ],
    ],
    'condition_change' => [
        'authoriser_is_creator' => [
            'title' => 'Authorizer and creator are the same person',
            'explanation' => 'This record does not meet the separation-of-duties expectation. Request review by a different authorized employee.',
        ],
        'authoriser_missing' => [
            'title' => 'Missing condition-change authorization',
            'explanation' => 'No authorizing user is recorded for this change. Check the required approval before posting.',
        ],
        'cancelled' => [
            'title' => 'Condition change was cancelled',
            'explanation' => 'The change has been cancelled. Check its history; do not post or edit it as an active request.',
        ],
        'damage_open' => [
            'title' => 'Damage case is still open',
            'explanation' => 'There is an unresolved damage-related record for this stock. Review inspection and disposition before deciding its final condition.',
        ],
        'evidence_missing' => [
            'title' => 'Evidence for the condition change is missing',
            'explanation' => 'The record lacks evidence required by this type of condition change. Attach or verify the required inspection information before approval.',
        ],
        'inactive_warehouse' => [
            'title' => 'Stock-condition warehouse is inactive',
            'explanation' => 'The warehouse linked to the request is inactive. Verify the warehouse and stock location before proceeding.',
        ],
        'insufficient_quarantine' => [
            'title' => 'Not enough quarantined stock for the action',
            'explanation' => 'The requested disposition exceeds the quantity currently recorded in quarantine. Review balances and prior movements before posting.',
        ],
        'invalid_reversal' => [
            'title' => 'Condition-change reversal does not match its source',
            'explanation' => 'The reversal cannot be reconciled with its original condition change. Check the source reference, quantities and state transitions.',
        ],
        'no_stock' => [
            'title' => 'No eligible stock for condition change',
            'explanation' => 'The source condition has no eligible quantity for this action. Review the SKU, warehouse and stock-condition balance.',
        ],
        'ready_to_post' => [
            'title' => 'Condition change can proceed to posting review',
            'explanation' => 'The recorded prerequisites passed these checks. Verify inspection evidence and use only the permitted posting action.',
        ],
        'recovery_exceeds' => [
            'title' => 'Recovery quantity exceeds recorded damage',
            'explanation' => 'The requested recovery exceeds the damaged quantity available for recovery. Review previous recoveries and condition balances.',
        ],
    ],
    'correction' => [
        'cancelled' => [
            'title' => 'Receipt correction was cancelled',
            'explanation' => 'This correction is cancelled and has no pending posting. The original receipt remains part of the audit trail.',
        ],
        'empty' => [
            'title' => 'Receipt correction has no lines',
            'explanation' => 'No corrective quantities have been entered. Review the original receipt and add valid correction lines before proceeding.',
        ],
        'movement_missing' => [
            'title' => 'Original receipt movement cannot be found',
            'explanation' => 'The movement required to support this correction was not found. Check the source posting before further stock changes.',
        ],
        'not_saleable' => [
            'title' => 'Source quantity is not in saleable condition',
            'explanation' => 'The correction involves quantity in a condition that cannot be treated as normal saleable stock. Review the condition ledger and original receipt.',
        ],
        'original_mismatch' => [
            'title' => 'Correction does not match its original receipt',
            'explanation' => 'The linked receipt and corrective lines disagree. Verify product variant, warehouse and original quantities.',
        ],
        'original_not_done' => [
            'title' => 'Original receipt is not completed',
            'explanation' => 'The referenced receipt has not reached its completed stage. Review the original workflow before creating a post-completion correction.',
        ],
        'ready_to_post' => [
            'title' => 'Receipt correction is ready for authorization',
            'explanation' => 'The recorded prerequisites passed. Confirm the correction quantities and source document before an authorized posting.',
        ],
        'serial_quantity' => [
            'title' => 'Correction quantity conflicts with serial tracking',
            'explanation' => 'The requested quantity does not match the serialized units involved. Verify each serial\'s history before correcting the receipt.',
        ],
        'transfer_partially_received' => [
            'title' => 'Linked transfer was only partly received',
            'explanation' => 'The stock connected to this correction is involved in a partially received transfer. Resolve outstanding transfer quantities before deciding the correction.',
        ],
    ],
    'count' => [
        'awaiting_confirmation' => [
            'title' => 'Count submitted for confirmation',
            'explanation' => 'The counting work is submitted, but an authorized independent reviewer has not confirmed it yet. Check differences and recount requests.',
        ],
        'cancelled' => [
            'title' => 'Inventory count was cancelled',
            'explanation' => 'The count was cancelled and cannot be treated as approved physical stock. Refer to its historical record if investigating a discrepancy.',
        ],
        'confirmed' => [
            'title' => 'Inventory count has been confirmed',
            'explanation' => 'The count completed independent confirmation. Review any linked adjustment and movements to understand its inventory impact.',
        ],
        'confirmed_no_variance' => [
            'title' => 'Confirmed count has no quantity differences',
            'explanation' => 'The confirmed quantities match recorded balances in this count. This finding does not replace later periodic counts.',
        ],
        'no_lines' => [
            'title' => 'No items were generated for the count',
            'explanation' => 'The selected scope produced no count lines. Verify the warehouse, selection criteria and count scope before continuing.',
        ],
        'partial' => [
            'title' => 'Not every count item has been recorded',
            'explanation' => 'Some required quantities are not recorded. Complete the remaining count lines before requesting review.',
        ],
        'ready_for_review' => [
            'title' => 'Count is ready for independent review',
            'explanation' => 'All required counting entries are present for review. The authorized reviewer should verify discrepancies before confirmation.',
        ],
        'recount_requested' => [
            'title' => 'A physical recount has been requested',
            'explanation' => 'One or more lines need another physical count. Record the requested recount before confirming the result.',
        ],
        'unvalued_variances' => [
            'title' => 'Count differences need valuation review',
            'explanation' => 'Quantity differences are recorded without the necessary valuation information. Ask an authorized accounting or inventory reviewer to assess the financial effect.',
        ],
    ],
    'inbound' => [
        'awaiting_confirmation' => [
            'title' => 'Supplier confirmation is still pending',
            'explanation' => 'The purchase inbound does not yet have all required supplier confirmation. Check the supplier commitment before planning warehouse receipts.',
        ],
        'cancelled' => [
            'title' => 'Purchase inbound was cancelled',
            'explanation' => 'This inbound is cancelled. Do not plan new warehouse receipts against it; review existing linked records if any.',
        ],
        'over_allocated' => [
            'title' => 'Inbound allocations exceed confirmed quantity',
            'explanation' => 'Warehouse receipt allocations exceed the supplier-confirmed quantity. Compare each allocation with supplier confirmation before receiving.',
        ],
        'overdue' => [
            'title' => 'Expected inbound delivery is late',
            'explanation' => 'The expected date has passed and some quantity is still outstanding. Review the supplier commitment and receiving schedule.',
        ],
        'receipt_not_created' => [
            'title' => 'Confirmed inbound still needs a receipt',
            'explanation' => 'Confirmed quantity is allocated but the corresponding warehouse receipt has not been created. Check inbound allocation and create the receiving document through its workflow.',
        ],
        'receipt_open' => [
            'title' => 'Inbound receipt has not been completed',
            'explanation' => 'The linked receipt is open. Warehouse stock will change only when the authorized receiving workflow is completed.',
        ],
        'supplier_shortfall' => [
            'title' => 'Supplier confirmation is short of ordered quantity',
            'explanation' => 'The supplier-confirmed quantity does not cover the ordered amount. Review the shortage and agree the remaining supply before planning full receipt.',
        ],
    ],
    'lot' => [
        'expiring_' => [
            'title' => 'Lot is approaching its expiry threshold',
            'explanation' => 'The lot is approaching a configured expiry window. Review the actual expiry date, remaining stock and FEFO policy before allocating it.',
        ],
    ],
    'movement' => [
        'condition_change' => [
            'title' => 'Movement changed a stock condition',
            'explanation' => 'This movement records a condition transition, not necessarily a physical relocation. Review its linked disposition record and quantities.',
        ],
        'is_reversal' => [
            'title' => 'This movement reverses an earlier posting',
            'explanation' => 'The movement was recorded to reverse an earlier stock effect. Review the original movement and reversal quantity together.',
        ],
        'no_source' => [
            'title' => 'Movement has no recognized source document',
            'explanation' => 'The movement\'s source document is not available in the normal links. Escalate for an audit review instead of manually changing the stock history.',
        ],
        'source_deleted' => [
            'title' => 'Source document was deleted',
            'explanation' => 'The movement remains recorded, but its original document is soft-deleted or unavailable. Review the audit trail for its provenance.',
        ],
        'source_missing' => [
            'title' => 'Linked source document cannot be found',
            'explanation' => 'A referenced source document cannot be resolved. Verify the historical posting and relationship before correcting balances.',
        ],
    ],
    'operation' => [
        'canceled' => [
            'title' => 'Inventory operation was cancelled',
            'explanation' => 'The operation is cancelled and should no longer progress. Check any linked reservations or shipments when reviewing its history.',
        ],
        'delivery_ready' => [
            'title' => 'Delivery is ready for dispatch',
            'explanation' => 'The delivery passed readiness checks, but physical stock has not been dispatched by this stage alone. Verify the goods and planned shipment before Dispatch.',
        ],
        'done' => [
            'title' => 'Inventory operation is completed',
            'explanation' => 'This operation is marked complete. Review posted stock movements to verify what changed instead of running it again.',
        ],
        'draft_' => [
            'title' => 'Inventory operation is still a draft',
            'explanation' => 'This operation has not reached the posting stage. Review its lines, destination and tracking requirements before preparing it.',
        ],
        'draft_shortage' => [
            'title' => 'Draft operation requires more stock',
            'explanation' => 'The available quantity does not cover one or more requested lines. Review source stock and replenish before expecting readiness.',
        ],
        'inactive_variant' => [
            'title' => 'Operation contains an inactive product variant',
            'explanation' => 'A referenced variant is inactive. Check whether it is permitted for this workflow before attempting readiness or posting.',
        ],
        'receipt_ready' => [
            'title' => 'Receipt is ready for completion',
            'explanation' => 'The receipt is prepared, but received quantities are not in on-hand stock until completion. Verify the physical goods and tracking first.',
        ],
        'reservation_missing' => [
            'title' => 'Expected reservation is missing',
            'explanation' => 'This operation\'s preparation depends on a reservation that cannot be found. Review its lines and reservation history before dispatch.',
        ],
        'reservation_overdue' => [
            'title' => 'Operation reservation has passed its expiry',
            'explanation' => 'An active reservation associated with the operation is overdue. Confirm that the quantity remains eligible before dispatch or manual release.',
        ],
        'serial_unavailable' => [
            'title' => 'Required serialized unit is unavailable',
            'explanation' => 'A line requires a serialized unit that is not eligible or available. Check its warehouse, custody and condition before proceeding.',
        ],
        'stock_now_available' => [
            'title' => 'Previously missing stock may now be available',
            'explanation' => 'Stock availability has improved since the operation last waited. Recheck its preparation conditions before marking it Ready.',
        ],
        'transfer_over_received' => [
            'title' => 'Transfer receipt exceeds dispatched quantity',
            'explanation' => 'The destination recorded more units than were dispatched. Review transfer receipt entries and source movements before closing.',
        ],
        'transfer_ready' => [
            'title' => 'Internal transfer is ready for dispatch',
            'explanation' => 'The source allocation is ready, but the goods have not yet left the source warehouse. Verify items and use authorized dispatch.',
        ],
    ],
    'outbound' => [
        'delivery_waiting' => [
            'title' => 'Outbound delivery is waiting for stock',
            'explanation' => 'The linked delivery could not complete preparation. Review source availability, reservations and tracking requirements.',
        ],
        'draft_delivery' => [
            'title' => 'Outbound delivery is still a draft',
            'explanation' => 'The delivery exists but has not been prepared for stock dispatch. Review its lines and continue via the authorized workflow.',
        ],
        'in_transit' => [
            'title' => 'Outbound shipment is in transit',
            'explanation' => 'The goods were dispatched but the customer arrival confirmation is outstanding. Confirm arrival separately after verification.',
        ],
        'ready_to_dispatch' => [
            'title' => 'Outbound fulfillment is ready for dispatch',
            'explanation' => 'The planned delivery is ready for the next authorized dispatch action. Verify the linked shipment and physical items first.',
        ],
        'waiting_now_covered' => [
            'title' => 'Stock may now cover waiting outbound demand',
            'explanation' => 'Availability has changed since an outbound delivery entered Waiting. Recheck current allocations before preparing it again.',
        ],
    ],
    'package' => [
        'inactive_in_open_documents' => [
            'title' => 'Inactive package appears on open documents',
            'explanation' => 'This package is inactive but remains linked to active documents. Review the affected documents before reassigning or archiving it.',
        ],
        'type_archived' => [
            'title' => 'Package type has been archived',
            'explanation' => 'The selected package type is archived and may not be suitable for new work. Review the package type and open documents.',
        ],
        'type_inactive' => [
            'title' => 'Package type is inactive',
            'explanation' => 'The package uses an inactive type. Review current type settings before using it in a new operation.',
        ],
        'type_missing' => [
            'title' => 'Package type is not set',
            'explanation' => 'This package does not have the required type assignment. Choose an active package type where the workflow allows editing.',
        ],
        'warehouse_inactive' => [
            'title' => 'Package warehouse is inactive',
            'explanation' => 'The warehouse assigned to this package is inactive. Review its location and any linked open documents before further use.',
        ],
    ],
    'reservation' => [
        'allocation_mismatch' => [
            'title' => 'Reservation allocations do not match held quantity',
            'explanation' => 'The sum of lot or serial allocations differs from the reserved quantity. Review each allocation before completing the source operation.',
        ],
        'other_source' => [
            'title' => 'Reservation belongs to another source type',
            'explanation' => 'This reservation was created outside the inventory-operation source type. Review the referenced document and allocations before taking action.',
        ],
        'source_closed' => [
            'title' => 'Reservation still active for a closed operation',
            'explanation' => 'The source operation has finished or closed while this reservation remains active. Review the operation\'s stock movements before any manual release.',
        ],
        'source_deleted' => [
            'title' => 'Reservation refers to a deleted source operation',
            'explanation' => 'The source operation is deleted from normal views. Verify the historical record and allocations before changing reserved stock.',
        ],
        'source_not_ready' => [
            'title' => 'Reservation source operation is not ready',
            'explanation' => 'This reservation is active although its operation is Draft or Waiting. Review how the quantity was allocated and whether the operation can be prepared.',
        ],
    ],
    'return' => [
        'cancelled' => [
            'title' => 'Return was cancelled',
            'explanation' => 'This return was cancelled and has no pending posting. Review its history rather than treating the quantity as returned.',
        ],
        'credit_note_draft' => [
            'title' => 'Linked credit note is still a draft',
            'explanation' => 'The return and its financial follow-up are separate workflows. Review the draft credit note with Accounting before treating the financial side as complete.',
        ],
        'empty' => [
            'title' => 'Return contains no product lines',
            'explanation' => 'No products or quantities are entered. Add valid lines and reference the original document while the return is editable.',
        ],
        'provenance_missing' => [
            'title' => 'Return origin cannot be verified',
            'explanation' => 'The referenced original receipt or delivery cannot be verified. Check the source transaction before approving a stock return.',
        ],
        'ready_to_mark' => [
            'title' => 'Return is ready for its next workflow stage',
            'explanation' => 'The draft passed the recorded checks for moving to the next stage. Verify original quantities and stock condition first.',
        ],
        'ready_to_post' => [
            'title' => 'Return is ready for stock posting',
            'explanation' => 'The recorded prerequisites passed. Verify the physically returned goods and execute the authorized Post action only once.',
        ],
        'source_condition_missing' => [
            'title' => 'Original stock condition is not available',
            'explanation' => 'The source condition needed to evaluate this return is missing. Investigate the original stock record before posting.',
        ],
        'uninspected' => [
            'title' => 'Returned goods still require inspection',
            'explanation' => 'The returned items have not completed the required condition inspection. Record their actual condition before making them saleable.',
        ],
    ],
    'serial' => [
        'adjusted_out' => [
            'title' => 'Serialized unit left stock through an adjustment',
            'explanation' => 'The serial\'s stock balance was adjusted out. Review the adjustment and custody trail; do not treat it as available for another order.',
        ],
        'custody_unknown' => [
            'title' => 'Serialized unit custody is unknown',
            'explanation' => 'No reliable current custodian is recorded for this serial. Verify physical custody and linked movements before reallocating it.',
        ],
        'maintenance_missed' => [
            'title' => 'Scheduled maintenance was missed',
            'explanation' => 'The unit has a scheduled maintenance item that was not completed by its due date. Review the service plan and follow-up assignment.',
        ],
        'maintenance_open' => [
            'title' => 'Serialized unit has an open maintenance request',
            'explanation' => 'A maintenance case is still open for this serial. Review its status and restrictions before deciding whether the device can be delivered.',
        ],
        'warranty_cancelled' => [
            'title' => 'Serialized warranty was cancelled',
            'explanation' => 'The linked warranty is cancelled. Do not promise warranty coverage; review the original delivery and warranty history.',
        ],
        'warranty_ended' => [
            'title' => 'Serialized warranty period has ended',
            'explanation' => 'The recorded warranty end date has passed. Review service eligibility and any contractual exceptions before estimating customer charges.',
        ],
        'warranty_expiring' => [
            'title' => 'Serialized warranty approaches its end date',
            'explanation' => 'The warranty expiry date is near. Review coverage terms and any upcoming maintenance activity.',
        ],
        'warranty_pending_activation' => [
            'title' => 'Serialized warranty has not been activated',
            'explanation' => 'A qualifying warranty may still need the delivery or arrival event required for activation. Check the linked shipment and warranty service.',
        ],
    ],
    'shipment' => [
        'arrived' => [
            'title' => 'Shipment arrival is confirmed',
            'explanation' => 'The shipment is marked Arrived. Review the confirmation actor, time and delivery evidence for traceability.',
        ],
        'cancelled' => [
            'title' => 'Shipment was cancelled',
            'explanation' => 'The shipment is cancelled and should not receive further arrival actions. Review its original delivery and cancellation record.',
        ],
        'cancelled_delivery_done' => [
            'title' => 'Cancelled shipment has a completed delivery',
            'explanation' => 'The shipment is cancelled but its inventory delivery was completed. Review shipment and delivery timelines to reconcile the physical goods.',
        ],
        'in_transit_delivery_not_done' => [
            'title' => 'Shipment in transit without completed delivery',
            'explanation' => 'The shipment says In Transit, but the linked inventory Delivery is not Done. Investigate the source posting before confirming arrival.',
        ],
        'planned' => [
            'title' => 'Shipment is planned, not dispatched',
            'explanation' => 'The shipment exists but has not left the warehouse. Follow the linked delivery preparation and dispatch workflow.',
        ],
        'planned_delivery_canceled' => [
            'title' => 'Planned shipment links to a cancelled delivery',
            'explanation' => 'The linked Delivery was cancelled. Review the fulfillment plan before attempting to dispatch this shipment.',
        ],
        'planned_delivery_done' => [
            'title' => 'Planned shipment has an already completed delivery',
            'explanation' => 'The delivery is marked Done while the shipment remains Planned. Review the posting and shipment status synchronization.',
        ],
        'planned_delivery_not_ready' => [
            'title' => 'Shipment\'s delivery has not reached Ready',
            'explanation' => 'The planned shipment cannot be dispatched until its Delivery is Ready and the required stock checks pass.',
        ],
    ],
];
