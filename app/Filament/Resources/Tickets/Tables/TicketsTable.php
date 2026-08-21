<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Tables;

use App\Enums\PaymentLinkStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketPaymentService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

final class TicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('ticket_number')
                    ->label('Ticket #')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('customer.company_name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('priority')
                    ->badge()
                    ->color(static fn (TicketPriority $state): string => match ($state) {
                        TicketPriority::Urgent => 'danger',
                        TicketPriority::High => 'warning',
                        TicketPriority::Normal => 'info',
                        TicketPriority::Low => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('assignedEmployee.user.name')
                    ->label('Assignee')
                    ->placeholder('Unassigned'),
                IconColumn::make('response_breached')
                    ->label('Response breached')
                    ->boolean()
                    ->trueColor('danger')
                    // Live-accurate: reflects a just-passed due time immediately rather than
                    // waiting for the next scheduled sweep to persist the flag (FR-054).
                    ->getStateUsing(static fn (Ticket $record): bool => $record->isResponseBreached()),
                IconColumn::make('resolution_breached')
                    ->label('Resolution breached')
                    ->boolean()
                    ->trueColor('danger')
                    ->getStateUsing(static fn (Ticket $record): bool => $record->isResolutionBreached()),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(TicketStatus::cases())
                        ->mapWithKeys(static fn (TicketStatus $status): array => [$status->value => str($status->value)->headline()->toString()])),
                SelectFilter::make('type')
                    ->options(collect(TicketType::cases())
                        ->mapWithKeys(static fn (TicketType $type): array => [$type->value => str($type->value)->headline()->toString()])),
                SelectFilter::make('priority')
                    ->options(collect(TicketPriority::cases())
                        ->mapWithKeys(static fn (TicketPriority $priority): array => [$priority->value => str($priority->value)->headline()->toString()])),
                SelectFilter::make('assigned_employee_id')
                    ->label('Assignee')
                    ->relationship('assignedEmployee', 'employee_code'),
                TernaryFilter::make('response_breached')
                    ->label('Response breached')
                    ->queries(
                        true: self::responseBreachedQuery(...),
                        false: self::notResponseBreachedQuery(...),
                    ),
                TernaryFilter::make('resolution_breached')
                    ->label('Resolution breached')
                    ->queries(
                        true: self::resolutionBreachedQuery(...),
                        false: self::notResolutionBreachedQuery(...),
                    ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    self::transitionAction('triage', 'Triage', TicketStatus::Live)
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::Pending),
                    Action::make('settlePayment')
                        ->label('Settle Payment')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->authorize('settlePayment')
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::PendingPayment
                            && $record->paymentLink?->status === PaymentLinkStatus::Pending)
                        ->schema([
                            TextInput::make('payment_method_reference')
                                ->label('Payment reference')
                                ->required()
                                ->maxLength(255),
                        ])
                        ->action(static function (Ticket $record, array $data): void {
                            $reference = $data['payment_method_reference'] ?? null;

                            if (is_string($reference) && $reference !== '') {
                                self::applySettlement($record, $reference);
                            }
                        }),
                    self::transitionAction('startProgress', 'Start work', TicketStatus::InProgress)
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::Assigned),
                    self::transitionAction('waitForCustomer', 'Wait for customer', TicketStatus::WaitingCustomer)
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::InProgress),
                    self::transitionAction('resumeWork', 'Resume', TicketStatus::InProgress)
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::WaitingCustomer),
                    self::transitionAction('resolve', 'Resolve', TicketStatus::Resolved)
                        ->visible(static fn (Ticket $record): bool => in_array($record->status, [TicketStatus::InProgress, TicketStatus::WaitingCustomer], true)),
                    self::transitionAction('close', 'Close', TicketStatus::Closed)
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::Resolved),
                    self::transitionAction('reopen', 'Reopen', TicketStatus::InProgress)
                        ->modalHeading('Reopen this ticket?')
                        ->modalDescription('Reopening clears the resolution date and resumes the original resolution clock.')
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::Resolved),
                    self::transitionAction('cancel', 'Cancel', TicketStatus::Cancelled)
                        ->color('danger')
                        ->visible(static fn (Ticket $record): bool => ! in_array($record->status, [TicketStatus::Closed, TicketStatus::Cancelled], true)),
                    Action::make('unassign')
                        ->label('Unassign')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->requiresConfirmation()
                        ->authorize('assign')
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::Assigned)
                        ->action(static fn (Ticket $record) => self::applyUnassign($record)),
                    Action::make('raiseMaintenanceRequest')
                        ->label('Raise Maintenance Request')
                        ->icon(Heroicon::OutlinedWrench)
                        ->authorize('create', MaintenanceRecord::class)
                        ->url(static fn (Ticket $record): string => MaintenanceRequestResource::getUrl('create', ['ticket_id' => $record->getKey()])),
                    Action::make('archive')
                        ->label('Delete')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('delete')
                        ->visible(static fn (Ticket $record): bool => ! $record->trashed())
                        ->action(static fn (Ticket $record) => $record->delete()),
                    Action::make('restore')
                        ->label('Restore')
                        ->requiresConfirmation()
                        ->authorize('restore')
                        ->visible(static fn (Ticket $record): bool => $record->trashed())
                        ->action(static fn (Ticket $record) => $record->restore()),
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
                            /** @var Ticket $record */
                            foreach ($records as $record) {
                                $record->delete();
                            }
                        }),
                    BulkAction::make('restore')
                        ->label('Restore selected')
                        ->requiresConfirmation()
                        ->authorize('restoreAny')
                        ->action(static function (Collection $records): void {
                            /** @var Ticket $record */
                            foreach ($records as $record) {
                                $record->restore();
                            }
                        }),
                ]),
            ]);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private static function responseBreachedQuery(Builder $query): Builder
    {
        return $query->responseBreached();
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private static function notResponseBreachedQuery(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $query): Builder => $query->responseBreached());
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private static function resolutionBreachedQuery(Builder $query): Builder
    {
        return $query->resolutionBreached();
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    private static function notResolutionBreachedQuery(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $query): Builder => $query->resolutionBreached());
    }

    /**
     * The authorize ability mirrors {@see TicketLifecycleService::authorizeTransition()}
     * exactly — `live`/`cancelled` are the Support Manager's unrestricted
     * `update` ability, every other target is the Support Agent's
     * own-ticket-only `work` ability. Without this, an unauthorized action
     * click reached the service layer's own rejection but as a raw
     * exception rather than the module's usual graceful notification.
     */
    private static function transitionAction(string $name, string $label, TicketStatus $to): Action
    {
        $ability = in_array($to, [TicketStatus::Live, TicketStatus::Cancelled], true) ? 'update' : 'work';

        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->requiresConfirmation()
            ->authorize($ability)
            ->action(static fn (Ticket $record) => self::applyTransition($record, $to));
    }

    private static function applyTransition(Ticket $record, TicketStatus $to): void
    {
        try {
            app(TicketLifecycleService::class)->transition($record, $to, self::currentActor());
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title('Unable to change the ticket status')->body($domainException->getMessage())->send();
        }
    }

    private static function applyUnassign(Ticket $record): void
    {
        try {
            app(TicketLifecycleService::class)->unassign($record, self::currentActor());
            // @codeCoverageIgnoreStart
            // The action's own ->visible() guard (status === Assigned) matches unassign()'s
            // only precondition exactly, so this can never actually be reached here.
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title('Unable to unassign this ticket')->body($domainException->getMessage())->send();
        }

        // @codeCoverageIgnoreEnd
    }

    private static function applySettlement(Ticket $record, string $methodReference): void
    {
        $link = $record->paymentLink;

        // @codeCoverageIgnoreStart
        // The action's own ->visible() guard already requires a paymentLink to exist.
        if ($link === null) {
            Notification::make()->danger()->title('Unable to settle payment')->body('This ticket has no payment link.')->send();

            return;
        }

        // @codeCoverageIgnoreEnd

        try {
            app(TicketPaymentService::class)->settle($link, $methodReference, self::currentActor());
            // @codeCoverageIgnoreStart
            // The action's own ->visible() guard matches settle()'s only precondition
            // (status === PendingPayment and link status === Pending) exactly.
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title('Unable to settle payment')->body($domainException->getMessage())->send();
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
