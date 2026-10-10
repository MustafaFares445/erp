#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[2]
GUIDE = REPO / "user-guide"
MANIFEST_PATH = GUIDE / "content" / "admin-manifest.json"
RESOURCE_PURPOSE_PATH = GUIDE / "content" / "resource-purpose.json"
VERIFIED_SCREENSHOTS_PATH = GUIDE / "content" / "verified-screenshots.json"
RESOURCE_PURPOSES = json.loads(RESOURCE_PURPOSE_PATH.read_text(encoding="utf-8")) if RESOURCE_PURPOSE_PATH.exists() else {}
VERIFIED_SCREENSHOTS = json.loads(VERIFIED_SCREENSHOTS_PATH.read_text(encoding="utf-8")) if VERIFIED_SCREENSHOTS_PATH.exists() else {}
TODAY = "2026-10-07"

FORM_COMPONENTS = {
    "TextInput", "Textarea", "Select", "Toggle", "Checkbox", "Radio", "DatePicker",
    "DateTimePicker", "TimePicker", "Repeater", "FileUpload", "TagsInput", "KeyValue",
    "RichEditor", "ColorPicker", "ToggleButtons", "CheckboxList", "Hidden", "Placeholder",
}
ENTRY_COMPONENTS = {"TextEntry", "IconEntry", "ImageEntry", "RepeatableEntry", "KeyValueEntry"}
COLUMN_COMPONENTS = {"TextColumn", "IconColumn", "ImageColumn", "ColorColumn", "CheckboxColumn", "ToggleColumn"}
FILTER_COMPONENTS = {"Filter", "SelectFilter", "TernaryFilter", "QueryBuilder"}
ACTION_COMPONENTS = {
    "Action", "CreateAction", "EditAction", "ViewAction", "DeleteAction", "RestoreAction",
    "ForceDeleteAction", "BulkAction", "DeleteBulkAction", "ExportAction", "ImportAction",
}

MODULE_INTRO_EN = {
    "sales": "Sales controls the customer commercial lifecycle from quotation and confirmed demand through fulfillment, billing, collection and credit correction.",
    "accounting": "Accounting owns the financial truth of IERP: ledgers, receivables, payables, tax, periods, supplier liabilities and posted financial corrections.",
    "inventory": "Inventory owns physical stock truth: warehouses, quantities, tracked lots and serials, receipts, deliveries, transfers, counts, returns, reservations and replenishment.",
    "vendors": "Purchasing manages supplier sourcing and commercial commitments while Inventory remains the only owner of physical receiving and Accounting owns supplier liabilities.",
    "crm": "CRM organizes customers, leads, opportunities, interactions, campaigns and customer-specific commercial context before and around the sales process.",
    "employees": "Employees covers workforce records, monthly planning, tasks, field visits, reviewed sales opportunities, performance and salary calculation.",
    "support": "Support & Maintenance coordinates customer tickets, SLA, warranty, service work, maintenance, field appointments, equipment context and advanced routing.",
    "reports": "Reports provides consolidated operational and audit views. Reports explain business facts; corrections must be made in the module that owns the source record.",
    "system": "System contains access, templates and shared configuration surfaces used across the ERP.",
}
MODULE_INTRO_AR = {
    "sales": "تدير المبيعات الدورة التجارية للعميل من عرض السعر والطلب المؤكد حتى التنفيذ والفوترة والتحصيل والتصحيح بالائتمان.",
    "accounting": "المحاسبة هي مرجع الحقيقة المالية في IERP وتشمل الدفاتر والذمم والضرائب والفترات والالتزامات والتصحيحات المالية المرحلة.",
    "inventory": "المخزون هو مرجع الحقيقة المادية للكميات والمستودعات والدفعات والأرقام التسلسلية والاستلام والتسليم والنقل والجرد والمرتجعات والحجوزات وإعادة التزويد.",
    "vendors": "تدير المشتريات التوريد والالتزام التجاري مع المورد، بينما يبقى الاستلام الفعلي للمخزون مسؤولية المخزون والذمم مسؤولية المحاسبة.",
    "crm": "تنظم CRM العملاء والعملاء المحتملين والفرص والتفاعلات والحملات والسياق التجاري الخاص بالعميل قبل عملية البيع وأثناءها.",
    "employees": "تغطي وحدة الموظفين ملفات القوى العاملة والخطط الشهرية والمهام والزيارات والفرص المراجعة والأداء وحساب الرواتب.",
    "support": "ينظم الدعم والصيانة التذاكر وSLA والضمان وأعمال الخدمة والصيانة والزيارات الميدانية وسياق المعدات والتوجيه المتقدم.",
    "reports": "تعرض التقارير حقائق التشغيل والتدقيق، أما التصحيح فيتم دائمًا من خلال الوحدة المالكة للسجل المصدر.",
    "system": "يحتوي النظام على إدارة الوصول والقوالب والإعدادات المشتركة التي تستخدمها وحدات ERP.",
}

SPECIAL_PURPOSE_EN = {
    "quotation": "Create, send, review and convert customer quotations without changing stock until the customer commitment becomes a Sales Order.",
    "order": "Manage confirmed customer demand, availability, procurement readiness and the release boundary into fulfillment.",
    "delivery-note": "Review delivery evidence and the commercial delivery document produced from warehouse fulfillment.",
    "invoice": "Create, issue and review customer invoices while keeping document status separate from financial settlement.",
    "payment": "Record customer money movement, proof and invoice allocation before posting the financial effect.",
    "payment-transaction": "Inspect immutable or provider-facing payment transaction events linked to customer payment processing.",
    "credit-note": "Create controlled customer credits against invoiced value, normally following an approved correction or return.",
    "purchase-order": "Create and approve supplier commitments, send them to the supplier and follow confirmation, inbound receipt and billing progress.",
    "supplier-confirmation": "Record the supplier's committed quantities and promised dates against a sent Purchase Order.",
    "purchase-inbound": "Track expected inbound work generated from supplier purchasing before Inventory posts the physical receipt.",
    "inventory-operation": "Execute controlled inventory movements such as receipts, deliveries and internal transfers while preserving movement history.",
    "stock-level": "Review on-hand, reserved, damaged and available stock by warehouse and product without directly editing quantities.",
    "inventory-lot": "Inspect lot-controlled stock, expiry dates and the movement history of each lot.",
    "serialized-inventory-unit": "Track one uniquely serialized unit, its condition, current custody and movement provenance.",
    "inventory-count": "Perform physical stock counts, compare system and actual quantities and turn approved variance into controlled adjustment.",
    "adjustment": "Correct approved stock variances through an auditable inventory movement rather than direct quantity editing.",
    "return": "Manage the physical customer return, inspection and disposition before any separate financial credit or refund.",
    "customer": "Maintain the customer master profile and review the customer's commercial, service, equipment and financial history in one place.",
    "lead": "Capture and qualify potential business before it becomes a formal sales opportunity or customer process.",
    "sales-opportunity": "Track an active commercial opportunity and its progression toward quotation or follow-up.",
    "employee": "Maintain employee identity and operational context used by planning, visits, performance and compensation.",
    "monthly-plan": "Plan an employee's monthly objectives and tasks before field execution.",
    "visit": "Track scheduled field visits from travel and check-in through outcome, evidence and follow-up.",
    "ticket": "Manage a customer support case from intake and triage through assignment, service work, resolution and closure.",
    "maintenance-request": "Coordinate diagnosis, approval and maintenance execution for customer equipment or service needs.",
    "service-appointment": "Schedule and follow field service work with the customer, assigned resource and result.",
    "support-equipment": "Review the support-facing 360° view of serialized customer equipment, warranty, service and custody context.",
    "dashboard-user": "Manage access to the administration dashboard and the user's operational role context.",
}



PAGE_OPERATOR_NOTES_EN = {
    ("sales", "dashboard", "overview"): {
        "markdown": """
### Dashboard filters

Use **Date range** to choose the reporting period. **Salesperson** narrows quotation-owned sales activity to that commercial owner, while **Customer** narrows the dashboard to one customer. **Reset filters** restores the default dashboard scope.

The filters change what you are looking at; they do not edit Sales records. Requires Attention remains a current-state queue rather than a purely date-bounded report, although Customer/Salesperson filters still apply.

### Reading the widgets

Confirmed sales value/count and average order value come from confirmed commercial orders. Quotation conversion and the funnel describe commercial progression. Top Customers/Products rank confirmed order value, while Salesperson Performance depends on quotation salesperson ownership. Open the source records from the widgets when follow-up is required.
""",
    },
    ("sales", "quotation", "index"): {
        "markdown": """
### Working views and export

Use the status tabs to focus Draft, Sent/Awaiting decision, Accepted, Expiring soon, Open, Converted, or Rejected/Cancelled quotations. The favorite column and Starred view are personal working aids and do not change the quotation lifecycle.

The list shows quotation number, customer, status, stock-coverage context, issue/expiry dates and grand total. Row actions are state-sensitive: Send, Record Decision, Convert to Order, Create Revised Quotation/Requote and ordinary View/Edit appear only where the record and permission allow them.

**Export CSV** downloads the current filtered/searched table result and records an audit event for the export.
""",
    },
    ("sales", "quotation", "create"): {
        "markdown": """
### Dependent line behavior

1. Select the customer first so IERP can resolve the active pricing tier and commercial defaults.
2. Select a product. If it has one active variant, IERP resolves that variant automatically. If it has several variants, a required **Product variant** / SKU selector appears.
3. The selected variant supplies the eligible active sale units and the default sale UOM.
4. With **Use tier price** enabled, Unit price is resolved and read-only. Disable it only when a justified manual price is required.
5. If that manual price is below the variant floor, the authoritative save validation still requires an approved exception. An operator with price-floor approval sees a required reason field for creating that approval evidence.
6. Enter tax and optional line description, then add more lines as needed. At least one line is required.
""",
    },
    ("sales", "quotation", "view"): {
        "markdown": """
### Customer decision and conversion

A Sent quotation exposes **Record Decision**. Accepted needs the decision date; Rejected and Changes Requested require both the date and a note. Changes Requested/expired quotations can be re-quoted without overwriting the prior offer.

Only an Accepted quotation is convertible. Conversion is one-time and creates a **Confirmed Sales Order** with the quoted line quantity, UOM, price, tax and pricing provenance preserved. It does not create stock movements or reservations.

For Sent/Accepted quotations, **Generate PDF** queues document generation. Once generation finishes, the **Document** section shows **Preview PDF** and **Download**. If it still says **Not generated yet**, reload after processing completes or ask the administrator to check document generation. Generating a PDF does not email it to the customer.
""",
    },
    ("sales", "order", "index"): {
        "markdown": """
### Reading the order queue

The list combines commercial status with **Availability**, **Milestone**, payment status and the requested date. Use the Active, Awaiting fulfillment and Requires attention views to find orders that are waiting on operational work.

The emphasized row action represents the projected **Next step**. It can navigate to supply resolution, Logistics allocation/dispatch/arrival, invoicing or payment. It is a navigation handoff to the owning module, not an in-table shortcut that bypasses that module.
""",
    },
    ("sales", "order", "create"): {
        "replace_auto": True,
        "markdown": """
### Three-step order wizard

#### 1. Customer & destination

Select **Customer** first. Choose an active delivery address belonging to that customer when addresses exist, optionally set the requested delivery date, and assign the responsible salesperson. Warehouse allocation is intentionally absent here.

#### 2. Products & commercial quantities

Add one or more product variants, choose an eligible sale **UOM**, and enter customer quantity. IERP shows the resolved unit-price preview and current available base quantity as advice. Saving or confirming here does **not** reserve or move that stock.

#### 3. Commercial terms & review

Select the payment term, add order notes, and review the document. Users with confirmation permission can enable **Save and confirm commercial order**. Confirmation freezes the commercial quantity/UOM/price/tax evidence, but Logistics still requires an explicit Release before it starts warehouse execution.

After creation, continue from the Order detail/list actions; never enter warehouse, lot, serial or shipment data into the Sales wizard.
""",
    },
    ("sales", "delivery-note", "index"): {
        "markdown": """
### Delivery working views

Use **Ready to dispatch**, **Delivered, not invoiced**, **Delivered** and **Cancelled** views to separate logistics progress from billing follow-up. The **Uninvoiced** filter means the Inventory delivery is Done and no invoice-delivery link exists yet.

**Create invoice** is deliberately conditional: it appears only for a Done, uninvoiced delivery when the operator can create invoices.
""",
    },
    ("sales", "delivery-note", "view"): {
        "markdown": """
### What the detail page represents

This page reads the Inventory delivery operation: reference, stage, customer, source warehouse, schedule, notes, delivered lines and warehouse-preparation state. Related documents show how the operation connects back to the order and forward to shipment/invoice work.

**Generate Packing List** produces operational output. **Create invoice**, when eligible, creates a Draft invoice from this completed delivery and redirects to that invoice.
""",
    },
    ("sales", "invoice", "index"): {
        "markdown": """
### Document status vs financial status

Do not treat **Document status** and **Financial status** as the same thing. Document status describes the invoice lifecycle; Financial status is derived from payment, credit and write-off settlement. **Outstanding** shows the amount still open after those effects.

The **Next action** column and Needs attention/Overdue/Unpaid/Partially paid views are designed as collection queues. Export CSV downloads the current filtered/searched invoice result.
""",
    },
    ("sales", "invoice", "view"): {
        "markdown": """
### Issue, collect and correct

Issuing is the accounting boundary: it freezes commercial content and posts Accounts Receivable, Revenue and Deferred Sales Tax. After issue, use **Record payment** for collection, **Create credit note** when invoiced value itself must be reduced, or **Write off receivable** when the company intentionally stops collecting valid debt.

Document generation and delivery are separate: **Generate PDF** queues the PDF; **Send email copy** is available only after the stored PDF exists and does not post accounting. Receipt confirmation records evidence that the customer received the document.
""",
    },
    ("sales", "payment", "index"): {
        "markdown": """
### Read collection and allocation separately

**Received** is the payment amount, **Applied to invoices** is what has been allocated, and **Available customer deposit** is the posted remainder still available for future eligible invoices. The Customer deposits view focuses records with that usable remainder.

Draft payments can be posted; posted payments are immutable through the normal edit form. Export CSV downloads the current filtered/searched payment set.
""",
    },
    ("sales", "payment", "view"): {
        "markdown": """
### Posting and allocation

Posting opens an allocation form containing eligible issued invoices for the same customer and the amount to apply to each. When the payment was opened from an invoice, IERP can prefill that invoice allocation up to the smaller of the payment amount and current outstanding balance.

After posting, the allocation table lets you compare the applied amount with the invoice's current outstanding balance. Any unused posted amount remains customer deposit. Reversal uses the explicit **Reverse payment** action rather than editing a posted record.
""",
    },
    ("sales", "payment-transaction", "index"): {
        "markdown": """
### Provider state and ERP settlement

The list intentionally shows **Provider status** beside **ERP settlement**. A transaction can have succeeded at the provider while still requiring ERP settlement.

Use **Refresh provider status** to reconcile an unsettled transaction with the provider. Use **Complete ERP settlement** only for a provider-Succeeded transaction that has not finished internal settlement. Neither action lets an operator type or force Succeeded manually.
""",
    },
    ("sales", "payment-transaction", "view"): {
        "markdown": """
### Reconciliation evidence

The detail screen keeps provider identifiers, amount, purpose, failure information, last provider event, idempotency key, settlement description and any linked ERP Payment together. Use the linked Payment to inspect allocations/accounting after settlement.
""",
    },
    ("sales", "credit-note", "index"): {
        "markdown": """
### Credit-note working views

Use **Draft**, **Confirmed this month**, **Confirmed**, **Sales returns**, and **Reversed / Cancelled** to separate preparation, posted credit work, return-related credits and historical reversals. The list keeps the source invoice and Inventory consequence visible so a financial correction can be traced to both its billing source and any physical-return context.

**Export CSV** downloads the current filtered/searched credit-note rows. Confirmation, PDF generation and reversal stay on the record detail because they change or document the credit-note lifecycle.
""",
    },
    ("sales", "credit-note", "create"): {
        "markdown": """
### Choose the inventory consequence explicitly

Select the customer/source invoice and a reason category, then choose one inventory consequence:

- **Goods returned** — a Posted Inventory Return for the same customer becomes required.
- **Customer retained goods** — the financial credit does not claim that goods returned to stock.
- **No inventory movement** — use when the correction has no physical stock consequence.

Subtotal, tax and grand total shown by this form are calculated/read-only context rather than free-form correction amounts. The credit-note lines and service rules determine the controlled credit value.
""",
    },
    ("sales", "credit-note", "view"): {
        "markdown": """
### Confirm, refund and reverse

A Draft can be edited until **Confirm** applies the controlled credit effect. The detail page preserves source invoice, reason, stock consequence, line values, linked Inventory Return where relevant, refunds and journal evidence.

**Generate PDF** is available for confirmed notes. **Reverse** reverses a confirmed credit note through the service workflow; it does not delete the original financial history.
""",
    },
    ("sales", "sales-report", "index"): {
        "replace_auto": True,
        "markdown": """
### Choose the question first

The **Report** selector contains nine read-only views:

| Report | What it answers |
| --- | --- |
| Quotation Funnel | How quotation volume is distributed across statuses, employees and customers. |
| Win Loss Analysis | Closed opportunities, win/loss counts, win rate, owners and loss reasons. |
| Conversion Velocity | Median time from sent quotation to decision and from acceptance to order conversion. |
| Delivered Not Invoiced | Completed deliveries that still require an invoice. |
| Invoiced Not Collected | Issued receivables still outstanding, including ageing and control-account tie-out. |
| Tax Recognition Summary | Output/input tax recognition and reversal position for the reporting period. |
| Discount And Floor Overrides | Approved price-floor exceptions, approver, date, attempted/floor price and reason. |
| Returns Without Credit | Posted customer returns still missing a confirmed credit note. |
| Customer Revenue | Invoiced and collected revenue by customer. |

### Date behavior

Most reports use **From** and **To**. Delivered Not Invoiced, Invoiced Not Collected, Returns Without Credit and Customer Revenue use a single **As of** date instead. Clearing the filters removes the date restriction.

### Reading and exporting results

Metrics summarize the selected report; tables show the supporting rows and may link to the owning Customer, Inventory Return/Operation, Product Variant or other source record. Correct bad source data in that owning module.

**Export current report** downloads the selected report/date scope as CSV. It does not modify source records.
""",
    },
}

PAGE_OPERATOR_NOTES_AR = {
    ("sales", "dashboard", "overview"): {
        "markdown": """
### فلاتر لوحة المبيعات

استخدم **Date range** لتحديد الفترة. يقوم **Salesperson** بتضييق نشاط المبيعات المرتبط بملكية مندوب المبيعات على عرض السعر، بينما يحدد **Customer** عميلًا واحدًا. يعيد **Reset filters** نطاق اللوحة الافتراضي.

تغير الفلاتر طريقة العرض فقط ولا تعدل سجلات المبيعات. تبقى Requires Attention قائمة للحالات الحالية وليست تقريرًا مقيدًا بالكامل بالفترة، مع استمرار تطبيق فلتر العميل وموظف المبيعات.

### قراءة المؤشرات

تعتمد قيمة وعدد المبيعات المؤكدة ومتوسط الطلب على الطلبات التجارية المؤكدة. توضح نسبة تحويل عروض الأسعار والـFunnel التقدم التجاري. يرتب Top Customers/Products حسب قيمة الطلبات المؤكدة، بينما يعتمد Salesperson Performance على ملكية مندوب المبيعات في عرض السعر. افتح السجل المصدر من المؤشر عندما يحتاج إلى متابعة.
""",
    },
    ("sales", "quotation", "index"): {
        "markdown": """
### طرق العرض والتصدير

استخدم تبويبات الحالة للتركيز على المسودات، المرسلة بانتظار القرار، المقبولة، القريبة من الانتهاء، المفتوحة، المحولة، أو المرفوضة/الملغاة. عمود المفضلة وعرض Starred أدوات تنظيم شخصية ولا تغير دورة حياة العرض.

تعرض القائمة رقم العرض والعميل والحالة وتغطية المخزون وتاريخ الإصدار والانتهاء والإجمالي. تظهر إجراءات الصف حسب الحالة والصلاحية مثل Send وRecord Decision وConvert to Order وCreate Revised Quotation/Requote وView/Edit.

يقوم **Export CSV** بتنزيل نتيجة الجدول الحالية بعد البحث والفلاتر ويسجل حدث تدقيق للتصدير.
""",
    },
    ("sales", "quotation", "create"): {
        "markdown": """
### سلوك البنود المترابط

1. اختر العميل أولًا ليتمكن IERP من حل شريحة التسعير والإعدادات التجارية.
2. اختر المنتج. إذا كان لديه متغير فعال واحد يتم اختياره تلقائيًا، وإذا كان لديه عدة متغيرات فيظهر حقل **متغير المنتج** / SKU ويصبح إلزاميًا.
3. يحدد المتغير وحدات البيع الفعالة ووحدة البيع الافتراضية.
4. عند تفعيل **استخدام سعر الشريحة** يكون سعر الوحدة محلولًا وللقراءة فقط. ألغِ الخيار فقط عندما تحتاج سعرًا يدويًا مبررًا.
5. إذا كان السعر اليدوي أقل من الحد الأدنى، يبقى تحقق الحفظ بحاجة لاستثناء معتمد. من يملك صلاحية اعتماد الحد يظهر له سبب إلزامي لإنشاء دليل الاعتماد.
6. أدخل الضريبة والوصف الاختياري وأضف بنودًا أخرى عند الحاجة. يجب وجود بند واحد على الأقل.
""",
    },
    ("sales", "quotation", "view"): {
        "markdown": """
### قرار العميل والتحويل

يعرض العرض المرسل إجراء **Record Decision**. يحتاج القبول إلى تاريخ القرار، بينما يحتاج الرفض وطلب التعديلات إلى التاريخ والملاحظة. يمكن إنشاء عرض جديد مرتبط من العروض المنتهية أو التي طلب العميل تعديلها دون الكتابة فوق العرض السابق.

لا يمكن التحويل إلا لعرض مقبول، ويتم التحويل مرة واحدة إلى **طلب مبيعات مؤكد** مع الحفاظ على الكمية والوحدة والسعر والضريبة ومصدر التسعير. لا ينشئ التحويل حركة مخزون أو حجزًا.

لعرض السعر المُرسل أو المقبول، يقوم **إنشاء PDF** بجدولة إنشاء المستند. بعد اكتمال الإنشاء، يظهر **معاينة ملف PDF** وزر **تنزيل** في قسم **المستند**. إذا بقيت عبارة **لم يُنشأ الملف بعد**، أعد تحميل الصفحة بعد اكتمال المعالجة أو اطلب من مسؤول النظام التحقق من إنشاء المستند. إنشاء الملف لا يرسله بالبريد إلى العميل.
""",
    },
    ("sales", "order", "index"): {
        "markdown": """
### قراءة طابور الطلبات

تجمع القائمة بين الحالة التجارية و**التوفر** و**المرحلة** وحالة الدفع والتاريخ المطلوب. استخدم Active وAwaiting fulfillment وRequires attention للوصول إلى الطلبات التي تنتظر عملًا تشغيليًا.

يمثل إجراء **الخطوة التالية** المميز الانتقال المتوقع حسب سير العمل، وقد يفتح التوريد أو تخصيص المخزون أو الإرسال أو تأكيد الوصول أو الفوترة أو الدفع. هو انتقال إلى الوحدة المالكة وليس اختصارًا يتجاوزها.
""",
    },
    ("sales", "order", "create"): {
        "replace_auto": True,
        "markdown": """
### معالج إنشاء الطلب من ثلاث خطوات

#### 1. العميل والوجهة

اختر **العميل** أولًا. اختر عنوان تسليم فعالًا تابعًا له إذا كانت لديه عناوين، ويمكن تحديد تاريخ التسليم المطلوب وموظف المبيعات المسؤول. لا يتم تخصيص المستودع هنا.

#### 2. المنتجات والكميات التجارية

أضف متغير منتج واحدًا أو أكثر، واختر **وحدة بيع** مؤهلة وأدخل كمية العميل. يعرض IERP معاينة السعر المحلول والكمية المتاحة حاليًا بوحدة الأساس كمعلومة فقط. الحفظ أو التأكيد هنا لا يحجز أو يحرك هذا المخزون.

#### 3. الشروط التجارية والمراجعة

اختر شرط الدفع وأضف ملاحظات الطلب وراجع المستند. من يملك صلاحية التأكيد يمكنه تفعيل **Save and confirm commercial order**. يثبت التأكيد أدلة الكمية والوحدة والسعر والضريبة، لكن التنفيذ ما زال بحاجة إلى Release صريح نحو Logistics.

بعد الإنشاء تابع من إجراءات الطلب؛ لا تدخل بيانات المستودع أو الدفعة أو الرقم التسلسلي أو الشحنة داخل معالج المبيعات.
""",
    },
    ("sales", "delivery-note", "index"): {
        "markdown": """
### طرق عرض التسليم

استخدم **Ready to dispatch** و**Delivered, not invoiced** و**Delivered** و**Cancelled** لفصل تقدم اللوجستيات عن متابعة الفوترة. يعني فلتر **Uninvoiced** أن عملية المخزون مكتملة ولا يوجد رابط فاتورة تسليم بعد.

يظهر **Create invoice** فقط لتسليم مكتمل وغير مفوتر عندما يملك المستخدم صلاحية إنشاء فاتورة.
""",
    },
    ("sales", "delivery-note", "view"): {
        "markdown": """
### ماذا تمثل شاشة التفاصيل؟

تقرأ الصفحة عملية التسليم المملوكة للمخزون: المرجع والحالة والعميل والمستودع المصدر والموعد والملاحظات والبنود وحالة تحضير المستودع. تعرض المستندات المرتبطة الاتصال بالطلب والشحنة والفاتورة.

يقوم **Generate Packing List** بإنتاج مستند تشغيلي. أما **Create invoice** عند توفره فينشئ فاتورة مسودة من التسليم المكتمل ثم ينتقل إليها.
""",
    },
    ("sales", "invoice", "index"): {
        "markdown": """
### حالة المستند مقابل الحالة المالية

لا تعتبر **Document status** و**Financial status** شيئًا واحدًا. الأولى تصف دورة حياة الفاتورة، والثانية مشتقة من الدفع والائتمان والشطب. يعرض **Outstanding** القيمة التي بقيت مفتوحة بعد هذه الآثار.

يعمل عمود **Next action** وعروض Needs attention وOverdue وUnpaid وPartially paid كطوابير متابعة للتحصيل. ينزل Export CSV نتيجة الفواتير الحالية بعد البحث والفلاتر.
""",
    },
    ("sales", "invoice", "view"): {
        "markdown": """
### الإصدار والتحصيل والتصحيح

الإصدار هو حد الترحيل المحاسبي: يثبت المحتوى التجاري ويرحل الذمم المدينة والإيراد وضريبة المبيعات المؤجلة. بعده استخدم **Record payment** للتحصيل، و**Create credit note** لتخفيض القيمة المفوترة، أو **Write off receivable** عندما تتوقف الشركة عمدًا عن تحصيل دين صحيح.

توليد وإرسال المستند منفصلان: يقوم **Generate PDF** بجدولة الملف، ويظهر **Send email copy** بعد وجود PDF ولا يرحل محاسبيًا. ويمكن تأكيد الاستلام بدليل مخصص.
""",
    },
    ("sales", "payment", "index"): {
        "markdown": """
### افصل بين التحصيل والتخصيص

تمثل **Received** قيمة الدفعة، و**Applied to invoices** الجزء المخصص، و**Available customer deposit** الجزء المرحل المتبقي لاستخدامه مع فواتير مؤهلة لاحقًا. يركز عرض Customer deposits على هذا الرصيد المتاح.

يمكن ترحيل المسودات، ولا تعد المدفوعات المرحلة قابلة للتعديل بالنموذج العادي. ينزل Export CSV مجموعة المدفوعات الحالية بعد البحث والفلاتر.
""",
    },
    ("sales", "payment", "view"): {
        "markdown": """
### الترحيل والتخصيص

يفتح الترحيل نموذج تخصيص يحتوي الفواتير الصادرة والمفتوحة لنفس العميل وقيمة التطبيق على كل فاتورة. إذا فُتحت الدفعة من فاتورة فيمكن لـIERP تعبئة تخصيصها حتى أصغر قيمة بين الدفعة والرصيد المفتوح.

بعد الترحيل يقارن جدول التخصيص القيمة المطبقة بالرصيد الحالي للفاتورة. يبقى أي جزء غير مستخدم كعربون عميل. ويتم العكس عبر **Reverse payment** بدل تعديل سجل مرحل.
""",
    },
    ("sales", "payment-transaction", "index"): {
        "markdown": """
### حالة المزود مقابل تسوية ERP

تعرض القائمة **Provider status** و**ERP settlement** كلًا على حدة، فقد تنجح العملية لدى المزود وتبقى بحاجة لتسوية داخلية.

استخدم **Refresh provider status** للمطابقة مع المزود، و**Complete ERP settlement** فقط عندما تكون حالة المزود Succeeded ولم تكتمل التسوية الداخلية. لا يسمح أي منهما بكتابة Succeeded يدويًا.
""",
    },
    ("sales", "payment-transaction", "view"): {
        "markdown": """
### أدلة المطابقة

تجمع شاشة التفاصيل معرفات المزود والمبلغ والغرض وأخطاء الدفع وآخر حدث من المزود ومفتاح idempotency ووصف التسوية وأي دفعة ERP مرتبطة. افتح سجل Payment المرتبط لمراجعة التخصيصات والأثر الداخلي بعد التسوية.
""",
    },
    ("sales", "credit-note", "index"): {
        "markdown": """
### طرق عرض الإشعارات الدائنة

استخدم **Draft** و**Confirmed this month** و**Confirmed** و**Sales returns** و**Reversed / Cancelled** لفصل التحضير عن الائتمانات المرحلة والائتمانات المرتبطة بالمرتجعات والعكس التاريخي. تبقي القائمة الفاتورة المصدر وأثر المخزون ظاهرين حتى يمكن تتبع التصحيح المالي إلى مصدر الفوترة وأي سياق مرتجع مادي.

يقوم **Export CSV** بتنزيل الصفوف الحالية بعد البحث والفلاتر. تبقى إجراءات التأكيد وتوليد PDF والعكس على شاشة السجل لأنها تغير أو توثق دورة حياة الإشعار.
""",
    },
    ("sales", "credit-note", "create"): {
        "markdown": """
### اختر أثر المخزون صراحة

حدد العميل والفاتورة المصدر وتصنيف السبب، ثم اختر أحد آثار المخزون:

- **Goods returned** — يصبح مرتجع مخزون مرحل لنفس العميل إلزاميًا.
- **Customer retained goods** — التصحيح المالي لا يدعي رجوع البضاعة للمخزون.
- **No inventory movement** — عندما لا يملك التصحيح أثرًا ماديًا.

الإجمالي قبل الضريبة والضريبة والإجمالي النهائي المعروضة هنا سياق محسوب/للقراءة وليست قيم تصحيح حرة. تحدد البنود وقواعد الخدمة قيمة الائتمان المضبوطة.
""",
    },
    ("sales", "credit-note", "view"): {
        "markdown": """
### التأكيد والاسترداد والعكس

يمكن تعديل المسودة حتى يقوم **Confirm** بتطبيق أثر الائتمان المضبوط. تحفظ التفاصيل الفاتورة المصدر والسبب وأثر المخزون والبنود ومرتجع المخزون عند وجوده والاستردادات وأدلة القيود.

يتوفر **Generate PDF** للإشعارات المؤكدة. ويعكس **Reverse** الإشعار عبر المسار المخصص دون حذف التاريخ المالي الأصلي.
""",
    },
    ("sales", "sales-report", "index"): {
        "replace_auto": True,
        "markdown": """
### اختر السؤال أولًا

يحتوي اختيار **Report** تسعة تقارير للقراءة فقط:

| التقرير | السؤال الذي يجيب عنه |
| --- | --- |
| Quotation Funnel | توزيع حجم عروض الأسعار على الحالات والموظفين والعملاء. |
| Win Loss Analysis | الفرص المغلقة ومعدل الفوز والمالكون وأسباب الخسارة. |
| Conversion Velocity | الزمن الوسيط من إرسال العرض إلى القرار ومن القبول إلى تحويله لطلب. |
| Delivered Not Invoiced | التسليمات المكتملة التي ما زالت بحاجة إلى فاتورة. |
| Invoiced Not Collected | الذمم الصادرة غير المحصلة مع أعمارها ومطابقة حساب السيطرة. |
| Tax Recognition Summary | موضع الاعتراف وعكس ضريبة المبيعات للفترة. |
| Discount And Floor Overrides | استثناءات الحد الأدنى المعتمدة والموافق والتاريخ والسعر والسبب. |
| Returns Without Credit | المرتجعات المرحلة التي لا يغطيها إشعار دائن مؤكد. |
| Customer Revenue | القيمة المفوترة والمحصلة حسب العميل. |

### سلوك التاريخ

تستخدم معظم التقارير **من / إلى**. أما Delivered Not Invoiced وInvoiced Not Collected وReturns Without Credit وCustomer Revenue فتستخدم **As of** واحدًا. تؤدي إزالة الفلاتر إلى إلغاء قيد التاريخ.

### قراءة النتائج وتصديرها

تلخص المؤشرات التقرير المحدد، وتعرض الجداول الصفوف الداعمة وقد تربط بالسجل المالك مثل العميل أو مرتجع/عملية المخزون أو متغير المنتج. صحح البيانات الخاطئة في الوحدة المالكة.

يقوم **Export current report** بتنزيل CSV للتقرير والنطاق الزمني المحددين ولا يغير أي سجل مصدر.
""",
    },
}

