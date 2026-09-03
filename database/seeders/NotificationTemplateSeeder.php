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

        return $templates;
    }
}
