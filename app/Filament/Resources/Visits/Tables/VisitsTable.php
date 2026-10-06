<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Tables;

use App\Enums\VisitStatus;
use App\Filament\Resources\Visits\Actions\VisitManagementActions;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\CustomerVisit;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\QueryBuilder\Constraints\BooleanConstraint;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class VisitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_start_at', 'desc')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('reference')
                    ->label(__('Visit reference'))
                    ->searchable()
                    ->sortable()
                    ->placeholder(__('Legacy visit')),
                TextColumn::make('customer.company_name')
                    ->label(__('Customer'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('employee.user.name')
                    ->label(__('Employee'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('visit_type')
                    ->label(__('Visit type'))
                    ->searchable()
                    ->placeholder(__('General')),
                TextColumn::make('scheduled_start_at')
                    ->label(__('Scheduled start'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('scheduled_end_at')
                    ->label(__('Scheduled end'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (VisitStatus $state): string => $state->label())
                    ->color(static fn (VisitStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('duration')
                    ->label(__('Duration'))
                    ->state(static fn (CustomerVisit $record): ?string => $record->durationMinutes() !== null
                        ? $record->durationMinutes().' min'
                        : null)
                    ->placeholder(__('—')),
                IconColumn::make('location_warning')
                    ->label(__('Location warning'))
                    ->boolean()
                    ->trueColor('warning'),
                IconColumn::make('follow_up_required')
                    ->label(__('Follow-up'))
                    ->boolean()
                    ->trueColor('warning'),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Status'))
                    ->getTitleFromRecordUsing(static fn (CustomerVisit $record): string => $record->status->label()),
                Group::make('employee.user.name')->label(__('Employee')),
                Group::make('customer.company_name')->label(__('Customer')),
                Group::make('scheduled_start_at')->label(__('Scheduled date'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    TextConstraint::make('reference')->label(__('Visit reference')),
                    TextConstraint::make('visit_type')->label(__('Visit type')),
                    SelectConstraint::make('status')
                        ->label(__('Status'))
                        ->options(static fn (): array => collect(VisitStatus::cases())
                            ->mapWithKeys(static fn (VisitStatus $status): array => [$status->value => $status->label()])
                            ->all())
                        ->multiple(),
                    RelationshipConstraint::make('employee')
                        ->label(__('Employee'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('employee_code')->searchable()->multiple()),
                    RelationshipConstraint::make('customer')
                        ->label(__('Customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    DateConstraint::make('scheduled_start_at')->label(__('Scheduled start')),
                    DateConstraint::make('scheduled_end_at')->label(__('Scheduled end')),
                    BooleanConstraint::make('location_warning')->label(__('Location warning')),
                    BooleanConstraint::make('follow_up_required')->label(__('Follow-up required')),
                ]),
                SelectFilter::make('status')
                    ->options(static fn (): array => collect(VisitStatus::cases())
                        ->mapWithKeys(static fn (VisitStatus $status): array => [$status->value => $status->label()])
                        ->all())
                    ->multiple(),
                SelectFilter::make('employee_id')
                    ->label(__('Employee'))
                    ->relationship('employee', 'employee_code')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('customer_id')
                    ->label(__('Customer'))
                    ->relationship('customer', 'company_name')
                    ->searchable()
                    ->preload(),
                Filter::make('scheduled_between')
                    ->label(__('Scheduled date'))
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(
                            is_string($data['from'] ?? null) ? $data['from'] : null,
                            static fn (Builder $query, string $date): Builder => $query->whereDate('scheduled_start_at', '>=', $date),
                        )
                        ->when(
                            is_string($data['until'] ?? null) ? $data['until'] : null,
                            static fn (Builder $query, string $date): Builder => $query->whereDate('scheduled_start_at', '<=', $date),
                        )),
                TernaryFilter::make('location_warning')->label(__('Location warning')),
                TernaryFilter::make('follow_up_required')->label(__('Follow-up required')),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    VisitManagementActions::reschedule(),
                    VisitManagementActions::review(),
                ]),
            ]);
    }
}
