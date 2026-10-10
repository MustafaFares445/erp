<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryDiagnosis;
use App\Enums\InventoryCountStatus;
use App\Enums\InventoryDiagnosticSeverity;
use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryCounts\Pages\ViewInventoryCount;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\User;
use App\Services\Inventory\InventoryViewDiagnosticsService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function countDiagManager(): User
{
    $user = User::factory()->admin()->create();
    $user->givePermissionTo(array_map(static fn (InventoryPermission $permission): string => $permission->value, InventoryPermission::cases()));

    return $user;
}

function countDiagnose(InventoryCount $count): InventoryDiagnosis
{
    return app(InventoryViewDiagnosticsService::class)->diagnose($count->fresh() ?? $count);
}

function countDiagLine(InventoryCount $count, ?float $counted, array $attributes = []): InventoryCountLine
{
    return InventoryCountLine::factory()->create([
        'inventory_count_id' => $count->getKey(),
        'system_base_quantity' => 10,
        'counted_base_quantity' => $counted,
        'variance_base_quantity' => $counted === null ? null : $counted - 10,
        'variance_value_minor' => $counted === null ? null : (int) (($counted - 10) * 100),
        ...$attributes,
    ]);
}

it('lists uncounted lines of a count in progress', function (): void {
    $this->actingAs(countDiagManager());
    $count = InventoryCount::factory()->counting()->create();
    countDiagLine($count, 10);
    countDiagLine($count, null);

    $finding = countDiagnose($count)->find('count.uncounted');

    expect($finding?->severity)->toBe(InventoryDiagnosticSeverity::Notice)
        ->and($finding?->action?->allowed)->toBeTrue();
});

it('guides a fully counted count to review', function (): void {
    $this->actingAs(countDiagManager());
    $count = InventoryCount::factory()->counting()->create();
    countDiagLine($count, 9);

    expect(countDiagnose($count)->codes())->toBe(['count.ready_for_review']);
});

it('warns about lines flagged for recount and differences above the threshold', function (): void {
    $this->actingAs(countDiagManager());
    $count = InventoryCount::factory()->counting()->create(['materiality_threshold_minor' => 50]);
    countDiagLine($count, 4, ['recount_requested' => true]);
    countDiagLine($count, 2);

    $diagnosis = countDiagnose($count);

    expect($diagnosis->find('count.recount_requested')?->severity)->toBe(InventoryDiagnosticSeverity::Warning)
        ->and($diagnosis->find('count.material_variances')?->severity)->toBe(InventoryDiagnosticSeverity::Notice);
});

it('blocks confirmation while lines are flagged for recount', function (): void {
    $this->actingAs(countDiagManager());
    $count = InventoryCount::factory()->pendingReview()->create();
    countDiagLine($count, 7, ['recount_requested' => true]);

    expect(countDiagnose($count)->find('count.recount_blocks_confirm')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker);
});

it('tells the counter that a different user must confirm and guides the reviewer', function (): void {
    $counter = countDiagManager();
    $reviewer = countDiagManager();
    $count = InventoryCount::factory()->pendingReview()->create(['counted_by' => $counter->getKey()]);
    countDiagLine($count, 8, ['variance_value_minor' => null]);

    $this->actingAs($counter);
    $forCounter = countDiagnose($count);

    $this->actingAs($reviewer);
    $forReviewer = countDiagnose($count);

    expect($forCounter->find('count.self_confirmation')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($forCounter->find('count.self_confirmation')?->action?->allowed)->toBeFalse()
        ->and($forCounter->has('count.awaiting_confirmation'))->toBeFalse()
        ->and($forReviewer->find('count.awaiting_confirmation')?->action?->allowed)->toBeTrue()
        ->and($forReviewer->find('count.unvalued_variances')?->severity)->toBe(InventoryDiagnosticSeverity::Warning);
});

it('flags a confirmed count with differences but no adjustment', function (): void {
    $this->actingAs(countDiagManager());
    $count = InventoryCount::factory()->create(['status' => InventoryCountStatus::Confirmed, 'confirmed_at' => now()]);
    countDiagLine($count, 6);

    expect(countDiagnose($count)->find('count.confirmed_without_adjustment')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker);
});

it('renders the panel on the view page without changing the count', function (): void {
    $user = countDiagManager();
    $count = InventoryCount::factory()->pendingReview()->create(['counted_by' => $user->getKey()]);
    countDiagLine($count, 8);
    $before = $count->fresh()?->updated_at?->toDateTimeString();

    Livewire::actingAs($user)
        ->test(ViewInventoryCount::class, ['record' => $count->getKey()])
        ->assertOk()
        ->assertSee(__('inventory_view_ux.title'))
        ->assertSee(__('inventory_view_ux.rules.count.self_confirmation.title'));

    expect($count->fresh()?->status)->toBe(InventoryCountStatus::PendingReview)
        ->and($count->fresh()?->updated_at?->toDateTimeString())->toBe($before);
});
