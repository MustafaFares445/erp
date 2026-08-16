<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\Tables;

use App\Enums\MaintenanceStatus;
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
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use LogicException;

final class ServiceRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('maintenanceRecord.id')
                    ->label('Maintenance request #')
                    ->searchable(),
                TextColumn::make('employee.user.name')
                    ->label('Assigned to')
                    ->placeholder('Unassigned'),
                TextColumn::make('due_at')
                    ->label('Due')
                    ->dateTime()
                    ->sortable()
                    ->color(static fn (MaintenanceTask $record): string => match (true) {
                        self::isOverdue($record) => 'danger',
                        self::isDueSoon($record) => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(MaintenanceStatus::cases())
                        ->mapWithKeys(static fn (MaintenanceStatus $status): array => [$status->value => str($status->value)->headline()->toString()])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    self::transitionAction('startProgress', 'Start work', MaintenanceStatus::InProgress)
                        ->visible(static fn (MaintenanceTask $record): bool => $record->status === MaintenanceStatus::Open),
                    self::transitionAction('close', 'Close', MaintenanceStatus::Closed)
                        ->visible(static fn (MaintenanceTask $record): bool => $record->status === MaintenanceStatus::InProgress),
                    self::transitionAction('cancel', 'Cancel', MaintenanceStatus::Cancelled)
                        ->color('danger')
                        ->visible(static fn (MaintenanceTask $record): bool => ! in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)),
                    Action::make('archive')
                        ->label('Delete')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('delete')
                        ->visible(static fn (MaintenanceTask $record): bool => ! $record->trashed())
                        ->action(static fn (MaintenanceTask $record) => $record->delete()),
                    Action::make('restore')
                        ->label('Restore')
                        ->requiresConfirmation()
                        ->authorize('restore')
                        ->visible(static fn (MaintenanceTask $record): bool => $record->trashed())
                        ->action(static fn (MaintenanceTask $record) => $record->restore()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Delete selected')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('deleteAny')
                        ->action(static function (Collection $records): void {
                            /** @var MaintenanceTask $record */
                            foreach ($records as $record) {
                                $record->delete();
                            }
                        }),
                    BulkAction::make('restore')
                        ->label('Restore selected')
                        ->requiresConfirmation()
                        ->authorize('restoreAny')
                        ->action(static function (Collection $records): void {
                            /** @var MaintenanceTask $record */
                            foreach ($records as $record) {
                                $record->restore();
                            }
                        }),
                ]),
            ]);
    }

    /**
     * Overdue: not yet terminal, and past its due date (quickstart.md
     * Scenario 7).
     */
    private static function isOverdue(MaintenanceTask $record): bool
    {
        return $record->due_at !== null
            && $record->due_at->isPast()
            && ! in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true);
    }

    /**
     * Due soon: not yet terminal, due within the next 24 hours.
     */
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

    private static function applyTransition(MaintenanceTask $record, MaintenanceStatus $to): void
    {
        try {
            app(ServiceRecordService::class)->transition($record, $to, self::currentActor());
            // @codeCoverageIgnoreStart
            // Each transition action's own ->visible() guard matches MaintenanceStatus::
            // canTransitionTo() exactly, so this can never actually be reached here.
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title('Unable to change the service record status')->body($domainException->getMessage())->send();
        }

        // @codeCoverageIgnoreEnd
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        // @codeCoverageIgnoreStart
        // The admin panel's own auth middleware guarantees an authenticated User here.
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        // @codeCoverageIgnoreEnd

        return $actor;
    }
}
