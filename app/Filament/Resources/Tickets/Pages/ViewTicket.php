<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\SupportPermission;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('raiseMaintenanceRequest')
                ->label('Raise Maintenance Request')
                ->icon(Heroicon::OutlinedWrench)
                ->authorize('create', MaintenanceRecord::class)
                ->url(fn (): string => MaintenanceRequestResource::getUrl('create', ['ticket_id' => $this->getTicket()->getKey()])),
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
        ];
    }

    private function getTicket(): Ticket
    {
        /** @var Ticket $record */
        $record = $this->getRecord();

        return $record;
    }
}
