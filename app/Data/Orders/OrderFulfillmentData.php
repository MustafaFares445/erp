<?php

declare(strict_types=1);

namespace App\Data\Orders;

use App\Enums\DeliveryType;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * @phpstan-type FormState array<array-key, mixed>
 */
final readonly class OrderFulfillmentData
{
    /**
     * @param  FormState  $products
     * @param  FormState  $shipments  each shipment may carry its own `delivery_type`; a shipment
     *                                without one defaults to {@see DeliveryType::Inner}
     * @param  array<string, string>  $documents  temporary delivery-document paths keyed by collection
     */
    public function __construct(
        public CustomerProfile $customer,
        public array $products,
        public array $shipments,
        public User $actor,
        public ?string $notes,
        public array $documents = [],
        public ?CustomerDeliveryAddress $deliveryAddress = null,
        public ?CarbonInterface $scheduledAt = null,
        public ?User $responsible = null,
    ) {}
}
