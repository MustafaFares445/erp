# توصيف تطبيق الموظف الميداني — مسودة للمناقشة

**الحالة:** Draft — غير معتمد بعد  
**تاريخ المراجعة:** 2026-09-19  
**مصدر الحقيقة:** الكود الحالي على فرع `dev`، بنية قاعدة البيانات، الـ Models، الـ Services، والواجهات الحالية في Filament Admin.  
**مهم:** لم يتم اعتماد الـ SRS أو specs القديمة كأساس للسلوك.

## 1. الهدف

تطبيق الموظف هو واجهة العمل الميداني المرتبطة مباشرةً بنظام IERP ولوحة الإدارة.
الهدف أن يعرف الموظف ما هو مطلوب منه، ينفذ المهام والزيارات، يسجل ما حدث ميدانياً، ويرسل الأدلة والبيانات إلى نفس السجلات التي يراجعها المدير في لوحة التحكم.

لا يجب إنشاء workflow منفصل خاص بالموبايل. أي Action في التطبيق يجب أن يستعمل نفس الـ entities والـ lifecycle rules الموجودة في النظام الحالي.

## 2. ما هو موجود فعلياً في النظام الآن

الكود الحالي يحتوي فعلياً على:
- Employee Profile مرتبط بـ User من نوع `employee`.
- تفعيل وتعطيل الموظف من لوحة الإدارة.
- Monthly Sales Plans مرتبطة بالموظف.
- Plan Tasks داخل الخطة الشهرية.
- Customer Visits مرتبطة بالموظف والـ Task والعميل.
- Visit Check-in / Check-out timestamps.
- GPS trail لكل زيارة عبر سجلات append-only.
- Visit attachments خاصة وغير public.
- Employee Voice Notes مرتبطة بالزيارة.

- Transcription asynchronous للتسجيلات الصوتية.
- AI keyword detection وإنشاء Sales Opportunities من التسجيلات.
- Admin review للفرص الناتجة.
- Performance scoring للموظف.
- Salary calculations و bonus suggestions.
- Quotations مرتبطة بالموظف والفرصة البيعية.
- Tickets يمكن إسنادها لموظف.
- Maintenance / Service Records يمكن إسنادها لموظف.
- Notification infrastructure عبر Database / Mail.
- Activity / Audit logging للعمليات.

## 3. الربط الحالي مع لوحة الإدارة

صفحة Visit الحالية في لوحة الإدارة تعرض فعلياً:
- الموظف والعميل والمهمة المرتبطة.
- حالة الزيارة ومدة الزيارة.
- Check In وCheck Out.
- Outcome.
- Review Note من الإدارة.
- GPS Trail على خريطة مع موقع العميل.
- Voice Notes.
- Sales Opportunities المستخرجة من التسجيل.
- Attachments.

بالتالي تطبيق الموظف يجب أن يكون المصدر الميداني الذي يغذي هذه البيانات نفسها، وليس نظاماً ثانياً منفصلاً.
## 4. فجوة الـ API الحالية

وقت هذه المراجعة:
- لا يوجد ملف `routes/api.php` مسجل في المشروع.
- `bootstrap/app.php` لا يسجل API routes.
- لا توجد REST API مكتملة لتطبيق الموظف.
- لا يوجد flow واضح لتسجيل دخول الموظف من تطبيق موبايل باستخدام token.
- `UserType::Employee` موجود ومخصص لقناة تطبيق مستقلة، بينما Filament Admin محصور بالـ Admin.

لذلك تنفيذ التطبيق يحتاج Mobile API layer فوق منطق النظام الحالي، وليس إعادة كتابة المنطق.

> يوجد عمل متزامن حالياً على Authentication/Routing في الـ working tree، لذلك يجب إعادة فحص هذه النقطة قبل التنفيذ مباشرة.

## 5. التنقل المقترح

Bottom Navigation:
1. الرئيسية
2. المهام
3. الزيارات
4. العمل
5. الإشعارات
6. الحساب

قسم العمل يكون capability-driven: Sales أو Support أو Maintenance حسب دور الموظف والسجلات المسندة له.
## 6. تسجيل الدخول والحساب

السلوك المطلوب:
- تسجيل الدخول من Employee API مخصصة.
- يمنع الدخول إذا لم يكن المستخدم من نوع employee.
- يمنع الدخول إذا EmployeeProfile غير موجود أو غير فعال أو مؤرشف.
- تعطيل الموظف من لوحة الإدارة يجب أن ينعكس على التطبيق.
- Sessions/Tokens قابلة للإلغاء.
- صفحة الحساب تعرض الاسم، employee code، job title، الهاتف، والبريد.
- تغيير كلمة المرور وForgot password وFirst login يجب أن يكون لها flow واضح.

