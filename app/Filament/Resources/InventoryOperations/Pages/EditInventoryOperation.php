<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Enums\DeliveryDocument;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use App\Services\Inventory\DeliveryDocumentSynchronizer;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditInventoryOperation extends EditRecord
{
    protected static string $resource = InventoryOperationResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
        ];
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof InventoryOperation) {
            return parent::handleRecordUpdate($record, $data);
        }

        $documents = $this->extractDeliveryDocuments($data);
        $record->update($data);
        $synchronizer = app(DeliveryDocumentSynchronizer::class);

        foreach ($documents as $collection => $path) {
            $synchronizer->sync($record, $collection, $path);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function extractDeliveryDocuments(array &$data): array
    {
        $documents = [];

        foreach (DeliveryDocument::cases() as $document) {
            $value = $data[$document->value] ?? null;
            unset($data[$document->value]);

            if (is_string($value)) {
                $documents[$document->value] = $value;
            }
        }

        return $documents;
    }
}
