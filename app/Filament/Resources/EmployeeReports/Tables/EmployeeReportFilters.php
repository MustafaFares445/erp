<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeReports\Tables;

use App\Models\EmployeeProfile;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeReportFilters
{
    /** @return list<Filter|SelectFilter> */
    public static function make(): array
    {
        return [
            SelectFilter::make('employee_id')
                ->label('Employee')
                ->options(fn (): array => EmployeeProfile::query()
                    ->with('user:id,name')
                    ->get()
                    ->mapWithKeys(fn (EmployeeProfile $employee): array => [$employee->id => self::employeeLabel($employee)])
                    ->all())
                // The Filament table filter widget only ever narrows the
                // report query — EmployeeReportService owns the actual
                // WHERE clause (research.md: "Filament filter components
                // are UI-only").
                ->query(static fn (Builder $query): Builder => $query),
            Filter::make('month')
                ->schema([
                    DatePicker::make('month')->label('Month'),
                ])
                ->query(static fn (Builder $query): Builder => $query),
        ];
    }

    private static function employeeLabel(EmployeeProfile $employee): string
    {
        $user = $employee->user;

        return $user instanceof User ? $user->name : $employee->employee_code;
    }
}
