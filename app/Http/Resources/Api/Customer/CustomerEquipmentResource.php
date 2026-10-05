<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Customer;

use App\Enums\CommissioningStatus;
use App\Enums\CustomerAcceptanceStatus;
use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\SupportEntitlementStatus;
use App\Models\EquipmentCalibration;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceAppointment;
use App\Models\SupportEntitlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin SerializedInventoryUnit */
final class CustomerEquipmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(Request $request): array
    {
        $entitlement = $this->currentWarrantyEntitlement;
        $today = today();
        $supportEntitlement = $this->supportEntitlements
            ->first(static fn (SupportEntitlement $item): bool => $item->status === SupportEntitlementStatus::Active
                && $item->starts_on->lte($today)
                && ($item->ends_on === null || $item->ends_on->gte($today)));

        /** @var Collection<int, MaintenanceRecord> $maintenance */
        $maintenance = $this->maintenanceRecords;
        $installation = $maintenance
            ->map(static fn (MaintenanceRecord $record): ?EquipmentInstallation => $record->installation)
            ->filter()
            ->sortByDesc('id')
            ->first();
        $lastCalibration = $this->calibrations->sortByDesc('id')->first();
        $activeMaintenance = $maintenance
            ->reject(static fn (MaintenanceRecord $record): bool => in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true))
            ->sortByDesc('id')
            ->first();
        $upcomingVisit = $maintenance
            ->flatMap(static fn (MaintenanceRecord $record): Collection => $record->serviceRecords)
            ->flatMap(static fn (MaintenanceTask $task): Collection => $task->serviceAppointments)
            ->filter(static fn (ServiceAppointment $appointment): bool => $appointment->scheduled_start_at->gte(now()))
            ->sortBy('scheduled_start_at')
            ->first();
        $activeLoan = $maintenance
            ->flatMap(static fn (MaintenanceRecord $record): Collection => $record->equipmentLoans)
            ->filter(static fn (EquipmentLoan $loan): bool => $loan->status->isActive())
            ->sortByDesc('id')
            ->first();
        $currentRma = $maintenance
            ->flatMap(static fn (MaintenanceRecord $record): Collection => $record->externalRepairs)
            ->filter(static fn (MaintenanceExternalRepair $repair): bool => ! $repair->status->isClosed())
            ->sortByDesc('id')
            ->first();

        $nextCalibrationDue = $this->maintenanceSchedules
            ->where('maintenance_kind', MaintenanceKind::Calibration)
            ->where('is_active', true)
            ->whereNotNull('next_due_on')
            ->sortBy('next_due_on')
            ->first()?->next_due_on?->toDateString();

        return [
            'id' => $this->getKey(),
            'serial_number' => $this->serial_number,
            'iot_number' => $this->iot_number,
            'product' => [
                'name' => $this->productVariant?->product?->name,
                'variant' => $this->productVariant?->name,
                'sku' => $this->productVariant?->sku,
            ],
            'condition' => $this->stock_condition->value,
            'warranty' => [
                'state' => $entitlement?->state?->value,
                'policy_name' => $entitlement?->policy_name,
                'starts_on' => $entitlement?->starts_on?->toDateString() ?? $this->warranty_started_on?->toDateString(),
                'expires_on' => $entitlement?->expires_on?->toDateString() ?? $this->warranty_expires_on?->toDateString(),
            ],
            'service_level' => $supportEntitlement?->serviceLevel?->name,
            'next_preventive_due_on' => $this->maintenanceSchedules
                ->where('maintenance_kind', MaintenanceKind::Preventive)
                ->where('is_active', true)
                ->whereNotNull('next_due_on')
                ->sortBy('next_due_on')
                ->first()?->next_due_on?->toDateString(),
            'installation_status' => (bool) config('support.equipment_installation_enabled', true) && $installation instanceof EquipmentInstallation
                ? self::installationStatus($installation)
                : null,
            'installed_at' => (bool) config('support.equipment_installation_enabled', true)
                ? $installation?->installed_at?->toIso8601String()
                : null,
            'commissioning_status' => (bool) config('support.equipment_installation_enabled', true)
                ? $installation?->commissioning_status?->value
                : null,
            'last_calibration' => (bool) config('support.calibration_enabled', true) && $lastCalibration instanceof EquipmentCalibration ? [
                'calibrated_at' => $lastCalibration->calibrated_at?->toIso8601String(),
                'result' => $lastCalibration->result?->value,
                'certificate_number' => $lastCalibration->certificate_number,
            ] : null,
            'next_calibration_due' => (bool) config('support.calibration_enabled', true) ? $nextCalibrationDue : null,
            'active_maintenance' => $activeMaintenance instanceof MaintenanceRecord ? [
                'id' => $activeMaintenance->getKey(),
                'maintenance_kind' => $activeMaintenance->maintenance_kind->value,
                'status' => $activeMaintenance->status->value,
                'status_label' => $activeMaintenance->status->label(),
            ] : null,
            'upcoming_field_visit' => $upcomingVisit instanceof ServiceAppointment ? [
                'id' => $upcomingVisit->getKey(),
                'status' => $upcomingVisit->status->value,
                'scheduled_start_at' => $upcomingVisit->scheduled_start_at->toIso8601String(),
                'scheduled_end_at' => $upcomingVisit->scheduled_end_at->toIso8601String(),
            ] : null,
            'loaner_summary' => (bool) config('support.loaner_equipment_enabled', false) && $activeLoan instanceof EquipmentLoan ? [
                'status' => $activeLoan->status->value,
                'serial_number' => $activeLoan->loanerUnit?->serial_number,
                'product' => $activeLoan->loanerUnit?->productVariant?->name,
                'issued_at' => $activeLoan->issued_at?->toIso8601String(),
                'expected_return_at' => $activeLoan->expected_return_at?->toIso8601String(),
            ] : null,
            'rma_summary' => (bool) config('support.external_repair_enabled', false) && $currentRma instanceof MaintenanceExternalRepair ? [
                'status' => $currentRma->status->value,
                'rma_number' => $currentRma->rma_number,
                'supplier' => $currentRma->supplier?->name,
                'estimated_return_on' => $currentRma->estimated_return_on?->toDateString(),
            ] : null,
        ];
    }

    private static function installationStatus(EquipmentInstallation $installation): string
    {
        if ($installation->customer_acceptance_status === CustomerAcceptanceStatus::Rejected) {
            return 'rejected';
        }

        if ($installation->customer_acceptance_status === CustomerAcceptanceStatus::Accepted) {
            return 'accepted';
        }

        if ($installation->commissioning_status === CommissioningStatus::Failed) {
            return 'commissioning_failed';
        }

        if ($installation->commissioning_status === CommissioningStatus::Passed) {
            return 'commissioned';
        }

        return $installation->installed_at === null ? 'pending' : 'installed';
    }
}
