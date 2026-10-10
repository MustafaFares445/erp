<?php

declare(strict_types=1);

$base = [
    'title' => 'Operational health',
    'description' => 'Read-only checks on the recorded data: what needs attention, why, and the next step. This panel never changes stock.',
    'metrics_title' => 'Key figures',
    'next' => 'Next step',
    'action_denied' => 'You do not have permission for this step. Ask a user with the required inventory permission to do it.',
    'scope_toggle' => 'What was checked (:count checks) and what these checks cannot tell you',
    'checks_heading' => 'Checked',
    'limits_heading' => 'Not covered by these checks',

    'severity' => [
        'blocker' => 'Blocker',
        'warning' => 'Needs attention',
        'notice' => 'For information',
        'clear' => 'No issue found',
    ],

    'category' => [
        'availability' => 'Availability',
        'replenishment' => 'Replenishment',
        'expiry' => 'Expiry',
        'tracking' => 'Lot / serial tracking',
        'transfer' => 'Transfers',
        'reservation' => 'Reservations',
        'count' => 'Counts',
        'delivery' => 'Deliveries',
        'receipt' => 'Receipts',
        'custody' => 'Custody',
        'condition' => 'Stock condition',
        'warranty' => 'Warranty',
        'maintenance' => 'Maintenance',
        'approval' => 'Approval',
        'workflow' => 'Workflow',
        'data_quality' => 'Data quality',
    ],

    'summary' => [
        'blockers' => '{1} 1 blocker must be resolved before work can continue.|[2,*] :count blockers must be resolved before work can continue.',
        'warnings' => '{1} 1 item needs attention.|[2,*] :count items need attention.',
        'guidance' => 'No problem found. The notes below explain the current state and the next step.',
        'clear' => 'No problem found by the :checks checks listed below.',
    ],

    'rules' => [
        'stock' => [
            'inconsistent' => [
                'title' => 'Recorded balances contradict each other',
                'explanation' => 'Reserved quantity is higher than the saleable stock on hand, or on-hand is negative. This should never happen through normal posting. Do not post further movements for this item until an inventory supervisor has reviewed the movement history.',
            ],
            'no_stock' => [
                'title' => 'No physical stock in this warehouse',
                'explanation' => 'The recorded on-hand quantity is zero, so nothing can be reserved or delivered from here.',
            ],
            'none_available' => [
                'title' => 'Stock is on hand but none is available',
                'explanation' => 'Every unit on hand is held by one of the reasons listed here, so new orders and deliveries cannot use it.',
            ],
            'reserved' => [
                'title' => ':quantity reserved for other documents',
                'explanation' => 'This quantity is held by :count active reservation(s). It stays on the shelf but cannot be promised again until the reservation is delivered or released.',
            ],
            'quarantine' => [
                'title' => ':quantity held in quarantine',
                'explanation' => 'Quarantined stock is physically here but blocked until someone decides what happens to it (release to saleable, downgrade to damaged, dispose, or return to supplier).',
            ],
            'damaged' => [
                'title' => ':quantity recorded as damaged',
                'explanation' => 'Damaged stock is excluded from availability. It can be recovered if it is usable again, or disposed of through the disposal workflow.',
            ],
            'expired_lots' => [
                'title' => ':quantity in expired lots is still counted as saleable',
                'explanation' => 'These lots are past their expiry date but still sit in saleable stock, so they inflate availability. Picking them is blocked unless a user holds the expired-stock override.',
            ],
            'reservation_overdue' => [
                'title' => ':count reservation(s) past their expiry still hold stock',
                'explanation' => 'The reservation expiry time has passed but the reservation is still active, so the stock is still held. The scheduled expiry job may not have run yet.',
            ],
            'no_policy' => [
                'title' => 'No replenishment threshold is set',
                'explanation' => 'Without a minimum quantity for this item in this warehouse, low stock cannot be detected here or raised as an alert.',
            ],
            'policy_inactive' => [
                'title' => 'The replenishment threshold is inactive',
                'explanation' => 'A minimum quantity exists but is switched off, so low stock is not detected for this item in this warehouse.',
            ],
            'below_minimum' => [
                'title' => 'Available stock is at or below the minimum',
                'explanation' => 'Available stock has reached the replenishment minimum and is not covered by incoming transfers. :shortage are needed to reach the target level.',
            ],
            'below_minimum_covered' => [
                'title' => 'Below the minimum, but incoming transfers cover it',
                'explanation' => 'Available stock is at or below the minimum, but stock already in transit to this warehouse will bring it back above the minimum once received. :shortage short of the target level.',
            ],
            'waiting_deliveries' => [
                'title' => ':count delivery(ies) are waiting for this item',
                'explanation' => 'These deliveries could not be prepared because the source warehouse did not have enough available stock when they were checked.',
            ],
            'active_alerts' => [
                'title' => ':count open alert(s) on this balance',
                'explanation' => 'The alert engine flagged this balance and the alert is not resolved yet.',
            ],
            'in_transit' => [
                'title' => ':quantity on the way to this warehouse',
                'explanation' => 'Internal transfers have left their source warehouse but have not been received here. This stock is not counted in on-hand or available until it is received.',
            ],
        ],
        'lot' => [
            'merged' => [
                'title' => 'This lot was merged into lot :lot',
                'explanation' => 'Balances and new movements are kept on the surviving lot. Use that lot for current quantities.',
            ],
            'inconsistent' => [
                'title' => 'Lot balances contradict each other',
                'explanation' => 'A warehouse balance for this lot is negative or has more reserved than on hand. Ask an inventory supervisor to review the lot movement history before posting further movements.',
            ],
            'expiry_missing' => [
                'title' => 'Expiry date is required but missing',
                'explanation' => 'This product tracks expiry, but the lot has no expiry date. FEFO picking and expiry alerts cannot work for it.',
            ],
            'expired_with_stock' => [
                'title' => 'Expired on :date with :quantity still saleable',
                'explanation' => 'This lot expired :days day(s) ago. It cannot be picked for deliveries unless a user holds the expired-stock override, yet it still counts as saleable stock. Move it out of saleable stock.',
            ],
            'expired_empty' => [
                'title' => 'Expired on :date, no saleable stock left',
                'explanation' => 'The lot is expired but holds no saleable stock, so it does not affect availability.',
            ],
            'expiring_critical' => [
                'title' => 'Expires in :days day(s) (:date)',
                'explanation' => 'The lot is inside the critical expiry window. :quantity saleable should be used first (FEFO) or reviewed for markdown or return before it expires.',
            ],
            'expiring_warning' => [
                'title' => 'Expires in :days day(s) (:date)',
                'explanation' => 'The lot is inside the warning expiry window. FEFO picking will use it before later-expiring lots.',
            ],
            'expiring_notice' => [
                'title' => 'Expires in :days day(s) (:date)',
                'explanation' => 'The lot is inside the notice expiry window. No action is needed yet; keep FEFO picking in place.',
            ],
            'no_stock' => [
                'title' => 'No stock remains in this lot',
                'explanation' => 'The lot has been fully issued, disposed or moved. Its history is kept for traceability.',
            ],
            'reserved' => [
                'title' => ':quantity of this lot is reserved',
                'explanation' => 'Reserved lot stock is allocated to open documents and cannot be picked for anything else.',
            ],
            'quarantine' => [
                'title' => ':quantity of this lot is in quarantine',
                'explanation' => 'Quarantined lot stock is blocked until a disposition decision is recorded.',
            ],
            'damaged' => [
                'title' => ':quantity of this lot is damaged',
                'explanation' => 'Damaged lot stock is excluded from availability until it is recovered or disposed of.',
            ],
            'quality_alert' => [
                'title' => 'Potential quality issue: :count open complaint(s)',
                'explanation' => 'Customer complaints on this lot reached the alert threshold (:threshold). The alert is information only; Inventory decides whether to quarantine the lot.',
            ],
        ],
        'warehouse' => [
            'inactive' => [
                'title' => 'Warehouse is inactive',
                'explanation' => 'Inactive warehouses cannot be used for new receipts, deliveries, transfers or adjustments.',
            ],
            'coordinates_missing' => [
                'title' => 'Location coordinates are missing',
                'explanation' => 'Without latitude and longitude, location-based delivery planning cannot use this warehouse.',
            ],
            'inconsistent' => [
                'title' => ':count item balance(s) have more reserved than on hand',
                'explanation' => 'These balances contradict each other. Review the affected items before posting further movements.',
            ],
            'unavailable' => [
                'title' => ':count item(s) have stock on hand but none available',
                'explanation' => 'All units of these items are reserved, in quarantine or damaged. Open the stock level to see which documents hold them.',
            ],
            'quarantine' => [
                'title' => ':count item(s) have stock waiting in quarantine',
                'explanation' => 'Quarantined stock is blocked until a disposition decision is recorded.',
            ],
            'below_minimum' => [
                'title' => ':count item(s) are at or below their replenishment minimum',
                'explanation' => 'Available stock reached the minimum configured for this warehouse.',
            ],
            'alerts' => [
                'title' => ':count open stock alert(s) in this warehouse',
                'explanation' => 'Alerts raised on stock balances of this warehouse are not resolved yet.',
            ],
            'waiting_deliveries' => [
                'title' => ':count delivery(ies) waiting for stock',
                'explanation' => 'These deliveries from this warehouse could not be prepared because stock was not available when they were checked.',
            ],
            'inbound_transfers' => [
                'title' => ':count transfer(s) on the way to this warehouse',
                'explanation' => 'Goods left the source warehouse but receipt here is not recorded yet.',
            ],
            'counts_pending' => [
                'title' => ':count count(s) waiting for review',
                'explanation' => 'Counted quantities are submitted but not confirmed, so the differences are not posted yet. A different user from the counter must review them.',
            ],
            'expired_lots' => [
                'title' => ':count expired lot(s) still hold saleable stock here',
                'explanation' => 'Expired lots inflate availability and cannot be picked without the expired-stock override.',
            ],
            'empty' => [
                'title' => 'No stock recorded in this warehouse',
                'explanation' => 'Nothing has been received here yet, or all stock has been issued.',
            ],
        ],
        'product' => [
            'no_variants' => [
                'title' => 'The product has no variants',
                'explanation' => 'Stock is held per variant (SKU). Without a variant this product cannot be received, counted or delivered.',
            ],
            'inactive_variants' => [
                'title' => ':count variant(s) are inactive',
                'explanation' => 'Inactive variants cannot be used in new inventory documents. Existing stock and history stay visible.',
            ],
            'no_stock' => [
                'title' => 'No stock recorded in any warehouse',
                'explanation' => 'No variant of this product has on-hand stock.',
            ],
            'none_available' => [
                'title' => 'Stock is on hand but none is available',
                'explanation' => 'All on-hand stock is reserved, in quarantine or damaged. Open a stock level to see which documents hold it.',
            ],
            'restricted' => [
                'title' => ':quantity held in quarantine or damaged',
                'explanation' => 'This stock is physically present but excluded from availability until a condition decision is recorded.',
            ],
            'below_minimum' => [
                'title' => ':count warehouse balance(s) at or below the minimum',
                'explanation' => 'Available stock reached the replenishment minimum in these warehouses.',
            ],
            'expired_lots' => [
                'title' => ':count expired lot(s) still hold saleable stock',
                'explanation' => 'Expired lots inflate availability and cannot be picked without the expired-stock override.',
            ],
        ],
    ],

    'facts' => [
        'on_hand' => 'On hand: :quantity',
        'saleable_on_hand' => 'Saleable on hand: :quantity',
        'reserved' => 'Reserved: :quantity',
        'available' => 'Available: :quantity',
        'minimum' => 'Minimum: :quantity',
        'target' => 'Target level: :quantity',
        'in_transit' => 'In transit: :quantity',
        'waiting_demand' => 'Waiting demand: :quantity',
        'no_source_document' => 'No source document is linked to this quantity.',
        'lot_expired_on' => 'Lot :lot expired on :date: :quantity',
        'reservation_expired_at' => 'Reservation :reservation expired at :date: :quantity',
        'lot_balance' => ':warehouse, :condition: on hand :on_hand, reserved :reserved',
        'in_warehouse' => ':warehouse: :quantity',
        'item' => ':sku in :warehouse',
        'count' => ':label: :count',
        'stock_sample' => ':sku: on hand :on_hand, reserved :reserved, available :available',
        'and_more' => '… and :count more',
    ],

    'actions' => [
        'review_movements' => 'Review the movement history',
        'review_reservations' => 'Review the active reservations',
        'review_reservation' => 'Open the reservation',
        'decide_quarantine' => 'Record a quarantine decision',
        'complete_open_disposition' => 'Complete the open quarantine decision (draft condition change) listed above',
        'resolve_damage' => 'Recover or dispose of the damaged stock',
        'open_lot' => 'Open the lot',
        'open_canonical_lot' => 'Open the surviving lot',
        'open_transfer' => 'Open the transfer',
        'open_delivery' => 'Open delivery :number',
        'open_alert' => 'Open the alert',
        'review_policy' => 'Review the replenishment policy',
        'review_expiring_lots' => 'Review expiring lots',
        'segregate_expired' => 'Record a condition change to remove expired stock from saleable',
        'record_expiry' => 'Ask the receiving team to correct the lot expiry through a receipt correction',
        'decide_lot_quality' => 'Inventory decides whether to quarantine this lot; review the complaints with Support',
        'escalate_inconsistency' => 'Report this to an inventory supervisor; do not post further movements until it is reviewed',
        'edit_warehouse' => 'Edit the warehouse',
        'view_stock' => 'Review stock levels in this warehouse',
        'open_stock_level' => 'Open the stock level',
        'view_alerts' => 'Review open alerts',
        'view_waiting_deliveries' => 'Review waiting deliveries',
        'view_transfers' => 'Review incoming transfers',
        'view_counts' => 'Review counts waiting for review',
        'view_quantities' => 'Review quantities per warehouse',
        'view_variants' => 'Review the variants',
    ],

    'checks' => [
        'balance_consistency' => 'Reserved never exceeds saleable on-hand, and on-hand is not negative',
        'availability_causes' => 'Why on-hand stock is not available (reservations, quarantine, damage)',
        'expired_lots' => 'Expired lots still counted as saleable',
        'reservation_expiry' => 'Active reservations past their expiry time',
        'replenishment_threshold' => 'Available stock against the replenishment minimum, including stock in transit',
        'waiting_deliveries' => 'Deliveries waiting for this item at this warehouse',
        'active_alerts' => 'Open alerts on this balance',
        'inbound_transfers' => 'Transfers on the way to this warehouse',
        'lot_expiry' => 'Expiry date against the configured windows (critical :critical, warning :warning, notice :notice days)',
        'lot_expiry_required' => 'Expiry date present when the product tracks expiry',
        'lot_balance_consistency' => 'Lot balances per warehouse are not negative and not over-reserved',
        'lot_conditions' => 'Quarantined and damaged lot stock',
        'lot_reservations' => 'Reserved lot stock',
        'lot_quality' => 'Open customer-complaint alert on the lot (when you may view complaints)',
        'warehouse_status' => 'Warehouse active and located',
        'warehouse_balances' => 'Item balances that are contradictory, fully unavailable, quarantined or below the minimum',
        'warehouse_documents' => 'Waiting deliveries, incoming transfers and counts waiting for review',
        'warehouse_alerts' => 'Open stock alerts and expired lots with saleable stock',
        'product_variants' => 'Variants exist and are active',
        'product_stock' => 'On-hand, available, quarantined and damaged stock across all warehouses',
        'product_replenishment' => 'Warehouse balances at or below the replenishment minimum',
        'product_tracking' => 'Expired lots that still hold saleable stock',
    ],

    'limits' => [
        'recorded_balances' => 'These checks read recorded balances. They cannot confirm that the goods are physically on the shelf; only an inventory count can.',
        'unposted_documents' => 'Draft documents that are not posted yet do not change the balances shown here.',
        'lot_quality_decision' => 'Quality complaints are counted from Support tickets; whether the lot is defective is a business decision.',
        'warehouse_scope' => 'Only the most common issues are counted here. Open each stock level for the full explanation of an item.',
        'product_scope' => 'Product definitions (names, tracking, pricing) belong to the catalog; this page explains stock only.',
        'no_rules' => 'There are no automated checks for this type of record. Review the source documents and their status.',
    ],

    'ui' => [
        'stock_conditions_description' => 'Only saleable stock that is not reserved is available. Quarantined and damaged stock is physically here but blocked.',
        'stock_planning_description' => 'The replenishment policy of this item in this warehouse. Low stock is detected only when an active minimum is set.',
        'stock_breakdown_description' => 'The documents that hold the difference between on-hand and available stock, and stock on the way.',
        'replenishment_status' => 'Replenishment status',
        'above_minimum' => 'Above minimum',
        'minimum_inactive' => 'Minimum inactive',
        'no_minimum' => 'No minimum set',
        'product_identity' => 'Product identity',
        'variants' => 'Variants',
        'active_count' => ':count active',
        'warehouses_with_stock' => 'Warehouses with stock',
        'restricted' => 'Restricted stock',
        'restricted_helper' => 'Quarantined or damaged',
        'variants_and_tracking' => 'Variants, tracking and stock',
        'variants_and_tracking_description' => 'Tracking requirements are set in the catalog; quantities are totals across all warehouses.',
        'no_variants' => 'This product has no variants yet.',
        'tracking' => 'Tracking',
        'expiry_tracking' => 'Expiry',
        'variant_status' => 'Status',
        'expiry_required' => 'Expiry date required',
        'expiry_not_tracked' => 'Not tracked',
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],
];

// Keep existing reviewed descriptions authoritative and add the domain-specific
// diagnostics vocabulary implemented in the extended translation file.
return array_replace_recursive(
    require __DIR__.'/inventory_view_ux_extended.php',
    ['rules' => require __DIR__.'/inventory_view_ux_reviewed.php'],
    ['ui' => require __DIR__.'/inventory_view_ui.php'],
    $base,
);
