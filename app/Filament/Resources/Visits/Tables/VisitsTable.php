<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Tables;

use App\Enums\VisitStatus;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\CustomerVisit;
use App\Services\Employees\VisitReviewService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class VisitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('employee.user.name')->label(__('Employee'))->searchable()->sortable(),
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
                TextColumn::make('planned_at')->label(__('Planned at'))->dateTime()->placeholder(__('—'))->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Created at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Status'))
                    ->getTitleFromRecordUsing(static fn (CustomerVisit $record): string => $record->status->label()),
                Group::make('employee.user.name')->label(__('Employee')),
                Group::make('customer.company_name')->label(__('Customer')),
                Group::make('planned_at')->label(__('Planned at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->options(static fn (): array => collect(VisitStatus::cases())->mapWithKeys(static fn (VisitStatus $status): array => [$status->value => $status->label()])->all())
                        ->multiple(),
                    RelationshipConstraint::make('employee')
                        ->label(__('Employee'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('employee_code')->searchable()->multiple()),
                    RelationshipConstraint::make('customer')
                        ->label(__('Customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    RelationshipConstraint::make('planTask')
                        ->label(__('Plan task'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('title')->searchable()->multiple()),
                    DateConstraint::make('planned_at')->label(__('Planned at')),
                    DateConstraint::make('checked_in_at')->label(__('Checked in at')),
                    DateConstraint::make('checked_out_at')->label(__('Checked out at')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('review')
                    ->label(__('Add / update review note'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->authorize('review')
                    ->fillForm(static fn (CustomerVisit $record): array => ['review_note' => $record->review_note])
                    ->schema([
                        Textarea::make('review_note')
                            ->label(__('Review note'))
                            ->required()
                            ->rows(4),
                    ])
                    ->action(static function (CustomerVisit $record, array $data): void {
                        $note = $data['review_note'] ?? null;

                        app(VisitReviewService::class)->updateReviewNote($record, is_string($note) ? $note : '');
                    }),
            ]);
    }
}
