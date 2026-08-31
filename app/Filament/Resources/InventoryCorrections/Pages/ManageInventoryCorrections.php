<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCorrections\Pages;

use App\Filament\Resources\InventoryCorrections\InventoryCorrectionResource;
use App\Models\InventoryCorrection;
use App\Models\InventoryOperation;
use App\Models\User;
use App\Services\Inventory\InventoryCorrectionService;
use DomainException;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use LogicException;

final class ManageInventoryCorrections extends ManageRecords
{
    protected static string $resource = InventoryCorrectionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data): InventoryCorrection {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        throw new LogicException('An authenticated inventory correction actor is required.');
                    }

                    $receiptId = $data['original_inventory_operation_id'] ?? null;
                    $reason = $data['reason'] ?? null;

                    if (! is_numeric($receiptId) || ! is_string($reason)) {
                        throw new DomainException('A completed receipt and correction reason are required.');
                    }

                    $notes = is_string($data['notes'] ?? null) && trim($data['notes']) !== ''
                        ? trim($data['notes'])
                        : null;

                    return app(InventoryCorrectionService::class)->createReceiptCorrection(
                        $actor,
                        InventoryOperation::query()->findOrFail((int) $receiptId),
                        $reason,
                        $notes,
                    );
                })
                ->successRedirectUrl(
                    fn (InventoryCorrection $record): string => InventoryCorrectionResource::getUrl(
                        'view',
                        ['record' => $record],
                    ),
                ),
        ];
    }

    #[\Override]
    public function getSubheading(): string
    {
        return __('admin.inventory.correction.list_notice');
    }
}
