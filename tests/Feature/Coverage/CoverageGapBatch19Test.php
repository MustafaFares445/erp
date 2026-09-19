<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\OpportunityOrigin;
use App\Enums\ShipmentStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\AccountsPayable\Pages\ListAccountsPayable;
use App\Filament\Resources\ChartOfAccounts\Pages\CreateChartOfAccount;
use App\Filament\Widgets\InventoryOperationsPipeline;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryCountLine;
use App\Models\InventoryOperation;
use App\Models\SalesOpportunity;
use App\Models\Shipment;
use App\Models\SupplierConfirmationItem;
use App\Models\User;
use App\Services\Accounting\AccountsPayableService;
use Carbon\CarbonImmutable;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('covers inventory pipeline delivery permission and inventory count counted-state helpers', function (): void {
    (new InventoryPermissionSeeder)->run();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::DeliveryView->value);
    $this->actingAs($viewer);

    expect(InventoryOperationsPipeline::canView())->toBeTrue();

    $uncounted = new InventoryCountLine;
    $uncounted->forceFill(['counted_base_quantity' => null]);

    $countedZero = new InventoryCountLine;
    $countedZero->forceFill(['counted_base_quantity' => '0.000000']);

    expect($uncounted->isCounted())->toBeFalse()
        ->and($countedZero->isCounted())->toBeTrue();
});

it('covers chart-of-account creation and accounts-payable unauthenticated guards', function (): void {
    auth()->logout();

    $chartPage = new ReflectionClass(CreateChartOfAccount::class)->newInstanceWithoutConstructor();
    $chartCreate = new ReflectionMethod(CreateChartOfAccount::class, 'handleRecordCreation');

    expect(fn (): mixed => $chartCreate->invoke($chartPage, []))
        ->toThrow(Halt::class);

    $page = new ReflectionClass(ListAccountsPayable::class)->newInstanceWithoutConstructor();
    $canView = new ReflectionMethod(ListAccountsPayable::class, 'canViewPayables');
    $authorize = new ReflectionMethod(ListAccountsPayable::class, 'authorizePayableAccess');
    $headerActions = new ReflectionMethod(ListAccountsPayable::class, 'getHeaderActions');

    expect($canView->invoke($page))->toBeFalse()
        ->and(fn (): mixed => $authorize->invoke($page))
        ->toThrow(HttpException::class);

    $export = $headerActions->invoke($page)[0]->getActionFunction();

    expect(fn (): mixed => $export())
        ->toThrow(LogicException::class, 'authenticated accounting user');
});

it('covers accounts-payable streamed export callback for an authenticated admin', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    app()->instance(AccountsPayableService::class, new class
    {
        public function toCsv(CarbonImmutable $asOf): string
        {
            return "supplier,total\nCoverage,10.00\n";
        }
    });

    $page = new ReflectionClass(ListAccountsPayable::class)->newInstanceWithoutConstructor();
    $page->asOf = today()->toDateString();

    $headerActions = new ReflectionMethod(ListAccountsPayable::class, 'getHeaderActions');
    $export = $headerActions->invoke($page)[0]->getActionFunction();
    $response = $export();

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('Coverage,10.00');
});

it('covers shipment cancelled state and lifecycle transition guards', function (): void {
    $cancelled = Shipment::factory()->create(['status' => ShipmentStatus::Cancelled]);
    expect($cancelled->isCancelled())->toBeTrue();

    $notPlanned = Shipment::factory()->create(['status' => ShipmentStatus::InTransit]);
    expect(fn (): mixed => $notPlanned->markInTransit())
        ->toThrow(DomainException::class, 'Only a planned shipment');

    $draftDelivery = InventoryOperation::factory()->delivery()->draft()->create();
    $planned = Shipment::factory()->create([
        'inventory_operation_id' => $draftDelivery->getKey(),
        'warehouse_id' => $draftDelivery->source_warehouse_id,
        'status' => ShipmentStatus::Planned,
    ]);
    expect(fn (): mixed => $planned->markInTransit())
        ->toThrow(DomainException::class, 'delivery has left inventory');

    expect(fn (): mixed => $planned->confirmBySystem())
        ->toThrow(DomainException::class, 'Only an in-transit shipment');

    $secondDraftDelivery = InventoryOperation::factory()->delivery()->draft()->create();
    $inTransit = Shipment::factory()->create([
        'inventory_operation_id' => $secondDraftDelivery->getKey(),
        'warehouse_id' => $secondDraftDelivery->source_warehouse_id,
        'status' => ShipmentStatus::InTransit,
    ]);
    expect(fn (): mixed => $inTransit->confirmBySystem())
        ->toThrow(DomainException::class, 'completed customer delivery');
});

it('covers supplier confirmation answered state and opportunity direct-resolution and origin immutability', function (): void {
    $pending = SupplierConfirmationItem::factory()->create([
        'confirmation_status' => SupplierConfirmationStatus::Pending,
    ]);
    $answered = SupplierConfirmationItem::factory()->create([
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
    ]);

    expect($pending->isAnswered())->toBeFalse()
        ->and($answered->isAnswered())->toBeTrue();

    $customer = CustomerProfile::factory()->create();
    $customerOpportunity = SalesOpportunity::factory()->manual()->create([
        'customer_id' => $customer->getKey(),
    ]);

    expect($customerOpportunity->resolvedCustomer()?->is($customer))->toBeTrue();

    $employee = EmployeeProfile::factory()->create();
    $owner = $employee->user()->firstOrFail();
    $employeeOpportunity = SalesOpportunity::factory()->manual()->create([
        'owner_id' => $owner->getKey(),
    ]);

    expect($employeeOpportunity->resolvedEmployee()?->is($employee))->toBeTrue();

    $immutable = SalesOpportunity::factory()->manual()->create();
    $immutable->origin = OpportunityOrigin::AiVoiceNote;

    expect(fn (): bool => $immutable->save())
        ->toThrow(DomainException::class, 'origin is immutable');
});