def page_operator_notes(module: str, slug: str, page_key: str, ar: bool = False) -> dict[str, Any] | None:
    mapping = PAGE_OPERATOR_NOTES_AR if ar else PAGE_OPERATOR_NOTES_EN
    value = mapping.get((module, slug, page_key))
    return value if isinstance(value, dict) else None


RESOURCE_MANUAL_EN = {
    "quotation": {
        "when": [
            "Use a quotation when the customer needs a commercial offer before committing to an order.",
            "Use it when price provenance, validity, product/UOM selection, tax and any below-floor approval must be preserved before the sale is confirmed.",
            "Do not use a quotation to move stock. Physical stock changes begin later through fulfillment and Inventory.",
        ],
        "before": [
            "The customer must exist and be eligible for the commercial flow.",
            "Products/variants and units of measure must be active.",
            "Review the customer's pricing tier and payment-term defaults before entering lines.",
        ],
        "after": [
            "Saving creates a Draft quotation only; it does not reserve or reduce stock and does not post accounting.",
            "Send moves the commercial offer to the customer-facing stage.",
            "An Accepted quotation may be converted once into a Confirmed Sales Order.",
            "Rejected, expired, cancelled or changes-requested quotations do not create an order. Re-quote creates a new quotation with provenance to the prior one.",
        ],
    },
    "order": {
        "when": [
            "Use Sales Orders for confirmed customer demand, whether converted from an accepted quotation or created through an allowed direct-order path.",
            "The order is the Sales-owned commercial commitment that later hands work to fulfillment and shortage procurement.",
        ],
        "before": [
            "Confirm the customer, commercial terms, product variants, UOM and quantities.",
            "If the order came from a quotation, keep the source relationship rather than recreating the order manually.",
        ],
        "after": [
            "Confirming establishes committed demand; releasing is the operational handoff.",
            "Release can synchronize procurement requirements for quantities not covered by available Inventory.",
            "The order itself does not directly post warehouse stock or the general ledger.",
        ],
    },
    "customer": {
        "when": [
            "Use Customers as the master record for the organization or person buying from IERP.",
            "Open the customer when you need one place to understand commercial defaults, approval state, addresses, interactions, sales documents, service history or owned equipment.",
        ],
        "before": [
            "Capture identity and contact information first, then commercial defaults such as currency/payment terms and assigned salesperson.",
            "Add delivery addresses as structured addresses instead of embedding shipping details in free-form notes.",
            "Where approval is required, complete the review flow before relying on the customer in downstream transactions.",
        ],
        "after": [
            "The customer becomes reusable context across CRM, Sales, Payments, Support and reporting according to permissions.",
            "Changing master data does not rewrite historical documents that already captured their transaction values.",
            "Timeline and relation tabs are a 360° view; actions that belong to Sales, Inventory, Payments or Support remain owned by those modules.",
        ],
    },
    "product": {
        "when": [
            "Use Products to maintain commercial catalog identity and shared product attributes; use Variants for concrete SKU-level identity.",
            "Use this area to understand category, manufacturer, brand, operational profile and how the product connects to suppliers and Inventory.",
            "Do not edit Product data to repair a stock quantity. Quantity truth belongs to Inventory movements and counts.",
        ],
        "before": [
            "Decide whether the catalog item needs variants, tracking and a particular operational profile before activating it.",
            "Confirm category/manufacturer/brand and default unit choices because they influence downstream selection and reporting.",
        ],
        "after": [
            "A product can be selected by Sales/Purchasing only through the active catalog rules used by those workflows.",
            "Variants, supplier references and stock quantities remain separate facts even though they are reachable from the product record.",
        ],
    },
    "purchase-order": {
        "when": [
            "Use a Purchase Order when the business is ready to make a commercial commitment to a supplier.",
            "A PO can originate from replenishment or Sales shortage sourcing, or from an independent purchasing need.",
        ],
        "before": [
            "Choose the supplier first and verify active supplier/product references, purchasing UOM, currency, MOQ and lead-time constraints.",
            "Review approval thresholds and maker-checker requirements before sending the order.",
        ],
        "after": [
            "Submit/Approve controls authorization; first Send activates downstream inbound and draft supplier-bill work.",
            "Supplier Confirmation records committed quantities and promised dates.",
            "Inventory alone posts physical receipt. Purchasing tracks commercial and provenance status but does not write stock balances.",
        ],
    },
    "invoice": {
        "when": [
            "Use Invoices to record customer billing after eligible delivery work or for an explicitly supported standalone billing case.",
            "Invoice document state and financial settlement state are deliberately separate.",
        ],
        "before": [
            "Verify the customer, source delivery/order where applicable, currency, lines, tax and due-date/payment terms.",
            "Do not issue an invoice to work around unfinished fulfillment when the workflow requires a completed delivery source.",
        ],
        "after": [
            "Issue posts the approved invoice accounting effect through Accounting.",
            "Payments and allocations update financial settlement independently from the invoice's commercial document lifecycle.",
            "Corrections use Credit Notes/Refunds or other explicit financial corrections rather than rewriting issued history.",
        ],
    },
    "payment": {
        "when": [
            "Use Payments to record customer money movement and allocate it to eligible issued invoices.",
            "Use unallocated posted money as customer-deposit context when the payment exceeds the immediate allocation.",
        ],
        "after": [
            "Posting records the money/accounting effect and synchronizes allocations.",
            "Tax recognition follows collection/allocation rules rather than simply invoice issue.",
            "Reversal is an explicit workflow and can be blocked by downstream credit/refund dependencies.",
        ],
    },
    "stock-level": {
        "when": [
            "Use Stock Levels to answer what is physically on hand, reserved and available by warehouse/product context.",
            "This is a lookup and planning surface, not a quantity-edit screen.",
        ],
        "after": [
            "To change stock, open the owning Inventory operation: receipt, delivery, transfer, adjustment, count, return or correction.",
            "Availability shown here can affect Sales fulfillment and replenishment recommendations.",
        ],
    },
    "inventory-operation": {
        "when": [
            "Use Inventory Operations for controlled physical movements such as receipts, deliveries and internal transfers.",
            "Use the source document relationship so the movement preserves why the stock moved.",
        ],
        "before": [
            "Verify warehouse, product/variant, UOM-normalized quantity and lot/serial requirements before moving to a posted state.",
            "Tracked items must preserve lot/serial identity and eligible condition/custody.",
        ],
        "after": [
            "Ready may reserve/prepare stock without changing on-hand where the operation requires that distinction.",
            "Completion is the stock-posting boundary and must be idempotent.",
            "Historical posted movement is corrected through an explicit correction/compensating path rather than destructive editing.",
        ],
    },
    "supplier": {
        "when": [
            "Use Suppliers as the purchasing-side master record for organizations that can provide products or services.",
            "Supplier defaults and product references are reused by RFQs, Purchase Orders, replenishment and Accounts Payable context.",
        ],
        "after": [
            "Supplier changes influence future purchasing choices but do not rewrite historical PO/bill snapshots.",
            "Physical receipt remains Inventory-owned and supplier liabilities remain Accounting-owned.",
        ],
    },
    "ticket": {
        "when": [
            "Use Tickets as the primary customer support case from intake/triage through assignment, work, resolution and closure.",
            "Open a ticket when the business needs SLA, entitlement, warranty, equipment or maintenance context to remain auditable.",
        ],
        "before": [
            "Identify the customer and related equipment/serial when known instead of creating duplicate equipment context.",
            "Triage priority, entitlement, queue/team and SLA context before work starts.",
        ],
        "after": [
            "Tickets can create or coordinate maintenance/service work, but they do not directly rewrite stock custody or posted accounting.",
            "Closure can be blocked by unfinished child work such as maintenance, appointments, loaner or supplier-repair activity.",
        ],
    },
    "employee": {
        "when": [
            "Use Employees for the workforce master record used by plans, tasks, visits, performance and salary calculation.",
            "Operational employee activity belongs in its dedicated planning/visit records rather than being hidden in employee notes.",
        ],
    },
    "visit": {
        "when": [
            "Use Visits to execute field activity against a customer through travel, check-in, in-progress work, outcome and follow-up.",
        ],
        "after": [
            "The normal lifecycle is Scheduled → En Route → In Progress → Completed, with Unable to Complete and Cancelled as terminal alternatives.",
            "Visit outcome/evidence can support follow-up or sales-opportunity review without bypassing the normal Sales lifecycle.",
        ],
    },
    "dashboard-user": {
        "when": [
            "Use Dashboard Users to manage who can access the administration interface and the role/permission context applied to that user.",
            "Permissions should be granted by business responsibility; cross-module permission boundaries are intentional.",
        ],
    },
}

RESOURCE_MANUAL_AR = {
    "quotation": {
        "when": [
            "استخدم عرض السعر عندما يحتاج العميل عرضًا تجاريًا قبل الالتزام بطلب مبيعات.",
            "يستخدم لحفظ مصدر السعر وفترة الصلاحية والمنتج ووحدة القياس والضريبة وأي موافقة على سعر أقل من الحد قبل تأكيد البيع.",
            "عرض السعر لا يحرك المخزون؛ الأثر المادي يبدأ لاحقًا عبر التنفيذ والمخزون.",
        ],
        "before": [
            "يجب أن يكون العميل موجودًا ومؤهلًا للمسار التجاري.",
            "يجب أن تكون المنتجات والمتغيرات ووحدات القياس المطلوبة فعالة.",
            "راجع شريحة تسعير العميل وشروط الدفع الافتراضية قبل إدخال البنود.",
        ],
        "after": [
            "الحفظ ينشئ عرض سعر مسودة فقط ولا يحجز أو ينقص المخزون ولا يرحّل محاسبيًا.",
            "الإرسال ينقل العرض إلى مرحلة التعامل مع العميل.",
            "يمكن تحويل عرض السعر المقبول مرة واحدة إلى طلب مبيعات مؤكد.",
            "العروض المرفوضة أو المنتهية أو الملغاة أو التي طلب العميل تعديلها لا تنشئ طلبًا. إعادة العرض تنشئ عرضًا جديدًا مرتبطًا بالسابق.",
        ],
    },
    "customer": {
        "when": [
            "استخدم العملاء كسجل رئيسي للجهة أو الشخص الذي يشتري من IERP.",
            "افتح العميل عندما تحتاج رؤية الإعدادات التجارية والموافقة والعناوين والتفاعلات والمبيعات والخدمة والمعدات في مكان واحد.",
        ],
        "before": [
            "أدخل الهوية وبيانات التواصل ثم الإعدادات التجارية مثل العملة وشروط الدفع وموظف المبيعات.",
            "أضف عناوين التسليم كعناوين منظمة بدل وضعها في ملاحظات حرة.",
            "إذا كانت الموافقة مطلوبة فأكمل المراجعة قبل استخدام العميل في العمليات التابعة.",
        ],
        "after": [
            "يصبح العميل سياقًا مشتركًا لـCRM والمبيعات والمدفوعات والدعم والتقارير حسب الصلاحيات.",
            "تعديل البيانات الرئيسية لا يعيد كتابة المستندات التاريخية التي احتفظت بقيمها وقت العملية.",
            "التبويبات والخط الزمني تقدم عرض 360°، لكن كل إجراء يبقى في الوحدة المالكة له.",
        ],
    },
    "product": {
        "when": [
            "استخدم المنتجات لتعريف هوية الكتالوج والخصائص المشتركة، واستخدم المتغيرات للهوية الفعلية على مستوى SKU.",
            "هذه الصفحة تربط التصنيف والمصنع والعلامة التجارية والملف التشغيلي بالموردين والمخزون.",
            "لا تعدل المنتج لتصحيح كمية مخزون؛ الحقيقة المادية تأتي من حركات المخزون والجرد.",
        ],
        "before": [
            "حدد مبكرًا إن كان المنتج يحتاج متغيرات أو تتبعًا أو ملفًا تشغيليًا محددًا قبل تفعيله.",
            "راجع التصنيف والمصنع والعلامة ووحدة القياس لأنها تؤثر على الاستخدام اللاحق.",
        ],
        "after": [
            "يمكن اختيار المنتج في المبيعات والمشتريات وفق قواعد الكتالوج الفعالة.",
            "المتغيرات ومراجع الموردين والكميات حقائق منفصلة رغم إمكانية الوصول لها من سجل المنتج.",
        ],
    },
    "purchase-order": {
        "when": [
            "استخدم أمر الشراء عندما تصبح الشركة جاهزة للالتزام التجاري مع المورد.",
            "قد يأتي الأمر من إعادة التزويد أو نقص طلب مبيعات أو حاجة شراء مستقلة.",
        ],
        "before": [
            "اختر المورد أولًا وراجع مرجع المورد للمنتج ووحدة الشراء والعملة والحد الأدنى ومدة التوريد.",
            "راجع حدود الموافقة وقاعدة maker-checker قبل الإرسال.",
        ],
        "after": [
            "الإرسال للموافقة والموافقة يحددان التفويض، وأول إرسال للمورد يفعّل أعمال الوارد ومسودة فاتورة المورد.",
            "يسجل تأكيد المورد الكمية المتعهد بها وتاريخ الوعد.",
            "المخزون وحده يرحّل الاستلام الفعلي، بينما تبقى الذمة لدى المحاسبة.",
        ],
    },
    "invoice": {
        "when": [
            "استخدم الفواتير لفوترة العميل بعد تنفيذ مؤهل أو لحالة فوترة مستقلة يدعمها النظام صراحة.",
            "حالة مستند الفاتورة منفصلة عن حالة التسوية المالية.",
        ],
        "after": [
            "إصدار الفاتورة يرحّل أثرها المعتمد عبر المحاسبة.",
            "تقوم المدفوعات والتخصيصات بتحديث التسوية بشكل مستقل عن دورة حياة مستند الفاتورة.",
            "التصحيح يتم بإشعار دائن أو استرداد أو مسار مالي صريح بدل إعادة كتابة التاريخ.",
        ],
    },
    "stock-level": {
        "when": [
            "استخدم مستويات المخزون لمعرفة الموجود والمحجوز والمتاح حسب المستودع والمنتج.",
            "هذه شاشة قراءة وتخطيط وليست شاشة تعديل مباشر للكميات.",
        ],
        "after": [
            "لتغيير الكمية استخدم عملية المخزون المالكة: استلام أو تسليم أو نقل أو تعديل أو جرد أو مرتجع أو تصحيح.",
            "التوفر الظاهر هنا قد يؤثر على التنفيذ وتوصيات إعادة التزويد.",
        ],
    },
    "ticket": {
        "when": [
            "استخدم التذاكر كحالة الدعم الرئيسية من الاستقبال والفرز حتى الإسناد والعمل والحل والإغلاق.",
            "التذكرة تحفظ سياق SLA والاستحقاق والضمان والمعدات والصيانة بصورة قابلة للتدقيق.",
        ],
        "before": [
            "حدد العميل والمعدة أو الرقم التسلسلي المعروف بدل إنشاء سياق معدات مكرر.",
            "راجع الأولوية والاستحقاق والطابور أو الفريق وSLA قبل بدء العمل.",
        ],
        "after": [
            "يمكن للتذكرة تنسيق الصيانة أو الخدمة لكنها لا تغير عهدة المخزون أو المحاسبة المرحلة مباشرة.",
            "قد يمنع وجود عمل فرعي غير مكتمل إغلاق التذكرة.",
        ],
    },
}


SALES_VERIFIED_EN = {
    "dashboard": [
        "The date-range filter controls period-based Sales metrics; Salesperson and Customer narrow the same dashboard to the selected commercial owner/customer. Reset Filters restores the dashboard defaults.",
        "The canonical Sales value on this dashboard is confirmed order value, not invoiced revenue or collected cash. It sums orders that are Confirmed, Released or Closed and dates them by confirmation time.",
        "Salesperson attribution comes from the quotation's employee assignment. A direct order without a source quotation is not silently attributed from the order's logistics/responsible user.",
        "KPI cards show confirmed sales value, confirmed order count, average order value and quotation conversion. The sales performance chart compares the selected period with an equal-length previous period.",
        "The funnel follows Quotations → Accepted → Orders → Delivered → Invoiced. It is a commercial progression view, not a stock or cash ledger.",
        "Requires Attention is a current-state work queue. Its items are intentionally not limited by the selected date period, although Customer/Salesperson filters still apply.",
        "Top Customers and Top Products rank confirmed order value. Salesperson Performance can be hidden when no quotation carries salesperson ownership data.",
    ],
    "quotation": [
        "Selecting a customer refreshes the Active Pricing Tier card. Selecting a product can reveal a Product Variant/SKU selector when the product has multiple variants; the chosen variant then constrains the sale UOM and resolved price.",
        "Use Tier Price keeps the line tied to the eligible resolved pricing tier. If a manually entered price is below the permitted floor, the form requires a reason and stores the approved/linked floor-override provenance separately from the visible reason.",
        "Send is available only from Draft and makes the quotation a customer-facing offer; direct editing is no longer the normal path after sending.",
        "Record Decision is available on Sent quotations. Accepted, Rejected and Changes Requested are explicit outcomes; the decision date is required, and Rejected or Changes Requested also require a decision note.",
        "Convert is available only when the quotation is eligible for conversion. Browser QA verified that one accepted quotation produced exactly one Confirmed Sales Order while preserving quantity, UOM, unit price, tax and pricing provenance.",
        "Conversion does not reserve stock, create stock movements or reduce on-hand quantity. Inventory effects happen later through fulfillment/Inventory.",
        "Re-quote is the correction path for an expired quotation or one where the customer requested changes; it creates a new linked quotation instead of rewriting the prior decision history.",
        "Generate PDF creates or regenerates the document for Sent/Accepted quotations. After generation, use Preview PDF or Download in the Document section. Generation is separate from opening, downloading or emailing a document.",
    ],
    "order": [
        "Create Order is a three-step wizard: Customer & destination → Products & commercial quantities → Commercial terms & review.",
        "Customer selection controls eligible delivery addresses. The address is snapshotted into the order so later master-data edits do not rewrite the destination that was committed for this transaction.",
        "Product availability shown during creation is advisory only. Saving or confirming the Sales Order does not reserve stock or move inventory.",
        "Confirm freezes the customer-facing quantity, UOM, price and tax evidence. Release is a separate action that hands confirmed demand to Logistics and still does not itself create a delivery, reservation or on-hand movement.",
        "The detail page separates commercial status from operational availability and shows the cross-module journey: Quotation → Order → Logistics → Delivery → Invoice → Payment → Completion.",
        "The Next Step action is dynamic. Depending on current progress it can route the operator to supply requirements, stock allocation, dispatch, arrival confirmation, invoice creation/issue or payment.",
        "Short Close is available on Released orders with remaining unplanned demand. It records exact quantities being abandoned plus a mandatory reason; it does not silently change the originally ordered quantity.",
        "Cancel requires a reason. Direct Edit is available only while the order remains Draft; the edit form is intentionally limited to requested delivery date, payment term and notes.",
    ],
    "delivery-note": [
        "Delivery Notes are a Sales-facing view of Inventory delivery operations. The record is an InventoryOperation filtered to operation type Delivery, so Inventory remains the physical stock authority.",
        "The list shows delivery number, customer, source warehouse, schedule, stage and whether the completed delivery has already been invoiced. The Uninvoiced filter focuses Done deliveries that have no invoice link.",
        "The detail page shows the source warehouse, customer, stage, line quantities/UOM, warehouse-preparation state and related documents.",
        "Create Invoice appears only when the delivery is Done, has not already been invoiced and the user may create invoices. It creates a Draft invoice from the delivery and links the two records.",
        "Generating/using the packing list and completing physical delivery remain Inventory/Logistics responsibilities; Sales should not edit delivery quantities from this screen.",
    ],
    "invoice": [
        "Use a Draft invoice to review commercial values before posting. Issue is the posting boundary: it freezes commercial content and posts Accounts Receivable, Revenue and Deferred Sales Tax.",
        "Sending an email copy is not the accounting event. It is available only when a stored invoice PDF exists and does not change the ledger.",
        "Generate PDF is available for issued invoices and queues/regenerates the stored invoice document.",
        "Confirm Receipt records how the customer confirmed receipt and can include notes and optional signature evidence. It is available after the invoice is Sent.",
        "Record Payment opens the Payment creation flow only for issued invoices with an outstanding balance.",
        "Write Off Receivable is for a valid amount the company intentionally stops collecting; Create Credit Note is for correcting/reducing the invoiced amount itself. They are distinct financial corrections.",
        "If automatic customer-deposit application failed during issue, Retry Deposit Application safely re-checks unallocated posted deposits and is designed to be idempotent.",
    ],
    "payment": [
        "Create Payment records the customer, active payment method, amount, currency, payment date, optional external reference/proof and notes. Payment proof is a creation-time evidence upload.",
        "Posting is the financial boundary. The Post action lets the operator allocate the payment to eligible issued invoices for the same customer; only invoices with outstanding value are offered.",
        "When Payment Create is opened from an invoice, the customer/invoice context can prefill the intended allocation, but the operator still reviews the amount before posting.",
        "Any posted excess that is not allocated to an invoice remains customer-deposit context rather than being silently discarded.",
        "Posted payment records are not normally edited through the form. Reverse uses an explicit financial reversal path and is available only for posted, non-reversed payments with permission.",
    ],
    "payment-transaction": [
        "Payment Transactions expose provider-side payment state separately from IERP settlement state. A provider success is not the same thing as a successfully created/allocated ERP payment.",
        "Refresh Status reconciles an unsettled transaction with the configured provider. The action never writes Succeeded by hand; provider reconciliation is the source of provider status.",
        "Retry Settlement is available only when the provider transaction is Succeeded but ERP settlement is still incomplete. It retries the internal settlement without pretending the provider charged the customer again.",
        "Use the Requires Attention filter to find provider/ERP settlement mismatches. Review purpose, provider identifiers, failure information, linked ERP payment and settlement description before retrying.",
    ],
    "credit-note": [
        "A Credit Note corrects or reduces invoiced customer value; it is not itself the physical return operation.",
        "Choose the source invoice and a reason category, then record a human-readable reason. Totals are derived from the credit-note lines/context rather than freely edited totals.",
        "Stock Consequence makes the relationship to physical goods explicit. When Goods Returned is selected, a Posted Inventory Return for the same customer becomes required.",
        "Confirm is the financial commitment/posting path for the credit. Reverse is an explicit reversal; do not delete or rewrite a confirmed credit note to undo history.",
        "Generate PDF is available only after confirmation.",
    ],
    "sales-report": [
        "Sales Reports are read-only analytical surfaces. They do not create/edit Sales records and should never be used as a repair path for bad source data.",
        "The Report selector switches among nine report types. Most reports use From/To dates; Delivered Not Invoiced, Invoiced Not Collected, Returns Without Credit and Customer Revenue use an As of date instead.",
        "Quotation Funnel shows quotation volume by status. Win/Loss Analysis explains closed outcomes. Conversion Velocity measures time from sent quotation to decision/conversion.",
        "Delivered Not Invoiced identifies completed deliveries still missing an invoice; Invoiced Not Collected identifies issued receivables still outstanding.",
        "Tax Recognition Summary reports recognized sales tax. Discount and Floor Overrides audits approved pricing exceptions and their provenance.",
        "Returns Without Credit identifies posted customer returns that are not yet covered by a confirmed credit note. Customer Revenue compares invoiced and collected value by customer.",
        "Export Current downloads the currently selected report and filters as CSV. Export does not change any business record.",
        "When a value is wrong, correct the owning source transaction/workflow and rerun the report rather than editing the report output.",
    ],
}

SALES_VERIFIED_AR = {
    "dashboard": [
        "فلتر الفترة يتحكم بالمؤشرات المرتبطة بالزمن، بينما يضيّق فلتر موظف المبيعات والعميل لوحة المتابعة على المالك التجاري أو العميل المختار. يعيد Reset Filters القيم الافتراضية.",
        "قيمة المبيعات الأساسية في اللوحة هي قيمة طلبات المبيعات المؤكدة، وليست قيمة الفواتير أو النقد المحصل. تشمل الطلبات Confirmed وReleased وClosed ويعتمد تاريخها على وقت التأكيد.",
        "نسبة المبيعات إلى الموظف تأتي من الموظف المحدد على عرض السعر، ولا ينسب النظام الطلب المباشر تلقائيًا إلى مسؤول اللوجستيات.",
        "تعرض بطاقات KPI قيمة المبيعات المؤكدة وعدد الطلبات المؤكدة ومتوسط قيمة الطلب ونسبة تحويل عروض الأسعار. يقارن مخطط الأداء الفترة المختارة بفترة سابقة مساوية لها.",
        "يعرض Funnel المسار: عروض الأسعار ← المقبولة ← الطلبات ← المسلّمة ← المفوترة. وهو عرض لتقدم المسار التجاري وليس دفتر مخزون أو تحصيل.",
        "Requires Attention قائمة عمل للحالات الحالية، لذلك لا تتقيد بفترة التاريخ المختارة، لكنها تحترم فلتر العميل وموظف المبيعات.",
        "يرتب Top Customers وTop Products حسب قيمة الطلبات المؤكدة. وقد يختفي Salesperson Performance إذا لم توجد عروض أسعار مرتبطة بموظفي مبيعات.",
    ],
    "quotation": [
        "اختيار العميل يحدث بطاقة شريحة التسعير الفعالة. وإذا كان المنتج متعدد المتغيرات يظهر اختيار SKU/المتغير، ثم يحدد المتغير وحدات البيع والسعر المحلول المسموح بها.",
        "خيار Use Tier Price يبقي سعر البند مرتبطًا بشريحة السعر المؤهلة. وإذا تم إدخال سعر أقل من الحد المسموح يصبح سبب التجاوز مطلوبًا ويحتفظ النظام بمرجع موافقة تجاوز السعر منفصلًا عن النص الظاهر.",
        "إجراء Send متاح من Draft فقط، وبعد الإرسال يصبح العرض موجّهًا للعميل ولا يبقى التعديل المباشر هو المسار الطبيعي.",
        "Record Decision متاح للعروض Sent. القرارات هي Accepted أو Rejected أو Changes Requested؛ تاريخ القرار مطلوب، كما تصبح الملاحظة مطلوبة عند الرفض أو طلب التعديلات.",
        "Convert متاح فقط عندما يكون العرض قابلًا للتحويل. أكد اختبار المتصفح أن العرض المقبول ينشئ طلب مبيعات Confirmed واحدًا ويحافظ على الكمية ووحدة القياس والسعر والضريبة ومصدر التسعير.",
        "التحويل لا يحجز مخزونًا ولا ينشئ حركة مخزون ولا ينقص الموجود. يبدأ الأثر المادي لاحقًا عبر التنفيذ والمخزون.",
        "Re-quote هو مسار التعديل للعرض المنتهي أو الذي طلب العميل تغييره، وينشئ عرضًا جديدًا مرتبطًا بالسابق بدل إعادة كتابة تاريخ القرار.",
        "ينشئ إجراء إنشاء PDF مستند عرض السعر المُرسل أو المقبول، أو يعيد إنشاءه. بعد اكتمال الإنشاء، استخدم معاينة ملف PDF أو تنزيل من قسم المستند. إنشاء الملف منفصل عن فتحه أو تنزيله أو إرساله بالبريد.",
    ],
    "order": [
        "إنشاء طلب المبيعات Wizard من ثلاث خطوات: العميل والوجهة ← المنتجات والكميات التجارية ← الشروط التجارية والمراجعة.",
        "اختيار العميل يحدد عناوين التسليم المؤهلة، ويتم حفظ Snapshot للعنوان داخل الطلب حتى لا تعيد تعديلات ملف العميل كتابة وجهة العملية التاريخية.",
        "التوفر الظاهر أثناء الإنشاء استرشادي فقط؛ حفظ الطلب أو تأكيده لا يحجز ولا يحرك المخزون.",
        "Confirm يثبت الكمية ووحدة القياس والسعر والضريبة التجارية. Release خطوة مستقلة تسلم الطلب المؤكد إلى اللوجستيات ولا تنشئ بحد ذاتها تسليمًا أو حجزًا أو حركة موجود.",
        "تفاصيل الطلب تفصل حالة المستند التجاري عن جاهزية المخزون وتعرض المسار: عرض سعر ← طلب ← لوجستيات ← تسليم ← فاتورة ← دفع ← إكمال.",
        "إجراء Next Step ديناميكي وقد يفتح معالجة نقص التوريد أو تخصيص المخزون أو الإرسال أو تأكيد الوصول أو إنشاء/إصدار الفاتورة أو الدفع حسب حالة الطلب.",
        "Short Close متاح للطلبات Released التي بقيت فيها كمية غير مخططة، ويتطلب تحديد الكمية المتروكة وسببًا إلزاميًا دون تغيير الكمية الأصلية بصمت.",
        "الإلغاء يتطلب سببًا. التعديل المباشر متاح فقط في Draft، ونموذج التعديل مقصود أن يقتصر على تاريخ التسليم المطلوب وشروط الدفع والملاحظات.",
    ],
    "delivery-note": [
        "Delivery Notes واجهة مبيعات للقراءة فوق عمليات تسليم يملكها Inventory. السجل هو InventoryOperation من النوع Delivery، لذلك تبقى الحقيقة المادية للمخزون في Inventory.",
        "تعرض القائمة رقم التسليم والعميل ومستودع المصدر والموعد والمرحلة وحالة الفوترة. فلتر Uninvoiced يركز على عمليات Done غير المرتبطة بفاتورة.",
        "تعرض التفاصيل المستودع والعميل والمرحلة وكميات البنود ووحداتها وحالة تجهيز المستودع والمستندات المرتبطة.",
        "Create Invoice يظهر فقط إذا كانت عملية التسليم Done وغير مفوترة ولدى المستخدم صلاحية إنشاء فاتورة، وينشئ Draft Invoice مرتبطة بعملية التسليم.",
        "Packing List والتنفيذ المادي للتسليم مسؤولية Inventory/Logistics ولا يجب تعديل كميات التسليم من واجهة المبيعات.",
    ],
    "invoice": [
        "استخدم الفاتورة Draft لمراجعة القيم قبل الترحيل. Issue هو حد الترحيل: يثبت المحتوى التجاري ويسجل Accounts Receivable وRevenue وDeferred Sales Tax.",
        "إرسال نسخة بريدية ليس حدثًا محاسبيًا، ويظهر فقط عند وجود PDF مخزن للفاتورة ولا يغير الدفتر.",
        "Generate PDF متاح للفواتير المصدرة ويولّد أو يعيد توليد المستند المخزن.",
        "Confirm Receipt يسجل طريقة تأكيد العميل للاستلام ويمكن أن يتضمن ملاحظات وتوقيعًا اختياريًا، ويظهر بعد حالة Sent.",
        "Record Payment يفتح إنشاء دفعة فقط لفاتورة مصدرة لها رصيد متبقٍ.",
        "Write Off Receivable يستخدم عندما تتوقف الشركة عن تحصيل ذمة صحيحة؛ Create Credit Note يستخدم عندما يجب تصحيح أو تخفيض قيمة الفاتورة نفسها.",
        "إذا فشل تطبيق عربون العميل تلقائيًا أثناء الإصدار، يعيد Retry Deposit Application فحص العربون المرحّل غير المخصص بطريقة idempotent.",
    ],
    "payment": [
        "ينشئ Payment سجلًا للعميل وطريقة الدفع الفعالة والمبلغ والعملة وتاريخ الدفع والمرجع أو إثبات الدفع الاختياري والملاحظات.",
        "Post هو حد الأثر المالي، ويتيح تخصيص الدفعة لفواتير مصدرة ومفتوحة لنفس العميل فقط.",
        "عند فتح إنشاء الدفعة من فاتورة يمكن للنظام تعبئة العميل والفاتورة المقصودة مسبقًا، لكن المستخدم يراجع المبلغ قبل الترحيل.",
        "أي مبلغ مرحّل يزيد على التخصيصات يبقى سياق عربون للعميل ولا يختفي.",
        "بعد الترحيل لا يعدل سجل الدفع عادة بالنموذج. Reverse يستخدم مسار عكس مالي صريح للدفعة المرحلة وغير المعكوسة.",
    ],
    "payment-transaction": [
        "تفصل Payment Transactions بين حالة مزود الدفع وحالة التسوية داخل IERP. نجاح المزود لا يعني تلقائيًا اكتمال إنشاء/تخصيص Payment داخل النظام.",
        "Refresh Status يصالح المعاملة غير المسواة مع المزود ولا يكتب Succeeded يدويًا؛ نتيجة المصالحة هي مصدر حالة المزود.",
        "Retry Settlement يظهر فقط عندما تكون حالة المزود Succeeded لكن التسوية الداخلية غير مكتملة، ويعيد محاولة التسوية دون تنفيذ خصم جديد على المزود.",
        "استخدم فلتر Requires Attention للعثور على اختلافات المزود/IERP وراجع الغرض ومعرفات المزود ورسالة الفشل والدفعة المرتبطة ووصف التسوية قبل إعادة المحاولة.",
    ],
    "credit-note": [
        "Credit Note يصحح أو يخفض قيمة مفوترة للعميل، ولا يمثل بحد ذاته عملية إرجاع البضاعة المادية.",
        "حدد الفاتورة المصدر وتصنيف السبب وسجل سببًا واضحًا. الإجماليات مشتقة من بنود/سياق الإشعار وليست قيمًا حرة للتعديل.",
        "Stock Consequence يوضح علاقته بالبضاعة. عند اختيار Goods Returned يصبح Inventory Return مرحّل لنفس العميل مطلوبًا.",
        "Confirm هو مسار اعتماد الأثر المالي. Reverse عكس صريح؛ لا تحذف أو تعيد كتابة إشعار دائن مؤكد لإلغاء التاريخ.",
        "Generate PDF متاح بعد تأكيد الإشعار.",
    ],
    "sales-report": [
        "Sales Reports شاشات تحليل للقراءة فقط؛ لا تنشئ أو تعدل سجلات المبيعات ولا تستخدم لإصلاح بيانات المصدر.",
        "Report يبدل بين تسعة تقارير. تستخدم معظم التقارير From/To، بينما Delivered Not Invoiced وInvoiced Not Collected وReturns Without Credit وCustomer Revenue تستخدم تاريخ As of.",
        "Quotation Funnel يعرض حجم عروض الأسعار حسب الحالة، وWin/Loss يشرح النتائج المغلقة، وConversion Velocity يقيس الزمن من الإرسال حتى القرار/التحويل.",
        "Delivered Not Invoiced يكشف التسليمات المكتملة التي لم تفوتر، وInvoiced Not Collected يكشف الذمم المصدرة غير المحصلة.",
        "Tax Recognition Summary يعرض ضريبة المبيعات المعترف بها، وDiscount and Floor Overrides يدقق استثناءات حد السعر المعتمدة ومصدرها.",
        "Returns Without Credit يكشف المرتجعات المرحلة التي لم تغط بإشعار دائن مؤكد، وCustomer Revenue يقارن المفوتر والمحصّل حسب العميل.",
        "Export Current ينزل التقرير الحالي مع فلاتره كملف CSV ولا يغير أي سجل تجاري.",
        "إذا كانت قيمة التقرير غير صحيحة فصحح العملية في الوحدة المالكة ثم أعد تشغيل التقرير بدل تعديل ناتج التقرير.",
    ],
}



