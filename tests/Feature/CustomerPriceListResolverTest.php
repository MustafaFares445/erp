<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\ResolvedPriceSource;
use App\Models\Currency;
use App\Models\CustomerGroup;
use App\Models\CustomerProfile;
use App\Models\InvoiceLine;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Services\Inventory\PriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Currency::query()->updateOrCreate(
        ['code' => 'AED'],
        [
            'name' => 'UAE Dirham',
            'is_active' => true,
            'is_default' => true,
        ],
    );
});

it('resolves deterministic product and variant quantity breaks from the customer default price list', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'base_price' => 120,
        'min_price' => 60,
        'status' => ProductStatus::Active,
    ]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $priceList = PriceList::query()->create([
        'name' => 'Clinic AED',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);

    $profile->update([
        'default_currency_code' => 'AED',
        'default_price_list_id' => $priceList->getKey(),
    ]);

    $productItem = PriceListItem::query()->create([
        'price_list_id' => $priceList->getKey(),
        'product_id' => $variant->product_id,
        'product_variant_id' => null,
        'minimum_quantity' => null,
        'price' => '105.00',
        'is_active' => true,
    ]);

    $variantBreak = PriceListItem::query()->create([
        'price_list_id' => $priceList->getKey(),
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->getKey(),
        'minimum_quantity' => '10.000000',
        'price' => '90.00',
        'is_active' => true,
    ]);

    $resolver = app(PriceResolver::class);
    $small = $resolver->resolve($variant, $profile->user, 5);
    $bulk = $resolver->resolve($variant, $profile->user, 10);

    expect($small->amount)->toBe(105.0)
        ->and($small->source)->toBe(ResolvedPriceSource::CustomerPriceList)
        ->and($small->priceListId)->toBe($priceList->getKey())
        ->and($small->priceListItemId)->toBe($productItem->getKey())
        ->and($small->currencyCode)->toBe('AED')
        ->and($bulk->amount)->toBe(90.0)
        ->and($bulk->priceListItemId)->toBe($variantBreak->getKey());
});

it('resolves customer-group price lists through the explicit assignment pivot', function (): void {
    $group = CustomerGroup::query()->create([
        'name' => 'Dental Labs',
        'code' => 'LAB',
        'is_active' => true,
    ]);
    $priceList = PriceList::query()->create([
        'name' => 'Labs AED',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);
    $group->priceLists()->attach($priceList->getKey());

    $profile = CustomerProfile::factory()->create([
        'customer_group_id' => $group->getKey(),
        'default_currency_code' => 'AED',
    ]);
    $variant = ProductVariant::factory()->create([
        'base_price' => 100,
        'status' => ProductStatus::Active,
    ]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $item = PriceListItem::query()->create([
        'price_list_id' => $priceList->getKey(),
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->getKey(),
        'minimum_quantity' => '1.000000',
        'price' => '82.00',
        'is_active' => true,
    ]);

    $resolved = app(PriceResolver::class)->resolve($variant, $profile->user, 1);

    expect($resolved->source)->toBe(ResolvedPriceSource::CustomerPriceList)
        ->and($resolved->amount)->toBe(82.0)
        ->and($resolved->priceListItemId)->toBe($item->getKey());
});

it('keeps default price list priority ahead of additional directly assigned lists', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'base_price' => 100,
        'status' => ProductStatus::Active,
    ]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $default = PriceList::query()->create([
        'name' => 'Default AED',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);
    $secondary = PriceList::query()->create([
        'name' => 'Secondary AED',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);

    $profile->update([
        'default_currency_code' => 'AED',
        'default_price_list_id' => $default->getKey(),
    ]);
    $profile->priceLists()->attach($secondary->getKey());

    foreach ([[$default, '92.00'], [$secondary, '70.00']] as [$list, $price]) {
        PriceListItem::query()->create([
            'price_list_id' => $list->getKey(),
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
            'minimum_quantity' => null,
            'price' => $price,
            'is_active' => true,
        ]);
    }

    $resolved = app(PriceResolver::class)->resolve($variant, $profile->user, 1);

    expect($resolved->amount)->toBe(92.0)
        ->and($resolved->priceListId)->toBe($default->getKey());
});

it('allows downstream invoice lines to retain price-list provenance fields', function (): void {
    $line = new InvoiceLine([
        'resolved_price_list_id' => 11,
        'resolved_price_list_item_id' => 22,
    ]);

    expect($line->resolved_price_list_id)->toBe(11)
        ->and($line->resolved_price_list_item_id)->toBe(22);
});
