<?php

declare(strict_types=1);

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\RelationManagers\ProductSubscriptionsRelationManager;
use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Filament\Resources\ProductSubscriptions\RelationManagers\CustomersRelationManager;
use App\Filament\Resources\ProductSubscriptions\RelationManagers\ProductsRelationManager;

test('registers the product and customer relationship managers on their canonical resources', function (): void {
    expect(ProductSubscriptionResource::getRelations())->toContain(ProductsRelationManager::class, CustomersRelationManager::class)
        ->and(CustomerResource::getRelations())->toContain(ProductSubscriptionsRelationManager::class);
});
