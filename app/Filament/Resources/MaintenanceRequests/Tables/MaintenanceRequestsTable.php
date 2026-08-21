<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Tables;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceRecordService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use LogicException;

final class MaintenanceRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('customer.company_name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('ticket.ticket_number')
                    ->label('Ticket')
                    ->placeholder('Standalone')
                    ->searchable(),
                TextColumn::make('serial_number')
                    ->label('Serial number')
                    ->searchable()
                    ->placeholder('—'),
                IconColumn::make('serialized_inventory_unit_id')
                    ->label('Equipment linked')
                    ->boolean(),
                IconColumn::make('is_equipment_unlinked')
                    ->label('Unlinked equipment')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('gray'),
                TextColumn::make('warranty_status')
                    ->label('Warranty')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                        ->visible(static fn (MaintenanceRecord $record): bool => $record->status === MaintenanceStatus::Open),
                    self::transitionAction('close', 'Close', MaintenanceStatus::Closed)
                        ->visible(static fn (MaintenanceRecord $record): bool => $record->status === MaintenanceStatus::InProgress),
                    self::transitionAction('cancel', 'Cancel', MaintenanceStatus::Cancelled)
                        ->color('danger')
                        ->visible(static fn (MaintenanceRecord $record): bool => ! in_array($record->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)),
                    Action::make('archive')
                        ->label('Delete')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('delete')
                        ->visible(static fn (MaintenanceRecord $record): bool => ! $record->trashed())
                        ->action(static fn (MaintenanceRecord $record) => $record->delete()),
                    Action::make('restore')
                        ->label('Restore')
                        ->requiresConfirmation()
                        ->authorize('restore')
                        ->visible(static fn (MaintenanceRecord $record): bool => $record->trashed())
                        ->action(static fn (MaintenanceRecord $record) => $record->restore()),
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
                            /** @var MaintenanceRecord $record */
                            foreach ($records as $record) {
                                $record->delete();
                            }
                        }),
                    BulkAction::make('restore')
                        ->label('Restore selected')
                        ->requiresConfirmation()
                        ->authorize('restoreAny')
                        ->action(static function (Collection $records): void {
                            /** @var MaintenanceRecord $record */
                            foreach ($records as $record) {
                                $record->restore();
                            }
                        }),
                ]),
            ]);
    }

    private static function transitionAction(string $name, string $label, MaintenanceStatus $to): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->requiresConfirmation()
            ->authorize('update')
            ->action(static fn (MaintenanceRecord $record) => self::applyTransition($record, $to));
    }

    private static function applyTransition(MaintenanceRecord $record, MaintenanceStatus $to): void
    {
        try {
            app(MaintenanceRecordService::class)->transition($record, $to, self::currentActor());
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title('Unable to change the maintenance request status')->body($domainException->getMessage())->send();
        }
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
