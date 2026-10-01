<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Pages;

use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\SupportPermission;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\MaintenanceRequests\Actions\MaintenanceBillingActions;
use App\Filament\Resources\MaintenanceRequests\Actions\WarrantyClaimActions;
use App\Filament\Resources\MaintenanceRequests\Actions\WarrantyRecoveryActions;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceRecordService;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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
            ...WarrantyClaimActions::make(),
            $this->customerApprovalAction(),
            $this->transitionAction('startRepair', 'Start Repair', MaintenanceStatus::InProgress)
                ->visible(fn (): bool => $this->getMaintenanceRecord()->status === MaintenanceStatus::ReadyForRepair),
            $this->transitionAction('sendToQa', 'Send to QA', MaintenanceStatus::QualityAssurance)
                ->visible(fn (): bool => $this->getMaintenanceRecord()->status === MaintenanceStatus::InProgress),
            $this->transitionAction('completeMaintenance', 'Complete Maintenance', MaintenanceStatus::Closed)
                ->visible(fn (): bool => $this->getMaintenanceRecord()->status === MaintenanceStatus::QualityAssurance),
            ...MaintenanceBillingActions::make(),
            ...WarrantyRecoveryActions::make(),
            ActionGroup::make([
                EditAction::make()
                    ->visible(fn (): bool => ! in_array($this->getMaintenanceRecord()->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)),
                Action::make('overrideWarranty')
                    ->label('Correct Warranty Information')
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->authorize('overrideWarranty')
                    ->visible(fn (): bool => ! in_array($this->getMaintenanceRecord()->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true))
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
                        $statusValue = $data['warranty_status'] ?? null;
                        $reason = $data['reason'] ?? null;

                        if (! is_string($statusValue) || ! is_string($reason)) {
                            throw new LogicException('Warranty override data is invalid.');
                        }

                        $status = WarrantyStatus::from($statusValue);
                        $expiry = isset($data['warranty_expiry_date']) && is_string($data['warranty_expiry_date'])
                            ? Carbon::parse($data['warranty_expiry_date'])
                            : null;

                        app(MaintenanceRecordService::class)->overrideWarranty(
                            $this->getMaintenanceRecord(),
                            $status,
                            $expiry,
                            $reason,
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
                            'subject_id' => ['value' => $this->getMaintenanceRecord()->getRouteKey()],
                        ],
                    ])),
            ]),
        ];
    }

    private function customerApprovalAction(): Action
    {
        return Action::make('customerApprovedRepair')
            ->label('Customer Approved — Ready for Repair')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('update')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->getMaintenanceRecord()->status === MaintenanceStatus::AwaitingApproval)
            ->action(function (): void {
                try {
                    $record = $this->getMaintenanceRecord();
                    $customerAmount = (int) $record->coverageLines()->sum('customer_amount_minor');
                    $record->loadMissing('quotation');

                    if ($customerAmount > 0 && $record->quotation?->status !== QuotationStatus::Accepted) {
                        throw new DomainException('The customer quotation must be accepted before repair can begin.');
                    }

                    app(MaintenanceRecordService::class)->transition(
                        $record,
                        MaintenanceStatus::ReadyForRepair,
                        self::currentActor(),
                    );
                    Notification::make()->success()->title('Repair approved and ready to start')->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Repair cannot start yet')->body($domainException->getMessage())->send();
                }
            });
    }

    private function transitionAction(string $name, string $label, MaintenanceStatus $to): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->authorize('update')
            ->requiresConfirmation()
            ->action(function () use ($to): void {
                try {
                    app(MaintenanceRecordService::class)->transition(
                        $this->getMaintenanceRecord(),
                        $to,
                        self::currentActor(),
                    );
                    Notification::make()->success()->title('Maintenance request updated')->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Unable to change maintenance status')->body($domainException->getMessage())->send();
                }
            });
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
