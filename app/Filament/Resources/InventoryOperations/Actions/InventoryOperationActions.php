<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Actions;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Jobs\GeneratePackingListDocument;
use App\Models\InventoryOperation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Shared with {@see ViewDeliveryNote}: a Delivery Note
 * is the same {@see InventoryOperation} record viewed through a different resource, so the packing
 * list action lives here once rather than being duplicated on both view pages.
 */
final class InventoryOperationActions
{
    public static function generatePackingList(): Action
    {
        return Action::make('generate_packing_list')
            ->label(fn (InventoryOperation $record): string => $record->getFirstMedia('packing-list-pdf') instanceof Media
                ? 'Regenerate Packing List'
                : 'Generate Packing List')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->visible(fn (InventoryOperation $record): bool => self::isEligible($record) && self::canGenerate($record))
            ->authorize(fn (InventoryOperation $record): bool => self::canGenerate($record))
            ->action(function (InventoryOperation $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                GeneratePackingListDocument::dispatch($record->id, $actor->id);

                Notification::make()->success()->title('Packing list generation queued.')->send();
            });
    }

    private static function isEligible(InventoryOperation $record): bool
    {
        return $record->operation_type === OperationType::Delivery
            && in_array($record->stage, [OperationStage::Ready, OperationStage::Done], true);
    }

    private static function canGenerate(InventoryOperation $record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('view', $record);
    }
}
