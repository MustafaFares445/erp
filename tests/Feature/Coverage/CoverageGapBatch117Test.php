<?php

declare(strict_types=1);

use App\Data\Inventory\BarcodeResolution;
use App\Enums\BillStatus;
use App\Enums\OperationType;
use App\Enums\WriteOffStatus;
use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\ReportsCenter;
use App\Filament\Pages\SalesDashboard;
use App\Filament\RelationManagers\CollaborationEntriesRelationManager;
use App\Filament\Resources\Adjustments\Actions\AdjustmentActions;
use App\Filament\Resources\BankStatements\Actions\BankStatementActions;
use App\Filament\Resources\BankStatements\Pages\CreateBankStatement;
use App\Filament\Resources\BankStatements\Pages\ViewBankStatement;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Filament\Resources\Bills\Schemas\BillInfolist;
use App\Filament\Resources\Customers\Pages\CustomerTimeline;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\InventoryCounts\Pages\ViewInventoryCount;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryOperations\Pages\ViewInventoryOperation;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\Tables\MaintenanceRequestsTable;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\NotificationTemplates\Pages\ListNotificationTemplates;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\OutboundFulfillments\Actions\OutboundFulfillmentActions;
use App\Filament\Resources\PurchaseAgreements\Pages\ViewPurchaseAgreement;
use App\Filament\Resources\PurchaseOrders\RelationManagers\LinesRelationManager;
use App\Filament\Resources\PurchaseRfqs\Pages\ViewPurchaseRfq;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Resources\SupportReports\Pages\ViewSupportReports;
use App\Filament\Resources\Taxes\Pages\ViewTaxRegister;
use App\Filament\Search\IerpGlobalSearchProvider;
use App\Filament\Support\Settings\SettingsRegistry;
use App\Filament\Support\WorkspaceNavigation;
use App\Filament\Widgets\SupportUpcomingMaintenance;
use App\Models\BankStatement;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\CustomerProfile;
use App\Models\Expense;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCount;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventorySetting;
use App\Models\Invoice;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRfq;
use App\Models\ReceivableWriteOff;
use App\Models\Refund;
use App\Models\SupplierConfirmation;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Reporting\ReportRegistry;
use Database\Seeders\CurrencySeeder;
use Filament\Forms\Components\FileUpload;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\Livewire;

uses(RefreshDatabase::class);

final class Coverage117UnavailableOutputStream
{
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }
}

it('handles an unavailable CSV output stream according to the export contract', function (string $export): void {
    $this->actingAs(User::factory()->admin()->create());
    Gate::before(static fn (): bool => true);
    $message = null;
    if ($export === 'count') {
        $page = new ViewInventoryCount;
        $response = new ReflectionMethod($page, 'downloadCountSheet')->invoke($page, InventoryCount::factory()->create());
    } elseif ($export === 'tax') {
        $page = new ViewTaxRegister;
        $response = new ReflectionMethod($page, 'streamCsv')->invoke($page, 'tax.csv', static function (): void {
            throw new LogicException('The writer must not run without a stream.');
        });
    } elseif ($export === 'support') {
        (new CurrencySeeder)->run();
        $page = new ViewSupportReports;
        $response = $page->exportCurrentReport();
        $message = 'Unable to open the support report export stream.';
    } else {
        $page = new CustomerTimeline;
        $page->record = CustomerProfile::factory()->create();
        $response = new ReflectionMethod($page, 'exportCsv')->invoke($page);
        $message = 'Unable to open the CSV output stream.';
    }

    $previousHandler = set_error_handler(static function (int $severity, string $warning, string $file, int $line) use (&$previousHandler): bool {
        if ($severity === E_WARNING && str_contains($warning, 'fopen(php://output)')) {
            return true;
        }

        return $previousHandler !== null ? $previousHandler($severity, $warning, $file, $line) : false;
    });
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', Coverage117UnavailableOutputStream::class);

    try {
        if ($message !== null) {
            expect(fn () => $response->sendContent())->toThrow(RuntimeException::class, $message);
        } else {
            expect($response->sendContent())->toBe($response);
        }
    } finally {
        stream_wrapper_restore('php');
        restore_error_handler();
    }
})->with(['count', 'tax', 'support', 'timeline']);

