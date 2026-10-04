<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SupportServiceLevel;
use Illuminate\Database\Seeder;

final class SupportServiceLevelSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'STANDARD', 'name' => 'Standard', 'description' => 'Default support service level.'],
            ['code' => 'PRIORITY', 'name' => 'Priority', 'description' => 'Faster contractual support response and resolution.'],
            ['code' => 'PREMIUM', 'name' => 'Premium', 'description' => 'Highest contractual support service level.'],
        ] as $level) {
            SupportServiceLevel::query()->updateOrCreate(
                ['code' => $level['code']],
                [...$level, 'is_active' => true],
            );
        }
    }
}
