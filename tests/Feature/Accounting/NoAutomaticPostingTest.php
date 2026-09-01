<?php

declare(strict_types=1);

use App\Data\Orders\OrderFulfillmentData;
use App\Enums\DashboardRole;
use App\Enums\DeliveryType;
use App\Enums\InventoryPermission;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ProductVariant;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Support\TicketPaymentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportDemoSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * SC-008 / FR-034 — no commercial document posts to the ledger.
 *
 * This feature builds the posting service and its manual caller only. ADR 0007
 * authorises no automatic posting, and connecting a document is that document's
 * own feature and its own ADR. These tests are what keeps that true: the moment
 * an observer, listener, or service starts posting on a document's behalf, one of
 * them fails.
 */
beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
});

function actorWithInventoryPermissions(): User
{
    $role = Role::findOrCreate('no-posting-test-operator', 'web');

    foreach (InventoryPermission::values() as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return tap(User::factory()->admin()->create(), fn (User $user): User => $user->assignRole($role));
}

it('writes no journal entry when an order is created and its deliveries are reserved', function (): void {
    $actor = actorWithInventoryPermissions();
    $customer = CustomerProfile::factory()->create();
    $address = CustomerDeliveryAddress::factory()->for($customer, 'customer')->create(['is_default' => true]);
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '10.000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => null,
    ]);

    $order = app(OrderFulfillmentService::class)->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 2]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 2, 'inventory_lot_id' => $lot->getKey()]],
            'delivery_type' => DeliveryType::Inner->value,
        ]],
        actor: $actor,
        notes: null,
        deliveryAddress: $address,
        scheduledAt: now()->addDay(),
        responsible: $actor,
    ));

    expect($order->lines)->toHaveCount(1)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(JournalEntryLine::query()->count())->toBe(0);
});

it('writes no journal entry when a delivery is dispatched and completed', function (): void {
    $actor = actorWithInventoryPermissions();
    $customer = CustomerProfile::factory()->create();
    $address = CustomerDeliveryAddress::factory()->for($customer, 'customer')->create(['is_default' => true]);
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '10.000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => null,
    ]);

    $order = app(OrderFulfillmentService::class)->create(new OrderFulfillmentData(
        customer: $customer,
        products: [['product_variant_id' => $variant->getKey(), 'quantity' => 2]],
        shipments: [[
            'warehouse_id' => $warehouse->getKey(),
            'assignments' => [['product_variant_id' => $variant->getKey(), 'quantity' => 2, 'inventory_lot_id' => $lot->getKey()]],
            'delivery_type' => DeliveryType::Inner->value,
        ]],
        actor: $actor,
        notes: null,
        deliveryAddress: $address,
        scheduledAt: now()->addDay(),
        responsible: $actor,
    ));

    $delivery = InventoryOperation::query()->whereMorphedTo('sourceDocument', $order)->sole();

    // A delivery goes straight from Ready to Done; only an internal transfer uses
    // the InTransit stage.
    app(InventoryOperationService::class)->complete($delivery, $actor);

    // Stock genuinely moved, so the path really was exercised — and still no
    // ledger row exists.
    expect(InventoryStock::query()->whereBelongsTo($warehouse)->whereBelongsTo($variant)->value('on_hand_quantity'))->toBe('8.000000')
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('writes no journal entry when a chargeable ticket payment is settled', function (): void {
    (new SupportPermissionSeeder)->run();
    // Settling moves the ticket to Live, which snapshots an SLA target.
    (new SlaPolicySeeder)->run();

    $actor = User::factory()->admin()->create();
    // System Admin, not Support Manager: settling a chargeable ticket's payment is
    // the one support ability the manager role deliberately lacks.
    $actor->assignRole(DashboardRole::SystemAdmin->value);

    $ticket = Ticket::factory()->chargeable()->create();

    $service = app(TicketPaymentService::class);
    $link = $service->createForTicket($ticket, 450.00, 'AED');
    $service->settle($link, 'VISA-TEST-0001', $actor);

    expect($link->refresh()->settled_at)->not->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('writes no journal entry when an inventory adjustment moves stock', function (): void {
    $actor = actorWithInventoryPermissions();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '5.000',
    ]);

    app(InventoryBalanceService::class)->adjustTo($variant, (int) $warehouse->getKey(), 7.0);

    expect(InventoryStock::query()->whereBelongsTo($warehouse)->whereBelongsTo($variant)->value('on_hand_quantity'))->toBe('7.000000')
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('leaves the ledger empty after the whole demo data set, accounting aside', function (): void {
    // Every other module's demo seeder drives its own real services end to end —
    // orders, deliveries, receipts, ticket intake, chargeable-payment settlement,
    // maintenance and spare-part consumption. None of them may leave a ledger row.
    $this->seed(InventoryDemoSeeder::class);
    $this->seed(SupportDemoSeeder::class);

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(JournalEntryLine::query()->count())->toBe(0);
});

it('registers no model observer or event listener that could post on a document event', function (): void {
    // FR-034 names observers and listeners specifically. The check is structural
    // rather than behavioural because an observer that posts would otherwise only
    // be caught by whichever document path happened to be exercised above.
    $observerFiles = glob(app_path('Observers/*.php')) ?: [];
    $listenerFiles = glob(app_path('Listeners/*.php')) ?: [];

    foreach ([...$observerFiles, ...$listenerFiles] as $file) {
        $contents = (string) file_get_contents($file);

        expect($contents)->not->toContain('JournalPostingService')
            ->and($contents)->not->toContain('JournalEntry');
    }
});
