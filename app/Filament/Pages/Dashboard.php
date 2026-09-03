<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\PageUsageGuide;
use App\Filament\Widgets\FailedNotifications;
use Filament\Pages\Dashboard as BaseDashboard;

final class Dashboard extends BaseDashboard
{
    #[\Override]
    public function getHeaderWidgets(): array
    {
        return [
            FailedNotifications::class,
        ];
    }

    #[\Override]
    public function getColumns(): array
    {
        return [
            'lg' => 2,
        ];
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.dashboard');
    }

    #[\Override]
    public function getSubheading(): string
    {
        return PageUsageGuide::for([self::class]);
    }
}
