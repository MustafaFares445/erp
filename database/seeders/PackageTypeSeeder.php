<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PackageType;
use Illuminate\Database\Seeder;

final class PackageTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Box', 'code' => 'BOX'],
            ['name' => 'Bottle', 'code' => 'BOTTLE'],
            ['name' => 'Bag', 'code' => 'BAG'],
            ['name' => 'Roll', 'code' => 'ROLL'],
            ['name' => 'Piece', 'code' => 'PIECE'],
        ] as $packageType) {
            PackageType::query()->updateOrCreate(
                ['code' => $packageType['code']],
                ['name' => $packageType['name'], 'is_active' => true],
            );
        }
    }
}
