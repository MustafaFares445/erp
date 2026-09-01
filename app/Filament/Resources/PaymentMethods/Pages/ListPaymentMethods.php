<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPaymentMethods extends ListRecords
{
    protected static string $resource = PaymentMethodResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
