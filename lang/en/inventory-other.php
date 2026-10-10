<?php

declare(strict_types=1);

return [
    'lot' => [
        'expiry_window' => 'Expiry window',
        'traceability' => 'Lot traceability',
        'traceability_description' => 'Immutable warehouse movement history for backward and forward lot tracing.',
        'movement_history' => 'Movement history',
        'fields' => [
            'date' => 'Date',
            'movement' => 'Movement',
            'warehouse' => 'Warehouse',
            'transaction_quantity' => 'Transaction quantity',
            'unit' => 'Unit',
            'base_delta' => 'Base delta',
            'from_condition' => 'From condition',
            'to_condition' => 'To condition',
            'serial' => 'Serial number',
            'source' => 'Source document',
            'counterparty' => 'Supplier / customer',
            'invoice' => 'Invoice',
            'notes' => 'Notes',
        ],
        'expiry_states' => [
            'expired' => 'Expired',
            'expiring' => 'Expiring',
            'healthy' => 'Valid',
            'no_expiry' => 'No expiry',
        ],
        'buckets' => [
            'expired' => 'Expired',
            'critical' => '0–30 days',
            'warning' => '31–60 days',
            'notice' => '61–90 days',
            'healthy' => 'Beyond alert window',
            'none' => 'No expiry',
        ],
        'movements' => [
            'receipt' => 'Receipt',
            'sale' => 'Sale',
            'transfer_in' => 'Transfer in',
            'transfer_out' => 'Transfer out',
            'adjustment' => 'Adjustment',
            'damage' => 'Damage',
            'return' => 'Return',
        ],
        'conditions' => [
            'saleable' => 'Saleable',
            'quarantine' => 'Quarantined',
            'damaged' => 'Damaged',
            'disposed' => 'Disposed',
        ],
    ],
];
