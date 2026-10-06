<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Actions;

use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Models\InventoryAdjustment;
use App\Models\User;
use App\Services\Inventory\InventoryAdjustmentService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;
use LogicException;

/**
 * Adjustment workflow actions shared by the list table and the detail page.
 * Thin adapters over {@see InventoryAdjustmentService}; they compute nothing
 * and never edit an adjustment's status directly.
 */
final class AdjustmentActions
{
    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label(__('admin.inventory.adjustment.confirm'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize(static fn (InventoryAdjustment $record): bool => self::actor()?->can('confirm', $record) ?? false)
            ->visible(static fn (InventoryAdjustment $record): bool => $record->isDraft()
                && (self::actor()?->can('confirm', $record) ?? false))
            ->requiresConfirmation()
            ->modalDescription(__('Confirming this adjustment posts inventory movements. The user who created the adjustment cannot confirm their own work.'))
            ->action(static function (InventoryAdjustment $record): void {
                $actor = self::actor();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated User is required.');
                }

                try {
                    app(InventoryAdjustmentService::class)->confirm($record, $actor);
                } catch (DomainException $domainException) {
                    self::notifyFailure($domainException->getMessage());

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('admin.inventory.adjustment.notifications.confirmed'))
                    ->send();
            });
    }

    public static function createCorrection(): Action
    {
        return Action::make('createCorrection')
            ->label(__('admin.inventory.adjustment.actions.create_correction'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(static fn (InventoryAdjustment $record): bool => $record->isConfirmed()
                && (self::actor()?->can('create', InventoryAdjustment::class) ?? false))
            ->authorize(static fn (): bool => self::actor()?->can('create', InventoryAdjustment::class) ?? false)
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.inventory.adjustment.reason'))
                    ->required()
                    ->maxLength(1_000),
            ])
            ->action(static function (InventoryAdjustment $record, array $data, Component $livewire): void {
                $actor = self::actor();
                $reason = $data['reason'] ?? null;

                if (! $actor instanceof User || ! is_string($reason)) {
                    throw new LogicException('An authenticated actor and correction reason are required.');
                }

                $correction = app(InventoryAdjustmentService::class)->createCorrection($record, $actor, $reason);

                $livewire->redirect(AdjustmentResource::getUrl('edit', ['record' => $correction]));
            });
    }

    private static function actor(): ?User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : null;
    }

    private static function notifyFailure(string $message): void
    {
        Notification::make()
            ->danger()
            ->title(__('admin.inventory.notifications.error'))
            ->body($message)
            ->send();
    }
}
