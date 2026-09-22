<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTransactions\Pages;

use App\Filament\Resources\PaymentTransactions\Actions\PaymentTransactionActions;
use App\Filament\Resources\PaymentTransactions\PaymentTransactionResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewPaymentTransaction extends ViewRecord
{
    protected static string $resource = PaymentTransactionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            PaymentTransactionActions::refreshStatus(),
            PaymentTransactionActions::retrySettlement(),
        ];
    }
}
