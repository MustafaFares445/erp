<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallationCheckResult;
use Database\Factories\EquipmentInstallationCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'equipment_installation_id',
    'check_key',
    'label',
    'result',
    'measured_value',
    'unit',
    'notes',
    'sort_order',
])]
final class EquipmentInstallationCheck extends Model
{
    /** @use HasFactory<EquipmentInstallationCheckFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'result' => InstallationCheckResult::class,
            'measured_value' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<EquipmentInstallation, $this> */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(EquipmentInstallation::class, 'equipment_installation_id');
    }
}
