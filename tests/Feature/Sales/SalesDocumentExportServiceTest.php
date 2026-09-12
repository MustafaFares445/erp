<?php

declare(strict_types=1);

use App\Enums\SalesPermission;
use App\Jobs\GenerateDocumentExport;
use App\Models\Order;
use App\Models\User;
use App\Services\Exports\DocumentExportService;
use App\Services\Sales\SalesDocumentExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

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
