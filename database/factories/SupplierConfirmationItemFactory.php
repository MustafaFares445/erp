<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SupplierConfirmationStatus;
use App\Models\ProductVariant;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierConfirmationItem> */
final class SupplierConfirmationItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_confirmation_id' => SupplierConfirmation::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'requested_quantity' => fake()->randomFloat(3, 1, 50),
            'confirmation_status' => SupplierConfirmationStatus::Pending,
        ];
    }
}
