<?php

declare(strict_types=1);

use App\Filament\Widgets\AccountingLedgerTrend;
use App\Http\Controllers\InventoryOperationMediaController;
use App\Http\Controllers\ShipmentMediaController;
use App\Http\Controllers\TicketMediaController;
use App\Http\Controllers\VisitMediaController;
use App\Http\Controllers\VoiceNoteMediaController;
use App\Models\AccountType;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryCorrection;
use App\Models\InventoryCorrectionLine;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\InvoiceConfirmation;
use App\Models\InvoiceLine;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\ManualPaymentRecord;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Refund;
use App\Models\ServiceRecordPart;
use App\Models\Shipment;
use App\Models\SlaPolicy;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierProductSupport;
use App\Models\TaskStatusLog;
use App\Models\TaxRecognitionEntry;
use App\Models\TicketAssignment;
use App\Models\TicketMessage;
use App\Models\VisitGpsLog;
use App\Models\VoiceNoteTranscription;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Employees\OpenAiWhisperTranscriber;
use App\Services\Inventory\CatalogImportApplicationService;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryDamageService;
use App\Services\Inventory\InventoryLotService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Inventory\InventoryReturnService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Shipments\ShipmentService;
use App\Services\Support\ServiceRecordPartService;
use Illuminate\Support\Facades\File;

