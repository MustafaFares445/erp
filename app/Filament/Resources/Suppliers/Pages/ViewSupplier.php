<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\SupplierProductReferences\SupplierProductReferenceResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    #[\Override]
    public function getTitle(): string
    {
        /** @var Supplier $record */
        $record = $this->getRecord();

        return $record->name;
    }

    #[\Override]
    public function getSubheading(): string
    {
        /** @var Supplier $record */
        $record = $this->getRecord();

        return sprintf(
            '%s · %s · Confirmation %s',
            $record->code,
            $record->is_active ? 'Active' : 'Inactive',
            $record->requires_confirmation ? 'required' : 'not required',
        );
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createPurchaseOrder')
                ->label(__('Create Purchase Order'))
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('primary')
                ->url(fn (Supplier $record): string => PurchaseOrderResource::getUrl('create', [
                    'supplier_id' => $record->id,
                ])),
            Action::make('supplierProducts')
                ->label(__('Supplier Products'))
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->url(fn (Supplier $record): string => SupplierProductReferenceResource::getUrl('index', [
                    'tableFilters' => ['supplier_id' => ['value' => $record->id]],
                ])),
            Action::make('supplierConfirmations')
                ->label(__('Confirmations'))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('gray')
                ->url(fn (Supplier $record): string => SupplierConfirmationResource::getUrl('index', [
                    'tableFilters' => ['supplier_id' => ['value' => $record->id]],
                ])),
            EditAction::make()
                ->label(__('Edit supplier')),
        ];
    }
}