SALES_TYPICAL_TASKS_EN = {
    "dashboard": [
        "Change Date range, Salesperson or Customer to focus the dashboard without changing source records.",
        "Use KPI/funnel/trend widgets to understand commercial performance, then open the source record for follow-up.",
        "Use Requires Attention as the current-state work queue even when you are reviewing a historical date period.",
    ],
    "invoice": [
        "Use the list views to find invoices that need issue, collection or exception follow-up.",
        "Open invoices created from eligible delivery/billing flows; the invoice list intentionally does not expose a generic New Invoice header action.",
        "Issue Draft invoices, record payment for issued outstanding invoices, and use explicit Credit Note / Write-off paths for corrections.",
        "Generate/send invoice documents separately from the accounting issue event.",
    ],
    "sales-report": [
        "Choose the business question from the Report selector and set the applicable From/To or As of date.",
        "Read the metrics and supporting tables, then drill into the owning source record when a value needs investigation.",
        "Export the currently selected report/date scope as CSV without changing source data.",
    ],
}

SALES_TYPICAL_TASKS_AR = {
    "dashboard": [
        "غيّر الفترة أو موظف المبيعات أو العميل لتضييق لوحة المتابعة دون تعديل السجلات المصدرية.",
        "استخدم مؤشرات KPI والـFunnel والاتجاهات لفهم الأداء التجاري ثم افتح السجل المصدر للمتابعة.",
        "استخدم Requires Attention كقائمة للحالات الحالية حتى عند مراجعة فترة تاريخية.",
    ],
    "invoice": [
        "استخدم طرق عرض القائمة للوصول إلى الفواتير التي تحتاج إصدارًا أو تحصيلًا أو معالجة استثناء.",
        "افتح الفواتير الناتجة عن مسارات التسليم أو الفوترة المؤهلة؛ قائمة الفواتير لا تعرض عمدًا زر New Invoice عام في رأس الصفحة.",
        "أصدر الفواتير المسودة وسجل الدفع للفواتير الصادرة المفتوحة واستخدم إشعارًا دائنًا أو شطبًا صريحًا للتصحيح.",
        "افصل توليد/إرسال مستند الفاتورة عن حدث الإصدار المحاسبي.",
    ],
    "sales-report": [
        "اختر السؤال التجاري من Report وحدد فترة From/To أو تاريخ As of المناسب.",
        "اقرأ المؤشرات والجداول الداعمة ثم افتح السجل المالك عندما تحتاج للتحقيق في قيمة.",
        "صدّر التقرير والنطاق الزمني الحاليين كملف CSV دون تغيير بيانات المصدر.",
    ],
}

def sales_typical_tasks(module: str, slug: str, ar: bool = False) -> list[str]:
    if module != "sales":
        return []
    mapping = SALES_TYPICAL_TASKS_AR if ar else SALES_TYPICAL_TASKS_EN
    return mapping.get(slug, [])

SALES_WIDGET_GUIDANCE_EN = {
    "SalesKpiCards": "Shows confirmed order value, confirmed order count, average order value and quotation conversion for the active period. The first two cards also compare against the equal-length previous period.",
    "SalesPerformanceChart": "Plots confirmed order value for the selected period against the equal-length previous period; the bucket size changes with the selected period.",
    "TopProductsChart": "Ranks products by confirmed order value inside the active dashboard filters.",
    "TopCustomersWidget": "Ranks customers by confirmed order value and shows order count plus average order value.",
    "SalespersonPerformanceWidget": "Shows quotation-owned salesperson performance, including quotation count/conversion, converted order count and confirmed order value. It is hidden when no quotation carries salesperson ownership.",
    "SalesFunnelWidget": "Shows quotation → accepted → order → delivered → invoiced counts/values and stage-to-stage conversion percentages.",
    "QuotationPerformanceWidget": "Summarizes open, awaiting-decision, accepted and rejected/expired quotation segments together with conversion rate and median decision time.",
    "RequiresAttentionWidget": "Lists current Sales bottlenecks that require action. It is intentionally current-state rather than period-bounded, while Customer/Salesperson filters still apply.",
    "RecentSalesActivityWidget": "Shows recent Sales events so the operator can open the source record and understand what changed.",
}
SALES_WIDGET_GUIDANCE_AR = {
    "SalesKpiCards": "تعرض قيمة الطلبات المؤكدة وعددها ومتوسط قيمة الطلب ونسبة تحويل عروض الأسعار للفترة الفعالة، مع مقارنة المؤشرات المناسبة بفترة سابقة مساوية.",
    "SalesPerformanceChart": "يقارن قيمة الطلبات المؤكدة في الفترة المختارة بفترة سابقة مساوية، وتتغير دقة التجميع حسب الفترة.",
    "TopProductsChart": "يرتب المنتجات حسب قيمة طلبات المبيعات المؤكدة ضمن الفلاتر الفعالة.",
    "TopCustomersWidget": "يرتب العملاء حسب قيمة الطلبات المؤكدة ويعرض عدد الطلبات ومتوسط قيمة الطلب.",
    "SalespersonPerformanceWidget": "يعرض أداء موظف المبيعات المملوك من عرض السعر، بما في ذلك عدد العروض والتحويل والطلبات المحولة وقيمتها، ويختفي إذا لم توجد بيانات ملكية مبيعات.",
    "SalesFunnelWidget": "يعرض مراحل عرض السعر ← القبول ← الطلب ← التسليم ← الفوترة مع الأعداد والقيم ونسب الانتقال بين المراحل.",
    "QuotationPerformanceWidget": "يلخص عروض الأسعار المفتوحة والمنتظرة للقرار والمقبولة والمرفوضة/المنتهية مع نسبة التحويل ومتوسط زمن القرار.",
    "RequiresAttentionWidget": "قائمة عمل لاختناقات المبيعات الحالية التي تحتاج إجراء، ولا تتقيد بالفترة الزمنية لكنها تحترم فلتر العميل وموظف المبيعات.",
    "RecentSalesActivityWidget": "يعرض أحداث المبيعات الحديثة لفتح السجل المصدر وفهم ما تغير.",
}

def verified_notes_en(module: str, slug: str) -> list[str]:
    return SALES_VERIFIED_EN.get(slug, []) if module == "sales" else []

def verified_notes_ar(module: str, slug: str) -> list[str]:
    return SALES_VERIFIED_AR.get(slug, []) if module == "sales" else []

SPECIAL_PAGE_GUIDE_EN = {
    "timeline": "Use Timeline to read important events in chronological order. It is for history and context; perform the actual business action from the owning record or module.",
    "variants": "Variants is the SKU-level catalog view. Use it to review the concrete sellable/purchasable variants, identifiers and variant-specific behavior under this product.",
    "vendors": "Vendors shows supplier-specific references and purchasing context for this product. Supplier references can carry supplier item identifiers, purchase UOM/cost/MOQ and other sourcing context.",
    "quantities": "Quantities is a stock lookup view. Read on-hand/reserved/available quantities by warehouse here; change them only through Inventory operations.",
    "movements": "Movements is the audit trail of physical inventory changes for this product. Use the source document and movement direction to understand why quantity changed.",
    "audit": "Audit shows historical changes and actor context. Use it to understand what changed; do not treat it as an editing surface.",
    "history": "History preserves prior activity and decisions for the record so current state can be understood in context.",
}

SPECIAL_PAGE_GUIDE_AR = {
    "timeline": "استخدم الخط الزمني لقراءة الأحداث المهمة بالتسلسل. هذه شاشة تاريخ وسياق، أما الإجراء الفعلي فينفذ من السجل أو الوحدة المالكة.",
    "variants": "يعرض تبويب المتغيرات هوية الكتالوج على مستوى SKU والخيارات القابلة للبيع أو الشراء وسلوك كل متغير.",
    "vendors": "يعرض الموردون مراجع المورد الخاصة بالمنتج وسياق الشراء مثل رمز المورد ووحدة الشراء والتكلفة والحد الأدنى وسياق التوريد.",
    "quantities": "تعرض الكميات الموجود والمحجوز والمتاح حسب المستودع. تغيير الكمية يتم فقط عبر عمليات المخزون.",
    "movements": "تعرض الحركات سجل التدقيق للتغيرات المادية في المخزون ومصدر كل حركة واتجاهها.",
    "audit": "يعرض التدقيق التغيرات التاريخية ومن قام بها لفهم ما حدث، وليس لتعديل السجل.",
    "history": "يحافظ السجل التاريخي على النشاط والقرارات السابقة لفهم الحالة الحالية ضمن سياقها.",
}

FIELD_GROUPS_EN = {
    "identity": "Identity & master data",
    "commercial": "Commercial terms & value",
    "schedule": "Dates & scheduling",
    "inventory": "Product, quantity & inventory context",
    "contact": "Contacts & addresses",
    "workflow": "Workflow, approval & evidence",
    "other": "Additional information",
}
FIELD_GROUPS_AR = {
    "identity": "الهوية والبيانات الرئيسية",
    "commercial": "الشروط والقيم التجارية",
    "schedule": "التواريخ والجدولة",
    "inventory": "المنتج والكمية وسياق المخزون",
    "contact": "التواصل والعناوين",
    "workflow": "مسار العمل والموافقة والأدلة",
    "other": "معلومات إضافية",
}

def field_group(name: str) -> str:
    n = name.lower()
    if any(x in n for x in ("email", "phone", "address", "city", "country", "contact", "latitude", "longitude")):
        return "contact"
    if any(x in n for x in ("price", "cost", "currency", "payment", "tax", "discount", "amount", "subtotal", "total", "tier", "floor")):
        return "commercial"
    if any(x in n for x in ("date", "_at", "start", "end", "due", "expire", "expected", "scheduled", "time")):
        return "schedule"
    if any(x in n for x in ("product", "variant", "warehouse", "quantity", "unit", "uom", "lot", "serial", "stock", "condition", "package")):
        return "inventory"
    if any(x in n for x in ("status", "reason", "note", "approval", "review", "evidence", "file", "document", "assigned", "employee")):
        return "workflow"
    if any(x in n for x in ("name", "code", "number", "customer", "supplier", "brand", "manufacturer", "category", "type")):
        return "identity"
    return "other"

def build_grouped_fields_en(details: dict[str, Any]) -> str:
    fields = details["fields"]
    if not fields:
        return "This screen has no conventional editable form. Work through the actions and read-only information exposed by the page."
    grouped: dict[str, list[dict[str, str]]] = {}
    for item in fields:
        grouped.setdefault(field_group(item["name"]), []).append(item)
    out: list[str] = []
    for key in ("identity", "contact", "commercial", "inventory", "schedule", "workflow", "other"):
        items = grouped.get(key, [])
        if not items:
            continue
        out += [
            f"### {FIELD_GROUPS_EN[key]}",
            "",
            "| Field / area | Control | How to use it |",
            "| --- | --- | --- |",
        ]
        for item in items:
            out.append(f"| {md_escape(humanize(item['name']))} | {item['component']} | {md_escape(component_meaning_en(item['name'], item['component']))} |")
        out.append("")
    return "\n".join(out).rstrip()

def build_grouped_fields_ar(details: dict[str, Any]) -> str:
    fields = details["fields"]
    if not fields:
        return "لا تعرض هذه الشاشة نموذج تعديل تقليديًا. استخدم الإجراءات والمعلومات المقروءة التي توفرها الصفحة."
    grouped: dict[str, list[dict[str, str]]] = {}
    for item in fields:
        grouped.setdefault(field_group(item["name"]), []).append(item)
    out: list[str] = []
    for key in ("identity", "contact", "commercial", "inventory", "schedule", "workflow", "other"):
        items = grouped.get(key, [])
        if not items:
            continue
        out += [
            f"### {FIELD_GROUPS_AR[key]}",
            "",
            "| الحقل / المنطقة | نوع التحكم | طريقة الاستخدام |",
            "| --- | --- | --- |",
        ]
        for item in items:
            out.append(f"| {md_escape(field_label_ar(item['name']))} | {item['component']} | {md_escape(component_meaning_ar(item['name'], item['component']))} |")
        out.append("")
    return "\n".join(out).rstrip()

def manual_block_en(slug: str, kind: str) -> list[str]:
    return RESOURCE_MANUAL_EN.get(slug, {}).get(kind, [])

def manual_block_ar(slug: str, kind: str) -> list[str]:
    return RESOURCE_MANUAL_AR.get(slug, {}).get(kind, [])

WORKFLOW_EFFECT_EN = {
    "sales": "Sales may create or update downstream fulfillment, invoice, payment and accounting work. Stock changes only through Inventory actions.",
    "accounting": "Posted accounting actions affect ledger truth. Historical posted entries should be corrected by reversal, credit or compensating entries instead of destructive edits.",
    "inventory": "Confirmed inventory actions change physical stock truth and keep warehouse, lot, serial and source-document provenance.",
    "vendors": "Purchasing changes supplier commercial commitments and may create inbound or billing follow-up, but it does not post physical stock.",
    "crm": "CRM changes customer relationship context and can feed the sales process without bypassing Sales lifecycle rules.",
    "employees": "Employee activity contributes to field execution and performance while warehouse, pricing and accounting responsibilities remain in their owning modules.",
    "support": "Support coordinates service work and can trigger maintenance or chargeable commercial follow-up, but stock custody and ledger posting remain owned by Inventory and Accounting.",
    "reports": "Reports do not mutate source records. Resolve discrepancies in the owning module.",
    "system": "System changes can affect future transactions, permissions and shared presentation. Posted history should remain stable.",
}
WORKFLOW_EFFECT_AR = {
    "sales": "قد تنشئ المبيعات أعمال تنفيذ وفوترة ودفع ومحاسبة لاحقة، لكن تغيير المخزون يتم فقط عبر إجراءات المخزون.",
    "accounting": "تؤثر الإجراءات المحاسبية المرحلة على الحقيقة المالية، ويجب تصحيح التاريخ المرحّل بالعكس أو الإشعار الدائن أو القيد المقابل بدل التعديل الهدّام.",
    "inventory": "تغير إجراءات المخزون المؤكدة الحقيقة المادية وتحافظ على المستودع والدفعة والرقم التسلسلي ومصدر الحركة.",
    "vendors": "تغير المشتريات الالتزام التجاري مع المورد وقد تنشئ متابعة للاستلام أو الفوترة، لكنها لا ترحّل المخزون فعليًا.",
    "crm": "تغير CRM سياق العلاقة مع العميل ويمكن أن تغذي المبيعات دون تجاوز دورة حياة المبيعات.",
    "employees": "تساهم أنشطة الموظف في التنفيذ الميداني والأداء بينما تبقى مسؤوليات المستودع والتسعير والمحاسبة في وحداتها المالكة.",
    "support": "ينسق الدعم أعمال الخدمة ويمكن أن يولد صيانة أو متابعة تجارية مدفوعة، لكن عهدة المخزون والترحيل المالي يبقيان مسؤولية المخزون والمحاسبة.",
    "reports": "التقارير لا تعدل السجلات المصدرية؛ يتم حل الفروقات في الوحدة المالكة.",
    "system": "قد تؤثر إعدادات النظام على العمليات المستقبلية والصلاحيات والعرض المشترك، بينما يجب أن يبقى التاريخ المرحّل مستقرًا.",
}


STATUS_BY_SLUG = {
    "inventory-operation": ["Draft", "Waiting", "Ready", "In Transit", "Partially Received", "Done", "Canceled"],
    "inventory-count": ["Draft", "Counting", "Pending Review", "Confirmed", "Cancelled"],
    "return": ["Draft", "Ready", "Posted", "Cancelled"],
    "inventory-correction": ["Draft", "Posted", "Cancelled"],
    "purchase-order": ["Draft", "Pending Approval", "Accepted", "Rejected", "Partially Received", "Received", "Closed", "Cancelled"],
    "purchase-inbound": ["Awaiting Allocation", "Awaiting Receipt", "Partially Received", "Received", "Cancelled"],
    "quotation": ["Draft", "Sent", "Accepted", "Rejected", "Expired", "Converted to Order", "Cancelled", "Changes Requested"],
    "order": ["Draft", "Confirmed", "Released", "Closed", "Cancelled"],
    "invoice": ["Draft", "Issued", "Sent", "Written Off", "Cancelled"],
    "credit-note": ["Draft", "Confirmed", "Reversed", "Cancelled"],
    "payment": ["Draft", "Posted", "Reversed"],
    "payment-transaction": ["Pending", "Requires Action", "Succeeded", "Failed", "Cancelled", "Partially Refunded", "Refunded"],
    "refund": ["Draft", "Approved", "Paid", "Cancelled"],
    "journal-entry": ["Draft", "Posted"],
    "bill": ["Draft", "Approved", "Partially Paid", "Paid", "Cancelled"],
    "expense": ["Draft", "Approved", "Paid", "Cancelled"],
    "supplier-payment": ["Draft", "Paid", "Cancelled"],
    "shipment": ["Planned", "In Transit", "Arrived", "Cancelled"],
    "customer-quotation-request": ["Submitted", "Under Review", "Quoted", "Rejected", "Cancelled"],
    "customer-return-request": ["Submitted", "Under Review", "Approved", "Rejected", "Converted", "Cancelled"],
    "lead": ["New", "Contacted", "Qualified", "Converted", "Disqualified"],
    "sales-opportunity": ["Qualification", "Needs Analysis", "Demo", "Proposal", "Negotiation", "Closed Won", "Closed Lost"],
    "campaign": ["Draft", "Scheduled", "Sending", "Completed", "Failed", "Cancelled"],
    "monthly-plan": ["Draft", "Active", "Paused", "Completed", "Archived"],
    "task": ["Pending", "In Progress", "Completed", "Cancelled"],
    "visit": ["Scheduled", "En Route", "In Progress", "Completed", "Unable to Complete", "Cancelled"],
    "salary-calculation": ["Draft", "Pending Confirmation", "Confirmed", "Superseded"],
    "ticket": ["Pending", "Pending Payment", "Live", "Assigned", "In Progress", "Waiting Customer", "Resolved", "Closed", "Cancelled"],
    "support-entitlement": ["Active", "Suspended", "Expired", "Cancelled"],
    "service-appointment": ["Planned", "Dispatched", "En Route", "On Site", "Completed", "Cancelled"],
    "knowledge-article": ["Draft", "Published", "Archived"],
    "maintenance-request": ["Open", "Diagnosing", "Awaiting Approval", "Ready For Repair", "In Progress", "Quality Assurance", "Closed", "Cancelled"],
    "warranty-entitlement": ["Pending Activation", "Active", "Ended", "Cancelled"],
}

def status_tone(status: str) -> str:
    s = status.lower()
    if any(word in s for word in ("approved", "accepted", "completed", "paid", "posted", "done", "received", "arrived", "resolved", "closed", "active", "succeeded", "published", "converted")):
        return "success"
    if any(word in s for word in ("pending", "waiting", "awaiting", "partial", "en route", "in transit", "under review", "ready")):
        return "warning"
    if any(word in s for word in ("rejected", "failed", "cancel", "unable", "expired", "overdue", "written off")):
        return "danger"
    if any(word in s for word in ("sent", "released", "assigned", "in progress", "live", "dispatched", "on site", "processing", "counting")):
        return "primary"
    return "neutral"

WORKFLOW_LINKS = {
    "sales": ["quote-to-cash", "customer-return-to-refund", "sales-shortage-procurement"],
    "accounting": ["accounts-receivable", "accounts-payable", "period-close", "customer-return-to-refund"],
    "inventory": ["tracked-inventory", "warehouse-replenishment", "inventory-transfer", "physical-count", "inventory-correction", "customer-return-to-refund"],
    "vendors": ["procure-to-pay", "sales-shortage-procurement", "warehouse-replenishment"],
    "crm": ["lead-to-sale", "customer-pricing", "quote-to-cash"],
    "employees": ["employee-field-sales", "employee-performance"],
    "support": ["support-case", "maintenance", "warranty", "field-service", "equipment-lifecycle"],
    "reports": [],
    "system": [],
}

def slugify(value: str) -> str:
    value = value.replace("\\", "-")
    value = re.sub(r"(?<!^)(?=[A-Z])", "-", value).lower()
    value = re.sub(r"[^a-z0-9]+", "-", value).strip("-")
    return value

def class_slug(class_name: str) -> str:
    base = class_name.split("\\")[-1]
    for suffix in ("Resource", "Page"):
        if base.endswith(suffix):
            base = base[:-len(suffix)]
    if base.lower().endswith("dashboard"):
        return "dashboard"
    return slugify(base)

def humanize(value: str) -> str:
    value = value.replace(".", " ").replace("_", " ").replace("-", " ")
    value = re.sub(r"(?<=[a-z0-9])(?=[A-Z])", " ", value)
    words = value.split()
    if words and words[-1].lower() in {"id", "ids"}:
        words = words[:-1]
    return " ".join(word.upper() if word.lower() in {"sku", "sla", "uom", "api", "pdf"} else word.capitalize() for word in words)


EXACT_AR_LABELS = {
    "quotation_number": "رقم عرض السعر",
    "order_number": "رقم طلب المبيعات",
    "invoice_number": "رقم الفاتورة",
    "purchase_order_number": "رقم أمر الشراء",
    "request_number": "رقم الطلب",
    "customer_company_name": "اسم شركة العميل",
    "customer_customer_code": "رمز العميل",
    "company_name": "اسم الشركة",
    "customer_code": "رمز العميل",
    "supplier_code": "رمز المورد",
    "active_pricing_tier": "شريحة التسعير الفعالة",
    "pricing_tier": "شريحة التسعير",
    "use_tier_price": "استخدام سعر الشريحة",
    "payment_term": "شروط الدفع",
    "unit_price": "سعر الوحدة",
    "unit_cost": "تكلفة الوحدة",
    "tax_amount": "مبلغ الضريبة",
    "tax_total": "إجمالي الضريبة",
    "grand_total": "الإجمالي النهائي",
    "line_total": "إجمالي البند",
    "subtotal": "المجموع الفرعي",
    "price_floor_override": "تجاوز حد السعر",
    "price_floor_override_reason": "سبب تجاوز حد السعر",
    "floor_override_status": "حالة تجاوز حد السعر",
    "floor_price_minor": "حد السعر",
    "list_price_minor": "سعر القائمة",
    "resolved_price_source": "مصدر السعر المعتمد",
    "resolved_price_tier_name": "شريحة السعر المعتمدة",
    "product_variant": "متغير المنتج",
    "product_variant_image": "صورة متغير المنتج",
    "product_variant_main_image": "الصورة الرئيسية لمتغير المنتج",
    "product_variant_sku": "SKU متغير المنتج",
    "issue_date": "تاريخ الإصدار",
    "expires_at": "تاريخ انتهاء الصلاحية",
    "expected_at": "التاريخ المتوقع",
    "due_date": "تاريخ الاستحقاق",
    "ordered_at": "تاريخ الطلب",
    "workflow_summary": "ملخص مسار العمل",
    "reservation_coverage": "تغطية الحجز",
    "decision_status": "حالة القرار",
    "decided_at": "تاريخ القرار",
    "decided_by_name": "صاحب القرار",
    "decision_note": "ملاحظة القرار",
    "response_summary": "ملخص الرد",
    "responses": "الردود",
    "recorded_by_name": "تم التسجيل بواسطة",
    "note": "ملاحظة",
    "customer_quotation_request_request_number": "رقم طلب عرض سعر العميل",
    "assigned_to": "المسؤول",
    "assigned_to_name": "اسم المسؤول",
    "assigned_sales_employee": "موظف المبيعات المسؤول",
    "approval_status": "حالة الموافقة",
    "review_status": "حالة المراجعة",
    "reviewed_at": "تاريخ المراجعة",
    "reviewed_by_name": "المراجع",
    "approval_note": "ملاحظة الموافقة",
    "created_at": "تاريخ الإنشاء",
    "updated_at": "تاريخ آخر تعديل",
    "reference_number": "الرقم المرجعي",
    "external_reference": "المرجع الخارجي",
    "source_document": "المستند المصدر",
    "source_type": "نوع المصدر",
    "source_number": "رقم المصدر",
    "warehouse_name": "اسم المستودع",
    "available_quantity": "الكمية المتاحة",
    "reserved_quantity": "الكمية المحجوزة",
    "on_hand_quantity": "الكمية الموجودة",
    "damaged_quantity": "الكمية المتضررة",
    "quantity_ordered": "الكمية المطلوبة",
    "quantity_received": "الكمية المستلمة",
    "quantity_remaining": "الكمية المتبقية",
    "lot_number": "رقم الدفعة",
    "serial_number": "الرقم التسلسلي",
    "expiry_date": "تاريخ انتهاء الصلاحية",
    "lead_time_days": "مدة التوريد بالأيام",
    "minimum_order_quantity": "الحد الأدنى لكمية الطلب",
    "contact_name": "اسم جهة الاتصال",
    "contact_email": "بريد جهة الاتصال",
    "contact_phone": "هاتف جهة الاتصال",
}

COMMON_AR = {
    "dashboard": "لوحة المتابعة", "customer": "العميل", "supplier": "المورد", "status": "الحالة",
    "currency": "العملة", "date": "التاريخ", "notes": "الملاحظات", "description": "الوصف",
    "quantity": "الكمية", "price": "السعر", "amount": "المبلغ", "total": "الإجمالي",
    "warehouse": "المستودع", "product": "المنتج", "variant": "المتغير", "unit": "الوحدة",
    "name": "الاسم", "email": "البريد الإلكتروني", "phone": "الهاتف", "address": "العنوان",
    "code": "الرمز", "number": "الرقم", "type": "النوع", "reason": "السبب",
    "start": "البدء", "end": "النهاية", "payment": "الدفع", "tax": "الضريبة",
    "route": "توجيه", "ticket": "التذكرة", "view": "عرض", "audit": "التدقيق", "trail": "السجل",
    "raise": "إنشاء", "maintenance": "الصيانة", "request": "الطلب", "assign": "إسناد",
    "settle": "تسوية", "unassign": "إلغاء الإسناد", "archive": "أرشفة", "restore": "استعادة",
    "send": "إرسال", "record": "تسجيل", "decision": "القرار", "convert": "تحويل",
    "requote": "إعادة عرض السعر", "generate": "إنشاء", "pdf": "PDF", "create": "إنشاء",
    "edit": "تعديل", "approve": "موافقة", "confirm": "تأكيد", "release": "تحرير",
    "post": "ترحيل", "complete": "إكمال", "resolve": "حل", "close": "إغلاق",
    "cancel": "إلغاء", "delete": "حذف", "invoice": "الفاتورة", "order": "الطلب",
    "purchase": "شراء", "sales": "مبيعات", "employee": "الموظف", "priority": "الأولوية",
    "impact": "التأثير", "title": "العنوان", "attachments": "المرفقات", "line": "البند",
    "lines": "البنود", "issue": "الإصدار", "expires": "ينتهي", "term": "الشرط",
}

def field_label_ar(value: str) -> str:
    special = {
        "receivable-write-off": "شطب الذمم المدينة",
        "receivable_write_off": "شطب الذمم المدينة",
        "custom-fields": "الحقول المخصصة",
        "custom_fields": "الحقول المخصصة",
        "custom-field-definition": "تعريف الحقول المخصصة",
        "custom_field_definition": "تعريف الحقول المخصصة",
    }
    raw = value.lower()
    if raw in special:
        return special[raw]

    normalized = re.sub(r"(?<=[a-z0-9])(?=[A-Z])", "_", value)
    normalized = re.sub(r"[_\-. ]+", "_", normalized).strip("_").lower()
    if normalized.endswith("_id"):
        normalized = normalized[:-3]
    elif normalized.endswith("_ids"):
        normalized = normalized[:-4]

    if normalized in EXACT_AR_LABELS:
        return EXACT_AR_LABELS[normalized]

    words = [w for w in normalized.split("_") if w]
    translated = [COMMON_AR.get(w, humanize(w)) for w in words]
    return " ".join(translated)

