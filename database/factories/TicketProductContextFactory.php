<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Ticket;
use App\Models\TicketProductContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketProductContext> */
final class TicketProductContextFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => '1.000000',
        ];
    }
}
