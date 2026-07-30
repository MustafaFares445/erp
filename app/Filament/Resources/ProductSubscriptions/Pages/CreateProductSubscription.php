<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductSubscriptions\Pages;

use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Services\Crm\ProductSubscriptionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateProductSubscription extends CreateRecord
{
    protected static string $resource = ProductSubscriptionResource::class;

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return app(ProductSubscriptionService::class)->create($data, [], [], ProductSubscriptionResource::actor());
    }
}
