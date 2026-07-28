<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use App\Models\User;
use App\Services\Inventory\InventoryOperationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use LogicException;

final class ViewInventoryOperation extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = InventoryOperationResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
            $this->transitionAction('markReady', 'ready', 'admin.inventory.operation.notifications.ready'),
            $this->transitionAction('dispatch', 'dispatch', 'admin.inventory.operation.notifications.dispatched'),
            $this->transitionAction('complete', 'complete', 'admin.inventory.operation.notifications.completed'),
            $this->transitionAction('cancel', 'cancel', 'admin.inventory.operation.notifications.canceled'),
        ];
    }

    private function transitionAction(string $ability, string $method, string $notification): Action
    {
        return Action::make($ability)
            ->label(Str::headline($ability))
            ->visible(fn (InventoryOperation $record): bool => auth()->user()?->can($ability, $record) ?? false)
            ->authorize(fn (InventoryOperation $record): bool => auth()->user()?->can($ability, $record) ?? false)
            ->action(function (InventoryOperation $record) use ($method, $notification): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated inventory operation actor is required.');
                }

                $service = app(InventoryOperationService::class);

                $this->runInventoryOperation(
                    fn (): InventoryOperation => match ($method) {
                        'ready' => $service->markReady($record),
                        'dispatch' => $service->dispatch($record, $actor),
                        'complete' => $service->complete($record, $actor),
                        'cancel' => $service->cancel($record, $actor, 'Canceled from the inventory operation screen.'),
                        default => throw new LogicException(sprintf('Unknown inventory operation transition [%s].', $method)),
                    },
                    $notification,
                );
            });
    }

    private function previewDescription(InventoryOperation $record): HtmlString
    {
        $effects = app(InventoryOperationService::class)->previewEffect($record);

        if ($effects === []) {
            return new HtmlString(__('admin.inventory.operation.confirm_preview_notice'));
        }

        $lines = array_map(
            fn (array $effect): string => e(sprintf('#%d: %s → %s', $effect['product_variant_id'], $effect['before'], $effect['after'])),
            $effects,
        );

        return new HtmlString(__('admin.inventory.operation.confirm_preview_notice').'<br><br>'.implode('<br>', $lines));
    }
}