it('rejects invoice creation and delivery operations without the required actor permissions', function (): void {
    auth()->logout();
    expect(new ReflectionMethod(OutboundFulfillmentActions::class, 'canOnDelivery')->invoke(null, new Order, [], 'dispatch'))->toBeFalse();
    $actor = User::factory()->employee()->create();
    expect($actor->can('create', Invoice::class))->toBeFalse();
    expect(new ReflectionMethod(OrderActions::class, 'createInvoiceTarget')->invoke(null, new Order, $actor))->toBeNull();
});

it('returns safe defaults for missing purchasing documents', function (): void {
    expect(new ReflectionMethod(ManageBills::class, 'createDefaults')->invoke(null, ['purchase_order_id' => 999999999]))
        ->toBe(['bill_date' => today()->toDateString()]);

    request()->query->set('bill_id', 999999999);
    expect(new ReflectionMethod(SupplierPaymentResource::class, 'billAllocationDefaults')->invoke(null, new SupplierPayment))
        ->toBe([]);

    $confirmation = new SupplierConfirmation;
    $confirmation->setRelation('purchaseOrder', null);
    expect(new ReflectionMethod(SupplierConfirmationResource::class, 'primaryLink')->invoke(null, $confirmation))->toBeNull();
});

it('rejects a non-purchase-order owner in the purchase lines manager', function (): void {
    $manager = new LinesRelationManager;
    $manager->ownerRecord = new ProductVariant;
    expect(fn () => new ReflectionMethod($manager, 'order')->invoke($manager))
        ->toThrow(LogicException::class, 'Expected the owner record');
});

it('distinguishes serialized barcode resolutions from variant-only scans', function (?int $serial, bool $expected): void {
    $resolution = new BarcodeResolution('scan', 'variant', 1, 'SKU', null, 'Variant', $serial);
    expect($resolution->isSerialized())->toBe($expected);
})->with([[null, false], [12, true]]);

it('invalidates expiry defaults when inventory settings are deleted', function (): void {
    $setting = InventorySetting::current();
    $setting->update(['expiry_alert_days' => 12]);
    expect(InventorySetting::expiryAlertDays())->toBe(12);
    $setting->delete();
    expect(InventorySetting::expiryAlertDays())->toBe(30);
});

it('uses the warning color for a partially configured notification template', function (): void {
    $host = new ListNotificationTemplates;
    $table = NotificationTemplateResource::table(Table::make($host));
    expect($table->getColumn('status')->getColor('partial'))->toBe('warning');
});

it('refuses to close an already closed bank statement', function (): void {
    $statement = new BankStatement;
    $statement->forceFill(['status' => 'closed']);
    expect(BankStatementActions::isClosable($statement))->toBeFalse();
});

it('reuses the saved table-view action menu within a page instance', function (): void {
    $page = new ListOrders;
    expect($page->getTableViewMenu())->toBe($page->getTableViewMenu());
});

it('rejects an unpersisted inventory lot identifier', function (): void {
    expect(fn () => new ReflectionMethod(InventoryConditionChangeResource::class, 'lotKey')->invoke(null, new InventoryLot))
        ->toThrow(LogicException::class, 'Inventory lot identifiers must be integers.');
});

it('reports credit and approved write-off amounts in the invoice balance breakdown', function (): void {
    $invoice = new Invoice;
    $invoice->forceFill(['amount_paid' => '2.50', 'credited_amount' => '12.50']);
    $writeOff = new ReceivableWriteOff;
    $writeOff->forceFill(['status' => WriteOffStatus::Approved, 'amount_minor' => 500]);
    $invoice->setRelation('writeOffs', new Collection([$writeOff]));

    expect(new ReflectionMethod(InvoicesTable::class, 'outstandingBreakdown')->invoke(null, $invoice))
        ->toBe('Paid: 2.50 · Credited: 12.50 · Written off: 5.00');
});

