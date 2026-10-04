<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEquipment\Pages;

use App\Enums\MaintenanceKind;
use App\Enums\SerializedCustodyType;
use App\Enums\SupportEntitlementStatus;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\SupportEquipment\SupportEquipmentResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\CustomerProfile;
use App\Models\EquipmentCalibration;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\SerializedInventoryUnit;
use App\Models\SupportEntitlement;
use App\Models\Ticket;
use App\Services\Support\CalibrationProgressResolver;
use App\Services\Support\EquipmentReliabilityService;
use App\Services\Support\InstallationProgressResolver;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use LogicException;

final class ViewSupportEquipment extends ViewRecord
{
    protected static string $resource = SupportEquipmentResource::class;

    #[\Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.support-equipment.equipment-360')
                ->viewData(fn (): array => $this->equipmentViewData()),
        ]);
    }

    /** @return array<string, mixed> */
    private function equipmentViewData(): array
    {
        $unit = $this->equipment();
        $unit->loadMissing([
            'productVariant.product',
            'currentWarrantyEntitlement',
            'supportEntitlements.serviceLevel',
        ]);

        $tickets = $unit->tickets()
            ->with('customer:id,company_name')
            ->latest('id')
            ->limit(20)
            ->get();

        $maintenance = $unit->maintenanceRecords()
            ->with('warrantyRecoveryClaim')
            ->latest('id')
            ->limit(20)
            ->get();

        $schedules = $unit->maintenanceSchedules()
            ->latest('id')
            ->get();

        $customer = $unit->custody_type === SerializedCustodyType::Customer && is_numeric($unit->custody_reference_id)
            ? CustomerProfile::query()->find((int) $unit->custody_reference_id)
            : null;

        $supportEntitlement = $unit->supportEntitlements->first(static fn (SupportEntitlement $entitlement): bool => $entitlement->status === SupportEntitlementStatus::Active
            && $entitlement->starts_on->lte(today())
            && ($entitlement->ends_on === null || $entitlement->ends_on->gte(today())));

        $installation = EquipmentInstallation::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->with(['maintenanceRecord', 'shipment', 'installedBy.user', 'commissionedBy.user', 'checks', 'media'])
            ->latest('id')
            ->first();
        $installationRecord = $installation instanceof EquipmentInstallation
            ? $installation->maintenanceRecord
            : $unit->maintenanceRecords()->where('maintenance_kind', MaintenanceKind::Installation->value)->latest('id')->first();

        $lastCalibration = EquipmentCalibration::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->whereNotNull('result')
            ->with(['maintenanceRecord', 'measurements', 'performedBy.user', 'externalProvider', 'media'])
            ->latest('calibrated_at')
            ->latest('id')
            ->first();
        $openCalibrationRecord = $unit->maintenanceRecords()
            ->where('maintenance_kind', MaintenanceKind::Calibration->value)
            ->with('calibration.measurements')
            ->latest('id')
            ->get()
            ->first(static fn (MaintenanceRecord $record): bool => ! $record->isFinalised());
        $nextDueByKind = $schedules
            ->where('is_active', true)
            ->groupBy(static fn (MaintenanceSchedule $schedule): string => $schedule->maintenance_kind->value)
            ->map(static fn (Collection $group): mixed => $group->min('next_due_on'));

        $loanCollections = [
            'loansAsOriginal' => EquipmentLoan::query()
                ->where('original_serialized_inventory_unit_id', $unit->getKey())
                ->with(['loanerUnit', 'maintenanceRecord'])
                ->latest('id')
                ->limit(5)
                ->get(),
            'loansAsLoaner' => EquipmentLoan::query()
                ->where('loaner_serialized_inventory_unit_id', $unit->getKey())
                ->with(['customer', 'maintenanceRecord'])
                ->latest('id')
                ->limit(10)
                ->get(),
        ];
        $externalRepairs = MaintenanceExternalRepair::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->with(['supplier', 'replacementUnit', 'maintenanceRecord'])
            ->latest('id')
            ->limit(5)
            ->get();
        $replacementFor = MaintenanceExternalRepair::query()
            ->where('replacement_serialized_inventory_unit_id', $unit->getKey())
            ->with('serializedInventoryUnit')
            ->first();

        return [
            ...$loanCollections,
            'externalRepairs' => $externalRepairs,
            'replacementFor' => $replacementFor,
            'lastCalibration' => $lastCalibration,
            'openCalibrationRecord' => $openCalibrationRecord,
            'calibrationProgress' => $openCalibrationRecord instanceof MaintenanceRecord
                ? app(CalibrationProgressResolver::class)->resolve($openCalibrationRecord)
                : null,
            'nextDueByKind' => $nextDueByKind,
            'installation' => $installation,
            'installationRecord' => $installationRecord,
            'installationProgress' => $installationRecord instanceof MaintenanceRecord
                ? app(InstallationProgressResolver::class)->resolve($installationRecord, $installation)
                : null,
            'unit' => $unit,
            'customer' => $customer,
            'supportEntitlement' => $supportEntitlement,
            'metrics' => app(EquipmentReliabilityService::class)->metrics($unit),
            'tickets' => $tickets,
            'maintenanceRecords' => $maintenance,
            'schedules' => $schedules,
            'ticketUrl' => static fn (Ticket $ticket): string => TicketResource::getUrl('view', ['record' => $ticket]),
            'maintenanceUrl' => static fn (MaintenanceRecord $record): string => MaintenanceRequestResource::getUrl('view', ['record' => $record]),
        ];
    }

    private function equipment(): SerializedInventoryUnit
    {
        $record = $this->getRecord();

        if (! $record instanceof SerializedInventoryUnit) {
            throw new LogicException('Expected serialized equipment.');
        }

        return $record;
    }
}
