<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds\Pages;

use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewPurchaseInbound extends ViewRecord
{
    protected static string $resource = PurchaseInboundResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [];
    }
}
