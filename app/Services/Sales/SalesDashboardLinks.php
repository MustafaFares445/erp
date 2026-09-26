<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Quotations\QuotationResource;

/**
 * Builds drill-down URLs into the Sales resource list pages. Filament's
 * `ListRecords` binds the active tab to the `tab` query key and the table
 * filters form to `filters` (see `#[Url(as: 'tab')]` on
 * `$activeTab` and `#[Url(as: 'filters')]` on `$tableFilters`) — not
 * `activeTab`/`tableFilters` as the key names themselves, which is why
 * every link built here uses `tab`/`filters` rather than mirroring the
 * property names.
 */
final class SalesDashboardLinks
{
    public static function quotations(?string $tab = null, ?int $customerId = null, ?int $employeeId = null): string
    {
        return QuotationResource::getUrl('index', self::params($tab, $customerId, $employeeId));
    }

    public static function orders(?string $tab = null, ?int $customerId = null): string
    {
        return OrderResource::getUrl('index', self::params($tab, $customerId));
    }

    public static function invoices(?string $tab = null, ?int $customerId = null): string
    {
        return InvoiceResource::getUrl('index', self::params($tab, $customerId));
    }

    public static function deliveryNotes(?string $tab = null): string
    {
        return DeliveryNoteResource::getUrl('index', self::params($tab));
    }

    /** @return array<string, mixed> */
    private static function params(?string $tab = null, ?int $customerId = null, ?int $employeeId = null): array
    {
        $filters = [];
        if ($customerId !== null) {
            $filters['customer_id'] = ['value' => $customerId];
        }
        if ($employeeId !== null) {
            $filters['employee_id'] = ['value' => $employeeId];
        }

        return array_filter([
            'tab' => $tab,
            'filters' => $filters !== [] ? $filters : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
