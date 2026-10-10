<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Support\OperationalRouteData;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates a standalone invoice with line items using the transactional sales service', function (): void {
    (new SalesPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole(DashboardRole::SystemAdmin->value);
    $customer = CustomerProfile::factory()->create();

    Livewire::actingAs($actor)
        ->test(CreateInvoice::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'invoice_date' => today()->toDateString(),
            'lines' => [
                ['description' => 'Implementation service', 'quantity' => 2, 'unit_price' => 50, 'tax_amount' => 0],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->sole();
    expect($invoice->customer_id)->toBe($customer->getKey())
        ->and($invoice->lines()->count())->toBe(1)
        ->and((float) $invoice->total_amount)->toBe(100.0)
        ->and($invoice->issued_at)->toBeNull();
});

it('renders a usable shipment map data source without inventing missing route data', function (): void {
    $warehouse = Warehouse::factory()->create(['latitude' => '36.2021000', 'longitude' => '37.1531000']);
    $address = CustomerDeliveryAddress::factory()->create([
        'latitude' => '36.2101000', 'longitude' => '37.1601000',
    ]);
    $order = Order::factory()->create([
        'customer_delivery_address_id' => $address->getKey(),
        'destination_address_snapshot' => null,
    ]);
    $shipment = Shipment::factory()->create([
        'order_id' => $order->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'inventory_operation_id' => null,
    ]);

    $data = OperationalRouteData::shipment($shipment);
    expect($data['source']['latitude'])->toBe(36.2021)
        ->and($data['destination']['longitude'])->toBe(37.1601)
        ->and($data)->not->toHaveKey('estimated_minutes');
});
