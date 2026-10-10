<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Filament\LocalizedResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\MaintenanceTask;
use App\Models\ServiceAppointment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** Operational date fields only: created_at / updated_at are deliberately excluded. */
final class ResourceCalendarEventService
{
    /** @return array<string, array{0:non-empty-string,1:non-empty-string,2:array<non-empty-string,non-empty-string>,3:non-empty-string,4?:list<string>,5?:bool}> */
    public static function definitions(): array
    {
        // Resource folder, singular resource class, date labels, title field, timed fields, expand to month.
        return [
            'tasks' => ['Tasks', 'Task', ['starts_at' => 'Starts', 'due_at' => 'Due'], 'title'],
            'monthly-plans' => ['MonthlyPlans', 'MonthlyPlan', ['month' => 'Monthly plan'], 'name', [], true],
            'inventory-lots' => ['InventoryLots', 'InventoryLot', ['expires_at' => 'Lot expiry'], 'lot_number'],
            'serialized-inventory-units' => ['SerializedInventoryUnits', 'SerializedInventoryUnit', ['warranty_expires_on' => 'Warranty expiry'], 'serial_number'],
            'service-appointments' => ['ServiceAppointments', 'ServiceAppointment', ['scheduled_start_at' => 'Appointment'], 'serviceRecord.title', ['scheduled_start_at']],
            'campaigns' => ['Campaigns', 'Campaign', ['scheduled_at' => 'Campaign'], 'name', ['scheduled_at']],
            'tickets' => ['Tickets', 'Ticket', ['response_due_at' => 'Response SLA', 'resolution_due_at' => 'Resolution SLA'], 'title', ['response_due_at', 'resolution_due_at']],
            'invoices' => ['Invoices', 'Invoice', ['due_date' => 'Payment due'], 'invoice_number'],
            'quotations' => ['Quotations', 'Quotation', ['expires_at' => 'Quotation expiry'], 'quotation_number'],
            'purchase-orders' => ['PurchaseOrders', 'PurchaseOrder', ['expected_at' => 'Expected delivery'], 'purchase_order_number'],
            'purchase-agreements' => ['PurchaseAgreements', 'PurchaseAgreement', ['starts_on' => 'Agreement begins', 'ends_on' => 'Agreement ends'], 'supplier.name'],
            'purchase-rfqs' => ['PurchaseRfqs', 'PurchaseRfq', ['needed_by' => 'Needed by', 'closes_at' => 'RFQ closes'], 'id', ['closes_at']],
            'orders' => ['Orders', 'Order', ['scheduled_at' => 'Scheduled order'], 'order_number', ['scheduled_at']],
            'inventory-reservations' => ['InventoryReservations', 'InventoryReservation', ['expires_at' => 'Reservation expiry'], 'id', ['expires_at']],
            'sales-opportunities' => ['SalesOpportunities', 'SalesOpportunity', ['expected_close_date' => 'Expected close'], 'title'],
            'support-entitlements' => ['SupportEntitlements', 'SupportEntitlement', ['starts_on' => 'Coverage begins', 'ends_on' => 'Coverage ends'], 'external_reference'],
            'fiscal-periods' => ['FiscalPeriods', 'FiscalPeriod', ['starts_at' => 'Period begins', 'ends_at' => 'Period ends'], 'name'],
            'bills' => ['Bills', 'Bill', ['due_date' => 'Bill due'], 'bill_number'],
            'service-records' => ['ServiceRecords', 'ServiceRecord', ['due_at' => 'Service due'], 'title', ['due_at']],
            'delivery-notes' => ['DeliveryNotes', 'DeliveryNote', ['scheduled_at' => 'Delivery scheduled'], 'operation_number', ['scheduled_at']],
            'expenses' => ['Expenses', 'Expense', ['due_date' => 'Expense due'], 'id'],
            'pricing-tiers' => ['PricingTiers', 'PricingTier', ['valid_from' => 'Price validity begins', 'valid_until' => 'Price validity ends'], 'name'],
            'supplier-confirmations' => ['SupplierConfirmations', 'SupplierConfirmation', ['promised_at' => 'Supplier promised date'], 'id', ['promised_at']],
            'maintenance-requests' => ['MaintenanceRequests', 'MaintenanceRequest', ['warranty_expiry_date' => 'Warranty expiry'], 'id'],
            'supplier-product-references' => ['SupplierProductReferences', 'SupplierProductReference', ['valid_from' => 'Supplier reference begins'], 'id'],
        ];
    }

