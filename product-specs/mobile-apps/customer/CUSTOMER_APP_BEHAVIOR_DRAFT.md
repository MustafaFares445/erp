# توصيف تطبيق العميل — مسودة للمناقشة

**الحالة:** Draft — غير معتمد بعد  
**النطاق المقترح:** Customer Mobile App V1  
**تاريخ المراجعة:** 2026-09-19  
**مصدر الحقيقة:** الكود الحالي على فرع `dev`، الـ Models، Services، Policies، Routes، وواجهات الإدارة الحالية.  
**مهم:** لم يتم استخدام المواصفات القديمة لتحديد السلوك.

## 1. الهدف

تطبيق العميل هو القناة التي تربط العميل مباشرةً مع دورة المبيعات وما بعد البيع داخل IERP.

الهدف المقترح:
- التسجيل وطلب فتح حساب عميل.
- متابعة حالة تفعيل الحساب.
- عرض كتالوج المنتجات والأسعار الخاصة بالعميل.
- متابعة العروض التجارية Quotations.
- متابعة Sales Orders وتنفيذها وشحناتها.
- تأكيد استلام الشحنة.
- متابعة الفواتير والمبالغ المستحقة والمدفوعات.
- متابعة Credit Notes وRefunds.
- إدارة الدعم الفني والضمان والصيانة.
- التواصل داخل Tickets.
- استقبال الإشعارات المرتبطة بالحساب.

التطبيق لا يدير Inventory أو Warehouse أو Accounting مباشرة.
## 2. ما هو موجود فعلياً حالياً

النظام يحتوي فعلياً على:
- User من نوع Customer.
- CustomerProfile مرتبط بالحساب.
- Self-registration عبر `/join-us`.
- تفعيل/تعطيل CustomerProfile.
- بيانات شركة وموقع وجهات اتصال.
- مستندات قانونية خاصة بالعميل.
- Delivery Addresses متعددة.
- Product catalog وVariants وصور.
- Customer-specific pricing tiers.
- Quotations.
- Sales Orders.
- Shipments وtracking number.
- Customer shipment-arrival confirmation.
- Invoices وPDF.
- Payments وpayment proof.
- Credit Notes وRefunds.
- Support Tickets وattachments/messages.
- Customer-owned serialized equipment.
- Warranty state.
- Maintenance records.
- Notification infrastructure.

## 3. فجوة قناة العميل الحالية

حالياً لا يوجد Customer Mobile API مكتمل.

`bootstrap/app.php` لا يسجل `routes/api.php`، والسياسات الحالية للـOrders/Quotations/Tickets/Payments مبنية لقناة Dashboard وليس لملكية العميل.
لذلك تطبيق العميل يحتاج API مستقلة تفرض ownership من خلال المستخدم المصادق عليه وCustomerProfile المرتبط به.

لا يجوز أن يرسل التطبيق `customer_id` ويعتمد عليه السيرفر كمرجع للملكية.

## 4. التسجيل وفتح الحساب

السلوك الموجود حالياً:
1. العميل يسجل بيانات الحساب بنفسه.
2. يدخل بيانات الشركة.
3. يدخل الدولة/المدينة والعنوان والموقع الجغرافي.
4. يدخل بيانات المحاسب إن وجدت.
5. يحدد جهة الاتصال الرئيسية.
6. يرفع المستندات المطلوبة.
7. يختار كلمة المرور أثناء التسجيل.
8. ينشأ CustomerProfile بحالة غير فعالة.
9. الإدارة تراجع الحساب وتفعله لاحقاً.

التطبيق المقترح يعيد استخدام نفس المنطق من خلال Customer API بدلاً من إنشاء onboarding مختلف.

بعد Submit تظهر شاشة:
- Registration received.
- Account under review.
- لا يسمح باستخدام الأعمال التجارية حتى تفعيل الحساب.

قرار مطلوب: هل نسمح بتسجيل الدخول للحساب غير المفعل لرؤية حالة المراجعة فقط، أم يمنع Login بالكامل حتى التفعيل؟
## 5. تسجيل الدخول والحساب

