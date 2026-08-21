<?php

declare(strict_types=1);

use App\Enums\InventoryImportRunStatus;
use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Filament\Resources\InventoryImportRuns\Pages\ManageInventoryImportRuns;
use App\Jobs\ApplyCatalogImport;
use App\Jobs\ParseCatalogImport;
use App\Models\InventoryImportItem;
use App\Models\InventoryImportRun;
use App\Models\User;
use App\Services\Inventory\CatalogImportService;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    (new InventoryPermissionSeeder)->run();
});

it('allows an import manager to queue a ready-with-errors run', function (): void {
    $manager = importPanelManager();
    $run = InventoryImportRun::factory()->create([
        'status' => InventoryImportRunStatus::ReadyWithErrors,
        'total_rows' => 2,
        'valid_rows' => 1,
        'failed_rows' => 1,
    ]);
    InventoryImportItem::factory()->for($run, 'run')->create();

    Livewire::actingAs($manager)
        ->test(ManageInventoryImportRuns::class)
        ->assertActionVisible(TestAction::make('confirm')->table($run))
        ->callAction(TestAction::make('confirm')->table($run))
        ->assertHasNoActionErrors();

    expect($run->fresh()->status)->toBe(InventoryImportRunStatus::Applying)
        ->and($run->fresh()->confirmed_by)->toBe($manager->getKey());

    Queue::assertPushed(
        ApplyCatalogImport::class,
        fn (ApplyCatalogImport $job): bool => $job->importRunId === $run->getKey()
            && $job->actorId === $manager->getKey(),
    );
});

it('shows private result downloads only when files are available', function (): void {
    $manager = importPanelManager();
    $run = InventoryImportRun::factory()->create([
        'status' => InventoryImportRunStatus::ConfirmedWithErrors,
        'result_path' => null,
        'summary_path' => null,
    ]);
    $component = Livewire::actingAs($manager)->test(ManageInventoryImportRuns::class);

    $component
        ->assertActionHidden(TestAction::make('download_rows')->table($run))
        ->assertActionHidden(TestAction::make('download_summary')->table($run));

    Storage::disk('local')->put('catalog-imports/results/rows.csv', 'rows');
    Storage::disk('local')->put('catalog-imports/results/summary.csv', 'summary');
    $run->forceFill([
        'result_path' => 'catalog-imports/results/rows.csv',
        'summary_path' => 'catalog-imports/results/summary.csv',
    ])->save();

    Livewire::actingAs($manager)
        ->test(ManageInventoryImportRuns::class)
        ->assertActionVisible(TestAction::make('download_rows')->table($run))
        ->assertActionVisible(TestAction::make('download_summary')->table($run));
});

it('denies the import resource without import management permission', function (): void {
    $administrator = User::factory()->admin()->create();

    $this->actingAs($administrator)
        ->get(InventoryImportRunResource::getUrl('index'))
        ->assertForbidden();
});

it('downloads templates uploads stored workbooks and guards import resource helpers', function (): void {
    $manager = importPanelManager();
    $sourcePath = 'catalog-imports/source.xlsx';
    app(CatalogImportService::class)
        ->writeTemplate(Storage::disk('local')->path($sourcePath));

    $component = Livewire::actingAs($manager)
        ->test(ManageInventoryImportRuns::class)
        ->callAction('download_template')
        ->assertHasNoActionErrors();
    $uploadAction = $component->instance()->getAction('upload');

    if ($uploadAction === null) {
        throw new RuntimeException('Missing catalog upload action.');
    }

    $uploadAction->call(['data' => ['file_path' => $sourcePath]]);

    Queue::assertPushed(ParseCatalogImport::class);

    $run = InventoryImportRun::factory()->create();
    $downloadPath = new ReflectionMethod(InventoryImportRunResource::class, 'downloadPath');
    $recordId = new ReflectionMethod(InventoryImportRunResource::class, 'recordId');
    $actor = new ReflectionMethod(ManageInventoryImportRuns::class, 'actor');
    $storedPath = new ReflectionMethod(ManageInventoryImportRuns::class, 'storedPath');

    expect(InventoryImportRunResource::form(Schema::make())->getComponents())->not->toBeEmpty()
        ->and($downloadPath->invoke(null, 'catalog-imports/results/report.csv'))->toBe('catalog-imports/results/report.csv')
        ->and($recordId->invoke(null, $run))->toBe($run->getKey())
        ->and(fn (): mixed => $downloadPath->invoke(null, null))->toThrow(LogicException::class)
        ->and(fn (): mixed => $recordId->invoke(null, new InventoryImportRun))->toThrow(LogicException::class)
        ->and(fn (): mixed => $storedPath->invoke(null, []))->toThrow(LogicException::class);

    auth()->logout();

    expect(fn (): mixed => $actor->invoke(null))->toThrow(LogicException::class);
});

function importPanelManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo(InventoryPermission::ImportManage->value);

    return $manager;
}
