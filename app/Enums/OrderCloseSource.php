<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Order;

/**
 * Who finalised an {@see Order}'s completion (WP: customer
 * completion & auto-close). Deliberately excludes a Sales/Admin case — final
 * close is owned by the customer or the system, never a dashboard user.
 */
enum OrderCloseSource: string
{
    case Customer = 'customer';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Customer => __(__('Customer')),
            self::System => __(__('System auto-close')),
        };
    }
}