### نقطة غير محسومة

EmployeeOnboardingService الحالي ينشئ Password عشوائية داخلية ولا يوجد onboarding flow للموبايل. يجب تحديد طريقة أول تسجيل دخول قبل الاعتماد.

## 7. الشاشة الرئيسية — Today

تعرض:
- الخطة الشهرية الفعالة.
- مهام اليوم والمتأخرة والقريبة من الموعد.
- زيارات اليوم مرتبة حسب الوقت.
- الزيارة الجارية حالياً إن وجدت.
- ملخص تقدم الخطة والـ Performance إذا كان مسموحاً.
- الإشعارات غير المقروءة.
- CTA واضح للخطوة التالية.

لا نعرض للموظف KPIs عامة للشركة أو معلومات موظفين آخرين.
## 8. الخطة الشهرية

الموظف يشاهد:
- اسم الخطة والشهر والحالة.
- حالات الخطة الحالية: Draft / Active / Paused / Completed / Archived.
- مهام الخطة والزيارات الناتجة عنها.
- required visit minutes.
- progress/performance breakdown.
- الخطط السابقة كـ read-only.

الموظف لا يعدل scoring weights أو employee assignment أو required visit minutes أو lifecycle الخاص بالخطة، إلا إذا اعتمدنا ذلك لاحقاً.

## 9. المهام

حالات المهمة الحالية:
- Pending
- InProgress
- Completed
- Cancelled

الـ lifecycle الحالي يسمح تقنياً بـ:
- Pending -> InProgress / Completed / Cancelled
- InProgress -> Completed / Cancelled / Pending
- Completed -> InProgress
- Cancelled -> Pending

واجهة الموظف المقترحة تقسم المهام إلى Today / Upcoming / Overdue / Completed مع filters حسب status/customer/date.
تفاصيل المهمة تعرض:
- Title وDescription.
- Customer إن وجد.
- Start date وDue date.
- Status وCompleted at.
- Status history أو notes إذا تم السماح بإظهارها.

صلاحيات الموظف في التطبيق يجب أن تكون أضيق من transitions الخام الموجودة في الـ domain، ونحتاج قراراً صريحاً بهذا الخصوص.

## 10. الزيارة — قبل الوصول

تفاصيل الزيارة تعرض:
- اسم العميل/الشركة.
- بيانات الاتصال اللازمة للعمل فقط.
- العنوان والمدينة.
- موقع العميل على الخريطة إذا كانت الإحداثيات موجودة.
- وقت الزيارة المخطط.
- المهمة المرتبطة.
- حالة الزيارة.
- الحد الأدنى المطلوب لمدة الزيارة ضمن الخطة.
- زر فتح الطريق عبر تطبيق الخرائط في الهاتف.
- تنبيه واضح حول GPS tracking قبل بدء التسجيل.

لا يعرض التطبيق بيانات العميل المالية أو المحاسبية التي لا يحتاجها الموظف.
## 11. Check In و GPS

Visit lifecycle الحالي:
- Planned -> InProgress أو Missed
- InProgress -> Completed أو Missed
- Missed -> Planned
- Completed نهائية.

السلوك المقترح:
1. الموظف يفتح الزيارة المسندة له.
2. التطبيق يتحقق من Location Permission.
3. الموظف يضغط بدء الزيارة / Check In.
4. السيرفر يسجل `checked_in_at` ويحول الزيارة إلى InProgress.
5. يبدأ التطبيق تسجيل GPS لهذه الزيارة.
6. يرسل نقاط latitude + longitude + recorded_at.
7. النقاط تذهب إلى VisitGpsLog الموجودة حالياً.
8. لوحة الإدارة تعرض المسار ضمن نفس Visit.
9. عند انقطاع الإنترنت تحفظ النقاط محلياً وتزامن لاحقاً حسب السياسة المعتمدة.

الـ backend الحالي يخزن latitude وlongitude وrecorded_at فقط. لا يخزن GPS accuracy أو speed أو altitude أو mock-location flag أو geofence result أو battery state.

إذا أردنا تحققاً أقوى من الزيارة الحقيقية، يجب توسيع النموذج.
## 12. أثناء الزيارة

الموظف يجب أن يستطيع:
- رؤية مدة الزيارة الجارية.
- إدخال أو تعديل Outcome.
- رفع صور أو ملفات كـ visit attachments.
- تسجيل Voice Note واحدة أو أكثر.
- رؤية حالة الرفع: Pending / Uploaded / Failed.
- الاستمرار بالعمل حتى لو transcription لم تكتمل.
- تنفيذ Sales action معتمدة إذا دخل Sales ضمن V1.
- إنهاء الزيارة من Check Out واضح ومقصود.

