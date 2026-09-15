<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SupportPermission;
use App\Filament\Widgets\SupportNeedsAttention;
use App\Filament\Widgets\SupportStatistics;
use App\Filament\Widgets\SupportTicketTrend;
use App\Filament\Widgets\SupportUpcomingMaintenance;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Support's module landing page. Keep operational work queues above trend
 * reporting so staff can act on tickets and maintenance before reviewing
 * historical charts.
 */
final class SupportDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(SupportPermission::TicketView->value) ?? false;
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.support_dashboard');
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            SupportStatistics::class,
            SupportNeedsAttention::class,
            SupportUpcomingMaintenance::class,
            SupportTicketTrend::class,
        ];
    }
}