def localized_entry_label(entry: dict[str, Any], locale: str) -> str:
    slug = class_slug(entry["class"])
    if locale == "ar":
        label = entry.get("label_ar")
        if isinstance(label, str) and re.search(r"[\u0600-\u06FF]", label):
            return label
        return field_label_ar(slug)
    return entry.get("label_en") or humanize(slug)

def source_path_for_class(class_name: str) -> Path | None:
    if not class_name.startswith("App\\"):
        return None
    return REPO / ("app/" + class_name[len("App\\"):].replace("\\", "/") + ".php")

def collect_php_text(class_name: str) -> tuple[str, list[Path]]:
    source = source_path_for_class(class_name)
    if source is None or not source.exists():
        return "", []
    files = [source]
    if source.name.endswith("Resource.php"):
        files = sorted(source.parent.rglob("*.php"))
    text = "\n".join(p.read_text(encoding="utf-8", errors="ignore") for p in files)
    return text, files

def source_fingerprint(class_name: str) -> tuple[str, list[str]]:
    _, files = collect_php_text(class_name)
    digest = hashlib.sha256()
    relative_files: list[str] = []
    for file in sorted(files):
        relative = str(file.relative_to(REPO)).replace("\\", "/")
        relative_files.append(relative)
        digest.update(relative.encode("utf-8"))
        digest.update(b"\0")
        digest.update(file.read_bytes())
        digest.update(b"\0")
    return digest.hexdigest(), relative_files

def unique(seq: list[Any]) -> list[Any]:
    out = []
    seen = set()
    for item in seq:
        key = json.dumps(item, sort_keys=True) if isinstance(item, dict) else item
        if key in seen:
            continue
        seen.add(key)
        out.append(item)
    return out

def extract_component_values(text: str, components: set[str]) -> list[dict[str, str]]:
    names = "|".join(sorted(map(re.escape, components), key=len, reverse=True))
    pattern = re.compile(rf"\b({names})::make\(\s*['\"]([^'\"]+)['\"]")
    return unique([{"component": m.group(1), "name": m.group(2)} for m in pattern.finditer(text)])

def extract_resource_details(class_name: str) -> dict[str, Any]:
    text, files = collect_php_text(class_name)
    source = source_path_for_class(class_name)
    main = source.read_text(encoding="utf-8", errors="ignore") if source and source.exists() else ""

    def joined(predicate) -> str:
        selected = [p for p in files if predicate(p)]
        return "\n".join(p.read_text(encoding="utf-8", errors="ignore") for p in selected)

    form_text = main + "\n" + joined(lambda p: "Schemas" in p.parts and "Infolist" not in p.stem)
    entry_text = main + "\n" + joined(lambda p: "Schemas" in p.parts and "Infolist" in p.stem)
    table_text = main + "\n" + joined(lambda p: "Tables" in p.parts)
    action_text = main + "\n" + joined(lambda p: any(part in {"Actions", "Pages", "Tables", "Support"} for part in p.parts))

    fields = extract_component_values(form_text, FORM_COMPONENTS)
    entries = extract_component_values(entry_text, ENTRY_COMPONENTS)
    columns = extract_component_values(table_text, COLUMN_COMPONENTS)
    filters = extract_component_values(table_text, FILTER_COMPONENTS)
    actions = extract_component_values(action_text, ACTION_COMPONENTS)

    relation_pattern = re.compile(r"\b([A-Za-z0-9_]+RelationManager)::class")
    relations = unique(relation_pattern.findall(main))

    section_pattern = re.compile(r"\b(?:Section|Fieldset|Tabs\\Tab)::make\(\s*(?:__\()?['\"]([^'\"]+)['\"]")
    sections = unique(section_pattern.findall(form_text))

    searchable: list[str] = []
    if main:
        match = re.search(r"getGloballySearchableAttributes\(\).*?return\s*\[(.*?)\];", main, re.S)
        if match:
            searchable = unique(re.findall(r"['\"]([^'\"]+)['\"]", match.group(1)))

    widgets: list[str] = []
    if main:
        match = re.search(r"getDashboardWidgets\(\).*?return\s*\[(.*?)\];", main, re.S)
        if match:
            widgets = unique(re.findall(r"\b([A-Za-z][A-Za-z0-9_]+)::class", match.group(1)))

    return {
        "source_files": [str(p.relative_to(REPO)).replace("\\", "/") for p in files],
        "fields": fields[:60],
        "entries": entries[:40],
        "columns": columns[:40],
        "filters": filters[:30],
        "actions": actions[:40],
        "relations": relations[:30],
        "sections": sections[:30],
        "searchable": searchable[:20],
        "widgets": widgets[:30],
    }

def page_title_en(page_key: str) -> str:
    known = {
        "index": "List page",
        "create": "Create page",
        "view": "Record details",
        "edit": "Edit page",
        "timeline": "Timeline",
    }
    return known.get(page_key, humanize(page_key))

def page_title_ar(page_key: str) -> str:
    known = {
        "index": "صفحة القائمة",
        "create": "صفحة الإنشاء",
        "view": "تفاصيل السجل",
        "edit": "صفحة التعديل",
        "timeline": "الخط الزمني",
    }
    return known.get(page_key, field_label_ar(page_key))

def page_description_en(page_key: str, label: str) -> str:
    known = {
        "index": f"The main {label} list. Use it to search, filter, sort, open records and run the actions available to your role.",
        "create": f"The {label} creation form. Complete the required business information and review dependent fields before saving or submitting.",
        "view": f"The {label} detail screen. It brings together record facts, status, linked work, history and context-sensitive workflow actions.",
        "edit": f"The {label} editing screen. Editable fields depend on the record status, permissions and whether the transaction has already crossed a posting or approval boundary.",
        "timeline": f"The chronological {label} timeline, used to understand important customer or record events in sequence.",
    }
    if page_key in known:
        return known[page_key]
    if page_key in SPECIAL_PAGE_GUIDE_EN:
        return SPECIAL_PAGE_GUIDE_EN[page_key]
    return f"The {page_title_en(page_key).lower()} view for {label}. Use it for the specialized work represented by this page, then continue in the owning module for any downstream state change."

def page_description_ar(page_key: str, label: str) -> str:
    known = {
        "index": f"القائمة الرئيسية لـ {label}. استخدمها للبحث والفلترة والترتيب وفتح السجلات وتنفيذ الإجراءات المتاحة حسب صلاحيتك.",
        "create": f"نموذج إنشاء {label}. أكمل المعلومات المطلوبة وراجع الحقول التابعة قبل الحفظ أو الإرسال.",
        "view": f"شاشة تفاصيل {label}. تجمع معلومات السجل والحالة والأعمال المرتبطة والسجل التاريخي والإجراءات المتاحة حسب السياق.",
        "edit": f"شاشة تعديل {label}. تعتمد الحقول القابلة للتعديل على حالة السجل والصلاحيات وما إذا كانت العملية تجاوزت حد الموافقة أو الترحيل.",
        "timeline": f"الخط الزمني لـ {label} لقراءة الأحداث المهمة بالتسلسل.",
    }
    if page_key in known:
        return known[page_key]
    if page_key in SPECIAL_PAGE_GUIDE_AR:
        return SPECIAL_PAGE_GUIDE_AR[page_key]
    return f"شاشة {page_title_ar(page_key)} الخاصة بـ {label}. استخدمها للعمل المتخصص الذي تمثله الصفحة ثم تابع تغيير الحالة من الوحدة المالكة عند الحاجة."

def purpose_key(module: str, slug: str) -> str:
    if slug != "dashboard":
        return slug
    return {
        "sales": "sales-dashboard",
        "accounting": "accounting-dashboard",
        "inventory": "inventory-dashboard",
        "vendors": "purchasing-dashboard",
        "crm": "crm-dashboard",
        "employees": "employees-dashboard",
        "support": "support-dashboard",
    }.get(module, slug)

def purpose_for(module: str, slug: str, label_en: str) -> str:
    authored = RESOURCE_PURPOSES.get(purpose_key(module, slug), {}).get("en")
    if authored:
        return authored
    return SPECIAL_PURPOSE_EN.get(
        slug,
        f"Use {label_en} to review and manage the records and actions represented by this screen inside the {humanize(module)} area."
    )

def purpose_for_ar(module: str, slug: str, label_ar: str) -> str:
    authored = RESOURCE_PURPOSES.get(purpose_key(module, slug), {}).get("ar")
    if authored:
        return authored
    special = {
        "quotation": "إنشاء عروض أسعار العملاء وإرسالها ومراجعتها وتحويل المقبول منها إلى طلب مبيعات دون تغيير المخزون مباشرة.",
        "order": "إدارة طلب العميل المؤكد وجاهزية المخزون والتوريد وحد التحرير إلى التنفيذ.",
        "purchase-order": "إدارة التزام الشراء مع المورد من الإنشاء والموافقة والإرسال حتى التأكيد والاستلام والفوترة.",
        "stock-level": "مراجعة الكميات الفعلية والمحجوزة والمتضررة والمتاحة حسب المنتج والمستودع دون تعديل الكمية مباشرة.",
        "customer": "إدارة الملف الرئيسي للعميل ومراجعة تاريخه التجاري والخدمي والمعداتي والمالي.",
        "visit": "متابعة الزيارة الميدانية من الجدولة والحركة وحتى النتيجة والأدلة والمتابعة.",
        "ticket": "إدارة طلب دعم العميل من الاستقبال والفرز حتى الإسناد والعمل والحل والإغلاق.",
    }
    return special.get(slug, f"استخدم {label_ar} لمراجعة وإدارة السجلات والإجراءات التي تمثلها هذه الشاشة ضمن الوحدة.")

FIELD_MEANINGS_EN = {
    "customer": "Selects the customer that owns the commercial, service or financial record. Customer defaults can influence currency, price list, payment terms and assigned sales context.",
    "supplier": "Selects the supplier responsible for this purchasing record. The supplier can constrain currency, commercial references, purchase UOM, MOQ and lead-time behavior.",
    "currency": "Sets the transaction currency from the shared active currency catalog. Historical records retain their stored currency even if it is later deactivated.",
    "currency_code": "Sets the transaction currency from the shared active currency catalog. Historical records retain their stored currency even if it is later deactivated.",
    "payment_term": "Applies the agreed payment term that controls expected due-date behavior for billing or supplier liability.",
    "payment_term_id": "Applies the agreed payment term that controls expected due-date behavior for billing or supplier liability.",
    "warehouse": "Identifies the warehouse whose physical stock, receipt, delivery, transfer or replenishment context this record belongs to.",
    "warehouse_id": "Identifies the warehouse whose physical stock, receipt, delivery, transfer or replenishment context this record belongs to.",
    "product": "Selects the commercial product. Variant, tracking, UOM and pricing choices can depend on this selection.",
    "product_id": "Selects the commercial product. Variant, tracking, UOM and pricing choices can depend on this selection.",
    "product_variant": "Selects the concrete SKU/variant used for pricing, stock tracking and downstream fulfillment.",
    "product_variant_id": "Selects the concrete SKU/variant used for pricing, stock tracking and downstream fulfillment.",
    "unit": "Sets the unit of measure used by this line. IERP preserves conversion rules between commercial/purchase units and normalized inventory quantities.",
    "unit_id": "Sets the unit of measure used by this line. IERP preserves conversion rules between commercial/purchase units and normalized inventory quantities.",
    "quantity": "Records the requested quantity for this line. Downstream availability, totals, reservations or replenishment can depend on it.",
    "quantity_ordered": "Records the quantity committed on the purchase line before supplier confirmation and physical receipt.",
    "unit_cost": "Records the supplier-side unit cost used to calculate the purchase-line commercial value before accounting consequences.",
    "unit_price": "Records the selling unit price resolved or entered for the line before discounts, tax and totals are applied.",
    "ordered_at": "Records the commercial order date used for purchasing history and follow-up.",
    "expected_at": "Records the expected supplier delivery date used by purchasing, inbound planning and overdue follow-up.",
    "due_date": "Records when the financial amount becomes due according to the document and payment terms.",
    "status": "Shows or sets the lifecycle state only where direct status input is explicitly allowed. Normal lifecycle movement should use dedicated actions.",
    "notes": "Stores operator context that should remain visible with the record; do not use notes as a substitute for required structured fields.",
    "reason": "Records the business reason for an exception, correction, cancellation or override so reviewers can understand and audit why it occurred.",
    "lines": "Contains the transaction line items. Each row carries its own product, quantity, UOM and commercial or tracking context.",
    "active_pricing_tier": "Shows the customer pricing tier currently resolved for this transaction. It is context for price calculation rather than a free-form price field.",
    "employee": "Selects the employee/salesperson responsible for the commercial or field-work context of this record.",
    "assigned_sales_employee": "Selects the salesperson responsible for this customer relationship and its normal commercial follow-up.",
    "use_tier_price": "When enabled, the line uses the eligible resolved tier price rather than treating the entered price as an unrelated manual value.",
    "tax_amount": "Shows or captures the tax amount calculated for the line/document under the active tax rules. Review the tax definition rather than overriding totals casually.",
    "price_floor_override": "System context linking an approved below-floor pricing exception to the transaction. It is not edited as ordinary commercial data.",
    "price_floor_override_reason": "Explains why a below-floor price is being requested or used so the exception remains auditable.",
    "description": "Stores the business description shown with the record or line. Use it for meaningful transaction context, not to replace structured fields.",
    "company_name": "Stores the customer's legal/commercial company name used throughout CRM, Sales, Support and reporting.",
    "customer_code": "Stores the stable customer identifier used for search, references and downstream documents.",
    "default_currency": "Sets the customer's preferred transaction currency for new commercial work when the workflow allows a customer default.",
    "default_payment_term": "Sets the customer's preferred payment term used to prefill eligible new quotations/orders/invoices.",
    "billing_address": "Stores the customer's billing address used by commercial and invoice documents where an address snapshot is required.",
    "tax_registration_number": "Stores the customer's tax registration identifier used on tax-sensitive commercial documents and reports.",
    "allow_direct_orders": "Controls whether this approved customer may use the direct-order policy path instead of always starting from quotation.",
    "delivery_addresses": "Maintains structured reusable delivery destinations for the customer. Each address can carry its own contact and default flag.",
    "label": "Provides a human-friendly name for an address or reusable entry so users can distinguish multiple destinations quickly.",
    "is_default": "Marks this entry as the preferred default when the workflow needs one automatic choice.",
    "accountant_name": "Stores the customer's accounting contact name for billing and receivable follow-up.",
    "accountant_phone": "Stores the customer's accounting contact phone for billing and receivable follow-up.",
    "accountant_email": "Stores the customer's accounting contact email for billing and receivable follow-up.",
    "contact_is_self": "Indicates that the primary contact is the customer/account holder rather than a separate contact person.",
    "contact_email": "Stores the operational contact email used for customer communication where it differs from the account email.",
    "contact_name": "Stores the named operational contact for this address, customer, supplier or service context.",
    "contact_phone": "Stores the operational contact phone used for this address or service context.",
    "category": "Places the product in the configured catalog category used for organization, search, filtering and reporting.",
    "manufacturer": "Associates the product with its manufacturer master data for catalog identity and sourcing/reporting context.",
    "brand": "Associates the product with the configured brand used for catalog identity, filtering and reporting.",
    "operational_profile": "Selects the product behavior profile that controls supported operational assumptions such as tracking/handling capabilities.",
    "default_unit": "Sets the product's default unit of measure used when a more specific commercial or purchasing UOM is not selected.",
    "product_type": "Classifies how the catalog item behaves in the ERP and which downstream operations are appropriate for it.",
    "images": "Maintains the product images used to identify the catalog item in dashboard and downstream interfaces.",
    "product_variant_image": "Shows the selected variant image so the operator can visually verify the exact SKU before entering quantity or price.",
    "issue_date": "Records the commercial issue date from which the quotation/invoice document is considered issued for its lifecycle.",
    "expires_at": "Sets when the commercial offer expires if the customer has not accepted it before that time.",
    "approval_status": "Shows the current review/approval result. Use dedicated approval actions rather than manually forcing this value.",
    "review_note": "Captures the reviewer's explanation when approval, rejection or requested changes require an auditable comment.",
    "priority": "Sets the operational priority used to order and route work; it does not replace SLA or entitlement rules.",
    "assigned_to": "Selects the user/agent responsible for the current work item when assignment is supported.",
}

FIELD_MEANINGS_AR = {
    "customer": "يحدد العميل المالك للسجل التجاري أو الخدمي أو المالي، وقد تؤثر إعداداته الافتراضية على العملة وقائمة الأسعار وشروط الدفع وموظف المبيعات.",
    "supplier": "يحدد المورد المسؤول عن عملية الشراء، وقد يقيّد العملة والمراجع التجارية ووحدة الشراء والحد الأدنى ومدة التوريد.",
    "currency": "يحدد عملة العملية من دليل العملات الفعالة المشترك، بينما تحتفظ السجلات التاريخية بعملتها المخزنة حتى لو عُطلت لاحقًا.",
    "currency_code": "يحدد عملة العملية من دليل العملات الفعالة المشترك، بينما تحتفظ السجلات التاريخية بعملتها المخزنة حتى لو عُطلت لاحقًا.",
    "payment_term": "يطبق شرط الدفع المتفق عليه الذي يتحكم بسلوك تاريخ الاستحقاق للفوترة أو ذمة المورد.",
    "payment_term_id": "يطبق شرط الدفع المتفق عليه الذي يتحكم بسلوك تاريخ الاستحقاق للفوترة أو ذمة المورد.",
    "warehouse": "يحدد المستودع المرتبط بالمخزون الفعلي أو الاستلام أو التسليم أو النقل أو إعادة التزويد.",
    "warehouse_id": "يحدد المستودع المرتبط بالمخزون الفعلي أو الاستلام أو التسليم أو النقل أو إعادة التزويد.",
    "product": "يحدد المنتج التجاري، وقد تعتمد عليه خيارات المتغير والتتبع ووحدة القياس والتسعير.",
    "product_id": "يحدد المنتج التجاري، وقد تعتمد عليه خيارات المتغير والتتبع ووحدة القياس والتسعير.",
    "product_variant": "يحدد SKU أو المتغير الفعلي المستخدم في التسعير وتتبع المخزون والتنفيذ اللاحق.",
    "product_variant_id": "يحدد SKU أو المتغير الفعلي المستخدم في التسعير وتتبع المخزون والتنفيذ اللاحق.",
    "unit": "يحدد وحدة القياس المستخدمة في البند مع الحفاظ على قواعد التحويل بين الوحدة التجارية أو وحدة الشراء والكمية المخزنية المعيارية.",
    "unit_id": "يحدد وحدة القياس المستخدمة في البند مع الحفاظ على قواعد التحويل بين الوحدة التجارية أو وحدة الشراء والكمية المخزنية المعيارية.",
    "quantity": "يسجل الكمية المطلوبة في البند وقد تؤثر على التوفر والإجماليات والحجز أو إعادة التزويد.",
    "quantity_ordered": "يسجل الكمية الملتزم بها في بند الشراء قبل تأكيد المورد والاستلام الفعلي.",
    "unit_cost": "يسجل تكلفة الوحدة لدى المورد لحساب القيمة التجارية لبند الشراء قبل الأثر المحاسبي.",
    "unit_price": "يسجل سعر بيع الوحدة قبل تطبيق الخصومات والضرائب والإجماليات.",
    "ordered_at": "يسجل تاريخ أمر الشراء المستخدم في السجل التجاري والمتابعة.",
    "expected_at": "يسجل تاريخ التوريد المتوقع المستخدم في التخطيط للوارد ومتابعة التأخير.",
    "due_date": "يسجل تاريخ استحقاق القيمة المالية وفق المستند وشروط الدفع.",
    "status": "يعرض أو يحدد حالة دورة الحياة فقط عندما يسمح التنفيذ بإدخال الحالة مباشرة؛ الانتقالات الطبيعية تتم عبر الإجراءات المخصصة.",
    "notes": "يحفظ سياق المستخدم المرتبط بالسجل ولا يجب استخدامه بدل الحقول المنظمة المطلوبة.",
    "reason": "يسجل السبب التجاري المطلوب لجعل الاستثناء أو التصحيح أو الإلغاء أو التجاوز قابلًا للتدقيق.",
    "lines": "يحتوي بنود العملية، ولكل صف منتج وكمية ووحدة قياس وسياق تجاري أو تتبعي مستقل.",
    "active_pricing_tier": "يعرض شريحة التسعير التي حلّها النظام حاليًا لهذه العملية، وهي سياق لحساب السعر وليست حقل سعر يدوي حر.",
    "employee": "يحدد الموظف أو مندوب المبيعات المسؤول عن السياق التجاري أو الميداني لهذا السجل.",
    "assigned_sales_employee": "يحدد مندوب المبيعات المسؤول عادة عن علاقة العميل والمتابعة التجارية.",
    "use_tier_price": "عند تفعيله يستخدم البند سعر الشريحة المؤهل الذي حلّه النظام بدل التعامل مع السعر كقيمة يدوية منفصلة.",
    "tax_amount": "يعرض أو يسجل مبلغ الضريبة للبند أو المستند وفق قواعد الضريبة الفعالة؛ راجع تعريف الضريبة بدل تجاوز الإجماليات يدويًا.",
    "price_floor_override": "سياق نظامي يربط استثناء سعر أقل من الحد تمت الموافقة عليه بالعملية ولا يعدّل كبيان تجاري عادي.",
    "price_floor_override_reason": "يوضح سبب طلب أو استخدام سعر أقل من الحد حتى يبقى الاستثناء قابلًا للتدقيق.",
    "description": "يحفظ الوصف التجاري الظاهر مع السجل أو البند ويستخدم للسياق المفيد وليس بدل الحقول المنظمة.",
    "company_name": "يسجل الاسم القانوني أو التجاري للعميل المستخدم عبر CRM والمبيعات والدعم والتقارير.",
    "customer_code": "يسجل معرف العميل الثابت المستخدم في البحث والمراجع والمستندات اللاحقة.",
    "default_currency": "يحدد العملة المفضلة للعميل للعمليات التجارية الجديدة عندما يسمح المسار باستخدام قيمة افتراضية.",
    "default_payment_term": "يحدد شرط الدفع المفضل للعميل لملء عروض الأسعار أو الطلبات أو الفواتير المؤهلة تلقائيًا.",
    "billing_address": "يسجل عنوان الفوترة المستخدم في المستندات التجارية والفواتير عند الحاجة إلى لقطة عنوان.",
    "tax_registration_number": "يسجل رقم التسجيل الضريبي للعميل المستخدم في المستندات والتقارير المرتبطة بالضريبة.",
    "allow_direct_orders": "يتحكم بالسماح للعميل المعتمد باستخدام مسار الطلب المباشر بدل البدء دائمًا بعرض سعر.",
    "delivery_addresses": "يدير عناوين تسليم منظمة وقابلة لإعادة الاستخدام، ويمكن لكل عنوان أن يحمل جهة اتصال وعلامة افتراضية.",
    "label": "يعطي اسمًا واضحًا للعنوان أو الإدخال القابل لإعادة الاستخدام لتمييز الوجهات المتعددة بسرعة.",
    "is_default": "يحدد هذا الإدخال كخيار افتراضي مفضل عندما يحتاج المسار إلى اختيار تلقائي.",
    "accountant_name": "يسجل اسم جهة الاتصال المحاسبية لدى العميل لمتابعة الفوترة والذمم.",
    "accountant_phone": "يسجل هاتف جهة الاتصال المحاسبية لدى العميل لمتابعة الفوترة والذمم.",
    "accountant_email": "يسجل بريد جهة الاتصال المحاسبية لدى العميل لمتابعة الفوترة والذمم.",
    "contact_is_self": "يحدد أن جهة الاتصال الرئيسية هي صاحب الحساب نفسه وليست شخصًا منفصلًا.",
    "contact_email": "يسجل البريد التشغيلي المستخدم للتواصل مع العميل عندما يختلف عن بريد الحساب.",
    "contact_name": "يسجل اسم جهة الاتصال التشغيلية للعنوان أو العميل أو المورد أو سياق الخدمة.",
    "contact_phone": "يسجل هاتف جهة الاتصال التشغيلية للعنوان أو سياق الخدمة.",
    "category": "يضع المنتج ضمن تصنيف الكتالوج المستخدم للتنظيم والبحث والفلترة والتقارير.",
    "manufacturer": "يربط المنتج ببيانات المصنع الرئيسية لهوية الكتالوج وسياق التوريد والتقارير.",
    "brand": "يربط المنتج بالعلامة التجارية المعرفة في الكتالوج للاستخدام في الهوية والفلترة والتقارير.",
    "operational_profile": "يحدد ملف سلوك المنتج الذي يضبط الافتراضات التشغيلية المدعومة مثل التتبع والمناولة.",
    "default_unit": "يحدد وحدة القياس الافتراضية للمنتج عندما لا يتم اختيار وحدة تجارية أو شرائية أكثر تحديدًا.",
    "product_type": "يصنف سلوك عنصر الكتالوج في ERP ويحدد العمليات اللاحقة المناسبة له.",
    "images": "يدير صور المنتج المستخدمة للتعرف على عنصر الكتالوج في لوحة التحكم والواجهات اللاحقة.",
    "product_variant_image": "يعرض صورة المتغير المختار حتى يتحقق المستخدم بصريًا من SKU الصحيح قبل إدخال الكمية أو السعر.",
    "issue_date": "يسجل تاريخ إصدار المستند التجاري الذي تبدأ منه دورة حياته.",
    "expires_at": "يحدد وقت انتهاء صلاحية العرض إذا لم يقبله العميل قبل هذا الوقت.",
    "approval_status": "يعرض نتيجة المراجعة أو الموافقة الحالية؛ استخدم إجراءات الموافقة المخصصة بدل فرض القيمة يدويًا.",
    "review_note": "يسجل شرح المراجع عندما تحتاج الموافقة أو الرفض أو طلب التعديلات إلى ملاحظة قابلة للتدقيق.",
    "priority": "يحدد الأولوية التشغيلية المستخدمة لترتيب وتوجيه العمل ولا يستبدل قواعد SLA أو الاستحقاق.",
    "assigned_to": "يحدد المستخدم أو الوكيل المسؤول عن عنصر العمل الحالي عندما يدعم المسار الإسناد.",
}

def component_meaning_en(name: str, component: str) -> str:
    key = name.lower()
    if key.endswith("_id") and key[:-3] in FIELD_MEANINGS_EN:
        key = key[:-3]
    if key in FIELD_MEANINGS_EN:
        return FIELD_MEANINGS_EN[key]

    friendly = humanize(name)
    lower = key.replace("_", " ")

    if lower in {"name", "name ar", "title"} or lower.endswith(" name"):
        return f"Stores the human-readable {friendly.lower()} shown in lists, selectors and reports."
    if lower == "code" or lower.endswith(" code") or lower.endswith(" number"):
        return f"Stores the stable business identifier used to search, reference and distinguish this record."
    if "email" in lower:
        return f"Stores the email address used for the {friendly.lower()} contact or account context."
    if "phone" in lower:
        return f"Stores the phone number used for the {friendly.lower()} contact or operational follow-up."
    if "address" in lower:
        return f"Stores the structured {friendly.lower()} used by the relevant billing, delivery, supplier or service workflow."
    if lower in {"city", "country"} or lower.endswith(" country"):
        return f"Stores the {friendly.lower()} used to complete the record's geographic/address context."
    if "username" in lower or lower == "login email":
        return f"Stores the account identifier used to sign in or identify the linked user account."
    if "password" in lower:
        return "Sets the account credential during the allowed account-setup/reset flow; it is not displayed back after saving."
    if any(word in lower for word in ("amount", "subtotal", "total", "debit", "credit", "value minor", "salary", "commission")):
        return f"Stores or displays the monetary {friendly.lower()} used by totals, posting or performance calculations according to the owning workflow."
    if "percent" in lower or "weight" in lower or "probability" in lower:
        return f"Stores the {friendly.lower()} used by the related calculation, scoring or allocation rule."
    if "serial" in lower or "lot number" in lower or "iot number" in lower:
        return f"Stores the tracking identifier used to trace the physical item/lot through Inventory, custody and service history."
    if "reference" in lower:
        return f"Stores an external or source reference so this record can be reconciled with the originating business document/system."
    if "sort order" in lower or "precedence" in lower:
        return f"Controls ordering/priority when multiple configured rules or entries can apply."
    if lower == "slug":
        return "Stores the stable URL/key-friendly identifier used by the configured record where a slug is required."
    if lower in {"summary", "body"} or "note" in lower or "description" in lower:
        return f"Stores the explanatory text users need to understand the purpose, outcome or context of this record."
    if "lead time" in lower:
        return "Stores the expected supplier/operational lead time used by planning and expected-date calculations."
    if "due days" in lower or "grace days" in lower or "minutes" in lower:
        return f"Stores the configured duration used to calculate deadlines, targets or grace periods for this workflow."
    if "quantity" in lower or lower in {"old quantity", "new quantity", "difference"}:
        return f"Stores the {friendly.lower()} used to calculate availability, variance or downstream movement for this operation."
    if "progress" in lower or "stage bar" in lower:
        return f"Displays calculated {friendly.lower()} progress from the record's current state rather than accepting a manual value."
    if lower.startswith("reviewed ") or lower.startswith("approval ") or lower.endswith(" display"):
        return f"Displays the current review/approval context for the record and is not intended as a free-form editable value."

    if component in {"Select", "Radio", "ToggleButtons", "CheckboxList"}:
        return f"Selects the {friendly.lower()} from the configured choices that are valid for this record and its current context."
    if component in {"DatePicker", "DateTimePicker", "TimePicker"}:
        return f"Records the {friendly.lower()} used by the workflow for scheduling, validity, due-date logic or historical context."
    if component in {"Toggle", "Checkbox"}:
        return f"Enables or disables the {friendly.lower()} behavior for future processing of this record."
    if component == "Repeater":
        return f"Adds one or more {friendly.lower()} rows. Each row is validated independently because it can affect totals, tracking or downstream work."
    if component == "FileUpload":
        return f"Attaches the {friendly.lower()} document or evidence required by the workflow."
    if component == "Hidden":
        return f"Stores system-managed {friendly.lower()} context that supports the workflow but is not edited directly by the operator."
    if component == "Placeholder":
        return f"Displays the current calculated or contextual {friendly.lower()} value so the user can review it before acting."
    return f"Stores the {friendly.lower()} as structured data on this record so it can be reused by the owning workflow and related reports."

