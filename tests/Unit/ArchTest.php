<?php

declare(strict_types=1);
use App\Http\Controllers\InventoryOperationMediaController;
use App\Http\Controllers\ShipmentMediaController;
use App\Http\Controllers\TicketMediaController;
use App\Http\Controllers\VisitMediaController;
use App\Http\Controllers\VoiceNoteMediaController;
use App\Models\AccountType;
use App\Models\AuditLog;
use App\Models\ChartAccount;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\FiscalPeriod;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\Order;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ServiceRecordPart;
use App\Models\Shipment;
use App\Models\SlaPolicy;
use App\Models\TaskStatusLog;
use App\Models\TicketAssignment;
use App\Models\TicketMessage;
use App\Models\VisitGpsLog;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\OpenAiWhisperTranscriber;

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
    VisitGpsLog::class,
    VoiceNoteTranscription::class,
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
// spec 018 (contracts/journal-posting.md) under App\Services\Accounting\Exceptions.
arch()->preset()->laravel()->ignoring([
    InventoryOperationMediaController::class,
    ShipmentMediaController::class,
    TicketMediaController::class,
    VisitMediaController::class,
    VoiceNoteMediaController::class,
    'App\Services\Employees\Exceptions',
    'App\Services\Support\Exceptions',
    'App\Services\Accounting\Exceptions',
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
// exclusively through ServiceRecordPartService (which itself calls
// InventoryBalanceService), never directly from the Filament layer
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
            'App\Filament\Resources\Returns',
            'App\Filament\Resources\InventoryReports',
            'App\Filament\Resources\InventoryAlerts',
            'App\Filament\Widgets',
        ]);
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
        ]);
});
