<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Enums\SalesReportType;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\Returns\ReturnResource;
use Throwable;

/**
 * Turns domain-owned Sales report arrays into an explicit presentation schema.
 *
 * This intentionally avoids guessing table columns from arbitrary arrays.
 * Every report type declares its own KPIs, columns, formatting, and drill-downs.
 *
 * @phpstan-type ReportMetric array{label:string,value:string,helper?:string}
 * @phpstan-type ReportCell array{value:string,url:?string}
 * @phpstan-type ReportTable array{heading:string,columns:list<string>,rows:list<list<ReportCell>>}
 * @phpstan-type ReportPresentation array{metrics:list<ReportMetric>,tables:list<ReportTable>}
 */
final class SalesReportPresenter
{
    /**
     * @param  array<string,mixed>  $report
     * @return ReportPresentation
     */
    public function present(SalesReportType $type, array $report): array
    {
        return match ($type) {
            SalesReportType::QuotationFunnel => $this->quotationFunnel($report),
            SalesReportType::WinLossAnalysis => $this->winLoss($report),
            SalesReportType::ConversionVelocity => $this->conversionVelocity($report),
            SalesReportType::DeliveredNotInvoiced => $this->deliveredNotInvoiced($report),
            SalesReportType::InvoicedNotCollected => $this->invoicedNotCollected($report),
            SalesReportType::TaxRecognitionSummary => $this->taxRecognition($report),
            SalesReportType::DiscountAndFloorOverrides => $this->discountOverrides($report),
            SalesReportType::ReturnsWithoutCredit => $this->returnsWithoutCredit($report),
            SalesReportType::CustomerRevenue => $this->customerRevenue($report),
        };
    }

