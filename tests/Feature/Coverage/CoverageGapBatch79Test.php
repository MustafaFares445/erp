<?php

declare(strict_types=1);

use App\Enums\InventoryReportType;
use App\Enums\MaintenanceStatus;
use App\Enums\ReconciliationScope;
use App\Filament\Resources\InventoryReports\Pages\ManageInventoryReports;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\LabourEntriesRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ThirdPartyCostsRelationManager;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\ServiceRecords\Pages\ViewServiceRecord;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\InventoryReportService;
use App\Services\Inventory\PriceResolver;
use App\Services\Support\MaintenanceCostService;
use App\Services\Support\ServiceRecordService;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('covers inventory report reconciliation enum formatting and streamed report rows', function (): void {
    $actor = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->create([
        'sku' => 'B79-CATALOG-SKU',
    ]);

    $component = Livewire::actingAs($actor)->test(ManageInventoryReports::class);
    $component->set('activeTab', InventoryReportType::Catalog->value);
    $component->set('tableFilters', []);

    $page = $component->instance();
    $page->tableFilters = null;

    expect(app(InventoryReportService::class)
        ->query(InventoryReportType::Catalog, [])
        ->whereKey($variant->getKey())
        ->exists())->toBeTrue();

    $response = $page->exportCurrentReport();

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('B79-CATALOG-SKU');

    $table = new ReflectionMethod(ManageInventoryReports::class, 'reconciliationTable')
        ->invoke($page, Table::make($page));
    $scope = $table->getColumn('scope');
    $case = ReconciliationScope::cases()[0];

    expect($scope->formatState($case))
        ->toBe(__('admin.inventory.reports.reconciliation.scopes.'.$case->value));

    expect($variant->exists)->toBeTrue();
});

it('covers service-record completion and transition DomainException notifications', function (): void {
    $actor = User::factory()->admin()->create();

    $fake = new class
    {
        public function transition(mixed ...$arguments): never
        {
            throw new DomainException('Batch 79 transition failure');
        }
    };

    app()->instance(ServiceRecordService::class, $fake);

    try {
        $inProgress = MaintenanceTask::factory()->create([
            'status' => MaintenanceStatus::InProgress,
        ]);

        Livewire::actingAs($actor)
            ->test(ViewServiceRecord::class, ['record' => $inProgress->getKey()])
            ->callAction('complete', [
                'work_performed' => 'Coverage work',
                'completion_notes' => 'Coverage note',
            ])
            ->assertNotified();

        $open = MaintenanceTask::factory()->create([
            'status' => MaintenanceStatus::Open,
        ]);

        Livewire::actingAs($actor)
            ->test(ViewServiceRecord::class, ['record' => $open->getKey()])
            ->callAction('startWork')
            ->assertNotified();
    } finally {
        app()->forgetInstance(ServiceRecordService::class);
    }
});

it('covers third-party and labour cost DomainException notification paths', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $fake = new class
    {
        public function recordThirdPartyCost(mixed ...$arguments): never
        {
            throw new DomainException('Batch 79 third-party failure');
        }

        public function recordLabour(mixed ...$arguments): never
        {
            throw new DomainException('Batch 79 labour failure');
        }
    };

    app()->instance(MaintenanceCostService::class, $fake);

    try {
        $record = MaintenanceRecord::factory()->create();

        $thirdParty = new ThirdPartyCostsRelationManager;
        $thirdParty->ownerRecord = $record;
        $thirdPartyAction = collect($thirdParty->table(Table::make($thirdParty))->getHeaderActions())
            ->first(fn (mixed $candidate): bool => method_exists($candidate, 'getName') && $candidate->getName() === 'recordThirdPartyCost');

        expect($thirdPartyAction)->not->toBeNull();

        ($thirdPartyAction->getActionFunction())([
            'description' => 'Coverage vendor cost',
            'amount_minor' => 1000,
            'incurred_on' => today()->toDateString(),
            'supplier_id' => null,
        ]);

        $labour = new LabourEntriesRelationManager;
        $labour->ownerRecord = $record;
        $labourAction = collect($labour->table(Table::make($labour))->getHeaderActions())
            ->first(fn (mixed $candidate): bool => method_exists($candidate, 'getName') && $candidate->getName() === 'recordLabour');

        expect($labourAction)->not->toBeNull();

        ($labourAction->getActionFunction())([
            'employee_id' => $actor->getKey(),
            'performed_on' => today()->toDateString(),
            'minutes' => 15,
            'hourly_rate_minor' => null,
            'notes' => null,
        ]);
    } finally {
        app()->forgetInstance(MaintenanceCostService::class);
    }
});

it('covers create-order price review when the resolver rejects the price', function (): void {
    $variant = ProductVariant::factory()->create();

    $fake = new class
    {
        public function resolve(mixed ...$arguments): never
        {
            throw new DomainException('Batch 79 unresolved price');
        }
    };

    app()->instance(PriceResolver::class, $fake);

    try {
        $page = new ReflectionClass(CreateOrder::class)->newInstanceWithoutConstructor();
        $preview = new ReflectionMethod(CreateOrder::class, 'pricePreview');

        expect($preview->invoke($page, null, $variant->getKey(), null, null))
            ->toBe('Price requires review');
    } finally {
        app()->forgetInstance(PriceResolver::class);
    }
});
