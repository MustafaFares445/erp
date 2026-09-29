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
        $record = $this->getRecord();

        return $record instanceof Supplier
            ? $record->name
            : 'Supplier';
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof Supplier) {
            return null;
        }

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
                ->label('Create Purchase Order')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('primary')
                ->url(fn (Supplier $record): string => PurchaseOrderResource::getUrl('create', [
                    'supplier_id' => $record->id,
                ])),
            Action::make('supplierProducts')
                ->label('Supplier Products')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->url(fn (Supplier $record): string => SupplierProductReferenceResource::getUrl('index', [
                    'tableFilters' => ['supplier_id' => ['value' => $record->id]],
                ])),
            Action::make('supplierConfirmations')
                ->label('Confirmations')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('gray')
                ->url(fn (Supplier $record): string => SupplierConfirmationResource::getUrl('index', [
                    'tableFilters' => ['supplier_id' => ['value' => $record->id]],
                ])),
            EditAction::make()
                ->label('Edit supplier'),
        ];
    }
}
