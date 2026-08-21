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
                        TextEntry::make('employee_code')->label('Employee code'),
                        TextEntry::make('job_title'),
                        TextEntry::make('user.name')->label('Account name'),
                        TextEntry::make('user.email')->label('Login email'),
                        TextEntry::make('phone')->placeholder('Not provided'),
                        TextEntry::make('email')->label('Contact email')->placeholder('Not provided'),
                        IconEntry::make('is_active')->label('App access enabled')->boolean(),
                    ])
                    ->columns(2),
                Section::make('Salary basis')
                    ->schema([
                        IconEntry::make('use_base_salary')->label('Uses base salary')->boolean(),
                        TextEntry::make('base_salary')->money('AED')->placeholder('Not provided'),
                        TextEntry::make('commission_target_amount')->label('Commission/target amount')->money('AED')->placeholder('Not provided'),
                    ])
                    ->columns(3),
            ]);
    }
}
