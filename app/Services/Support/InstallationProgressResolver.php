<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\CommissioningStatus;
use App\Enums\CustomerAcceptanceStatus;
use App\Enums\InstallationCheckResult;
use App\Enums\WarrantyEntitlementState;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentInstallationCheck;
use App\Models\MaintenanceRecord;
use App\Models\WarrantyEntitlement;

/**
 * Read-only projection of where an installation stands: the ordered workflow
 * steps, the headline status, the next action and the current blocker. Shared
 * by the maintenance-request panel, Equipment 360 and Field Service so all
 * three describe the same installation the same way.
 */
final readonly class InstallationProgressResolver
{
    /**
     * @return array{
     *   status:string, color:string, next_action:string, blocker:?string,
     *   checks_done:int, checks_total:int, failed:bool,
     *   steps:list<array{key:string,label:string,state:string,detail:?string}>
     * }
     */
    public function resolve(MaintenanceRecord $record, ?EquipmentInstallation $installation = null): array
    {
        $installation ??= $record->installation;

        if (! $installation instanceof EquipmentInstallation) {
            return $this->notStarted($record);
        }

        $installation->loadMissing('checks');
        $total = $installation->checks->count();
        $done = $installation->checks
            ->filter(static fn (EquipmentInstallationCheck $check): bool => $check->result->satisfiesCommissioning())
            ->count();
        $anyFailedCheck = $installation->checks->contains(static fn (EquipmentInstallationCheck $check): bool => $check->result === InstallationCheckResult::Failed);

        $installed = $installation->isInstalled();
        $commissioning = $installation->commissioning_status;
        $acceptance = $installation->customer_acceptance_status;
        $commissionFailed = $commissioning === CommissioningStatus::Failed;

        [$status, $color, $next, $blocker] = match (true) {
            $acceptance === CustomerAcceptanceStatus::Accepted => [$this->t('Accepted'), 'success', $this->t('Installation is complete.'), null],
            $acceptance === CustomerAcceptanceStatus::Rejected => [$this->t('Rejected'), 'danger', $this->t('Review the customer rejection and agree the next step.'), $this->t('The customer rejected the installation.')],
            $commissioning === CommissioningStatus::Passed => [$this->t('Awaiting customer acceptance'), 'info', $this->t('Record the customer acceptance or rejection.'), null],
            $commissionFailed => [$this->t('Commissioning failed'), 'danger', $this->t('Correct the fault, update the checks and retry commissioning.'), $this->t('Commissioning failed.')],
            $installed => [$this->t('Installation completed'), 'warning', $this->t('Pass every checklist item, then pass commissioning.'), $done < $total ? $this->t('Checklist incomplete.') : null],
            default => [$this->t('Pending installation'), 'gray', $this->t('Complete the installation on site.'), null],
        };

        return [
            'status' => $status,
            'color' => $color,
            'next_action' => $next,
            'blocker' => $blocker,
            'checks_done' => $done,
            'checks_total' => $total,
            'failed' => $commissionFailed || $anyFailedCheck || $acceptance === CustomerAcceptanceStatus::Rejected,
            'steps' => [
                $this->step('equipment', $this->t('Shipment / Equipment'), 'done', $installation->shipment?->tracking_number),
                $this->step('installation', $this->t('Installation'), $installed ? 'done' : 'current', $installation->installed_at?->toDayDateTimeString()),
                $this->step('checklist', $this->t('Checklist'), $total > 0 && $done === $total ? 'done' : ($installed ? 'current' : 'upcoming'), $done.' / '.$total),
                $this->step(
                    'commissioning',
                    $this->t('Commissioning'),
                    $commissioning === CommissioningStatus::Passed ? 'done' : ($commissionFailed ? 'failed' : ($installed ? 'current' : 'upcoming')),
                    $installation->commissioned_at?->toDayDateTimeString(),
                ),
                $this->step(
                    'acceptance',
                    $this->t('Customer acceptance'),
                    match ($acceptance) {
                        CustomerAcceptanceStatus::Accepted => 'done',
                        CustomerAcceptanceStatus::Rejected => 'failed',
                        CustomerAcceptanceStatus::Pending => $commissioning === CommissioningStatus::Passed ? 'current' : 'upcoming',
                    },
                    $installation->customer_signatory_name,
                ),
                $this->warrantyStep($record),
            ],
        ];
    }

    /**
     * @return array{
     *   status:string, color:string, next_action:string, blocker:?string,
     *   checks_done:int, checks_total:int, failed:bool,
     *   steps:list<array{key:string,label:string,state:string,detail:?string}>
     * }
     */
    private function notStarted(MaintenanceRecord $record): array
    {
        return [
            'status' => $this->t('Installation not started'),
            'color' => 'gray',
            'next_action' => $this->t('Start the installation.'),
            'blocker' => $record->serialized_inventory_unit_id === null ? $this->t('Link serialized equipment to this request first.') : null,
            'checks_done' => 0,
            'checks_total' => 0,
            'failed' => false,
            'steps' => [
                $this->step('equipment', $this->t('Shipment / Equipment'), 'current', null),
                $this->step('installation', $this->t('Installation'), 'upcoming', null),
                $this->step('checklist', $this->t('Checklist'), 'upcoming', null),
                $this->step('commissioning', $this->t('Commissioning'), 'upcoming', null),
                $this->step('acceptance', $this->t('Customer acceptance'), 'upcoming', null),
                $this->warrantyStep($record),
            ],
        ];
    }

    /** @return array{key:string,label:string,state:string,detail:?string} */
    private function warrantyStep(MaintenanceRecord $record): array
    {
        $entitlement = $record->serialized_inventory_unit_id === null
            ? null
            : WarrantyEntitlement::query()
                ->where('serialized_inventory_unit_id', $record->serialized_inventory_unit_id)
                ->where('customer_id', $record->customer_id)
                ->latest('id')
                ->first();

        if (! $entitlement instanceof WarrantyEntitlement) {
            return $this->step('warranty', $this->t('Warranty activation'), 'upcoming', $this->t('No warranty policy applies.'));
        }

        return match ($entitlement->state) {
            WarrantyEntitlementState::Active => $this->step('warranty', $this->t('Warranty activation'), 'done', $this->t('Active until :date', ['date' => $entitlement->expires_on?->toDateString() ?? '—'])),
            WarrantyEntitlementState::PendingActivation => $this->step('warranty', $this->t('Warranty activation'), 'upcoming', $this->t('Starts on :trigger', ['trigger' => $entitlement->start_trigger->label()])),
            default => $this->step('warranty', $this->t('Warranty activation'), 'upcoming', $entitlement->state->label()),
        };
    }

    /** @return array{key:string,label:string,state:string,detail:?string} */
    private function step(string $key, string $label, string $state, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail];
    }

    /** @param array<string, scalar> $replace */
    private function t(string $key, array $replace = []): string
    {
        return (string) __($key, $replace);
    }
}
