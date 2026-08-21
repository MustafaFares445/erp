<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalPeriods\Schemas;

use App\Models\FiscalPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class FiscalPeriodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.resources.fiscal_periods'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin.accounting.fields.period_name'))
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true),
                        DatePicker::make('starts_at')
                            ->label(__('admin.accounting.fields.starts_at'))
                            ->required(),
                        DatePicker::make('ends_at')
                            ->label(__('admin.accounting.fields.ends_at'))
                            ->required()
                            ->afterOrEqual('starts_at'),
                    ])
                    ->columns(3),
            ])
            // Dates on a closed period are frozen: reopen it first. Overlap and
            // ordering are re-checked by FiscalPeriodService regardless, so this
            // is a usability guard rather than the enforcement point.
            ->disabled(fn (?FiscalPeriod $record): bool => $record instanceof FiscalPeriod && $record->is_closed);
    }
}
