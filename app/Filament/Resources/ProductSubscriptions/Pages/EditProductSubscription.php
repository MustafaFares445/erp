<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductSubscriptions\Pages;

use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Models\ProductSubscription;
use App\Services\Crm\ProductSubscriptionService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditProductSubscription extends EditRecord
{
    protected static string $resource = ProductSubscriptionResource::class;

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof ProductSubscription) {
            return parent::handleRecordUpdate($record, $data);
        }

        return app(ProductSubscriptionService::class)->update($record, $data, ProductSubscriptionResource::actor());
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()->using(static function (ProductSubscription $record, ProductSubscriptionService $service): bool {
                $service->delete($record, ProductSubscriptionResource::actor());

                return true;
            }),
            RestoreAction::make()->using(static fn (ProductSubscription $record, ProductSubscriptionService $service): ProductSubscription => $service->restore($record, ProductSubscriptionResource::actor())),
        ];
    }
}
