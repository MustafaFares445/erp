<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Tables;

use App\Enums\PaymentLinkStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\Tickets\Actions\TriageTicketAction;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketPaymentService;
use App\Services\Support\TicketSlaStateResolver;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

final class TicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('ticket_number')->label(__('Ticket #'))->badge()->searchable()->sortable(),
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('customer.company_name')->label(__('Customer'))->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('priority')->badge()->color(static fn (TicketPriority $state): string => match ($state) {
                    TicketPriority::Urgent => 'danger',
                    TicketPriority::High => 'warning',
                    TicketPriority::Normal => 'info',
                    TicketPriority::Low => 'gray',
                }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (TicketStatus $state): string => $state->label())
                    ->color(static fn (TicketStatus $state): string => $state->color()),
                TextColumn::make('next_action')
                    ->label(__('Next action'))
                    ->getStateUsing(static fn (Ticket $record): string => self::nextAction($record))
                    ->wrap(),
                TextColumn::make('equipment')->label(__('Equipment'))
                    ->getStateUsing(static function (Ticket $record): string {
                        $unit = $record->serializedInventoryUnit;

                        if ($unit instanceof SerializedInventoryUnit) {
                            $product = $unit->productVariant->name ?? 'Equipment';

                            return $product.' · SN '.$unit->serial_number;
                        }

                        return $record->external_equipment_name ?? 'Not triaged';
                    })
                    ->wrap(),
                TextColumn::make('warranty_status')
                    ->label(__('Warranty eligibility'))
                    ->badge()
                    ->placeholder(__('Not checked'))
                    ->formatStateUsing(static fn (WarrantyStatus $state): string => $state->label())
                    ->color(static fn (WarrantyStatus $state): string => $state->color()),
                TextColumn::make('pending_reason')->label(__('Blocked by'))->placeholder(__('—'))->limit(32),
                TextColumn::make('assignedEmployee.user.name')->label(__('Assignee'))->placeholder(__('Unassigned')),
                TextColumn::make('sla_state')
                    ->label(__('SLA'))
                    ->badge()
                    ->getStateUsing(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->label($record))
                    ->color(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->color($record)),
                TextColumn::make('updated_at')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(TicketStatus::cases())->mapWithKeys(static fn (TicketStatus $status): array => [$status->value => __(str($status->value)->headline()->toString())])),
                SelectFilter::make('type')->options(collect(TicketType::cases())->mapWithKeys(static fn (TicketType $type): array => [$type->value => __(str($type->value)->headline()->toString())])),
                SelectFilter::make('priority')->options(collect(TicketPriority::cases())->mapWithKeys(static fn (TicketPriority $priority): array => [$priority->value => __(str($priority->value)->headline()->toString())])),
                SelectFilter::make('assigned_employee_id')->label(__('Assignee'))->relationship('assignedEmployee', 'employee_code'),
                TernaryFilter::make('response_breached')->label(__('Response breached'))->queries(
                    true: self::responseBreachedQuery(...),
                    false: self::notResponseBreachedQuery(...),
                ),
                TernaryFilter::make('resolution_breached')->label(__('Resolution breached'))->queries(
                    true: self::resolutionBreachedQuery(...),
                    false: self::notResolutionBreachedQuery(...),
                ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    TriageTicketAction::make(),
                    Action::make('settlePayment')
                        ->label(__('Settle Payment'))
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->authorize('settlePayment')
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::PendingPayment && $record->paymentLink?->status === PaymentLinkStatus::Pending)
                        ->schema([TextInput::make('payment_method_reference')->label(__('Payment reference'))->required()->maxLength(255)])
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
                        ->modalHeading(__('Reopen this ticket?'))
                        ->modalDescription(__('Reopening clears the resolution date and resumes the original resolution clock.'))
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::Resolved),
                    self::transitionAction('cancel', 'Cancel', TicketStatus::Cancelled)
                        ->color('danger')
                        ->visible(static fn (Ticket $record): bool => ! in_array($record->status, [TicketStatus::Closed, TicketStatus::Cancelled], true)),
                    Action::make('unassign')
                        ->label(__('Unassign'))->icon(Heroicon::OutlinedArrowUturnLeft)->requiresConfirmation()->authorize('assign')
                        ->visible(static fn (Ticket $record): bool => $record->status === TicketStatus::Assigned)
                        ->action(static fn (Ticket $record) => self::applyUnassign($record)),
                    Action::make('raiseMaintenanceRequest')
                        ->label(__('Raise Maintenance Request'))->icon(Heroicon::OutlinedWrench)->authorize('create', MaintenanceRecord::class)
                        ->visible(static fn (Ticket $record): bool => in_array($record->service_path, [
                            TicketServicePath::Maintenance,
                            TicketServicePath::OnSiteVisit,
                        ], true)
                            && in_array($record->status, [TicketStatus::Live, TicketStatus::Assigned, TicketStatus::InProgress], true))
                        ->url(static fn (Ticket $record): string => MaintenanceRequestResource::getUrl('create', ['ticket_id' => $record->getKey()])),
                    Action::make('archive')->label(__('Delete'))->color('danger')->requiresConfirmation()->authorize('delete')
                        ->visible(static fn (Ticket $record): bool => ! $record->trashed())
                        ->action(static fn (Ticket $record) => $record->delete()),
                    Action::make('restore')->label(__('Restore'))->requiresConfirmation()->authorize('restore')
                        ->visible(static fn (Ticket $record): bool => $record->trashed())
                        ->action(static fn (Ticket $record) => $record->restore()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')->label(__('Delete selected'))->color('danger')->requiresConfirmation()->authorize('deleteAny')
                        ->action(static function (Collection $records): void {
                            foreach ($records as $record) {
                                if ($record instanceof Ticket) {
                                    $record->delete();
                                }
                            }
                        }),
                    BulkAction::make('restore')->label(__('Restore selected'))->requiresConfirmation()->authorize('restoreAny')
                        ->action(static function (Collection $records): void {
                            foreach ($records as $record) {
                                if ($record instanceof Ticket) {
                                    $record->restore();
                                }
                            }
                        }),
                ]),
            ]);
    }

    private static function nextAction(Ticket $ticket): string
    {
        return match ($ticket->status) {
            TicketStatus::Pending => 'Triage ticket',
            TicketStatus::PendingPayment => 'Collect diagnostic fee',
            TicketStatus::Live => 'Assign owner',
            TicketStatus::Assigned => 'Start work',
            TicketStatus::InProgress => in_array($ticket->service_path, [TicketServicePath::Maintenance, TicketServicePath::OnSiteVisit], true)
                ? 'Continue / raise maintenance'
                : 'Continue remote support',
            TicketStatus::WaitingCustomer => 'Waiting for customer',
            TicketStatus::Resolved => 'Review & close',
            TicketStatus::Closed => 'Complete',
            TicketStatus::Cancelled => 'Cancelled',
        };
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

    private static function transitionAction(string $name, string $label, TicketStatus $to): Action
    {
        $ability = in_array($to, [TicketStatus::Live, TicketStatus::Cancelled], true) ? 'update' : 'work';

        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->requiresConfirmation()
            ->authorize($ability)
            ->schema($to === TicketStatus::Resolved ? [
                Textarea::make('resolution_summary')->label(__('Resolution summary'))->required()->rows(4),
            ] : [])
            ->action(static function (Ticket $record, array $data) use ($to): void {
                $summary = $to === TicketStatus::Resolved ? ($data['resolution_summary'] ?? null) : null;
                self::applyTransition($record, $to, is_string($summary) ? $summary : null);
            });
    }

    private static function applyTransition(Ticket $record, TicketStatus $to, ?string $note = null): void
    {
        try {
            app(TicketLifecycleService::class)->transition($record, $to, self::currentActor(), $note);
        } catch (ValidationException|DomainException $exception) {
            Notification::make()->danger()->title(__('Unable to change the ticket status'))->body($exception->getMessage())->send();
        }
    }

    private static function applyUnassign(Ticket $record): void
    {
        try {
            app(TicketLifecycleService::class)->unassign($record, self::currentActor());
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title(__('Unable to unassign this ticket'))->body($domainException->getMessage())->send();
        }
    }

    private static function applySettlement(Ticket $record, string $methodReference): void
    {
        $link = $record->paymentLink;

        if ($link === null) {
            Notification::make()->danger()->title(__('Unable to settle payment'))->body(__('This ticket has no payment link.'))->send();

            return;
        }

        try {
            app(TicketPaymentService::class)->settle($link, $methodReference, self::currentActor());
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title(__('Unable to settle payment'))->body($domainException->getMessage())->send();
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
