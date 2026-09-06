<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SalesReportType;
use App\Services\Inventory\InventoryReportFormatter;
use LogicException;

/**
 * CSV rendering for {@see SalesReportService}, mirroring
 * {@see InventoryReportFormatter}'s convention.
 *
 * {@see SalesReportService}'s reports are plain associative arrays (not typed models), so every
 * cell value read from one is `mixed` as far as static analysis is concerned. {@see self::cellInt()},
 * {@see self::cellString()} and {@see self::cellStringOrNull()} narrow each cell with a real runtime
 * type check before it reaches a CSV row, rather than casting a `mixed` value unchecked.
 */
final readonly class SalesReportFormatter
{
    /** @param array<string, mixed> $report */
    public function toCsv(SalesReportType $type, array $report): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new LogicException('The sales report export stream could not be opened.');
        }

        foreach ($this->rows($type, $report) as $row) {
            fputcsv($stream, $row, escape: '\\');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return is_string($csv) ? $csv : '';
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<bool|float|int|string|null>>
     */
    private function rows(SalesReportType $type, array $report): array
    {
        return match ($type) {
            SalesReportType::QuotationFunnel => $this->quotationFunnelRows($report),
            SalesReportType::WinLossAnalysis => $this->winLossRows($report),
            SalesReportType::ConversionVelocity => $this->conversionVelocityRows($report),
            SalesReportType::DeliveredNotInvoiced => $this->deliveredNotInvoicedRows($report),
            SalesReportType::InvoicedNotCollected => $this->invoicedNotCollectedRows($report),
            SalesReportType::TaxRecognitionSummary => $this->taxRecognitionSummaryRows($report),
            SalesReportType::DiscountAndFloorOverrides => $this->discountAndFloorOverridesRows($report),
            SalesReportType::ReturnsWithoutCredit => $this->returnsWithoutCreditRows($report),
            SalesReportType::CustomerRevenue => $this->customerRevenueRows($report),
        };
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<bool|float|int|string|null>>
     */
    private function quotationFunnelRows(array $report): array
    {
        $rows = [['Total', self::cellInt($report['total'] ?? null)]];

        $byStatus = $report['by_status'] ?? [];
        if (is_array($byStatus)) {
            foreach ($byStatus as $status => $count) {
                $rows[] = ['Status: '.self::cellString($status), self::cellInt($count)];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<bool|float|int|string|null>>
     */
    private function winLossRows(array $report): array
    {
        $rows = [
            ['Total closed', self::cellInt($report['total_closed'] ?? null)],
            ['Won', self::cellInt($report['won_count'] ?? null)],
            ['Lost', self::cellInt($report['lost_count'] ?? null)],
            ['Win rate %', self::cellString($report['win_rate_percent'] ?? null)],
        ];

        $lossReasons = $report['loss_reasons'] ?? [];
        if (is_array($lossReasons)) {
            foreach ($lossReasons as $reason) {
                if (! is_array($reason)) {
                    continue;
                }
                $rows[] = ['Loss reason: '.self::cellString($reason['reason'] ?? null), self::cellInt($reason['count'] ?? null)];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<bool|float|int|string|null>>
     */
    private function conversionVelocityRows(array $report): array
    {
        return [
            ['Median days sent to decided', self::cellString($report['median_days_sent_to_decided'] ?? null)],
            ['Median days accepted to converted', self::cellString($report['median_days_accepted_to_converted'] ?? null)],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<bool|float|int|string|null>>
     */
    private function deliveredNotInvoicedRows(array $report): array
    {
        $rows = [['As of', self::cellString($report['as_of'] ?? null)], ['Operation number', 'Customer', 'Completed at', 'Days outstanding']];

        $deliveries = $report['deliveries'] ?? [];
        if (is_array($deliveries)) {
            foreach ($deliveries as $delivery) {
                if (! is_array($delivery)) {
                    continue;
                }
                $rows[] = [
                    self::cellString($delivery['operation_number'] ?? null),
                    self::cellString($delivery['customer_name'] ?? null),
                    self::cellStringOrNull($delivery['completed_at'] ?? null),
                    self::cellInt($delivery['days_outstanding'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<bool|float|int|string|null>>
     */
    private function invoicedNotCollectedRows(array $report): array
    {
        $rows = [['As of', self::cellString($report['as_of'] ?? null)], ['Customer', 'Outstanding minor']];

        $customers = $report['customers'] ?? [];
        if (is_array($customers)) {
            foreach ($customers as $customer) {
                if (! is_array($customer)) {
                    continue;
                }
                $rows[] = [self::cellString($customer['customer_name'] ?? null), self::cellInt($customer['outstanding_minor'] ?? null)];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<bool|float|int|string|null>>
     */
    private function taxRecognitionSummaryRows(array $report): array
    {
        $rows = [];
        foreach ($report as $key => $value) {
            $rows[] = [$key, is_scalar($value) ? $value : self::cellString(json_encode($value))];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<bool|float|int|string|null>>
     */
    private function discountAndFloorOverridesRows(array $report): array
    {
        $rows = [['Product variant', 'Attempted price', 'Min price', 'Approved by', 'Approved at', 'Reason']];

        $overrides = $report['overrides'] ?? [];
        if (is_array($overrides)) {
            foreach ($overrides as $override) {
                if (! is_array($override)) {
                    continue;
                }
                $rows[] = [
                    self::cellInt($override['product_variant_id'] ?? null),
                    self::cellString($override['attempted_price'] ?? null),
                    self::cellString($override['min_price'] ?? null),
                    self::cellStringOrNull($override['approved_by_name'] ?? null),
                    self::cellStringOrNull($override['approved_at'] ?? null),
                    self::cellStringOrNull($override['reason'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<bool|float|int|string|null>>
     */
    private function returnsWithoutCreditRows(array $report): array
    {
        $rows = [['As of', self::cellString($report['as_of'] ?? null)], ['Return number', 'Customer', 'Posted at', 'Days outstanding']];

        $returns = $report['returns'] ?? [];
        if (is_array($returns)) {
            foreach ($returns as $return) {
                if (! is_array($return)) {
                    continue;
                }
                $rows[] = [
                    self::cellString($return['return_number'] ?? null),
                    self::cellString($return['customer_name'] ?? null),
                    self::cellStringOrNull($return['posted_at'] ?? null),
                    self::cellInt($return['days_outstanding'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<bool|float|int|string|null>>
     */
    private function customerRevenueRows(array $report): array
    {
        $rows = [['As of', self::cellString($report['as_of'] ?? null)], ['Customer', 'Invoiced minor', 'Collected minor']];

        $customers = $report['customers'] ?? [];
        if (is_array($customers)) {
            foreach ($customers as $customer) {
                if (! is_array($customer)) {
                    continue;
                }
                $rows[] = [
                    self::cellString($customer['customer_name'] ?? null),
                    self::cellInt($customer['invoiced_minor'] ?? null),
                    self::cellInt($customer['collected_minor'] ?? null),
                ];
            }
        }

        return $rows;
    }

    private static function cellInt(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) => (int) $value,
            is_string($value) && is_numeric($value) => (int) $value,
            default => 0,
        };
    }

    private static function cellString(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            default => '',
        };
    }

    private static function cellStringOrNull(mixed $value): ?string
    {
        return $value === null ? null : self::cellString($value);
    }
}
