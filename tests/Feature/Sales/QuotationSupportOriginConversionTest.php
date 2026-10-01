<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\QuotationDecision;
use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Models\CustomerProfile;
use App\Models\MaintenanceRecord;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use App\Services\Sales\QuotationConversionService;
use App\Services\Sales\QuotationService;
use Carbon\CarbonImmutable;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
    (new CrmPermissionSeeder)->run();
});

/**
 * @param  list<array<string, mixed>>  $lines
 */
function acceptedQuotationWith(array $lines): Quotation
{
    $service = app(QuotationService::class);
    $quotation = $service->create(
        ['customer_id' => CustomerProfile::factory()->create()->getKey(), 'issue_date' => now()->toDateString()],
        $lines,
    );
    $service->send($quotation);
    $service->recordDecision($quotation, QuotationDecision::Accepted, CarbonImmutable::today(), null, User::factory()->create());

    return $quotation->refresh();
}

function salesManagerUser(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole(DashboardRole::SalesManager->value);

    return $manager;
}

it('refuses to convert a support-style quotation with a service line instead of crashing on the null variant', function (): void {
    $quotation = acceptedQuotationWith([
        ['product_variant_id' => null, 'description' => 'Diagnostic labour', 'quantity' => 2, 'unit_price' => 50],
    ]);

    expect($quotation->isSupportOrigin())->toBeTrue()
        ->and($quotation->isConvertibleToOrder())->toBeFalse()
        ->and(fn () => app(QuotationConversionService::class)->convert($quotation))
        ->toThrow(InvalidQuotationTransition::class, $quotation->quotation_number);

    expect(Order::query()->count())->toBe(0)
        ->and($quotation->refresh()->status)->toBe(QuotationStatus::Accepted)
        ->and($quotation->converted_order_id)->toBeNull();
});

it('refuses to convert a quotation that a maintenance record already bills', function (): void {
    $variant = ProductVariant::factory()->create(['base_price' => 100]);
    $quotation = acceptedQuotationWith([
        ['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100],
    ]);
    MaintenanceRecord::factory()->create([
        'customer_id' => $quotation->customer_id,
        'quotation_id' => $quotation->getKey(),
    ]);

    expect($quotation->isSupportOrigin())->toBeTrue()
        ->and($quotation->isConvertibleToOrder())->toBeFalse()
        ->and(fn () => app(QuotationConversionService::class)->convert($quotation))
        ->toThrow(InvalidQuotationTransition::class, $quotation->quotation_number);

    expect(Order::query()->count())->toBe(0);
});

it('still converts an ordinary accepted product quotation', function (): void {
    $variant = ProductVariant::factory()->create(['base_price' => 100]);
    $quotation = acceptedQuotationWith([
        ['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100],
    ]);

    expect($quotation->isConvertibleToOrder())->toBeTrue();

    $order = app(QuotationConversionService::class)->convert($quotation);

    expect($order->quotation_id)->toBe($quotation->getKey())
        ->and($quotation->isConvertibleToOrder())->toBeFalse();
});

it('hides the Convert action for support-origin quotations and shows it for ordinary ones', function (): void {
    $manager = salesManagerUser();
    $variant = ProductVariant::factory()->create(['base_price' => 100]);

    $support = acceptedQuotationWith([
        ['product_variant_id' => null, 'description' => 'On-site repair', 'quantity' => 1, 'unit_price' => 80],
    ]);
    $ordinary = acceptedQuotationWith([
        ['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100],
    ]);

    Livewire::actingAs($manager)
        ->test(ViewQuotation::class, ['record' => $support->getKey()])
        ->assertActionHidden('convert');

    Livewire::actingAs($manager)
        ->test(ViewQuotation::class, ['record' => $ordinary->getKey()])
        ->assertActionVisible('convert');
});