it('flags a bill line whose price differs from its purchase order', function (): void {
    $bill = new Bill;
    $bill->forceFill(['status' => BillStatus::Approved]);
    $line = new BillLine;
    $line->forceFill(['unit_price' => '12.00']);
    $orderLine = new PurchaseOrderLine;
    $orderLine->forceFill(['unit_cost' => '10.00']);
    $line->setRelation('purchaseOrderLine', $orderLine);
    $bill->setRelation('lines', new Collection([$line]));
    expect(BillInfolist::blocker($bill))->toBe('Three-way match variance requires review');
});

it('does not link a maintenance occurrence to an unavailable schedule', function (): void {
    $widget = new SupportUpcomingMaintenance;
    $table = $widget->table(Table::make($widget));
    $occurrence = new MaintenanceScheduleOccurrence;
    $occurrence->setRelation('schedule', null);
    expect($table->getRecordUrl($occurrence))->toBeNull();
});

it('checks inventory creation permission for the selected operation type', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Gate::before(static fn (): bool => true);
    request()->query->set('operation_type', OperationType::Receipt->value);
    expect(InventoryOperationResource::canCreate())->toBeFalse();
    request()->query->set('operation_type', OperationType::InternalTransfer->value);
    expect(InventoryOperationResource::canCreate())->toBeTrue();
});

it('omits settings whose resource URL cannot be resolved', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Gate::before(static fn (): bool => true);
    $router = app('router');
    $routes = $router->getRoutes();
    $router->setRoutes(new RouteCollection);
    try {
        expect(SettingsRegistry::accessible())->toBe([]);
    } finally {
        $router->setRoutes($routes);
    }
});

it('requires an authenticated actor before receiving a transfer from its detail page', function (): void {
    auth()->logout();
    $page = new ViewInventoryOperation;
    $action = new ReflectionMethod($page, 'transferReceiptAction')->invoke($page);
    expect(fn () => ($action->getActionFunction())(new InventoryOperation, []))
        ->toThrow(LogicException::class, 'An authenticated inventory operation actor is required.');
});

it('returns no pending confirmations to an unauthenticated adjustment viewer', function (): void {
    auth()->logout();
    InventoryAdjustment::factory()->create();
    $page = new \App\Filament\Resources\Adjustments\Pages\ListAdjustments;
    $table = \App\Filament\Resources\Adjustments\Tables\AdjustmentsTable::configure(\Filament\Tables\Table::make($page));
    $query = $table->getFilter('pending_my_confirmation')->apply(InventoryAdjustment::query());
    expect($query->get())->toBeEmpty();
});

it('does not delete a fiscal period without an authenticated accounting actor', function (): void {
    auth()->logout();
    $period = \App\Models\FiscalPeriod::factory()->create();
    $page = new \App\Filament\Resources\FiscalPeriods\Pages\ListFiscalPeriods;
    $table = \App\Filament\Resources\FiscalPeriods\Tables\FiscalPeriodsTable::configure(\Filament\Tables\Table::make($page));
    $action = $table->getAction('delete')->record($period);
    expect($action->process(null))->toBeFalse()
        ->and($period->fresh())->not->toBeNull();
});

it('rejects a count sheet that disappears after its existence check', function (): void {
    $this->actingAs(User::factory()->create());
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-count-'.bin2hex(random_bytes(8)).'.csv';
    $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
    $disk->shouldReceive('exists')->once()->with('sheet.csv')->andReturnTrue();
    $disk->shouldReceive('path')->once()->with('sheet.csv')->andReturn($path);
    \Illuminate\Support\Facades\Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    $page = new ViewInventoryCount;
    $count = new InventoryCount;
    $previousHandler = set_error_handler(static function (int $severity, string $warning, string $file, int $line) use (&$previousHandler, $path): bool {
        if ($severity === E_WARNING && str_contains($warning, 'fopen('.$path.')')) {
            return true;
        }
        return $previousHandler !== null ? $previousHandler($severity, $warning, $file, $line) : false;
    });
    try {
        expect(fn () => new ReflectionMethod($page, 'uploadCounts')->invoke($page, $count, 'sheet.csv'))
            ->toThrow(LogicException::class, 'The uploaded count sheet could not be opened.');
    } finally {
        restore_error_handler();
    }
});

