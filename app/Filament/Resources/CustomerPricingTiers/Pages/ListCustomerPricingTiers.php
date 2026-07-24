<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerPricingTiers\Pages;

use App\Data\Inventory\CustomerTierAssignmentData;
use App\Enums\InventoryPermission;
use App\Enums\UserType;
use App\Filament\Resources\CustomerPricingTiers\CustomerPricingTierResource;
use App\Models\PricingTier;
use App\Models\User;
use App\Services\Inventory\ProductPricingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use LogicException;

final class ListCustomerPricingTiers extends ListRecords
{
    protected static string $resource = CustomerPricingTierResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('assignGeneralTier')
                ->label('Assign general tier')
                ->visible(fn (): bool => CustomerPricingTierResource::canCreate())
                ->schema([
                    Select::make('customer_user_id')
                        ->label('Customer')
                        ->options(fn (): array => User::query()
                            ->where('user_type', UserType::Customer->value)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                    Select::make('pricing_tier_id')
                        ->label('General tier')
                        ->options(fn (): array => PricingTier::query()
                            ->whereNull('customer_user_id')
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data, ProductPricingService $productPricingService): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        throw new LogicException('An authenticated pricing actor is required.');
                    }

                    $assignment = CustomerTierAssignmentData::from([
                        'customerUserId' => $data['customer_user_id'] ?? null,
                        'pricingTierId' => $data['pricing_tier_id'] ?? null,
                    ]);
                    $customer = User::query()
                        ->where('user_type', UserType::Customer->value)
                        ->findOrFail($assignment->customerUserId);
                    $pricingTier = PricingTier::query()
                        ->whereNull('customer_user_id')
                        ->where('is_active', true)
                        ->findOrFail($assignment->pricingTierId);

                    $productPricingService->assignGeneralTier($customer, $pricingTier, $actor);

                    Notification::make()
                        ->title('Customer pricing tier assigned.')
                        ->success()
                        ->send();
                })
                ->authorize(InventoryPermission::PricingManage->value),
        ];
    }
}
