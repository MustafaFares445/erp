<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (Payment $record): bool => ! $record->isPosted()),
            PaymentActions::post(),
            PaymentActions::reverse(),
        ];
    }
}
