<?php

declare(strict_types=1);

use App\Models\PackageType;
use Database\Seeders\PackageTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the default package types idempotently', function (): void {
    $this->seed(PackageTypeSeeder::class);
    $this->seed(PackageTypeSeeder::class);

    expect(PackageType::query()->count())->toBe(5)
        ->and(PackageType::query()->pluck('code')->all())->toEqual([
            'BOX',
            'BOTTLE',
            'BAG',
            'ROLL',
            'PIECE',
        ]);
});
