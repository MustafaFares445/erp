<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseSettings\PurchaseSettingResource;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\SupplierProductReferences\SupplierProductReferenceResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Every item in the `purchasing` group, all of them now backed by a real
 * resource. The group had two string stubs before this feature — placeholders
 * that named a class which did not exist — and they are what this asserts is
 * gone.
 */
const PURCHASING_ITEMS = [
    'admin.resources.suppliers' => SupplierResource::class,
    'admin.resources.purchase_orders' => PurchaseOrderResource::class,
    'admin.resources.supplier_confirmations' => SupplierConfirmationResource::class,
    'admin.resources.supplier_product_references' => SupplierProductReferenceResource::class,
    'admin.resources.purchase_settings' => PurchaseSettingResource::class,
];

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();

    $this->manager = User::factory()->admin()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);

    /** @var array{key: string, items: list<array{label: string, link: string}>} $group */
    $group = collect(AdminModuleRegistry::groups())->firstWhere('key', 'purchasing');

    $this->items = collect($group['items']);
});

it('lists every purchasing item in order, each pointing at a class that exists', function (): void {
    expect($this->items->pluck('label')->all())->toBe(array_keys(PURCHASING_ITEMS));

    foreach (PURCHASING_ITEMS as $label => $resource) {
        $link = $this->items->firstWhere('label', $label)['link'];

        expect($link)->toBe($resource)
            // The two string stubs the registry carried before this feature named
            // classes that did not exist, so the sidebar advertised surfaces the
            // panel could not render.
            ->and(class_exists($link))->toBeTrue($label);
    }
});

it('leaves no placeholder in the purchasing group', function (): void {
    expect($this->items)->toHaveCount(count(PURCHASING_ITEMS));
});

it('registers the purchasing report under the shared reports group, not inside purchasing (R-011)', function (): void {
    /** @var array{items: list<array{label: string, link: string}>} $reports */
    $reports = collect(AdminModuleRegistry::groups())->firstWhere('key', 'reports');

    expect(collect($reports['items'])->pluck('link')->all())->toContain(PurchasingReportResource::class)
        ->and($this->items->pluck('link')->all())->not->toContain(PurchasingReportResource::class);
});

it('gives every purchasing resource an English label', function (): void {
    foreach (PURCHASING_ITEMS as $label => $resource) {
        $translated = __($label);

        expect($translated)->not->toBe($label, $label)
            ->and($resource::getNavigationLabel())->toBe($translated);
    }
});

it('opens every purchasing surface for a purchasing manager', function (): void {
    $this->actingAs($this->manager);

    foreach ([
        PurchaseOrderResource::class,
        SupplierConfirmationResource::class,
        SupplierProductReferenceResource::class,
        SupplierResource::class,
    ] as $resource) {
        expect($resource::canViewAny())->toBeTrue($resource);
    }

    // Except the threshold, which is System Admin only.
    expect(PurchaseSettingResource::canViewAny())->toBeFalse();
});

it('closes every purchasing surface to a user with no purchasing permission', function (): void {
    // A fixed role from another module: enough to lose the admin bypass, and
    // carrying no purchase.* grant of its own.
    Role::findOrCreate(DashboardRole::SupportAgent->value, 'web');

    $outsider = User::factory()->admin()->create();
    $outsider->assignRole(DashboardRole::SupportAgent->value);
    $this->actingAs($outsider);

    foreach ([
        PurchaseOrderResource::class,
        SupplierConfirmationResource::class,
        PurchaseSettingResource::class,
    ] as $resource) {
        expect($resource::canViewAny())->toBeFalse($resource);
    }
});