بعد التفعيل:
- يسجل العميل الدخول باستخدام username/email + password.
- يمنع الدخول التجاري إذا CustomerProfile غير فعال أو مؤرشف.
- التطبيق يعرض بيانات الشركة والحساب.
- تغيير كلمة المرور.
- Forgot Password flow.
- Logout.
- Push token lifecycle عند اعتماد Push.

صفحة الحساب تعرض:
- Customer code.
- Company name.
- Main account user.
- Company phone/email.
- Address.
- Primary contact.
- Accountant contact.
- Legal documents status.
- Delivery addresses.

قرار مطلوب:
هل يستطيع العميل تعديل بيانات الشركة والمستندات مباشرة، أم يرسل Change Request تحتاج موافقة الإدارة؟

## 6. التنقل المقترح

Bottom Navigation:
1. الرئيسية
2. المنتجات
3. الطلبات
4. الدعم
5. الإشعارات

الحساب يفتح من Avatar/Profile في أعلى التطبيق.

داخل الطلبات توجد تبويبات:
- Quotations
- Orders
- Invoices & Payments
## 7. الشاشة الرئيسية

الصفحة الرئيسية يجب أن تركز على ما يحتاج انتباه العميل الآن:

- حالة الحساب إذا ما زال تحت المراجعة.
- Quotation جديد/منتهي قريباً.
- Order جاري تنفيذه.
- Shipment في الطريق.
- Shipment يحتاج Confirm Arrival.
- Invoice مستحقة أو Overdue.
- Ticket يحتاج رد العميل.
- Maintenance due إن وجدت.
- Recent notifications.

ملخصات اختيارية:
- Open quotations.
- Active orders.
- Outstanding balance.
- Open support tickets.

لا تعرض للعميل KPIs داخلية مثل procurement blockers التقنية أو أسماء المستودعات الداخلية إلا إذا احتاجها كسياق عملي.

## 8. كتالوج المنتجات

الكود الحالي يسمح بعرض:
- Product name EN/AR.
- Description.
- Category.
- Brand.
- Product images.
- Variants.
- SKU.
- Units.
- Warranty duration.
- Operational status.

الـPriceResolver يستطيع إرجاع السعر الخاص بهذا العميل وفق Pricing Tier.
لذلك Product Catalog في التطبيق يجب أن يعرض السعر resolved من السيرفر، وليس حساب الخصومات محلياً.

لا يجب كشف:
- cost price.
- minimum price.
- markup.
- supplier references.
- warehouse balances الداخلية.
- price floor rules.

قرار مطلوب:
ما هو CTA الأساسي للمنتج في V1؟
- Request Quotation.
- Add to Quote Request cart.
- Create Order مباشرة.
- أو عرض فقط والتواصل مع Sales.

## 9. Quotations

العميل يشاهد فقط Quotations التابعة له.

التفاصيل:
- quotation number.
- issue/expiry dates.
- employee/sales contact إذا قررنا إظهاره.
- lines.
- quantities/units.
- prices.
- taxes.
- grand total.
- payment terms.
- notes.
- status.
- PDF download.

الحالة الحالية تدعم Draft / Sent / Accepted / Rejected / Expired / Converted / Cancelled.
قاعدة الكود الحالية تقول صراحةً إن العميل لا يملك accept/reject route؛ Admin أو Employee يسجل القرار بالنيابة عنه.

قرار مطلوب:
هل نغيّر هذا في تطبيق العميل ليصبح العميل نفسه قادراً على:
- Accept Quotation.
- Reject Quotation مع reason.
- Request Changes / Requote.

إذا تم اعتماد ذلك، يجب أن يصبح قرار العميل هو evidence رسمي ويسجل user/time/source_channel.

## 10. Orders

العميل يشاهد Sales Orders الخاصة به فقط.

التطبيق لا يحتاج عرض statuses الداخلية الخام فقط، لأن `OrderWorkflowService` يحسب business milestone أوضح.

المقترح عرض:
- Order number.
- Date.
- Total.
- Payment status.
- Delivery address.
- Products/quantities.
- Overall fulfillment progress.
- Business milestone.
- Shipments.
- Invoices.
- Payments/credits.
- Timeline.

