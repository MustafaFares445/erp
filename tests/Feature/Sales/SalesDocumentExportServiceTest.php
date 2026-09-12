<?php

declare(strict_types=1);

use App\Enums\SalesPermission;
use App\Jobs\GenerateDocumentExport;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Exports\DocumentExportService;
use App\Services\Sales\SalesDocumentExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('maps every supported sales document model to its retained export type', function (string $modelClass, string $type): void {
    expect(app(SalesDocumentExportService::class)->typeForModel($modelClass))->toBe($type);
})->with([
    'orders' => [Order::class, 'orders'],
    'quotations' => [Quotation::class, 'quotations'],
    'invoices' => [Invoice::class, 'invoices'],
    'payments' => [Payment::class, 'payments'],
    'credit notes' => [CreditNote::class, 'credit_notes'],
]);

it('queues every supported sales document type through the canonical retained export workflow', function (string $type): void {
    Bus::fake();
    Permission::findOrCreate(SalesPermission::Export->value);
    $actor = User::factory()->create();
    $actor->givePermissionTo(SalesPermission::Export->value);

    $export = app(SalesDocumentExportService::class)->request(
        $type,
        [9, 7, 9, 0],
        ['filters' => ['status' => 'pending'], 'search' => 'needle', 'resource' => 'sales-list'],
        $actor,
    );

    expect($export->module)->toBe('sales')
        ->and($export->type)->toBe($type)
        ->and($export->format)->toBe('csv')
        ->and($export->status)->toBe('queued')
        ->and($export->created_by)->toBe($actor->getKey())
        ->and($export->parameters)->toMatchArray([
            'record_ids' => [9, 7],
            'filters' => ['status' => 'pending'],
            'search' => 'needle',
            'resource' => 'sales-list',
        ]);

    Bus::assertDispatched(
        GenerateDocumentExport::class,
        fn (GenerateDocumentExport $job): bool => $job->documentExportId === $export->getKey(),
    );
})->with([
    'orders',
    'quotations',
    'invoices',
    'payments',
    'credit_notes',
]);

it('snapshots sales selection and generates it asynchronously through DocumentExport', function (): void {
    Storage::fake('local');
    Bus::fake();
    Permission::findOrCreate(SalesPermission::Export->value);
    $actor = User::factory()->create();
    $actor->givePermissionTo(SalesPermission::Export->value);
    $order = Order::factory()->create();

    $export = app(SalesDocumentExportService::class)->request(
        'orders',
        [$order->getKey()],
        ['filters' => ['status' => 'pending'], 'search' => 'SO-', 'resource' => 'orders-page'],
        $actor,
    );

    expect($export->status)->toBe('queued')
        ->and($export->parameters['record_ids'])->toBe([$order->getKey()])
        ->and($export->parameters['filters'])->toBe(['status' => 'pending']);
    Bus::assertDispatched(GenerateDocumentExport::class);

    new GenerateDocumentExport($export->id)->handle(app(DocumentExportService::class));
    $export->refresh();

    expect($export->status)->toBe('completed')
        ->and($export->row_count)->toBe(1)
        ->and($export->file_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $export->file_path))->toBeTrue();
});
