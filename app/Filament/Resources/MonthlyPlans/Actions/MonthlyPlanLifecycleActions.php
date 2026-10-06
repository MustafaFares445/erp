<?php

declare(strict_types=1);

namespace App\Filament\Resources\MonthlyPlans\Actions;

use App\Enums\SalesPlanStatus;
use App\Models\SalesPlan;
use App\Services\Employees\SalesPlanService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class MonthlyPlanLifecycleActions
{
    public static function publish(): Action
    {
        return self::transition('publish', __('Publish plan'), SalesPlanStatus::Published, Heroicon::OutlinedPaperAirplane);
    }

    public static function start(): Action
    {
        return self::transition('start', __('Start plan'), SalesPlanStatus::InProgress, Heroicon::OutlinedPlay);
    }

    public static function complete(): Action
    {
        return self::transition('complete', __('Complete'), SalesPlanStatus::Completed, Heroicon::OutlinedCheckCircle);
    }

    public static function archive(): Action
    {
        return self::transition('archive', __('Archive'), SalesPlanStatus::Archived, Heroicon::OutlinedArchiveBox);
    }

    private static function transition(string $name, string $label, SalesPlanStatus $to, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->requiresConfirmation()
            ->authorize('update')
            ->visible(static fn (SalesPlan $record): bool => $record->status->canTransitionTo($to))
            ->action(static function (SalesPlan $record) use ($to): void {
                try {
                    app(SalesPlanService::class)->transition($record, $to);
                } catch (DomainException $domainException) {
                    Notification::make()
                        ->danger()
                        ->title(__('Unable to change the plan status'))
                        ->body($domainException->getMessage())
                        ->send();
                }
            });
    }
}
