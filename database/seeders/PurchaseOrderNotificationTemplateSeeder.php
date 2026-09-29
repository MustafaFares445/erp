<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Workflow templates introduced by Phase 0 purchasing remediation.
 *
 * Kept separate from the legacy notification template catalogue so this
 * purchasing concern remains reviewable as one small Phase-0 slice.
 */
final class PurchaseOrderNotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            NotificationTemplate::query()->updateOrCreate(
                [
                    'key' => $template['key'],
                    'locale' => $template['locale'],
                    'channel' => NotificationChannel::Database,
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

    /** @return list<array{key:string,locale:string,subject:string,body:string,variables:list<string>}> */
    private function templates(): array
    {
        return [
            [
                'key' => NotificationEventKey::PurchaseOrderReadyForAllocation->value,
                'locale' => 'en',
                'subject' => 'PO {{ purchase_order_number }} ready for allocation',
                'body' => 'Purchase order {{ purchase_order_number }} is ready for warehouse allocation.',
                'variables' => ['purchase_order_number'],
            ],
            [
                'key' => NotificationEventKey::PurchaseOrderReadyForAllocation->value,
                'locale' => 'ar',
                'subject' => 'أمر الشراء {{ purchase_order_number }} جاهز للتخصيص',
                'body' => 'أمر الشراء {{ purchase_order_number }} جاهز لتخصيص المستودع.',
                'variables' => ['purchase_order_number'],
            ],
            [
                'key' => NotificationEventKey::PurchaseOrderDraftBillReady->value,
                'locale' => 'en',
                'subject' => 'Draft bill {{ bill_number }} ready for review',
                'body' => 'Draft bill {{ bill_number }} for purchase order {{ purchase_order_number }} is ready for Accounting review.',
                'variables' => ['bill_number', 'purchase_order_number'],
            ],
            [
                'key' => NotificationEventKey::PurchaseOrderDraftBillReady->value,
                'locale' => 'ar',
                'subject' => 'مسودة الفاتورة {{ bill_number }} جاهزة للمراجعة',
                'body' => 'مسودة الفاتورة {{ bill_number }} لأمر الشراء {{ purchase_order_number }} جاهزة لمراجعة المحاسبة.',
                'variables' => ['bill_number', 'purchase_order_number'],
            ],
            [
                'key' => NotificationEventKey::SupplierCommitmentBackordered->value,
                'locale' => 'en',
                'subject' => 'Supplier backorder on PO {{ purchase_order_number }}',
                'body' => 'PO {{ purchase_order_number }} has {{ backordered_quantity }} units backordered. Record the next supplier commitment or re-source the outstanding demand.',
                'variables' => ['purchase_order_number', 'backordered_quantity'],
            ],
            [
                'key' => NotificationEventKey::SupplierCommitmentBackordered->value,
                'locale' => 'ar',
                'subject' => 'كمية مؤجلة من المورد لأمر الشراء {{ purchase_order_number }}',
                'body' => 'يوجد {{ backordered_quantity }} وحدة مؤجلة في أمر الشراء {{ purchase_order_number }}. تابع التزام المورد التالي أو أعد توريد الكمية المتبقية.',
                'variables' => ['purchase_order_number', 'backordered_quantity'],
            ],
            [
                'key' => NotificationEventKey::SupplierCommitmentRejected->value,
                'locale' => 'en',
                'subject' => 'Supplier rejected PO {{ purchase_order_number }} commitment',
                'body' => 'The supplier rejected the requested commitment for PO {{ purchase_order_number }}. Linked Sales demand was returned to the sourcing queue.',
                'variables' => ['purchase_order_number'],
            ],
            [
                'key' => NotificationEventKey::SupplierCommitmentRejected->value,
                'locale' => 'ar',
                'subject' => 'رفض المورد التزام أمر الشراء {{ purchase_order_number }}',
                'body' => 'رفض المورد الالتزام المطلوب لأمر الشراء {{ purchase_order_number }}. تمت إعادة طلبات المبيعات المرتبطة إلى قائمة التوريد.',
                'variables' => ['purchase_order_number'],
            ],
            [
                'key' => NotificationEventKey::PurchaseOrderReceivedForAccounting->value,
                'locale' => 'en',
                'subject' => 'PO {{ purchase_order_number }} fully received',
                'body' => 'PO {{ purchase_order_number }} is fully received. Review the supplier bill and three-way match before approval or payment.',
                'variables' => ['purchase_order_number'],
            ],
            [
                'key' => NotificationEventKey::PurchaseOrderReceivedForAccounting->value,
                'locale' => 'ar',
                'subject' => 'تم استلام أمر الشراء {{ purchase_order_number }} بالكامل',
                'body' => 'تم استلام أمر الشراء {{ purchase_order_number }} بالكامل. راجع فاتورة المورد والمطابقة الثلاثية قبل الاعتماد أو الدفع.',
                'variables' => ['purchase_order_number'],
            ],
        ];
    }
}
