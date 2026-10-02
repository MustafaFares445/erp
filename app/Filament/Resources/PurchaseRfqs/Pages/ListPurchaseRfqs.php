<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseRfqs\Pages;

use App\Filament\Resources\PurchaseRfqs\PurchaseRfqResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPurchaseRfqs extends ListRecords
{
    protected static string $resource = PurchaseRfqResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
