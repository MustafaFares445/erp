<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bills\Pages;

use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Models\Bill;
use App\Models\SupplierPayment;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewBill extends ViewRecord
{
    protected static string $resource = BillResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            BillResource::approveAction(),
            Action::make('recordSupplierPayment')
                ->label(__('Record supplier payment'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('primary')
                ->visible(fn (Bill $record): bool => $record->isOpen()
                    && $record->outstandingAmount() > 0.0
                    && (auth()->user()?->can('create', SupplierPayment::class) ?? false))
                ->url(fn (Bill $record): string => SupplierPaymentResource::getUrl('index', [
                    'bill_id' => $record->id,
                ])),
            EditAction::make(),
            BillResource::cancelAction(),
        ];
    }
}
