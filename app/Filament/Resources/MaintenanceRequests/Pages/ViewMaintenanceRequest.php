<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Pages;

use App\Enums\SupportPermission;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceRecordService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use LogicException;

final class ViewMaintenanceRequest extends ViewRecord
{
    protected static string $resource = MaintenanceRequestResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('overrideWarranty')
                ->label('Override Warranty')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->authorize('update')
                ->schema([
                    Select::make('warranty_status')
                        ->label('Warranty')
                        ->options(collect(WarrantyStatus::cases())
                            ->mapWithKeys(static fn (WarrantyStatus $status): array => [$status->value => str($status->value)->headline()->toString()]))
                        ->required()
                        ->live(),
                    DatePicker::make('warranty_expiry_date')
                        ->label('Warranty expiry date')
                        ->required(static fn (Get $get): bool => $get('warranty_status') === WarrantyStatus::Covered->value)
                        ->visible(static fn (Get $get): bool => $get('warranty_status') === WarrantyStatus::Covered->value),
                    Textarea::make('reason')->required()->label('Reason'),
                ])
                ->action(function (array $data): void {
                    $status = WarrantyStatus::from((string) $data['warranty_status']);
                    $expiry = isset($data['warranty_expiry_date']) && is_string($data['warranty_expiry_date'])
                        ? Carbon::parse($data['warranty_expiry_date'])
                        : null;

                    app(MaintenanceRecordService::class)->overrideWarranty(
                        $this->getMaintenanceRecord(),
                        $status,
                        $expiry,
                        (string) $data['reason'],
                        self::currentActor(),
                    );
                }),
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

    private static function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }
}