def component_meaning_ar(name: str, component: str) -> str:
    key = name.lower()
    if key.endswith("_id") and key[:-3] in FIELD_MEANINGS_AR:
        key = key[:-3]
    if key in FIELD_MEANINGS_AR:
        return FIELD_MEANINGS_AR[key]

    friendly = field_label_ar(name)
    lower = key.replace("_", " ")

    if lower in {"name", "name ar", "title"} or lower.endswith(" name"):
        return f"يسجل {friendly} المقروء للمستخدم والذي يظهر في القوائم والاختيارات والتقارير."
    if lower == "code" or lower.endswith(" code") or lower.endswith(" number"):
        return f"يسجل المعرف التجاري الثابت المستخدم للبحث والمرجع وتمييز السجل."
    if "email" in lower:
        return f"يسجل البريد الإلكتروني المستخدم في سياق {friendly} أو الحساب المرتبط."
    if "phone" in lower:
        return f"يسجل رقم الهاتف المستخدم في سياق {friendly} أو المتابعة التشغيلية."
    if "address" in lower:
        return f"يسجل {friendly} المنظم المستخدم في مسار الفوترة أو التسليم أو المورد أو الخدمة."
    if lower in {"city", "country"} or lower.endswith(" country"):
        return f"يسجل {friendly} لإكمال السياق الجغرافي أو العنوان للسجل."
    if "username" in lower or lower == "login email":
        return "يسجل معرف الحساب المستخدم لتسجيل الدخول أو التعرف على الحساب المرتبط."
    if "password" in lower:
        return "يضبط بيانات اعتماد الحساب ضمن مسار إنشاء أو إعادة ضبط مسموح ولا يتم عرضها بعد الحفظ."
    if any(word in lower for word in ("amount", "subtotal", "total", "debit", "credit", "value minor", "salary", "commission")):
        return f"يسجل أو يعرض {friendly} المالي المستخدم في الإجماليات أو الترحيل أو حسابات الأداء حسب الوحدة المالكة."
    if "percent" in lower or "weight" in lower or "probability" in lower:
        return f"يسجل {friendly} المستخدم في قاعدة الحساب أو التقييم أو التوزيع المرتبطة."
    if "serial" in lower or "lot number" in lower or "iot number" in lower:
        return f"يسجل معرف التتبع المستخدم لمتابعة العنصر أو الدفعة عبر المخزون والعهدة وسجل الخدمة."
    if "reference" in lower:
        return f"يسجل مرجع المصدر أو المرجع الخارجي لربط السجل بالمستند أو النظام الأصلي."
    if "sort order" in lower or "precedence" in lower:
        return "يتحكم بترتيب أو أولوية التطبيق عندما توجد عدة قواعد أو إدخالات ممكنة."
    if lower == "slug":
        return "يسجل المعرف الثابت المناسب للرابط أو المفتاح عندما يحتاج السجل إلى slug."
    if lower in {"summary", "body"} or "note" in lower or "description" in lower:
        return f"يسجل نص {friendly} الذي يحتاجه المستخدم لفهم الغرض أو النتيجة أو سياق السجل."
    if "lead time" in lower:
        return "يسجل مدة التوريد أو التنفيذ المتوقعة المستخدمة في التخطيط وحساب التاريخ المتوقع."
    if "due days" in lower or "grace days" in lower or "minutes" in lower:
        return f"يسجل المدة الخاصة بـ{friendly} المستخدمة لحساب المهل أو الأهداف أو فترات السماح."
    if "quantity" in lower or lower in {"old quantity", "new quantity", "difference"}:
        return f"يسجل {friendly} المستخدم لحساب التوفر أو الفرق أو الحركة اللاحقة لهذه العملية."
    if "progress" in lower or "stage bar" in lower:
        return f"يعرض تقدم {friendly} المحسوب من الحالة الحالية ولا يقبل قيمة يدوية."
    if lower.startswith("reviewed ") or lower.startswith("approval ") or lower.endswith(" display"):
        return f"يعرض سياق المراجعة أو الموافقة الحالي لـ{friendly} وليس قيمة حرة للتعديل."

    if component in {"Select", "Radio", "ToggleButtons", "CheckboxList"}:
        return f"يختار {friendly} من الخيارات المهيأة والمسموح بها ضمن سياق السجل الحالي."
    if component in {"DatePicker", "DateTimePicker", "TimePicker"}:
        return f"يسجل {friendly} المستخدم في الجدولة أو الصلاحية أو الاستحقاق أو السياق التاريخي."
    if component in {"Toggle", "Checkbox"}:
        return f"يفعّل أو يعطّل سلوك {friendly} للمعالجة المستقبلية لهذا السجل."
    if component == "Repeater":
        return f"يضيف صفًا أو أكثر ضمن {friendly}، ويخضع كل صف للتحقق لأنه قد يؤثر على الإجماليات أو التتبع أو العمل اللاحق."
    if component == "FileUpload":
        return f"يرفق المستند أو الدليل المرتبط بـ{friendly} والمطلوب ضمن العملية."
    if component == "Hidden":
        return f"يحفظ سياق {friendly} الذي يديره النظام لدعم المسار ولا يعدله المستخدم مباشرة."
    if component == "Placeholder":
        return f"يعرض قيمة {friendly} المحسوبة أو السياقية حتى يراجعها المستخدم قبل تنفيذ الإجراء."
    return f"يسجل {friendly} كبيانات منظمة يمكن أن تستخدمها الوحدة المالكة والتقارير المرتبطة."


def widget_meaning_en(name: str) -> str:
    if name in SALES_WIDGET_GUIDANCE_EN:
        return SALES_WIDGET_GUIDANCE_EN[name]
    label = humanize(name)
    lower = name.lower()
    if any(word in lower for word in ("kpi", "statistics", "metrics", "cards")):
        return f"{label} summarizes headline metrics so the operator can understand the module's current position before opening a detailed queue."
    if any(word in lower for word in ("trend", "chart", "performance")):
        return f"{label} visualizes change, performance or distribution across the active dashboard period and filters."
    if any(word in lower for word in ("attention", "queue", "overdue", "expiring", "lowstock", "low_stock")):
        return f"{label} is an operational work queue. Use it to identify records that need action rather than treating it as a read-only KPI."
    if "recent" in lower or "activity" in lower or "movement" in lower:
        return f"{label} shows recent operational activity and gives quick context before opening the source record."
    if "top" in lower or "funnel" in lower:
        return f"{label} provides a ranked or staged breakdown that helps explain where volume or value is concentrated."
    return f"{label} is a dashboard widget that summarizes an important part of the module's current operational state."

def widget_meaning_ar(name: str) -> str:
    if name in SALES_WIDGET_GUIDANCE_AR:
        return SALES_WIDGET_GUIDANCE_AR[name]
    label = field_label_ar(name)
    lower = name.lower()
    if any(word in lower for word in ("kpi", "statistics", "metrics", "cards")):
        return f"يلخص {label} المؤشرات الرئيسية حتى يفهم المستخدم وضع الوحدة قبل فتح قوائم التفاصيل."
    if any(word in lower for word in ("trend", "chart", "performance")):
        return f"يعرض {label} التغير أو الأداء أو التوزيع ضمن الفترة والفلاتر الفعالة في لوحة المتابعة."
    if any(word in lower for word in ("attention", "queue", "overdue", "expiring", "lowstock", "low_stock")):
        return f"يمثل {label} قائمة عمل تشغيلية لتحديد السجلات التي تحتاج إجراء، وليس مجرد مؤشر للقراءة."
    if "recent" in lower or "activity" in lower or "movement" in lower:
        return f"يعرض {label} النشاط التشغيلي الحديث ويوفر سياقًا سريعًا قبل فتح السجل المصدر."
    if "top" in lower or "funnel" in lower:
        return f"يوفر {label} تفصيلًا مرتبًا أو مرحليًا يوضح أين تتركز القيمة أو الحجم."
    return f"يعرض {label} جزءًا مهمًا من الحالة التشغيلية الحالية للوحدة."

def route_hint(module_label: str, section_label: str | None, label: str, page_key: str) -> list[str]:
    items = [module_label]
    if section_label and section_label.lower() not in {"overview", "نظرة عامة"}:
        items.append(section_label)
    items.append(label)
    if page_key not in {"index", "overview"}:
        items.append(page_title_en(page_key))
    return items

def route_hint_ar(module_label: str, section_label: str | None, label: str, page_key: str) -> list[str]:
    items = [module_label]
    if section_label and section_label not in {"Overview", "نظرة عامة"}:
        items.append(section_label)
    items.append(label)
    if page_key not in {"index", "overview"}:
        items.append(page_title_ar(page_key))
    return items

def md_escape(value: str) -> str:
    return value.replace("|", "\\|")

def vue_array(values: list[str]) -> str:
    return json.dumps(values, ensure_ascii=False)

def screen_id(module: str, slug: str, page_key: str) -> str:
    return f"{module}-{slug}-{page_key}"

def build_fields_table_en(details: dict[str, Any]) -> str:
    fields = details["fields"]
    if not fields:
        return "This screen does not expose a conventional editable form in the current implementation. Use the visible actions and read-only information described below."
    lines = ["| Field / area | Control | What it means |", "| --- | --- | --- |"]
    for item in fields:
        lines.append(f"| {md_escape(humanize(item['name']))} | {item['component']} | {md_escape(component_meaning_en(item['name'], item['component']))} |")
    return "\n".join(lines)

def build_fields_table_ar(details: dict[str, Any]) -> str:
    fields = details["fields"]
    if not fields:
        return "لا تعرض هذه الشاشة نموذج تعديل تقليديًا في التنفيذ الحالي. استخدم الإجراءات والمعلومات المقروءة الموضحة أدناه."
    lines = ["| الحقل / المنطقة | نوع التحكم | المعنى العملي |", "| --- | --- | --- |"]
    for item in fields:
        lines.append(f"| {md_escape(field_label_ar(item['name']))} | {item['component']} | {md_escape(component_meaning_ar(item['name'], item['component']))} |")
    return "\n".join(lines)

def build_list_details_en(details: dict[str, Any]) -> str:
    parts = []
    if details["columns"]:
        parts.append("### Main table information\n\nThe list can expose the following record information, depending on permissions and table configuration:\n\n" + "\n".join(f"- **{humanize(x['name'])}**" for x in details["columns"]))
    if details["filters"]:
        parts.append("### Filters\n\nUse filters to narrow the working set without changing records:\n\n" + "\n".join(f"- **{humanize(x['name'])}** ({x['component']})" for x in details["filters"]))
    if details["searchable"]:
        parts.append("### Global search\n\nThis resource participates in dashboard search through:\n\n" + "\n".join(f"- {humanize(x)}" for x in details["searchable"]))
    return "\n\n".join(parts)

def build_list_details_ar(details: dict[str, Any]) -> str:
    parts = []
    if details["columns"]:
        parts.append("### معلومات الجدول الأساسية\n\nقد تعرض القائمة المعلومات التالية حسب الصلاحيات وإعداد الجدول:\n\n" + "\n".join(f"- **{field_label_ar(x['name'])}**" for x in details["columns"]))
    if details["filters"]:
        parts.append("### الفلاتر\n\nاستخدم الفلاتر لتضييق قائمة العمل دون تعديل السجلات:\n\n" + "\n".join(f"- **{field_label_ar(x['name'])}** ({x['component']})" for x in details["filters"]))
    if details["searchable"]:
        parts.append("### البحث العام\n\nيمكن الوصول إلى هذا القسم من البحث العام باستخدام معلومات مثل:\n\n" + "\n".join(f"- {field_label_ar(x)}" for x in details["searchable"]))
    return "\n\n".join(parts)


ACTION_MEANINGS_EN = {
    "send": "moves the record into the sent/customer-or-supplier-facing stage after validating required commercial data",
    "submit": "submits the record into its approval/review workflow; it is no longer treated as an untouched draft",
    "approve": "authorizes the current business decision when the user has the required approval permission",
    "reject": "records a negative approval/decision outcome while preserving the record and reason",
    "confirm": "confirms the business commitment represented by the record and can unlock downstream work",
    "release": "hands confirmed demand to operational execution such as fulfillment or procurement synchronization",
    "post": "crosses the posting boundary and writes the controlled stock or financial effect owned by this module",
    "complete": "finishes the operational step after required quantities/evidence have been validated",
    "convert": "creates the next owning-domain document from the current accepted/approved source while preserving provenance",
    "requote": "creates a new quotation attempt linked to the previous quotation instead of rewriting the prior decision history",
    "reverse": "creates or applies an explicit reversal so posted history remains auditable",
    "cancel": "terminates eligible open work without deleting historical provenance; dependencies can block cancellation",
    "close": "moves eligible completed work into its final closed state after child work has been settled",
    "resolve": "records that the support/service issue has been resolved before final closure where applicable",
    "record decision": "records the external or reviewer decision and its note/evidence without bypassing the lifecycle",
    "generate pdf": "queues generation or regeneration of the printable business document; the action does not by itself imply an immediate browser preview/download",
    "export csv": "exports the current data set for analysis without changing business state",
    "request changes": "returns the record to a correction/review path while preserving the reviewer request",
    "reactivate": "returns an eligible inactive record to active use while preserving its prior history",
    "archive": "removes the record from normal active work without deleting its historical context",
    "restore": "returns an archived or soft-deleted record where the workflow permits restoration",
}

ACTION_MEANINGS_AR = {
    "send": "ينقل السجل إلى مرحلة الإرسال للطرف الخارجي بعد التحقق من البيانات المطلوبة",
    "submit": "يرسل السجل إلى مسار المراجعة أو الموافقة ولا يبقى كمسودة غير مقدمة",
    "approve": "يعتمد القرار التجاري الحالي عند توفر صلاحية الموافقة المطلوبة",
    "reject": "يسجل رفض القرار مع الاحتفاظ بالسجل والسبب والتاريخ",
    "confirm": "يؤكد الالتزام التجاري الذي يمثله السجل وقد يفتح الأعمال التابعة",
    "release": "يسلم الطلب المؤكد إلى التنفيذ التشغيلي مثل التنفيذ أو مزامنة التوريد",
    "post": "يعبر حد الترحيل ويكتب أثر المخزون أو المال المضبوط الذي تملكه الوحدة",
    "complete": "ينهي الخطوة التشغيلية بعد التحقق من الكميات والأدلة المطلوبة",
    "convert": "ينشئ مستند الوحدة التالية من المصدر المقبول أو المعتمد مع الحفاظ على رابط المصدر",
    "requote": "ينشئ محاولة عرض سعر جديدة مرتبطة بالعرض السابق بدل إعادة كتابة القرار التاريخي",
    "reverse": "ينفذ عكسًا صريحًا بحيث يبقى التاريخ المرحّل قابلًا للتدقيق",
    "cancel": "ينهي العمل المفتوح المؤهل دون حذف مصدره التاريخي وقد تمنعه التبعيات",
    "close": "ينقل العمل المكتمل إلى الإغلاق النهائي بعد تسوية الأعمال الفرعية المطلوبة",
    "resolve": "يسجل حل حالة الدعم أو الخدمة قبل الإغلاق النهائي عند وجود هذه المرحلة",
    "record decision": "يسجل قرار العميل أو المراجع وملاحظته دون تجاوز دورة الحياة",
    "generate pdf": "يضع توليد أو إعادة توليد المستند القابل للطباعة في الطابور، ولا يعني الإجراء بحد ذاته فتح معاينة أو تنزيل فوري في المتصفح",
    "export csv": "يصدر مجموعة البيانات الحالية للتحليل دون تغيير حالة العمل",
    "request changes": "يعيد السجل إلى مسار التصحيح أو المراجعة مع حفظ طلب المراجع",
    "reactivate": "يعيد السجل المؤهل إلى الاستخدام الفعال مع الحفاظ على تاريخه",
    "archive": "يخرج السجل من العمل اليومي دون حذف سياقه التاريخي",
    "restore": "يعيد سجلًا مؤرشفًا أو محذوفًا منطقيًا عندما يسمح المسار بذلك",
}

def action_explanation_en(name: str) -> str:
    key = humanize(name).lower()
    if key in ACTION_MEANINGS_EN:
        return ACTION_MEANINGS_EN[key]
    if key.startswith("create "):
        target = key.removeprefix("create ")
        return f"creates the linked {target} through the supported workflow and preserves this record as its source"
    if key.startswith("open "):
        target = key.removeprefix("open ")
        return f"opens the related {target} so you can continue the work in the screen that owns it"
    if key.startswith("view "):
        target = key.removeprefix("view ")
        return f"opens the {target} for review without changing the current record"
    if key.startswith("download "):
        target = key.removeprefix("download ")
        return f"downloads the generated {target} from the current record/data set without changing business state"
    if key.startswith("export"):
        return "exports the current filtered/report data for offline analysis without changing business state"
    if key.startswith("upload"):
        return "uploads the requested file/data into the controlled import or evidence workflow; validation still applies before business state changes"
    if key.startswith("attach "):
        return "attaches the requested evidence to the record so the decision or operational step remains auditable"
    if key.startswith("assign"):
        return "assigns ownership or responsibility to the selected user/team while preserving the assignment history"
    if key.startswith("review") or key == "start review":
        return "opens or starts the formal review step so the record can be checked before approval, posting or continuation"
    if key.startswith("approve"):
        return "authorizes the eligible record/decision when the current reviewer has the required permission"
    if key.startswith("reject"):
        return "records a rejected outcome and keeps the reason/history instead of deleting the request"
    if key in {"activate", "enable"}:
        return "makes the configuration/master record available for future eligible use without rewriting historical transactions"
    if key in {"deactivate", "disable"}:
        return "prevents future normal use while preserving existing historical references"
    if key.startswith("recalculate"):
        return "recomputes the derived result from current source data rather than asking the user to edit calculated values manually"
    if key.startswith("reschedule"):
        return "changes the planned schedule through the dedicated workflow so the previous/current timing remains traceable"
    if key.startswith("expire"):
        return "moves the eligible record into its expired state when its usable period has ended"
    if key.startswith("pay") or "payment" in key:
        return "continues the controlled payment flow and records the financial action only when its prerequisites are satisfied"
    if key.startswith("receive"):
        return "continues the Inventory-owned receiving step for the eligible quantity while preserving source and tracking provenance"
    if key.startswith("release"):
        return "releases eligible work to the next operational stage after validation of readiness and permissions"
    if key.startswith("preview"):
        return "shows the calculated/generated result before committing or downloading it"
    if key.startswith("manage "):
        return "opens the related management surface for the selected scope without duplicating the underlying master records"
    if key.startswith("change stage"):
        return "moves the record through its controlled stage workflow while preserving the transition history"
    if key.startswith("update"):
        return "updates the editable record data while respecting lifecycle and permission rules"
    if key.startswith("retry"):
        return "retries the previously failed transition using the current validated source data"
    if key.startswith("run "):
        return "runs the named controlled process and records its result instead of requiring manual state manipulation"
    if key.startswith("reopen"):
        return "reopens an eligible closed/reviewed item through a traceable workflow rather than erasing the prior completion"
    if key.startswith("copy "):
        return "creates a new planning/configuration copy from the current source while keeping the original unchanged"
    if key in {"calendar", "list", "timeline"}:
        return f"switches to the {key} presentation of the same underlying records without changing business state"
    return "executes this named workflow action after validating the current status, permission and prerequisites"

def action_explanation_ar(name: str) -> str:
    key = humanize(name).lower()
    if key in ACTION_MEANINGS_AR:
        return ACTION_MEANINGS_AR[key]
    if key.startswith("create "):
        return "ينشئ السجل المرتبط عبر المسار المعتمد مع الحفاظ على السجل الحالي كمصدر"
    if key.startswith("open "):
        return "يفتح السجل المرتبط لمتابعة العمل من الشاشة المالكة له"
    if key.startswith("view "):
        return "يفتح العرض أو السجل المرتبط للمراجعة دون تغيير حالة السجل الحالي"
    if key.startswith("download "):
        return "ينزّل الملف أو المستند المولد من البيانات الحالية دون تغيير حالة العمل"
    if key.startswith("export"):
        return "يصدر البيانات الحالية أو المفلترة للتحليل دون تغيير حالة العمل"
    if key.startswith("upload"):
        return "يرفع الملف أو البيانات المطلوبة ضمن مسار استيراد أو إثبات مضبوط مع تطبيق التحقق قبل أي أثر تشغيلي"
    if key.startswith("attach "):
        return "يرفق الدليل المطلوب بالسجل حتى يبقى القرار أو الإجراء قابلًا للتدقيق"
    if key.startswith("assign"):
        return "يسند المسؤولية للمستخدم أو الفريق المختار مع الحفاظ على سجل الإسناد"
    if key.startswith("review") or key == "start review":
        return "يبدأ أو يفتح خطوة المراجعة الرسمية قبل الموافقة أو الترحيل أو الاستمرار"
    if key.startswith("approve"):
        return "يعتمد السجل أو القرار المؤهل عندما يملك المراجع الصلاحية المطلوبة"
    if key.startswith("reject"):
        return "يسجل نتيجة الرفض مع الاحتفاظ بالسبب والتاريخ بدل حذف الطلب"
    if key in {"activate", "enable"}:
        return "يجعل السجل أو الإعداد متاحًا للاستخدام المستقبلي دون إعادة كتابة العمليات التاريخية"
    if key in {"deactivate", "disable"}:
        return "يمنع الاستخدام المستقبلي العادي مع الحفاظ على المراجع التاريخية"
    if key.startswith("recalculate"):
        return "يعيد حساب النتيجة المشتقة من بيانات المصدر الحالية بدل تعديل القيم المحسوبة يدويًا"
    if key.startswith("reschedule"):
        return "يغير الجدولة عبر المسار المخصص مع الحفاظ على سياق التوقيت السابق والحالي"
    if key.startswith("expire"):
        return "ينقل السجل المؤهل إلى حالة منتهية عند انتهاء فترة صلاحيته"
    if key.startswith("pay") or "payment" in key:
        return "يتابع مسار الدفع المضبوط ويسجل الأثر المالي فقط بعد اكتمال المتطلبات"
    if key.startswith("receive"):
        return "يتابع خطوة الاستلام المملوكة للمخزون للكمية المؤهلة مع الحفاظ على المصدر والتتبع"
    if key.startswith("release"):
        return "يحرر العمل المؤهل للمرحلة التشغيلية التالية بعد التحقق من الجاهزية والصلاحيات"
    if key.startswith("preview"):
        return "يعرض النتيجة المحسوبة أو المولدة قبل اعتمادها أو تنزيلها"
    if key.startswith("manage "):
        return "يفتح مساحة إدارة السجلات المرتبطة ضمن النطاق الحالي دون تكرار البيانات الرئيسية"
    if key.startswith("change stage"):
        return "ينقل السجل بين مراحله المضبوطة مع حفظ تاريخ الانتقال"
    if key.startswith("update"):
        return "يحدث البيانات القابلة للتعديل مع احترام الحالة والصلاحيات"
    if key.startswith("retry"):
        return "يعيد محاولة الانتقال السابق باستخدام بيانات المصدر الحالية بعد التحقق"
    if key.startswith("run "):
        return "يشغل العملية المضبوطة ويسجل نتيجتها بدل تغيير الحالة يدويًا"
    if key.startswith("reopen"):
        return "يعيد فتح عنصر مؤهل عبر مسار قابل للتتبع دون مسح تاريخ الإكمال السابق"
    if key.startswith("copy "):
        return "ينشئ نسخة تخطيط أو إعداد جديدة من المصدر مع إبقاء الأصل دون تغيير"
    if key in {"calendar", "list", "timeline"}:
        return "يبدل طريقة عرض السجلات نفسها دون تغيير حالتها التجارية"
    return "ينفذ الإجراء المسمى بعد التحقق من حالة السجل والصلاحيات والمتطلبات السابقة"

def build_typical_tasks_en(pages: list[str], details: dict[str, Any]) -> str:
    tasks: list[str] = []
    if "index" in pages:
        tasks.append("Find an existing record using search, views and filters, then open it from the working list.")
    if "create" in pages:
        tasks.append("Create a new record from the page action and complete the required business sections before saving.")
    if "view" in pages:
        tasks.append("Review the record's current status, source relationships, totals/evidence and related work from the detail page.")
    if "edit" in pages:
        tasks.append("Edit fields only while the lifecycle and your permission still allow direct editing.")
    action_names = unique([humanize(item["name"]) for item in details["actions"]])[:5]
    if action_names:
        tasks.append("Move the record through its workflow using actions such as **" + "**, **".join(action_names) + "** rather than changing status fields manually.")
    if not tasks:
        tasks.append("Use the page as the module's working surface and follow the visible actions/links to the owning record.")
    return "\n".join(f"- {task}" for task in tasks)

def build_typical_tasks_ar(pages: list[str], details: dict[str, Any]) -> str:
    tasks: list[str] = []
    if "index" in pages:
        tasks.append("ابحث عن السجل باستخدام البحث وطرق العرض والفلاتر ثم افتحه من قائمة العمل.")
    if "create" in pages:
        tasks.append("أنشئ سجلًا جديدًا من إجراء الصفحة وأكمل أقسام العمل المطلوبة قبل الحفظ.")
    if "view" in pages:
        tasks.append("راجع الحالة الحالية ومصادر السجل والإجماليات أو الأدلة والعمل المرتبط من شاشة التفاصيل.")
    if "edit" in pages:
        tasks.append("عدّل الحقول فقط عندما تسمح دورة الحياة وصلاحيتك بالتعديل المباشر.")
    action_names = unique([field_label_ar(item["name"]) for item in details["actions"]])[:5]
    if action_names:
        tasks.append("انقل السجل ضمن المسار باستخدام إجراءات مثل **" + "**، **".join(action_names) + "** بدل تغيير الحالة يدويًا.")
    if not tasks:
        tasks.append("استخدم الصفحة كمساحة العمل الخاصة بالوحدة واتبع الإجراءات والروابط إلى السجل المالك.")
    return "\n".join(f"- {task}" for task in tasks)


def build_actions_en(details: dict[str, Any]) -> str:
    actions = details["actions"]
    if not actions:
        return "Available actions are provided by the page and record state. If an expected action is missing, check the status and your permissions."
    lines = ["The implementation currently exposes action definitions including:"]
    for name in unique([item["name"] for item in actions]):
        lines.append(f"- **{humanize(name)}** — {action_explanation_en(name)}.")
    lines.append("\nHigh-impact actions can change stock, money, approvals or lifecycle state. Read the confirmation dialog before continuing.")
    return "\n".join(lines)

def build_actions_ar(details: dict[str, Any]) -> str:
    actions = details["actions"]
    if not actions:
        return "توفر الصفحة الإجراءات حسب حالة السجل وصلاحية المستخدم. إذا لم يظهر إجراء متوقع فتحقق من الحالة والصلاحيات."
    lines = ["يتضمن التنفيذ الحالي تعريفات لإجراءات مثل:"]
    for name in unique([item["name"] for item in actions]):
        lines.append(f"- **{field_label_ar(name)}** — {action_explanation_ar(name)}.")
    lines.append("\nقد تغير الإجراءات الحساسة المخزون أو المال أو الموافقات أو حالة العملية. اقرأ نافذة التأكيد قبل المتابعة.")
    return "\n".join(lines)

def section_label(group: dict[str, Any], section_key: str | None, locale: str) -> str | None:
    for section in group.get("sections", []):
        if section["key"] == section_key:
            return section["label_ar" if locale == "ar" else "label_en"]
    return None

def article_frontmatter(title: str, module: str, class_name: str, pages: list[str], ids: list[str]) -> str:
    safe_title = title.replace('"', '\\"')
    return ("---\n" + f'title: "{safe_title}"\n' + f"module: {module}\n" + f"pages: {json.dumps(pages, ensure_ascii=False)}\n" + f"screenshots: {json.dumps(ids, ensure_ascii=False)}\n" + f"lastVerified: {TODAY}\n" + "---\n")

def screenshot_markup(
    sid: str,
    title: str,
    description: str,
    path_text: str,
    locale: str,
) -> str:
    image_src = VERIFIED_SCREENSHOTS.get(sid, {}).get(locale)
    image_attr = f' imageSrc="{image_src}"' if isinstance(image_src, str) and image_src else ""

    return (
        f'<IerpScreenshot id="{sid}" title="{title}" '
        f'description="{description.replace(chr(34), "&quot;")}" '
        f'path="{path_text}"{image_attr} />'
    )

def page_capture_metadata(entry: dict[str, Any], page_key: str) -> dict[str, Any]:
    capture_context = {}
    if entry.get("class") == "App\\Filament\\Resources\\InventoryOperations\\InventoryOperationResource" and page_key == "create":
        capture_context = {"capture_query": {"operation_type": "internal_transfer"}}
    for page in entry.get("pages", []):
        if page.get("key") == page_key:
            return {
                "route_name": page.get("route_name"),
                "route_uri": page.get("route_uri"),
                "route_parameters": page.get("route_parameters", []),
                "page_class": page.get("page_class"),
                "model": entry.get("model"),
                **capture_context,
            }

    return {
        "route_name": entry.get("route_name"),
        "route_uri": entry.get("route_uri"),
        "route_parameters": entry.get("route_parameters", []),
        "page_class": entry.get("class"),
        "model": entry.get("model"),
    }

