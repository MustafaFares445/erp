<?php

declare(strict_types=1);

use App\Enums\SalesReportType;
use App\Reporting\SalesReportPresenter;
use Filament\Resources\Resource;

final class Coverage63ThrowingResource extends Resource
{
    public static function canAccess(): bool
    {
        throw new RuntimeException('coverage');
    }
}

it('covers win-loss discount-override and return-exception presentation rows', function (): void {
    $presenter = new SalesReportPresenter;

    $winLoss = $presenter->present(SalesReportType::WinLossAnalysis, [
        'total_closed' => 5,
        'won_count' => 3,
        'lost_count' => 2,
        'win_rate_percent' => 60,
        'loss_reasons' => [
            ['reason' => 'price_too_high', 'count' => 2],
        ],
        'by_owner' => [
            ['owner_id' => 7, 'won' => 3, 'lost' => 1, 'win_rate_percent' => 75],
        ],
    ]);

    expect($winLoss['tables'])->toHaveCount(2)
        ->and($winLoss['tables'][0]['rows'])->toHaveCount(1)
        ->and($winLoss['tables'][1]['rows'])->toHaveCount(1);

    $overrides = $presenter->present(SalesReportType::DiscountAndFloorOverrides, [
        'count' => 1,
        'overrides' => [[
            'product_variant_id' => null,
            'attempted_price' => '90.00',
            'min_price' => '100.00',
            'approved_by_name' => 'Manager',
            'approved_at' => '2026-10-05',
            'reason' => 'Strategic customer',
        ]],
    ]);

    expect($overrides['tables'][0]['rows'])->toHaveCount(1)
        ->and($overrides['tables'][0]['rows'][0][0]['value'])->toBe('—');

    $returns = $presenter->present(SalesReportType::ReturnsWithoutCredit, [
        'count' => 1,
        'as_of' => '2026-10-05',
        'returns' => [[
            'inventory_return_id' => null,
            'customer_id' => null,
            'return_number' => 'RET-1',
            'customer_name' => 'Coverage customer',
            'posted_at' => '2026-10-01',
            'days_outstanding' => 4,
        ]],
    ]);

    expect($returns['tables'][0]['rows'])->toHaveCount(1)
        ->and($returns['tables'][0]['rows'][0][0]['value'])->toBe('RET-1')
        ->and($returns['tables'][0]['rows'][0][1]['value'])->toBe('Coverage customer');
});

it('covers presenter display and row-normalization defensive branches', function (): void {
    $presenter = new SalesReportPresenter;
    $display = new ReflectionMethod(SalesReportPresenter::class, 'display');
    $rows = new ReflectionMethod(SalesReportPresenter::class, 'rows');

    expect($display->invoke($presenter, null))->toBe('—')
        ->and($display->invoke($presenter, ''))->toBe('—')
        ->and($display->invoke($presenter, true))->toBe(__('Yes'))
        ->and($display->invoke($presenter, false))->toBe(__('No'))
        ->and($display->invoke($presenter, ['not scalar']))->toBe('—')
        ->and($rows->invoke($presenter, 'not-an-array'))->toBe([])
        ->and($rows->invoke($presenter, [
            'skip-me',
            ['valid' => 1, 0 => 'drop-numeric-key'],
        ]))->toBe([['valid' => 1]]);
});

it('covers sales report resource-url null and exception branches', function (): void {
    $presenter = new SalesReportPresenter;
    $resourceUrl = new ReflectionMethod(SalesReportPresenter::class, 'resourceUrl');

    expect($resourceUrl->invoke($presenter, Coverage63ThrowingResource::class, null))->toBeNull()
        ->and($resourceUrl->invoke($presenter, Coverage63ThrowingResource::class, 1))->toBeNull();
});
