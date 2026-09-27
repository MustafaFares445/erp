<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\Schemas\SupplierInfolist;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.suppliers');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Supplier name')->required()->maxLength(255),
            TextInput::make('code')->label('Supplier code')->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(50),
            Toggle::make('is_active')
                ->label('Active supplier')
                ->helperText('Inactive suppliers remain visible historically but cannot be used for new Purchase Orders.')
                ->default(true),
            Toggle::make('requires_confirmation')
                ->label('Require supplier confirmation by default')
                ->helperText('The value is snapshotted when each Purchase Order is accepted. Changing it later does not change existing accepted POs.')
                ->default(false)
                ->visible(fn (): bool => self::canManageSupplierCommercialData()),
            Textarea::make('address')->columnSpanFull(),
        ])->columns(2);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return SupplierInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('code')->label('Code')->searchable()->sortable(),
                TextColumn::make('name')->label('Supplier')->searchable()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('confirmation_policy')
                    ->label('Confirmation policy')
                    ->state(fn (Supplier $record): string => $record->requires_confirmation ? 'Required' : 'Not required')
                    ->badge()
                    ->color(fn (Supplier $record): string => $record->requires_confirmation ? 'warning' : 'gray'),
                TextColumn::make('active_catalog_count')
                    ->label('Catalog items')
                    ->badge()
                    ->sortable(),
                TextColumn::make('open_po_count')
                    ->label('Open POs')
                    ->badge()
                    ->sortable(),
                TextColumn::make('pending_confirmation_count')
                    ->label('Awaiting response')
                    ->badge()
                    ->color(fn (mixed $state): string => is_numeric($state) && (int) $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('last_purchase_at')
                    ->label('Last purchase')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active supplier'),
                TernaryFilter::make('requires_confirmation')->label('Confirmation required'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageSuppliers::route('/'),
            'view' => ViewSupplier::route('/{record}'),
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        $terminal = [
            PurchaseOrderStatus::Received->value,
            PurchaseOrderStatus::Closed->value,
            PurchaseOrderStatus::Cancelled->value,
        ];

        return parent::getEloquentQuery()
            ->withCount([
                'productReferences as active_catalog_count' => static fn (Builder $query): Builder => $query->where('is_active', true),
                'purchaseOrders as open_po_count' => static fn (Builder $query): Builder => $query->whereNotIn('status', $terminal),
                'confirmations as pending_confirmation_count' => static fn (Builder $query): Builder => $query
                    ->where('confirmation_status', SupplierConfirmationStatus::Pending->value),
            ])
            ->withMax('purchaseOrders as last_purchase_at', 'ordered_at');
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with([
                'productReferences.productVariant.product',
                'productSupports.product',
                'productSupports.productVariant.product',
                'purchaseOrders.lines',
                'confirmations',
                'bills',
                'supplierPayments',
            ]);
    }

    private static function canManageSupplierCommercialData(): bool
    {
        return auth()->user()?->can(PurchasePermission::SupplierManage->value) ?? false;
    }
}
