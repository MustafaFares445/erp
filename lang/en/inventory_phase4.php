<?php

declare(strict_types=1);

return [
    'adjustment' => [
        'warehouse_help' => 'Choose the warehouse whose on-hand quantities will be corrected by this adjustment.',
        'number_help' => 'The adjustment number is assigned automatically when the record is saved.',
        'reason_help' => 'Record why the correction is required so the change can be audited.',
        'serialized_unit' => 'Serialized unit',
        'add_item' => 'New inventory adjustment item',
        'items_title' => 'Inventory adjustment items',
        'serial' => 'Serial',
        'variant_help' => 'Select the variant whose recorded stock needs correction.',
        'quantity_help' => 'Enter the physical quantity counted. The difference is calculated against the live on-hand quantity.',
        'difference_help' => 'This is calculated automatically as the new quantity minus the current on-hand quantity.',
    ],
    'correction' => [
        'lines_title' => 'Correction lines',
    ],
    'return' => [
        'lines_title' => 'Return lines',
    ],
];
