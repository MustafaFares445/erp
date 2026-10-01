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
            self::ConfirmedDelivery => __(__('Confirmed customer delivery')),
            self::Installation => __(__('Installation')),
            self::Commissioning => __(__('Commissioning')),
            self::ManualActivation => __(__('Manual activation')),
        };
    }
}
