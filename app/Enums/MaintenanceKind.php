<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaintenanceKind: string implements HasLabel
{
    case Corrective = 'corrective';
    case Preventive = 'preventive';
    case Inspection = 'inspection';
    case Installation = 'installation';
    case Calibration = 'calibration';
    case Other = 'other';

    /**
     * Kinds a recurring maintenance schedule may generate. Corrective work is
     * reactive and never recurs.
     *
     * @return list<self>
     */
    public static function scheduleKinds(): array
    {
        return [self::Preventive, self::Inspection, self::Calibration];
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Corrective => __('Corrective'),
            self::Preventive => __('Preventive'),
            self::Inspection => __('Inspection'),
            self::Installation => __('Installation'),
            self::Calibration => __('Calibration'),
            self::Other => __('Other'),
        };
    }
}
