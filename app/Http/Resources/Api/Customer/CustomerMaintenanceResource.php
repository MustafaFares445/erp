<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Customer;

use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\ServiceAppointment;
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
        $loan = $this->equipmentLoans
            ->filter(static fn (EquipmentLoan $item): bool => $item->status->isActive())
            ->sortByDesc('id')
            ->first()
            ?? $this->equipmentLoans->sortByDesc('id')->first();
        $rma = $this->externalRepairs
            ->filter(static fn (MaintenanceExternalRepair $item): bool => ! $item->status->isClosed())
            ->sortByDesc('id')
            ->first()
            ?? $this->externalRepairs->sortByDesc('id')->first();

        return [
            'id' => $this->getKey(),
            'ticket_id' => $this->ticket_id,
            'maintenance_kind' => $this->maintenance_kind->value,
            'maintenance_kind_label' => $this->maintenance_kind->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'service_stage' => [
                'key' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'description' => $this->description,
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
            'appointment' => $appointment instanceof ServiceAppointment ? [
                'id' => $appointment->getKey(),
                'status' => $appointment->status->value,
                'scheduled_start_at' => $appointment->scheduled_start_at->toIso8601String(),
                'scheduled_end_at' => $appointment->scheduled_end_at->toIso8601String(),
            ] : null,
            'installation' => ! (bool) config('support.equipment_installation_enabled', true) || $this->installation === null ? null : [
                'installed_at' => $this->installation->installed_at?->toIso8601String(),
                'commissioning_status' => $this->installation->commissioning_status?->value,
                'commissioned_at' => $this->installation->commissioned_at?->toIso8601String(),
                'customer_acceptance_status' => $this->installation->customer_acceptance_status?->value,
                'customer_accepted_at' => $this->installation->customer_accepted_at?->toIso8601String(),
            ],
            'calibration' => ! (bool) config('support.calibration_enabled', true) || $this->calibration === null ? null : [
                'result' => $this->calibration->result?->value,
                'calibrated_at' => $this->calibration->calibrated_at?->toIso8601String(),
                'certificate_number' => $this->calibration->certificate_number,
                'certificate_expires_on' => $this->calibration->certificate_expires_on?->toDateString(),
                'next_calibration_due_on' => $this->calibration->next_calibration_due_on?->toDateString(),
            ],
            'loaner' => (bool) config('support.loaner_equipment_enabled', false) && $loan instanceof EquipmentLoan ? [
                'status' => $loan->status->value,
                'serial_number' => $loan->loanerUnit?->serial_number,
                'product' => $loan->loanerUnit?->productVariant?->name,
                'issued_at' => $loan->issued_at?->toIso8601String(),
                'expected_return_at' => $loan->expected_return_at?->toIso8601String(),
                'returned_at' => $loan->returned_at?->toIso8601String(),
            ] : null,
            'rma' => (bool) config('support.external_repair_enabled', false) && $rma instanceof MaintenanceExternalRepair ? [
                'status' => $rma->status->value,
                'rma_number' => $rma->rma_number,
                'supplier' => $rma->supplier?->name,
                'requested_at' => $rma->requested_at?->toIso8601String(),
                'estimated_return_on' => $rma->estimated_return_on?->toDateString(),
                'actual_return_on' => $rma->actual_return_on?->toDateString(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