it('requires an authenticated accounting user before running a document action', function (string $resource, string $factory, string $model): void {
    auth()->logout();
    $action = new ReflectionMethod($resource, $factory)->invoke(null);
    $record = new $model;

    expect(fn () => ($action->getActionFunction())($record))
        ->toThrow(LogicException::class, 'An authenticated accounting user is required.');
})->with([
    'approve bill' => [BillResource::class, 'approveAction', Bill::class],
    'cancel bill' => [BillResource::class, 'cancelAction', Bill::class],
    'approve refund' => [RefundResource::class, 'approveAction', Refund::class],
    'pay refund' => [RefundResource::class, 'payAction', Refund::class],
    'cancel refund' => [RefundResource::class, 'cancelAction', Refund::class],
]);

it('rejects a missing actor in maintenance and collaboration helpers', function (string $componentClass, string $method): void {
    auth()->logout();
    $component = new $componentClass;

    expect(fn () => new ReflectionMethod($componentClass, $method)->invoke($component))
        ->toThrow(LogicException::class, 'An authenticated User is required');
})->with([
    'maintenance page' => [ViewMaintenanceRequest::class, 'currentActor'],
    'maintenance table' => [MaintenanceRequestsTable::class, 'currentActor'],
    'collaboration entries' => [CollaborationEntriesRelationManager::class, 'actor'],
]);

it('uses the expected record to build document page titles', function (string $pageClass, string $modelClass, string $numberField, string $title): void {
    $page = new $pageClass;
    $record = new $modelClass;
    $record->forceFill([$numberField => 'TITLE-117']);
    $page->record = $record;

    expect($page->getTitle())->toBe($title);
})->with([
    'bank statement' => [ViewBankStatement::class, BankStatement::class, 'statement_number', 'Bank Statement TITLE-117'],
    'purchase RFQ' => [ViewPurchaseRfq::class, PurchaseRfq::class, 'rfq_number', 'RFQ TITLE-117'],
]);

it('rejects a document page record from another domain', function (string $pageClass, string $message): void {
    $page = new $pageClass;
    $page->record = new ProductVariant;

    expect(fn () => $page->getTitle())->toThrow(LogicException::class, $message);
})->with([
    'bank statement' => [ViewBankStatement::class, 'Expected a BankStatement record.'],
    'purchase RFQ' => [ViewPurchaseRfq::class, 'Expected a PurchaseRfq record.'],
]);

it('exposes no report domains to an unauthenticated actor', function (): void {
    auth()->logout();

    expect(ReportRegistry::accessibleDomains())->toBe([]);
    expect(new ReportsCenter()->domains())->toBe([]);
});

it('halts bank statement creation without an accounting actor', function (): void {
    auth()->logout();
    $page = new CreateBankStatement;

    expect(fn () => new ReflectionMethod($page, 'handleRecordCreation')->invoke($page, []))
        ->toThrow(Halt::class);
});

it('leaves a bank statement open when its close handler has no actor', function (): void {
    auth()->logout();
    $statement = new BankStatement;
    $statement->forceFill(['status' => 'open']);

    (BankStatementActions::close()->getActionFunction())($statement);

    expect($statement->status)->toBe('open');
});

it('hides delivery preparation from unauthenticated actors', function (): void {
    auth()->logout();

    expect(OutboundFulfillmentActions::prepareDelivery()->record(new Order)->isVisible())->toBeFalse();
});

final class Coverage117SchemaHost extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public function render(): string
    {
        return '<div></div>';
    }
}

final class Coverage117ThrowingPage extends Page
{
    protected string $view = 'filament.pages.dashboard';

    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null,
    ): string {
        throw new RuntimeException('coverage URL failure');
    }
}

