<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductSubscriptions\Pages;

use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Models\ProductSubscription;
use App\Services\Crm\ProductSubscriptionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewProductSubscription extends ViewRecord
{
    protected static string $resource = ProductSubscriptionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('activate')
                ->visible(fn (ProductSubscription $record): bool => ! $record->is_active)
                ->action(static fn (ProductSubscription $record, ProductSubscriptionService $service): ProductSubscription => $service->activate($record, ProductSubscriptionResource::actor())),
            Action::make('deactivate')
                ->visible(fn (ProductSubscription $record): bool => $record->is_active)
                ->action(static fn (ProductSubscription $record, ProductSubscriptionService $service): ProductSubscription => $service->deactivate($record, ProductSubscriptionResource::actor())),
        ];
    }
}
