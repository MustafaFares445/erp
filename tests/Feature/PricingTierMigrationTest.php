<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the unified fresh pricing tier schema', function (): void {
    expect(Schema::hasColumns('pricing_tiers', [
        'name',
        'tier_type',
        'discount_type',
        'discount_value',
        'customer_user_id',
        'visibility',
        'valid_from',
        'valid_until',
        'is_active',
        'deleted_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('pricing_tier_products', ['pricing_tier_id', 'product_id']))->toBeTrue()
        ->and(Schema::hasColumns('customer_pricing_tiers', ['customer_user_id', 'pricing_tier_id', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('price_floor_overrides', ['pricing_tier_id']))->toBeTrue()
        ->and(Schema::hasColumn('price_floor_overrides', 'product_subscription_id'))->toBeFalse();
});
