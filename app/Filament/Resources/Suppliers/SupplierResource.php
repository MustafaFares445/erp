<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\LocalizedResource as Resource;
use App\Filament\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\Schemas\SupplierInfolist;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

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
            FileUpload::make('logo_path')
                ->label(__('Supplier logo'))
                ->disk('public')
                ->directory('supplier-logos')
                ->visibility('public')
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->imagePreviewHeight('120')
                ->maxSize(3072),
            TextInput::make('name')->label(__('Supplier name'))->required()->maxLength(255),
            TextInput::make('code')->label(__('Supplier code'))->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(50),
            Toggle::make('is_active')
                ->label(__('Active supplier'))
                ->helperText(__('Inactive suppliers remain visible historically but cannot be used for new Purchase Orders.'))
                ->default(true),
            Toggle::make('requires_confirmation')
                ->label(__('Require supplier confirmation by default'))
                ->helperText(__('The value is snapshotted when each Purchase Order is accepted. Changing it later does not change existing accepted POs.'))
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
            ->searchPlaceholder(__('Name, code, email…'))
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('logo_path')->label('')->disk('public')->circular()->imageHeight(40),
                TextColumn::make('name')->label(__('Supplier'))->description(fn (Supplier $record): string => $record->code)->searchable(['name', 'code'])->sortable(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('active_catalog_count')
                    ->label(__('Products'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('open_po_count')
                    ->label(__('Open POs'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('pending_confirmation_count')
                    ->label(__('Awaiting response'))
                    ->badge()
                    ->color(fn (mixed $state): string => is_numeric($state) && (int) $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('last_purchase_at')
                    ->label(__('Last purchase'))
                    ->date()
                    ->placeholder(__('—'))
                    ->sortable(),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Active supplier')),
                TernaryFilter::make('requires_confirmation')->label(__('Confirmation required')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ]);
    }

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'code',
            'email',
            'phone',
        ];
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [CustomFieldsRelationManager::class];
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
                    ->where('confirmation_status', SupplierConfirmationStatus::Pending->value)
                    ->whereHas('purchaseOrder', static fn (Builder $order): Builder => $order->whereNotNull('sent_at')),
            ])
            ->withMax('purchaseOrders as last_purchase_at', 'ordered_at');
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with([
                'activeProductReferencesPreview.productVariant.product',
                'activeProductSupportsPreview.product',
                'activeProductSupportsPreview.productVariant.product',
                'recentPurchaseOrders.lines',
                'confirmations',
                'recentBills',
                'supplierPayments',
            ]);
    }

    private static function canManageSupplierCommercialData(): bool
    {
        return auth()->user()?->can(PurchasePermission::SupplierManage->value) ?? false;
    }
}
