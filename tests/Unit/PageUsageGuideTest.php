<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\PageUsageGuide;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\ProductResource;

test('it explains the purpose of resource pages', function (): void {
    expect(PageUsageGuide::for([ManageProducts::class, ProductResource::class]))
        ->toContain('products')
        ->toContain('search and filters');
});

test('it explains the dashboard purpose', function (): void {
    expect(PageUsageGuide::for([Dashboard::class]))
        ->toContain('pending documents')
        ->toContain('low stock');
});
