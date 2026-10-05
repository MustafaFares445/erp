<?php

declare(strict_types=1);

use App\Enums\PurchasePermission;
use App\Filament\Resources\PurchasingReports\Pages\ListPurchasingReports;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverage61User(): User
{
    (new PurchasePermissionSeeder)->run();

    $user = User::factory()->create();
    $user->givePermissionTo(PurchasePermission::ReportView->value);

    return $user;
}

it('covers every purchasing report view branch', function (): void {
    $this->actingAs(coverage61User());

    foreach ([
        'open_commitments',
        'receiving_performance',
        'cost_variance',
        'duplicate_reference_attempts',
    ] as $reportType) {
        $page = app(ListPurchasingReports::class);
        $page->reportType = $reportType;
        $data = $page->getViewData();

        expect($data['reportKey'])->toBe($reportType)
            ->and($data)->toHaveKeys(['reportLabel', 'reportDescription', 'rows', 'summary']);
    }
});

it('covers purchasing report summary branches with non-empty rows', function (): void {
    $page = app(ListPurchasingReports::class);
    $summary = new ReflectionMethod(ListPurchasingReports::class, 'summaryFor');

    $open = $summary->invoke($page, 'open_commitments', [
        ['orders' => '2'],
        ['orders' => 3],
    ]);
    expect($open[0]['value'])->toBe(2)
        ->and($open[1]['value'])->toBe(5);

    $receiving = $summary->invoke($page, 'receiving_performance', [
        ['promised' => '4', 'on_time' => 3],
        ['promised' => 2, 'on_time' => '1'],
    ]);
    expect($receiving[0]['value'])->toBe(2)
        ->and($receiving[1]['value'])->toBe(6)
        ->and($receiving[2]['value'])->toBe(4);

    $cost = $summary->invoke($page, 'cost_variance', [
        ['supplier' => 'Supplier A'],
        ['supplier' => 'Supplier A'],
        ['supplier' => 'Supplier B'],
        ['supplier' => null],
    ]);
    expect($cost[0]['value'])->toBe(4)
        ->and($cost[1]['value'])->toBe(2);

    $duplicate = $summary->invoke($page, 'duplicate_reference_attempts', [
        ['supplier' => 'Supplier A'],
        ['supplier' => 'Supplier C'],
        ['supplier' => 'Supplier C'],
    ]);
    expect($duplicate[0]['value'])->toBe(3)
        ->and($duplicate[1]['value'])->toBe(2);
});

it('covers every purchasing CSV export shape and scalar conversion branch', function (): void {
    $page = app(ListPurchasingReports::class);
    $shape = new ReflectionMethod(ListPurchasingReports::class, 'exportShape');

    [$receivingHeadings, $receivingValues] = $shape->invoke($page, 'receiving_performance');
    expect($receivingHeadings)->toBe(['supplier', 'promised', 'on_time', 'on_time_rate_percent'])
        ->and($receivingValues([
            'supplier' => 'Supplier A',
            'promised' => '5',
            'on_time' => 4,
            'on_time_rate' => '80.5',
        ]))->toBe(['Supplier A', 5, 4, 80.5]);

    [$costHeadings, $costValues] = $shape->invoke($page, 'cost_variance');
    expect($costHeadings)->toHaveCount(7)
        ->and($costValues([
            'purchase_order_number' => 'PO-1',
            'supplier' => 'Supplier B',
            'currency_code' => 'AED',
            'variant' => 'SKU-1',
            'ordered_cost' => '10.25',
            'received_cost' => 11.5,
            'variance' => '1.25',
        ]))->toBe(['PO-1', 'Supplier B', 'AED', 'SKU-1', 10.25, 11.5, 1.25]);

    [$duplicateHeadings, $duplicateValues] = $shape->invoke($page, 'duplicate_reference_attempts');
    expect($duplicateHeadings)->toHaveCount(5)
        ->and($duplicateValues([
            'attempted_at' => '2026-10-05',
            'supplier' => 'Supplier C',
            'supplier_reference' => 'REF-1',
            'attempted_by' => 'Admin',
            'message' => 'Duplicate',
        ]))->toBe(['2026-10-05', 'Supplier C', 'REF-1', 'Admin', 'Duplicate']);

    [$openHeadings, $openValues] = $shape->invoke($page, 'open_commitments');
    expect($openHeadings)->toHaveCount(6)
        ->and($openValues([
            'supplier' => 'Supplier D',
            'currency_code' => 'AED',
            'orders' => '2',
            'ordered_value' => '15.50',
            'received_value' => 5,
            'outstanding_value' => '10.50',
        ]))->toBe(['Supplier D', 'AED', 2, 15.5, 5.0, 10.5]);

    $intValue = new ReflectionMethod(ListPurchasingReports::class, 'intValue');
    $floatValue = new ReflectionMethod(ListPurchasingReports::class, 'floatValue');
    $stringValue = new ReflectionMethod(ListPurchasingReports::class, 'stringValue');

    expect($intValue->invoke(null, 'not-numeric'))->toBe(0)
        ->and($floatValue->invoke(null, []))->toBe(0.0)
        ->and($stringValue->invoke(null, []))->toBe('');
});