def article_en(group: dict[str, Any], entry: dict[str, Any]) -> tuple[str, list[dict[str, Any]]]:
    module = group["key"]
    class_name = entry["class"]
    slug = class_slug(class_name)
    label = entry.get("label_en") or humanize(slug)
    pages = [p["key"] for p in entry.get("pages", [])] or [entry.get("page") or "overview"]
    pages = unique(pages)
    section = section_label(group, entry.get("section"), "en")
    details = extract_resource_details(class_name)
    ids = [screen_id(module, slug, p) for p in pages]
    shots = []
    for page_key in pages:
        capture = page_capture_metadata(entry, page_key)
        shots.append({
            "id": screen_id(module, slug, page_key),
            "module": module,
            "resource": class_name,
            "page": page_key,
            "article": f"/screens/{module}/{slug}",
            **capture,
            "title_en": f"{label} — {page_title_en(page_key)}",
            "title_ar": f"{localized_entry_label(entry, 'ar')} — {page_title_ar(page_key)}",
            "description_en": page_description_en(page_key, label),
            "description_ar": page_description_ar(page_key, localized_entry_label(entry, "ar")),
            "locales": ["en", "ar"],
            "capture_status": "planned",
            "theme": "light",
            "viewport": "1440x1100",
        })

    nav = [group["label_en"]]
    if section and section != "Overview":
        nav.append(section)
    nav.append(label)
    purpose = purpose_for(module, slug, label)

    out = [article_frontmatter(label, module, class_name, pages, ids)]
    out += [
        f"# {label}",
        "",
        f"<IerpNavigationPath :items='{vue_array(nav)}' />",
        "",
        f'<IerpPageSummary purpose="{purpose.replace(chr(34), "&quot;")}" useWhen="You need to work with {label.lower()} records or follow their lifecycle." owner="{group["label_en"]}" />',
        "",
        "## Overview",
        "",
        purpose,
        "",
        MODULE_INTRO_EN[module],
        "",
        "## How to open it",
        "",
        f"Use the dashboard module switcher and follow **{' → '.join(nav)}**. The exact visible options depend on your role.",
        "",
        "## Screens in this area",
        "",
        "| Screen | What you use it for |",
        "| --- | --- |",
    ]
    for page_key in pages:
        out.append(f"| {page_title_en(page_key)} | {md_escape(page_description_en(page_key, label))} |")
    out.append("")

    when_notes = manual_block_en(slug, "when")
    out += [
        "## When to use this page",
        "",
        *(f"- {note}" for note in when_notes),
    ] if when_notes else [
        "## When to use this page",
        "",
        f"Use **{label}** when your work belongs to this {group['label_en']} responsibility. The page keeps the record in the module that owns the business fact instead of duplicating it in another area.",
    ]
    task_overrides = sales_typical_tasks(module, slug)
    out += [
        "",
        "## Typical tasks",
        "",
        (
            "\n".join(f"- {task}" for task in task_overrides)
            if task_overrides
            else build_typical_tasks_en(pages, details)
        ),
        "",
    ]

    verified = verified_notes_en(module, slug)
    if verified:
        out += [
            "## Verified operating behavior",
            "",
            *[f"- {note}" for note in verified],
            "",
        ]

    for page_key in pages:
        sid = screen_id(module, slug, page_key)
        ptitle = page_title_en(page_key)
        pdesc = page_description_en(page_key, label)
        path_text = " → ".join(nav + ([] if page_key in {"index", "overview"} else [ptitle]))
        out += [
            f"## {ptitle}",
            "",
            screenshot_markup(sid, f"{label} — {ptitle}", pdesc, path_text, "en"),
            "",
            pdesc,
            "",
        ]

        page_notes = page_operator_notes(module, slug, page_key)
        if page_notes:
            out += [page_notes["markdown"].strip(), ""]
            if page_notes.get("replace_auto") is True:
                continue

        special_page = SPECIAL_PAGE_GUIDE_EN.get(page_key)
        if special_page:
            out += [
                '<div class="docs-section-intro">',
                f"<strong>How to use this view</strong><span>{special_page}</span>",
                "</div>",
                "",
            ]
        if page_key == "index":
            out += [
                "The screen is a working queue: search and filters narrow records, saved or preset views focus common tasks, and row actions open the record without changing its lifecycle by themselves.",
                "",
            ]
            extra = build_list_details_en(details)
            if extra:
                out += [extra, ""]
        elif page_key in {"create", "edit"}:
            if page_key == "create":
                before_notes = manual_block_en(slug, "before")
                out += [
                    "### Before you create the record",
                    "",
                    *(f"- {note}" for note in before_notes),
                    "",
                ] if before_notes else [
                    "### Before you create the record",
                    "",
                    "- Confirm the master records referenced by the form are active and correct.",
                    "- Review the source document or prior workflow step when this record is created from another process.",
                    "- Confirm your role has permission for the later submit/approve/post actions you expect to use.",
                    "",
                ]
            out += [
                "### Form fields and sections",
                "",
                build_grouped_fields_en(details),
                "",
            ]
            if page_key == "create":
                out += [
                    "### Before saving",
                    "",
                    "Verify required identities, dates, currency/UOM values, line-level quantities and any evidence requested by the workflow. Dependent selectors can change after choosing a customer, supplier, product or warehouse.",
                    "",
                ]
            else:
                out += [
                    "### Editing rules",
                    "",
                    "Editing is not a replacement for a workflow transition. Once a transaction is approved, posted, sent or otherwise locked, IERP can hide or reject edits and require a dedicated correction or reversal path.",
                    "",
                ]
        elif details["entries"] and not special_page:
            out += [
                "### Information shown on the detail screen",
                "",
                "The detail/infolist implementation can expose:",
                "",
                *[f"- **{humanize(x['name'])}**" for x in details["entries"]],
                "",
            ]

    after_notes = manual_block_en(slug, "after")
    if after_notes:
        out += [
            "## What happens after this step",
            "",
            *(f"- {note}" for note in after_notes),
            "",
        ]

    if details["widgets"]:
        out += [
            "## Dashboard widgets and queues",
            "",
            "The dashboard is not only a summary page. Its widgets combine headline metrics, trends and operational queues:",
            "",
            *[f"- **{humanize(widget)}** — {widget_meaning_en(widget)}" for widget in details["widgets"]],
            "",
            "Dashboard filters apply to the widgets that are designed to use them. Open the linked source record when you need to change business state.",
            "",
        ]

    if (
        details["fields"]
        and not any(page in {"create", "edit"} for page in pages)
        and (module, slug) not in {("sales", "dashboard"), ("sales", "sales-report")}
    ):
        out += [
            "## Fields and form behavior",
            "",
            build_grouped_fields_en(details),
            "",
            "Required, visible and editable behavior can be conditional. A field may appear only after another selection or only while the record remains in an editable state.",
            "",
        ]

    read_only_surface = (module, slug) in {("sales", "dashboard"), ("sales", "sales-report")}

    if read_only_surface:
        if slug == "sales-report":
            out += [
                "## Actions",
                "",
                "- **Export current report** — downloads the selected report and current date scope as CSV. It does not change source records.",
                "",
            ]
        else:
            out += [
                "## Actions",
                "",
                "Dashboard controls filter or navigate to source records. They do not change commercial, stock or accounting state.",
                "",
            ]
    else:
        out += [
            "## Actions and lifecycle controls",
            "",
            build_actions_en(details),
            "",
        ]

    statuses = STATUS_BY_SLUG.get(slug, [])
    if statuses:
        out += [
            "## Statuses used by this record",
            "",
            "The current status vocabulary is:",
            "",
            " ".join(f'<IerpStatus tone="{status_tone(status)}">{status}</IerpStatus>' for status in statuses),
            "",
            "Use the action buttons exposed by the record to move between statuses. The presence of a status does not mean every user may perform the transition.",
            "",
        ]

    if details["relations"]:
        out += [
            "## Related tabs and linked records",
            "",
            "The record can include related information or work areas such as:",
            "",
            *[f"- {humanize(r.replace('RelationManager', ''))}" for r in details["relations"]],
            "",
            "Related tabs provide context, not duplicate master records. Open the owning module when you need to perform a dedicated downstream workflow.",
            "",
        ]
    if read_only_surface:
        out += [
            "## Business effect",
            "",
            (
                "Sales Reports read source facts and export analysis only; correct discrepancies in the owning source workflow."
                if slug == "sales-report"
                else "The Sales Dashboard reads and links to source facts only. Filtering or opening a widget does not change business state."
            ),
            "",
            "## When something is missing",
            "",
            "- Your role may not have permission to view the underlying metric/report or source record.",
            "- The selected filters or date scope may contain no matching data.",
            "- Some dashboard widgets intentionally hide when there is no meaningful source data to show.",
            "",
            "## Related workflows",
            "",
        ]
    else:
        out += [
            "## Business effect",
            "",
            WORKFLOW_EFFECT_EN[module],
            "",
            "## Common reasons an action is unavailable",
            "",
            "- Your role does not have the required permission.",
            "- The record is not in the status required by that action.",
            "- A prerequisite approval, source document or configuration is missing.",
            "- A posted or confirmed transaction is intentionally immutable and needs a correction or reversal workflow.",
            "- A dependent module still has unfinished work, such as receiving, payment, maintenance or fulfillment.",
            "",
            "## Related workflows",
            "",
        ]
    links = WORKFLOW_LINKS.get(module, [])
    if links:
        for wf in links:
            out.append(f"- [{humanize(wf)}](/workflows/{wf})")
    else:
        out.append("- [Core business workflows](/workflows/)")
    return "\n".join(out).rstrip() + "\n", shots

def article_ar(group: dict[str, Any], entry: dict[str, Any]) -> str:
    module = group["key"]
    class_name = entry["class"]
    slug = class_slug(class_name)
    label = localized_entry_label(entry, "ar")
    pages = [p["key"] for p in entry.get("pages", [])] or [entry.get("page") or "overview"]
    pages = unique(pages)
    section = section_label(group, entry.get("section"), "ar")
    details = extract_resource_details(class_name)
    ids = [screen_id(module, slug, p) for p in pages]
    nav = [group["label_ar"]]
    if section and section != "نظرة عامة":
        nav.append(section)
    nav.append(label)
    purpose = purpose_for_ar(module, slug, label)

    out = [article_frontmatter(label, module, class_name, pages, ids)]
    out += [
        f"# {label}",
        "",
        f"<IerpNavigationPath :items='{vue_array(nav)}' />",
        "",
        f'<IerpPageSummary purpose="{purpose.replace(chr(34), "&quot;")}" useWhen="عندما تحتاج للعمل على سجلات {label} أو متابعة دورة حياتها." owner="{group["label_ar"]}" />',
        "",
        "## نظرة عامة",
        "",
        purpose,
        "",
        MODULE_INTRO_AR[module],
        "",
        "## طريقة الوصول",
        "",
        f"استخدم مبدّل الوحدات واتبع المسار **{' ← '.join(nav)}**. تختلف الخيارات الظاهرة حسب صلاحيات المستخدم.",
        "",
        "## الشاشات ضمن هذا القسم",
        "",
        "| الشاشة | استخدامها |",
        "| --- | --- |",
    ]
    for page_key in pages:
        out.append(f"| {page_title_ar(page_key)} | {md_escape(page_description_ar(page_key, label))} |")
    out.append("")

    when_notes = manual_block_ar(slug, "when")
    out += [
        "## متى تستخدم هذه الصفحة؟",
        "",
        *(f"- {note}" for note in when_notes),
    ] if when_notes else [
        "## متى تستخدم هذه الصفحة؟",
        "",
        f"استخدم **{label}** عندما يكون العمل تابعًا لمسؤولية {group['label_ar']}. تحافظ الصفحة على السجل في الوحدة المالكة للحقيقة التجارية بدل تكراره في وحدة أخرى.",
    ]
    out += [
        "",
        "## المهام الشائعة",
        "",
        build_typical_tasks_ar(pages, details),
        "",
    ]

    verified = verified_notes_ar(module, slug)
    if verified:
        out += [
            "## السلوك التشغيلي المتحقق منه",
            "",
            *[f"- {note}" for note in verified],
            "",
        ]

    for page_key in pages:
        sid = screen_id(module, slug, page_key)
        ptitle = page_title_ar(page_key)
        pdesc = page_description_ar(page_key, label)
        path_text = " ← ".join(nav + ([] if page_key in {"index", "overview"} else [ptitle]))
        out += [
            f"## {ptitle}",
            "",
            screenshot_markup(sid, f"{label} — {ptitle}", pdesc, path_text, "ar"),
            "",
            pdesc,
            "",
        ]

        page_notes = page_operator_notes(module, slug, page_key, ar=True)
        if page_notes:
            out += [page_notes["markdown"].strip(), ""]
            if page_notes.get("replace_auto") is True:
                continue

        special_page = SPECIAL_PAGE_GUIDE_AR.get(page_key)
        if special_page:
            out += [
                '<div class="docs-section-intro">',
                f"<strong>طريقة استخدام هذه الشاشة</strong><span>{special_page}</span>",
                "</div>",
                "",
            ]
        if page_key == "index":
            out += [
                "هذه الشاشة قائمة عمل: استخدم البحث والفلاتر وطرق العرض لتحديد السجلات المطلوبة، ثم افتح السجل أو نفذ الإجراء المتاح حسب حالته.",
                "",
            ]
            extra = build_list_details_ar(details)
            if extra:
                out += [extra, ""]
        elif page_key in {"create", "edit"}:
            if page_key == "create":
                before_notes = manual_block_ar(slug, "before")
                out += [
                    "### قبل إنشاء السجل",
                    "",
                    *(f"- {note}" for note in before_notes),
                    "",
                ] if before_notes else [
                    "### قبل إنشاء السجل",
                    "",
                    "- تأكد من أن السجلات الرئيسية المشار إليها في النموذج فعالة وصحيحة.",
                    "- راجع المستند المصدر أو الخطوة السابقة إذا كان هذا السجل ناتجًا عن عملية أخرى.",
                    "- تأكد من امتلاك صلاحية إجراءات الإرسال أو الموافقة أو الترحيل التي ستحتاجها لاحقًا.",
                    "",
                ]
            out += [
                "### الحقول وأقسام النموذج",
                "",
                build_grouped_fields_ar(details),
                "",
            ]
            if page_key == "create":
                out += [
                    "### قبل الحفظ",
                    "",
                    "راجع الهوية والتواريخ والعملة ووحدة القياس والكميات والمرفقات المطلوبة. قد تتغير بعض الحقول بعد اختيار العميل أو المورد أو المنتج أو المستودع.",
                    "",
                ]
            else:
                out += [
                    "### قواعد التعديل",
                    "",
                    "التعديل لا يستبدل انتقال الحالة. بعد الموافقة أو الترحيل أو الإرسال قد يمنع النظام التعديل ويطلب مسار تصحيح أو عكس مخصص.",
                    "",
                ]
        elif details["entries"] and not special_page:
            out += [
                "### المعلومات الظاهرة في شاشة التفاصيل",
                "",
                "قد تعرض شاشة التفاصيل معلومات مثل:",
                "",
                *[f"- **{field_label_ar(x['name'])}**" for x in details["entries"]],
                "",
            ]

    after_notes = manual_block_ar(slug, "after")
    if after_notes:
        out += [
            "## ماذا يحدث بعد هذه الخطوة؟",
            "",
            *(f"- {note}" for note in after_notes),
            "",
        ]

    if details["widgets"]:
        out += [
            "## مؤشرات وقوائم لوحة المتابعة",
            "",
            "لوحة المتابعة ليست صفحة أرقام فقط؛ تجمع مؤشرات رئيسية واتجاهات وقوائم عمل تشغيلية:",
            "",
            *[f"- **{field_label_ar(widget)}** — {widget_meaning_ar(widget)}" for widget in details["widgets"]],
            "",
            "تؤثر فلاتر لوحة المتابعة على المؤشرات المصممة لاستخدامها. افتح السجل المصدر عندما تحتاج لتغيير حالة العمل.",
            "",
        ]

    if (
        details["fields"]
        and not any(page in {"create", "edit"} for page in pages)
        and (module, slug) not in {("sales", "dashboard"), ("sales", "sales-report")}
    ):
        out += [
            "## الحقول وسلوك النموذج",
            "",
            build_grouped_fields_ar(details),
            "",
            "قد تكون الحقول مطلوبة أو ظاهرة أو قابلة للتعديل بشكل شرطي حسب حالة السجل والاختيارات السابقة.",
            "",
        ]

    read_only_surface = (module, slug) in {("sales", "dashboard"), ("sales", "sales-report")}

    if read_only_surface:
        if slug == "sales-report":
            out += [
                "## الإجراءات",
                "",
                "- **Export current report** — ينزل التقرير المحدد ونطاق التاريخ الحالي كملف CSV ولا يغير سجلات المصدر.",
                "",
            ]
        else:
            out += [
                "## الإجراءات",
                "",
                "تقوم عناصر لوحة المتابعة بالفلترة أو الانتقال إلى السجلات المصدرية ولا تغير الحالة التجارية أو المخزون أو المحاسبة.",
                "",
            ]
    else:
        out += [
            "## الإجراءات والتحكم بدورة الحياة",
            "",
            build_actions_ar(details),
            "",
        ]

    statuses = STATUS_BY_SLUG.get(slug, [])
    if statuses:
        out += [
            "## حالات السجل",
            "",
            "مفردات الحالة الحالية لهذا السجل هي:",
            "",
            " ".join(f'<IerpStatus tone="{status_tone(status)}">{status}</IerpStatus>' for status in statuses),
            "",
            "استخدم الإجراءات التي تعرضها الشاشة للانتقال بين الحالات. وجود الحالة لا يعني أن كل مستخدم يملك صلاحية تنفيذ الانتقال.",
            "",
        ]

    if details["relations"]:
        out += [
            "## التبويبات والسجلات المرتبطة",
            "",
            "قد يحتوي السجل على معلومات أو مناطق عمل مرتبطة مثل:",
            "",
            *[f"- {field_label_ar(r.replace('RelationManager', ''))}" for r in details["relations"]],
            "",
            "تعرض هذه التبويبات السياق المرتبط ولا تنشئ مسارًا موازيًا للوحدة المالكة للعملية.",
            "",
        ]

    if read_only_surface:
        out += [
            "## الأثر على العمل",
            "",
            (
                "تقرأ تقارير المبيعات حقائق المصدر وتصدر التحليل فقط؛ صحح الفروقات من مسار العمل المالك للبيانات."
                if slug == "sales-report"
                else "تقرأ لوحة المبيعات حقائق المصدر وتربط بها فقط، ولا تغير الفلاتر أو فتح المؤشر حالة أي سجل."
            ),
            "",
            "## عندما لا تظهر بيانات متوقعة",
            "",
            "- قد لا يملك دور المستخدم صلاحية رؤية المؤشر أو التقرير أو السجل المصدر.",
            "- قد لا تحتوي الفلاتر أو الفترة المحددة على بيانات مطابقة.",
            "- تخفي بعض مؤشرات اللوحة نفسها عمدًا عندما لا توجد بيانات مصدر ذات معنى.",
            "",
            "## مسارات العمل المرتبطة",
            "",
        ]
    else:
        out += [
            "## الأثر على العمل",
            "",
            WORKFLOW_EFFECT_AR[module],
            "",
            "## أسباب شائعة لعدم توفر إجراء",
            "",
            "- لا يملك المستخدم الصلاحية المطلوبة.",
            "- حالة السجل الحالية لا تسمح بالإجراء.",
            "- توجد موافقة أو مستند مصدر أو إعداد مطلوب غير مكتمل.",
            "- السجل المرحّل أو المؤكد أصبح ثابتًا ويحتاج مسار تصحيح أو عكس.",
            "- توجد أعمال غير مكتملة في وحدة مرتبطة مثل الاستلام أو الدفع أو الصيانة أو التنفيذ.",
            "",
            "## مسارات العمل المرتبطة",
            "",
        ]
    links = WORKFLOW_LINKS.get(module, [])
    if links:
        for wf in links:
            title = next(workflow["ar"] for workflow in WORKFLOWS if workflow["slug"] == wf)
            out.append(f"- [{title}](/ar/workflows/{wf})")
    else:
        out.append("- [مسارات العمل الأساسية](/ar/workflows/)")

    return "\n".join(out).rstrip() + "\n"

WORKFLOWS = [
    {
        "slug": "quote-to-cash",
        "en": "Quote to Cash",
        "ar": "من عرض السعر إلى التحصيل",
        "summary_en": "Complete a customer sale from the first commercial offer through confirmed demand, warehouse execution, invoicing, collection and accounting.",
        "summary_ar": "تنفيذ دورة بيع العميل من العرض التجاري الأول حتى الطلب المؤكد والتنفيذ المخزني والفوترة والتحصيل والمحاسبة.",
        "steps_en": ["Customer", "Quotation", "Sales Order", "Fulfillment", "Delivery / Shipment", "Invoice", "Payment", "Accounting"],
        "steps_ar": ["العميل", "عرض السعر", "طلب المبيعات", "التنفيذ", "التسليم / الشحنة", "الفاتورة", "الدفعة", "المحاسبة"],
        "modules": ["crm", "sales", "inventory", "accounting"],
        "rules_en": [
            "A quotation does not change stock.",
            "An accepted quotation converts to a Sales Order; it does not jump directly to delivery.",
            "Inventory owns reservation, lot/serial allocation and physical posting.",
            "Payment posting and accounting settlement are separate from the invoice document status.",
        ],
        "rules_ar": [
            "عرض السعر لا يغير المخزون.",
            "عرض السعر المقبول يتحول إلى طلب مبيعات ولا ينتقل مباشرة إلى التسليم.",
            "المخزون مسؤول عن الحجز وتخصيص الدفعات والأرقام التسلسلية والترحيل المادي.",
            "ترحيل الدفعة والتسوية المحاسبية منفصلان عن حالة مستند الفاتورة.",
        ],
    },
    {
        "slug": "customer-return-to-refund",
        "en": "Customer Return to Refund",
        "ar": "من مرتجع العميل إلى الاسترداد",
        "summary_en": "Handle the physical return and its financial consequence without mixing Inventory and Accounting responsibilities.",
        "summary_ar": "معالجة المرتجع المادي وأثره المالي مع الحفاظ على فصل مسؤوليات المخزون والمحاسبة.",
        "steps_en": ["Return Request", "Customer Return", "Inspection", "Disposition", "Inventory Posting", "Credit Note", "Refund"],
        "steps_ar": ["طلب مرتجع", "مرتجع العميل", "الفحص", "تحديد الحالة", "ترحيل المخزون", "إشعار دائن", "استرداد"],
        "modules": ["crm", "inventory", "sales", "accounting"],
        "rules_en": [
            "Returned quantity must preserve original delivery, lot, serial and movement provenance where tracked.",
            "Inventory decides Saleable, Quarantine, Damaged or Supplier Return disposition.",
            "A Credit Note is a financial correction and cannot credit more than the returned quantity still available for credit.",
            "Refunds do not directly mutate physical stock.",
        ],
        "rules_ar": [
            "يجب الحفاظ على مصدر التسليم والدفعة والرقم التسلسلي والحركة للكمية المرتجعة عند التتبع.",
            "يحدد المخزون حالة صالح للبيع أو حجر أو متضرر أو إرجاع للمورد.",
            "الإشعار الدائن تصحيح مالي ولا يمكن أن يتجاوز الكمية المرتجعة المتاحة للائتمان.",
            "الاسترداد المالي لا يغير المخزون المادي مباشرة.",
        ],
    },
    {
        "slug": "procure-to-pay",
        "en": "Procure to Pay",
        "ar": "من الشراء إلى دفع المورد",
        "summary_en": "Buy from a supplier through sourcing, Purchase Order commitment, supplier confirmation, Inventory receipt, supplier liability and payment.",
        "summary_ar": "الشراء من المورد عبر التوريد وأمر الشراء وتأكيد المورد والاستلام المخزني والذمة والدفع.",
        "steps_en": ["Purchase Need / RFQ", "Agreement", "Purchase Order", "Supplier Confirmation", "Inbound", "Inventory Receipt", "Supplier Bill / AP", "Supplier Payment"],
        "steps_ar": ["حاجة شراء / RFQ", "اتفاقية", "أمر شراء", "تأكيد المورد", "وارد", "استلام مخزني", "فاتورة مورد / ذمم", "دفع المورد"],
        "modules": ["vendors", "inventory", "accounting"],
        "rules_en": [
            "Purchase Orders own supplier commercial data.",
            "Supplier Confirmation records committed quantities and promised dates.",
            "Inventory is the only module that posts physical receipt.",
            "Supplier bills and payments continue through Accounting and Payments rather than a purchasing-only ledger.",
        ],
        "rules_ar": [
            "يمتلك أمر الشراء البيانات التجارية مع المورد.",
            "يسجل تأكيد المورد الكميات الملتزم بها وتواريخ الوعد.",
            "المخزون وحده يرحّل الاستلام المادي.",
            "تستمر فواتير ومدفوعات المورد عبر المحاسبة والمدفوعات بدل إنشاء دفتر مستقل داخل المشتريات.",
        ],
    },
    {
        "slug": "sales-shortage-procurement",
        "en": "Sales Shortage Procurement",
        "ar": "توريد نقص طلب المبيعات",
        "summary_en": "Cover a Sales Order shortage through purchasing and bring received stock back into fulfillment readiness.",
        "summary_ar": "تغطية نقص طلب المبيعات عبر المشتريات ثم إعادة المخزون المستلم إلى جاهزية التنفيذ.",
        "steps_en": ["Sales Order", "Shortage", "Purchase Need", "Purchase Order", "Supplier Confirmation", "Receipt", "Reservation", "Fulfillment"],
        "steps_ar": ["طلب المبيعات", "النقص", "حاجة شراء", "أمر شراء", "تأكيد المورد", "الاستلام", "الحجز", "التنفيذ"],
        "modules": ["sales", "vendors", "inventory"],
        "rules_en": ["Order availability states explain readiness but do not replace Inventory posting.", "Procurement requirements preserve their Sales Order provenance.", "Received stock must become available before fulfillment can consume it."],
        "rules_ar": ["حالات توفر الطلب تشرح الجاهزية لكنها لا تستبدل ترحيل المخزون.", "تحافظ متطلبات التوريد على مصدرها من طلب المبيعات.", "يجب أن يصبح المخزون المستلم متاحًا قبل أن يستهلكه التنفيذ."],
    },
    {
        "slug": "warehouse-replenishment",
        "en": "Warehouse Replenishment",
        "ar": "إعادة تزويد المستودع",
        "summary_en": "Turn min/max replenishment policy and stock alerts into controlled purchase needs and supplier orders.",
        "summary_ar": "تحويل سياسات الحد الأدنى والأقصى وتنبيهات المخزون إلى احتياجات شراء وأوامر مورد مضبوطة.",
        "steps_en": ["Replenishment Policy", "Stock Position", "Alert / Recommendation", "Purchase Need", "Purchase Order", "Receipt"],
        "steps_ar": ["سياسة إعادة التزويد", "وضع المخزون", "تنبيه / توصية", "حاجة شراء", "أمر شراء", "استلام"],
        "modules": ["inventory", "vendors"],
        "rules_en": ["Reservations are not double-subtracted when calculating shortage.", "Preferred supplier, purchase UOM, MOQ and lead time can influence the recommendation.", "Physical receipt remains an Inventory action."],
        "rules_ar": ["لا يتم طرح الحجوزات مرتين عند حساب النقص.", "يمكن أن يؤثر المورد المفضل ووحدة الشراء والحد الأدنى ومدة التوريد على التوصية.", "يبقى الاستلام الفعلي إجراءً في المخزون."],
    },
    {
        "slug": "inventory-transfer",
        "en": "Inventory Transfer",
        "ar": "نقل المخزون",
        "summary_en": "Move stock between warehouse locations while preserving source, destination and tracking identity.",
        "summary_ar": "نقل المخزون بين المواقع مع الحفاظ على المصدر والوجهة وهوية التتبع.",
        "steps_en": ["Transfer", "Source Stock", "Tracked Allocation", "Movement", "Destination Stock"],
        "steps_ar": ["طلب النقل", "مخزون المصدر", "تخصيص التتبع", "الحركة", "مخزون الوجهة"],
        "modules": ["inventory"],
        "rules_en": ["Lot and serial identity must remain consistent.", "Transfers are inventory movements, not purchasing or sales transactions."],
        "rules_ar": ["يجب الحفاظ على هوية الدفعة والرقم التسلسلي.", "النقل حركة مخزون وليس عملية شراء أو بيع."],
    },
    {
        "slug": "physical-count",
        "en": "Physical Count",
        "ar": "الجرد الفعلي",
        "summary_en": "Compare physical stock with system stock and post an approved variance through the controlled count workflow.",
        "summary_ar": "مقارنة المخزون الفعلي مع النظام وترحيل الفرق الموافق عليه عبر مسار الجرد المضبوط.",
        "steps_en": ["Count Scope", "Physical Count", "Variance", "Review", "Approval", "Adjustment"],
        "steps_ar": ["نطاق الجرد", "العد الفعلي", "الفرق", "المراجعة", "الموافقة", "التعديل"],
        "modules": ["inventory"],
        "rules_en": ["Never repair stock by editing totals directly.", "Tracked variance must identify the affected lot or serial where applicable."],
        "rules_ar": ["لا تصلح المخزون بتعديل الإجمالي مباشرة.", "يجب تحديد الدفعة أو الرقم التسلسلي المتأثر عند وجود تتبع."],
    },
    {
        "slug": "inventory-correction",
        "en": "Inventory Correction",
        "ar": "تصحيح حركة المخزون",
        "summary_en": "Correct a previously posted inventory operation with an explicit historical correction instead of rewriting the original movement.",
        "summary_ar": "تصحيح عملية مخزون مرحلة سابقًا بتصحيح تاريخي صريح بدل إعادة كتابة الحركة الأصلية.",
        "steps_en": ["Posted Operation", "Correction Reason", "Correction Lines", "Review", "Compensating Movement"],
        "steps_ar": ["عملية مرحلة", "سبب التصحيح", "بنود التصحيح", "المراجعة", "حركة مقابلة"],
        "modules": ["inventory"],
        "rules_en": ["Posted history remains visible.", "Inventory correction never fabricates a Credit Note or Refund; financial consequences stay in Sales/Accounting."],
        "rules_ar": ["يبقى التاريخ المرحّل ظاهرًا.", "تصحيح المخزون لا ينشئ إشعارًا دائنًا أو استردادًا؛ الأثر المالي يبقى في المبيعات والمحاسبة."],
    },
    {
        "slug": "tracked-inventory",
        "en": "Tracked Inventory Lifecycle",
        "ar": "دورة المخزون المتتبع",
        "summary_en": "Follow lot-, serial- and expiry-controlled inventory from receipt through reservation, delivery and customer custody.",
        "summary_ar": "متابعة المخزون المتتبع بالدفعة أو الرقم التسلسلي أو الصلاحية من الاستلام حتى الحجز والتسليم وعهدة العميل.",
        "steps_en": ["Tracked Product", "Receipt", "Lot / Serial", "Stock", "Reservation", "Picking", "Delivery", "Custody"],
        "steps_ar": ["منتج متتبع", "استلام", "دفعة / تسلسل", "مخزون", "حجز", "التقاط", "تسليم", "عهدة"],
        "modules": ["inventory", "sales", "support"],
        "rules_en": ["Expiry-aware stock can use FEFO.", "Serial identity is unique and custody history must remain traceable.", "Support equipment should reuse known customer-owned serialized equipment when available."],
        "rules_ar": ["يمكن للمخزون ذي الصلاحية استخدام FEFO.", "الرقم التسلسلي فريد ويجب بقاء تاريخ العهدة قابلاً للتتبع.", "يفضل الدعم استخدام المعدات ذات الرقم التسلسلي المعروفة والمملوكة للعميل عند توفرها."],
    },
    {
        "slug": "lead-to-sale",
        "en": "Lead to Sale",
        "ar": "من عميل محتمل إلى عملية بيع",
        "summary_en": "Move potential business from a lead through opportunity and customer context into the formal Sales lifecycle.",
        "summary_ar": "نقل فرصة العمل المحتملة من عميل محتمل وفرصة إلى سياق العميل ثم دورة المبيعات الرسمية.",
        "steps_en": ["Lead", "Qualification", "Opportunity", "Customer", "Quotation", "Sales Order"],
        "steps_ar": ["عميل محتمل", "تأهيل", "فرصة", "عميل", "عرض سعر", "طلب مبيعات"],
        "modules": ["crm", "sales"],
        "rules_en": ["CRM does not bypass quotation/order rules.", "Customer commercial defaults can guide currency, price list, payment terms and assigned salesperson."],
        "rules_ar": ["لا تتجاوز CRM قواعد عروض الأسعار والطلبات.", "يمكن لإعدادات العميل التجارية أن تحدد العملة وقائمة الأسعار وشروط الدفع وموظف المبيعات."],
    },
    {
        "slug": "customer-pricing",
        "en": "Customer Pricing",
        "ar": "تسعير العميل",
        "summary_en": "Understand how customer profile, currency, price lists, tiers, quantity breaks and floor controls influence selling price.",
        "summary_ar": "فهم تأثير ملف العميل والعملة وقوائم الأسعار والشرائح وشرائح الكمية وحدود السعر على سعر البيع.",
        "steps_en": ["Customer Profile", "Currency", "Price List / Tier", "Product / Variant", "Quantity Break", "Resolved Price", "Quotation"],
        "steps_ar": ["ملف العميل", "العملة", "قائمة / شريحة سعر", "منتج / متغير", "شريحة كمية", "السعر المحسوب", "عرض السعر"],
        "modules": ["crm", "sales"],
        "rules_en": ["Pricing resolution should remain deterministic and preserve provenance.", "Inactive catalog values remain valid for historical records but are not offered for new transactions."],
        "rules_ar": ["يجب أن يبقى حساب السعر محددًا ويحافظ على مصدر القرار.", "تبقى القيم غير الفعالة صالحة للسجلات التاريخية لكنها لا تعرض للعمليات الجديدة."],
    },
    {
        "slug": "accounts-receivable",
        "en": "Accounts Receivable",
        "ar": "الذمم المدينة",
        "summary_en": "Follow customer invoiced value through receivable status, payment allocation, settlement, credit and write-off controls.",
        "summary_ar": "متابعة قيمة فواتير العملاء عبر الذمم وتخصيص الدفعات والتسوية والإشعارات الدائنة وضوابط الشطب.",
        "steps_en": ["Issued Invoice", "Accounts Receivable", "Payment", "Allocation", "Settlement / Credit"],
        "steps_ar": ["فاتورة صادرة", "ذمم مدينة", "دفعة", "تخصيص", "تسوية / ائتمان"],
        "modules": ["sales", "accounting"],
        "rules_en": ["Invoice document status and payment status are separate.", "Unallocated posted money can remain a customer deposit.", "Write-offs require controlled financial treatment."],
        "rules_ar": ["حالة مستند الفاتورة منفصلة عن حالة السداد.", "قد يبقى المبلغ المرحّل غير المخصص كعربون عميل.", "يحتاج شطب الذمم إلى معالجة مالية مضبوطة."],
    },
    {
        "slug": "accounts-payable",
        "en": "Accounts Payable",
        "ar": "الذمم الدائنة",
        "summary_en": "Follow supplier billing from received purchasing work into payable liability and supplier payment.",
        "summary_ar": "متابعة فوترة المورد من عملية الشراء والاستلام إلى الذمة الدائنة ودفع المورد.",
        "steps_en": ["Purchase / Receipt", "Supplier Bill", "Accounts Payable", "Supplier Payment", "Allocation"],
        "steps_ar": ["شراء / استلام", "فاتورة مورد", "ذمم دائنة", "دفع المورد", "تخصيص"],
        "modules": ["vendors", "accounting"],
        "rules_en": ["Purchasing and receiving provide source context; Accounting owns the liability.", "Payments should preserve allocation provenance to supplier bills."],
        "rules_ar": ["توفر المشتريات والاستلام سياق المصدر بينما تمتلك المحاسبة الذمة.", "يجب أن تحافظ المدفوعات على مصدر تخصيصها لفواتير المورد."],
    },
    {
        "slug": "period-close",
        "en": "Fiscal Period Review and Close",
        "ar": "مراجعة وإغلاق الفترة المالية",
        "summary_en": "Review posted financial activity and control whether new transactions can affect a fiscal period.",
        "summary_ar": "مراجعة النشاط المالي المرحّل والتحكم بقدرة العمليات الجديدة على التأثير في الفترة المالية.",
        "steps_en": ["Period", "Transaction Review", "Exceptions", "Financial Reports", "Close / Lock"],
        "steps_ar": ["الفترة", "مراجعة الحركات", "الاستثناءات", "التقارير المالية", "إغلاق / قفل"],
        "modules": ["accounting", "reports"],
        "rules_en": ["Closing controls future posting; it should not erase historical transactions.", "Corrections may require authorized reopen/reversal behavior according to policy."],
        "rules_ar": ["الإغلاق يتحكم بالترحيل المستقبلي ولا يمحو الحركات التاريخية.", "قد تحتاج التصحيحات إلى إعادة فتح أو عكس مصرح به حسب السياسة."],
    },
    {
        "slug": "employee-field-sales",
        "en": "Employee Field Sales",
        "ar": "المبيعات الميدانية للموظف",
        "summary_en": "Execute an employee plan through tasks and visits, capture outcomes, follow-up and approved sales opportunities, then continue into Sales.",
        "summary_ar": "تنفيذ خطة الموظف عبر المهام والزيارات وتسجيل النتائج والمتابعة والفرص المقبولة ثم الاستمرار إلى المبيعات.",
        "steps_en": ["Monthly Plan", "Task", "Visit", "Customer", "Sales Action", "Outcome", "Follow-up", "Opportunity / Quotation"],
        "steps_ar": ["خطة شهرية", "مهمة", "زيارة", "عميل", "إجراء مبيعات", "نتيجة", "متابعة", "فرصة / عرض سعر"],
        "modules": ["employees", "crm", "sales"],
        "rules_en": ["Visit lifecycle is Scheduled → En Route → In Progress → Completed, with Unable to Complete and Cancelled terminal alternatives.", "Only approved employee sales opportunities contribute to the opportunity performance factor.", "Employee-created quotations still follow the normal Sales lifecycle."],
        "rules_ar": ["دورة الزيارة هي مجدولة ثم في الطريق ثم قيد التنفيذ ثم مكتملة، مع عدم القدرة على الإكمال والإلغاء كحالات نهائية بديلة.", "الفرص المعتمدة فقط تدخل في عامل أداء الفرص.", "عروض الأسعار التي ينشئها الموظف تلتزم بدورة المبيعات العادية."],
    },
    {
        "slug": "employee-performance",
        "en": "Employee Performance and Salary",
        "ar": "أداء الموظف والراتب",
        "summary_en": "Turn completed operational evidence into performance review and salary-calculation context.",
        "summary_ar": "تحويل أدلة التنفيذ المكتملة إلى سياق مراجعة الأداء وحساب الراتب.",
        "steps_en": ["Plan", "Tasks / Visits", "Approved Outcomes", "Performance", "Salary Calculation"],
        "steps_ar": ["الخطة", "المهام / الزيارات", "النتائج المعتمدة", "الأداء", "حساب الراتب"],
        "modules": ["employees"],
        "rules_en": ["Follow-up tasks are excluded from the base plan-task performance denominator.", "Only approved opportunity outcomes contribute where that performance factor applies."],
        "rules_ar": ["تستبعد مهام المتابعة من مقام أداء مهام الخطة الأساسي.", "الفرص المعتمدة فقط تساهم عندما يطبق عامل أداء الفرص."],
    },
    {
        "slug": "support-case",
        "en": "Support Case Lifecycle",
        "ar": "دورة حالة الدعم",
        "summary_en": "Handle a customer ticket from intake and triage through SLA, assignment, work, customer waiting, resolution and closure.",
        "summary_ar": "معالجة تذكرة العميل من الاستقبال والفرز وSLA حتى الإسناد والعمل وانتظار العميل والحل والإغلاق.",
        "steps_en": ["Ticket", "Triage", "SLA / Entitlement", "Assignment", "Work", "Resolution", "Closure"],
        "steps_ar": ["تذكرة", "فرز", "SLA / استحقاق", "إسناد", "عمل", "حل", "إغلاق"],
        "modules": ["support"],
        "rules_en": ["Ticket closure can be blocked by unfinished child work.", "Warranty and chargeability decisions should remain traceable.", "Support does not directly rewrite stock custody or posted accounting."],
        "rules_ar": ["قد يمنع العمل الفرعي غير المكتمل إغلاق التذكرة.", "يجب بقاء قرارات الضمان والتكلفة قابلة للتتبع.", "الدعم لا يعيد كتابة عهدة المخزون أو المحاسبة المرحلة مباشرة."],
    },
    {
        "slug": "maintenance",
        "en": "Maintenance and Repair",
        "ar": "الصيانة والإصلاح",
        "summary_en": "Coordinate equipment diagnosis, approval, repair, quality checks and service evidence.",
        "summary_ar": "تنسيق تشخيص المعدات والموافقة والإصلاح وضبط الجودة وأدلة الخدمة.",
        "steps_en": ["Request", "Equipment Context", "Diagnosis", "Approval", "Repair", "QA", "Service Record"],
        "steps_ar": ["طلب", "سياق المعدة", "تشخيص", "موافقة", "إصلاح", "ضبط جودة", "سجل خدمة"],
        "modules": ["support", "inventory"],
        "rules_en": ["Known serialized customer equipment should be reused when available.", "Parts consumption and custody changes are posted through Inventory.", "Chargeable work can create Sales-owned commercial documents."],
        "rules_ar": ["يفضل استخدام المعدة المعروفة ذات الرقم التسلسلي للعميل عند توفرها.", "يتم ترحيل استهلاك القطع وتغير العهدة عبر المخزون.", "يمكن للعمل المدفوع إنشاء مستندات تجارية مملوكة للمبيعات."],
    },
    {
        "slug": "warranty",
        "en": "Warranty Service",
        "ar": "خدمة الضمان",
        "summary_en": "Evaluate equipment entitlement and execute covered service while preserving warranty evidence and authorized corrections.",
        "summary_ar": "تقييم استحقاق المعدة وتنفيذ الخدمة المغطاة مع الحفاظ على أدلة الضمان والتصحيحات المصرح بها.",
        "steps_en": ["Equipment", "Warranty Policy", "Entitlement", "Ticket / Request", "Coverage Decision", "Service", "History"],
        "steps_ar": ["معدة", "سياسة ضمان", "استحقاق", "تذكرة / طلب", "قرار التغطية", "خدمة", "سجل"],
        "modules": ["support"],
        "rules_en": ["Warranty activation can depend on delivery, installation or commissioning policy.", "Warranty-date correction requires dedicated permission and a reason.", "Serialized equipment warranty snapshot must remain synchronized."],
        "rules_ar": ["قد يعتمد بدء الضمان على سياسة التسليم أو التركيب أو التشغيل.", "تصحيح تاريخ الضمان يحتاج صلاحية مخصصة وسببًا.", "يجب أن تبقى لقطة ضمان المعدة ذات الرقم التسلسلي متزامنة."],
    },
    {
        "slug": "field-service",
        "en": "Field Service",
        "ar": "الخدمة الميدانية",
        "summary_en": "Schedule and execute a customer service appointment with the correct equipment, assignee and service outcome.",
        "summary_ar": "جدولة وتنفيذ موعد خدمة لدى العميل مع المعدة والمسؤول والنتيجة الصحيحة.",
        "steps_en": ["Service Need", "Appointment", "Assignment", "Visit", "Work", "Outcome", "Follow-up"],
        "steps_ar": ["حاجة خدمة", "موعد", "إسناد", "زيارة", "عمل", "نتيجة", "متابعة"],
        "modules": ["support", "employees"],
        "rules_en": ["Appointment status and ticket/maintenance status remain related but distinct.", "Evidence and equipment context should follow the service record."],
        "rules_ar": ["تبقى حالة الموعد وحالة التذكرة أو الصيانة مترابطة لكنها منفصلة.", "يجب أن تنتقل الأدلة وسياق المعدة مع سجل الخدمة."],
    },
    {
        "slug": "equipment-lifecycle",
        "en": "Customer Equipment Lifecycle",
        "ar": "دورة معدات العميل",
        "summary_en": "Follow serialized equipment from Inventory delivery into customer custody, support, maintenance, loaner or supplier repair and later service history.",
        "summary_ar": "متابعة المعدة ذات الرقم التسلسلي من تسليم المخزون إلى عهدة العميل ثم الدعم والصيانة والإعارة أو إصلاح المورد وسجل الخدمة.",
        "steps_en": ["Serial Unit", "Delivery", "Customer Custody", "Equipment 360", "Ticket", "Maintenance / Loan / Supplier Repair", "Return / Service History"],
        "steps_ar": ["وحدة متسلسلة", "تسليم", "عهدة عميل", "Equipment 360", "تذكرة", "صيانة / إعارة / إصلاح مورد", "إرجاع / سجل خدمة"],
        "modules": ["inventory", "sales", "support"],
        "rules_en": ["Custody movement is an Inventory responsibility.", "Support references the same serialized identity rather than creating duplicate device records.", "Supplier repair and loaner work must be closed before the related service case can fully close when policy requires it."],
        "rules_ar": ["حركة العهدة مسؤولية المخزون.", "يشير الدعم إلى نفس الهوية التسلسلية بدل إنشاء سجل جهاز مكرر.", "يجب إغلاق إصلاح المورد والإعارة قبل إغلاق الحالة الخدمية عندما تتطلب السياسة ذلك."],
    },
]


