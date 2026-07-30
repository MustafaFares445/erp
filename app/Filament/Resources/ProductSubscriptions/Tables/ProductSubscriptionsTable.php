<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductSubscriptions\Tables;

use App\Enums\ProductSubscriptionVisibility;
use App\Filament\Resources\ProductSubscriptions\ProductSubscriptionResource;
use App\Models\ProductSubscription;
use App\Services\Crm\ProductSubscriptionService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ProductSubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('discount_type'),
                TextColumn::make('discount_value')->numeric(decimalPlaces: 2)->label('Discount'),
                TextColumn::make('visibility')->badge(),
                TextColumn::make('status')->state(fn (ProductSubscription $record): string => $record->status())->badge(),
                TextColumn::make('valid_from')->date()->sortable(),
                TextColumn::make('valid_until')->date()->sortable(),
                TextColumn::make('products_count')->label('Products')->sortable(),
                TextColumn::make('active_customer_profiles_count')->label('Assigned active customers')->sortable(),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('visibility')->options([
                    ProductSubscriptionVisibility::Public->value => 'Public',
                    ProductSubscriptionVisibility::Restricted->value => 'Restricted',
                ]),
                TernaryFilter::make('is_active')->label('Active'),
                Filter::make('status')
                    ->schema([Select::make('value')->options([
                        'scheduled' => 'Scheduled',
                        'current' => 'Current',
                        'expired' => 'Expired',
                    ])])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;

                        if ($status === 'scheduled') {
                            return $query->where('is_active', true)->whereDate('valid_from', '>', today());
                        }

                        if ($status === 'current') {
                            return $query
                                ->where('is_active', true)
                                ->where(fn (Builder $dateQuery): Builder => $dateQuery->whereNull('valid_from')->orWhereDate('valid_from', '<=', today()))
                                ->where(fn (Builder $dateQuery): Builder => $dateQuery->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
                        }

                        if ($status === 'expired') {
                            return $query->where('is_active', true)->whereDate('valid_until', '<', today());
                        }

                        return $query;
                    }),
                Filter::make('near_expiry')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (is_string($from)) {
                            $query->whereDate('valid_until', '>=', $from);
                        }

                        if (is_string($until)) {
                            $query->whereDate('valid_until', '<=', $until);
                        }

                        return $query;
                    }),
                SelectFilter::make('product')->relationship('products', 'name')->searchable()->preload(),
                SelectFilter::make('customer')->relationship('customerProfiles', 'customer_code')->searchable()->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('activate')
                    ->visible(fn (ProductSubscription $record): bool => ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(static fn (ProductSubscription $record, ProductSubscriptionService $service): ProductSubscription => $service->activate($record, ProductSubscriptionResource::actor())),
                Action::make('deactivate')
                    ->visible(fn (ProductSubscription $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->action(static fn (ProductSubscription $record, ProductSubscriptionService $service): ProductSubscription => $service->deactivate($record, ProductSubscriptionResource::actor())),
                DeleteAction::make()
                    ->using(static function (ProductSubscription $record, ProductSubscriptionService $service): bool {
                        $service->delete($record, ProductSubscriptionResource::actor());

                        return true;
                    }),
                RestoreAction::make()
                    ->using(static fn (ProductSubscription $record, ProductSubscriptionService $service): ProductSubscription => $service->restore($record, ProductSubscriptionResource::actor())),
            ])
            ->paginated([25, 50]);
    }
}
