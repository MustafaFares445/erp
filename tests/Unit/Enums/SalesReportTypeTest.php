<?php

declare(strict_types=1);

use App\Enums\SalesPermission;
use App\Enums\SalesReportType;

it('maps every sales report type to the report-view permission and a label', function (SalesReportType $type): void {
    expect($type->sourcePermission())->toBe(SalesPermission::ReportView)
        ->and($type->label())->not->toBeEmpty();
})->with(SalesReportType::cases());

it('names the two leak-point reports SL-15 requires to be first-class', function (): void {
    $values = array_map(fn (SalesReportType $type): string => $type->value, SalesReportType::cases());

    expect($values)->toContain('delivered_not_invoiced')
        ->toContain('invoiced_not_collected');
});
