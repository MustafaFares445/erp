<?php

declare(strict_types=1);

use App\Jobs\GenerateDocumentExport;
use App\Models\DocumentExport;
use App\Models\User;
use App\Services\Exports\DocumentExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('retains export ownership parameters and configured expiry while dispatching the canonical job', function (): void {
    Bus::fake();
    config()->set('document_exports.retention_days', 14);
    $actor = User::factory()->create();
    $expectedExpiry = now()->addDays(14);

    $export = app(DocumentExportService::class)->request(
        module: 'sales',
        type: 'orders',
        format: 'csv',
        parameters: ['record_ids' => [7, 9], 'filters' => ['status' => 'open']],
        actor: $actor,
    );

    expect($export->status)->toBe('queued')
        ->and($export->created_by)->toBe($actor->getKey())
        ->and($export->parameters)->toBe(['record_ids' => [7, 9], 'filters' => ['status' => 'open']])
        ->and($export->filters)->toBe(['status' => 'open'])
        ->and($export->row_count)->toBe(0)
        ->and($export->expires_at)->not->toBeNull()
        ->and($export->expires_at?->diffInSeconds($expectedExpiry))->toBeLessThan(5.0);

    Bus::assertDispatched(
        GenerateDocumentExport::class,
        fn (GenerateDocumentExport $job): bool => $job->documentExportId === $export->getKey(),
    );
});

it('serializes duplicate generation jobs for the same retained export', function (): void {
    $middleware = (new GenerateDocumentExport(42))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('denies retained export downloads to a different requester', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Storage::disk('local')->put('sales-exports/document-1.csv', "id\n1\n");

    $export = DocumentExport::query()->create([
        'module' => 'sales',
        'type' => 'orders',
        'format' => 'csv',
        'parameters' => ['record_ids' => [1]],
        'file_path' => 'sales-exports/document-1.csv',
        'status' => 'completed',
        'created_by' => $owner->getKey(),
        'completed_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    expect(fn () => app(DocumentExportService::class)->download($export, $other))
        ->toThrow(DomainException::class);
});

it('cleans expired retained files without deleting the audit record', function (): void {
    Storage::fake('local');
    $actor = User::factory()->create();
    Storage::disk('local')->put('document-exports/expired.csv', "id\n1\n");

    $export = DocumentExport::query()->create([
        'module' => 'sales',
        'type' => 'orders',
        'format' => 'csv',
        'parameters' => ['record_ids' => [1]],
        'file_path' => 'document-exports/expired.csv',
        'status' => 'completed',
        'created_by' => $actor->getKey(),
        'completed_at' => now()->subDays(8),
        'expires_at' => now()->subMinute(),
    ]);

    expect(app(DocumentExportService::class)->cleanupExpired())->toBe(1);

    $export->refresh();
    expect($export->status)->toBe('expired')
        ->and($export->file_path)->toBeNull()
        ->and(Storage::disk('local')->exists('document-exports/expired.csv'))->toBeFalse();
});
