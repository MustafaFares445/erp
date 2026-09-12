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
                'body' => 'Accepted purchase order {{ purchase_order_number }} is ready for warehouse allocation.',
                'variables' => ['purchase_order_number'],
            ],
            [
                'key' => NotificationEventKey::PurchaseOrderReadyForAllocation->value,
                'locale' => 'ar',
                'subject' => 'أمر الشراء {{ purchase_order_number }} جاهز للتخصيص',
                'body' => 'أمر الشراء المقبول {{ purchase_order_number }} جاهز لتخصيص المستودع.',
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
        ];
    }
}
