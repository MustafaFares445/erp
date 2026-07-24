<?php

declare(strict_types=1);

namespace App\Filament\Resources\PricingTiers;

use App\Data\Inventory\PricingTierData;
use App\Enums\UserType;
use App\Filament\Resources\PricingTiers\Pages\ManagePricingTiers;
use App\Models\PricingTier;
use App\Models\User;
use App\Services\Inventory\ProductPricingService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LogicException;

final class PricingTierResource extends Resource
{
    protected static ?string $model = PricingTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('discount_percent')->numeric()->minValue(0)->maxValue(100)->step(0.01)->required(),
            Select::make('customer_user_id')
                ->relationship(
                    name: 'customer',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->where('user_type', UserType::Customer->value),
                )
                ->searchable()
                ->preload(),
            Toggle::make('is_active')->default(true),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('discount_percent')->suffix('%')->sortable(),
            TextColumn::make('customer.name')->label('Customer')->searchable(),
            IconColumn::make('is_active')->boolean(),
        ])->filters([TernaryFilter::make('is_active'), TrashedFilter::make()])
            ->recordActions([
                self::editAction(),
                DeleteAction::make()
                    ->using(static function (Model $record, ProductPricingService $productPricingService): bool {
                        if (! $record instanceof PricingTier) {
                            throw new LogicException('The pricing action requires a pricing tier.');
                        }

                        return $productPricingService->deleteTier($record, self::actor());
                    }),
                RestoreAction::make()
                    ->using(static function (Model $record, ProductPricingService $productPricingService): bool {
                        if (! $record instanceof PricingTier) {
                            throw new LogicException('The pricing action requires a pricing tier.');
                        }

                        return $productPricingService->restoreTier($record, self::actor());
                    }),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(static function (array $data, ProductPricingService $productPricingService): Model {
                return $productPricingService->saveTier(
                    tier: null,
                    pricingTier: PricingTierData::from([
                        'name' => $data['name'] ?? null,
                        'discountPercent' => $data['discount_percent'] ?? null,
                        'customerUserId' => $data['customer_user_id'] ?? null,
                        'isActive' => $data['is_active'] ?? false,
                    ]),
                    actor: self::actor(),
                );
            });
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()
            ->using(static function (Model $record, array $data, ProductPricingService $productPricingService): Model {
                if (! $record instanceof PricingTier) {
                    throw new LogicException('The pricing action requires a pricing tier.');
                }

                return $productPricingService->saveTier(
                    tier: $record,
                    pricingTier: PricingTierData::from([
                        'name' => $data['name'] ?? null,
                        'discountPercent' => $data['discount_percent'] ?? null,
                        'customerUserId' => $data['customer_user_id'] ?? null,
                        'isActive' => $data['is_active'] ?? false,
                    ]),
                    actor: self::actor(),
                );
            });
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManagePricingTiers::route('/')];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
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
