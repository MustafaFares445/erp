<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Pages;

use App\Enums\SupportPermission;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRecord;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewMaintenanceRequest extends ViewRecord
{
    protected static string $resource = MaintenanceRequestResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('viewAuditTrail')
                ->label('View Audit Trail')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->authorize(fn (): bool => (bool) auth()->user()?->can(SupportPermission::AuditView->value))
                ->url(fn (): string => AuditLogResource::getUrl('index', [
                    'tableFilters' => [
                        'subject_type' => ['value' => MaintenanceRecord::class],
                        'subject_id' => ['value' => (string) $this->getMaintenanceRecord()->id],
                    ],
                ])),
        ];
    }

    private function getMaintenanceRecord(): MaintenanceRecord
    {
        /** @var MaintenanceRecord $record */
        $record = $this->getRecord();

        return $record;
    }
}