    /**
     * @param  array<string,mixed>  $report
     * @return ReportPresentation
     */
    private function quotationFunnel(array $report): array
    {
        $statusRows = [];
        foreach ($this->array($report['by_status'] ?? []) as $status => $count) {
            $statusRows[] = [
                $this->cell(__(str((string) $status)->replace('_', ' ')->headline()->toString())),
                $this->cell($this->integer($count)),
            ];
        }

        $employeeRows = [];
        foreach ($this->rows($report['by_employee'] ?? []) as $row) {
            $employeeRows[] = [
                $this->cell($this->idLabel(__('Employee'), $row['employee_id'] ?? null)),
                $this->cell($this->integer($row['count'] ?? null)),
                $this->cell($this->integer($row['accepted'] ?? null)),
            ];
        }

        $customerRows = [];
        foreach ($this->rows($report['by_customer'] ?? []) as $row) {
            $customerId = $this->nullableInt($row['customer_id'] ?? null);
            $customerRows[] = [
                $this->cell(
                    $this->idLabel(__('Customer'), $customerId),
                    $this->resourceUrl(CustomerResource::class, $customerId),
                ),
                $this->cell($this->integer($row['count'] ?? null)),
                $this->cell($this->integer($row['accepted'] ?? null)),
            ];
        }

        return [
            'metrics' => [
                $this->metric(__('Total quotations'), $this->integer($report['total'] ?? null)),
                $this->metric(__('Statuses represented'), count($statusRows)),
            ],
            'tables' => [
                $this->table(__('Quotation status'), [__('Status'), __('Quotations')], $statusRows),
                ...($employeeRows !== [] ? [$this->table(__('By employee'), [__('Employee'), __('Quotations'), __('Accepted')], $employeeRows)] : []),
                ...($customerRows !== [] ? [$this->table(__('By customer'), [__('Customer'), __('Quotations'), __('Accepted')], $customerRows)] : []),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return ReportPresentation
     */
    private function winLoss(array $report): array
    {
        $lossRows = [];
        foreach ($this->rows($report['loss_reasons'] ?? []) as $row) {
            $lossRows[] = [
                $this->cell(__(str($this->string($row['reason'] ?? null))->replace('_', ' ')->headline()->toString())),
                $this->cell($this->integer($row['count'] ?? null)),
            ];
        }

        $ownerRows = [];
        foreach ($this->rows($report['by_owner'] ?? []) as $row) {
            $ownerRows[] = [
                $this->cell($this->idLabel(__('Owner'), $row['owner_id'] ?? null)),
                $this->cell($this->integer($row['won'] ?? null)),
                $this->cell($this->integer($row['lost'] ?? null)),
                $this->cell($this->percent($row['win_rate_percent'] ?? null)),
            ];
        }

        return [
            'metrics' => [
                $this->metric(__('Closed opportunities'), $this->integer($report['total_closed'] ?? null)),
                $this->metric(__('Won'), $this->integer($report['won_count'] ?? null)),
                $this->metric(__('Lost'), $this->integer($report['lost_count'] ?? null)),
                $this->metric(__('Win rate'), $this->percent($report['win_rate_percent'] ?? null)),
            ],
            'tables' => array_values(array_filter([
                $this->table(__('Loss reasons'), [__('Reason'), __('Count')], $lossRows),
                $this->table(__('Owner performance'), [__('Owner'), __('Won'), __('Lost'), __('Win rate')], $ownerRows),
            ], static fn (array $table): bool => $table['rows'] !== [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return ReportPresentation
     */
    private function conversionVelocity(array $report): array
    {
        return [
            'metrics' => [
                $this->metric(
                    __('Median sent to decision'),
                    $this->days($report['median_days_sent_to_decided'] ?? null),
                    __(':count quotations', ['count' => $this->integer($report['sample_size_sent_to_decided'] ?? null)]),
                ),
                $this->metric(
                    __('Median accepted to order'),
                    $this->days($report['median_days_accepted_to_converted'] ?? null),
                    __(':count conversions', ['count' => $this->integer($report['sample_size_accepted_to_converted'] ?? null)]),
                ),
            ],
            'tables' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return ReportPresentation
     */
    private function deliveredNotInvoiced(array $report): array
    {
        $rows = [];
        foreach ($this->rows($report['deliveries'] ?? []) as $row) {
            $operationId = $this->nullableInt($row['inventory_operation_id'] ?? null);
            $customerId = $this->nullableInt($row['customer_id'] ?? null);

            $rows[] = [
                $this->cell($this->string($row['operation_number'] ?? null), $this->resourceUrl(InventoryOperationResource::class, $operationId)),
                $this->cell($this->string($row['customer_name'] ?? null), $this->resourceUrl(CustomerResource::class, $customerId)),
                $this->cell($this->string($row['completed_at'] ?? null)),
                $this->cell($this->integer($row['days_outstanding'] ?? null)),
            ];
        }

        return [
            'metrics' => [
                $this->metric(__('Deliveries requiring invoice'), $this->integer($report['count'] ?? null)),
                $this->metric(__('As of'), $this->string($report['as_of'] ?? null)),
            ],
            'tables' => [$this->table(
                __('Delivery exceptions'),
                [__('Delivery'), __('Customer'), __('Completed'), __('Days outstanding')],
                $rows,
            )],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return ReportPresentation
     */
    private function invoicedNotCollected(array $report): array
    {
        $rows = [];
        foreach ($this->rows($report['customers'] ?? []) as $row) {
            $customerId = $this->nullableInt($row['customer_id'] ?? null);
            $buckets = $this->array($row['buckets'] ?? []);

            $rows[] = [
                $this->cell($this->string($row['customer_name'] ?? null), $this->resourceUrl(CustomerResource::class, $customerId)),
                $this->cell($this->minor($row['outstanding_minor'] ?? null)),
                $this->cell($this->minor($buckets['current'] ?? null)),
                $this->cell($this->minor($buckets['1_30'] ?? null)),
                $this->cell($this->minor($buckets['31_60'] ?? null)),
                $this->cell($this->minor($buckets['61_90'] ?? null)),
                $this->cell($this->minor($buckets['over_90'] ?? null)),
            ];
        }

        return [
            'metrics' => [
                $this->metric(__('Outstanding'), $this->minor($report['outstanding_minor'] ?? null)),
                $this->metric(__('Receivable control'), $this->minor($report['control_account_minor'] ?? null)),
                $this->metric(__('Tie-out difference'), $this->minor($report['tie_out_difference_minor'] ?? null)),
                $this->metric(__('Reconciled'), $this->boolean($report['is_reconciled'] ?? false)),
            ],
            'tables' => [$this->table(
                __('Outstanding by customer'),
                [__('Customer'), __('Outstanding'), __('Current'), __('1–30'), __('31–60'), __('61–90'), __('Over 90')],
                $rows,
            )],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return ReportPresentation
     */
    private function taxRecognition(array $report): array
    {
        return [
            'metrics' => [
                $this->metric(__('Output tax charged / deferred'), $this->string($report['output_tax_charged_deferred'] ?? null)),
                $this->metric(__('Output tax recognized / payable'), $this->string($report['output_tax_recognised_payable'] ?? null)),
                $this->metric(__('Output tax reversed'), $this->string($report['output_tax_reversed'] ?? null)),
                $this->metric(__('Input tax recognized'), $this->string($report['input_tax_recognised'] ?? null)),
                $this->metric(__('Net position'), $this->string($report['net_position'] ?? null)),
            ],
            'tables' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return ReportPresentation
     */
    private function discountOverrides(array $report): array
    {
        $rows = [];
        foreach ($this->rows($report['overrides'] ?? []) as $row) {
            $variantId = $this->nullableInt($row['product_variant_id'] ?? null);
            $rows[] = [
                $this->cell($this->idLabel(__('Variant'), $variantId), $this->resourceUrl(ProductVariantResource::class, $variantId)),
                $this->cell($this->string($row['attempted_price'] ?? null)),
                $this->cell($this->string($row['min_price'] ?? null)),
                $this->cell($this->string($row['approved_by_name'] ?? null)),
                $this->cell($this->string($row['approved_at'] ?? null)),
                $this->cell($this->string($row['reason'] ?? null)),
            ];
        }

        return [
            'metrics' => [$this->metric(__('Approved overrides'), $this->integer($report['count'] ?? null))],
            'tables' => [$this->table(
                __('Price floor override audit'),
                [__('Variant'), __('Attempted price'), __('Minimum price'), __('Approved by'), __('Approved at'), __('Reason')],
                $rows,
            )],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return ReportPresentation
     */
    private function returnsWithoutCredit(array $report): array
    {
        $rows = [];
        foreach ($this->rows($report['returns'] ?? []) as $row) {
            $returnId = $this->nullableInt($row['inventory_return_id'] ?? null);
            $customerId = $this->nullableInt($row['customer_id'] ?? null);

            $rows[] = [
                $this->cell($this->string($row['return_number'] ?? null), $this->resourceUrl(ReturnResource::class, $returnId)),
                $this->cell($this->string($row['customer_name'] ?? null), $this->resourceUrl(CustomerResource::class, $customerId)),
                $this->cell($this->string($row['posted_at'] ?? null)),
                $this->cell($this->integer($row['days_outstanding'] ?? null)),
            ];
        }

        return [
            'metrics' => [
                $this->metric(__('Returns requiring credit'), $this->integer($report['count'] ?? null)),
                $this->metric(__('As of'), $this->string($report['as_of'] ?? null)),
            ],
            'tables' => [$this->table(
                __('Return exceptions'),
                [__('Return'), __('Customer'), __('Posted'), __('Days outstanding')],
                $rows,
            )],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return ReportPresentation
     */
    private function customerRevenue(array $report): array
    {
        $rows = [];
        foreach ($this->rows($report['customers'] ?? []) as $row) {
            $customerId = $this->nullableInt($row['customer_id'] ?? null);
            $rows[] = [
                $this->cell($this->string($row['customer_name'] ?? null), $this->resourceUrl(CustomerResource::class, $customerId)),
                $this->cell($this->minor($row['invoiced_minor'] ?? null)),
                $this->cell($this->minor($row['collected_minor'] ?? null)),
            ];
        }

        return [
            'metrics' => [
                $this->metric(__('Customers'), count($rows)),
                $this->metric(__('As of'), $this->string($report['as_of'] ?? null)),
            ],
            'tables' => [$this->table(
                __('Revenue by customer'),
                [__('Customer'), __('Invoiced'), __('Collected')],
                $rows,
            )],
        ];
    }

    /** @return ReportMetric */
    private function metric(string $label, string|int|float $value, ?string $helper = null): array
    {
        $metric = ['label' => $label, 'value' => is_string($value) ? $value : (string) $value];

        if (is_string($helper) && $helper !== '') {
            $metric['helper'] = $helper;
        }

        return $metric;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<ReportCell>>  $rows
     * @return ReportTable
     */
    private function table(string $heading, array $columns, array $rows): array
    {
        return ['heading' => $heading, 'columns' => $columns, 'rows' => $rows];
    }

    /** @return ReportCell */
    private function cell(mixed $value, ?string $url = null): array
    {
        return ['value' => $this->display($value), 'url' => $url];
    }

    private function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        return is_scalar($value) ? (string) $value : '—';
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function percent(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 1).'%' : '—';
    }

    private function days(mixed $value): string
    {
        return is_numeric($value) ? __(':days days', ['days' => number_format((float) $value, 1)]) : '—';
    }

    private function minor(mixed $value): string
    {
        return is_numeric($value) ? number_format(((int) $value) / 100, 2) : '—';
    }

    private function boolean(mixed $value): string
    {
        return (bool) $value ? __('Yes') : __('No');
    }

    private function idLabel(string $label, mixed $id): string
    {
        return is_numeric($id) ? $label.' #'.(int) $id : '—';
    }

    /** @return array<array-key,mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = [];

            foreach ($row as $key => $item) {
                if (is_string($key)) {
                    $normalized[$key] = $item;
                }
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    /** @param class-string<\Filament\Resources\Resource> $resource */
    private function resourceUrl(string $resource, ?int $recordId): ?string
    {
        if ($recordId === null) {
            return null;
        }

        try {
            if (! $resource::canAccess()) {
                return null;
            }

            return $resource::getUrl('view', ['record' => $recordId]);
        } catch (Throwable) {
            return null;
        }
    }
}
