<?php

declare(strict_types=1);

use App\Data\Inventory\PricingTierData;
use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\PricingTierVisibility;
use App\Models\AuditLog;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\PricingTierService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates updates and audits product-scoped pricing tiers without price history', function (): void {
    $actor = User::factory()->admin()->create();
    $service = app(PricingTierService::class);
    $tier = $service->save(null, new PricingTierData(
        name: 'Clinic agreement',
        tierType: PricingTierType::ProductScoped,
        discountType: PricingTierDiscountType::Percentage,
        discountValue: 10,
        visibility: PricingTierVisibility::Public,
        validFrom: '2026-08-02',
        validUntil: '2026-08-31',
    ), $actor);
    $updated = $service->save($tier, new PricingTierData(
        name: 'Clinic agreement',
        tierType: PricingTierType::ProductScoped,
        discountType: PricingTierDiscountType::Fixed,
        discountValue: 15,
        visibility: PricingTierVisibility::Public,
        validFrom: '2026-08-03',
        validUntil: '2026-08-31',
    ), $actor);

    expect($updated->discount_type)->toBe(PricingTierDiscountType::Fixed)
        ->and($updated->discount_value)->toBe('15.00')
        ->and($updated->valid_from?->toDateString())->toBe('2026-08-03')
        ->and(AuditLog::query()->pluck('action')->all())->toContain('pricing.tier.created', 'pricing.tier.updated')
        ->and(PriceHistory::query()->count())->toBe(0);
});

it('validates product-scoped activation prerequisites and restores it inactive', function (): void {
    $actor = User::factory()->admin()->create();
    $service = app(PricingTierService::class);
    $tier = PricingTier::factory()->productScoped()->inactive()->create();

    expect(fn (): PricingTier => $service->activate($tier, $actor))
        ->toThrow(DomainException::class, 'active product');

    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create(['base_price' => 120]);
    $service->syncProducts($tier, [$product->id], $actor);
    $activated = $service->activate($tier, $actor);
    $service->delete($activated, $actor);
    $restored = $service->restore($activated, $actor);

    expect($activated->is_active)->toBeTrue()
        ->and($restored->trashed())->toBeFalse()
        ->and($restored->is_active)->toBeFalse();
});

it('requires an active customer assignment before activating restricted tiers', function (): void {
    $actor = User::factory()->admin()->create();
    $service = app(PricingTierService::class);
    $tier = PricingTier::factory()->restricted()->inactive()->create();
    $product = Product::factory()->create();
    $service->syncProducts($tier, [$product->id], $actor);

    expect(fn (): PricingTier => $service->activate($tier, $actor))
        ->toThrow(DomainException::class, 'active customer assignment');

    $profile = CustomerProfile::factory()->create();
    $service->syncCustomers($tier, [$profile->user_id], $actor);

    expect($service->activate($tier, $actor)->is_active)->toBeTrue();
});

it('keeps one active customer-specific tier per active customer', function (): void {
    $actor = User::factory()->admin()->create();
    $profile = CustomerProfile::factory()->create();
    $service = app(PricingTierService::class);
    $data = fn (string $name, float $discount): PricingTierData => new PricingTierData(
        name: $name,
        tierType: PricingTierType::CustomerSpecific,
        discountType: PricingTierDiscountType::Percentage,
        discountValue: $discount,
        customerUserId: $profile->user_id,
        isActive: true,
    );

    $first = $service->save(null, $data('Specific A', 10), $actor);
    $second = $service->save(null, $data('Specific B', 20), $actor);

    expect($first->refresh()->is_active)->toBeFalse()
        ->and($second->refresh()->is_active)->toBeTrue();
});