WORKFLOW_STEP_TARGETS = {
    "quote-to-cash": [
        ("crm", "customer"), ("sales", "quotation"), ("sales", "order"),
        ("inventory", "outbound-fulfillment"), ("inventory", "shipment"),
        ("sales", "invoice"), ("sales", "payment"), ("accounting", "journal-entry"),
    ],
    "customer-return-to-refund": [
        ("crm", "customer-return-request"), ("inventory", "return"), ("inventory", "return"),
        ("inventory", "inventory-condition-change"), ("inventory", "return"),
        ("sales", "credit-note"), ("accounting", "refund"),
    ],
    "procure-to-pay": [
        ("vendors", "purchase-needs"), ("vendors", "purchase-agreement"), ("vendors", "purchase-order"),
        ("vendors", "supplier-confirmation"), ("inventory", "purchase-inbound"),
        ("inventory", "inventory-operation"), ("accounting", "bill"), ("accounting", "supplier-payment"),
    ],
    "sales-shortage-procurement": [
        ("sales", "order"), ("inventory", "stock-level"), ("vendors", "purchase-needs"),
        ("vendors", "purchase-order"), ("vendors", "supplier-confirmation"),
        ("inventory", "inventory-operation"), ("inventory", "inventory-reservation"),
        ("inventory", "outbound-fulfillment"),
    ],
    "warehouse-replenishment": [
        ("inventory", "warehouse-replenishment-policy"), ("inventory", "stock-level"),
        ("inventory", "inventory-alert"), ("vendors", "purchase-needs"),
        ("vendors", "purchase-order"), ("inventory", "inventory-operation"),
    ],
    "inventory-transfer": [
        ("inventory", "inventory-operation"), ("inventory", "stock-level"),
        ("inventory", "inventory-operation"), ("inventory", "stock-movement"), ("inventory", "stock-level"),
    ],
    "physical-count": [
        ("inventory", "inventory-count"), ("inventory", "inventory-count"),
        ("inventory", "inventory-count"), ("inventory", "inventory-count"),
        ("inventory", "inventory-count"), ("inventory", "adjustment"),
    ],
    "inventory-correction": [
        ("inventory", "inventory-operation"), ("inventory", "inventory-correction"),
        ("inventory", "inventory-correction"), ("inventory", "inventory-correction"),
        ("inventory", "stock-movement"),
    ],
    "tracked-inventory": [
        ("inventory", "product"), ("inventory", "inventory-operation"), ("inventory", "inventory-lot"),
        ("inventory", "stock-level"), ("inventory", "inventory-reservation"),
        ("inventory", "outbound-fulfillment"), ("inventory", "inventory-operation"),
        ("inventory", "serialized-inventory-unit"),
    ],
    "lead-to-sale": [
        ("crm", "lead"), ("crm", "lead"), ("crm", "sales-opportunity"),
        ("crm", "customer"), ("sales", "quotation"), ("sales", "order"),
    ],
    "customer-pricing": [
        ("crm", "customer"), ("accounting", "currency"), ("crm", "pricing-tier"),
        ("inventory", "product"), ("crm", "pricing-tier"), ("sales", "quotation"), ("sales", "quotation"),
    ],
    "accounts-receivable": [
        ("sales", "invoice"), ("accounting", "accounts-receivable"),
        ("sales", "payment"), ("sales", "payment"), ("accounting", "accounts-receivable"),
    ],
    "accounts-payable": [
        ("vendors", "purchase-order"), ("accounting", "bill"), ("accounting", "accounts-payable"),
        ("accounting", "supplier-payment"), ("accounting", "supplier-payment"),
    ],
    "period-close": [
        ("accounting", "fiscal-period"), ("accounting", "journal-entry"),
        ("accounting", "financial-report"), ("accounting", "financial-report"), ("accounting", "fiscal-period"),
    ],
    "employee-field-sales": [
        ("employees", "monthly-plan"), ("employees", "task"), ("employees", "visit"),
        ("crm", "customer"), ("employees", "visit"), ("employees", "visit"),
        ("employees", "task"), ("crm", "sales-opportunity"),
    ],
    "employee-performance": [
        ("employees", "monthly-plan"), ("employees", "task"), ("employees", "visit"),
        ("employees", "performance"), ("employees", "salary-calculation"),
    ],
    "support-case": [
        ("support", "ticket"), ("support", "ticket"), ("support", "support-entitlement"),
        ("support", "support-team"), ("support", "ticket"), ("support", "ticket"), ("support", "ticket"),
    ],
    "maintenance": [
        ("support", "maintenance-request"), ("support", "support-equipment"),
        ("support", "maintenance-request"), ("support", "maintenance-request"),
        ("support", "maintenance-request"), ("support", "maintenance-request"), ("support", "service-record"),
    ],
    "warranty": [
        ("support", "support-equipment"), ("support", "warranty-policy"),
        ("support", "support-entitlement"), ("support", "ticket"),
        ("support", "maintenance-request"), ("support", "service-record"), ("support", "support-equipment"),
    ],
    "field-service": [
        ("support", "ticket"), ("support", "service-appointment"), ("support", "service-appointment"),
        ("support", "service-appointment"), ("support", "service-record"), ("support", "service-record"),
        ("support", "service-appointment"),
    ],
    "equipment-lifecycle": [
        ("inventory", "serialized-inventory-unit"), ("inventory", "inventory-operation"),
        ("inventory", "serialized-inventory-unit"), ("support", "support-equipment"),
        ("support", "ticket"), ("support", "maintenance-request"), ("support", "service-record"),
    ],
}

WORKFLOW_STEP_NOTES_EN = {
    "quote-to-cash": [
        "Start from an approved/usable customer profile so pricing, payment terms and commercial ownership can resolve correctly.",
        "Build the offer, verify validity/pricing provenance and send it. No stock or ledger effect occurs here.",
        "Convert only an Accepted quotation. The conversion is one-time and creates confirmed customer demand.",
        "Release/plan fulfillment against available stock. Any uncovered demand can create procurement requirements instead of pretending stock exists.",
        "Complete the physical delivery through Inventory/Logistics and progress the shipment to arrival; physical custody changes here, not on the quotation.",
        "Create/issue the invoice from eligible commercial/fulfillment context. Issuing creates the approved accounting effect while settlement remains separate.",
        "Record and post the customer payment, allocating it to eligible invoices or leaving valid remainder as customer deposit.",
        "Review the resulting receivable, collection, tax and ledger effects in Accounting; corrections use explicit credit/reversal paths.",
    ],
    "procure-to-pay": [
        "Start from a real external supply need—independent purchasing, replenishment or Sales shortage—and preserve that source when applicable.",
        "Use supplier sourcing/agreement context to determine the eligible supplier and commercial terms before committing.",
        "Create the PO with supplier-owned commercial data, submit/approve as required and send only after authorization.",
        "Record what the supplier actually commits to provide, including partial quantity or promised-date changes.",
        "Allocate expected inbound quantity to the warehouse destination before the physical receipt is posted.",
        "Create/complete the Inventory receipt. This is the physical stock-posting boundary; Purchasing does not write stock directly.",
        "Review/approve the supplier bill and resulting payable liability in Accounting using the valid purchasing/receiving provenance.",
        "Pay and allocate the supplier liability through the Accounting supplier-payment workflow.",
    ],
}
WORKFLOW_STEP_NOTES_AR = {
    "quote-to-cash": [
        "ابدأ من ملف عميل معتمد وصالح حتى يتم حل التسعير وشروط الدفع والملكية التجارية بشكل صحيح.",
        "أنشئ العرض وراجع الصلاحية ومصدر السعر ثم أرسله. لا يوجد أثر مخزون أو دفتر في هذه المرحلة.",
        "حوّل العرض المقبول فقط. التحويل مرة واحدة وينشئ طلب عميل مؤكدًا.",
        "حرر وخطط التنفيذ مقابل المخزون المتاح، وأي نقص ينشئ متطلبات توريد بدل افتراض وجود مخزون.",
        "أكمل التسليم المادي عبر المخزون واللوجستيات وتابع الشحنة حتى الوصول؛ هنا تتغير العهدة المادية.",
        "أنشئ وأصدر الفاتورة من سياق تنفيذ مؤهل. الإصدار ينشئ الأثر المحاسبي بينما تبقى التسوية منفصلة.",
        "سجل دفعة العميل ورحّلها وخصصها للفواتير المؤهلة أو اترك الباقي كعربون صحيح عند انطباق القاعدة.",
        "راجع الذمم والتحصيل والضريبة والدفتر في المحاسبة، واستخدم الائتمان أو العكس الصريح للتصحيح.",
    ],
    "procure-to-pay": [
        "ابدأ من حاجة توريد خارجية حقيقية سواء شراء مستقل أو إعادة تزويد أو نقص مبيعات، واحفظ مصدر الحاجة.",
        "استخدم سياق التوريد أو الاتفاقية لتحديد المورد المؤهل والشروط التجارية قبل الالتزام.",
        "أنشئ أمر الشراء ببيانات المورد التجارية وأرسله للموافقة ثم للمورد بعد التفويض.",
        "سجل ما التزم المورد فعليًا بتوفيره بما في ذلك الكمية الجزئية أو تغير تاريخ الوعد.",
        "وزع الكمية الواردة المتوقعة على المستودع قبل ترحيل الاستلام الفعلي.",
        "أنشئ وأكمل استلام المخزون؛ هذه هي نقطة ترحيل المخزون الفعلي ولا تكتب المشتريات الكمية مباشرة.",
        "راجع واعتمد فاتورة المورد والذمة الناتجة في المحاسبة مع مصدر شراء واستلام صحيح.",
        "ادفع ذمة المورد وخصصها من خلال مسار دفع المورد في المحاسبة.",
    ],
}


WORKFLOW_CHECKPOINT_EN = {
    "sales": "Verify the commercial source/status, customer and line values. Sales owns the customer document; physical stock still changes only through Inventory.",
    "inventory": "Verify warehouse, normalized quantity and lot/serial requirements. Any physical or reservation effect must be recorded through Inventory-owned work.",
    "vendors": "Verify supplier, purchasing UOM, currency and source demand. Purchasing owns supplier commitment, not physical stock posting.",
    "accounting": "Verify the posting period, currency, source document and allocation context. Accounting owns the financial truth written by this step.",
    "crm": "Verify customer/opportunity context and approval state. CRM provides relationship context without bypassing Sales, Inventory or Accounting ownership.",
    "employees": "Verify assignee, schedule, customer and required evidence. Employee operations contribute to execution/performance but do not bypass owning modules.",
    "support": "Verify customer, equipment/serial, entitlement/SLA and open child work. Support coordinates service while Inventory/Accounting keep their own truth.",
    "reports": "Verify report filters and source-period scope. Reports read operational truth and do not repair source records.",
    "system": "Verify the configuration scope and permission impact. System settings affect future behavior without rewriting posted history.",
}
WORKFLOW_CHECKPOINT_AR = {
    "sales": "تحقق من المصدر التجاري والحالة والعميل وقيم البنود. تملك المبيعات المستند التجاري بينما يبقى تغيير المخزون للمخزون فقط.",
    "inventory": "تحقق من المستودع والكمية المعيارية ومتطلبات الدفعة أو الرقم التسلسلي. أي أثر مادي أو حجز يجب أن يسجل عبر المخزون.",
    "vendors": "تحقق من المورد ووحدة الشراء والعملة ومصدر الحاجة. تملك المشتريات التزام المورد وليس ترحيل المخزون الفعلي.",
    "accounting": "تحقق من الفترة والعملة ومستند المصدر وسياق التخصيص. تملك المحاسبة الحقيقة المالية الناتجة عن هذه الخطوة.",
    "crm": "تحقق من سياق العميل أو الفرصة وحالة الموافقة. توفر CRM سياق العلاقة دون تجاوز ملكية المبيعات أو المخزون أو المحاسبة.",
    "employees": "تحقق من المسؤول والجدولة والعميل والأدلة المطلوبة. تساهم عمليات الموظف في التنفيذ والأداء دون تجاوز الوحدات المالكة.",
    "support": "تحقق من العميل والمعدة أو الرقم التسلسلي والاستحقاق وSLA والعمل الفرعي المفتوح. ينسق الدعم الخدمة بينما تبقى الحقيقة المادية والمالية في وحداتها.",
    "reports": "تحقق من فلاتر التقرير ونطاق الفترة. تقرأ التقارير الحقيقة التشغيلية ولا تصلح سجلات المصدر.",
    "system": "تحقق من نطاق الإعداد وأثر الصلاحيات. تؤثر إعدادات النظام على العمل المستقبلي دون إعادة كتابة التاريخ المرحل.",
}

WORKFLOW_TARGET_GUIDANCE_EN = {
    "customer": "Confirm the customer profile and commercial defaults are ready for the next business document.",
    "quotation": "Create or review the quotation, verify pricing/validity and use its lifecycle actions rather than editing status directly.",
    "order": "Confirm customer demand and use the order lifecycle to control release into fulfillment/procurement.",
    "outbound-fulfillment": "Plan and execute the warehouse-facing fulfillment work against actually available/reserved stock.",
    "shipment": "Track dispatched goods through logistics milestones until the delivery/shipment reaches its expected terminal state.",
    "invoice": "Create and issue the billing document from eligible source context while keeping settlement separate.",
    "payment": "Record, post and allocate customer money against eligible invoices or deposit context.",
    "journal-entry": "Review the ledger effect produced by the business event; use explicit correcting entries rather than destructive edits.",
    "customer-return-request": "Capture the customer's return request and review whether it can be accepted into the operational return flow.",
    "return": "Receive/inspect the returned quantity and determine the physical disposition through Inventory.",
    "inventory-condition-change": "Record the approved condition/disposition change so the physical item state remains auditable.",
    "credit-note": "Create the financial credit against eligible invoiced/returned value without changing physical stock directly.",
    "refund": "Execute the approved money-return path after the required financial/return prerequisites are satisfied.",
    "purchase-needs": "Review the external supply requirement and preserve its source, quantity and planning context.",
    "purchase-agreement": "Use the supplier agreement/sourcing context to resolve commercial terms before committing.",
    "purchase-order": "Create, approve and send the supplier commitment using supplier-owned commercial data.",
    "supplier-confirmation": "Record supplier-committed quantity and promised dates, including valid partial commitments.",
    "purchase-inbound": "Allocate expected inbound work to its warehouse destination before physical receipt.",
    "inventory-operation": "Execute the Inventory-owned receipt/delivery/transfer and post the physical movement only when validated.",
    "bill": "Review/approve the supplier bill that creates or updates the payable liability from valid purchasing provenance.",
    "supplier-payment": "Record and allocate payment against supplier liabilities through Accounting.",
    "stock-level": "Review current on-hand/reserved/available stock; do not edit balances from this read-only planning surface.",
    "inventory-reservation": "Reserve eligible available stock for the source demand while preserving warehouse/tracking provenance.",
    "warehouse-replenishment-policy": "Review the configured min/max, supplier and lead-time policy used to calculate replenishment need.",
    "inventory-alert": "Review the exception/alert and decide whether it requires replenishment or another owning-domain action.",
    "stock-movement": "Use the movement trail to verify what physically changed, where and from which source document.",
    "inventory-count": "Define/count/review the physical count and variance through the controlled count lifecycle.",
    "adjustment": "Post the approved variance as an auditable adjustment rather than changing stock totals directly.",
    "inventory-correction": "Create the explicit correction against posted inventory history so the original movement remains visible.",
    "product": "Verify catalog/variant/UOM/tracking configuration before relying on the item in downstream operations.",
    "inventory-lot": "Verify lot identity, quantity and expiry-sensitive context for the tracked stock.",
    "serialized-inventory-unit": "Trace the unique serial, condition and custody history for this physical unit.",
    "lead": "Capture and qualify the potential customer/business before promotion into a formal opportunity.",
    "sales-opportunity": "Review the commercial opportunity stage/value and progress it toward quotation or closure.",
    "pricing-tier": "Review the applicable customer/product pricing tier and quantity-break context.",
    "currency": "Use the active shared currency catalog for new work while preserving historical stored currencies.",
    "accounts-receivable": "Review customer open value, allocations and settlement status; correct from the owning source when needed.",
    "accounts-payable": "Review supplier open liability, allocations and settlement status from valid supplier bills.",
    "fiscal-period": "Review whether the financial period is open for posting and close/lock it only after exceptions are resolved.",
    "financial-report": "Review the financial output for the selected period and trace discrepancies back to source transactions.",
    "monthly-plan": "Set the employee's period objectives/tasks before field execution.",
    "task": "Execute or follow the assigned task and record completion/cancellation through its lifecycle.",
    "visit": "Execute the scheduled customer visit through travel, in-progress work, outcome and evidence.",
    "performance": "Review calculated performance evidence from completed operational work rather than entering a manual score in isolation.",
    "salary-calculation": "Review the salary result generated from the configured compensation/performance context before confirmation.",
    "ticket": "Triage and progress the customer support case through assignment, work, resolution and closure.",
    "support-entitlement": "Verify service/warranty entitlement before deciding whether the requested work is covered or chargeable.",
    "support-team": "Assign the support work to the appropriate team/queue according to routing and skill context.",
    "maintenance-request": "Progress diagnosis, approval, repair and QA without losing equipment and warranty context.",
    "support-equipment": "Use the same serialized customer equipment identity to review custody, warranty and service history.",
    "service-record": "Record the performed service, evidence and result as the durable history of the service event.",
    "warranty-policy": "Review the configured warranty rules/activation basis used to evaluate equipment coverage.",
    "service-appointment": "Schedule and execute field service with the correct customer, equipment and assignee.",
}
WORKFLOW_TARGET_GUIDANCE_AR = {
    "customer": "تحقق من جاهزية ملف العميل والإعدادات التجارية للوثيقة التالية.",
    "quotation": "أنشئ أو راجع عرض السعر وتحقق من التسعير والصلاحية واستخدم إجراءات دورة الحياة بدل تعديل الحالة يدويًا.",
    "order": "أكد طلب العميل واستخدم دورة حياة الطلب للتحكم بالتحرير إلى التنفيذ أو التوريد.",
    "outbound-fulfillment": "خطط ونفذ عمل التنفيذ المخزني مقابل مخزون متاح أو محجوز فعليًا.",
    "shipment": "تابع البضاعة المرسلة عبر مراحل الشحن حتى الوصول إلى الحالة النهائية المتوقعة.",
    "invoice": "أنشئ وأصدر مستند الفوترة من مصدر مؤهل مع إبقاء التسوية المالية منفصلة.",
    "payment": "سجل دفعة العميل ورحّلها وخصصها لفواتير مؤهلة أو لسياق العربون.",
    "journal-entry": "راجع أثر الحدث على الدفتر واستخدم قيود تصحيح صريحة بدل التعديل الهدام.",
    "customer-return-request": "سجل طلب مرتجع العميل وراجعه قبل إدخاله في مسار المرتجع التشغيلي.",
    "return": "استلم وافحص الكمية المرتجعة وحدد حالتها المادية عبر المخزون.",
    "inventory-condition-change": "سجل تغير الحالة أو التصنيف المعتمد حتى يبقى وضع العنصر المادي قابلًا للتدقيق.",
    "credit-note": "أنشئ الائتمان المالي مقابل قيمة مؤهلة دون تغيير المخزون المادي مباشرة.",
    "refund": "نفذ مسار إعادة المال بعد اكتمال متطلبات المرتجع والتصحيح المالي.",
    "purchase-needs": "راجع حاجة التوريد الخارجية واحفظ مصدرها وكميتها وسياق التخطيط.",
    "purchase-agreement": "استخدم الاتفاقية أو سياق التوريد لحل الشروط التجارية قبل الالتزام.",
    "purchase-order": "أنشئ واعتمد وأرسل التزام المورد باستخدام بيانات الشراء التجارية.",
    "supplier-confirmation": "سجل الكمية التي التزم المورد بها وتاريخ الوعد بما فيها الالتزامات الجزئية الصحيحة.",
    "purchase-inbound": "وزع العمل الوارد المتوقع على وجهة المستودع قبل الاستلام الفعلي.",
    "inventory-operation": "نفذ الاستلام أو التسليم أو النقل المملوك للمخزون ولا ترحل الحركة إلا بعد التحقق.",
    "bill": "راجع واعتمد فاتورة المورد التي تنشئ أو تحدث الذمة من مصدر شراء صحيح.",
    "supplier-payment": "سجل وخصص دفع المورد مقابل الذمم عبر المحاسبة.",
    "stock-level": "راجع الموجود والمحجوز والمتاح ولا تعدل الأرصدة من شاشة التخطيط المقروءة.",
    "inventory-reservation": "احجز المخزون المتاح المؤهل للطلب مع الحفاظ على المستودع والتتبع.",
    "warehouse-replenishment-policy": "راجع سياسة الحد الأدنى والأقصى والمورد ومدة التوريد المستخدمة لحساب إعادة التزويد.",
    "inventory-alert": "راجع التنبيه وحدد هل يحتاج إعادة تزويد أو إجراء من الوحدة المالكة.",
    "stock-movement": "استخدم سجل الحركة للتحقق مما تغير ماديًا وأين وما هو مصدر الحركة.",
    "inventory-count": "حدد الجرد ونفذ العد وراجع الفرق عبر دورة الجرد المضبوطة.",
    "adjustment": "رحّل الفرق المعتمد كتعديل قابل للتدقيق بدل تغيير إجمالي المخزون مباشرة.",
    "inventory-correction": "أنشئ تصحيحًا صريحًا للتاريخ المرحل مع إبقاء الحركة الأصلية ظاهرة.",
    "product": "تحقق من إعداد المنتج والمتغير ووحدة القياس والتتبع قبل استخدامه في العمليات التالية.",
    "inventory-lot": "تحقق من هوية الدفعة والكمية وسياق الصلاحية للمخزون المتتبع.",
    "serialized-inventory-unit": "تتبع الرقم التسلسلي الفريد وحالته وعهدته التاريخية.",
    "lead": "سجل العميل أو العمل المحتمل وأهله قبل تحويله إلى فرصة رسمية.",
    "sales-opportunity": "راجع مرحلة وقيمة الفرصة التجارية وتقدم بها إلى عرض سعر أو إغلاق.",
    "pricing-tier": "راجع شريحة السعر المطبقة وسياق شرائح الكمية للعميل والمنتج.",
    "currency": "استخدم دليل العملات الفعالة للعمل الجديد مع الحفاظ على العملات المخزنة تاريخيًا.",
    "accounts-receivable": "راجع ذمة العميل المفتوحة والتخصيص والتسوية وصحح من المصدر المالك عند الحاجة.",
    "accounts-payable": "راجع ذمة المورد المفتوحة والتخصيص والتسوية من فواتير مورد صحيحة.",
    "fiscal-period": "تحقق من أن الفترة مفتوحة للترحيل ولا تغلقها إلا بعد حل الاستثناءات.",
    "financial-report": "راجع التقرير للفترة المختارة وتتبع أي فرق إلى الحركات المصدرية.",
    "monthly-plan": "حدد أهداف ومهام الموظف للفترة قبل التنفيذ الميداني.",
    "task": "نفذ أو تابع المهمة المسندة وسجل الإكمال أو الإلغاء عبر دورة حياتها.",
    "visit": "نفذ زيارة العميل من الحركة والوصول والعمل وحتى النتيجة والأدلة.",
    "performance": "راجع أدلة الأداء المحسوبة من العمل المكتمل بدل إدخال درجة يدوية معزولة.",
    "salary-calculation": "راجع نتيجة الراتب الناتجة عن إعدادات التعويض والأداء قبل التأكيد.",
    "ticket": "افرز حالة الدعم وتابعها عبر الإسناد والعمل والحل والإغلاق.",
    "support-entitlement": "تحقق من استحقاق الخدمة أو الضمان قبل تحديد إن كان العمل مغطى أو مدفوعًا.",
    "support-team": "أسند عمل الدعم للفريق أو الطابور المناسب حسب التوجيه والمهارات.",
    "maintenance-request": "تابع التشخيص والموافقة والإصلاح وضبط الجودة مع الحفاظ على سياق المعدة والضمان.",
    "support-equipment": "استخدم نفس هوية المعدة المتسلسلة لمراجعة العهدة والضمان وسجل الخدمة.",
    "service-record": "سجل الخدمة المنفذة والأدلة والنتيجة كتاريخ دائم لحدث الخدمة.",
    "warranty-policy": "راجع قواعد الضمان وأساس التفعيل المستخدمة لتقييم تغطية المعدة.",
    "service-appointment": "جدول ونفذ الخدمة الميدانية مع العميل والمعدة والمسؤول الصحيح.",
}

