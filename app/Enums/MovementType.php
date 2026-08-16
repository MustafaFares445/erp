<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\InventoryMovement;

/**
 * Classifies an {@see InventoryMovement} row.
 *
 * The `inventory_movements.movement_type` column stays a plain string for
 * engine portability (ERD §6); this enum is the application-layer, type-safe
 * view over it, driving the table badge color and the type filter options.
 */
enum MovementType: string
{
    case Sale = 'sale';
    case Return = 'return';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case Reservation = 'reservation';
    case Receipt = 'receipt';
    case Damage = 'damage';
    case DamageRecovery = 'damage_recovery';
    case Disposal = 'disposal';
    case ServiceConsumption = 'service_consumption';
}
