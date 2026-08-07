<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Schemas;

use App\Enums\VisitStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The admin field-edit escape hatch (FR-044) — reached only through
 * `employees.visit.field-edit`, so this form deliberately allows a direct
 * correction of any field rather than re-running the check-in/check-out
 * workflow the (out-of-scope) field app would normally drive.
 */
final class VisitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('status')
                            ->options(array_column(VisitStatus::cases(), 'value', 'value'))
                            ->required(),
                        DateTimePicker::make('planned_at'),
                        DateTimePicker::make('checked_in_at'),
                        DateTimePicker::make('checked_out_at'),
                        Textarea::make('outcome')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
