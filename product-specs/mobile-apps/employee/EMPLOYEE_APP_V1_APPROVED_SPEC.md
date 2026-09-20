# مواصفات تطبيق الموظف الميداني — الإصدار الأول المعتمد

**الحالة:** Approved Behavior  
**النطاق:** Sales Field Employees فقط  
**التاريخ:** 2026-09-19  
**مصدر الحقيقة:** الكود الحالي على فرع `dev` وبنية قاعدة البيانات والـ Models والـ Services وواجهات Filament الحالية.  
**مهم:** المواصفة لا تعتمد على الـ SRS أو المواصفات القديمة كسلوك تنفيذي.

## 1. الهدف

تطبيق الموظف هو قناة العمل الميداني لموظف المبيعات، ويرتبط مباشرة بنفس بيانات ولوحة إدارة IERP.

الهدف من الإصدار الأول:
- عرض خطة الموظف ومهامه وزياراته.
- إدارة تنفيذ المهام الميدانية.
- إثبات مدة ومكان الزيارة من Check In حتى Check Out.
- تسجيل مخرجات الزيارة.
- تسجيل الصوت وتحويله إلى نص وتحليله.
- استخراج Outcome وفرص بيع المنتجات بشكل آلي.
- إلزام الموظف بمراجعة وتصحيح النتائج قبل Submit النهائي.
- إنشاء ومتابعة Quotations من التطبيق.
- عرض الأداء والراتب والبونص المرتبطين بالموظف.
- استقبال إشعارات العمل ورد الموظف على ملاحظات الإدارة.

لا ينشئ تطبيق الموبايل Workflow موازياً؛ كل عملية تكتب في نفس سجلات النظام التي تستخدمها لوحة الإدارة.

## 2. نطاق الإصدار الأول

داخل النطاق:
- Employee account وFirst Login.
- ربط الحساب بجهاز واحد.
- Monthly Sales Plan.
- Plan Tasks.
- Customer Visits.
- Check In / Check Out.
- GPS trail أثناء الزيارة فقط.
- Visit Outcome.
- Attachments.
- Voice Notes + Transcription + AI review.
- Product sales opportunities.
- Quotations.
- Performance.
- Salary وBonus.
- Notifications.
- Admin review conversation.
- Profile والأمان.

خارج النطاق في V1:
- Support Tickets.
- Maintenance / Service Records.
- تتبع الموظف خارج الزيارة.
- Warehouse fulfillment.
- Inventory receiving أو stock movements.
- Logistics delivery posting.
- Check In أو Check Out بدون إنترنت.

## 3. تسجيل الدخول وربط الجهاز

- الحساب ينشأ من لوحة الإدارة فقط.
- الإدارة تنشئ Temporary Password للموظف.
- الموظف يدخل أول مرة بالبيانات المؤقتة.
- بعد نجاح أول Login يجب تغيير كلمة المرور قبل الوصول لبقية التطبيق.
- أول جهاز يكمل عملية First Login يصبح الجهاز المرتبط بالحساب.
- يسمح بحساب الموظف على جهاز واحد فعال فقط.
- تسجيل الدخول من جهاز آخر يرفض حتى يتم Reset Device Binding من لوحة الإدارة.
- عند تغيير الجهاز أو فقدانه أو حدوث مشكلة، يتواصل الموظف مع الدعم الفني.
- لوحة الإدارة توفر Action لإزالة ربط الجهاز.
- Reset Password من الإدارة ينشئ Temporary Password جديدة ويعيد فرض تغييرها.
- تعطيل أو أرشفة الموظف يمنع الدخول ويلغي الجلسات الفعالة.
- Reset Password وReset Device Binding يجب تسجيلهما في Audit Log.

## 4. التنقل الرئيسي

Bottom Navigation:
1. الرئيسية
2. المهام
3. الزيارات
4. المبيعات
5. الإشعارات
6. الحساب

لا يظهر Support أو Maintenance في هذا الإصدار.

## 5. الرئيسية والخطة والمهام

الرئيسية تعرض:
- الخطة الشهرية الفعالة.
- مهام اليوم والمتأخرة والقريبة.
- زيارات اليوم والزيارة الجارية.
- CTA للخطوة التالية.
- ملخص الأداء.
- الإشعارات غير المقروءة.

الخطة الشهرية Read-only للموظف وتعرض الاسم والشهر والحالة والمهام والزيارات والحد الأدنى المطلوب لمدة الزيارة وملخص الأداء.

