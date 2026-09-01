<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
