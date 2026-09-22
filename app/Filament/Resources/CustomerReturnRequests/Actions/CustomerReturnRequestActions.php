<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerReturnRequests\Actions;

use App\Enums\CustomerReturnRequestStatus;
use App\Models\CustomerReturnRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\CustomerReturnRequestService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Thin adapters over {@see CustomerReturnRequestService}, shared between the
 * table and the view page.
 */
final class CustomerReturnRequestActions
{
    public static function startReview(): Action
    {
        return Action::make('startReview')
            ->label('Start Review')
            ->icon(Heroicon::MagnifyingGlass)
            ->color('info')
            ->visible(fn (CustomerReturnRequest $record): bool => $record->status === CustomerReturnRequestStatus::Submitted)
            ->authorize('review')
            ->action(function (CustomerReturnRequest $record): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                app(CustomerReturnRequestService::class)->startReview($actor, $record);

                Notification::make()->success()->title('Request is now under review')->send();
            });
    }

    public static function approveAndConvert(): Action
    {
        return Action::make('approveAndConvert')
            ->label('Approve & Convert')
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->schema([
                Select::make('warehouse_id')
                    ->label('Receiving warehouse')
                    ->options(fn (): array => Warehouse::query()->where('is_active', true)->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                Textarea::make('note')->label('Approval note')->rows(2),
            ])
            ->visible(fn (CustomerReturnRequest $record): bool => $record->status === CustomerReturnRequestStatus::UnderReview)
            ->authorize('review')
            ->action(function (CustomerReturnRequest $record, array $data): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                $warehouseId = $data['warehouse_id'] ?? null;
                $warehouse = Warehouse::query()->find(is_numeric($warehouseId) ? (int) $warehouseId : 0);

                if (! $warehouse instanceof Warehouse) {
                    Notification::make()->danger()->title('Select a receiving warehouse.')->send();

                    return;
                }

                $note = $data['note'] ?? null;
                $service = app(CustomerReturnRequestService::class);

                try {
                    $approved = $service->approve($actor, $record, is_string($note) && $note !== '' ? $note : null);
                    $inventoryReturn = $service->convertToInventoryReturn($actor, $approved, $warehouse);
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title($domainException->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(sprintf('Converted to inventory return %s', (string) $inventoryReturn->return_number))
                    ->send();
            });
    }

    /**
     * Retries conversion for a request that was already approved but whose
     * conversion previously failed (e.g. the delivery line no longer has
     * enough returnable quantity) — never re-runs {@see CustomerReturnRequestService::approve()}.
     */
    public static function retryConvert(): Action
    {
        return Action::make('retryConvert')
            ->label('Retry Convert')
            ->icon(Heroicon::ArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->schema([
                Select::make('warehouse_id')
                    ->label('Receiving warehouse')
                    ->options(fn (): array => Warehouse::query()->where('is_active', true)->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->visible(fn (CustomerReturnRequest $record): bool => $record->status === CustomerReturnRequestStatus::Approved)
            ->authorize('review')
            ->action(function (CustomerReturnRequest $record, array $data): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                $warehouseId = $data['warehouse_id'] ?? null;
                $warehouse = Warehouse::query()->find(is_numeric($warehouseId) ? (int) $warehouseId : 0);

                if (! $warehouse instanceof Warehouse) {
                    Notification::make()->danger()->title('Select a receiving warehouse.')->send();

                    return;
                }

                try {
                    $inventoryReturn = app(CustomerReturnRequestService::class)->convertToInventoryReturn($actor, $record, $warehouse);
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title($domainException->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(sprintf('Converted to inventory return %s', (string) $inventoryReturn->return_number))
                    ->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectReturnRequest')
            ->label('Reject')
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->schema([
                Textarea::make('reason')->label('Reason')->rows(2)->required()->maxLength(1000),
            ])
            ->visible(fn (CustomerReturnRequest $record): bool => $record->isOpen())
            ->authorize('review')
            ->action(function (CustomerReturnRequest $record, array $data): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    return;
                }

                $reason = $data['reason'] ?? null;

                app(CustomerReturnRequestService::class)->reject(
                    $actor,
                    $record,
                    is_string($reason) ? $reason : '',
                );

                Notification::make()->danger()->title('Request rejected')->send();
            });
    }

    private static function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
