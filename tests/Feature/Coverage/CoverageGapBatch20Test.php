<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Filament\Resources\CreditNotes\Actions\CreditNoteActions;
use App\Filament\Resources\InventoryReports\Pages\ManageInventoryReports;
use App\Filament\Resources\SerializedInventoryUnits\Pages\ViewSerializedInventoryUnit;
use App\Models\CreditNote;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Infolists\Components\Entry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('covers reconciliation diagnostic formatting for empty and mixed detail arrays', function (): void {
    (new InventoryPermissionSeeder)->run();

    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo([
        InventoryPermission::ReportView->value,
        InventoryPermission::StockView->value,
    ]);

    $component = Livewire::actingAs($viewer)
        ->test(ManageInventoryReports::class)
        ->set('activeTab', InventoryReportType::Reconciliation->value);

    $column = $component->instance()->getTable()->getColumn('detail');

    expect($column)->toBeInstanceOf(TextColumn::class)
        ->and($column->formatState([]))->toBe('—')
        ->and($column->formatState(['plain', ['nested' => 1]]))->toBe("plain\n{\"nested\":1}");
});

it('covers serialized-unit service history infolist mapping', function (): void {
    (new InventoryPermissionSeeder)->run();

    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::StockView->value);

    $unit = SerializedInventoryUnit::factory()->create();
    $record = MaintenanceRecord::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    $component = Livewire::actingAs($viewer)
        ->test(ViewSerializedInventoryUnit::class, ['record' => $unit->getRouteKey()]);

    $schema = $component->instance()->getSchema('infolist');
    $entry = collect($schema?->getFlatComponents() ?? [])
        ->first(static fn (mixed $component): bool => $component instanceof Entry && $component->getName() === 'serviceHistory');

    expect($entry)->toBeInstanceOf(Entry::class);

    $state = $entry->getState();

    expect($state)->toBeArray()->toHaveCount(1)
        ->and($state[0]['id'])->toBe($record->getKey())
        ->and($state[0])->toHaveKeys([
            'status',
            'billing_type',
            'total_cost_minor',
            'coverage_percent',
            'created_at',
        ]);
});

it('covers unauthenticated credit-note action early returns', function (): void {
    auth()->logout();

    $record = new CreditNote;

    CreditNoteActions::confirm()->getActionFunction()($record);
    CreditNoteActions::reverse()->getActionFunction()($record);
    CreditNoteActions::generatePdf()->getActionFunction()($record);

    expect(true)->toBeTrue();
});