Customer-facing milestones:
Draft, Awaiting Release, Supply Blocked, Awaiting Logistics Allocation, Partially Allocated, Ready to Dispatch, In Transit, Invoice Pending, Payment Pending, Delivered, Closed, Cancelled.
يفضل إعادة صياغة بعض milestones التقنية للمستخدم بلغة تجارية أبسط، مثلاً:
- Supply Blocked -> Awaiting stock availability.
- Awaiting Logistics Allocation -> Preparing delivery.
- Invoice Pending -> Delivery completed; invoice is being prepared.

الـbackend يبقى المصدر للحالة.

قرار مطلوب:
هل يستطيع العميل إنشاء Sales Order مباشرة من التطبيق، أم الـOrder ينشأ فقط بعد Quotation/موظف المبيعات؟

## 11. Shipments وتتبع التسليم

Shipment يحتوي:
- tracking number.
- status.
- order.
- confirmed_at.
- confirmation source.

الحالات:
- Planned.
- In Transit.
- Arrived.
- Cancelled.

الكود يدعم فعلياً `confirmByCustomer()`.

السلوك المقترح:
- العميل يشاهد Shipment الحالية.
- يرى Tracking Number.
- يرى الحالة.
- عند In Transit يظهر Confirm Delivery فقط عند وصول البضاعة فعلياً.
- Confirm Delivery يحتاج confirmation screen واضح.
- بعد التأكيد يسجل العميل والتاريخ.
- لا يمكن تكرار التأكيد.
تأكيد وصول الشحنة مهم أيضاً لأنه يبدأ Warranty للمنتجات serialized حسب السلوك الحالي.

قرار مطلوب:
هل نحتاج OTP/Signature/Photo كدليل إضافي عند Confirm Delivery، أم يكفي تأكيد العميل داخل التطبيق؟

## 12. Delivery Addresses

العميل لديه عدة عناوين مع Default address.

التطبيق المقترح يسمح:
- List addresses.
- Add address.
- Edit address.
- Set default.
- Disable/remove address إذا لم يكن مرتبطاً بقيود تاريخية.

كل عنوان يحتوي:
- label.
- address.
- country/city.
- map coordinates.
- contact name/phone.

أي Order جديد أو Quote Request يحتاج اختيار عنوان التسليم المناسب عندما يكون ذلك مطلوباً.
## 13. Invoices

العميل يشاهد Invoices الخاصة به فقط.

التفاصيل:
- invoice number.
- related order أو maintenance record.
- invoice date.
- due date.
- lines.
- subtotal/tax/total.
- amount paid.
- credited amount.
- outstanding amount.
- status.
- PDF download.

Issued invoices تعتبر frozen تجارياً في النظام الحالي.

يوجد حالياً مفهوم `CustomerReceived` لتأكيد استلام الفاتورة.

السلوك المقترح:
- عند وصول Invoice جديدة يظهر Notification.
- العميل يفتح الفاتورة ويشاهد PDF/details.
- يمكنه Confirm Invoice Received إذا اعتمدنا Customer API لهذا المسار.
- يسجل السيرفر user/time/type.
- التأكيد لا يعني أن الفاتورة مدفوعة.

قرار مطلوب:
هل تريد تأكيد استلام Invoice من التطبيق، أم يكفي اعتبار فتحها/تنزيلها بدون Action رسمي؟
## 14. Payments

التطبيق يجب أن يعرض:
- Payment history.
- payment number.
- amount/currency.
- payment method.
- payment date.
- status.
- invoice allocations.
- payment proof إذا كان من المناسب إظهاره.
- outstanding balance.

الكود الحالي لا يحتوي General Customer Online Payment Gateway.

الـPaymentService الحالي ينشئ ويفعّل المدفوعات من قناة الإدارة ثم يطبق Accounting وInvoice allocations.

لذلك لا يجب تنفيذ زر "Pay Now" حقيقي بدون اختيار provider وتدفق محاسبي متكامل.

