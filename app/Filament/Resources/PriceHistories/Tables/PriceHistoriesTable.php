<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceHistories\Tables;

use App\Data\Inventory\VariantPricingData;
use App\Enums\InventoryPermission;
use App\Enums\PriceChangeRequestStatus;
use App\Models\PriceHistory;
use App\Models\User;
use App\Services\Inventory\ProductPricingService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use LogicException;

final class PriceHistoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productVariant.name')
                    ->label('Variant')
                    ->searchable(),
                TextColumn::make('cost_price')->money('USD')->sortable(),
                TextColumn::make('markup_percent')->suffix('%')->sortable(),
                TextColumn::make('base_price')->money('USD')->sortable(),
                TextColumn::make('min_price')->money('USD')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PriceChangeRequestStatus $state): string => match ($state) {
                        PriceChangeRequestStatus::Pending => 'warning',
                        PriceChangeRequestStatus::Approved => 'success',
                        PriceChangeRequestStatus::Rejected => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('changedBy.name')->label('Requested by')->sortable(),
                TextColumn::make('created_at')->label('Requested at')->dateTime()->sortable(),
                TextColumn::make('reviewedBy.name')->label('Reviewed by')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewed_at')->label('Reviewed at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('product_variant_id')
                    ->label('Variant')
                    ->relationship('productVariant', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options([
                        PriceChangeRequestStatus::Pending->value => 'Pending',
                        PriceChangeRequestStatus::Approved->value => 'Approved',
                        PriceChangeRequestStatus::Rejected->value => 'Rejected',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label(__('admin.inventory.pricing.requests.approve'))
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(self::canReview(...))
                    ->authorize(self::canReview(...))
                    ->action(function (PriceHistory $record): void {
                        app(ProductPricingService::class)->approvePriceChangeRequest($record, self::actor());

                        Notification::make()
                            ->title(__('admin.inventory.pricing.requests.approved_notification'))
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label(__('admin.inventory.pricing.requests.reject'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(self::canReview(...))
                    ->authorize(self::canReview(...))
                    ->action(function (PriceHistory $record): void {
                        app(ProductPricingService::class)->rejectPriceChangeRequest($record, self::actor());

                        Notification::make()
                            ->title(__('admin.inventory.pricing.requests.rejected_notification'))
                            ->success()
                            ->send();
                    }),
                Action::make('update')
                    ->label(__('admin.inventory.pricing.requests.update'))
                    ->color('warning')
                    ->visible(self::canReview(...))
                    ->authorize(self::canReview(...))
                    ->schema([
                        TextInput::make('cost_price')->numeric()->minValue(0)->step(0.01),
                        TextInput::make('markup_percent')->numeric()->minValue(0)->maxValue(100)->step(0.01),
                        TextInput::make('min_price')->numeric()->minValue(0)->step(0.01),
                    ])
                    ->fillForm(fn (PriceHistory $record): array => [
                        'cost_price' => $record->cost_price,
                        'markup_percent' => $record->markup_percent,
                        'min_price' => $record->min_price,
                    ])
                    ->action(function (array $data, PriceHistory $record): void {
                        app(ProductPricingService::class)->updatePriceChangeRequest(
                            $record,
                            VariantPricingData::from([
                                'costPrice' => $data['cost_price'] ?? null,
                                'markupPercent' => $data['markup_percent'] ?? null,
                                'minimumPrice' => $data['min_price'] ?? null,
                            ]),
                            self::actor(),
                        );

                        Notification::make()
                            ->title(__('admin.inventory.pricing.requests.updated_notification'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    private static function canReview(PriceHistory $record): bool
    {
        return $record->status === PriceChangeRequestStatus::Pending
            && (auth()->user()?->can(InventoryPermission::PricingReview->value) ?? false);
    }

    private static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated pricing actor is required.');
        }

        return $actor;
    }
}
