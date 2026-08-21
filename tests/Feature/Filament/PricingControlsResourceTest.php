<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\PriceChangeRequestStatus;
use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\ProductStatus;
use App\Filament\Resources\PriceFloorOverrides\Pages\ListPriceFloorOverrides;
use App\Filament\Resources\PriceFloorOverrides\PriceFloorOverrideResource;
use App\Filament\Resources\PriceHistories\Pages\ListPriceHistories;
use App\Filament\Resources\PriceHistories\PriceHistoryResource;
use App\Filament\Resources\PriceHistories\Tables\PriceHistoriesTable;
use App\Filament\Resources\PricingTiers\Pages\ManagePricingTiers;
use App\Filament\Resources\PricingTiers\PricingTierResource;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants;
use App\Filament\Resources\ProductVariants\Pages\ViewProductVariant;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\AuditLog;
use App\Models\CustomerPricingTier;
use App\Models\CustomerProfile;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function pricingPanelManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo([
        InventoryPermission::CatalogView->value,
        InventoryPermission::CatalogManage->value,
        InventoryPermission::PricingView->value,
        InventoryPermission::PricingManage->value,
        InventoryPermission::PricingReview->value,
        InventoryPermission::PriceFloorApprove->value,
    ]);

    return $manager;
}

it('creates a variant with a derived base price through the pricing service', function (): void {
    $manager = pricingPanelManager();
    $product = Product::factory()->expiryMaterial()->create();

    Livewire::actingAs($manager)
        ->test(ManageProductVariants::class)
        ->callAction(TestAction::make('create'), [
            'product_id' => $product->id,
            'sku' => 'SKU-PRICED',
            'name' => 'Priced variant',
            'status' => ProductStatus::Active->value,
            'cost_price' => 80,
            'markup_percent' => 25,
            'base_price' => 999,
            'min_price' => 90,
        ])
        ->assertHasNoActionErrors();

    $variant = ProductVariant::query()->where('sku', 'SKU-PRICED')->sole();

    expect($variant->cost_price)->toBe('80.00')
        ->and($variant->markup_percent)->toBe('25.00')
        ->and($variant->base_price)->toBe('100.00')
        ->and($variant->min_price)->toBe('90.00')
        ->and(PriceHistory::query()->where('product_variant_id', $variant->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('description', 'catalog.variant.price_updated')->count())->toBe(1);
});

it('updates catalog and pricing fields without creating history for a no-op save', function (): void {
    $manager = pricingPanelManager();
    $variant = ProductVariant::factory()->expiryMaterial()->create([
        'cost_price' => 50,
        'markup_percent' => 20,
        'base_price' => 60,
        'min_price' => 45,
    ]);

    Livewire::actingAs($manager)
        ->test(ManageProductVariants::class)
        ->callAction(TestAction::make('edit')->table($variant), [
            'name' => 'Updated variant',
            'cost_price' => 75,
            'markup_percent' => 20,
            'min_price' => 70,
        ])
        ->assertHasNoActionErrors();

    expect($variant->refresh()->name)->toBe('Updated variant')
        ->and($variant->base_price)->toBe('90.00')
        ->and(PriceHistory::query()->count())->toBe(1);

    Livewire::actingAs($manager)
        ->test(ManageProductVariants::class)
        ->callAction(TestAction::make('edit')->table($variant), [
            'name' => 'Updated variant',
            'cost_price' => 75,
            'markup_percent' => 20,
            'min_price' => 70,
        ])
        ->assertHasNoActionErrors();

    expect(PriceHistory::query()->count())->toBe(1);
});

it('creates and edits a variant without optional pricing data before redirecting to its product tab', function (): void {
    $manager = pricingPanelManager();
    $product = Product::factory()->expiryMaterial()->create();

    Livewire::actingAs($manager)
        ->test(ManageProductVariants::class)
        ->callAction(TestAction::make('create'), [
            'product_id' => $product->getKey(),
            'sku' => 'SKU-UNPRICED',
            'name' => 'Unpriced variant',
            'status' => ProductStatus::Active->value,
        ])
        ->assertHasNoActionErrors();

    $variant = ProductVariant::query()->where('sku', 'SKU-UNPRICED')->sole();

    Livewire::actingAs($manager)
        ->test(ManageProductVariants::class)
        ->callAction(TestAction::make('edit')->table($variant), [
            'sku' => 'SKU-UNPRICED',
            'name' => 'Unpriced variant updated',
        ])
        ->assertHasNoActionErrors();

    Livewire::actingAs($manager)
        ->test(ViewProductVariant::class, ['record' => $variant->getRouteKey()])
        ->assertRedirect(ProductVariantResource::parentProductVariantsUrl($variant));

    /** @var ProductVariant $directVariant */
    $directVariant = ProductVariantResource::createAction()->process(null, [
        'data' => [
            'product_id' => $product->getKey(),
            'sku' => 'SKU-DIRECT-UNPRICED',
            'name' => 'Direct unpriced variant',
            'status' => ProductStatus::Active->value,
        ],
    ]);
    ProductVariantResource::editAction()->process(null, [
        'record' => $directVariant,
        'data' => [
            'sku' => 'SKU-DIRECT-UNPRICED',
            'name' => 'Direct unpriced variant updated',
        ],
    ]);

    expect($variant->fresh()->name)->toBe('Unpriced variant updated')
        ->and($directVariant->fresh()->name)->toBe('Direct unpriced variant updated')
        ->and(PriceHistory::query()->count())->toBe(1)
        ->and(ProductVariantResource::getRecordRouteBindingEloquentQuery()->find($variant->getKey()))->toBeInstanceOf(ProductVariant::class);
});

it('routes pricing tier creation and customer assignment through the pricing service', function (): void {
    $manager = pricingPanelManager();
    $customerProfile = CustomerProfile::factory()->create();

    Livewire::actingAs($manager)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('create'), [
            'name' => 'Wholesale',
            'tier_type' => PricingTierType::General->value,
            'discount_type' => PricingTierDiscountType::Percentage->value,
            'discount_value' => 15,
            'customer_user_id' => null,
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $tier = PricingTier::query()->where('name', 'Wholesale')->sole();

    Livewire::actingAs($manager)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('assignGeneralTier'), [
            'customer_user_id' => $customerProfile->user_id,
            'pricing_tier_id' => $tier->id,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $assignment = CustomerPricingTier::query()->sole();

    expect($assignment->customer_user_id)->toBe($customerProfile->user_id)
        ->and($assignment->pricing_tier_id)->toBe($tier->id)
        ->and($assignment->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('description', 'pricing.tier.created')->count())->toBe(1)
        ->and(AuditLog::query()->where('description', 'pricing.tier.general.assigned')->count())->toBe(1);
});

it('removes the standalone customer pricing tier page', function (): void {
    $manager = pricingPanelManager();

    $this->actingAs($manager)->get('/admin/customer-pricing-tiers')->assertNotFound();
});

it('explains each pricing screen in its subheading', function (): void {
    $manager = pricingPanelManager();

    Livewire::actingAs($manager)
        ->test(ManagePricingTiers::class)
        ->assertSee(__('admin.inventory.pricing.tier_list_notice'));

    Livewire::actingAs($manager)
        ->test(ListPriceHistories::class)
        ->assertSee(__('admin.inventory.pricing.history_list_notice'));

    Livewire::actingAs($manager)
        ->test(ListPriceFloorOverrides::class)
        ->assertSee(__('admin.inventory.pricing.floor_override_list_notice'));
});

it('edits deletes and restores pricing tiers through the pricing service', function (): void {
    $manager = pricingPanelManager();
    $tier = PricingTier::factory()->create(['name' => 'Original tier']);

    Livewire::actingAs($manager)
        ->test(ManagePricingTiers::class)
        ->callAction(TestAction::make('edit')->table($tier), [
            'name' => 'Updated tier',
            'tier_type' => PricingTierType::General->value,
            'discount_type' => PricingTierDiscountType::Percentage->value,
            'discount_value' => 12,
            'customer_user_id' => null,
            'is_active' => true,
        ])
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('delete')->table($tier))
        ->assertHasNoActionErrors();

    Livewire::actingAs($manager)
        ->test(ManagePricingTiers::class)
        ->filterTable('trashed', 'with')
        ->callAction(TestAction::make('restore')->table($tier->fresh()))
        ->assertHasNoActionErrors();

    $tier->refresh();

    expect($tier->name)->toBe('Updated tier')
        ->and($tier->trashed())->toBeFalse()
        ->and(PricingTierResource::getRecordRouteBindingEloquentQuery()->find($tier->getKey()))->toBeInstanceOf(PricingTier::class);
});

it('approves a below-floor price from the variant administration screen', function (): void {
    $manager = pricingPanelManager();
    $customerProfile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 90]);

    Livewire::actingAs($manager)
        ->test(ManageProductVariants::class)
        ->callAction(TestAction::make('approveFloorOverride'), [
            'product_variant_id' => $variant->id,
            'customer_user_id' => $customerProfile->user_id,
            'attempted_price' => 85,
            'reason' => 'Approved account exception',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $override = PriceFloorOverride::query()->sole();

    expect($override->product_variant_id)->toBe($variant->id)
        ->and($override->customer_user_id)->toBe($customerProfile->user_id)
        ->and($override->reason)->toBe('Approved account exception');
});

it('exposes pricing histories as read-only resources', function (): void {
    $manager = pricingPanelManager();
    $variant = ProductVariant::factory()->create();
    $history = PriceHistory::factory()->for($variant, 'productVariant')->create(['changed_by' => $manager->id]);
    $override = PriceFloorOverride::query()->forceCreate([
        'product_variant_id' => $variant->id,
        'attempted_price' => 80,
        'min_price' => 90,
        'approved_by' => $manager->id,
        'approved_at' => now(),
        'reason' => 'Read-only example',
    ]);

    $this->actingAs($manager);

    $this->get(PriceHistoryResource::getUrl('index'))->assertOk();
    $this->get(PriceHistoryResource::getUrl('view', ['record' => $history]))->assertOk();
    $this->get(PriceFloorOverrideResource::getUrl('index'))->assertOk();
    $this->get(PriceFloorOverrideResource::getUrl('view', ['record' => $override]))->assertOk();

    $historyList = Livewire::actingAs($manager)->test(ListPriceHistories::class);
    $overrideList = Livewire::actingAs($manager)->test(ListPriceFloorOverrides::class);

    expect($historyList->instance()->getTable()->getBulkActions())->toBeEmpty()
        ->and($overrideList->instance()->getTable()->getBulkActions())->toBeEmpty()
        ->and(PriceHistoryResource::canCreate())->toBeFalse()
        ->and(PriceHistoryResource::canDeleteAny())->toBeFalse()
        ->and(PriceFloorOverrideResource::canCreate())->toBeFalse()
        ->and(PriceFloorOverrideResource::canDeleteAny())->toBeFalse();
});

it('keeps the bare-admin CRM fallback while protecting inventory price history', function (): void {
    $administrator = User::factory()->admin()->create();

    $this->actingAs($administrator);

    $this->get(PricingTierResource::getUrl('index'))->assertOk();
    $this->get(PriceHistoryResource::getUrl('index'))->assertForbidden();
    $this->get(PriceFloorOverrideResource::getUrl('index'))->assertOk();
});

it('approves, rejects, and updates a pending price change request from the price history table', function (): void {
    $manager = pricingPanelManager();

    $toApprove = PriceHistory::factory()->pending()->create();
    $toReject = PriceHistory::factory()->pending()->create();
    $toUpdate = PriceHistory::factory()->pending()->create(['cost_price' => 10, 'markup_percent' => 20, 'min_price' => 5]);
    $alreadyDecided = PriceHistory::factory()->create();

    Livewire::actingAs($manager)
        ->test(ListPriceHistories::class)
        ->assertTableActionVisible('approve', $toApprove)
        ->assertTableActionHidden('approve', $alreadyDecided)
        ->callTableAction('approve', $toApprove)
        ->assertNotified()
        ->callTableAction('reject', $toReject)
        ->assertNotified()
        ->callTableAction('update', $toUpdate, [
            'cost_price' => 15,
            'markup_percent' => 30,
            'min_price' => 8,
        ])
        ->assertNotified();

    expect($toApprove->fresh()->status)->toBe(PriceChangeRequestStatus::Approved)
        ->and($toReject->fresh()->status)->toBe(PriceChangeRequestStatus::Rejected)
        ->and($toUpdate->fresh()->status)->toBe(PriceChangeRequestStatus::Approved)
        ->and((float) $toUpdate->fresh()->cost_price)->toBe(15.0);
});

it('fails fast for invalid pricing action context and helper input', function (): void {
    auth()->logout();

    $variantActor = new ReflectionMethod(ProductVariantResource::class, 'actor');
    $tierActor = new ReflectionMethod(PricingTierResource::class, 'actor');
    $overrideActor = new ReflectionMethod(ManageProductVariants::class, 'actor');
    $historyActor = new ReflectionMethod(PriceHistoriesTable::class, 'actor');
    $sku = new ReflectionMethod(ProductVariantResource::class, 'sku');
    $recordId = new ReflectionMethod(ProductVariantResource::class, 'recordId');
    $catalogData = new ReflectionMethod(ProductVariantResource::class, 'catalogData');

    expect(fn (): mixed => $variantActor->invoke(null))->toThrow(LogicException::class)
        ->and(fn (): mixed => $tierActor->invoke(null))->toThrow(LogicException::class)
        ->and(fn (): mixed => $overrideActor->invoke(null))->toThrow(LogicException::class)
        ->and(fn (): mixed => $historyActor->invoke(null))->toThrow(LogicException::class)
        ->and(fn (): mixed => $sku->invoke(null, []))->toThrow(LogicException::class)
        ->and(fn (): mixed => $recordId->invoke(null, new ProductVariant))->toThrow(LogicException::class)
        ->and($catalogData->invoke(null, [
            0 => 'discarded',
            'name' => 'Kept',
            'cost_price' => 10,
        ]))->toBe(['name' => 'Kept'])
        ->and(ProductVariantResource::getGlobalSearchResultDetails(new Product))->toBe([]);
});