### صلاحيات Task في التطبيق

الموظف يستطيع فقط:
- Start: Pending -> InProgress.
- Complete: InProgress -> Completed.

لا يستطيع من التطبيق:
- Cancel.
- Reopen.
- Back to Pending.

هذه العمليات الإدارية تبقى في لوحة التحكم. كل تغيير حالة يسجل في TaskStatusLog وAudit Log مع مصدر `employee_app`.

## 6. الزيارة قبل Check In

صفحة الزيارة تعرض:
- العميل والشركة.
- بيانات الاتصال اللازمة للعمل.
- العنوان وموقع العميل على الخريطة.
- وقت الزيارة المخطط.
- المهمة المرتبطة.
- حالة الزيارة.
- Required Visit Minutes.
- زر فتح الطريق في تطبيق الخرائط.
- تنبيه أن تتبع الموقع سيعمل من Check In حتى Check Out.

## 7. Check In وGeofence

- Check In يحتاج اتصال إنترنت فعال.
- يحتاج Location Permission.
- التطبيق يرسل الموقع الحالي إلى السيرفر.
- يمنع Check In إذا كان الموظف خارج النطاق المسموح حول إحداثيات العميل.
- مسافة Geofence لا تكون hard-coded؛ تكون Setting قابلة للتعديل من لوحة الإدارة.
- إذا لم توجد إحداثيات للعميل، يمنع Check In وتظهر رسالة تطلب تصحيح موقع العميل من الإدارة.
- عند القبول يسجل السيرفر `checked_in_at` ويحوّل الزيارة إلى InProgress.
- لا يسمح بأكثر من Check In فعال لنفس الزيارة.
- لا يبدأ أي تتبع للموقع قبل Check In.

## 8. GPS Trail أثناء الزيارة

- يبدأ التتبع مباشرة بعد Check In.
- ينتهي مباشرة بعد Check Out.
- لا يوجد تتبع طوال ساعات العمل ولا خارج الزيارة.
- التطبيق يرسل نقاط الموقع بشكل دوري أثناء الزيارة.
- فاصل تحديث النقاط يكون Setting من لوحة الإدارة، وليس قيمة ثابتة داخل التطبيق.
- كل نقطة تحفظ كـ latitude + longitude + recorded_at في VisitGpsLog.
- لوحة الإدارة تعرض Trail يتحدث كلما وصلت نقاط جديدة.
- لا نحتاج Live streaming لحظي؛ trail المتجدد دورياً كافٍ.
- GPS logs تبقى append-only.
- يحتفظ النظام بمسار GPS بشكل دائم ضمن سجل الزيارة.
- Check Out لا يسمح بدون اتصال إنترنت.

إذا انقطع الاتصال مؤقتاً أثناء زيارة جارية، يجوز للتطبيق حفظ نقاط GPS محلياً كحماية من فقد البيانات، لكن لا يعتبر التطبيق Offline-capable، ويجب رفع النقاط فور عودة الاتصال قبل إتمام Check Out.

## 9. الغاية من GPS

الـ GPS في V1 يستخدم لإثبات:
- أن الموظف وصل فعلياً لموقع العميل ضمن Geofence.
- المسار خلال فترة الزيارة الميدانية.
- المدة بين Check In وCheck Out.

GPS ليس جزءاً من معادلة Performance الحالية، ولا يتم تحويله إلى مراقبة مستمرة خارج الزيارة.

## 10. Voice Note وAI Review قبل Submit

أثناء الزيارة يستطيع الموظف تسجيل Voice Note واحدة أو أكثر. التسجيلات تبقى Private وترتبط بنفس CustomerVisit.

Flow:
1. يرفع التطبيق التسجيل الصوتي.
2. ينشئ النظام Transcription بشكل asynchronous.
3. يعرض التطبيق حالة Processing / Ready / Failed.
4. عند نجاح التحويل، يحلل النظام النص ويولد Draft Outcome.
5. يحلل النص أيضاً لاكتشاف فرص بيع منتجات مناسبة للعميل.
6. قبل Submit النهائي تظهر شاشة Review & Confirm.
7. تعرض الشاشة الـ Transcript، والـ Outcome المقترح، والمنتجات/الفرص المقترحة.
8. يستطيع الموظف تعديل Outcome يدوياً.
9. يستطيع إزالة منتج مقترح أو اختيار منتجات إضافية من Product Catalog.
10. يستطيع تصحيح فرصة البيع قبل اعتمادها.
11. يسمح للموظف بتأكيد عدم وجود فرصة بيع إذا لم توجد فرصة فعلية.
12. لا يعتبر اقتراح AI قراراً نهائياً قبل تأكيد الموظف.
13. Submit النهائي يحتاج تأكيد صريح من الموظف أن Outcome وفرص البيع التي اختارها صحيحة.

