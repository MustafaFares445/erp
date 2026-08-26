<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductSupport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierProductSupport> */
final class SupplierProductSupportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'is_active' => true,
        ];
    }
}
