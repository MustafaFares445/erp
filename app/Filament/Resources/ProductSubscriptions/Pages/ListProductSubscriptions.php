<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductSubscriptions\Pages;

use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Models\ProductSubscription;
use App\Services\Crm\ProductSubscriptionService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListProductSubscriptions extends ListRecords
{
    protected static string $resource = ProductSubscriptionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(
                /** @param array<mixed> $data */
                static fn (array $data): ProductSubscription => app(ProductSubscriptionService::class)->create($data, [], [], ProductSubscriptionResource::actor()),
            ),
        ];
    }
}