it('covers unauthenticated guards in inventory adjustment actions', function (): void {
    auth()->logout();

    $record = new InventoryAdjustment;

    expect(fn () => (AdjustmentActions::confirm()->getActionFunction())($record))
        ->toThrow(LogicException::class, 'authenticated User');

    $livewire = Livewire::test(Coverage117SchemaHost::class)->instance();

    expect(fn () => (AdjustmentActions::createCorrection()->getActionFunction())(
        $record,
        ['reason' => 'coverage'],
        $livewire,
    ))->toThrow(LogicException::class, 'authenticated actor');
});

it('covers unauthenticated expense action guards and null receipt hydration', function (): void {
    auth()->logout();

    $expense = new Expense;

    expect(fn () => (ExpenseResource::approveAction()->getActionFunction())($expense))
        ->toThrow(LogicException::class, 'authenticated accounting user')
        ->and(fn () => (ExpenseResource::payAction()->getActionFunction())($expense, []))
        ->toThrow(LogicException::class, 'authenticated accounting user')
        ->and(fn () => (ExpenseResource::cancelAction()->getActionFunction())($expense))
        ->toThrow(LogicException::class, 'authenticated accounting user');

    $host = Livewire::test(Coverage117SchemaHost::class)->instance();
    $schema = ExpenseResource::form(Schema::make($host)->model(Expense::class));

    $receipt = collect($schema->getFlatComponents(withHidden: true))
        ->first(fn (mixed $component): bool => $component instanceof FileUpload && $component->getName() === 'receipt');

    expect($receipt)->toBeInstanceOf(FileUpload::class);

    $receipt->callAfterStateHydrated();
    expect($receipt->getState())->toBeNull();
});

it('covers purchase agreement no-actor action guards and invalid record type guard', function (): void {
    auth()->logout();

    $page = new ViewPurchaseAgreement;
    $headerActions = new ReflectionMethod(ViewPurchaseAgreement::class, 'getHeaderActions');
    $actions = $headerActions->invoke($page);

    foreach ($actions as $action) {
        expect(($action->getActionFunction())())->toBeNull();
    }

    $page->record = new ProductVariant;
    $agreement = new ReflectionMethod(ViewPurchaseAgreement::class, 'agreement');

    expect(fn () => $agreement->invoke($page))
        ->toThrow(LogicException::class, 'Expected a PurchaseAgreement');
});

it('covers global navigation URL failure and eight-result early return', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Gate::before(static fn (): bool => true);
    $provider = app(IerpGlobalSearchProvider::class);
    $navigation = new ReflectionMethod(IerpGlobalSearchProvider::class, 'navigationResults');
    $urlFor = new ReflectionMethod(IerpGlobalSearchProvider::class, 'urlForRegistryItem');

    expect($urlFor->invoke($provider, [
        'label' => 'Broken',
        'link' => Coverage117ThrowingPage::class,
    ]))->toBeNull();

    $groupsProperty = new ReflectionProperty(AdminModuleRegistry::class, 'groupDefinitions');
    $original = $groupsProperty->getValue();

    $items = [[
        'label' => 'Broken coverage item',
        'link' => Coverage117ThrowingPage::class,
        'section' => 'overview',
        'tabs' => [['label' => 'Invalid workspace tab', 'link' => Coverage117ThrowingPage::class]],
    ]];

    for ($i = 1; $i <= 9; $i++) {
        $items[] = [
            'label' => 'Coverage searchable item '.$i,
            'link' => SalesDashboard::class,
            'section' => 'overview',
        ];
    }

    $groupsProperty->setValue(null, [[
        'key' => 'coverage',
        'label' => 'Coverage navigation',
        'icon' => Heroicon::OutlinedMagnifyingGlass,
        'sort' => 1,
        'sections' => [['key' => 'overview', 'label' => 'Coverage']],
        'items' => $items,
    ]]);
    AdminModuleRegistry::forgetMemoized();

    try {
        expect(WorkspaceNavigation::listPageScopes())->toBe([]);
        $broken = $navigation->invoke($provider, 'broken coverage');
        expect($broken)->toBe([]);

        $many = $navigation->invoke($provider, 'coverage searchable');
        expect($many)->toHaveCount(8);
    } finally {
        $groupsProperty->setValue(null, $original);
        AdminModuleRegistry::forgetMemoized();
    }
});
