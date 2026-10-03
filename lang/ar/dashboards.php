<?php

declare(strict_types=1);

return [
    'date_range' => 'الفترة الزمنية',
    'from' => 'من',
    'until' => 'إلى',
    'reset_filters' => 'إعادة تعيين عوامل التصفية',
    'week_of' => 'أسبوع :date',
    'empty' => 'لا توجد بيانات لعوامل التصفية المحددة.',
    'view' => 'عرض',

    'periods' => [
        'today' => 'اليوم',
        'last_7_days' => 'آخر 7 أيام',
        'last_30_days' => 'آخر 30 يومًا',
        'this_month' => 'هذا الشهر',
        'last_month' => 'الشهر الماضي',
        'this_quarter' => 'هذا الربع',
        'this_year' => 'هذه السنة',
        'custom' => 'فترة مخصصة',
    ],

    'trend' => [
        'increase' => 'زيادة :percent%',
        'decrease' => 'انخفاض :percent%',
        'no_change' => 'لا تغيير عن الفترة السابقة',
        'new_this_period' => 'جديد في هذه الفترة',
        'no_activity' => 'لا يوجد نشاط في هذه الفترة',
    ],

    'charts' => [
        'selected_period' => 'الفترة المحددة',
        'previous_period' => 'الفترة السابقة',
    ],

    'fallback' => [
        'customer' => 'العميل رقم :id',
        'unknown_customer' => 'عميل غير معروف',
        'employee' => 'الموظف رقم :id',
        'variant' => 'المتغير رقم :id',
    ],

    'crm' => [
        'filters' => [
            'lead_source' => 'مصدر العميل المحتمل',
        ],
        'kpis' => [
            'new_customers' => 'عملاء جدد',
            'active_customers' => 'العملاء النشطون',
            'total_customers' => ':count عميل إجمالًا',
            'new_leads' => 'عملاء محتملون جدد',
            'lead_conversion' => 'تحويل العملاء المحتملين',
        ],
        'charts' => [
            'customer_growth' => 'نمو العملاء',
            'leads_by_status' => 'العملاء المحتملون الجدد حسب الحالة',
            'leads' => 'العملاء المحتملون',
        ],
        'tables' => [
            'dormant_leads' => 'عملاء محتملون خاملون',
            'dormant_leads_description' => 'عملاء محتملون مفتوحون بلا تواصل منذ 14 يومًا أو أكثر',
            'campaigns' => 'أداء الحملات',
        ],
        'columns' => [
            'lead' => 'العميل المحتمل',
            'status' => 'الحالة',
            'owner' => 'المسؤول',
            'last_interaction' => 'آخر تواصل',
            'never' => 'أبدًا',
            'campaign' => 'الحملة',
            'sent' => 'المرسلة',
            'failed' => 'فاشلة / محجوبة',
            'leads' => 'العملاء المحتملون',
        ],
    ],

    'employees' => [
        'filters' => [
            'employee' => 'الموظف',
        ],
        'kpis' => [
            'tasks_completed' => 'المهام المنجزة',
            'visits' => 'زيارات العملاء',
            'open_tasks' => 'المهام المفتوحة',
            'overdue' => ':count متأخرة',
            'opportunities_awaiting_review' => 'فرص بانتظار المراجعة',
        ],
        'charts' => [
            'tasks_completed' => 'المهام المنجزة',
            'tasks_by_status' => 'المهام المستحقة حسب الحالة',
            'tasks' => 'المهام',
        ],
        'tables' => [
            'top_employees' => 'أفضل الموظفين',
            'overdue_tasks' => 'المهام المتأخرة',
        ],
        'columns' => [
            'employee' => 'الموظف',
            'tasks_completed' => 'المهام المنجزة',
            'visits' => 'الزيارات',
            'task' => 'المهمة',
            'due' => 'الاستحقاق',
            'status' => 'الحالة',
        ],
    ],

    'inventory' => [
        'kpis' => [
            'active_skus' => ':skus صنفًا مخزنيًا في :warehouses مستودعًا',
            'replenishment_detail' => 'الكمية غير المغطاة: :quantity · مقترحات التحويل: :suggestions · القابل للتحويل: :transferable',
            'awaiting_detail' => 'تسويات مخزون مسودة: :adjustments · تحويلات مفتوحة: :transfers',
        ],
        'charts' => [
            'stock_value' => 'قيمة المخزون (:currency)',
        ],
    ],

    'purchasing' => [
        'filters' => [
            'supplier' => 'المورد',
        ],
        'kpis' => [
            'spend' => 'إنفاق أوامر الشراء (:currency)',
            'needs_sourcing' => 'بحاجة إلى توريد',
            'needs_sourcing_detail' => 'مخزون :inventory · احتياجات مبيعات :sales · :quantity وحدة مخزون',
            'awaiting_approval' => 'بانتظار الاعتماد',
            'awaiting_approval_detail' => 'يتطلب إجراء من مدير المشتريات',
            'overdue' => 'توريدات متأخرة',
            'overdue_detail' => 'انقضى التاريخ المتوقع والاستلام ما زال مفتوحًا',
        ],
        'charts' => [
            'spend' => 'إنفاق المشتريات (:currency)',
            'open_by_stage' => 'أوامر الشراء المفتوحة حسب المرحلة',
            'purchase_orders' => 'أوامر الشراء',
        ],
        'stages' => [
            'approval' => 'الاعتماد',
            'ready_to_send' => 'جاهز للإرسال',
            'supplier' => 'المورد',
            'receiving' => 'الاستلام',
            'accounting' => 'المحاسبة',
        ],
        'tables' => [
            'attention' => 'بحاجة إلى انتباهك',
            'upcoming' => 'التوريدات القادمة',
        ],
        'columns' => [
            'purchase_order' => 'أمر الشراء',
            'reason' => 'سبب الحاجة إلى الانتباه',
            'expected' => 'المتوقع',
            'not_specified' => 'غير محدد',
            'next_action' => 'الإجراء التالي',
            'receiving' => 'الاستلام',
            'value' => 'قيمة الأمر',
        ],
        'attention' => [
            'overdue' => 'توريد متأخر',
        ],
    ],

    'support' => [
        'filters' => [
            'assignee' => 'المسؤول',
            'priority' => 'الأولوية',
        ],
        'kpis' => [
            'opened' => 'التذاكر المفتوحة حديثًا',
            'resolved' => 'التذاكر المحلولة',
            'open' => 'التذاكر المفتوحة',
            'waiting_customer' => ':count بانتظار العميل',
            'sla_at_risk' => 'اتفاقيات خدمة معرضة للخطر',
            'sla_at_risk_detail' => 'مخالفة أو مستحقة خلال الساعة القادمة',
        ],
        'charts' => [
            'ticket_trend' => 'اتجاه التذاكر',
            'opened' => 'مفتوحة',
            'resolved' => 'محلولة',
            'service_economics' => 'اقتصاديات الخدمة (:currency)',
            'amount' => 'المبلغ',
        ],
        'economics' => [
            'warranty_cost' => 'تكلفة الضمان',
            'goodwill_cost' => 'تكلفة حسن النية',
            'customer_paid' => 'خدمة مدفوعة من العميل',
            'recovery_received' => 'المسترد المستلم',
            'recovery_outstanding' => 'المسترد المستحق',
        ],
        'tables' => [
            'attention' => 'تذاكر تحتاج إلى انتباه',
            'maintenance' => 'صيانة تتطلب إجراءً',
            'upcoming_maintenance' => 'الصيانة القادمة',
        ],
        'columns' => [
            'ticket' => 'التذكرة',
            'customer' => 'العميل',
            'unassigned' => 'غير مسندة',
            'blocked_by' => 'معطلة بسبب',
            'sla' => 'اتفاقية الخدمة',
            'job' => 'المهمة',
            'stage' => 'المرحلة',
            'customer_pays' => 'يدفعه العميل',
            'next_action' => 'الإجراء التالي',
            'schedule' => 'الجدول',
            'serial' => 'الرقم التسلسلي',
            'due' => 'الاستحقاق',
            'status' => 'الحالة',
        ],
        'blocked' => [
            'triage' => 'بانتظار الفرز',
            'diagnostic_fee' => 'رسوم التشخيص',
            'payment' => 'الدفع',
            'assignment' => 'الإسناد',
            'customer' => 'العميل',
            'sla_breach' => 'مخالفة اتفاقية الخدمة',
            'action_required' => 'يتطلب إجراءً',
        ],
        'next' => [
            'record_diagnosis' => 'تسجيل التشخيص',
            'determine_coverage' => 'تحديد التغطية',
            'start_repair' => 'بدء الإصلاح',
            'complete_qa' => 'إكمال فحص الجودة',
            'review' => 'مراجعة مهمة الصيانة',
            'confirm_approval' => 'تأكيد الاعتماد',
            'create_quotation' => 'إنشاء عرض سعر',
            'mark_ready' => 'تحديد كجاهز للإصلاح',
            'waiting_quote' => 'بانتظار اعتماد عرض السعر',
        ],
    ],

    'accounting' => [
        'kpis' => [
            'receivables' => 'الذمم المدينة المستحقة',
            'bad_debt' => 'الديون المعدومة في الفترة: :value',
            'payables' => 'الذمم الدائنة المستحقة',
            'billed' => 'فواتير الموردين في الفترة: :value',
            'net_tax' => 'صافي المركز الضريبي',
            'awaiting_action' => 'بانتظار الإجراء',
            'awaiting_detail' => ':entries قيود مسودة · :bills فواتير للاعتماد',
        ],
        'charts' => [
            'ledger' => 'نشاط القيود المرحّلة',
            'tax_position' => 'المركز الضريبي',
            'tax_amount' => 'مبلغ الضريبة',
        ],
        'tax' => [
            'deferred' => 'ضريبة المخرجات المؤجلة',
            'payable' => 'ضريبة المخرجات المثبتة والمستحقة',
            'reversed' => 'ضريبة المخرجات المعكوسة',
            'input' => 'ضريبة المدخلات المثبتة',
            'net' => 'صافي المركز الضريبي',
        ],
        'tables' => [
            'close_readiness' => 'جاهزية إقفال الفترة',
            'close_readiness_period' => 'آخر نتائج الفحص للفترة :period',
            'top_receivables' => 'أعلى العملاء رصيدًا مستحقًا',
        ],
        'columns' => [
            'check' => 'الفحص',
            'optional' => 'اختياري',
            'status' => 'الحالة',
            'measured' => 'آخر قياس',
            'customer' => 'العميل',
            'open_invoices' => 'الفواتير المفتوحة',
            'outstanding' => 'المستحق',
        ],
        'close_status' => [
            'passed' => 'ناجح',
            'failed' => 'فاشل',
            'not_measured' => 'لم يُقَس',
        ],
    ],

    'sales' => [
        'filters' => [
            'salesperson' => 'مندوب المبيعات',
            'customer' => 'العميل',
        ],
        'kpis' => [
            'confirmed_value' => 'قيمة الطلبات المؤكدة',
            'confirmed_orders' => 'الطلبات المؤكدة',
            'average_order_value' => 'متوسط قيمة الطلب',
            'conversion' => 'تحويل عروض الأسعار إلى طلبات',
            'conversion_detail' => 'تم تحويل :converted من :decided عرض سعر محسوم',
        ],
        'charts' => [
            'performance' => 'أداء المبيعات',
            'top_products' => 'المنتجات الأعلى مبيعًا',
            'sales_value' => 'قيمة المبيعات',
        ],
        'tables' => [
            'top_customers' => 'أفضل العملاء',
            'salesperson_performance' => 'أداء مندوبي المبيعات',
        ],
        'columns' => [
            'customer' => 'العميل',
            'salesperson' => 'مندوب المبيعات',
            'orders' => 'الطلبات',
            'sales_value' => 'قيمة المبيعات',
            'average' => 'المتوسط :value',
            'conversion_detail' => ':quotations عرض سعر · تحويل :percent%',
        ],
        'cards' => [
            'funnel' => 'مسار المبيعات',
            'of_previous_stage' => ':percent% من المرحلة السابقة',
            'quotation_performance' => 'أداء عروض الأسعار',
            'open_quotation_value' => 'قيمة عروض الأسعار المفتوحة',
            'median_days' => 'وسيط أيام اتخاذ القرار',
            'days' => ':days يوم',
            'accepted' => 'مقبولة',
            'awaiting_decision' => 'بانتظار القرار',
            'rejected_or_expired' => 'مرفوضة / منتهية',
            'requires_attention' => 'يتطلب الانتباه',
            'recent_activity' => 'أحدث نشاطات المبيعات',
        ],
        'funnel' => [
            'quotations' => 'عروض الأسعار',
            'accepted' => 'المقبولة',
            'orders' => 'الطلبات',
            'delivered' => 'المسلَّمة',
            'invoiced' => 'المفوترة',
        ],
        'attention' => [
            'blocked' => 'طلبات متوقفة بسبب المخزون',
            'accepted_not_converted' => 'عروض أسعار مقبولة لم تُحوَّل',
            'potential_value' => 'القيمة المحتملة: :value',
            'delivered_not_invoiced' => 'مسلَّمة ولم تُفوتر',
            'awaiting_fulfillment' => 'طلبات بانتظار التنفيذ',
            'awaiting_decision' => 'عروض أسعار بانتظار قرار العميل',
            'oldest_waiting' => 'الأقدم انتظارًا: :days يوم',
            'expiring_soon' => 'عروض أسعار تنتهي خلال 7 أيام',
        ],
        'activity' => [
            'quotation_accepted' => 'تم قبول عرض السعر :number',
            'order_confirmed' => 'تم تأكيد الطلب :number',
            'delivery_completed' => 'تم إكمال التسليم :number',
            'invoice_issued' => 'تم إصدار الفاتورة :number',
        ],
        'empty' => [
            'sales' => 'لا يوجد نشاط مبيعات في الفترة المحددة.',
            'products' => 'لا توجد مبيعات منتجات في الفترة المحددة.',
            'quotations' => 'لا توجد عروض أسعار في الفترة المحددة.',
            'attention' => 'لا يوجد ما يتطلب الانتباه حاليًا.',
        ],
    ],
];
