<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryReturnType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
}
