<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has no product subscription schema runtime class or route', function (): void {
    expect(Schema::hasTable('product_subscriptions'))->toBeFalse()
        ->and(Schema::hasTable('product_subscription_products'))->toBeFalse()
        ->and(Schema::hasTable('customer_product_subscriptions'))->toBeFalse()
        ->and(class_exists('App\\Models\\ProductSubscription'))->toBeFalse()
        ->and(collect(Route::getRoutes())->contains(fn ($route): bool => str_contains((string) $route->uri(), 'product-subscriptions')))->toBeFalse();
});
