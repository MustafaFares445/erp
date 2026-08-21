<?php

declare(strict_types=1);

use App\Filament\Pages\CatalogSetup;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ModulePlaceholder;
use App\Filament\PageUsageGuide;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
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

it('explains special pages and CRUD page purposes', function (string $page, string $expected): void {
    expect(PageUsageGuide::for([$page]))->toContain($expected);
})->with([
    [CatalogSetup::class, 'shared categories, brands, attributes, and units'],
    [ModulePlaceholder::class, 'listed for navigation but is not available'],
    [CreateProduct::class, 'Add a new product record'],
    [EditProduct::class, 'Update this product record'],
    [ViewProduct::class, 'Review this product record'],
]);

test('it gives a generic explanation when the scope is not a class name', function (): void {
    expect(PageUsageGuide::for([new stdClass]))
        ->toContain('available information and complete the actions');
});
