<?php

declare(strict_types=1);

return [

    'dashboard' => 'Dashboard',
    'empty_module' => 'No pages are available in this module yet.',

    'inventory' => [
        'notifications' => [
            'success' => 'Operation completed successfully.',
            'error' => 'The operation could not be completed.',
        ],
        'warehouse' => [
            'locations_count' => 'Locations',
            'stocks_count' => 'Stock Rows',
        ],
        'stock' => [
            'variant' => 'SKU',
            'variant_name' => 'Variant',
            'warehouse' => 'Warehouse Code',
            'warehouse_name' => 'Warehouse',
            'on_hand_quantity' => 'On Hand',
            'reserved_quantity' => 'Reserved',
            'available_quantity' => 'Available',
            'reorder_level' => 'Reorder Level',
            'low_stock' => 'Low Stock',
            'sanctioned_write_notice' => 'Stock balances change only through Adjustments and Transfers.',
        ],
        'movement' => [
            'date' => 'Date',
            'type' => 'Movement Type',
            'quantity' => 'Quantity',
            'source' => 'Source',
            'source_type' => 'Source Type',
            'creator' => 'Created By',
            'system' => 'System',
            'no_source' => 'No source document',
        ],
        'adjustment' => [
            'reason' => 'Reason',
            'adjustment_number' => 'Adjustment Number',
            'number_pending' => 'Assigned on confirmation',
            'status' => 'Status',
            'items_count' => 'Items',
            'old_quantity' => 'Current Quantity',
            'new_quantity' => 'Counted Quantity',
            'difference' => 'Difference',
            'confirm' => 'Confirm',
            'notifications' => [
                'confirmed' => 'Adjustment confirmed. Stock and the ledger have been updated.',
            ],
            'errors' => [
                'not_draft' => 'This adjustment has already been confirmed and cannot be applied again.',
                'inactive_warehouse' => 'This adjustment cannot be confirmed because its warehouse is inactive.',
                'no_items' => 'This adjustment has no items to confirm.',
                'negative_result' => 'This adjustment cannot be confirmed because it would result in a negative stock balance.',
            ],
        ],
    ],

    'groups' => [
        'sales' => 'Sales',
        'accounting' => 'Accounting',
        'inventory' => 'Inventory',
        'purchasing' => 'Purchasing',
        'crm' => 'CRM',
        'employees' => 'Employees',
        'support' => 'Support and Maintenance',
        'reports' => 'Reports',
        'system' => 'System',
    ],

    'resources' => [
        'quotations' => 'Quotations',
        'orders' => 'Orders',
        'delivery_notes' => 'Delivery Notes',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'credit_notes' => 'Credit Notes',

        'chart_of_accounts' => 'Chart of Accounts',
        'journal_entries' => 'Journal Entries',
        'accounts_receivable' => 'Accounts Receivable',
        'accounts_payable' => 'Accounts Payable',
        'bills' => 'Bills',
        'expenses' => 'Expenses',
        'refunds' => 'Refunds',
        'taxes' => 'Taxes',
        'financial_reports' => 'Financial Reports',

        'products' => 'Products',
        'product_variants' => 'Product Variants',
        'warehouses' => 'Warehouses',
        'stock_levels' => 'Stock Levels',
        'stock_movements' => 'Stock Movements',
        'transfers' => 'Transfers',
        'adjustments' => 'Adjustments',
        'returns' => 'Returns',

        'suppliers' => 'Suppliers',
        'purchase_orders' => 'Purchase Orders',
        'supplier_confirmations' => 'Supplier Confirmations',

        'customers' => 'Customers',
        'leads' => 'Leads',
        'opportunities' => 'Opportunities',
        'activities' => 'Activities',
        'campaigns' => 'Campaigns',

        'employees' => 'Employees',
        'monthly_plans' => 'Monthly Plans',
        'visits' => 'Visits',
        'tasks' => 'Tasks',
        'performance' => 'Performance',
        'salary_calculations' => 'Salary Calculations',

        'tickets' => 'Tickets',
        'maintenance_requests' => 'Maintenance Requests',
        'service_records' => 'Service Records',

        'operational_reports' => 'Operational Reports',
        'inventory_reports' => 'Inventory Reports',
        'employee_reports' => 'Employee Reports',

        'payment_terms' => 'Payment Terms',
        'payment_methods' => 'Payment Methods',
        'tax_definitions' => 'Tax Definitions',
        'units' => 'Units',
        'document_templates' => 'Document Templates',
        'users_and_permissions' => 'Users and Permissions',
        'settings' => 'Settings',
    ],

];
