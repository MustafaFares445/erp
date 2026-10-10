<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryDiagnosticCategory;
use App\Enums\InventoryDiagnosticSeverity;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryViewDiagnosticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function diagnosticFinding(string $code, InventoryDiagnosticSeverity $severity): InventoryDiagnosticFinding
{
    return new InventoryDiagnosticFinding($code, InventoryDiagnosticCategory::Workflow, $severity, $code, $code);
}

it('dispatches a record to the diagnoser that owns its type', function (): void {
    $this->actingAs(User::factory()->create());

    $diagnosis = app(InventoryViewDiagnosticsService::class)->diagnose(Warehouse::factory()->inactive()->create());

    expect($diagnosis->codes())->toContain('warehouse.inactive');
});

it('states that no automated checks exist for an unsupported record', function (): void {
    $diagnosis = app(InventoryViewDiagnosticsService::class)->diagnose(User::factory()->create());

    expect($diagnosis->findings)->toBe([])
        ->and($diagnosis->checks)->toBe([])
        ->and($diagnosis->limits)->toBe([__('inventory_view_ux.limits.no_rules')]);
});

it('orders findings by severity and summarises blockers first', function (): void {
    $diagnosis = new InventoryDiagnosis([
        diagnosticFinding('a', InventoryDiagnosticSeverity::Notice),
        diagnosticFinding('b', InventoryDiagnosticSeverity::Blocker),
        diagnosticFinding('c', InventoryDiagnosticSeverity::Warning),
    ]);

    expect($diagnosis->codes())->toBe(['b', 'c', 'a'])
        ->and($diagnosis->severity())->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($diagnosis->summary())->toBe(trans_choice('inventory_view_ux.summary.blockers', 1, ['count' => 1]));
});

it('never presents an empty diagnosis as more than the listed checks', function (): void {
    $diagnosis = new InventoryDiagnosis([], checks: ['one', 'two']);

    expect($diagnosis->severity())->toBe(InventoryDiagnosticSeverity::Clear)
        ->and($diagnosis->summary())->toBe(__('inventory_view_ux.summary.clear', ['checks' => 2]))
        ->and($diagnosis->summary())->toContain('2');
});

it('substitutes quantities into every rule text instead of leaving placeholders', function (string $locale): void {
    app()->setLocale($locale);

    $texts = array_merge(...array_map(
        static fn (array $area): array => array_merge(...array_map(static fn (array $rule): array => array_values($rule), array_values($area))),
        array_values(trans('inventory_view_ux.rules')),
    ));

    foreach ($texts as $text) {
        expect($text)->not->toMatch('/\{[a-z_]+\}/');
    }
})->with(['en', 'ar']);

it('keeps the English and Arabic diagnostic vocabularies in sync', function (): void {
    $flatten = static function (array $items, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($items as $key => $value) {
            $keys = is_array($value)
                ? [...$keys, ...$flatten($value, $prefix.$key.'.')]
                : [...$keys, $prefix.$key];
        }

        return $keys;
    };

    $english = $flatten(require lang_path('en/inventory_view_ux.php'));
    $arabic = $flatten(require lang_path('ar/inventory_view_ux.php'));
    sort($english);
    sort($arabic);

    expect($arabic)->toBe($english);
});
