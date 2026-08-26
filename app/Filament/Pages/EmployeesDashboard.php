<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\EmployeePermission;
use App\Filament\Widgets\EmployeesStatistics;
use App\Filament\Widgets\EmployeesTaskTrend;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

final class EmployeesDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    #[\Override]
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->can(EmployeePermission::EmployeeView->value) ?? false)
            || ($user?->can(EmployeePermission::TaskView->value) ?? false);
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.employees_dashboard');
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            EmployeesStatistics::class,
            EmployeesTaskTrend::class,
        ];
    }
}
