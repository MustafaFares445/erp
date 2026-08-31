<?php

declare(strict_types=1);

namespace App\Enums;

enum StockCondition: string
{
    case Saleable = 'saleable';
    case Quarantine = 'quarantine';
    case Damaged = 'damaged';
    case Disposed = 'disposed';

    public function isMaterialized(): bool
    {
        return $this !== self::Disposed;
    }

    public function allowsReservation(): bool
    {
        return $this === self::Saleable;
    }
}
