<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests\Actions;

use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\Support\MaintenanceRecordService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use LogicException;

/**
 * Workflow status actions shared by the maintenance detail page and the list
 * table. Every action delegates to {@see MaintenanceRecordService::transition()}.
 */
final class MaintenanceTransitionActions
{
    public static function transition(string $name, string $label, MaintenanceStatus $to): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowRight)
            ->authorize('transition')
            ->requiresConfirmation()
            ->action(static function (MaintenanceRecord $record) use ($to): void {
                try {
                    app(MaintenanceRecordService::class)->transition($record, $to, self::currentActor());
                    Notification::make()->success()->title(__('Maintenance request updated'))->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title(__('Unable to change maintenance status'))->body($domainException->getMessage())->send();
                }
            });
    }

    public static function customerApproval(string $name = 'customerApprovedRepair', ?string $label = null): Action
    {
        return Action::make($name)
            ->label($label ?? __('Customer Approved — Ready for Repair'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('transition')
            ->requiresConfirmation()
            ->visible(static fn (MaintenanceRecord $record): bool => $record->status === MaintenanceStatus::AwaitingApproval)
            ->action(static function (MaintenanceRecord $record): void {
                try {
                    $customerAmount = (int) $record->coverageLines()->sum('customer_amount_minor');
                    $record->loadMissing('quotation');

                    if ($customerAmount > 0 && $record->quotation?->status !== QuotationStatus::Accepted) {
                        throw new DomainException('The customer quotation must be accepted before repair can begin.');
                    }

                    app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::ReadyForRepair, self::currentActor());
                    Notification::make()->success()->title(__('Repair approved and ready to start'))->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title(__('Repair cannot start yet'))->body($domainException->getMessage())->send();
                }
            });
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
