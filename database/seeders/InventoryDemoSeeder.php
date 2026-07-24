<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DentalCatalogSeeder::class);
    }
}
