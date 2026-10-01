<?php

declare(strict_types=1);

namespace App\Enums;

enum WarrantyLineCategory: string
{
    case Part = 'part';
    case Labour = 'labour';
    case Travel = 'travel';
    case ThirdParty = 'third_party';
    case Consumable = 'consumable';
    case Other = 'other';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