فشل Transcription لا يلغي الزيارة. في هذه الحالة يدخل الموظف Outcome وفرص البيع يدوياً ثم يؤكدها.

### فجوة حالية يجب تنفيذها

SalesOpportunity الحالي لا يحتوي علاقة مباشرة مع منتجات محددة. يلزم إضافة بنية لربط Opportunity بالـ Product/Product Variant حتى يمكن حفظ المنتجات التي اقترحها AI وعدلها أو أكدها الموظف.

## 11. Submit وCheck Out

Submit النهائي للزيارة يسبق Check Out مباشرة.

المتطلبات:
- الزيارة InProgress.
- اتصال إنترنت فعال.
- Outcome موجود ومؤكد من الموظف.
- قسم فرص البيع تمت مراجعته وتأكيده، حتى لو كانت النتيجة No Opportunity.
- أي Voice Note تم استخدامها في التحليل تمت مراجعة نتيجتها أو تم إدخال البيانات يدوياً عند فشل المعالجة.
- نقاط GPS المعلقة ترفع قبل الإغلاق.

بعد التأكيد:
1. يحفظ النظام Outcome النهائي.
2. يحفظ فرص البيع والمنتجات المؤكدة.
3. يسجل `checked_out_at`.
4. يحول الزيارة إلى Completed.
5. يوقف GPS tracking فوراً.
6. يحسب مدة الزيارة من checked_in_at إلى checked_out_at.
7. تصبح البيانات متاحة مباشرة في لوحة الإدارة.

## 12. Sales Opportunities

- الفرصة الناتجة من Voice/AI تبقى قابلة لمراجعة الإدارة حسب workflow الحالي.
- الموظف يرى الفرص الخاصة به والمرتبطة بعملائه وزياراته.
- يرى Status وStage وEstimated Value وCurrency وExpected Close Date والمنتجات المرتبطة.
- يمكنه تعديل البيانات المسموحة قبل/وفق قواعد الـ backend.
- لا يستطيع تجاوز Admin approval أو lifecycle rules الموجودة في النظام.

## 13. Quotations داخل التطبيق

الموظف يستطيع إنشاء ومتابعة Quotation من التطبيق.

المسارات المعتمدة:
- إنشاء Draft Quotation مباشرة للعميل من شاشة المبيعات/الزيارة.
- إنشاء Quotation من Sales Opportunity عندما تكون قابلة للتحويل حسب قواعد الـ backend الحالية.
- المنتجات المؤكدة في فرصة البيع يمكن استخدامها لتهيئة Quotation Lines تلقائياً، مع مراجعة الموظف للكمية والسعر المسموح به قبل الحفظ.
- employee_id يؤخذ من المستخدم المصادق عليه ولا يرسل كقيمة موثوقة من الموبايل.
- Customer يؤخذ من الزيارة/الفرصة أو من العملاء المسموح للموظف بالوصول إليهم.
- التسعير والتحقق من الأسعار والـ currency وسياسات السعر تبقى Server-side.
- الموظف يرى Draft / Sent / Accepted / Rejected / Expired والحالة الحالية.
- Requote يستخدم قواعد الـ backend الحالية.
- تحويل Quotation المقبولة إلى Sales Order يبقى خاضعاً لتدفق النظام الحالي.
- Inventory وWarehouse وDelivery لا تدار من تطبيق الموظف في V1.

## 14. Performance والراتب والبونص

الموظف يرى:
- Total Performance.
- Task completion.
- Visit completion.
- Schedule adherence.
- Work-time adherence.
- تفاصيل احتساب الأداء المتاحة له.
- Salary calculation الخاصة به.
- Base/Payable salary حسب السجل الحالي.
- Performance percent.
- Bonus amount.
- Final salary.
- حالة الحساب/الاعتماد عندما تكون متاحة.

كل الأرقام تقرأ من السيرفر؛ لا يعاد حساب الراتب أو الأداء داخل التطبيق.

## 15. الإشعارات المطلوبة في V1

