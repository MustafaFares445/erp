<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords\Pages;

use App\Enums\SupportPermission;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\MaintenanceTask;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewServiceRecord extends ViewRecord
{
    protected static string $resource = ServiceRecordResource::class;

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
                        'subject_type' => ['value' => MaintenanceTask::class],
                        'subject_id' => ['value' => (string) $this->getServiceRecord()->id],
                    ],
                ])),
        ];
    }

    private function getServiceRecord(): MaintenanceTask
    {
        /** @var MaintenanceTask $record */
        $record = $this->getRecord();

        return $record;
    }
}
