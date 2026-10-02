<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseAgreements\Pages;

use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPurchaseAgreements extends ListRecords
{
    protected static string $resource = PurchaseAgreementResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
