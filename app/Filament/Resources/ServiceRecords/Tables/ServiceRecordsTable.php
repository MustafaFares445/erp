<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\Tables;

use App\Enums\MaintenanceStatus;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\Support\ServiceRecordService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use LogicException;

final class ServiceRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('maintenanceRecord.id')->label(__('Maintenance request #'))->searchable(),
                TextColumn::make('maintenanceRecord.customer.company_name')->label(__('Customer'))->searchable(),
                TextColumn::make('equipment')
                    ->label(__('Equipment'))
                    ->getStateUsing(static fn (MaintenanceTask $record): string => $record->maintenanceRecord?->serializedInventoryUnit?->productVariant->name
                        ?? ($record->maintenanceRecord?->is_equipment_unlinked ? 'External / unlinked' : '—')),
                TextColumn::make('maintenanceRecord.serial_number')->label(__('Serial'))->placeholder(__('—'))->searchable(),
                TextColumn::make('employee.user.name')->label(__('Technician'))->placeholder(__('Unassigned')),
                TextColumn::make('title')->label(__('Work'))->searchable()->limit(40),
                TextColumn::make('started_at')->label(__('Started'))->dateTime()->placeholder(__('—'))->sortable(),
                TextColumn::make('completed_at')->label(__('Completed'))->dateTime()->placeholder(__('—'))->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (MaintenanceStatus $state): string => $state->label())
                    ->color(static fn (MaintenanceStatus $state): string => $state->color()),
                TextColumn::make('due_at')
                    ->label(__('Due'))
                    ->dateTime()
                    ->sortable()
                    ->color(static fn (MaintenanceTask $record): string => match (true) {
                        self::isOverdue($record) => 'danger',
                        self::isDueSoon($record) => 'warning',
                        default => 'gray',
                    })
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Status'))
                    ->getTitleFromRecordUsing(static fn (MaintenanceTask $record): string => $record->status->label()),
                Group::make('employee.employee_code')->label(__('Technician')),
                Group::make('due_at')->label(__('Due'))->date(),
                Group::make('created_at')->label(__('Created at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Status'))
                        ->options(static fn (): array => collect(MaintenanceStatus::cases())
                            ->mapWithKeys(static fn (MaintenanceStatus $status): array => [$status->value => $status->label()])
                            ->all())
                        ->multiple(),
                    TextConstraint::make('title')->label(__('Work')),
                    RelationshipConstraint::make('employee')
                        ->label(__('Technician'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('employee_code')->searchable()->multiple()),
                    DateConstraint::make('started_at')->label(__('Started')),
                    DateConstraint::make('completed_at')->label(__('Completed')),
                    DateConstraint::make('due_at')->label(__('Due')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(static fn (MaintenanceTask $record): bool => in_array($record->status, [MaintenanceStatus::Open, MaintenanceStatus::InProgress], true)),
                ActionGroup::make([
                    self::transitionAction('startProgress', 'Start work', MaintenanceStatus::InProgress)
                        ->visible(static fn (MaintenanceTask $record): bool => $record->status === MaintenanceStatus::Open),
                    self::completeAction()
                        ->visible(static fn (MaintenanceTask $record): bool => $record->status === MaintenanceStatus::InProgress),
                    self::transitionAction('cancel', 'Cancel', MaintenanceStatus::Cancelled)
                        ->color('danger')
                        ->visible(static fn (MaintenanceTask $record): bool => ! in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)),
                    Action::make('archive')
                        ->label(__('Delete'))
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('delete')
                        ->visible(static fn (MaintenanceTask $record): bool => ! $record->trashed())
                        ->action(static fn (MaintenanceTask $record) => $record->delete()),
                    Action::make('restore')
                        ->label(__('Restore'))
                        ->requiresConfirmation()
                        ->authorize('restore')
                        ->visible(static fn (MaintenanceTask $record): bool => $record->trashed())
                        ->action(static fn (MaintenanceTask $record) => $record->restore()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label(__('Delete selected'))
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('deleteAny')
                        ->action(static function (Collection $records): void {
                            foreach ($records as $record) {
                                if ($record instanceof MaintenanceTask) {
                                    $record->delete();
                                }
                            }
                        }),
                    BulkAction::make('restore')
                        ->label(__('Restore selected'))
                        ->requiresConfirmation()
                        ->authorize('restoreAny')
                        ->action(static function (Collection $records): void {
                            foreach ($records as $record) {
                                if ($record instanceof MaintenanceTask) {
                                    $record->restore();
                                }
                            }
                        }),
                ]),
            ]);
    }

    private static function isOverdue(MaintenanceTask $record): bool
    {
        return $record->due_at !== null
            && $record->due_at->isPast()
            && ! in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true);
    }

    private static function isDueSoon(MaintenanceTask $record): bool
    {
        return $record->due_at !== null
            && ! self::isOverdue($record)
            && ! in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)
            && $record->due_at->lessThanOrEqualTo(now()->addHours(24));
    }

    private static function transitionAction(string $name, string $label, MaintenanceStatus $to): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->requiresConfirmation()
            ->authorize('execute')
            ->action(static fn (MaintenanceTask $record) => self::applyTransition($record, $to));
    }

    private static function completeAction(): Action
    {
        return Action::make('close')
            ->label(__('Complete work'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->authorize('execute')
            ->schema([
                Textarea::make('work_performed')
                    ->label(__('Work performed'))
                    ->required()
                    ->rows(4),
                Textarea::make('completion_notes')
                    ->label(__('Completion notes'))
                    ->rows(3),
            ])
            ->action(static function (MaintenanceTask $record, array $data): void {
                try {
                    $workPerformed = $data['work_performed'] ?? null;

                    if (! is_string($workPerformed) || mb_trim($workPerformed) === '') {
                        throw new DomainException('Work performed is required.');
                    }

                    app(ServiceRecordService::class)->transition(
                        $record,
                        MaintenanceStatus::Closed,
                        self::currentActor(),
                        is_string($data['completion_notes'] ?? null) ? $data['completion_notes'] : null,
                        $workPerformed,
                    );
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title(__('Unable to complete the service record'))->body(__($domainException->getMessage()))->send();
                }
            });
    }

    private static function applyTransition(MaintenanceTask $record, MaintenanceStatus $to): void
    {
        try {
            app(ServiceRecordService::class)->transition($record, $to, self::currentActor());
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title(__('Unable to change the service record status'))->body(__($domainException->getMessage()))->send();
        }
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }
}