    /** @return Collection<int|string, Collection<int, array{date:string,time:string|null,title:string,subtitle:string|null,status:string,url:string}>> */
    public function between(string $key, Carbon $from, Carbon $until): Collection
    {
        $config = self::definitions()[$key] ?? throw new InvalidArgumentException('Unknown calendar: '.$key);
        [$folder, $name, $dates, $titleField] = $config;
        /** @var class-string<LocalizedResource> $resource */
        $resource = "App\\Filament\\Resources\\{$folder}\\{$name}Resource";
        $monthSpan = $config[5] ?? false;
        $query = $resource::getEloquentQuery();
        $query->where(static function (Builder $query) use ($dates, $from, $until, $monthSpan): void {
            foreach (array_keys($dates) as $field) {
                $begin = $monthSpan ? $from->copy()->startOfMonth() : $from->copy()->startOfDay();
                $query->orWhereBetween($field, [$begin, $until->copy()->endOfDay()]);
            }
        });

        if ($key === 'inventory-lots') {
            $query->whereHas('conditionBalances', static fn (Builder $balances): Builder => $balances->where('on_hand_base_quantity', '>', 0));
        }

        /** @var Collection<int, array{date:string,time:string|null,title:string,subtitle:string|null,status:string,url:string}> $events */
        $events = collect();
        $pages = $resource::getPages();
        $destination = isset($pages['view']) ? 'view' : (isset($pages['edit']) ? 'edit' : 'index');

        foreach ($query->get() as $record) {
            $rawTitle = str_contains($titleField, '.') ? data_get($record, $titleField) : $record->getAttribute($titleField);
            $recordKey = $record->getKey();
            $fallbackTitle = '#'.(is_scalar($recordKey) ? (string) $recordKey : '?');
            $title = $titleField !== 'id' && is_scalar($rawTitle) && $rawTitle !== ''
                ? (string) $rawTitle
                : $fallbackTitle;
            $target = $destination;
            if ($target === 'view' && ! $resource::canView($record)) {
                $target = isset($pages['edit']) && $resource::canEdit($record) ? 'edit' : 'index';
            } elseif ($target === 'edit' && ! $resource::canEdit($record)) {
                $target = 'index';
            }
            $url = $resource::getUrl($target, $target === 'index' ? [] : ['record' => $record]);
            if ($key === 'service-appointments' && $record instanceof ServiceAppointment) {
                $serviceRecord = $record->serviceRecord;
                if ($serviceRecord instanceof MaintenanceTask && ServiceRecordResource::canView($serviceRecord)) {
                    $url = ServiceRecordResource::getUrl('view', ['record' => $serviceRecord]);
                }
            }
            $status = $record->getAttribute('status');
            $statusLabel = $this->statusLabel($status);
            $context = match ($key) {
                'tasks' => data_get($record, 'salesPlan.name'),
                'monthly-plans', 'service-appointments' => data_get($record, 'employee.user.name'),
                'purchase-orders', 'purchase-agreements' => data_get($record, 'supplier.name'),
                default => null,
            };
            $subtitle = implode(' · ', array_filter([is_string($context) ? $context : null, $statusLabel])) ?: null;

            foreach ($dates as $field => $label) {
                $rawDate = $record->getAttribute($field);
                if (! $rawDate instanceof \DateTimeInterface && ! is_string($rawDate) && ! is_int($rawDate) && ! is_float($rawDate)) {
                    continue;
                }
                $date = $rawDate instanceof CarbonInterface ? Carbon::instance($rawDate) : Carbon::parse($rawDate);
                $day = $monthSpan ? $date->copy()->startOfMonth() : $date->copy()->startOfDay();
                $last = $monthSpan ? $date->copy()->endOfMonth() : $date->copy()->endOfDay();
                $day = $day->max($from->copy()->startOfDay());
                $last = $last->min($until->copy()->endOfDay());

                while ($day->lte($last)) {
                    $events->push([
                        'date' => $day->toDateString(),
                        'time' => in_array($field, $config[4] ?? [], true) ? $date->format('H:i') : null,
                        'title' => $title,
                        'subtitle' => $subtitle,
                        'status' => $this->translate($label),
                        'url' => $url,
                    ]);
                    $day->addDay();
                }
            }
        }

        return $events->sortBy(static fn (array $event): string => $event['date'].' '.($event['time'] ?? ''))->values()->groupBy('date');
    }

    private function statusLabel(mixed $status): ?string
    {
        if ($status instanceof \BackedEnum) {
            if (method_exists($status, 'getLabel')) {
                $label = $status->getLabel();

                return is_scalar($label) ? (string) $label : null;
            }
            if (method_exists($status, 'label')) {
                $label = $status->label();

                return is_scalar($label) ? (string) $label : null;
            }

            return $this->translate(str_replace('_', ' ', (string) $status->value));
        }

        return is_string($status) && $status !== '' ? $this->translate($status) : null;
    }

    private function translate(string $value): string
    {
        $translation = __($value);

        return is_string($translation) ? $translation : $value;
    }
}