arch()->preset()->php();
// PriceFloorOverride/PriceHistory (spec 014) established the precedent this
// feature follows for the same reason: a protected static booted() is
// Eloquent's own required override signature for a model-level saving/
// deleting guard (EmployeeProfile's base-salary rule; TaskStatusLog's and
// VisitGpsLog's append-only guards; VoiceNoteTranscription's confidence
// invariant, D6) — not a design choice that could be made public instead.
//
// Order is unrelated to spec 014/015: its protected casts() overrides
// Eloquent's own casts() (Illuminate\Database\Eloquent\Concerns\HasAttributes),
// which the framework itself declares protected — same reasoning, a
// different required-override hook.
//
// Product::ofType()/ProductVariant::ofProductType() are Laravel's #[Scope]
// attribute local scopes (Laravel 12.4+): the framework's own documented
// convention declares these protected, unlike the older scopeXxx() naming
// convention which required public.
//
// Shipment combines both patterns above in one class: protected static
// booted() guards tracking_number generation (same required-override
// reasoning as the spec 014/015 group), and protected casts() is the
// same Eloquent override as Order's.
//
// AuditLog (ADR 0005): sourceChannel()/ipAddress() are Laravel's own
// Attribute::make() accessor methods (Illuminate\Database\Eloquent\Casts\
// Attribute), which the framework's own convention declares protected —
// the same required-override reasoning as Order's/Shipment's casts().
//
// TicketAssignment/TicketMessage (spec 016): protected static booted()
// guards their append-only invariant (FR-023/FR-032), the same required
// Eloquent-override reasoning as TaskStatusLog/VisitGpsLog above.
//
// SlaPolicy (spec 016, US5): protected static booted() stamps `updated_by`
// from the acting user on every edit (data-model.md §5 — no `created_by`,
// so TracksBlameable isn't reused) — the same required-override reasoning.
//
// ServiceRecordPart (spec 016, US8): protected static booted() guards its
// immutable-except-reversal-fields invariant (FR-086), the same required
// Eloquent-override reasoning as TicketAssignment/TicketMessage above.
//
// MaintenanceRecord (spec 016, FR-064): protected static booted() guards its
// covered-warranty-requires-expiry invariant as defense-in-depth against a
// direct write bypassing MaintenanceRecordService, the same required
// Eloquent-override reasoning as EmployeeProfile above.
//
// MaintenanceTask (spec 016, FR-071): protected static booted() guards its
// never-movable-between-parents invariant as defense-in-depth against a
// direct write bypassing ServiceRecordService, the same required
// Eloquent-override reasoning as TicketAssignment/TicketMessage above.
//
// AccountType/ChartAccount/FiscalPeriod (spec 018) override protected casts()
// only, the same Eloquent-mandated signature as Order above.
//
// JournalEntry/JournalEntryLine (spec 018, FR-025): both override protected
// static booted() to refuse every write against a *posted* entry — the entry
// itself on update/delete, and its lines on create/update/delete. Same required
// Eloquent-override signature as TicketMessage above, and the reason is stronger
// here than anywhere else in the codebase: a service-only guard would leave the
// ledger rewritable by any code path that skipped JournalPostingService, and a
// silently-edited posted entry is the one failure double-entry bookkeeping cannot
// recover from.
arch()->preset()->strict()->ignoring([
    'App\Filament',
    'App\Policies',
    'App\Models\Concerns',
    AuditLog::class,
    PriceFloorOverride::class,
    PriceHistory::class,
    EmployeeProfile::class,
    MaintenanceRecord::class,
    MaintenanceTask::class,
    ServiceRecordPart::class,
    SlaPolicy::class,
    TaskStatusLog::class,
    TicketAssignment::class,
    TicketMessage::class,
    AccountType::class,
    ChartAccount::class,
    FiscalPeriod::class,
    JournalEntry::class,
    JournalEntryLine::class,
    InventoryReturn::class,
    InventoryReturnLine::class,
    InventoryCorrection::class,
    InventoryCorrectionLine::class,
    VisitGpsLog::class,
    VoiceNoteTranscription::class,
    Bill::class,
    BillLine::class,
    CreditNote::class,
    CreditNoteLine::class,
    Expense::class,
    InventoryLot::class,
    Invoice::class,
    InvoiceConfirmation::class,
    InvoiceLine::class,
    ManualPaymentRecord::class,
    OrderLine::class,
    Payment::class,
    PaymentAllocation::class,
    PaymentMethod::class,
    Quotation::class,
    QuotationLine::class,
    Refund::class,
    SupplierConfirmationItem::class,
    SupplierPayment::class,
    SupplierPaymentAllocation::class,
    SupplierProductSupport::class,
    TaxRecognitionEntry::class,
    Order::class,
    Product::class,
    ProductVariant::class,
    Shipment::class,
    'Database',
]);
// These stream a private Spatie MediaLibrary collection behind Gate::authorize
// (preview/download, or play for the signed-URL voice-note case) — a shape the Laravel
// preset's controller-method check doesn't recognize. Deliberate, not a REST resource;
// every other controller still must fit the preset's allowed method list.
//
// The preset also bans any class implementing Throwable outside App\Exceptions
// (Laravel's single conventional home for them). This feature's domain exceptions
// (contracts/plan-lifecycle.md, contracts/voice-note-ai.md) are deliberately scoped
// to App\Services\Employees\Exceptions instead, next to the services that throw
// them, rather than collected in one flat, feature-agnostic folder — so that
// namespace is exempted from this one preset rule, not from the rest of it.
// Spec 016 (contracts/ticket-lifecycle.md, contracts/maintenance-lifecycle.md)
// follows the identical precedent under App\Services\Support\Exceptions, and
// spec 018 (contracts/journal-posting.md) under App\Services\Accounting\Exceptions,
// spec 017 under App\Services\Purchasing\Exceptions, and spec 019 under
// App\Services\Sales\Exceptions and App\Services\Payments\Exceptions.
arch()->preset()->laravel()->ignoring([
    InventoryOperationMediaController::class,
    ShipmentMediaController::class,
    TicketMediaController::class,
    VisitMediaController::class,
    VoiceNoteMediaController::class,
    'App\Services\Employees\Exceptions',
    'App\Services\Support\Exceptions',
    'App\Services\Accounting\Exceptions',
    'App\Services\Purchasing\Exceptions',
    'App\Services\Sales\Exceptions',
    'App\Services\Payments\Exceptions',
]);
arch()->preset()->security();