يدعم التطبيق In-App Notifications، ويضاف Mobile Push عبر Device Token للأنواع التالية:
- Task assigned.
- Task updated.
- Task cancelled من الإدارة.
- Task due soon.
- Task overdue.
- Visit scheduled.
- Visit rescheduled.
- Visit cancelled.
- Visit due soon.
- Admin review message جديد على الزيارة.
- Sales Opportunity review approved/rejected أو تغير مهم بالحالة.
- Quotation accepted.
- Quotation rejected.
- Quotation expired.
- Quotation updated بشكل يحتاج انتباه الموظف.
- Performance/Salary calculation أصبح متاحاً أو تم اعتماده عندما يكون ذلك مناسباً.
- Security notification عند Reset Password أو Reset Device Binding.

لكل إشعار:
- title وbody واضحان.
- deep link إلى السجل المناسب داخل التطبيق.
- read/unread state.
- created_at.
- لا ترسل بيانات حساسة كاملة داخل نص Push.

Push Token مرتبط بالجهاز الوحيد الفعال ويزال/يلغى عند Logout النهائي أو Reset Device Binding.

## 16. محادثة ملاحظات الإدارة على الزيارة

Review Note لم تعد read-only فقط في V1.

السلوك:
- الإدارة تضيف ملاحظة/رسالة مرتبطة بالزيارة من لوحة التحكم.
- الموظف يشاهدها داخل تفاصيل الزيارة.
- الموظف يستطيع Reply عليها من التطبيق.
- كل رد يحفظ كرسالة مستقلة؛ لا يقوم الموظف بتعديل Review Note الأصلية.
- المحادثة تظهر كاملة زمنياً داخل لوحة الإدارة.
- كل رسالة تحتوي المرسل، الوقت، والنص.
- يدعم unread state للطرفين.
- رسالة جديدة من الإدارة ترسل Notification للموظف.
- رد الموظف يظهر فوراً للإدارة ويولد تنبيهاً داخل لوحة التحكم.
- الرسائل تحفظ ضمن Audit Trail ولا تعدل تاريخياً إلا وفق سياسة إدارية واضحة.

يلزم إضافة نموذج/جدول Conversation Messages مرتبط بـ CustomerVisit لأن الحقل الحالي review_note وحده غير كافٍ لمحادثة ثنائية الاتجاه.

## 17. الربط مع لوحة الإدارة

لوحة الإدارة يجب أن ترى ضمن نفس Visit:
- Check In / Check Out.
- مدة الزيارة.
- GPS trail المتجدد.
- Outcome النهائي.
- Voice Notes.
- Transcript وحالة المعالجة.
- AI draft مقابل النتيجة التي أكدها الموظف عند الحاجة للمراجعة.
- فرص البيع والمنتجات المختارة.
- Attachments.
- Conversation مع الموظف.
- Quotation المرتبطة إن وجدت.

## 18. Security وData Ownership

- كل Endpoint يتحقق أن المستخدم Employee فعال وغير مؤرشف.
- الموظف يرى فقط خطته ومهامه وزياراته وفرصه وعروضه ورواتبه وإشعاراته.
- لا يعتمد السيرفر على employee_id القادم من الموبايل لتحديد الملكية.
- كل Transition يتحقق Server-side.
- Media تبقى Private.
- GPS logs append-only.
- Check In / Check Out وSubmit النهائي تكون Idempotent ضد retries.
- Device Binding يتحقق في كل Session/Token فعال.
- أي Reset Device يبطل التوكنات المرتبطة بالجهاز القديم.
- كل العمليات الحساسة تسجل في Audit Log مع `source_channel=employee_app`.

## 19. API المطلوبة

نحتاج Employee Mobile API تشمل:
- Login / first-login password change / logout.
- Device binding status.
- Profile.
- Current plan + history.
- Tasks list/detail/start/complete.
- Visits list/detail.
- Check In مع geofence validation.
- GPS batch append.
- Voice Note upload + transcription/AI status.
- Outcome draft/final update.
- Opportunity product candidates review/confirm.
- Visit final submit + Check Out.
- Sales Opportunities.
- Quotations list/detail/create/update/send/requote حسب الصلاحيات.
- Performance + salary + bonus.
- Notifications + mark read.
- Visit review conversation + reply.
- Push device-token registration/rotation.

Controllers تستدعي Services الحالية أو Services مشتركة جديدة؛ لا يعاد نسخ منطق الـ domain داخل Controllers.

## 20. التغييرات المطلوبة في Backend/Admin قبل بناء التطبيق

