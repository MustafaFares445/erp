<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\Pages;

use App\Enums\MaintenanceStatus;
use App\Enums\SupportPermission;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\Support\ServiceRecordService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use LogicException;

final class ViewServiceRecord extends ViewRecord
{
    protected static string $resource = ServiceRecordResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            $this->transitionAction('startWork', 'Start Work', MaintenanceStatus::InProgress)
                ->visible(fn (): bool => $this->getServiceRecord()->status === MaintenanceStatus::Open),
            $this->completeAction()
                ->visible(fn (): bool => $this->getServiceRecord()->status === MaintenanceStatus::InProgress),
            ActionGroup::make([
                $this->transitionAction('cancel', 'Cancel', MaintenanceStatus::Cancelled)
                    ->color('danger')
                    ->visible(fn (): bool => ! in_array($this->getServiceRecord()->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)),
                EditAction::make()
                    ->visible(fn (): bool => in_array($this->getServiceRecord()->status, [MaintenanceStatus::Open, MaintenanceStatus::InProgress], true)),
                Action::make('viewAuditTrail')
                    ->label('View Audit Trail')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->authorize(fn (): bool => (bool) auth()->user()?->can(SupportPermission::AuditView->value))
                    ->url(fn (): string => AuditLogResource::getUrl('index', [
                        'tableFilters' => [
                            'subject_type' => ['value' => MaintenanceTask::class],
                            'subject_id' => ['value' => (string) $this->getServiceRecord()->id],
                        ],
                    ])),
            ]),
        ];
    }

    private function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Complete Work')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->authorize('execute')
            ->schema([
                Textarea::make('work_performed')
                    ->label('Work performed')
                    ->required()
                    ->rows(4),
                Textarea::make('completion_notes')
                    ->label('Completion notes')
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                $workPerformed = $data['work_performed'] ?? null;

                if (! is_string($workPerformed) || mb_trim($workPerformed) === '') {
                    return;
                }

                try {
                    app(ServiceRecordService::class)->transition(
                        $this->getServiceRecord(),
                        MaintenanceStatus::Closed,
                        $this->currentActor(),
                        is_string($data['completion_notes'] ?? null) ? $data['completion_notes'] : null,
                        $workPerformed,
                    );
                    Notification::make()->success()->title('Service record completed')->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Unable to complete the service record')->body($domainException->getMessage())->send();
                }
            });
    }

    private function transitionAction(string $name, string $label, MaintenanceStatus $to): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->authorize('execute')
            ->requiresConfirmation()
            ->action(function () use ($to): void {
                try {
                    app(ServiceRecordService::class)->transition($this->getServiceRecord(), $to, $this->currentActor());
                    Notification::make()->success()->title('Service record updated')->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Unable to change the service record status')->body($domainException->getMessage())->send();
                }
            });
    }

    private function getServiceRecord(): MaintenanceTask
    {
        /** @var MaintenanceTask $record */
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