// Intent: no App\Filament code path may write stock balances or movement
// records. Stock levels, movements, returns, reports, and widgets may read
// these models through tested read-only surfaces. Every other Filament
// namespace remains banned, so write surfaces must use domain services.
// See specs/002-warehouses-stock-visibility/research.md R1.
//
// App\Filament\Resources\InventoryOperations and App\Filament\Resources\Packages
// (specs/014-inventory-erp-rework) are deliberately absent from the ignoring()
// list below and must stay that way: both write stock exclusively through
// InventoryOperationService, never directly (contracts/inventory-operations.md
// P-2). Because this assertion targets the whole App\Filament namespace, it
// already covers those two namespaces the moment their classes exist — no
// second assertion is needed, only the discipline not to except them here.
//
// App\Filament\Resources\{Tickets,MaintenanceRequests,ServiceRecords} (spec
// 016) are held to the same discipline: spare-parts consumption writes stock
// exclusively through ServiceRecordPartService, which now delegates every
// stock/lot/serial mutation to InventoryPostingService
// (contracts/maintenance-lifecycle.md §4).
it('never writes stock balances or movement records directly from a Filament class', function (): void {
    expect('App\Filament')
        ->not->toUse([
            InventoryStock::class,
            InventoryMovement::class,
        ])
        ->ignoring([
            'App\Filament\Resources\StockLevels',
            'App\Filament\Resources\StockMovements',
            'App\Filament\Resources\InventoryReports',
            'App\Filament\Resources\InventoryAlerts',
            'App\Filament\Resources\InventoryExports',
            'App\Filament\Widgets',
        ]);
});

it('keeps cross-module inventory consumers behind canonical service boundaries', function (): void {
    foreach ([
        CatalogImportApplicationService::class,
        OrderFulfillmentService::class,
        PurchaseOrderReceivingService::class,
        ShipmentService::class,
    ] as $consumer) {
        expect($consumer)->not->toUse([
            InventoryBalanceService::class,
            InventoryPostingService::class,
            InventoryConditionBalance::class,
        ]);
    }

    expect(CatalogImportApplicationService::class)->toUse(InventoryOperationService::class)
        ->and(OrderFulfillmentService::class)->toUse(InventoryOperationService::class)
        ->and(PurchaseOrderReceivingService::class)->toUse(InventoryOperation::class);
});

// Phase 6 closes the temporary writer allow-list. InventoryPostingService is
// the sole application service allowed to depend on the low-level balance engine.
it('allows low-level balance mutations only from the canonical posting service', function (): void {
    expect('App')
        ->not->toUse(InventoryBalanceService::class)
        ->ignoring([
            InventoryPostingService::class,
        ]);
});

