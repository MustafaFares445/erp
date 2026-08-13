<?php

declare(strict_types=1);
use App\Http\Controllers\InventoryOperationMediaController;
use App\Http\Controllers\ShipmentMediaController;
use App\Http\Controllers\VisitMediaController;
use App\Http\Controllers\VoiceNoteMediaController;
use App\Models\AuditLog;
use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\TaskStatusLog;
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
arch()->preset()->strict()->ignoring([
    'App\Filament',
    'App\Policies',
    'App\Models\Concerns',
    AuditLog::class,
    PriceFloorOverride::class,
    PriceHistory::class,
    EmployeeProfile::class,
    TaskStatusLog::class,
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
arch()->preset()->laravel()->ignoring([
    InventoryOperationMediaController::class,
    ShipmentMediaController::class,
    VisitMediaController::class,
    VoiceNoteMediaController::class,
    'App\Services\Employees\Exceptions',
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
