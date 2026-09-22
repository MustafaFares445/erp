<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTransactions\Pages;

use App\Filament\Resources\PaymentTransactions\PaymentTransactionResource;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentTransactions extends ListRecords
{
    protected static string $resource = PaymentTransactionResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [];
    }
}
