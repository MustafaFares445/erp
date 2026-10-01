<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\RelationManagers;

use App\Enums\VisitStatus;
use App\Filament\Resources\Visits\Schemas\VisitInfolist;
use App\Models\CustomerVisit;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class VisitsRelationManager extends RelationManager
{
    protected static string $relationship = 'visits';

    #[\Override]
    public function infolist(Schema $schema): Schema
    {
        return VisitInfolist::configure($schema);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('checked_in_at', 'desc')
            ->columns([
                TextColumn::make('customer.company_name')->label(__('Customer'))->searchable()->placeholder(__('Not linked')),
                TextColumn::make('planTask.title')->label(__('Plan task'))->searchable()->placeholder(__('Not linked')),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('checked_in_at')->dateTime()->sortable(),
                TextColumn::make('checked_out_at')->dateTime()->sortable(),
                TextColumn::make('duration')
                    ->label(__('Duration'))
                    ->state(static fn (CustomerVisit $record): ?string => $record->durationMinutes() !== null
                        ? $record->durationMinutes().' min'
                        : null)
                    ->placeholder(__('—')),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_column(VisitStatus::cases(), 'value', 'value')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
