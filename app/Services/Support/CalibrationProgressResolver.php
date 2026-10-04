<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\CalibrationMeasurementResult;
use App\Enums\CalibrationResult;
use App\Models\EquipmentCalibration;
use App\Models\EquipmentCalibrationMeasurement;
use App\Models\MaintenanceRecord;

/**
 * Read-only projection of where a calibration stands: the ordered workflow
 * steps, the headline status, the next action and the current blocker. Shared
 * by the maintenance-request panel, Equipment 360 and Field Service so all
 * three describe the same calibration the same way.
 */
final readonly class CalibrationProgressResolver
{
    /**
     * @return array{
     *   status:string, color:string, next_action:string, blocker:?string,
     *   measured:int, total:int, failed:bool,
     *   steps:list<array{key:string,label:string,state:string,detail:?string}>
     * }
     */
    public function resolve(MaintenanceRecord $record, ?EquipmentCalibration $calibration = null): array
    {
        $calibration ??= $record->calibration;

        if (! $calibration instanceof EquipmentCalibration) {
            return $this->notStarted($record);
        }

        $calibration->loadMissing('measurements');
        $total = $calibration->measurements->count();
        $measured = $calibration->measurements->filter(static fn (EquipmentCalibrationMeasurement $measurement): bool => $measurement->isRecorded())->count();
        $outOfTolerance = $calibration->measurements->contains(
            static fn (EquipmentCalibrationMeasurement $measurement): bool => $measurement->is_required && $measurement->result === CalibrationMeasurementResult::Failed,
        );
        $requiredPending = $total === 0 || $calibration->measurements->contains(
            static fn (EquipmentCalibrationMeasurement $measurement): bool => $measurement->is_required && ! $measurement->isRecorded(),
        );
        $result = $calibration->result;
        $certified = $calibration->hasCertificate();

        [$status, $color, $next, $blocker] = match (true) {
            $result === CalibrationResult::Failed => [$this->t('Calibration failed'), 'danger', $this->t('Review the failed measurements and follow up with a repair or adjustment.'), $this->t('Calibration failed.')],
            $result instanceof CalibrationResult && $certified => [$result->label(), $result->getColor(), $this->t('Calibration is complete.'), null],
            $result instanceof CalibrationResult => [$result->label(), $result->getColor(), $this->t('Issue the calibration certificate.'), null],
            $outOfTolerance => [$this->t('Out of tolerance'), 'danger', $this->t('Adjust the equipment and re-measure, or fail the calibration.'), $this->t('A mandatory measurement is out of tolerance.')],
            $requiredPending => [$this->t('Measuring'), 'warning', $this->t('Record the remaining measurements.'), $this->t('Measurements incomplete.')],
            default => [$this->t('Ready to complete'), 'info', $this->t('Complete the calibration.'), null],
        };

        $finished = $result instanceof CalibrationResult;
        $failed = $result === CalibrationResult::Failed;

        return [
            'status' => $status,
            'color' => $color,
            'next_action' => $next,
            'blocker' => $blocker,
            'measured' => $measured,
            'total' => $total,
            'failed' => $failed || $outOfTolerance,
            'steps' => [
                $this->step('equipment', $this->t('Equipment'), 'done', $calibration->serializedInventoryUnit->serial_number ?? $record->serial_number),
                $this->step('measurements', $this->t('Measurements'), $total > 0 && ! $requiredPending ? ($outOfTolerance ? 'failed' : 'done') : 'current', $measured.' / '.$total),
                $this->step('result', $this->t('Result'), $failed ? 'failed' : ($finished ? 'done' : (! $requiredPending && ! $outOfTolerance ? 'current' : 'upcoming')), $result?->label()),
                $this->step('certificate', $this->t('Certificate'), $certified ? 'done' : ($finished && ! $failed ? 'current' : 'upcoming'), $calibration->certificate_number),
                $this->step('next_due', $this->t('Next due'), $calibration->next_calibration_due_on !== null ? 'done' : 'upcoming', $calibration->next_calibration_due_on?->toFormattedDateString()),
            ],
        ];
    }

    /**
     * The unit's most recent finished calibration before this request, for
     * the "previous calibration" line.
     *
     * @return array{result:string, calibrated_at:string, certificate:?string, next_due:?string}|null
     */
    public function previous(MaintenanceRecord $record): ?array
    {
        if ($record->serialized_inventory_unit_id === null) {
            return null;
        }

        $previous = EquipmentCalibration::query()
            ->where('serialized_inventory_unit_id', $record->serialized_inventory_unit_id)
            ->where('maintenance_record_id', '!=', $record->getKey())
            ->whereNotNull('result')
            ->latest('calibrated_at')
            ->first();

        if (! $previous instanceof EquipmentCalibration || ! $previous->result instanceof CalibrationResult) {
            return null;
        }

        return [
            'result' => $previous->result->label(),
            'calibrated_at' => $previous->calibrated_at?->toFormattedDateString() ?? '—',
            'certificate' => $previous->certificate_number,
            'next_due' => $previous->next_calibration_due_on?->toFormattedDateString(),
        ];
    }

    /**
     * @return array{
     *   status:string, color:string, next_action:string, blocker:?string,
     *   measured:int, total:int, failed:bool,
     *   steps:list<array{key:string,label:string,state:string,detail:?string}>
     * }
     */
    private function notStarted(MaintenanceRecord $record): array
    {
        return [
            'status' => $this->t('Calibration not started'),
            'color' => 'gray',
            'next_action' => $this->t('Start the calibration.'),
            'blocker' => $record->serialized_inventory_unit_id === null ? $this->t('Link serialized equipment to this request first.') : null,
            'measured' => 0,
            'total' => 0,
            'failed' => false,
            'steps' => [
                $this->step('equipment', $this->t('Equipment'), 'current', null),
                $this->step('measurements', $this->t('Measurements'), 'upcoming', null),
                $this->step('result', $this->t('Result'), 'upcoming', null),
                $this->step('certificate', $this->t('Certificate'), 'upcoming', null),
                $this->step('next_due', $this->t('Next due'), 'upcoming', null),
            ],
        ];
    }

    /** @return array{key:string,label:string,state:string,detail:?string} */
    private function step(string $key, string $label, string $state, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail];
    }

    private function t(string $key): string
    {
        return (string) __($key);
    }
}
