<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\CrmPermission;
use App\Enums\EmployeePermission;
use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Enums\SalesPermission;
use App\Enums\SupportPermission;
use App\Enums\UserType;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\Brand;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\Interaction;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderLine;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('seeds an authorized system administrator and the permission catalogue', function (): void {
    $this->seed();

    $admin = User::query()->where('email', 'admin@ierp.com')->sole();
    $permissions = [...InventoryPermission::values(), ...CrmPermission::values(), ...EmployeePermission::values(), ...SupportPermission::values(), ...AccountingPermission::values(), ...PurchasePermission::values(), ...SalesPermission::values()];

    expect($admin->user_type)->toBe(UserType::Admin)
        ->and($admin->getAllPermissions()->pluck('name')->all())
        ->toEqualCanonicalizing($permissions)
        ->and(Permission::query()->where('guard_name', 'web')->pluck('name')->all())
        ->toEqualCanonicalizing($permissions);

    expect(Brand::query()->whereIn('code', ['FORMLABS', 'DENTSPLY-SIRONA', 'IVOCLAR'])->count())->toBe(3)
        ->and(ProductCategory::query()->count())->toBe(4)
        ->and(Unit::query()->whereIn('symbol', ['EA', 'L', 'SACK', 'KG'])->count())->toBe(4)
        ->and(Product::query()->count())->toBe(8)
        ->and(ProductVariant::query()->count())->toBe(16)
        ->and(ProductVariant::query()->where('sku', 'like', 'DEMO-%')->exists())->toBeFalse();

    expect(OrderLine::query()
        ->where(function ($query): void {
            $query->whereNull('transaction_quantity')
                ->orWhereNull('transaction_unit_id')
                ->orWhereNull('conversion_factor_snapshot')
                ->orWhereNull('base_quantity');
        })
        ->count())->toBe(0)
        ->and(PurchaseOrderLine::query()
            ->where(function ($query): void {
                $query->whereNull('transaction_quantity')
                    ->orWhereNull('transaction_unit_id')
                    ->orWhereNull('conversion_factor_snapshot')
                    ->orWhereNull('base_quantity')
                    ->orWhereNull('received_base_quantity');
            })
            ->count())->toBe(0);

    foreach ([
        WarehouseResource::getUrl(),
        StockLevelResource::getUrl(),
        StockMovementResource::getUrl(),
        AdjustmentResource::getUrl(),
        InventoryOperationResource::getUrl(),
    ] as $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
});

/**
 * Regression guard for the CR-05 timeline demo-data defects: a duplicate
 * "Bright Orthodontics" customer profile across two seeders, and a
 * showcase customer whose every document lands on the seeding day, leaving
 * the timeline's date-group headers with nothing to group.
 */
it('merges the duplicate customer and gives the showcase customer a full, chronologically-spread timeline', function (): void {
    $this->seed();

    expect(CustomerProfile::query()->where('company_name', 'Bright Orthodontics')->count())->toBe(1);

    $customer = CustomerProfile::query()->where('customer_code', 'DEMO-SMILE')->sole();

    $counts = [
        'quotation' => Quotation::query()->where('customer_id', $customer->id)->count(),
        'order' => Order::query()->where('customer_id', $customer->id)->count(),
        'invoice' => Invoice::query()->where('customer_id', $customer->id)->count(),
        'payment' => Payment::query()->where('customer_id', $customer->id)->count(),
        'ticket' => Ticket::query()->where('customer_id', $customer->id)->count(),
        'interaction' => Interaction::query()->where('subject_type', CustomerProfile::class)->where('subject_id', $customer->id)->count(),
        'visit' => CustomerVisit::query()->where('customer_id', $customer->id)->count(),
        'maintenance_record' => MaintenanceRecord::query()->where('customer_id', $customer->id)->count(),
    ];

    foreach ($counts as $type => $count) {
        expect($count)->toBeGreaterThanOrEqual(1, "Expected at least one seeded {$type} for the showcase customer.");
    }

    $earliest = Interaction::query()
        ->where('subject_type', CustomerProfile::class)
        ->where('subject_id', $customer->id)
        ->min('occurred_at');
    $latest = Interaction::query()
        ->where('subject_type', CustomerProfile::class)
        ->where('subject_id', $customer->id)
        ->max('occurred_at');

    expect(Carbon::parse($earliest)->diffInDays(Carbon::parse($latest)))->toBeGreaterThanOrEqual(180);
});
