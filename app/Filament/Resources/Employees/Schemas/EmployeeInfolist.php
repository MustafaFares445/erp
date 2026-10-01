<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('employee_code')->label(__('Employee code')),
                        TextEntry::make('job_title'),
                        TextEntry::make('user.name')->label(__('Account name')),
                        TextEntry::make('user.email')->label(__('Login email')),
                        TextEntry::make('phone')->placeholder(__('Not provided')),
                        TextEntry::make('email')->label(__('Contact email'))->placeholder(__('Not provided')),
                        IconEntry::make('is_active')->label(__('App access enabled'))->boolean(),
                    ])
                    ->columns(2),
                Section::make(__('Salary basis'))
                    ->schema([
                        IconEntry::make('use_base_salary')->label(__('Uses base salary'))->boolean(),
                        TextEntry::make('base_salary')->money()->placeholder(__('Not provided')),
                        TextEntry::make('commission_target_amount')->label(__('Commission/target amount'))->money()->placeholder(__('Not provided')),
                    ])
                    ->columns(3),
            ]);
    }
}
