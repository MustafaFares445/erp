<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use Illuminate\Support\Str;

/**
 * Business-facing description of the system-defined notifications: the human
 * name, when each is sent and the information (variables) its message may use.
 *
 * The raw event keys, locale codes and `{{ placeholder }}` syntax stay internal;
 * administrators only ever see the labels returned here.
 */
final readonly class NotificationTemplateCatalog
{
    /** @var array<string, string> */
    private const array LANGUAGES = ['en' => 'English', 'ar' => 'العربية'];

    /** @var list<NotificationChannel> */
    public const array MANAGED_CHANNELS = [NotificationChannel::Mail, NotificationChannel::Database];

    /** @var array<string, list<string>> */
    private const array VARIABLES = [
        'invoice.issued' => ['invoice_number', 'total_amount'],
        'invoice.overdue.7' => ['invoice_number', 'amount_due', 'days_overdue'],
        'invoice.overdue.30' => ['invoice_number', 'amount_due', 'days_overdue'],
        'invoice.overdue.60' => ['invoice_number', 'amount_due', 'days_overdue'],
        'payment.received' => ['payment_number', 'amount', 'currency'],
        'quotation.decided' => ['quotation_number', 'status'],
        'quotation.expired' => ['quotation_number'],
        'task.assigned' => ['task_title', 'due_at'],
        'visit.due' => ['visit_id', 'customer_name', 'planned_at'],
        'ticket.updated' => ['ticket_number', 'status'],
        'ticket.feedback_requested' => ['ticket_number'],
        'sla.at_risk' => ['ticket_number', 'sla_kind'],
        'stock.low' => ['stock_id', 'available_quantity'],
        'lot.expiring' => ['lot_number', 'expires_at'],
        'approval.pending' => ['document_type', 'document_number'],
        'inventory.reservation.expired' => ['source_reference', 'quantity'],
        'lead.converted' => ['lead_name', 'customer_name'],
        'campaign.completed' => ['campaign_name', 'sent_count', 'failed_count'],
        'maintenance.billed' => ['maintenance_reference', 'invoice_number'],
        'maintenance.due' => ['schedule_number', 'schedule_name', 'due_on'],
        'installation.scheduled' => ['maintenance_reference', 'serial_number', 'customer_name', 'scheduled_at'],
        'installation.completed' => ['maintenance_reference', 'serial_number', 'customer_name'],
        'commissioning.passed' => ['maintenance_reference', 'serial_number', 'customer_name'],
        'commissioning.failed' => ['maintenance_reference', 'serial_number', 'customer_name', 'reason'],
        'installation.acceptance_recorded' => ['maintenance_reference', 'serial_number', 'customer_name', 'reason'],
        'calibration.due' => ['schedule_number', 'schedule_name', 'due_on'],
        'calibration.completed' => ['maintenance_reference', 'serial_number', 'customer_name', 'result'],
        'calibration.failed' => ['maintenance_reference', 'serial_number', 'customer_name', 'reason'],
        'loaner.overdue' => ['maintenance_reference', 'serial_number', 'customer_name', 'loaner_serial', 'expected_return_at'],
        'rma.status_changed' => ['maintenance_reference', 'serial_number', 'supplier_name', 'rma_status'],
        'rma.equipment_returned' => ['maintenance_reference', 'serial_number', 'supplier_name', 'rma_status'],
        'purchase_order.ready_for_allocation' => ['purchase_order_number'],
        'purchase_order.draft_bill_ready' => ['bill_number', 'purchase_order_number'],
        'supplier_commitment.backordered' => ['purchase_order_number', 'backordered_quantity'],
        'supplier_commitment.rejected' => ['purchase_order_number'],
        'purchase_order.received_for_accounting' => ['purchase_order_number'],
    ];

    public function name(string $key): string
    {
        return $this->translateEvent($key, 'name') ?? Str::headline(str_replace('.', ' ', $key));
    }

    public function description(string $key): string
    {
        return $this->translateEvent($key, 'description') ?? '';
    }

    public function languageLabel(string $locale): string
    {
        return self::LANGUAGES[$locale] ?? Str::upper($locale);
    }

    public function isRightToLeft(string $locale): bool
    {
        return $locale === 'ar';
    }

    public function formatLabel(NotificationChannel $channel): string
    {
        return __('notification_templates.formats.'.$channel->value);
    }

    public function subjectLabel(NotificationChannel $channel): string
    {
        return __('notification_templates.subject.'.$channel->value);
    }

    /** @return array<string, string> variable name => friendly label */
    public function variables(string $key, ?string $locale = null): array
    {
        $variables = [];

        foreach (self::VARIABLES[$key] ?? [] as $name) {
            $variables[$name] = (string) __('notification_templates.information.'.$name, [], $locale);
        }

        return $variables;
    }

    /** @return array<string, string> variable name => realistic example value */
    public function sampleValues(string $key, string $locale): array
    {
        $samples = [];

        foreach (self::VARIABLES[$key] ?? [] as $name) {
            $samples[$name] = (string) __('notification_templates.samples.'.$name, [], $locale);
        }

        if (isset($samples['days_overdue']) && preg_match('/\.(\d+)$/', $key, $matches) === 1) {
            $samples['days_overdue'] = $matches[1];
        }

        return $samples;
    }

    /**
     * Placeholders found in the given texts that the notification does not provide.
     *
     * @return list<string>
     */
    public function unsupportedVariables(string $key, ?string ...$texts): array
    {
        $allowed = array_keys($this->variables($key));
        $found = [];

        foreach ($texts as $text) {
            if ($text === null) {
                continue;
            }

            preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', $text, $matches);
            $found = [...$found, ...$matches[1]];
        }

        return array_values(array_unique(array_diff($found, $allowed)));
    }

    /** @return list<string> event keys whose business name contains the term */
    public function keysMatching(string $term): array
    {
        $needle = Str::lower($term);

        return array_values(array_filter(
            array_map(static fn (NotificationEventKey $event): string => $event->value, NotificationEventKey::cases()),
            fn (string $key): bool => str_contains(Str::lower($this->name($key).' '.$this->description($key)), $needle),
        ));
    }

    private function translateEvent(string $key, string $field): ?string
    {
        $path = 'notification_templates.events.'.str_replace('.', '_', $key).'.'.$field;
        $translated = __($path);

        return is_string($translated) && $translated !== $path ? $translated : null;
    }
}
