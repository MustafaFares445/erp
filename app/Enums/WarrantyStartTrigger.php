<?php

declare(strict_types=1);

namespace App\Enums;

enum WarrantyStartTrigger: string
{
    case ConfirmedDelivery = 'confirmed_delivery';
    case Installation = 'installation';
    case Commissioning = 'commissioning';
    case ManualActivation = 'manual_activation';

    public function label(): string
    {
        return match ($this) {
            self::ConfirmedDelivery => 'Confirmed customer delivery',
            self::Installation => 'Installation',
            self::Commissioning => 'Commissioning',
            self::ManualActivation => 'Manual activation',
        };
    }
}
