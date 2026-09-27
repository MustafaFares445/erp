<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderDocument;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\PurchaseOrderPolicy;
use App\Services\Documents\DocumentUploadSynchronizer;
use App\Services\Purchasing\PurchaseOrderService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Reachable only for a draft: {@see PurchaseOrderPolicy::update()} refuses an
 * order that has left draft regardless of permission, so the route existing is
 * harmless.
 *
 * Header fields are written through PurchaseOrderService so draft edits use
 * the same supplier, currency, and lifecycle invariants as creation.
 */
final class EditPurchaseOrder extends EditRecord
{
    use InteractsWithPurchasingServices;

    protected static string $resource = PurchaseOrderResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            PurchaseOrderActions::submit(),
            DeleteAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof PurchaseOrder) {
            return parent::handleRecordUpdate($record, $data);
        }

        $documents = $this->extractDocuments($data);
        $actor = self::purchasingActor();

        if (! $actor instanceof User) {
            return $record;
        }

        $record = self::runPurchasingOperation(
            fn (): PurchaseOrder => app(PurchaseOrderService::class)->updateDraft($actor, $record, [
                'supplier_id' => self::integerFrom($data['supplier_id'] ?? $record->supplier_id),
                'currency_code' => self::stringFrom($data['currency_code'] ?? $record->currency_code),
                'ordered_at' => self::stringFrom($data['ordered_at'] ?? $record->ordered_at->toDateString()),
                'expected_at' => self::nullableStringFrom($data['expected_at'] ?? null),
                'notes' => self::nullableStringFrom($data['notes'] ?? null),
            ]),
        );

        $synchronizer = app(DocumentUploadSynchronizer::class);

        foreach ($documents as $collection => $path) {
            $synchronizer->sync($record, $collection, $path, 'purchase-order-documents/');
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function extractDocuments(array &$data): array
    {
        $documents = [];

        foreach (PurchaseOrderDocument::cases() as $document) {
            $value = $data[$document->value] ?? null;
            unset($data[$document->value]);

            if (is_array($value) && is_string($path = array_values($value)[0] ?? null)) {
                $documents[$document->value] = $path;
            }
        }

        return $documents;
    }
}
