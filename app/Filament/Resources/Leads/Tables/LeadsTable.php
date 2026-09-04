<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\Actions\LeadActions;
use App\Models\Lead;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('lead_number')->searchable()->sortable(),
                TextColumn::make('company_name')->searchable()->placeholder('—'),
                TextColumn::make('first_name')->label('Contact')->formatStateUsing(fn (Lead $record): string => $record->displayName())->searchable(['first_name', 'last_name']),
                TextColumn::make('source')->badge()->formatStateUsing(fn (LeadSource $state): string => str($state->value)->replace('_', ' ')->headline()->toString()),
                TextColumn::make('status')->badge()->color(fn (LeadStatus $state): string => $state->color())->formatStateUsing(fn (LeadStatus $state): string => str($state->value)->headline()->toString()),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('Unassigned')->searchable(),
                TextColumn::make('last_interaction_at')->dateTime()->placeholder('Never')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(LeadStatus::cases())->mapWithKeys(fn (LeadStatus $status): array => [$status->value => str($status->value)->headline()->toString()])->all()),
                SelectFilter::make('source')->options(collect(LeadSource::cases())->mapWithKeys(fn (LeadSource $source): array => [$source->value => str($source->value)->replace('_', ' ')->headline()->toString()])->all()),
                Filter::make('dormant')->label('Dormant 14+ days')->query(function (Builder $query): Builder {
                    /** @var Builder<Lead> $query */
                    return (new Lead)->scopeDormant($query);
                }),
            ])
            ->recordActions([
                LeadActions::logInteraction(),
                LeadActions::assign(),
                LeadActions::disqualify(),
                LeadActions::convert(),
                EditAction::make()->visible(fn (Lead $record): bool => ! $record->status->isTerminal()),
            ]);
    }
}
