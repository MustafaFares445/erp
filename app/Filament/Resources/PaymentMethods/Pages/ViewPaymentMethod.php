<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewPaymentMethod extends ViewRecord
{
    protected static string $resource = PaymentMethodResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
