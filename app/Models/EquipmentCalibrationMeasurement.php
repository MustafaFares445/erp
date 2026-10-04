<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CalibrationMeasurementResult;
use Database\Factories\EquipmentCalibrationMeasurementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'equipment_calibration_id',
    'measurement_key',
    'label',
    'expected_value',
    'minimum_value',
    'maximum_value',
    'actual_value',
    'unit',
    'is_required',
    'result',
    'notes',
    'sort_order',
])]
final class EquipmentCalibrationMeasurement extends Model
{
    /** @use HasFactory<EquipmentCalibrationMeasurementFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'expected_value' => 'decimal:4',
            'minimum_value' => 'decimal:4',
            'maximum_value' => 'decimal:4',
            'actual_value' => 'decimal:4',
            'is_required' => 'boolean',
            'result' => CalibrationMeasurementResult::class,
            'sort_order' => 'integer',
        ];
    }

    public function isRecorded(): bool
    {
        return $this->actual_value !== null;
    }

    /** @return BelongsTo<EquipmentCalibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(EquipmentCalibration::class, 'equipment_calibration_id');
    }
}
