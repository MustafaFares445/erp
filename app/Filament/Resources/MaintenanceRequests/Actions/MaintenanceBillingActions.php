<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Actions;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\PaymentLinkStatus;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use LogicException;

final class MaintenanceBillingActions
{
    /** @return list<Action> */
    public static function make(): array
    {
        return [
            self::markWarrantyCovered(),
            self::markTicketSettled(),
            self::createQuotation(),
            self::createInvoice(),
        ];
    }

    private static function markWarrantyCovered(): Action
    {
        return Action::make('mark_warranty_covered')
            ->label('Mark Warranty Covered')
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->required()->label('Reason')])
            ->visible(static fn (MaintenanceRecord $record): bool => self::isBillable($record))
            ->authorize(fn (MaintenanceRecord $record): bool => self::currentActor()->can('bill', $record))
            ->action(function (MaintenanceRecord $record, array $data): void {
                try {
                    $reason = $data['reason'] ?? null;
                    if (! is_string($reason) || $reason === '') {
                        throw new DomainException('A warranty coverage reason is required.');
                    }

                    app(MaintenanceBillingService::class)->markWarrantyCovered($record, self::currentActor(), $reason);
                    Notification::make()->success()->title('Marked as warranty-covered')->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Unable to mark as warranty-covered')->body($domainException->getMessage())->send();
                }
            });
    }

    private static function markTicketSettled(): Action
    {
        return Action::make('mark_ticket_settled')
            ->label('Mark Covered by Ticket Payment')
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->required()->label('Reason')])
            ->visible(static fn (MaintenanceRecord $record): bool => self::isBillable($record)
                && $record->ticket?->paymentLink?->status === PaymentLinkStatus::Settled)
            ->authorize(fn (MaintenanceRecord $record): bool => self::currentActor()->can('bill', $record))
            ->action(function (MaintenanceRecord $record, array $data): void {
                try {
                    $reason = $data['reason'] ?? null;
                    if (! is_string($reason) || $reason === '') {
                        throw new DomainException('A reason is required.');
                    }

                    app(MaintenanceBillingService::class)->markTicketSettled($record, self::currentActor(), $reason);
                    Notification::make()->success()->title('Marked as covered by ticket payment')->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Unable to settle maintenance billing')->body($domainException->getMessage())->send();
                }
            });
    }

    private static function createQuotation(): Action
    {
        return Action::make('create_quotation')
            ->label('Create Quotation')
            ->requiresConfirmation()
            ->visible(static fn (MaintenanceRecord $record): bool => self::isBillable($record))
            ->authorize(fn (MaintenanceRecord $record): bool => self::currentActor()->can('bill', $record))
            ->action(function (MaintenanceRecord $record): void {
                try {
                    app(MaintenanceBillingService::class)->createQuotation($record, self::currentActor());
                    Notification::make()->success()->title('Quotation created')->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Unable to create the quotation')->body($domainException->getMessage())->send();
                }
            });
    }

    private static function createInvoice(): Action
    {
        return Action::make('create_invoice')
            ->label('Create Invoice')
            ->requiresConfirmation()
            ->visible(static fn (MaintenanceRecord $record): bool => self::isBillable($record))
            ->authorize(fn (MaintenanceRecord $record): bool => self::currentActor()->can('bill', $record))
            ->action(function (MaintenanceRecord $record): void {
                try {
                    app(MaintenanceBillingService::class)->createInvoice($record, self::currentActor());
                    Notification::make()->success()->title('Invoice created')->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Unable to create the invoice')->body($domainException->getMessage())->send();
                }
            });
    }

    private static function isBillable(MaintenanceRecord $record): bool
    {
        return $record->status === MaintenanceStatus::Closed
            && $record->billing_type === MaintenanceBillingType::Unbilled;
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
