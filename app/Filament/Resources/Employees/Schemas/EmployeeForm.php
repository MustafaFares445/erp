<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\Schemas;

use App\Models\EmployeeProfile;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->visibleOn('create'),
                        TextInput::make('username')
                            ->maxLength(50)
                            ->visibleOn('create'),
                        TextInput::make('login_email')
                            ->label('Login email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->visibleOn('create'),
                        Placeholder::make('account_name')
                            ->label('Account name')
                            ->content(static fn (?EmployeeProfile $record): string => $record?->user->name ?? '—')
                            ->visibleOn('edit'),
                        Placeholder::make('account_email')
                            ->label('Login email')
                            ->content(static fn (?EmployeeProfile $record): string => $record?->user->email ?? '—')
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),
                Section::make('Profile')
                    ->schema([
                        TextInput::make('job_title')
                            ->required()
                            ->maxLength(150),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(30),
                        TextInput::make('email')
                            ->label('Contact email')
                            ->email()
                            ->maxLength(150)
                            ->visibleOn('edit'),
                        Toggle::make('is_active')
                            ->label('App access enabled')
                            ->default(true)
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),
                Section::make('Salary basis')
                    ->schema([
                        Toggle::make('use_base_salary')
                            ->label('Use base salary')
                            ->live()
                            ->default(true),
                        TextInput::make('base_salary')
                            ->numeric()
                            ->prefix('AED')
                            ->required(static fn (Get $get): bool => (bool) $get('use_base_salary'))
                            ->visible(static fn (Get $get): bool => (bool) $get('use_base_salary')),
                        TextInput::make('commission_target_amount')
                            ->label('Commission/target amount')
                            ->numeric()
                            ->prefix('AED')
                            ->required(static fn (Get $get): bool => ! $get('use_base_salary'))
                            ->visible(static fn (Get $get): bool => ! $get('use_base_salary')),
                    ])
                    ->columns(2),
            ]);
    }
}
