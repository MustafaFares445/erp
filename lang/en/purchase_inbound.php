<?php

declare(strict_types=1);

return [
    'fields' => [
        'ordered_base_quantity' => 'Ordered (base)',
        'allocated_base_quantity' => 'Allocated',
        'unallocated_base_quantity' => 'Unallocated',
        'received_base_quantity' => 'Received',
        'remaining_base_quantity' => 'Remaining',
        'warehouse' => 'Warehouse',
        'allocation' => 'Warehouse allocation',
        'receipt_quantity' => 'Quantity to receive',
    ],
    'actions' => [
        'add_allocation' => 'Add allocation',
        'edit_allocation' => 'Edit allocation',
        'remove_allocation' => 'Remove allocation',
        'receive_allocation' => 'Start receipt',
    ],
    'hints' => [
        'base_quantity' => 'Allocation and receipt quantities are entered in the product base unit.',
        'edit_allocation' => 'Choose an existing warehouse allocation, then enter its new warehouse and allocated base quantity.',
        'remove_allocation' => 'Only allocations without committed receipt quantity can be removed.',
        'receipt' => 'Choose the warehouse allocation that is physically arriving now. Available quantity already excludes open non-cancelled receipts.',
    ],
    'options' => [
        'allocation' => ':warehouse — allocated :allocated — received :received — remaining :remaining',
        'receipt' => ':sku — :warehouse — available :available',
    ],
    'notifications' => [
        'allocation_created' => 'Warehouse allocation added.',
        'allocation_updated' => 'Warehouse allocation updated.',
        'allocation_removed' => 'Warehouse allocation removed.',
    ],
];