خيارات V1 الممكنة:
1. View-only payments + outstanding invoices.
2. Submit Payment Proof: العميل يختار invoice/amount/method ويرفع إثبات، ثم الإدارة تراجعه وتحوّله إلى Payment.
3. External payment link/provider.

قرار مطلوب:
أي Payment Flow تريد في الإصدار الأول؟
## 15. Credit Notes وRefunds

العميل يشاهد:
- Credit Notes التابعة له.
- سبب الخصم/التصحيح.
- invoice reference.
- amount.
- status.
- PDF إذا توفر.
- Refunds.
- refund amount.
- payment method.
- status: Draft / Approved / Paid حسب السجل.

هذه سجلات مالية read-only للعميل في V1 المقترح.

لا يسمح للعميل بإنشاء Credit Note أو Refund مباشرة.

إذا كان العميل يريد إرجاع منتج، يبدأ من Return Request أو Support flow ثم الإدارة تنفذ المعالجة المالية/المخزنية.

## 16. Returns

الـbackend الحالي لديه Customer Inventory Return قوي لكنه عملية داخلية مرتبطة بمستودع وفحص disposition وlot/serial.

لا يجب كشف هذا workflow للعميل.

المقترح في الموبايل:
- العميل يبدأ Return Request بسيط.
- يختار Order/Delivery.
- يختار المنتجات والكميات المراد إرجاعها.
- يحدد reason.
- يرفع صور/مرفقات.
- يرسل الطلب للمراجعة.
بعد ذلك تقوم الإدارة/اللوجستيات بإنشاء Inventory Return الفعلي وتنفيذ الفحص والاستلام وCredit Note.

فجوة حالية:
لا يوجد Customer Return Request model مستقل في الكود.

قرار مطلوب:
هل تريد Returns داخل V1، أم يكفي إنشاء Support Ticket من نوع Return/Commercial Issue في الإصدار الأول؟

## 17. My Equipment & Warranty

هذه ميزة قوية يدعمها الكود الحالي للمنتجات serialized الموجودة في Customer custody.

التطبيق المقترح يعرض:
- Product/variant.
- serial number.
- warranty status.
- warranty started on.
- warranty expires on.
- related delivery/order إن أمكن.
- Open Support Ticket CTA.

Warranty states:
- Covered.
- Expired.
- Not Covered.
- Not Applicable.
- Unknown.

لا نعرض Supplier Warranty كاستحقاق للعميل إلا إذا أصبح جزءاً صريحاً من customer-facing policy لاحقاً.
## 18. Support Tickets

تطبيق العميل المقترح يجب أن يسمح بإنشاء Ticket لأن Ticket مرتبط أصلاً بالـCustomerProfile.

Customer intake:
- Ticket type.
- Title.
- Description.
- Attachments.
- اختيار Equipment من My Equipment أو تحديد External Equipment إذا اعتمدنا ذلك.
- Priority يمكن أن تكون hidden/default أو customer-selected حسب القرار.

بعد الإرسال:
- Status Pending.
- Support triage يحدد equipment provenance.
- يتحقق من Warranty.
- يحدد Service Path.
- يحدد إن كانت الخدمة Chargeable.
- ينتقل Ticket إلى Live أو Pending Payment.

العميل لا يقرر Warranty status بنفسه.

## 19. Ticket Conversation

TicketMessage الحالي append-only.

التطبيق يعرض conversation زمنية:
- رسائل Support للعميل.
- رسائل العميل.
- attachments عند الحاجة.
- timestamps.
- unread/read state في الـmobile layer.

Internal Notes الخاصة بفريق الدعم لا تظهر للعميل.
عندما تكون الحالة Waiting Customer، يجب أن يظهر CTA واضح للرد.

Customer reply ينشئ رسالة جديدة ولا يعدل الرسائل السابقة.

فجوة حالية:
TicketPolicy الحالي لا يمنح Customer user حق message؛ نحتاج Customer-specific ownership authorization.

## 20. Chargeable Support

بعد Triage قد يصبح Ticket في Pending Payment.

التطبيق يعرض:
- أن الخدمة تحتاج دفع قبل بدء العمل.
- amount.
- currency.
- سبب/وصف تجاري إن توفر.
- payment status.
- payment_url إذا أصبح provider/link متاحاً.

