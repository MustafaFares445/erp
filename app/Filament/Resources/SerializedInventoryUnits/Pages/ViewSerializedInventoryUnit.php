<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Pages;

use App\Enums\SupportPermission;
use App\Enums\WarrantyEntitlementState;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryMovement;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Support\WarrantyEntitlementService;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use LogicException;

final class ViewSerializedInventoryUnit extends ViewRecord
{
    protected static string $resource = SerializedInventoryUnitResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('activateWarranty')
                ->label('Activate Warranty')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('success')
                ->authorize(fn (): bool => (bool) auth()->user()?->can(SupportPermission::WarrantyOverride->value))
                ->visible(static fn (SerializedInventoryUnit $record): bool => self::currentEntitlement($record)?->state === WarrantyEntitlementState::PendingActivation)
                ->schema([
                    DatePicker::make('starts_on')
                        ->label('Warranty starts on')
                        ->default(today())
                        ->required(),
                    Textarea::make('reason')
                        ->label('Activation reason')
                        ->helperText('For example: installation completed, commissioning accepted, or manual activation approved.')
                        ->required()
                        ->rows(3),
                ])
                ->action(static function (SerializedInventoryUnit $record, array $data): void {
                    $entitlement = self::currentEntitlement($record);
                    $startsOn = $data['starts_on'] ?? null;
                    $reason = $data['reason'] ?? null;

                    if (! $entitlement instanceof WarrantyEntitlement || ! is_string($startsOn) || ! is_string($reason)) {
                        throw new LogicException('Warranty activation data is invalid.');
                    }

                    try {
                        app(WarrantyEntitlementService::class)->activate(
                            $entitlement,
                            Carbon::parse($startsOn),
                            self::currentActor(),
                            $reason,
                        );
                        Notification::make()->success()->title('Warranty activated')->send();
                    } catch (DomainException $domainException) {
                        Notification::make()->danger()->title('Unable to activate warranty')->body($domainException->getMessage())->send();
                    }
                }),
            Action::make('cancelWarrantyEntitlement')
                ->label('Cancel Warranty Entitlement')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color('danger')
                ->authorize(fn (): bool => (bool) auth()->user()?->can(SupportPermission::WarrantyOverride->value))
                ->visible(static fn (SerializedInventoryUnit $record): bool => in_array(
                    self::currentEntitlement($record)?->state,
                    [WarrantyEntitlementState::Active, WarrantyEntitlementState::PendingActivation],
                    true,
                ))
                ->requiresConfirmation()
                ->schema([
                    Textarea::make('reason')->label('Cancellation reason')->required()->rows(3),
                ])
                ->action(static function (SerializedInventoryUnit $record, array $data): void {
                    $entitlement = self::currentEntitlement($record);
                    $reason = $data['reason'] ?? null;

                    if (! $entitlement instanceof WarrantyEntitlement || ! is_string($reason)) {
                        throw new LogicException('Warranty cancellation data is invalid.');
                    }

                    try {
                        app(WarrantyEntitlementService::class)->cancel($entitlement, self::currentActor(), $reason);
                        Notification::make()->success()->title('Warranty entitlement cancelled')->send();
                    } catch (DomainException $domainException) {
                        Notification::make()->danger()->title('Unable to cancel warranty')->body($domainException->getMessage())->send();
                    }
                }),
            Action::make('viewMovements')
                ->label(__('admin.inventory.serialized.actions.view_movements'))
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->url(fn (SerializedInventoryUnit $record): string => StockMovementResource::getUrl('index', [
                    'tableFilters' => [
                        'serialized_inventory_unit_id' => ['value' => $record->getKey()],
                    ],
                ])),
            Action::make('viewMaintenance')
                ->label(__('admin.inventory.serialized.actions.view_maintenance'))
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->url(fn (SerializedInventoryUnit $record): string => MaintenanceScheduleResource::getUrl('index', [
                    'tableFilters' => [
                        'serialized_inventory_unit_id' => ['value' => $record->getKey()],
                    ],
                ])),
            Action::make('openReceipt')
                ->label(__('admin.inventory.serialized.actions.open_receipt'))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->visible(fn (SerializedInventoryUnit $record): bool => self::receiptOperationId($record) !== null)
                ->url(function (SerializedInventoryUnit $record): ?string {
                    $operationId = self::receiptOperationId($record);

                    return $operationId !== null
                        ? AdminModuleRegistry::resolveResourceRecordLink(InventoryOperationResource::class, $operationId)
                        : null;
                }),
        ];
    }

    private static function currentEntitlement(SerializedInventoryUnit $unit): ?WarrantyEntitlement
    {
        $entitlement = $unit->warrantyEntitlements()->first();

        return $entitlement instanceof WarrantyEntitlement ? $entitlement : null;
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        return $actor;
    }

    private static function receiptOperationId(SerializedInventoryUnit $unit): ?int
    {
        $movement = $unit->receiptMovement()->first();

        if (
            ! $movement instanceof InventoryMovement
            || $movement->source_type !== 'inventory_operation'
            || ! is_int($movement->source_id)
        ) {
            return null;
        }

        return $movement->source_id;
    }
}