فشل AI transcription لا يجب أن يمنع إكمال الزيارة.

## 13. Voice Notes والـ AI

النظام الحالي يدعم private audio، language، duration، queued transcription، transcript، confidence/provider/error، AI keyword matching، Sales Opportunity مرتبطة بالتسجيل، وAdmin approve/reject.

التطبيق يجب أن يعرض حالة التسجيل والمعالجة بدون اعتبار نتيجة AI قراراً تجارياً نهائياً.

نحتاج قراراً: هل يرى الموظف transcript والkeyword والفرصة الناتجة؟ وهل يستطيع تصحيحها أو إضافة context قبل admin review؟
## 14. Check Out وإكمال الزيارة

السلوك المقترح:
1. الموظف يضغط إنهاء الزيارة / Check Out.
2. التطبيق يتحقق من متطلبات الإكمال المعتمدة.
3. السيرفر يسجل `checked_out_at`.
4. الزيارة تنتقل إلى Completed.
5. مدة الزيارة تحسب من timestamps ولا تدخل يدوياً.
6. GPS/media pending uploads تكمل المزامنة حسب السياسة.
7. لوحة الإدارة ترى status/duration/outcome/GPS/attachments/voice notes.
8. Performance يحسب من السيرفر وفق القواعد الحالية.

حالياً الـ model لا يفرض Outcome أو Voice Note أو Attachment أو حد أدنى من GPS points. إذا أردنا أي منها شرطاً قبل Check Out، يجب اعتماده صراحةً.

## 15. Performance

الحساب الحالي يتكون من أربعة عوامل ذات weights قابلة للضبط من الإدارة:
- Task completion.
- Visit completion.
- Schedule adherence.
- Work-time adherence.

Work-time adherence حالياً يعني أن زيارة Completed وصلت إلى required_visit_minutes المحددة بالخطة. GPS/geofence ليس حالياً جزءاً من Performance formula.

اقتراح التطبيق: يعرض progress لكل عامل من السيرفر فقط، بدون حساب محلي. ويحتاج قرار هل نعرض Performance فقط أم أيضاً Salary وBonus.
## 16. Sales داخل التطبيق

الكود الحالي يدعم:
- Sales Opportunities مرتبطة بالزيارة والموظف.
- Approved Opportunity يمكن تحويلها إلى Quotation.
- Quotation فيها `employee_id`.
- الموظف المرتبط بالعرض تصله نتيجة قبول/رفض العرض عبر notification flow الحالي.
- العرض المقبول يمكن لاحقاً تحويله إلى Sales Order.

النطاق المحتمل للموبايل:
- رؤية فرص الموظف.
- رؤية opportunity الناتجة عن زيارة.
- إنشاء Draft Quotation للعميل.
- إنشاء Quotation من Approved Opportunity.
- متابعة status العرض.
- Requote حسب قواعد الـ backend.

**Boundary مقترحة:** warehouse fulfillment / delivery posting يبقى خارج Employee App لأنه يغير المخزون ويتبع Logistics/Inventory workflow.

## 17. Support و Maintenance داخل التطبيق

الـ backend الحالي يدعم Tickets مسندة إلى EmployeeProfile، Ticket lifecycle، assignment history، Maintenance Requests، وService Records مسندة للموظف مع Open -> InProgress -> Closed/Cancelled إضافة إلى work performed وcompletion notes.

إذا التطبيق موحد لكل الموظفين، يظهر قسم العمل Tickets/Service Records حسب الدور والملكية. إذا V1 خاص بـ field sales فقط، نستبعد هذه الواجهات من الإصدار الأول.
## 18. الإشعارات

موجود حالياً Backend notifications لـ Task Assigned وVisit Due وQuotation Decided/Expired وTicket Updated وأحداث أخرى.

القنوات العاملة حالياً:
- Database.
- Mail.

لا يوجد حالياً Mobile Push device-token infrastructure.

المطلوب للموبايل غالباً:
- New task.
- Task changed/cancelled.
- Visit due أو rescheduled.
- Ticket/service record assigned إن كان ضمن scope.
- Quotation accepted/rejected/expired إن كان Sales ضمن scope.
- Admin feedback المهم.

Push يحتاج Device registration وtoken lifecycle وprovider integration وlogout/revoke cleanup.

## 19. المزامنة مع لوحة الإدارة