الكود الحالي يحتوي TicketPaymentLink لكنه لا يحتوي Payment Gateway حقيقي.

قرار مطلوب:
هل Chargeable Support في V1 يدفع داخل التطبيق، عبر رابط خارجي، أم يتواصل العميل مع الإدارة ويتم settlement يدوياً؟

## 21. Maintenance

MaintenanceRecord مرتبط بالCustomer وقد ينتج من Ticket.

العميل يمكنه مبدئياً مشاهدة:
- maintenance request/service reference.
- equipment.
- warranty snapshot.
- status.
- billing type.
- related quotation/invoice.
- service records summary عندما تكون مناسبة للعميل.

قرار مطلوب:
هل نعرض Maintenance كقسم مستقل في V1، أم داخل Ticket Details فقط؟

## 22. Notifications

الإشعارات customer-facing المقترحة:
- Account approved/rejected/needs changes.
- New quotation.
- Quotation expiring.
- Quotation decision/change.
- Order confirmed/released.
- Order fulfillment milestone changed.
- Shipment dispatched/in transit.
- Shipment requires arrival confirmation.
- Invoice issued.
- Invoice due soon / overdue.
- Payment received/posted.
- Credit note issued.
- Refund approved/paid.
- Ticket updated.
- New support message.
- Ticket waiting for customer.
- Ticket payment required.
- Maintenance scheduled/due/billed.
- Security/password events.

In-App Notifications مطلوبة، وPush يحتاج Device Token infrastructure جديد مثل Employee App.
## 23. Home Action Priorities

الـHome لا تكون dashboard محاسبية كثيفة.

ترتيب الـCTA المقترح:
1. Security/account action.
2. Quotation needs decision أو expiring.
3. Shipment needs confirmation.
4. Invoice due/overdue.
5. Ticket waiting customer.
6. Ticket payment required.
7. Next maintenance due.
8. Order in transit.
9. Recent activity.

الهدف أن يعرف العميل "ما المطلوب مني الآن؟" وليس فقط يرى أرقاماً.

## 24. Security وData Ownership

كل Customer API يجب أن تفرض:
- UserType = Customer.
- CustomerProfile موجود ومرتبط بنفس user.
- profile active للعمليات التجارية.
- ownership على كل quotation/order/invoice/payment/ticket/shipment.
- لا يقبل customer_id من الموبايل لتغيير scope.
- media downloads private وموقعة/محمية.
- legal documents private.
- idempotency للقرارات الحساسة.
- audit source = `customer_app`.
- rate limiting للتسجيل/login/messages/uploads.
## 25. API المطلوبة

Customer Mobile API تحتاج على الأقل:
- Registration.
- Registration status.
- Login/logout/password reset.
- Profile/company details.
- Customer documents status.
- Delivery addresses.
- Product catalog/categories/variants.
- Customer-resolved prices.
- Quotations list/detail/PDF.
- Optional quotation decision.
- Orders list/detail/progress.
- Shipments list/detail/confirm-arrival.
- Invoices list/detail/PDF.
- Optional invoice receipt confirmation.
- Payments/history/outstanding.
- Optional payment-proof submission/provider payment.
- Credit notes/refunds.
- My equipment/warranties.
- Tickets create/list/detail.
- Ticket messages/reply.
- Ticket attachments.
- Maintenance summary.
- Notifications/mark read.
- Push token registration.

Controllers يجب أن تستخدم Services/domain rules الحالية أو Customer-channel services مشتركة، لا تنسخ منطق النظام.
## 26. Screen Inventory المقترح

Authentication:
- Splash / Session Check.
- Sign In.
- Join Us / Registration.
- Company Details.
- Location.
- Contacts.
- Documents Upload.
- Registration Submitted.
- Account Under Review.
- Forgot/Reset Password.

Main:
- Home.
- Product Catalog.
- Product Details.
- Variant Selection.
- Quote Request/Cart — إذا اعتمد.
- Orders Hub.
- Notifications.
- Profile.

