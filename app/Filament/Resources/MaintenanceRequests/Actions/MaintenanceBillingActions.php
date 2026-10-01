<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Actions;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;

final class MaintenanceBillingActions
{
    /** @return list<Action> */
    public static function make(): array
    {
        return [
            self::settleCoveredRepair(),
            self::createQuotation(),
            self::createInvoice(),
            self::reclassifyWarranty(),
        ];
    }

    private static function settleCoveredRepair(): Action
    {
        return Action::make('settle_covered_repair')
            ->label(__('Settle Covered Repair'))
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->required()->label(__('Settlement note'))])
            ->visible(static fn (MaintenanceRecord $record): bool => self::isBillable($record)
                && in_array($record->coverage_decision, [
                    WarrantyClaimDecision::FullyCovered,
                    WarrantyClaimDecision::Goodwill,
                    WarrantyClaimDecision::ThirdPartyWarranty,
                    WarrantyClaimDecision::ServiceContract,
                ], true))
            ->authorize(fn (MaintenanceRecord $record): bool => self::currentActor()->can('bill', $record))
            ->action(function (MaintenanceRecord $record, array $data): void {
                try {
                    $reason = $data['reason'] ?? null;
                    if (! is_string($reason) || mb_trim($reason) === '') {
                        throw new DomainException('A settlement note is required.');
                    }

                    app(MaintenanceBillingService::class)->settleCoverage($record, self::currentActor(), $reason);
                    Notification::make()->success()->title(__('Covered repair settled'))->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()->danger()->title(__('Unable to settle covered repair'))->body($exception->getMessage())->send();
                }
            });
    }

    private static function createQuotation(): Action
    {
        return Action::make('create_quotation')
            ->label(__('Create Customer Quotation'))
            ->requiresConfirmation()
            ->visible(static fn (MaintenanceRecord $record): bool => in_array($record->status, [
                MaintenanceStatus::AwaitingApproval,
                MaintenanceStatus::Closed,
            ], true)
                && $record->billing_type === MaintenanceBillingType::Unbilled
                && in_array($record->coverage_decision, [
                    WarrantyClaimDecision::PartiallyCovered,
                    WarrantyClaimDecision::Rejected,
                ], true))
            ->authorize(fn (MaintenanceRecord $record): bool => self::currentActor()->can('bill', $record))
            ->action(function (MaintenanceRecord $record): void {
                try {
                    app(MaintenanceBillingService::class)->createQuotation($record, self::currentActor());
                    Notification::make()->success()->title(__('Quotation created'))->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()->danger()->title(__('Unable to create the quotation'))->body($exception->getMessage())->send();
                }
            });
    }

    private static function createInvoice(): Action
    {
        return Action::make('create_invoice')
            ->label(__('Create Final Invoice'))
            ->requiresConfirmation()
            ->visible(static fn (MaintenanceRecord $record): bool => $record->status === MaintenanceStatus::Closed
                && (
                    $record->billing_type === MaintenanceBillingType::Unbilled
                    || (
                        $record->billing_type === MaintenanceBillingType::Quoted
                        && $record->quotation?->status === QuotationStatus::Accepted
                    )
                )
                && ! in_array($record->coverage_decision, [
                    WarrantyClaimDecision::FullyCovered,
                    WarrantyClaimDecision::Goodwill,
                    WarrantyClaimDecision::ThirdPartyWarranty,
                    WarrantyClaimDecision::ServiceContract,
                ], true))
            ->authorize(fn (MaintenanceRecord $record): bool => self::currentActor()->can('bill', $record))
            ->action(function (MaintenanceRecord $record): void {
                try {
                    app(MaintenanceBillingService::class)->createInvoice($record, self::currentActor());
                    Notification::make()->success()->title(__('Invoice created'))->send();
                } catch (ValidationException|DomainException $exception) {
                    Notification::make()->danger()->title(__('Unable to create the invoice'))->body($exception->getMessage())->send();
                }
            });
    }

    private static function reclassifyWarranty(): Action
    {
        return Action::make('reclassify_warranty_billing')
            ->label(__('Reclassify Warranty Billing'))
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->required()->label(__('Reason'))])
            ->visible(static fn (MaintenanceRecord $record): bool => $record->status === MaintenanceStatus::Closed
                && $record->billing_type === MaintenanceBillingType::WarrantyCovered)
            ->authorize(fn (MaintenanceRecord $record): bool => self::currentActor()->can('bill', $record))
            ->action(function (MaintenanceRecord $record, array $data): void {
                $reason = $data['reason'] ?? null;

                if (! is_string($reason) || mb_trim($reason) === '') {
                    Notification::make()->danger()->title(__('Unable to reclassify warranty billing'))->body(__('A reason is required.'))->send();

                    return;
                }

                try {
                    app(MaintenanceBillingService::class)->reclassifyWarrantyForBilling($record, self::currentActor(), $reason);
                    Notification::make()->success()->title(__('Warranty billing reclassified'))->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title(__('Unable to reclassify warranty billing'))->body($domainException->getMessage())->send();
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