| Action في التطبيق | السجل الحالي في النظام |
|---|---|
| تغيير حالة Task | PlanTask + TaskStatusLog |
| Check In / Out | CustomerVisit |
| GPS | VisitGpsLog |
| Outcome | CustomerVisit.outcome |
| صور/ملفات | visit-attachments |
| Voice Note | EmployeeVoiceNote |
| Transcription | VoiceNoteTranscription |
| AI Opportunity | SalesOpportunity |
| Ticket work | Ticket lifecycle |
| Maintenance work | MaintenanceTask |
| Mobile audit | Activity Log مع source_channel=employee_app |

Admin Review Note تبقى management-owned حالياً. إذا أردنا رد الموظف عليها، نحتاج مفهوم Reply منفصل بدل تعديل نفس الحقل.
## 20. Security و Data Ownership

يجب فرض التالي على السيرفر:
- الموظف يرى فقط بياناته والسجلات المسندة له.
- لا نعتمد على employee_id القادم من الموبايل لتحديد الملكية.
- كل endpoint يتحقق من ownership.
- كل status transition يتحقق server-side.
- Customer payload محدود لما يحتاجه العمل الميداني.
- Media تبقى private.
- GPS append-only.
- حماية من duplicate/retry للـ Check In / Check Out / uploads.
- تعطيل أو أرشفة الموظف يوقف الوصول حسب session policy.
- Activity source للموبايل يكون واضحاً في audit.

## 21. Offline و Reliability

المقترح:
- Cache للخطة الحالية ومهام اليوم وتفاصيل الزيارة وموقع العميل.
- Queue مؤقتة لـ GPS عند فقدان الشبكة.
- Queue للملاحظات والـ lightweight updates.
- Upload retry للصور والصوت.
- UI يوضح Synced / Waiting for network / Failed.
- لا نظهر زيارة Completed محلياً إذا السيرفر لم يقبل الانتقال.
- نحدد سياسة timestamps حتى لا نعتمد بشكل أعمى على ساعة الجهاز.

قرار مهم: هل Check In / Check Out نفسه يسمح Offline أم يحتاج اتصال مباشر؟

## 22. API المطلوبة لتنفيذ التطبيق

نحتاج Employee Mobile API تشمل على الأقل:
- Auth / logout / token or session.
- Profile.
- Current monthly plan + plan history.
- Tasks list/detail + task transition.
- Visits list/detail + check-in / check-out / missed.
- GPS batch append.
- Outcome update.
- Attachment upload.
- Voice-note upload + processing status.
- Notifications + mark read.
- Performance summary.
- Optional Opportunities / Quotations.
- Optional assigned Tickets / Service Records.
- Optional Push device registration.

Controllers لا يجب أن تعيد بناء منطق الـ domain؛ تستدعي Services الحالية أو Services مشتركة جديدة عند الحاجة.

## 23. الأسئلة المطلوبة قبل اعتماد السلوك

1. هل التطبيق للـ Sales field employees فقط، أم موحد أيضاً للـ Support/Maintenance technicians؟
2. هل نتابع GPS فقط من Check In إلى Check Out، أم طوال ساعات العمل؟
3. هل نمنع Check In إذا الموظف بعيد عن موقع العميل؟ وإذا نعم، ما المسافة المقبولة؟
4. إذا العميل لا يوجد له Coordinates، هل يسمح Check In بدون Geofence؟
5. هل تريد Live location للمدير أثناء الزيارة، أم فقط trail بعد انتهائها؟
6. ما الذي يجب أن يكون إلزامياً قبل Check Out: Outcome، Voice Note، صورة، GPS points، أم لا شيء إضافي؟
7. هل الموظف يستطيع فقط Start + Complete للـ Task، أم أيضاً Cancel / Reopen / Back to Pending؟
8. هل تسمح Check In/Out بدون إنترنت ثم sync لاحقاً؟
9. هل V1 يشمل إنشاء Quotation من التطبيق؟
10. هل الموظف يرى transcript والفرصة المكتشفة ويستطيع تصحيحها؟
11. هل يرى Performance فقط أم Salary وBonus أيضاً؟
12. هل Push Notifications مطلوبة من أول إصدار؟
13. First Login: Invitation link أم Temporary password أم OTP أم طريقة أخرى؟
14. كم نحتفظ بمسار GPS للزيارة؟
15. هل Review Note من المدير تظهر read-only أم مع Reply؟

## 24. شرط الاعتماد

هذه الوثيقة تبقى Draft حتى يتم حسم الأسئلة السابقة.

بعد الإجابات نصدر نسخة Approved تتضمن Screen inventory النهائي، صلاحيات كل نوع موظف، Visit/GPS rules، API contracts، Offline behavior، Notifications، Error states، Admin/mobile synchronization، وAcceptance Criteria لكل شاشة وFlow.