Commercial:
- Quotations List.
- Quotation Details.
- Quotation PDF.
- Quotation Decision — إذا اعتمد.
- Orders List.
- Order Details/Timeline.
- Shipment Details.
- Confirm Delivery.
Financial:
- Invoices List.
- Invoice Details.
- Invoice PDF.
- Payments List.
- Payment Details.
- Outstanding Balance.
- Submit Payment Proof — إذا اعتمد.
- Credit Notes.
- Refunds.

Support:
- My Equipment.
- Equipment/Warranty Details.
- Tickets List.
- Create Ticket.
- Ticket Details.
- Ticket Conversation.
- Payment Required.
- Maintenance Details.
- Return Request — إذا اعتمد.

Account:
- Company Profile.
- Contacts.
- Legal Documents.
- Delivery Addresses.
- Security.
- Notification Preferences.

## 27. Backend/Admin changes المطلوبة

قبل بناء التطبيق نحتاج:
- Customer API auth/token layer.
- Customer ownership policies.
- Customer-safe API resources.
- Customer registration API فوق CustomerOnboardingService.
- Admin onboarding review state/API feedback.
- Catalog endpoint مع PriceResolver per authenticated customer.
- Customer media endpoints/PDF signed access.
- Shipment customer-confirmation endpoint.
- Customer notification push tokens.
- Ticket customer creation/message ownership path.
- Hide support internal notes.
- Equipment/warranty customer endpoints.
- Optional quotation-decision service path for customer.
- Optional invoice receipt confirmation for customer.
- Payment proof request workflow أو provider integration.
- Optional Return Request domain.
- Audit `source_channel=customer_app`.
- Feature/ownership/security tests لكل endpoint.

## 28. القرارات المطلوبة قبل اعتماد V1

1. هل العميل يسجل حسابه بنفسه من التطبيق كما `join-us` الحالي، أم الحساب تنشئه الإدارة؟
2. الحساب غير المفعل: هل يستطيع Login فقط لرؤية "Under Review"، أم يمنع الدخول كلياً؟
3. هل حساب العميل يعمل على جهاز واحد فقط مثل Employee App، أم عدة أجهزة؟
4. هل العميل يستطيع تعديل بيانات الشركة والمستندات، أم Change Request بموافقة الإدارة؟
5. هل Product Catalog جزء أساسي من V1؟
6. ما Action المنتج: Request Quotation، Quote Cart، Direct Order، أم View Only؟
7. هل العميل يستطيع Accept/Reject Quotation بنفسه؟ وهل نضيف Request Changes؟
8. هل Sales Order يمكن إنشاؤه من العميل مباشرة أم فقط من Quotation/Sales team؟
9. Confirm Delivery: هل يكفي زر تأكيد، أم نحتاج OTP/Signature/Photo؟
10. هل العميل يؤكد استلام Invoice رسمياً من التطبيق؟
11. ما Payment Flow في V1: View Only، Upload Proof، External Link، أم Online Gateway؟
12. هل Returns تدخل V1؟ وإذا نعم هل نضيف Return Request مستقل؟
13. هل العميل ينشئ Support Ticket مباشرة؟
14. عند إنشاء Ticket: هل يختار Equipment/Serial بنفسه أم يترك ذلك للـSupport triage؟
15. هل Priority يحددها العميل أم النظام/Support فقط؟
16. هل Chargeable Support يتم دفعه من التطبيق أم خارج التطبيق؟
17. هل Maintenance يظهر كقسم مستقل أم ضمن Support/Ticket فقط؟
18. هل نعرض Warranty/Serials كقسم "My Equipment" مستقل؟
19. هل Push Notifications مطلوبة من الإصدار الأول؟
20. هل التطبيق Arabic + English من أول إصدار؟

## 29. شرط الاعتماد

هذه الوثيقة تبقى Draft حتى يتم حسم القرارات السابقة.

بعد إجاباتك سنصدر `CUSTOMER_APP_V1_APPROVED_SPEC.md` بنفس طريقة تطبيق الموظف، ثم نحولها إلى حزمة pen.dev كاملة: IA، User Flows، Screen Contracts، Components، States/Microcopy، Master Prompt، Build Sequence، وCustom Skill.
