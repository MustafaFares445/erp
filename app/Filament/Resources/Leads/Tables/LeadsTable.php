<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\Actions\LeadActions;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\Lead;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('lead_number')->searchable()->sortable(),
                TextColumn::make('company_name')->searchable()->placeholder(__('—')),
                TextColumn::make('first_name')->label(__('Contact'))->formatStateUsing(fn (Lead $record): string => $record->displayName())->searchable(['first_name', 'last_name']),
                TextColumn::make('source')->badge()->formatStateUsing(fn (LeadSource $state): string => $state->label()),
                TextColumn::make('status')->badge()->color(fn (LeadStatus $state): string => $state->color())->formatStateUsing(fn (LeadStatus $state): string => $state->label()),
                TextColumn::make('assignee.name')->label(__('Assigned to'))->placeholder(__('Unassigned'))->searchable(),
                TextColumn::make('last_interaction_at')->dateTime()->placeholder(__('Never'))->sortable(),
            ])
            ->groups([
                Group::make('status')->label(__('Status')),
                Group::make('source')->label(__('Source')),
                Group::make('assignee.name')->label(__('Assigned to')),
                Group::make('created_at')->label(__('Created at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Status'))
                        ->options(LeadStatus::class)
                        ->multiple(),
                    SelectConstraint::make('source')
                        ->label(__('Source'))
                        ->options(LeadSource::class)
                        ->multiple(),
                    TextConstraint::make('lead_number')->label(__('Reference')),
                    TextConstraint::make('company_name')->label(__('Company name')),
                    TextConstraint::make('email')->label(__('Email')),
                    RelationshipConstraint::make('assignee')
                        ->label(__('Assigned to'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    RelationshipConstraint::make('campaign')
                        ->label(__('Campaign'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    DateConstraint::make('last_interaction_at')->label(__('Last interaction')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
                Filter::make('dormant')->label(__('Dormant 14+ days'))->query(function (Builder $query): Builder {
                    /** @var Builder<Lead> $query */
                    return (new Lead)->scopeDormant($query);
                }),
            ])
            ->recordActions([
                ViewAction::make(),
                LeadActions::logInteraction(),
                LeadActions::assign(),
                LeadActions::disqualify(),
                LeadActions::convert(),
                EditAction::make()->visible(fn (Lead $record): bool => ! $record->status->isTerminal()),
            ]);
    }
}
