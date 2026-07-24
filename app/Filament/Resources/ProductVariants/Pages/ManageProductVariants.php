<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Data\Inventory\PriceFloorOverrideData;
use App\Enums\InventoryPermission;
use App\Enums\UserType;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\ProductPricingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use LogicException;

final class ManageProductVariants extends ManageRecords
{
    protected static string $resource = ProductVariantResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ProductVariantResource::createAction(),
            Action::make('approveFloorOverride')
                ->label('Approve below-floor price')
                ->visible(fn (): bool => ProductVariantResource::canManagePricing())
                ->schema([
                    Select::make('product_variant_id')
                        ->label('Variant')
                        ->options(fn (): array => ProductVariant::query()
                            ->whereNotNull('min_price')
                            ->orderBy('sku')
                            ->pluck('sku', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('attempted_price')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->required(),
                    Select::make('customer_user_id')
                        ->label('Customer')
                        ->options(fn (): array => User::query()
                            ->where('user_type', UserType::Customer->value)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload(),
                    Textarea::make('reason')
                        ->required()
                        ->maxLength(2000),
                ])
                ->action(function (array $data, ProductPricingService $productPricingService): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        throw new LogicException('An authenticated pricing actor is required.');
                    }

                    $productPricingService->approveFloorOverride(
                        approval: PriceFloorOverrideData::from([
                            'productVariantId' => $data['product_variant_id'] ?? null,
                            'customerUserId' => $data['customer_user_id'] ?? null,
                            'attemptedPrice' => $data['attempted_price'] ?? null,
                            'reason' => $data['reason'] ?? null,
                        ]),
                        actor: $actor,
                    );

                    Notification::make()
                        ->title('Price-floor override approved.')
                        ->success()
                        ->send();
                })
                ->authorize(InventoryPermission::PricingManage->value),
        ];
    }
}
