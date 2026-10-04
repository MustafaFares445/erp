<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Customer;

use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Services\Settings\CurrencyCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin MaintenanceRecord */
final class CustomerMaintenanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(Request $request): array
    {
        $customerAmountMinor = $this->coverageLines->sum(static fn (MaintenanceCoverageLine $line): int => $line->customer_amount_minor);
        $appointment = $this->serviceRecords
            ->flatMap(static fn (MaintenanceTask $task): Collection => $task->serviceAppointments)
            ->sortByDesc('scheduled_start_at')
            ->first();

        return [
            'id' => $this->getKey(),
            'ticket_id' => $this->ticket_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'description' => $this->description,
            'diagnosis_summary' => $this->diagnosis_summary,
            'customer_coverage_explanation' => $this->customer_coverage_explanation,
            'coverage' => [
                'decision' => $this->coverage_decision->value,
                'decision_label' => $this->coverage_decision->label(),
                'customer_amount_minor' => $customerAmountMinor,
                'currency' => app(CurrencyCatalogService::class)->defaultCode(),
            ],
            'equipment' => [
                'serialized_inventory_unit_id' => $this->serialized_inventory_unit_id,
                'name' => $this->serializedInventoryUnit?->productVariant->name
                    ?? $this->productVariant?->name,
                'serial_number' => $this->serial_number,
            ],
            'quotation' => $this->quotation === null || $this->quotation->status === QuotationStatus::Draft ? null : [
                'id' => $this->quotation->getKey(),
                'status' => $this->quotation->status->value,
                'total' => (float) $this->quotation->grand_total,
            ],
            'invoice' => $this->invoice === null || $this->invoice->status === InvoiceStatus::Draft ? null : [
                'id' => $this->invoice->getKey(),
                'status' => $this->invoice->status->value,
                'total' => (float) $this->invoice->total_amount,
            ],
            'appointment' => $appointment === null ? null : [
                'id' => $appointment->getKey(),
                'status' => $appointment->status->value,
                'scheduled_start_at' => $appointment->scheduled_start_at->toIso8601String(),
                'scheduled_end_at' => $appointment->scheduled_end_at->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
