<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Actions;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use LogicException;

/**
 * The billing actions for a closed service job (WP-2.9, GAP-MW-10) —
 * `mark_warranty_covered`, `create_quotation`, and `create_invoice` — each
 * visible only in the state its guard in {@see MaintenanceBillingService}
 * actually allows, so a disallowed transition never even reaches the user as
 * a clickable option.
 */
final class MaintenanceBillingActions
{
    /** @return list<Action> */
    public static function make(): array
    {
        return [
            self::markWarrantyCovered(),
            self::createQuotation(),
            self::createInvoice(),
        ];
    }

    private static function markWarrantyCovered(): Action
    {
        return Action::make('mark_warranty_covered')
            ->label('Mark Warranty Covered')
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')->required()->label('Reason'),
            ])
            ->visible(static fn (MaintenanceRecord $record): bool => self::isBillable($record))
            ->authorize(fn (MaintenanceRecord $record): bool => self::currentActor()->can('bill', $record))
            ->action(function (MaintenanceRecord $record, array $data): void {
                try {
                    app(MaintenanceBillingService::class)->markWarrantyCovered($record, self::currentActor(), (string) $data['reason']);
                    Notification::make()->success()->title('Marked as warranty-covered')->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Unable to mark as warranty-covered')->body($domainException->getMessage())->send();
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
