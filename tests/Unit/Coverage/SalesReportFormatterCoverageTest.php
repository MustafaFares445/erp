<?php

declare(strict_types=1);

use App\Enums\SalesReportType;
use App\Services\Sales\SalesReportFormatter;

it('renders every sales report type with populated and malformed rows', function (): void {
    $formatter = app(SalesReportFormatter::class);
    $reports = [
        SalesReportType::QuotationFunnel->value => [
            'total' => '3',
            'by_status' => ['draft' => 1.9, 2 => '2'],
        ],
        SalesReportType::WinLossAnalysis->value => [
            'total_closed' => 4.8,
            'won_count' => '3',
            'lost_count' => 1,
            'win_rate_percent' => true,
            'loss_reasons' => ['skip', ['reason' => 123, 'count' => '1']],
        ],
        SalesReportType::ConversionVelocity->value => [
            'median_days_sent_to_decided' => 2.5,
            'median_days_accepted_to_converted' => false,
        ],
    ];
    $reports += [
        SalesReportType::DeliveredNotInvoiced->value => [
            'as_of' => '2026-09-17',
            'deliveries' => ['skip', [
                'operation_number' => 'OP-1', 'customer_name' => 'Acme',
                'completed_at' => null, 'days_outstanding' => '2',
            ]],
        ],
        SalesReportType::InvoicedNotCollected->value => [
            'as_of' => 20260917,
            'customers' => ['skip', ['customer_name' => 'Acme', 'outstanding_minor' => 123.9]],
        ],
        SalesReportType::TaxRecognitionSummary->value => [
            'count' => 3, 'amount' => 12.5, 'active' => true,
            'nested' => ['x' => 1], 'bad' => new stdClass,
        ],
        SalesReportType::DiscountAndFloorOverrides->value => [
            'overrides' => ['skip', [
                'product_variant_id' => '4', 'attempted_price' => 9.5,
                'min_price' => '10.00', 'approved_by_name' => null,
                'approved_at' => '2026-09-17 10:00:00', 'reason' => false,
            ]],
        ],
    ];
    $reports += [
        SalesReportType::ReturnsWithoutCredit->value => [
            'as_of' => '2026-09-17',
            'returns' => ['skip', [
                'return_number' => 'RET-1', 'customer_name' => 22,
                'posted_at' => null, 'days_outstanding' => 5.8,
            ]],
        ],
        SalesReportType::CustomerRevenue->value => [
            'as_of' => '2026-09-17',
            'customers' => ['skip', [
                'customer_name' => 'Acme', 'invoiced_minor' => '5000',
                'collected_minor' => 2500.9,
            ]],
        ],
    ];

    foreach (SalesReportType::cases() as $type) {
        $csv = $formatter->toCsv($type, $reports[$type->value]);
        expect($csv)->toBeString()->not->toBeEmpty();
    }
});

it('renders empty and unexpected report shapes without failing', function (): void {
    $formatter = app(SalesReportFormatter::class);
    foreach (SalesReportType::cases() as $type) {
        expect($formatter->toCsv($type, ['customers' => 'bad', 'deliveries' => 'bad', 'returns' => 'bad']))
            ->toBeString();
    }
});
