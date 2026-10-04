<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\PaymentLinkStatus;
use App\Enums\SupportPermission;
use App\Enums\TicketBlocker;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\UserType;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\Tickets\Actions\TriageTicketAction;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\EmployeeProfile;
use App\Models\KnowledgeArticle;
use App\Models\MaintenanceRecord;
use App\Models\PaymentMethod;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Support\KnowledgeArticleService;
use App\Services\Support\KnowledgeSuggestionService;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketMessageService;
use App\Services\Support\TicketPaymentService;
use App\Services\Support\TicketRoutingService;
use App\Services\Support\TicketSlaStateResolver;
use App\Services\Support\TicketWorkspaceStateResolver;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public string $replyMessage = '';

    public bool $replyInternalNote = false;

    #[\Override]
    public function content(Schema $schema): Schema
    {
        if (! config('support.workspace_v2_enabled', true)) {
            return parent::content($schema);
        }

        return $schema->components([
            View::make('filament.resources.tickets.case-workspace')
                ->viewData(fn (): array => $this->workspaceViewData($this->getTicket())),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    #[\Override]
    public function getHeaderActions(): array
    {
        return array_values(array_filter([
            $this->primaryAction(),
            ActionGroup::make([
                $this->transitionAction('waitForCustomer', 'Wait for Customer', TicketStatus::WaitingCustomer)
                    ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::InProgress),
                $this->transitionAction('reopen', 'Reopen Ticket', TicketStatus::InProgress)
                    ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::Resolved),
                $this->transitionAction('resolveWaiting', 'Resolve Ticket', TicketStatus::Resolved)
                    ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::WaitingCustomer),
                $this->raiseMaintenanceAction(),
                Action::make('routeTicket')
                    ->label(__('Re-run routing'))
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->authorize('route')
                    ->visible(fn (): bool => $this->getTicket()->triaged_at !== null
                        && ! in_array($this->getTicket()->status, [TicketStatus::Closed, TicketStatus::Cancelled], true))
                    ->action(function (): void {
                        $decision = app(TicketRoutingService::class)->route($this->getTicket()->refresh());
                        $this->getTicket()->refresh();

                        if ($decision === null) {
                            Notification::make()->warning()->title(__('No routing rule matched this ticket'))->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Ticket routed'))
                            ->body($decision->reason)
                            ->send();
                    }),
                $this->transitionAction('cancel', 'Cancel Ticket', TicketStatus::Cancelled)
                    ->color('danger')
                    ->visible(fn (): bool => ! in_array($this->getTicket()->status, [TicketStatus::Closed, TicketStatus::Cancelled], true)),
                EditAction::make(),
                Action::make('viewAuditTrail')
                    ->label(__('View Audit Trail'))
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->authorize(fn (): bool => (bool) auth()->user()?->can(SupportPermission::AuditView->value))
                    ->url(fn (): string => AuditLogResource::getUrl('index', [
                        'tableFilters' => [
                            'subject_type' => ['value' => Ticket::class],
                            'subject_id' => ['value' => (string) $this->getTicket()->id],
                        ],
                    ])),
            ])->label(__('More actions'))->icon(Heroicon::OutlinedEllipsisVertical),
        ]));
    }

    public function postMessage(): void
    {
        $body = mb_trim($this->replyMessage);

        if ($body === '') {
            $this->addError('replyMessage', __('Write a message before posting.'));

            return;
        }

        try {
            app(TicketMessageService::class)->post(
                $this->getTicket(),
                $body,
                $this->replyInternalNote,
                $this->currentActor(),
            );

            $this->replyMessage = '';
            $this->replyInternalNote = false;
            $this->resetErrorBag('replyMessage');
            $this->getTicket()->refresh();

            Notification::make()
                ->success()
                ->title(__('Message posted'))
                ->send();
        } catch (DomainException $domainException) {
            Notification::make()
                ->danger()
                ->title(__('Unable to post message'))
                ->body(__($domainException->getMessage()))
                ->send();
        }
    }

    public function shareKnowledgeArticle(int $articleId): void
    {
        $article = KnowledgeArticle::query()->findOrFail($articleId);

        try {
            app(KnowledgeArticleService::class)->shareWithCustomer(
                $this->getTicket(),
                $article,
                $this->currentActor(),
            );

            Notification::make()
                ->success()
                ->title(__('Knowledge article shared with the customer'))
                ->send();
        } catch (DomainException $domainException) {
            Notification::make()
                ->danger()
                ->title(__('Unable to share the knowledge article'))
                ->body(__($domainException->getMessage()))
                ->send();
        }
    }

    public function markKnowledgeUsed(int $articleId): void
    {
        $article = KnowledgeArticle::query()->findOrFail($articleId);

        app(KnowledgeArticleService::class)->markUsedInResolution(
            $this->getTicket(),
            $article,
            $this->currentActor(),
        );

        Notification::make()
            ->success()
            ->title(__('Knowledge article linked to the resolution'))
            ->send();
    }

    private function primaryAction(): ?Action
    {
        $ticket = $this->getTicket();
        $blocker = app(TicketWorkspaceStateResolver::class)->resolve($ticket)->blocker;

        if ($blocker === TicketBlocker::MaintenanceAction) {
            return $this->raiseMaintenanceAction()->label(__('Raise maintenance job'));
        }

        return match ($ticket->status) {
            TicketStatus::Pending => TriageTicketAction::make()->label(__('Triage ticket')),
            TicketStatus::PendingPayment => $this->makeSettlePaymentAction(),
            TicketStatus::Live => $this->makeAssignAction(),
            TicketStatus::Assigned => $this->transitionAction('startProgress', 'Start Work', TicketStatus::InProgress),
            TicketStatus::WaitingCustomer => $this->transitionAction('resumeWork', 'Resume Work', TicketStatus::InProgress),
            TicketStatus::InProgress => $this->transitionAction('resolve', 'Resolve Ticket', TicketStatus::Resolved),
            TicketStatus::Resolved => $this->transitionAction('close', 'Close Ticket', TicketStatus::Closed),
            TicketStatus::Closed, TicketStatus::Cancelled => null,
        };
    }

    private function raiseMaintenanceAction(): Action
    {
        return Action::make('raiseMaintenanceRequest')
            ->label(__('Raise Maintenance Request'))
            ->icon(Heroicon::OutlinedWrench)
            ->authorize('create', MaintenanceRecord::class)
            ->visible(fn (): bool => in_array($this->getTicket()->service_path, [
                TicketServicePath::Maintenance,
                TicketServicePath::OnSiteVisit,
            ], true) && in_array($this->getTicket()->status, [
                TicketStatus::Live,
                TicketStatus::Assigned,
                TicketStatus::InProgress,
            ], true))
            ->url(fn (): string => MaintenanceRequestResource::getUrl('create', [
                'ticket_id' => $this->getTicket()->getKey(),
            ]));
    }

    private function makeAssignAction(): Action
    {
        return Action::make('assign')
            ->label(__('Assign Employee'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->authorize('assign')
            ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::Live)
            ->schema([
                Select::make('employee_id')
                    ->label(__('Employee'))
                    ->options(fn (): array => EmployeeProfile::query()
                        ->where('is_active', true)
                        ->with('user:id,name')
                        ->get()
                        ->mapWithKeys(fn (EmployeeProfile $employee): array => [
                            $employee->id => (string) $employee->user?->name,
                        ])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $employeeId = $data['employee_id'] ?? null;

                if (! is_numeric($employeeId)) {
                    return;
                }

                try {
                    $employee = EmployeeProfile::query()->findOrFail((int) $employeeId);
                    app(TicketLifecycleService::class)->assign($this->getTicket(), $employee, $this->currentActor());
                    $this->getTicket()->refresh();
                    Notification::make()->success()->title(__('Ticket assigned'))->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title(__('Unable to assign this ticket'))->body(__($domainException->getMessage()))->send();
                }
            });
    }

    private function makeSettlePaymentAction(): Action
    {
        return Action::make('settlePayment')
            ->label(__('Settle Payment'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('primary')
            ->authorize('settlePayment')
            ->visible(fn (): bool => $this->getTicket()->status === TicketStatus::PendingPayment
                && $this->getTicket()->paymentLink?->status === PaymentLinkStatus::Pending)
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
                TextInput::make('payment_method_reference')
                    ->label(__('Payment reference'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                $reference = $data['payment_method_reference'] ?? null;
                $paymentMethodId = $data['payment_method_id'] ?? null;
                $link = $this->getTicket()->paymentLink;

                if (! is_string($reference) || $reference === '' || ! is_numeric($paymentMethodId) || $link === null) {
                    return;
                }

                try {
                    app(TicketPaymentService::class)->settle($link, $reference, $this->currentActor(), (int) $paymentMethodId);
                    $this->getTicket()->refresh();
                    Notification::make()->success()->title(__('Payment settled'))->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title(__('Unable to settle payment'))->body(__($domainException->getMessage()))->send();
                }
            });
    }

    private function transitionAction(string $name, string $label, TicketStatus $to): Action
    {
        $ability = $to === TicketStatus::Cancelled ? 'update' : 'work';

        return Action::make($name)
            ->label(__($label))
            ->icon(Heroicon::OutlinedArrowRight)
            ->color($to === TicketStatus::Cancelled ? 'danger' : 'primary')
            ->authorize($ability)
            ->requiresConfirmation()
            ->schema($to === TicketStatus::Resolved ? [
                Textarea::make('resolution_summary')
                    ->label(__('Resolution summary'))
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
                    $this->getTicket()->refresh();
                    Notification::make()->success()->title(__('Ticket updated'))->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()->danger()->title(__('Unable to change the ticket status'))->body(__($exception->getMessage()))->send();
                }
            });
    }

    /** @return array<string, mixed> */
    private function workspaceViewData(Ticket $ticket): array
    {
        $ticket->loadMissing([
            'customer',
            'assignedEmployee.user',
            'supportTeam',
            'routedByRule',
            'serializedInventoryUnit.productVariant',
            'paymentLink.providerTransaction',
            'triagedBy',
            'continuedFromTicket:id,ticket_number',
        ]);

        /** @var Collection<int, TicketMessage> $messages */
        $messages = $ticket->messages()
            ->with('sender:id,name,user_type')
            ->reorder()
            ->latest('created_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        $firstPublicMessageId = $ticket->first_response_at === null
            ? null
            : $messages->first(static fn (TicketMessage $message): bool => ! $message->is_internal_note
                && $message->sender?->user_type !== UserType::Customer
                && $message->created_at !== null
                && ! $message->created_at->lt($ticket->first_response_at))?->getKey();

        $maintenance = $ticket->maintenanceRecords()
            ->latest('id')
            ->limit(6)
            ->get();

        $suggestedKnowledge = collect();

        if (config('support.knowledge_base_enabled', false)
            && $this->currentActor()->can(SupportPermission::KnowledgeView->value)) {
            $suggestedKnowledge = app(KnowledgeSuggestionService::class)
                ->suggestForTicket($ticket, 5);
        }

        return [
            'ticket' => $ticket,
            'workspaceState' => app(TicketWorkspaceStateResolver::class)->resolve($ticket),
            'slaLabel' => app(TicketSlaStateResolver::class)->label($ticket),
            'slaColor' => app(TicketSlaStateResolver::class)->color($ticket),
            'messages' => $messages,
            'firstPublicMessageId' => $firstPublicMessageId,
            'maintenanceRecords' => $maintenance,
            'suggestedKnowledge' => $suggestedKnowledge,
            'attachments' => $ticket->getMedia('ticket-attachments'),
            'maintenanceUrl' => static fn (MaintenanceRecord $record): string => MaintenanceRequestResource::getUrl('view', ['record' => $record]),
        ];
    }

    private function getTicket(): Ticket
    {
        $record = $this->getRecord();

        if (! $record instanceof Ticket) {
            throw new LogicException('Expected a Ticket record.');
        }

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
