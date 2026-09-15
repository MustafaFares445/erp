<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\PaymentLinkStatus;
use App\Enums\SupportPermission;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\Tickets\Actions\TriageTicketAction;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketPaymentService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            TriageTicketAction::make(),
            $this->settlePaymentAction(),
            $this->assignAction(),
            $this->transitionAction('startProgress', 'Start Work', TicketStatus::InProgress)
                ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::Assigned),
            $this->transitionAction('resumeWork', 'Resume Work', TicketStatus::InProgress)
                ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::WaitingCustomer),
            $this->transitionAction('resolve', 'Resolve Ticket', TicketStatus::Resolved)
                ->visible(fn (): bool => in_array($this->getTicket()->status, [TicketStatus::InProgress, TicketStatus::WaitingCustomer], true)),
            $this->transitionAction('close', 'Close Ticket', TicketStatus::Closed)
                ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::Resolved),
            ActionGroup::make([
                $this->transitionAction('waitForCustomer', 'Wait for Customer', TicketStatus::WaitingCustomer)
                    ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::InProgress),
                $this->transitionAction('reopen', 'Reopen Ticket', TicketStatus::InProgress)
                    ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::Resolved),
                $this->transitionAction('cancel', 'Cancel Ticket', TicketStatus::Cancelled)
                    ->color('danger')
                    ->visible(fn (): bool => ! in_array($this->getTicket()->status, [TicketStatus::Closed, TicketStatus::Cancelled], true)),
                Action::make('raiseMaintenanceRequest')
                    ->label('Raise Maintenance Request')
                    ->icon(Heroicon::OutlinedWrench)
                    ->authorize('create', MaintenanceRecord::class)
                    ->visible(fn (): bool => $this->getTicket()->service_path === TicketServicePath::Maintenance
                        && in_array($this->getTicket()->status, [TicketStatus::Live, TicketStatus::Assigned, TicketStatus::InProgress], true))
                    ->url(fn (): string => MaintenanceRequestResource::getUrl('create', ['ticket_id' => $this->getTicket()->getKey()])),
                EditAction::make(),
                Action::make('viewAuditTrail')
                    ->label('View Audit Trail')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->authorize(fn (): bool => (bool) auth()->user()?->can(SupportPermission::AuditView->value))
                    ->url(fn (): string => AuditLogResource::getUrl('index', [
                        'tableFilters' => [
                            'subject_type' => ['value' => Ticket::class],
                            'subject_id' => ['value' => (string) $this->getTicket()->id],
                        ],
                    ])),
            ]),
        ];
    }

    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Assign Employee')
            ->icon(Heroicon::OutlinedUserPlus)
            ->authorize('assign')
            ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::Live)
            ->schema([
                Select::make('employee_id')
                    ->label('Employee')
                    ->options(fn (): array => EmployeeProfile::query()->with('user')->get()
                        ->mapWithKeys(fn (EmployeeProfile $employee): array => [$employee->id => (string) $employee->user?->name])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $employeeId = $data['employee_id'] ?? null;

                if (! is_int($employeeId) && ! is_string($employeeId)) {
                    return;
                }

                try {
                    $employee = EmployeeProfile::query()->findOrFail($employeeId);
                    app(TicketLifecycleService::class)->assign($this->getTicket(), $employee, $this->currentActor());
                    Notification::make()->success()->title('Ticket assigned')->send();
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title('Unable to assign this ticket')->body($exception->getMessage())->send();
                }
            });
    }

    private function settlePaymentAction(): Action
    {
        return Action::make('settlePayment')
            ->label('Settle Payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->authorize('settlePayment')
            ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::PendingPayment
                && $this->getTicket()->paymentLink?->status === PaymentLinkStatus::Pending)
            ->schema([
                TextInput::make('payment_method_reference')
                    ->label('Payment reference')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                $reference = $data['payment_method_reference'] ?? null;
                $link = $this->getTicket()->paymentLink;

                if (! is_string($reference) || $reference === '' || $link === null) {
                    return;
                }

                try {
                    app(TicketPaymentService::class)->settle($link, $reference, $this->currentActor());
                    Notification::make()->success()->title('Payment settled')->send();
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title('Unable to settle payment')->body($exception->getMessage())->send();
                }
            });
    }

    private function transitionAction(string $name, string $label, TicketStatus $to): Action
    {
        $ability = $to === TicketStatus::Cancelled ? 'update' : 'work';

        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->authorize($ability)
            ->requiresConfirmation()
            ->schema($to === TicketStatus::Resolved ? [
                Textarea::make('resolution_summary')
                    ->label('Resolution summary')
                    ->required()
                    ->rows(4),
            ] : [])
            ->action(function (array $data) use ($to): void {
                $summary = $to === TicketStatus::Resolved ? ($data['resolution_summary'] ?? null) : null;

                try {
                    app(TicketLifecycleService::class)->transition(
                        $this->getTicket(),
                        $to,
                        $this->currentActor(),
                        is_string($summary) ? $summary : null,
                    );
                    Notification::make()->success()->title('Ticket updated')->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()->danger()->title('Unable to change the ticket status')->body($exception->getMessage())->send();
                }
            });
    }

    private function getTicket(): Ticket
    {
        /** @var Ticket $record */
        $record = $this->getRecord();

        return $record;
    }

    private function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }
}
