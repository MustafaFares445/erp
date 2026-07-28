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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('discount_percent')->numeric()->minValue(0)->maxValue(100)->step(0.01)->required()
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Set the percentage discount that applies when this pricing tier is selected.'),
            Select::make('customer_user_id')
                ->relationship(
                    name: 'customer',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->where('user_type', UserType::Customer->value),
                )
                ->searchable()
                ->preload()
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Assign this tier to one customer when their pricing should override the general tier.'),
            Toggle::make('is_active')->default(true)
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Inactive tiers remain in history but are not applied to new pricing.'),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('discount_percent')->suffix('%')->sortable(),
            TextColumn::make('customer.name')->label('Customer')->searchable(),
            ToggleColumn::make('is_active'),
        ])->filters([TernaryFilter::make('is_active'), TrashedFilter::make()])
            ->recordActions([
                self::editAction(),
                DeleteAction::make()
                    ->using(static fn (PricingTier $record, ProductPricingService $productPricingService): bool => $productPricingService->deleteTier($record, self::actor())),
                RestoreAction::make()
                    ->using(static fn (PricingTier $record, ProductPricingService $productPricingService): bool => $productPricingService->restoreTier($record, self::actor())),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(static fn (array $data, ProductPricingService $productPricingService): Model => $productPricingService->saveTier(
                tier: null,
                pricingTier: PricingTierData::from([
                    'name' => $data['name'] ?? null,
                    'discountPercent' => $data['discount_percent'] ?? null,
                    'customerUserId' => $data['customer_user_id'] ?? null,
                    'isActive' => $data['is_active'] ?? false,
                ]),
                actor: self::actor(),
            ));
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()
            ->using(static fn (PricingTier $record, array $data, ProductPricingService $productPricingService): Model => $productPricingService->saveTier(
                tier: $record,
                pricingTier: PricingTierData::from([
                    'name' => $data['name'] ?? null,
                    'discountPercent' => $data['discount_percent'] ?? null,
                    'customerUserId' => $data['customer_user_id'] ?? null,
                    'isActive' => $data['is_active'] ?? false,
                ]),
                actor: self::actor(),
            ));
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
