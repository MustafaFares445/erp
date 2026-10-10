<?php

declare(strict_types=1);

return [
    'lot' => [
        'expiry_window' => 'فترة انتهاء الصلاحية',
        'traceability' => 'تتبع الدفعة',
        'traceability_description' => 'سجل الحركات المخزنية المحفوظ لتتبع الدفعة من المصدر إلى الوجهة وبالعكس.',
        'movement_history' => 'سجل الحركات',
        'fields' => [
            'date' => 'التاريخ',
            'movement' => 'الحركة',
            'warehouse' => 'المستودع',
            'transaction_quantity' => 'الكمية بوحدة العملية',
            'unit' => 'الوحدة',
            'base_delta' => 'التغير بالوحدة الأساسية',
            'from_condition' => 'الحالة السابقة',
            'to_condition' => 'الحالة اللاحقة',
            'serial' => 'الرقم التسلسلي',
            'source' => 'المستند المصدر',
            'counterparty' => 'المورّد / العميل',
            'invoice' => 'الفاتورة',
            'notes' => 'ملاحظات',
        ],
        'expiry_states' => [
            'expired' => 'منتهية الصلاحية',
            'expiring' => 'قريبة الانتهاء',
            'healthy' => 'صالحة',
            'no_expiry' => 'بلا تاريخ انتهاء',
        ],
        'buckets' => [
            'expired' => 'منتهية الصلاحية',
            'critical' => 'من 0 إلى 30 يوماً',
            'warning' => 'من 31 إلى 60 يوماً',
            'notice' => 'من 61 إلى 90 يوماً',
            'healthy' => 'بعد فترة التنبيه',
            'none' => 'بلا تاريخ انتهاء',
        ],
        'movements' => [
            'receipt' => 'استلام',
            'sale' => 'صرف بيع',
            'transfer_in' => 'تحويل وارد',
            'transfer_out' => 'تحويل صادر',
            'adjustment' => 'تسوية',
            'damage' => 'تلف',
            'return' => 'مرتجع',
        ],
        'conditions' => [
            'saleable' => 'صالح للبيع',
            'quarantine' => 'محجور',
            'damaged' => 'تالف',
            'disposed' => 'متلف',
        ],
    ],
];