it('returns an unchanged tier without writing a duplicate audit event', function (): void {
    $actor = User::factory()->admin()->create();
    $service = app(PricingTierService::class);
    $data = new PricingTierData(
        name: 'Unchanged tier',
        tierType: PricingTierType::General,
        discountType: PricingTierDiscountType::Percentage,
        discountValue: 5,
    );
    $tier = $service->save(null, $data, $actor);
    $auditCount = AuditLog::query()->count();

    $unchanged = $service->save($tier, $data, $actor);

    expect($unchanged->is($tier))->toBeTrue()
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('validates every pricing tier contract boundary', function (PricingTierData $data, string $message): void {
    $actor = User::factory()->admin()->create();

    expect(fn (): PricingTier => app(PricingTierService::class)->save(null, $data, $actor))
        ->toThrow(DomainException::class, $message);
})->with([
    'blank name' => [new PricingTierData('', PricingTierType::General, PricingTierDiscountType::Percentage, 5), 'name is required'],
    'percentage above maximum' => [new PricingTierData('Too high', PricingTierType::General, PricingTierDiscountType::Percentage, 101), 'between 0 and 100'],
    'non-positive fixed discount' => [new PricingTierData('No fixed value', PricingTierType::ProductScoped, PricingTierDiscountType::Fixed, 0, visibility: PricingTierVisibility::Public), 'greater than zero'],
    'fixed general discount' => [new PricingTierData('Invalid fixed type', PricingTierType::General, PricingTierDiscountType::Fixed, 5), 'Only product-scoped'],
    'missing specific customer' => [new PricingTierData('Missing customer', PricingTierType::CustomerSpecific, PricingTierDiscountType::Percentage, 5), 'requires a customer'],
    'missing product visibility' => [new PricingTierData('Missing visibility', PricingTierType::ProductScoped, PricingTierDiscountType::Percentage, 5), 'requires visibility'],
    'reversed validity' => [new PricingTierData('Invalid dates', PricingTierType::ProductScoped, PricingTierDiscountType::Percentage, 5, visibility: PricingTierVisibility::Public, validFrom: '2026-08-02', validUntil: '2026-08-01'), 'cannot precede'],
]);

it('rejects invalid activation relationship and authorization states', function (): void {
    $service = app(PricingTierService::class);
    $actor = User::factory()->admin()->create();
    $unauthorized = User::factory()->customer()->create();
    $general = PricingTier::factory()->inactive()->create();
    $productTier = PricingTier::factory()->productScoped()->inactive()->create();
    $inactiveProfile = CustomerProfile::factory()->create(['is_active' => false]);

    expect(fn (): PricingTier => $service->syncProducts($general, [], $actor))
        ->toThrow(DomainException::class, 'only for product-scoped')
        ->and(fn () => $service->assignGeneralTier($inactiveProfile->user, $general, $actor))
        ->toThrow(DomainException::class, 'active customer profiles')
        ->and(fn () => $service->assignGeneralTier(CustomerProfile::factory()->create()->user, $productTier, $actor))
        ->toThrow(DomainException::class, 'Only active general')
        ->and(fn (): PricingTier => $service->save(null, new PricingTierData('Unauthorized', PricingTierType::General, PricingTierDiscountType::Percentage, 5), $unauthorized))
        ->toThrow(DomainException::class, 'not authorized')
        ->and(fn (): PricingTier => $service->activate(new PricingTier, $actor))
        ->toThrow(DomainException::class, 'persisted pricing tier');
});

it('blocks fixed discounts that exhaust an active variant price', function (): void {
    $actor = User::factory()->admin()->create();
    $service = app(PricingTierService::class);
    $tier = PricingTier::factory()->fixed()->inactive()->create(['discount_value' => 10]);
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create(['base_price' => 10]);
    $service->syncProducts($tier, [$product->id], $actor);

    expect(fn (): PricingTier => $service->activate($tier, $actor))
        ->toThrow(DomainException::class, 'positive price');
});

it('prevents tier type changes from orphaning products or active assignments', function (): void {
    $actor = User::factory()->admin()->create();
    $service = app(PricingTierService::class);
    $productTier = PricingTier::factory()->productScoped()->inactive()->create();
    $productTier->products()->attach(Product::factory()->create());
    $assignmentTier = PricingTier::factory()->productScoped()->inactive()->create();
    CustomerPricingTier::factory()->create([
        'pricing_tier_id' => $assignmentTier->id,
        'customer_user_id' => CustomerProfile::factory()->create()->user_id,
    ]);
    $generalData = fn (string $name): PricingTierData => new PricingTierData($name, PricingTierType::General, PricingTierDiscountType::Percentage, 5);

    expect(fn (): PricingTier => $service->save($productTier, $generalData($productTier->name), $actor))
        ->toThrow(DomainException::class, 'Remove product links')
        ->and(fn (): PricingTier => $service->save($assignmentTier, $generalData($assignmentTier->name), $actor))
        ->toThrow(DomainException::class, 'Remove active customer assignments');
});

it('handles empty and unchanged relationship synchronization without audit noise', function (): void {
    $actor = User::factory()->admin()->create();
    $tier = PricingTier::factory()->productScoped()->inactive()->create();
    $service = app(PricingTierService::class);

    $service->syncProducts($tier, [], $actor);
    $service->syncCustomers($tier, [], $actor);

    $auditCount = AuditLog::query()->count();
    $service->syncProducts($tier, [], $actor);
    $service->syncCustomers($tier, [], $actor);

    expect(AuditLog::query()->count())->toBe($auditCount);
});

it('deactivates tiers and treats restoration of an active record as a no-op', function (): void {
    $actor = User::factory()->admin()->create();
    $tier = PricingTier::factory()->create();
    $service = app(PricingTierService::class);

    $deactivated = $service->deactivate($tier, $actor);
    $restored = $service->restore($deactivated, $actor);

    expect($deactivated->is_active)->toBeFalse()
        ->and($restored->is($deactivated))->toBeTrue();
});

it('reports unique name conflicts detected before and during persistence', function (): void {
    $actor = User::factory()->admin()->create();
    $service = app(PricingTierService::class);
    $data = new PricingTierData('Duplicate tier', PricingTierType::General, PricingTierDiscountType::Percentage, 5);
    PricingTier::factory()->create(['name' => $data->name]);

    expect(fn (): PricingTier => $service->save(null, $data, $actor))
        ->toThrow(DomainException::class, 'already exists');

    $insertDuplicate = true;
    PricingTier::creating(function (PricingTier $tier) use (&$insertDuplicate): void {
        if (! $insertDuplicate || $tier->name !== 'Concurrent duplicate') {
            return;
        }

        $insertDuplicate = false;
        DB::table('pricing_tiers')->insert([
            'name' => $tier->name,
            'tier_type' => PricingTierType::General->value,
            'discount_type' => PricingTierDiscountType::Percentage->value,
            'discount_value' => 5,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn (): PricingTier => $service->save(null, new PricingTierData('Concurrent duplicate', PricingTierType::General, PricingTierDiscountType::Percentage, 5), $actor))
        ->toThrow(DomainException::class, 'already exists');
});

it('rethrows non-unique query failures from persistence', function (): void {
    $actor = User::factory()->admin()->create();
    $throwFailure = true;
    PricingTier::creating(function (PricingTier $tier) use (&$throwFailure): void {
        if (! $throwFailure || $tier->name !== 'Database failure') {
            return;
        }

        $throwFailure = false;

        throw new QueryException('testing', 'insert into pricing_tiers', [], new RuntimeException('Database unavailable.', 999));
    });

    expect(fn (): PricingTier => app(PricingTierService::class)->save(
        null,
        new PricingTierData('Database failure', PricingTierType::General, PricingTierDiscountType::Percentage, 5),
        $actor,
    ))->toThrow(QueryException::class, 'Database unavailable');
});
