---
title: "طرق الدفع"
module: accounting
pages: ["index", "create", "view", "edit"]
screenshots: ["accounting-payment-method-index", "accounting-payment-method-create", "accounting-payment-method-view", "accounting-payment-method-edit"]
lastVerified: 2026-10-07
---

# طرق الدفع وربط الحساب البنكي

<IerpPageSummary purpose="ضبط وسائل الدفع وربطها بحسابات التحصيل المحاسبية." useWhen="تهيئة تحصيل يدوي أو دفع مورد أو مطابقة كشف بنكي." owner="الإعدادات المشتركة والمحاسبة" />

## متى تستخدم هذه الصفحة؟

تهيئة تحصيل يدوي أو دفع مورد أو مطابقة كشف بنكي.

## المهام الشائعة

- راجع قسم «قائمة طرق الدفع» واتبع التعليمات المحددة فيه.
- راجع قسم «إضافة طريقة دفع» واتبع التعليمات المحددة فيه.
- راجع قسم «تفاصيل طريقة الدفع» واتبع التعليمات المحددة فيه.

<IerpScreenshot id="accounting-payment-method-index" title="قائمة طرق الدفع" description="الوسائل المتاحة والنشطة." imageSrc="/images/ar/accounting/accounting-payment-method-index.png" />
<IerpScreenshot id="accounting-payment-method-create" title="إضافة طريقة دفع" description="حدد الوسيلة والربط المحاسبي." imageSrc="/images/ar/accounting/accounting-payment-method-create.png" />
<IerpScreenshot id="accounting-payment-method-view" title="تفاصيل طريقة الدفع" description="راجع الحساب المرتبط وسياق الاستخدام." imageSrc="/images/ar/accounting/accounting-payment-method-view.png" />
<IerpScreenshot id="accounting-payment-method-edit" title="تعديل طريقة الدفع" description="عدّل الإعدادات المسموحة وفق الصلاحية." imageSrc="/images/ar/accounting/accounting-payment-method-edit.png" />

تتطلب الدفعة اليدوية وسيلة نشطة. يسجل ترحيل التحصيل جانبًا مدينًا في حساب التحصيل المرتبط؛ واستيراد كشف البنك يشترط حسابًا نشطًا يقبل الترحيل. اضبط الوسيلة قبل إجراء الحركات ولا تغيّر ربطها لإخفاء أثر قديم. تحقق من متطلبات الإثبات والربط قبل الدفع، ولا تخلط تنفيذ Stripe مع التأشير اليدوي على الاستلام.
