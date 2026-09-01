<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SalesPermission;
use App\Filament\Widgets\SalesRevenueTrend;
use App\Filament\Widgets\SalesStatistics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Sales's module landing page. Surfaces quotation, order, invoice, and
 * payment health at a glance for anyone who can view at least one of those
 * document types, ahead of the individual resource lists reached from the
 * navigation group below it.
 */
final class SalesDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    #[\Override]
    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user?->can(SalesPermission::QuotationView->value) ?? false) {
            return true;
        }
        if ($user?->can(SalesPermission::OrderView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(SalesPermission::InvoiceView->value) ?? false);
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.sales_dashboard');
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [SalesStatistics::class, SalesRevenueTrend::class];
    }
}
