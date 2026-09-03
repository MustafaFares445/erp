<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

final class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            NotificationTemplate::query()->updateOrCreate(
                [
                    'key' => $template['key'],
                    'locale' => $template['locale'],
                    'channel' => $template['channel'],
                ],
                [
                    'subject' => $template['subject'],
                    'body' => $template['body'],
                    'variables' => $template['variables'],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return list<array{key:string,locale:string,channel:NotificationChannel,subject:string,body:string,variables:list<string>}>
     */
    private function templates(): array
    {
        $templates = [];

        foreach ([7, 30, 60] as $days) {
            $event = match ($days) {
                7 => NotificationEventKey::InvoiceOverdue7,
                30 => NotificationEventKey::InvoiceOverdue30,
                60 => NotificationEventKey::InvoiceOverdue60,
            };

            $templates[] = [
                'key' => $event->value,
                'locale' => 'en',
                'channel' => NotificationChannel::Mail,
                'subject' => 'Invoice {{ invoice_number }} is overdue',
                'body' => 'Invoice {{ invoice_number }} has been overdue for {{ days_overdue }} days. Amount due: {{ amount_due }}.',
                'variables' => ['invoice_number', 'amount_due', 'days_overdue'],
            ];
            $templates[] = [
                'key' => $event->value,
                'locale' => 'ar',
                'channel' => NotificationChannel::Mail,
                'subject' => 'الفاتورة {{ invoice_number }} متأخرة',
                'body' => 'الفاتورة {{ invoice_number }} متأخرة منذ {{ days_overdue }} يوماً. المبلغ المستحق: {{ amount_due }}.',
                'variables' => ['invoice_number', 'amount_due', 'days_overdue'],
            ];
        }

        $templates[] = [
            'key' => NotificationEventKey::InvoiceIssued->value,
            'locale' => 'en',
            'channel' => NotificationChannel::Mail,
            'subject' => 'Invoice {{ invoice_number }}',
            'body' => 'Invoice {{ invoice_number }} has been issued. Total: {{ total_amount }}.',
            'variables' => ['invoice_number', 'total_amount'],
        ];
        $templates[] = [
            'key' => NotificationEventKey::InvoiceIssued->value,
            'locale' => 'ar',
            'channel' => NotificationChannel::Mail,
            'subject' => 'الفاتورة {{ invoice_number }}',
            'body' => 'تم إصدار الفاتورة {{ invoice_number }}. الإجمالي: {{ total_amount }}.',
            'variables' => ['invoice_number', 'total_amount'],
        ];

        foreach ([
            NotificationEventKey::LotExpiring->value => [
                'subject' => 'Inventory lot {{ lot_number }} is expiring',
                'body' => 'Lot {{ lot_number }} expires on {{ expires_at }}.',
                'variables' => ['lot_number', 'expires_at'],
            ],
            NotificationEventKey::ApprovalPending->value => [
                'subject' => 'Approval pending: {{ document_number }}',
                'body' => '{{ document_type }} {{ document_number }} is waiting for approval.',
                'variables' => ['document_type', 'document_number'],
            ],
            NotificationEventKey::VisitDue->value => [
                'subject' => 'Visit due: {{ customer_name }}',
                'body' => 'Visit {{ visit_id }} for {{ customer_name }} is planned for {{ planned_at }}.',
                'variables' => ['visit_id', 'customer_name', 'planned_at'],
            ],
        ] as $key => $definition) {
            $templates[] = [
                'key' => $key,
                'locale' => 'en',
                'channel' => NotificationChannel::Mail,
                'subject' => $definition['subject'],
                'body' => $definition['body'],
                'variables' => $definition['variables'],
            ];
        }

        foreach ([
            NotificationEventKey::InvoiceIssued->value => [
                'variables' => ['invoice_number', 'total_amount'],
                'channels' => [NotificationChannel::Database],
                'en' => ['Invoice {{ invoice_number }} issued', 'Invoice {{ invoice_number }} has been issued. Total: {{ total_amount }}.'],
                'ar' => ['تم إصدار الفاتورة {{ invoice_number }}', 'تم إصدار الفاتورة {{ invoice_number }}. الإجمالي: {{ total_amount }}.'],
            ],
            NotificationEventKey::PaymentReceived->value => [
                'variables' => ['payment_number', 'amount', 'currency'],
                'channels' => [NotificationChannel::Mail, NotificationChannel::Database],
                'en' => ['Payment {{ payment_number }} received', 'Payment {{ payment_number }} for {{ amount }} {{ currency }} was received.'],
                'ar' => ['تم استلام الدفعة {{ payment_number }}', 'تم استلام الدفعة {{ payment_number }} بقيمة {{ amount }} {{ currency }}.'],
            ],
            NotificationEventKey::QuotationDecided->value => [
                'variables' => ['quotation_number', 'status'],
                'channels' => [NotificationChannel::Database],
                'en' => ['Quotation {{ quotation_number }} updated', 'Quotation {{ quotation_number }} was marked {{ status }}.'],
                'ar' => ['تم تحديث عرض السعر {{ quotation_number }}', 'تم تغيير حالة عرض السعر {{ quotation_number }} إلى {{ status }}.'],
            ],
            NotificationEventKey::TaskAssigned->value => [
                'variables' => ['task_title', 'due_at'],
                'channels' => [NotificationChannel::Mail, NotificationChannel::Database],
                'en' => ['Task assigned: {{ task_title }}', '{{ task_title }} is due on {{ due_at }}.'],
                'ar' => ['تم تعيين مهمة: {{ task_title }}', 'المهمة {{ task_title }} مستحقة بتاريخ {{ due_at }}.'],
            ],
            NotificationEventKey::TicketUpdated->value => [
                'variables' => ['ticket_number', 'status'],
                'channels' => [NotificationChannel::Mail, NotificationChannel::Database],
                'en' => ['Ticket {{ ticket_number }} updated', 'Ticket {{ ticket_number }} is now {{ status }}.'],
                'ar' => ['تم تحديث التذكرة {{ ticket_number }}', 'أصبحت حالة التذكرة {{ ticket_number }}: {{ status }}.'],
            ],
            NotificationEventKey::SlaAtRisk->value => [
                'variables' => ['ticket_number', 'sla_kind'],
                'channels' => [NotificationChannel::Mail, NotificationChannel::Database],
                'en' => ['SLA attention: {{ ticket_number }}', 'Ticket {{ ticket_number }} breached the {{ sla_kind }} SLA target.'],
                'ar' => ['تنبيه SLA للتذكرة {{ ticket_number }}', 'تجاوزت التذكرة {{ ticket_number }} حد SLA الخاص بـ {{ sla_kind }}.'],
            ],
            NotificationEventKey::StockLow->value => [
                'variables' => ['stock_id', 'available_quantity'],
                'channels' => [NotificationChannel::Mail, NotificationChannel::Database],
                'en' => ['Stock level requires attention', 'Stock record {{ stock_id }} has {{ available_quantity }} available.'],
                'ar' => ['مستوى المخزون يحتاج متابعة', 'سجل المخزون {{ stock_id }} لديه {{ available_quantity }} متاحاً.'],
            ],
            NotificationEventKey::InventoryReservationExpired->value => [
                'variables' => ['source_reference', 'quantity'],
                'channels' => [NotificationChannel::Mail, NotificationChannel::Database],
                'en' => ['Inventory reservation expired', 'Reservation coverage for {{ source_reference }} expired for {{ quantity }} base units.'],
                'ar' => ['انتهى حجز المخزون', 'انتهى حجز المخزون للمرجع {{ source_reference }} بكمية {{ quantity }} من الوحدة الأساسية.'],
            ],
            NotificationEventKey::LeadConverted->value => [
                'variables' => ['lead_name', 'customer_name'],
                'channels' => [NotificationChannel::Mail, NotificationChannel::Database],
                'en' => ['Lead converted: {{ lead_name }}', '{{ lead_name }} was converted to customer {{ customer_name }}.'],
                'ar' => ['تم تحويل العميل المحتمل: {{ lead_name }}', 'تم تحويل {{ lead_name }} إلى العميل {{ customer_name }}.'],
            ],
            NotificationEventKey::CampaignCompleted->value => [
                'variables' => ['campaign_name', 'sent_count', 'failed_count'],
                'channels' => [NotificationChannel::Mail, NotificationChannel::Database],
                'en' => ['Campaign completed: {{ campaign_name }}', '{{ campaign_name }} completed with {{ sent_count }} sent and {{ failed_count }} failed deliveries.'],
                'ar' => ['اكتملت الحملة: {{ campaign_name }}', 'اكتملت الحملة {{ campaign_name }} مع {{ sent_count }} إرسال ناجح و {{ failed_count }} إرسال فاشل.'],
            ],
            NotificationEventKey::MaintenanceRecordBilled->value => [
                'variables' => ['maintenance_reference', 'invoice_number'],
                'channels' => [NotificationChannel::Mail, NotificationChannel::Database],
                'en' => ['Maintenance billed: {{ maintenance_reference }}', 'Maintenance {{ maintenance_reference }} was billed on invoice {{ invoice_number }}.'],
                'ar' => ['تمت فوترة الصيانة: {{ maintenance_reference }}', 'تمت فوترة الصيانة {{ maintenance_reference }} ضمن الفاتورة {{ invoice_number }}.'],
            ],
        ] as $key => $definition) {
            foreach ($definition['channels'] as $channel) {
                foreach (['en', 'ar'] as $locale) {
                    $templates[] = [
                        'key' => $key,
                        'locale' => $locale,
                        'channel' => $channel,
                        'subject' => $definition[$locale][0],
                        'body' => $definition[$locale][1],
                        'variables' => $definition['variables'],
                    ];
                }
            }
        }

        foreach ([
            NotificationEventKey::LotExpiring->value => [
                'subject' => 'المخزون بالدفعة {{ lot_number }} يقترب من انتهاء الصلاحية',
                'body' => 'تنتهي صلاحية الدفعة {{ lot_number }} بتاريخ {{ expires_at }}.',
                'variables' => ['lot_number', 'expires_at'],
            ],
            NotificationEventKey::ApprovalPending->value => [
                'subject' => 'اعتماد معلق: {{ document_number }}',
                'body' => '{{ document_type }} {{ document_number }} بانتظار الاعتماد.',
                'variables' => ['document_type', 'document_number'],
            ],
            NotificationEventKey::VisitDue->value => [
                'subject' => 'زيارة مستحقة: {{ customer_name }}',
                'body' => 'الزيارة {{ visit_id }} للعميل {{ customer_name }} مخططة في {{ planned_at }}.',
                'variables' => ['visit_id', 'customer_name', 'planned_at'],
            ],
        ] as $key => $definition) {
            $templates[] = [
                'key' => $key,
                'locale' => 'ar',
                'channel' => NotificationChannel::Mail,
                'subject' => $definition['subject'],
                'body' => $definition['body'],
                'variables' => $definition['variables'],
            ];
        }

        return $templates;
    }
}
