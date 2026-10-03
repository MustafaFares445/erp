<?php

declare(strict_types=1);

return [
    'fields' => [
        'ordered_base_quantity' => 'الكمية المطلوبة (الوحدة الأساسية)',
        'allocated_base_quantity' => 'المخصص',
        'unallocated_base_quantity' => 'غير المخصص',
        'received_base_quantity' => 'المستلم',
        'remaining_base_quantity' => 'المتبقي',
        'allocated_total' => 'إجمالي المخصص',
        'received_total' => 'إجمالي المستلم',
        'remaining_total' => 'إجمالي المتبقي',
        'allocation_allocated' => 'المخصص للمستودع',
        'allocation_received' => 'المستلم حسب المستودع',
        'allocation_remaining' => 'المتبقي حسب المستودع',
        'warehouse' => 'المستودع',
        'allocation' => 'تخصيص المستودع',
        'receipt_quantity' => 'الكمية المراد استلامها',
    ],
    'actions' => [
        'add_allocation' => 'إضافة تخصيص',
        'edit_allocation' => 'تعديل التخصيص',
        'remove_allocation' => 'إزالة التخصيص',
        'receive_allocation' => 'بدء الاستلام',
    ],
    'hints' => [
        'base_quantity' => 'يتم إدخال كميات التخصيص والاستلام بالوحدة الأساسية للمنتج.',
        'edit_allocation' => 'اختر تخصيصاً موجوداً ثم حدّد المستودع والكمية الأساسية الجديدة.',
        'remove_allocation' => 'يمكن إزالة التخصيصات التي لا ترتبط بكميات استلام محجوزة فقط.',
        'receipt' => 'اختر تخصيص المستودع الذي وصلت بضاعته الآن. تستبعد الكمية المتاحة تلقائياً أي عمليات استلام مفتوحة وغير ملغاة.',
    ],
    'options' => [
        'allocation' => ':warehouse — مخصص :allocated — مستلم :received — متبقي :remaining',
        'receipt' => ':sku — :warehouse — متاح :available',
    ],
    'notifications' => [
        'allocation_created' => 'تمت إضافة تخصيص المستودع.',
        'allocation_updated' => 'تم تحديث تخصيص المستودع.',
        'allocation_removed' => 'تمت إزالة تخصيص المستودع.',
    ],
];