it('contains no legacy receipt writer or writable Filament receipt surface', function (): void {
    expect(class_exists('App\\Services\\Inventory\\InventoryReceivingService'))->toBeFalse()
        ->and(class_exists('App\\Data\\Inventory\\ReceiptMovementContext'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Resources\\InventoryReceipts\\InventoryReceiptResource'))->toBeFalse()
        ->and(class_exists('App\\Policies\\InventoryReceiptPolicy'))->toBeFalse();
});

it('contains no legacy transfer writer or writable Filament transfer surface', function (): void {
    expect(class_exists('App\\Services\\Inventory\\StockTransferService'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Resources\\Transfers\\TransferResource'))->toBeFalse()
        ->and(class_exists('App\\Policies\\StockTransferPolicy'))->toBeFalse()
        ->and(class_exists('App\\Observers\\StockTransferObserver'))->toBeFalse();
});

it('contains no legacy reservation writer or aggregate-only reservation resource', function (): void {
    expect(class_exists('App\\Services\\Inventory\\ReservationService'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Resources\\StockReservations\\StockReservationResource'))->toBeFalse()
        ->and(class_exists('App\\Policies\\StockReservationPolicy'))->toBeFalse();
});

it('keeps serialized lifecycle tests and runtime free of retired receipt and transfer writers', function (): void {
    expect(class_exists('App\\Services\\Inventory\\InventoryReceivingService'))->toBeFalse()
        ->and(class_exists('App\\Services\\Inventory\\StockTransferService'))->toBeFalse();
});

it('keeps every migrated warehouse writer behind the canonical posting boundary', function (): void {
    foreach ([
        InventoryOperationService::class,
        InventoryReservationService::class,
        InventoryAdjustmentService::class,
        InventoryDamageService::class,
        InventoryReturnService::class,
        InventoryCorrectionService::class,
        ServiceRecordPartService::class,
    ] as $service) {
        expect($service)->not->toUse(InventoryBalanceService::class);
    }
});

it('keeps canonical condition-balance mutation inside InventoryPostingService', function (): void {
    foreach ([
        InventoryOperationService::class,
        InventoryReservationService::class,
        InventoryAdjustmentService::class,
        InventoryDamageService::class,
        InventoryLotService::class,
        InventoryReturnService::class,
        InventoryCorrectionService::class,
        ServiceRecordPartService::class,
    ] as $service) {
        expect($service)->not->toUse([
            InventoryConditionBalance::class,
        ]);
    }

    expect(InventoryPostingService::class)->toUse([
        InventoryConditionBalance::class,
        InventoryLotBalance::class,
    ]);
});

it('routes canonical receipt corrections through InventoryPostingService and never rewrites balances directly', function (): void {
    expect(InventoryCorrectionService::class)->toUse(InventoryPostingService::class);
    expect(InventoryCorrectionService::class)->not->toUse([
        InventoryBalanceService::class,
        InventoryConditionBalance::class,
        InventoryLotBalance::class,
        'App\\Services\\Purchasing',
        'App\\Services\\Accounting',
        'App\\Services\\Payments',
    ]);

    $source = (string) file_get_contents(app_path('Services/Inventory/InventoryCorrectionService.php'));

    expect($source)
        ->toContain('MovementType::Correction')
        ->toContain('reversalOfMovementId:')
        ->not->toContain('InventoryStock::')
        ->not->toContain('InventoryMovement::query()->forceCreate');
});

it('keeps the Corrections Filament resource service-backed', function (): void {
    $paths = [
        app_path('Filament/Resources/InventoryCorrections/Pages/ManageInventoryCorrections.php'),
        app_path('Filament/Resources/InventoryCorrections/Pages/ViewInventoryCorrection.php'),
        app_path('Filament/Resources/InventoryCorrections/RelationManagers/CorrectionLinesRelationManager.php'),
    ];

    foreach ($paths as $path) {
        $source = (string) file_get_contents($path);

        expect($source)
            ->toContain('InventoryCorrectionService::class')
            ->not->toContain('InventoryStock::')
            ->not->toContain('InventoryMovement::')
            ->not->toContain('InventoryBalanceService');
    }
});

it('routes canonical returns through InventoryPostingService without financial or purchasing writers', function (): void {
    expect(InventoryReturnService::class)->toUse(InventoryPostingService::class);
    expect(InventoryReturnService::class)->not->toUse(InventoryBalanceService::class);
    expect(InventoryReturnService::class)->not->toUse([
        InventoryConditionBalance::class,
        PurchaseOrder::class,
        PurchaseOrderLine::class,
        'App\\Services\\Purchasing',
        'App\\Services\\Accounting',
        'App\\Services\\Payments',
    ]);

    $source = (string) file_get_contents(app_path('Services/Inventory/InventoryReturnService.php'));

    foreach ([
        'CreditNote',
        'Refund',
        'JournalEntry',
        'JournalPostingService',
        'AccountingDocumentService',
        'PaymentPostingService',
        'SupplierPayment',
    ] as $financialWriter) {
        expect($source)->not->toContain($financialWriter);
    }
});

it('keeps MovementType Return owned by the canonical return service', function (): void {
    $owners = [];

    foreach (File::allFiles(app_path('Services')) as $file) {
        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, 'MovementType::Return')) {
            $owners[] = str_replace('\\', '/', $file->getRelativePathname());
        }
    }

    sort($owners);

    expect($owners)->toBe([
        'Inventory/InventoryLotReconciliationService.php',
        'Inventory/InventoryReturnService.php',
    ]);
});

it('keeps the Returns Filament resource service-backed and removes the movement-ledger placeholder', function (): void {
    $manage = (string) file_get_contents(app_path('Filament/Resources/Returns/Pages/ManageReturns.php'));
    $view = (string) file_get_contents(app_path('Filament/Resources/Returns/Pages/ViewReturn.php'));
    $lines = (string) file_get_contents(app_path('Filament/Resources/Returns/RelationManagers/ReturnLinesRelationManager.php'));

    expect($manage)
        ->toContain('InventoryReturnService::class')
        ->not->toContain('StockMovementResource')
        ->not->toContain('MovementType::Return');

    expect($view)->toContain('InventoryReturnService::class');
    expect($lines)->toContain('InventoryReturnService::class');

    foreach ([$manage, $view, $lines] as $source) {
        expect($source)
            ->not->toContain('InventoryStock::')
            ->not->toContain('InventoryMovement::')
            ->not->toContain('InventoryBalanceService');
    }
});

it('keeps runtime inventory logic off deprecated InventoryLot warehouse and quantity columns', function (): void {
    $paths = [
        app_path('Services/Inventory/InventoryPostingService.php'),
        app_path('Services/Inventory/InventoryLotService.php'),
        app_path('Services/Inventory/InventoryOperationService.php'),
        app_path('Services/Inventory/InventoryReservationService.php'),
        app_path('Services/Inventory/InventoryAdjustmentService.php'),
        app_path('Services/Inventory/InventoryDamageService.php'),
        app_path('Services/Support/ServiceRecordPartService.php'),
        app_path('Services/Inventory/InventoryReportService.php'),
        app_path('Services/Inventory/InventoryReportFormatter.php'),
        app_path('Filament/Resources/InventoryOperations/Schemas/OperationLinesRepeater.php'),
        app_path('Filament/Resources/Adjustments/Schemas/AdjustmentForm.php'),
        app_path('Filament/Resources/Adjustments/RelationManagers/AdjustmentItemsRelationManager.php'),
        app_path('Filament/Resources/ServiceRecords/RelationManagers/ConsumedPartsRelationManager.php'),
        app_path('Filament/Resources/StockLevels/Actions/StockDamageActions.php'),
        app_path('Filament/Resources/InventoryLots/InventoryLotResource.php'),
        app_path('Filament/Resources/InventoryLots/Tables/InventoryLotsTable.php'),
        app_path('Filament/Resources/InventoryLots/Schemas/InventoryLotInfolist.php'),
    ];

    foreach ($paths as $path) {
        $source = (string) file_get_contents($path);

        expect($source)
            ->not->toContain('$lot->warehouse_id')
            ->not->toContain('$lot->on_hand_quantity')
            ->not->toContain('$lot->reserved_quantity')
            ->not->toContain("where('warehouse_id', \$stock->warehouse_id)\n            ->where('on_hand_quantity'");
    }
});

it('contains no standalone product subscription runtime class', function (): void {
    expect(class_exists('App\\Models\\ProductSubscription'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Resources\\ProductSubscriptions\\ProductSubscriptionResource'))->toBeFalse();
});

// Intent: the OpenAI transcription client is a provider boundary detail
// (D6, contracts/voice-note-ai.md). No class outside the concrete driver may
// reference it, so the provider stays swappable behind VoiceNoteTranscriber
// and no test can accidentally reach the network from anywhere else.
it('never references the OpenAI client outside the transcription driver namespace', function (): void {
    expect('App')
        ->not->toUse('OpenAI')
        ->ignoring(OpenAiWhisperTranscriber::class);
});

// Intent: EmployeePerformanceScore/EmployeeSalaryCalculation rows are
// written exclusively by PerformanceScoringService/SalaryCalculationService
// (D2-D5, contracts/performance-scoring.md), never directly from a Filament
// resource, mirroring the existing stock-write ban above. Performance and
// SalaryCalculations are deliberately read-only preview surfaces (T165/T166)
// — like StockLevels/StockMovements above, they may read these models
// through their own resource namespace; every other Filament namespace
// remains banned, so any other write surface must go through the services.
it('never writes performance or salary rows directly from a Filament class', function (): void {
    expect('App\Filament')
        ->not->toUse([
            EmployeePerformanceScore::class,
            EmployeeSalaryCalculation::class,
        ])
        ->ignoring([
            'App\Filament\Resources\Performance',
            'App\Filament\Resources\SalaryCalculations',
        ]);
});

// Intent: every App\Services\Support service takes an explicit User $actor
// parameter and self-checks authorization against it (research.md §4,
// contracts/permissions.md) — a deliberate strengthening over the Employees
// module's inconsistent precedent, so a direct service call is never an
// authorization bypass. Calling auth()->user() internally would silently
// reintroduce that gap by letting a service trust the current web session
// instead of the actor it was explicitly given.
it('never resolves the authenticated user internally in a Support service', function (): void {
    expect('App\Services\Support')
        ->not->toUse('auth');
});

// Intent: identical rule for App\Services\Accounting (spec 018, research.md
// R-010). Every accounting service takes an explicit User $actor and authorizes
// exactly one ability against it, so a direct service call is never an
// authorization bypass. The ledger is the surface where trusting the ambient
// session instead of the given actor would be least recoverable.
it('never resolves the authenticated user internally in an Accounting service', function (): void {
    expect('App\Services\Accounting')
        ->not->toUse('auth');
});

// Intent: a journal line is written only as part of an entry, and only by
// JournalPostingService (contracts/journal-posting.md). The two accounting
// resource namespaces may touch the model — JournalEntries binds the lines
// repeater and reads their amounts for the live total, ChartOfAccounts reads them
// for the ledger and the balance column — but every other Filament namespace is
// banned, so no future document screen can grow its own line-writing shortcut.
// This mirrors the stock-write and performance-write bans above.
it('never uses journal entry lines from a Filament class outside the two accounting resources', function (): void {
    expect('App\Filament')
        ->not->toUse(JournalEntryLine::class)
        ->ignoring([
            'App\Filament\Resources\JournalEntries',
            'App\Filament\Resources\ChartOfAccounts',
            AccountingLedgerTrend::class,
        ]);
});

// Intent: SC-002 made mechanical. Purchasing initiates receipts and reacts to
// their completion, but it never writes stock itself — that is the whole of
// R-001, and a review-only guarantee would last exactly until the first
// "convenient" balance update. Both the service namespace and the purchase-order
// Filament namespace are held to it, since a resource action is the other place
// a shortcut would appear.
it('never writes stock from a Purchasing class', function (): void {
    expect('App\Services\Purchasing')
        ->not->toUse([InventoryStock::class, InventoryMovement::class, InventoryBalanceService::class]);

    expect('App\Filament\Resources\PurchaseOrders')
        ->not->toUse([InventoryStock::class, InventoryMovement::class, InventoryBalanceService::class]);
});

// Intent: the same explicit-actor rule the Support and Accounting services
// follow. Every purchasing service takes a User $actor and authorizes against
// it, so a direct call from a console command or a queued job is checked
// identically to a dashboard click (R-G).
it('never resolves the authenticated user internally in a Purchasing service', function (): void {
    expect('App\Services\Purchasing')
        ->not->toUse('auth');
});

// Intent: the dependency arrow points Purchasing -> Inventory and never back.
// Inventory emits a completion event that carries no purchasing knowledge; if
// InventoryOperationService ever named a purchasing class directly, the
// folder-level domain boundary Principle II protects would be gone (R-002).
it('never references a Purchasing class from an Inventory service', function (): void {
    expect('App\Services\Inventory')
        ->not->toUse([
            PurchaseOrder::class,
            PurchaseOrderLine::class,
            SupplierConfirmation::class,
            'App\Services\Purchasing',
        ]);
});

// Intent: the same explicit-actor rule every prior module's ledger-adjacent
// services follow (spec 019, FR-077). No Sales or Payments service may resolve
// the acting user from the ambient session; the actor is always an explicit
// argument, so a call from a queued job or a console command is authorized
// identically to a dashboard click.
it('never resolves the authenticated user internally in a Sales service', function (): void {
    expect('App\Services\Sales')
        ->not->toUse('auth');
});

it('never resolves the authenticated user internally in a Payments service', function (): void {
    expect('App\Services\Payments')
        ->not->toUse('auth');
});

// The payment-posting and tax-recognition services are intentionally not built
// yet. Until they are, retain the manual-only boundary by rejecting a Stripe
// dependency from the application.
// Intent: SC-013/FR-053. A reporting surface is the most natural place for a
// posting path to be added quietly — a "post the year-end close from the
// Balance Sheet" convenience is one line of plausible code and would be a
// governance breach (ADR 0009). Nothing in this feature may call
// JournalPostingService or any other write path.
it('never calls JournalPostingService from the financial reports feature', function (): void {
    expect('App\Filament\Resources\FinancialReports')
        ->not->toUse(JournalPostingService::class);

    expect(FinancialReportService::class)
        ->not->toUse(JournalPostingService::class);
});

it('does not introduce a Stripe payment-channel dependency', function (): void {
    expect('App')->not->toUse('Stripe');
});
