<?php

declare(strict_types=1);

return [
    'fields' => [
        'ordered_base_quantity' => 'الكمية المطلوبة (الوحدة الأساسية)',
        'allocated_base_quantity' => 'الموزع',
        'unallocated_base_quantity' => 'غير الموزع',
        'received_base_quantity' => 'المستلم',
        'remaining_base_quantity' => 'المتبقي',
        'warehouse' => 'المستودع',
        'allocation' => 'توزيع المستودع',
        'receipt_quantity' => 'الكمية المراد استلامها',
    ],
    'actions' => [
        'add_allocation' => 'إضافة توزيع',
        'edit_allocation' => 'تعديل توزيع',
        'remove_allocation' => 'حذف توزيع',
        'receive_allocation' => 'بدء الاستلام',
    ],
    'hints' => [
        'base_quantity' => 'يتم إدخال كميات التوزيع والاستلام بالوحدة الأساسية للمنتج.',
        'edit_allocation' => 'اختر توزيعاً موجوداً ثم أدخل المستودع والكمية الأساسية الجديدة.',
        'remove_allocation' => 'يمكن حذف التوزيعات التي لا ترتبط بكميات استلام محجوزة فقط.',
        'receipt' => 'اختر توزيع المستودع الذي تصل بضاعته الآن. الكمية المتاحة تستبعد تلقائياً أي استلام مفتوح وغير ملغى.',
    ],
    'options' => [
        'allocation' => ':warehouse — موزع :allocated — مستلم :received — متبقي :remaining',
        'receipt' => ':sku — :warehouse — متاح :available',
    ],
    'notifications' => [
        'allocation_created' => 'تمت إضافة توزيع المستودع.',
        'allocation_updated' => 'تم تحديث توزيع المستودع.',
        'allocation_removed' => 'تم حذف توزيع المستودع.',
    ],
];
