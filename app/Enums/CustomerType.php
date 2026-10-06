<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerType: string
{
    case Clinic = 'clinic';
    case DentalLab = 'dental_lab';
    case Hospital = 'hospital';
    case Distributor = 'distributor';
    case Individual = 'individual';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Clinic => 'Clinic',
            self::DentalLab => 'Dental Lab',
            self::Hospital => 'Hospital',
            self::Distributor => 'Distributor',
            self::Individual => 'Individual',
            self::Other => 'Other',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