def workflow_checkpoint(module: str, ar: bool) -> str:
    return (WORKFLOW_CHECKPOINT_AR if ar else WORKFLOW_CHECKPOINT_EN).get(module, "")

def workflow_target_guidance(target_slug: str, ar: bool) -> str:
    mapping = WORKFLOW_TARGET_GUIDANCE_AR if ar else WORKFLOW_TARGET_GUIDANCE_EN
    return mapping.get(target_slug, "")


def workflow_step_target(slug: str, index: int) -> tuple[str, str] | None:
    targets = WORKFLOW_STEP_TARGETS.get(slug, [])
    return targets[index] if index < len(targets) else None

def workflow_step_note(wf_slug: str, index: int, ar: bool, fallback: str) -> str:
    notes = WORKFLOW_STEP_NOTES_AR.get(wf_slug, []) if ar else WORKFLOW_STEP_NOTES_EN.get(wf_slug, [])
    return notes[index] if index < len(notes) else fallback


def workflow_article(wf: dict[str, Any], locale: str) -> str:
    ar = locale == "ar"
    title = wf["ar"] if ar else wf["en"]
    summary = wf["summary_ar"] if ar else wf["summary_en"]
    steps = wf["steps_ar"] if ar else wf["steps_en"]
    modules = wf["modules"]
    rules = wf["rules_ar"] if ar else wf["rules_en"]
    module_names = []
    with MANIFEST_PATH.open(encoding="utf-8") as f:
        manifest = json.load(f)
    by_key = {g["key"]: g for g in manifest}
    for key in modules:
        g = by_key[key]
        module_names.append(g["label_ar" if ar else "label_en"])

    out = [
        "---",
        f'title: "{title}"',
        "type: workflow",
        f"lastVerified: {TODAY}",
        "---",
        "",
        f"# {title}",
        "",
        summary,
        "",
        f"<IerpWorkflow :steps='{vue_array(steps)}' />",
        "",
        "## " + ("الوحدات المشاركة" if ar else "Modules involved"),
        "",
        ", ".join(module_names) + ".",
        "",
        "## " + ("متى تستخدم هذا المسار؟" if ar else "When to use this workflow"),
        "",
        (f"استخدم هذا المسار عندما تحتاج لتنفيذ **{title}** من البداية إلى النهاية مع الحفاظ على حدود المسؤولية بين الوحدات." if ar else f"Use this workflow when you need to complete **{title}** end to end while preserving the responsibility boundaries between modules."),
        "",
        "## " + ("المتطلبات قبل البدء" if ar else "Before you start"),
        "",
    ]
    if ar:
        out += [
            "- تأكد من أن السجلات الرئيسية المطلوبة فعالة مثل العميل أو المورد أو المنتج أو المعدة.",
            "- تأكد من صلاحيات المستخدم للإجراءات الحساسة في كل وحدة.",
            "- راجع العملة ووحدة القياس والمستودع وشروط الدفع عند ارتباطها بالعملية.",
            "- إذا كانت العملية بدأت من مستند سابق، استخدم الرابط أو الإجراء المخصص بدل إنشاء سجل مكرر يدويًا.",
        ]
    else:
        out += [
            "- Confirm required master records such as customer, supplier, product or equipment are active.",
            "- Confirm your role has permission for the high-impact actions in each module.",
            "- Review currency, UOM, warehouse and payment-term context when they apply.",
            "- When the workflow originates from another document, use the dedicated transition/link instead of manually creating a duplicate record.",
        ]
    out += ["", "## " + ("الخطوات" if ar else "Step by step"), ""]
    for zero_index, step in enumerate(steps):
        idx = zero_index + 1
        target = workflow_step_target(wf["slug"], zero_index)

        target_module = target[0] if target else ""
        target_slug = target[1] if target else ""
        guidance = workflow_target_guidance(target_slug, ar) if target else ""
        checkpoint = workflow_checkpoint(target_module, ar) if target else ""
        generic_fallback = (
            f"نفّذ خطوة **{step}** من الشاشة المالكة لها، ثم تحقق من نتيجة الخطوة قبل الانتقال لما بعدها."
            if ar
            else f"Complete **{step}** in its owning screen, then verify the result before continuing to the next step."
        )
        fallback = " ".join(part for part in (guidance, checkpoint) if part) or generic_fallback

        out += [
            f"### {idx}. {step}",
            "",
            workflow_step_note(wf["slug"], zero_index, ar, fallback),
            "",
        ]

        if target:
            target_label = step
            target_purpose = purpose_for_ar(target_module, target_slug, target_label) if ar else purpose_for(target_module, target_slug, target_label)
            prefix = "/ar/screens/" if ar else "/screens/"
            out += [
                (f"**الشاشة المستخدمة:** [{target_label}]({prefix}{target_module}/{target_slug})" if ar else f"**Work in:** [{target_label}]({prefix}{target_module}/{target_slug})"),
                "",
                target_purpose,
                "",
            ]
    out += ["## " + ("قواعد العمل المهمة" if ar else "Important business rules"), ""]
    out += [f"- {rule}" for rule in rules]
    out += ["", "## " + ("الاستثناءات والتوقفات" if ar else "Exceptions and blocked states"), ""]
    if ar:
        out += [
            "- إذا لم يظهر الإجراء التالي فتحقق من الصلاحية والحالة والمتطلبات السابقة.",
            "- لا تتجاوز خطوة بوضع حالة يدويًا؛ استخدم الإجراء المخصص.",
            "- عند وجود نقص أو تأكيد جزئي أو دفعة جزئية أو عمل خدمة غير مكتمل، يبقى المسار في حالة متابعة بدل اعتباره مكتملًا.",
            "- التصحيح بعد الترحيل يستخدم مسار تصحيح أو عكس بدلاً من حذف التاريخ.",
        ]
    else:
        out += [
            "- If the next action is missing, verify permissions, status and prerequisites.",
            "- Do not skip a step by forcing a status; use the dedicated workflow action.",
            "- Shortage, partial confirmation, partial payment or unfinished service work keeps the process in follow-up instead of pretending it is complete.",
            "- Corrections after posting use correction/reversal paths instead of deleting history.",
        ]
    out += ["", "## " + ("الشاشات المرتبطة" if ar else "Related screens"), ""]
    for key in modules:
        g = by_key[key]
        label = g["label_ar" if ar else "label_en"]
        prefix = "/ar/screens/" if ar else "/screens/"
        out.append(f"- [{label}]({prefix}{key}/)")
    out += ["", "## " + ("النتيجة النهائية" if ar else "Expected result"), ""]
    out.append(("تكون العملية مكتملة عندما تصل كل خطوة إلى حالتها النهائية الصحيحة وتظهر الآثار التابعة في الوحدات المالكة لها دون وجود عمل فرعي مفتوح يمنع الإغلاق." if ar else "The workflow is complete when every step reaches its correct terminal state and downstream effects are visible in their owning modules with no open child work blocking completion."))
    out.append("")
    return "\n".join(out)

def all_entries_for_group(group: dict[str, Any]) -> list[dict[str, Any]]:
    entries: list[dict[str, Any]] = []
    positions: dict[str, int] = {}

    def add(entry: dict[str, Any]) -> None:
        cls = entry["class"]
        if cls in positions:
            existing = entries[positions[cls]]
            existing_pages = {p["key"]: p for p in existing.get("pages", [])}
            for page in entry.get("pages", []):
                existing_pages[page["key"]] = page
            existing["pages"] = list(existing_pages.values())
            if not existing.get("label_key") and entry.get("label_key"):
                existing.update({k: v for k, v in entry.items() if k.startswith("label_")})
            if existing.get("section") in {None, "contextual"} and entry.get("section"):
                existing["section"] = entry["section"]
            return
        positions[cls] = len(entries)
        entries.append(dict(entry))

    for item in group["items"]:
        add(item)
        for tab in item.get("tabs", []):
            add({
                **tab,
                "section": item.get("section"),
                "tools": [],
                "tabs": [],
            })
        for tool in item.get("tools", []):
            cls = tool.get("class")
            if isinstance(cls, str) and cls.startswith("App\\"):
                add({
                    **tool,
                    "section": item.get("section"),
                    "pages": [],
                    "tools": [],
                    "tabs": [],
                })
    for contextual in group.get("contextual", []):
        cls = contextual["class"]
        base = class_slug(cls)
        add({
            **contextual,
            "class": cls,
            "label_en": humanize(base),
            "label_ar": field_label_ar(base),
            "label_key": "",
            "section": "contextual",
            "page": None,
            "pages": contextual.get("pages", []),
            "tabs": [],
            "tools": [],
        })
    return entries

def module_index(group: dict[str, Any], locale: str, entries: list[dict[str, Any]]) -> str:
    ar = locale == "ar"
    label = group["label_ar" if ar else "label_en"]
    intro = MODULE_INTRO_AR[group["key"]] if ar else MODULE_INTRO_EN[group["key"]]
    out = [
        "---",
        f'title: "{label}"',
        f"module: {group['key']}",
        f"lastVerified: {TODAY}",
        "---",
        "",
        f"# {label}",
        "",
        intro,
        "",
        "## " + ("كيف تستخدم هذا القسم؟" if ar else "How to use this module"),
        "",
        (
            "ابدأ من لوحة المتابعة الخاصة بالوحدة لفهم قوائم العمل والتنبيهات، ثم افتح الشاشة التي تملك السجل أو الإجراء المطلوب. يطابق ترتيب الصفحات أدناه مبدّل الوحدات والقائمة الجانبية في لوحة التحكم."
            if ar
            else
            "Start from the module dashboard to understand queues and exceptions, then open the screen that owns the record or action you need. The structure below follows the dashboard module switcher and sidebar."
        ),
        "",
        "## " + ("صفحات لوحة التحكم" if ar else "Dashboard screens"),
        "",
        '<div class="module-grid">',
    ]
    for entry in entries:
        slug = class_slug(entry["class"])
        title = localized_entry_label(entry, locale)
        href = (f"/guide/ar/screens/{group['key']}/{slug}" if ar else f"/guide/screens/{group['key']}/{slug}")
        pages = [p["key"] for p in entry.get("pages", [])] or [entry.get("page") or "overview"]
        desc = (
            purpose_for_ar(group["key"], slug, title)
            if ar
            else purpose_for(group["key"], slug, title)
        )
        out.append(f'<a class="module-card" href="{href}"><strong>{title}</strong><span>{desc}</span></a>')
    out += ["</div>", "", "## " + ("مسارات العمل المرتبطة" if ar else "Related workflows"), ""]
    links = WORKFLOW_LINKS.get(group["key"], [])
    if links:
        wf_by_slug = {w["slug"]: w for w in WORKFLOWS}
        for wf_slug in links:
            wf = wf_by_slug.get(wf_slug)
            if not wf:
                continue
            title = wf["ar" if ar else "en"]
            href = f"/ar/workflows/{wf_slug}" if ar else f"/workflows/{wf_slug}"
            out.append(f"- [{title}]({href})")
    else:
        out.append(("- [جميع مسارات العمل](/ar/workflows/)" if ar else "- [All business workflows](/workflows/)"))
    out += [
        "",
        "## " + ("قاعدة الملكية" if ar else "Ownership rule"),
        "",
        (WORKFLOW_EFFECT_AR[group["key"]] if ar else WORKFLOW_EFFECT_EN[group["key"]]),
        "",
    ]
    return "\n".join(out)

def sidebar_ts(manifest: list[dict[str, Any]], locale: str) -> str:
    ar = locale == "ar"
    items = []
    prefix = "/ar" if ar else ""
    items.append({
        "text": "البدء" if ar else "Getting Started",
        "collapsed": False,
        "items": [
            {"text": "مرحباً بك في IERP" if ar else "Welcome to IERP", "link": f"{prefix}/getting-started/"},
            {"text": "التنقل في لوحة التحكم" if ar else "Dashboard Navigation", "link": f"{prefix}/getting-started/navigation"},
            {"text": "الجداول والبحث والفلاتر" if ar else "Tables, Search & Filters", "link": f"{prefix}/getting-started/tables-search-filters"},
            {"text": "الحالات والإجراءات" if ar else "Statuses & Actions", "link": f"{prefix}/getting-started/statuses-actions"},
            {"text": "الصلاحيات والمساعدة" if ar else "Permissions & Help", "link": f"{prefix}/getting-started/permissions-help"},
        ],
    })
    items.append({
        "text": "مسارات العمل" if ar else "Business Workflows",
        "collapsed": True,
        "items": [
            {"text": wf["ar" if ar else "en"], "link": f"{prefix}/workflows/{wf['slug']}"}
            for wf in WORKFLOWS
        ],
    })
    for group in manifest:
        entries = all_entries_for_group(group)
        grouped: dict[str, list[dict[str, str]]] = {}
        for entry in entries:
            section = entry.get("section") or "other"
            grouped.setdefault(section, [])
            slug = class_slug(entry["class"])
            grouped[section].append({
                "text": localized_entry_label(entry, locale),
                "link": f"{prefix}/screens/{group['key']}/{slug}",
            })
        module_items: list[dict[str, Any]] = [
            {
                "text": "نظرة عامة" if ar else "Overview",
                "link": f"{prefix}/screens/{group['key']}/",
            }
        ]
        section_map = {s["key"]: s for s in group.get("sections", [])}
        for section_key, children in grouped.items():
            if section_key == "overview":
                module_items.extend(children)
                continue
            if section_key == "contextual":
                sec_label = "شاشات سياقية" if ar else "Contextual Screens"
            else:
                sec = section_map.get(section_key)
                sec_label = (sec["label_ar" if ar else "label_en"] if sec else humanize(section_key))
            module_items.append({"text": sec_label, "collapsed": True, "items": children})
        items.append({
            "text": group["label_ar" if ar else "label_en"],
            "collapsed": False,
            "items": module_items,
        })
    items.append({
        "text": "مرجع" if ar else "Reference",
        "collapsed": True,
        "items": [
            {"text": "الحالات والانتقالات" if ar else "Statuses & Transitions", "link": f"{prefix}/reference/statuses"},
            {"text": "ملكية الوحدات" if ar else "Module Ownership", "link": f"{prefix}/reference/ownership"},
            {"text": "المصطلحات" if ar else "Glossary", "link": f"{prefix}/glossary"},
            {"text": "حل المشاكل" if ar else "Troubleshooting", "link": f"{prefix}/troubleshooting/"},
        ],
    })
    name = "arSidebar" if ar else "enSidebar"
    return f"export const {name} = " + json.dumps(items, ensure_ascii=False, indent=2) + "\n"

def home_page(manifest: list[dict[str, Any]], locale: str) -> str:
    ar = locale == "ar"
    title = "دليل استخدام IERP" if ar else "IERP User Guide"
    text = "تشغيل ERP من الإعداد حتى العمليات اليومية" if ar else "Operate IERP from setup to daily execution"
    tagline = (
        "دليل عملي شامل يشرح كل شاشة في لوحة التحكم، الحقول والإجراءات والحالات ومسارات العمل المترابطة بين الوحدات."
        if ar
        else
        "A complete operational guide to every dashboard screen, its fields, actions, statuses and cross-module business workflows."
    )
    action1 = "ابدأ من هنا" if ar else "Get Started"
    action2 = "مسارات العمل" if ar else "Business Workflows"
    prefix = "/ar" if ar else ""
    raw_prefix = "/guide/ar" if ar else "/guide"
    out = [
        "---",
        "layout: home",
        f"title: {title}",
        "titleTemplate: false",
        "hero:",
        f"  name: {title}",
        f"  text: {text}",
        f"  tagline: {tagline}",
        "  actions:",
        "    - theme: brand",
        f"      text: {action1}",
        f"      link: {prefix}/getting-started/",
        "    - theme: alt",
        f"      text: {action2}",
        f"      link: {prefix}/workflows/",
        "---",
        "",
        "## " + ("ماذا تريد أن تنفذ؟" if ar else "What do you want to do?"),
        "",
        '<div class="task-grid">',
    ]
    tasks = [
        ("بيع لعميل", "عرض سعر ← طلب ← تنفيذ ← فاتورة ← تحصيل", "/guide/ar/workflows/quote-to-cash") if ar else ("Sell to a customer", "Quotation → Order → Fulfillment → Invoice → Payment", "/guide/workflows/quote-to-cash"),
        ("الشراء من مورد", "حاجة شراء ← أمر شراء ← تأكيد ← استلام ← دفع", "/guide/ar/workflows/procure-to-pay") if ar else ("Buy from a supplier", "Purchase Need → PO → Confirmation → Receipt → Payment", "/guide/workflows/procure-to-pay"),
        ("معالجة نقص مخزون", "تنبيه أو نقص طلب ← توريد ← استلام ← حجز", "/guide/ar/workflows/sales-shortage-procurement") if ar else ("Cover a stock shortage", "Sales shortage → Procurement → Receipt → Reservation", "/guide/workflows/sales-shortage-procurement"),
        ("إرجاع واسترداد", "مرتجع ← فحص ← مخزون ← إشعار دائن ← استرداد", "/guide/ar/workflows/customer-return-to-refund") if ar else ("Return and refund", "Return → Inspection → Inventory → Credit → Refund", "/guide/workflows/customer-return-to-refund"),
        ("إدارة زيارة موظف", "خطة ← مهمة ← زيارة ← نتيجة ← متابعة", "/guide/ar/workflows/employee-field-sales") if ar else ("Run a field visit", "Plan → Task → Visit → Outcome → Follow-up", "/guide/workflows/employee-field-sales"),
        ("معالجة تذكرة دعم", "تذكرة ← SLA ← إسناد ← خدمة ← حل", "/guide/ar/workflows/support-case") if ar else ("Handle a support case", "Ticket → SLA → Assignment → Service → Resolution", "/guide/workflows/support-case"),
    ]
    for task_title, task_desc, href in tasks:
        out.append(f'<a class="task-card" href="{href}"><strong>{task_title}</strong><span>{task_desc}</span></a>')
    out += ["</div>", "", "## " + ("تصفح حسب الوحدة" if ar else "Browse by module"), "", '<div class="module-grid">']
    for group in manifest:
        label = group["label_ar" if ar else "label_en"]
        href = f"{raw_prefix}/screens/{group['key']}/"
        desc = MODULE_INTRO_AR[group["key"]] if ar else MODULE_INTRO_EN[group["key"]]
        out.append(f'<a class="module-card" href="{href}"><strong>{label}</strong><span>{desc}</span></a>')
    out += [
        "</div>",
        "",
        "## " + ("كيف تقرأ الدليل" if ar else "How this guide is structured"),
        "",
        (
            "كل مقال يطابق شاشة فعلية في لوحة التحكم ويشرح طريقة الوصول، الغرض، الصفحات المسجلة، الحقول، أعمدة الجدول، الفلاتر، الإجراءات، السجلات المرتبطة، الأثر على العمل وأسباب الحظر الشائعة. أما مسارات العمل فتربط هذه الشاشات في عملية كاملة من البداية إلى النهاية."
            if ar
            else
            "Each screen article matches a real dashboard surface and explains navigation, purpose, registered pages, fields, table information, filters, actions, related records, business effects and common blocked states. Workflow articles connect those screens into complete end-to-end processes."
        ),
        "",
        "::: tip نصيحة" if ar else "::: tip",
        ("قد تختلف بعض الإجراءات الظاهرة حسب دور المستخدم وحالة السجل. إذا لم يظهر خيار موضح في الدليل فتحقق من الصلاحيات والمتطلبات السابقة." if ar else "Visible actions can vary by role and record state. If an option described in the guide is missing, check permissions and workflow prerequisites."),
        ":::",
        "",
    ]
    return "\n".join(out)


GLOBAL_SCREENSHOTS = [
    {
        "id": "global-dashboard-shell",
        "module": "getting-started",
        "resource": "dashboard-shell",
        "page": "overview",
        "article": "/getting-started/navigation",
        "title_en": "IERP dashboard shell",
        "title_ar": "هيكل لوحة تحكم IERP",
        "description_en": "Topbar, module switcher, sidebar, page header, search, language and user menu.",
        "description_ar": "الشريط العلوي ومبدّل الوحدات والقائمة الجانبية وعنوان الصفحة والبحث واللغة وقائمة المستخدم.",
        "locales": ["en", "ar"],
        "capture_status": "planned",
        "theme": "light",
        "viewport": "1440x1100",
    },
    {
        "id": "global-module-switcher",
        "module": "getting-started",
        "resource": "module-switcher",
        "page": "overview",
        "article": "/getting-started/navigation",
        "title_en": "Module switcher",
        "title_ar": "مبدّل الوحدات",
        "description_en": "The topbar tabs used to change between the nine IERP business modules.",
        "description_ar": "تبويبات الشريط العلوي المستخدمة للتنقل بين وحدات IERP التسع.",
        "locales": ["en", "ar"],
        "capture_status": "planned",
        "theme": "light",
        "viewport": "1440x1100",
    },
    {
        "id": "global-sidebar",
        "module": "getting-started",
        "resource": "sidebar",
        "page": "overview",
        "article": "/getting-started/navigation",
        "title_en": "Module sidebar",
        "title_ar": "القائمة الجانبية للوحدة",
        "description_en": "The navigation sections and workspace links available inside the active module.",
        "description_ar": "أقسام التنقل وروابط مساحات العمل المتاحة داخل الوحدة الفعالة.",
        "locales": ["en", "ar"],
        "capture_status": "planned",
        "theme": "light",
        "viewport": "1440x1100",
    },
    {
        "id": "global-search",
        "module": "getting-started",
        "resource": "global-search",
        "page": "overview",
        "article": "/getting-started/navigation",
        "title_en": "Global search",
        "title_ar": "البحث العام",
        "description_en": "The dashboard search used to find supported records across modules.",
        "description_ar": "بحث لوحة التحكم المستخدم للعثور على السجلات المدعومة عبر الوحدات.",
        "locales": ["en", "ar"],
        "capture_status": "planned",
        "theme": "light",
        "viewport": "1440x1100",
    },
    {
        "id": "global-table",
        "module": "getting-started",
        "resource": "table",
        "page": "overview",
        "article": "/getting-started/tables-search-filters",
        "title_en": "IERP list table",
        "title_ar": "جدول القوائم في IERP",
        "description_en": "Search, views, filters, sortable headers, rows, actions and pagination on a typical list page.",
        "description_ar": "البحث وطرق العرض والفلاتر والعناوين القابلة للترتيب والصفوف والإجراءات والترقيم في صفحة قائمة نموذجية.",
        "locales": ["en", "ar"],
        "capture_status": "planned",
        "theme": "light",
        "viewport": "1440x1100",
    },
    {
        "id": "global-status-actions",
        "module": "getting-started",
        "resource": "statuses-actions",
        "page": "overview",
        "article": "/getting-started/statuses-actions",
        "title_en": "Statuses and workflow actions",
        "title_ar": "الحالات وإجراءات مسار العمل",
        "description_en": "A demo record's status, available header action and expiry notice.",
        "description_ar": "حالة سجل تجريبي وإجراء العنوان المتاح وتنبيه انتهاء الصلاحية.",
        "locales": ["en", "ar"],
        "capture_status": "planned",
        "theme": "light",
        "viewport": "1440x1100",
    },
    {
        "id": "global-user-menu",
        "module": "getting-started",
        "resource": "user-menu",
        "page": "overview",
        "article": "/getting-started/permissions-help",
        "title_en": "User menu and help",
        "title_ar": "قائمة المستخدم والمساعدة",
        "description_en": "User menu actions including locale, appearance and the standalone User Guide link.",
        "description_ar": "إجراءات قائمة المستخدم بما فيها اللغة والمظهر ورابط دليل الاستخدام المستقل.",
        "locales": ["en", "ar"],
        "capture_status": "planned",
        "theme": "light",
        "viewport": "1440x1100",
    },
]

def workflow_index(locale: str) -> str:
    ar = locale == "ar"
    out = [
        "---",
        f'title: "{"مسارات العمل" if ar else "Business Workflows"}"',
        f"lastVerified: {TODAY}",
        "---",
        "",
        "# " + ("مسارات العمل" if ar else "Business Workflows"),
        "",
        (
            "تشرح هذه الصفحات كيف تنتقل العملية بين وحدات IERP من البداية إلى النهاية. افتح مسارًا عندما يكون هدفك إنجاز عملية عمل كاملة وليس مجرد إدارة سجل واحد."
            if ar
            else
            "These guides explain how work moves across IERP modules from start to finish. Use a workflow guide when your goal is to complete a business process rather than manage one record in isolation."
        ),
        "",
        '<div class="module-grid">',
    ]
    for wf in WORKFLOWS:
        title = wf["ar" if ar else "en"]
        summary = wf["summary_ar" if ar else "summary_en"]
        href = f"/guide/ar/workflows/{wf['slug']}" if ar else f"/guide/workflows/{wf['slug']}"
        out.append(f'<a class="module-card" href="{href}"><strong>{title}</strong><span>{summary}</span></a>')
    out += [
        "</div>",
        "",
        "## " + ("قاعدة أساسية" if ar else "Core rule"),
        "",
        (
            "كل خطوة تُنفذ من الوحدة المالكة لها. لا تستخدم تغيير الحالة اليدوي أو تعديل البيانات مباشرة لتجاوز انتقال عمل بين الوحدات."
            if ar
            else
            "Every step is completed in the module that owns it. Do not use manual status changes or direct data edits to bypass a cross-module transition."
        ),
        "",
    ]
    return "\n".join(out)

def write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

def authored_article(content: str, module: str, slug: str, locale: str) -> str:
    override = GUIDE / "content" / "overrides" / locale / module / f"{slug}.md"
    if not override.exists():
        return content
    frontmatter = content.partition("\n---\n")[0]
    return f"{frontmatter}\n---\n\n{override.read_text(encoding='utf-8').strip()}\n"

def main() -> None:
    manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
    screenshots: list[dict[str, Any]] = []
    coverage_articles: list[dict[str, Any]] = []

    for group in manifest:
        entries = all_entries_for_group(group)
        en_dir = GUIDE / "screens" / group["key"]
        ar_dir = GUIDE / "ar" / "screens" / group["key"]
        en_dir.mkdir(parents=True, exist_ok=True)
        ar_dir.mkdir(parents=True, exist_ok=True)

        (en_dir / "index.md").write_text(module_index(group, "en", entries), encoding="utf-8")
        (ar_dir / "index.md").write_text(module_index(group, "ar", entries), encoding="utf-8")

        for entry in entries:
            slug = class_slug(entry["class"])
            en_content, shots = article_en(group, entry)
            ar_content = article_ar(group, entry)
            en_content = authored_article(en_content, group["key"], slug, "en")
            ar_content = authored_article(ar_content, group["key"], slug, "ar")
            (en_dir / f"{slug}.md").write_text(en_content, encoding="utf-8")
            (ar_dir / f"{slug}.md").write_text(ar_content, encoding="utf-8")
            screenshots.extend(shots)
            fingerprint, source_files = source_fingerprint(entry["class"])
            coverage_articles.append({
                "module": group["key"],
                "class": entry["class"],
                "slug": slug,
                "article_en": f"/screens/{group['key']}/{slug}",
                "article_ar": f"/ar/screens/{group['key']}/{slug}",
                "pages": [s["page"] for s in shots],
                "screenshot_ids": [s["id"] for s in shots],
                "source_files": source_files,
                "source_fingerprint": fingerprint,
            })

    wf_dir = GUIDE / "workflows"
    wf_ar_dir = GUIDE / "ar" / "workflows"
    wf_dir.mkdir(parents=True, exist_ok=True)
    wf_ar_dir.mkdir(parents=True, exist_ok=True)
    (wf_dir / "index.md").write_text(workflow_index("en"), encoding="utf-8")
    (wf_ar_dir / "index.md").write_text(workflow_index("ar"), encoding="utf-8")
    for wf in WORKFLOWS:
        (wf_dir / f"{wf['slug']}.md").write_text(workflow_article(wf, "en"), encoding="utf-8")
        (wf_ar_dir / f"{wf['slug']}.md").write_text(workflow_article(wf, "ar"), encoding="utf-8")

    (GUIDE / ".vitepress" / "sidebar" / "en.ts").write_text(sidebar_ts(manifest, "en"), encoding="utf-8")
    (GUIDE / ".vitepress" / "sidebar" / "ar.ts").write_text(sidebar_ts(manifest, "ar"), encoding="utf-8")
    (GUIDE / "index.md").write_text(home_page(manifest, "en"), encoding="utf-8")
    (GUIDE / "ar" / "index.md").write_text(home_page(manifest, "ar"), encoding="utf-8")

    global_capture_routes = {
        "global-dashboard-shell": {"route_uri": "admin/sales-dashboard", "route_parameters": [], "model": None},
        "global-module-switcher": {"route_uri": "admin/sales-dashboard", "route_parameters": [], "model": None},
        "global-sidebar": {"route_uri": "admin/sales-dashboard", "route_parameters": [], "model": None},
        "global-search": {"route_uri": "admin/sales-dashboard", "route_parameters": [], "model": None},
        "global-table": {"route_uri": "admin/quotations", "route_parameters": [], "model": None},
        "global-status-actions": {"route_uri": "admin/quotations/{record}", "route_parameters": ["record"], "model": "App\\Models\\Quotation"},
        "global-user-menu": {"route_uri": "admin/sales-dashboard", "route_parameters": [], "model": None},
    }
    for shot in GLOBAL_SCREENSHOTS:
        shot.update(global_capture_routes[shot["id"]])

    screenshots.extend(GLOBAL_SCREENSHOTS)
    screenshots = unique(screenshots)
    verified_screenshots = json.loads(VERIFIED_SCREENSHOTS_PATH.read_text(encoding="utf-8"))
    for shot in screenshots:
        images = verified_screenshots.get(shot["id"], {})
        if images:
            shot["images"] = images
            shot["capture_status"] = "captured" if all(locale in images for locale in shot["locales"]) else "planned"
    write_json(GUIDE / "content" / "screenshots.manifest.json", screenshots)

    captured_count = sum(shot["capture_status"] == "captured" for shot in screenshots)
    capture_status = "planned"
    if verified_screenshots:
        capture_status = "captured" if captured_count == len(screenshots) else "partial"

    coverage = {
        "generated_at": TODAY,
        "module_count": len(manifest),
        "article_count": len(coverage_articles),
        "screen_count": len(screenshots),
        "workflow_count": len(WORKFLOWS),
        "articles": coverage_articles,
        "rules": {
            "every_manifest_class_has_bilingual_article": True,
            "every_registered_resource_page_has_screenshot_placeholder": True,
            "screenshots_embedded": bool(verified_screenshots),
            "screenshots_capture_status": capture_status,
        },
    }
    write_json(GUIDE / "content" / "coverage.json", coverage)
    print(f"Generated {len(coverage_articles)} bilingual screen articles")
    print(f"Tracked {len(screenshots)} dashboard screenshots")
    print(f"Generated {len(WORKFLOWS)} bilingual workflow articles")

if __name__ == "__main__":
    main()