الكود الحالي يحتاج استكمالات واضحة لدعم السلوك المعتمد:
1. تسجيل API routes وتوثيق Employee API authentication.
2. Temporary Password + flag لإجبار تغييرها عند أول Login.
3. Employee Device Binding مع Reset action من Filament Admin.
4. Settings لمسافة Geofence وفاصل تحديث GPS.
5. Mobile Push device tokens وprovider integration.
6. علاقة بين SalesOpportunity والمنتجات/الـ Product Variants.
7. حفظ AI proposed outcome/product candidates بصورة منفصلة عن القيم التي أكدها الموظف.
8. Visit review conversation messages.
9. Mobile audit source لكل العمليات.
10. Employee API resources تمنع كشف حقول غير لازمة من Customer أو بيانات موظفين آخرين.
11. Filament actions/screens لإدارة Device Binding ومحادثة الزيارة ومراجعة المنتجات المؤكدة.
12. Tests تغطي ownership وdevice lock وgeofence وGPS وAI confirmation وquotation creation.

## 21. Screen Inventory النهائي

- Splash / Session Check.
- Login.
- First Password Change.
- Device Rejected / Contact Support.
- Home.
- Current Plan.
- Tasks List.
- Task Details.
- Visits List.
- Visit Details.
- Check In confirmation.
- Active Visit.
- Voice Recorder / Upload status.
- AI Processing.
- Review & Confirm Outcome + Product Opportunities.
- Check Out confirmation.
- Completed Visit Summary.

تكملة الشاشات:
- Sales Opportunities List.
- Opportunity Details.
- Create/Edit Draft Quotation.
- Quotation Details.
- Performance Details.
- Salary & Bonus Details.
- Notifications.
- Visit Review Conversation.
- Profile / Security.

## 22. Acceptance Criteria الأساسية

### الحساب
- موظف غير فعال أو مؤرشف لا يستطيع الدخول.
- Temporary Password لا تسمح باستخدام التطبيق قبل تغييرها.
- بعد ربط الجهاز، أي جهاز آخر يرفض.
- Reset Device من الإدارة يبطل جلسة الجهاز القديم ويسمح بجهاز جديد.

### الزيارة
- Check In لا يعمل بدون إنترنت.
- Check In لا يعمل خارج Geofence.
- Check In لا يعمل إذا موقع العميل غير مجهز.
- بعد Check In تبدأ نقاط GPS الدورية.
- لوحة الإدارة ترى trail يتحدث مع وصول النقاط.
- بعد Check Out لا تسجل نقاط جديدة.
- مدة الزيارة مشتقة فقط من timestamps.
- GPS trail يبقى محفوظاً دائماً.

### Submit
- لا يمكن الإغلاق بدون Outcome مؤكد.
- لا يمكن الإغلاق قبل مراجعة فرص البيع وتأكيدها أو اختيار No Opportunity.
- اقتراح AI قابل للتعديل ولا يعتمد تلقائياً.
- عند فشل AI يمكن إدخال Outcome وفرص البيع يدوياً.

### المبيعات والـQuotation
- الموظف يرى فرصه فقط.
- يمكنه اختيار/تعديل المنتجات الناتجة من AI.
- يمكنه إنشاء Draft Quotation للعميل.
- المنتجات المؤكدة يمكن تحويلها إلى Quotation Lines.
- الأسعار النهائية والتحقق منها يتمان في السيرفر.
- لا يمكن تجاوز قواعد Opportunity approval أو Quotation lifecycle.
- لا توجد عمليات Warehouse أو Inventory في تطبيق الموظف.

### المحادثة والإشعارات
- رسالة الإدارة على الزيارة تظهر للموظف.
- رد الموظف يظهر في لوحة الإدارة ضمن نفس المحادثة.
- الرسائل مرتبة زمنياً ولا تستبدل Review Note الأصلية.
- الأحداث الحرجة ترسل In-App Notification وPush.
- كل Notification تفتح السجل الصحيح داخل التطبيق.

### الأداء
- الموظف يرى Performance details الخاصة به.
- يرى Salary وBonus details الخاصة به فقط.
- كل القيم تأتي من السيرفر ولا تحسب على الموبايل.

## 23. قرار الاعتماد

هذه الوثيقة تمثل السلوك المعتمد للإصدار الأول من تطبيق الموظف الميداني.

أي تغيير لاحق في:
- نطاق الموظفين.
- Tracking خارج الزيارة.
- Support/Maintenance.
- Offline Check In/Out.
- Warehouse/Inventory operations.
يعتبر Scope Change ويحتاج تحديث هذه المواصفة قبل التنفيذ.
