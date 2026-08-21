<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Schemas;

use App\Models\PlanTask;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('sales_plan')
                    ->label('Plan')
                    ->content(static fn (?PlanTask $record): string => $record?->salesPlan->name ?? '—'),
                TextInput::make('title')->required()->maxLength(200),
                Textarea::make('description'),
                DatePicker::make('starts_at')
                    ->required()
                    ->hintIcon(Heroicon::QuestionMarkCircle, "Must fall inside the plan's month."),
                DatePicker::make('due_at')
                    ->required()
                    ->hintIcon(Heroicon::QuestionMarkCircle, "Must fall inside the plan's month."),
                Select::make('customer_id')
                    ->label('Customer')
                    ->relationship('customer', 'company_name')
                    ->searchable(),
            ]);
    }
}
