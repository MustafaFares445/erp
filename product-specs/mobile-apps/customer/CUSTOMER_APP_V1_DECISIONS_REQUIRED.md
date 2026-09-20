# Customer App V1 — Decisions Required

هذه القرارات فقط هي التي ما زالت تمنع تحويل المسودة إلى `CUSTOMER_APP_V1_APPROVED_SPEC.md`.

## A. الحساب والتسجيل

1. إنشاء الحساب:
   - A: العميل يسجل بنفسه من التطبيق باستخدام نفس منطق Join Us الحالي.
   - B: الإدارة تنشئ الحساب فقط.

2. الحساب Under Review:
   - A: يسمح Login محدود لرؤية حالة الطلب فقط.
   - B: يمنع Login حتى موافقة الإدارة.

3. الأجهزة:
   - A: جهاز واحد فقط مثل Employee App.
   - B: عدة أجهزة للحساب.

4. تعديل بيانات الشركة والمستندات:
   - A: تعديل مباشر.
   - B: Change Request يحتاج موافقة الإدارة.

## B. المنتجات والمبيعات

5. Product Catalog في V1؟
   - Yes / No.

6. CTA الأساسي للمنتج:
   - A: Request Quotation.
   - B: Add to Quote Request Cart.
   - C: Direct Order.
   - D: View Only / Contact Sales.
7. قرار الـQuotation:
   - A: العميل يرى فقط؛ Sales/Admin يسجل القرار كما هو حالياً.
   - B: العميل Accept / Reject بنفسه.
   - C: العميل Accept / Reject / Request Changes.

8. إنشاء Sales Order:
   - A: فقط من Quotation/Sales Team.
   - B: العميل يستطيع Direct Order.

## C. التسليم والفواتير

9. Confirm Delivery evidence:
   - A: Confirm button فقط.
   - B: OTP.
   - C: Signature.
   - D: Photo.
   - E: أكثر من دليل.

10. Invoice receipt:
   - A: View/download فقط.
   - B: Confirm Invoice Received رسمياً.

## D. الدفع والمرتجعات

11. Payment V1:
   - A: View Only.
   - B: Upload Payment Proof.
   - C: External Payment Link.
   - D: Online Payment Gateway.

12. Returns:
   - A: خارج V1.
   - B: Support Ticket فقط.
   - C: Return Request workflow مستقل.
## E. الدعم والضمان

13. Customer Support Ticket creation:
   - Yes / No.

14. Equipment عند Ticket:
   - A: العميل يختار My Equipment/Serial أو External Equipment.
   - B: Support يحدد المعدات أثناء Triage فقط.

15. Priority:
   - A: العميل يحددها.
   - B: النظام/Support يحددها فقط.

16. Chargeable Support:
   - A: settlement خارج التطبيق.
   - B: External Payment Link.
   - C: Online payment داخل التطبيق.

17. Maintenance:
   - A: قسم مستقل.
   - B: يظهر داخل Support/Ticket فقط.

18. My Equipment & Warranty:
   - A: قسم مستقل في V1.
   - B: يظهر فقط أثناء إنشاء Support Ticket.

## F. تجربة التطبيق

19. Push Notifications في V1:
   - Yes / No.

20. اللغات من أول إصدار:
   - A: English فقط.
   - B: Arabic فقط.
   - C: Arabic + English.
