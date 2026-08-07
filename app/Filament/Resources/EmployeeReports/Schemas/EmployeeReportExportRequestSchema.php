<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeReports\Schemas;

use App\Models\EmployeeProfile;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;

final class EmployeeReportExportRequestSchema
{
    /** @return list<Component> */
    public static function make(): array
    {
        return [
            Select::make('employee_id')
                ->label('Employee')
                ->options(fn (): array => EmployeeProfile::query()
                    ->with('user:id,name')
                    ->get()
                    ->mapWithKeys(fn (EmployeeProfile $employee): array => [$employee->id => self::employeeLabel($employee)])
                    ->all())
                ->searchable(),
            DatePicker::make('month'),
        ];
    }

    private static function employeeLabel(EmployeeProfile $employee): string
    {
        $user = $employee->user;

        return $user instanceof User ? $user->name : $employee->employee_code;
    }
}
