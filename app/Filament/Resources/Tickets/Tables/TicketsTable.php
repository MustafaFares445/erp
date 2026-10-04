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
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\MaintenanceRecord;
use App\Models\PaymentMethod;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketPaymentService;
use App\Services\Support\TicketSlaStateResolver;
use App\Services\Support\TicketWorkspaceStateResolver;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
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
                FavoriteColumn::make(),
                TextColumn::make('ticket_number')
                    ->label(__('Ticket'))
                    ->badge()
                    ->searchable(['ticket_number', 'title'])
                    ->sortable()
                    ->description(static fn (Ticket $record): string => str($record->title)->limit(44)->toString())
                    ->tooltip(static fn (Ticket $record): string => $record->title),
                TextColumn::make('customer.company_name')->label(__('Customer'))->searchable(),
                TextColumn::make('type')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('priority')->badge()->color(static fn (TicketPriority $state): string => match ($state) {
                    TicketPriority::Urgent => 'danger',
                    TicketPriority::High => 'warning',
                    TicketPriority::Normal => 'info',
                    TicketPriority::Low => 'gray',
                }),
                TextColumn::make('workspace_stage')
                    ->label(config('support.workspace_v2_enabled', true) ? __('Stage / status') : __('Status'))
                    ->getStateUsing(static fn (Ticket $record): string => config('support.workspace_v2_enabled', true)
                        ? app(TicketWorkspaceStateResolver::class)->resolve($record)->stage->label()
                        : $record->status->label())
                    ->badge()
                    ->description(static fn (Ticket $record): ?string => config('support.workspace_v2_enabled', true) ? $record->status->label() : null)
                    ->color(static fn (Ticket $record): string => config('support.workspace_v2_enabled', true)
                        ? app(TicketWorkspaceStateResolver::class)->resolve($record)->stage->color()
                        : $record->status->color()),
                TextColumn::make('next_action')
                    ->label(__('Next action'))
                    ->getStateUsing(static fn (Ticket $record): string => app(TicketWorkspaceStateResolver::class)->resolve($record)->nextAction)
                    ->wrap(),
                TextColumn::make('equipment')->label(__('Equipment'))
                    ->getStateUsing(static function (Ticket $record): string {
                        $unit = $record->serializedInventoryUnit;

                        if ($unit instanceof SerializedInventoryUnit) {
                            $product = $unit->productVariant->name ?? __('Equipment');

                            return $product.' · '.__('SN :serial', ['serial' => $unit->serial_number]);
                        }

                        return $record->external_equipment_name ?? __('Not triaged');
                    })
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('warranty_status')
                    ->label(__('Warranty eligibility'))
                    ->badge()
                    ->placeholder(__('Not checked'))
                    ->formatStateUsing(static fn (WarrantyStatus $state): string => $state->label())
                    ->color(static fn (WarrantyStatus $state): string => $state->color())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('blocked_by')
                    ->label(__('Blocked by'))
                    ->getStateUsing(static fn (Ticket $record): string => app(TicketWorkspaceStateResolver::class)->resolve($record)->blocker->label())
                    ->badge()
                    ->color(static fn (Ticket $record): string => app(TicketWorkspaceStateResolver::class)->resolve($record)->blocker->color())
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(static fn (): bool => (bool) config('support.workspace_v2_enabled', true)),
                TextColumn::make('supportTeam.name')->label(__('Team'))->placeholder(__('Unrouted')),
                TextColumn::make('assignedEmployee.user.name')->label(__('Assignee'))->placeholder(__('Unassigned')),
                TextColumn::make('sla_state')
                    ->label(__('SLA'))
                    ->badge()
                    ->getStateUsing(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->label($record))
                    ->color(static fn (Ticket $record): string => app(TicketSlaStateResolver::class)->color($record)),
                TextColumn::make('updated_at')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolution_due_at')->label(__('Resolution due'))->dateTime()->placeholder(__('—'))->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Status'))
                    ->getTitleFromRecordUsing(static fn (Ticket $record): string => $record->status->label()),
                Group::make('priority')->label(__('Priority')),
                Group::make('type')->label(__('Type')),
                Group::make('customer.company_name')->label(__('Customer')),
                Group::make('assignedEmployee.employee_code')->label(__('Assignee')),
                Group::make('created_at')->label(__('Created at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->options(static fn (): array => collect(TicketStatus::cases())->mapWithKeys(static fn (TicketStatus $status): array => [$status->value => $status->label()])->all())
                        ->multiple(),
                    SelectConstraint::make('type')->options(TicketType::class)->multiple(),
                    SelectConstraint::make('priority')->options(TicketPriority::class)->multiple(),
                    TextConstraint::make('ticket_number')->label(__('Ticket #')),
                    TextConstraint::make('title')->label(__('Title')),
                    RelationshipConstraint::make('customer')
                        ->label(__('Customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    RelationshipConstraint::make('assignedEmployee')
                        ->label(__('Assignee'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('employee_code')->searchable()->multiple()),
                    DateConstraint::make('created_at')->label(__('Created at')),
                    DateConstraint::make('updated_at')->label(__('Updated at')),
                    DateConstraint::make('resolution_due_at')->label(__('Resolution due')),
                ]),
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
                        ->schema([
                            Select::make('payment_method_id')
                                ->label(__('Payment method'))
                                ->options(fn (): array => PaymentMethod::query()
                                    ->where('is_active', true)
                                    ->where('requires_proof', false)
                                    ->whereNotNull('chart_account_id')
                                    ->where('type', '!=', 'stripe')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->required(),
                            TextInput::make('payment_method_reference')->label(__('Payment reference'))->required()->maxLength(255),
                        ])
                        ->action(static function (Ticket $record, array $data): void {
                            $reference = $data['payment_method_reference'] ?? null;
                            $paymentMethodId = $data['payment_method_id'] ?? null;
                            if (is_string($reference) && $reference !== '' && is_numeric($paymentMethodId)) {
                                self::applySettlement($record, $reference, (int) $paymentMethodId);
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
            Notification::make()->danger()->title(__('Unable to change the ticket status'))->body(__($exception->getMessage()))->send();
        }
    }

    private static function applyUnassign(Ticket $record): void
    {
        try {
            app(TicketLifecycleService::class)->unassign($record, self::currentActor());
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title(__('Unable to unassign this ticket'))->body(__($domainException->getMessage()))->send();
        }
    }

    private static function applySettlement(Ticket $record, string $methodReference, int $paymentMethodId): void
    {
        $link = $record->paymentLink;

        if ($link === null) {
            Notification::make()->danger()->title(__('Unable to settle payment'))->body(__('This ticket has no payment link.'))->send();

            return;
        }

        try {
            app(TicketPaymentService::class)->settle($link, $methodReference, self::currentActor(), $paymentMethodId);
        } catch (DomainException $domainException) {
            Notification::make()->danger()->title(__('Unable to settle payment'))->body(__($domainException->getMessage()))->send();
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
