<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\OrderStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/**
 * Normalizes pre-canonical sales-order status values at the model boundary.
 *
 * Historical rows and older workflow writers used operational labels such as
 * `ready` and supplier-confirmation states directly on orders. The canonical
 * sales lifecycle now uses OrderStatus, while those operational details live
 * on fulfillment/procurement records and pending_reason.
 *
 * @implements CastsAttributes<OrderStatus, OrderStatus|string>
 */
final class OrderStatusCast implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): OrderStatus
    {
        if ($value instanceof OrderStatus) {
            return $value;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException('Order status must be a string.');
        }

        return match ($value) {
            'ready' => OrderStatus::Released,
            'pending_supplier_confirmation', 'supplier_confirmed', 'supplier_rejected' => OrderStatus::Confirmed,
            default => OrderStatus::from($value),
        };
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value instanceof OrderStatus) {
            return $value->value;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException('Order status must be an OrderStatus or string.');
        }

        return match ($value) {
            'ready' => OrderStatus::Released->value,
            'pending_supplier_confirmation', 'supplier_confirmed', 'supplier_rejected' => OrderStatus::Confirmed->value,
            default => OrderStatus::from($value)->value,
        };
    }
}
