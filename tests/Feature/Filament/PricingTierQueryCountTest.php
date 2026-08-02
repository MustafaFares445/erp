<?php

declare(strict_types=1);

use App\Enums\InventoryReportType;
use App\Filament\Resources\PricingTiers\PricingTierResource;
use App\Models\PricingTier;
use App\Services\Inventory\InventoryReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('keeps tier list and report queries bounded as records grow', function (): void {
    PricingTier::factory()->count(25)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    PricingTierResource::getEloquentQuery()->paginate(25);
    $listQueries = count(DB::getQueryLog());

    DB::flushQueryLog();
    app(InventoryReportService::class)->query(InventoryReportType::PricingTiers)->limit(25)->get();
    $reportQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($listQueries)->toBeLessThanOrEqual(4)
        ->and($reportQueries)->toBeLessThanOrEqual(3);
});
