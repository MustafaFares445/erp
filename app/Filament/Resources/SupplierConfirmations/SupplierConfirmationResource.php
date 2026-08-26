<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations;

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\SupplierConfirmations\Actions\SupplierConfirmationActions;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every supplier answer in one place, across both document types.
 *
 * Uses the simpler single-page `ManageX` shape rather than the full page set
 * (R-010): a confirmation is a handful of fields with no children, so a view
 * page would show the same thing the row already does.
 *
 * The `confirmable_type` filter is the reason this surface exists at all —
 * without it there is no way to answer "which customer orders are waiting on a
 * supplier right now?" across the whole system.
 *
 * @see /specs/017-purchasing-orders-suppliers/spec.md User Story 4
 */
final class SupplierConfirmationResource extends Resource
{
    protected static ?string $model = SupplierConfirmation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.purchasing';

    protected static ?int $navigationSort = 103;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.supplier_confirmations');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.supplier_confirmations');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')->label(__('admin.purchasing.fields.customer'))
                ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                ->searchable()->preload(),
            Select::make('quotation_id')->label('Quotation')
                ->options(fn (): array => Quotation::query()->orderByDesc('id')->pluck('quotation_number', 'id')->all())
                ->searchable(),
            Select::make('order_id')->label('Sales order')
                ->options(fn (): array => Order::query()->orderByDesc('id')->pluck('order_number', 'id')->all())
                ->searchable(),
            Select::make('purchase_order_id')->label('Purchase order')
                ->options(fn (): array => PurchaseOrder::query()->orderByDesc('id')->pluck('purchase_order_number', 'id')->all())
                ->searchable(),
            Select::make('supplier_id')
                ->label(__('admin.purchasing.fields.supplier'))
                ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->preload()
                ->required(),
            Repeater::make('items')->schema([
                Select::make('product_variant_id')->options(fn (): array => ProductVariant::query()->orderBy('sku')->pluck('sku', 'id')->all())->searchable()->preload()->required(),
                TextInput::make('requested_quantity')->numeric()->minValue(0.001)->required(),
            ])->minItems(1)->required()->columnSpanFull(),
            Textarea::make('notes')
                ->label(__('admin.purchasing.fields.notes'))
                ->rows(3)
                ->maxLength(1000)
                ->columnSpanFull(),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('customer.company_name')->label(__('admin.purchasing.fields.customer'))->placeholder('—'),
                TextColumn::make('supplier.name')
                    ->label(__('admin.purchasing.fields.supplier'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('confirmation_status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (SupplierConfirmationStatus $state): string => $state->label())
                    ->color(static fn (SupplierConfirmationStatus $state): string => match ($state) {
                        SupplierConfirmationStatus::Pending => 'warning',
                        SupplierConfirmationStatus::Partial => 'warning',
                        SupplierConfirmationStatus::Confirmed => 'success',
                        SupplierConfirmationStatus::Rejected => 'danger',
                    }),
                TextColumn::make('promised_at')
                    ->label(__('admin.purchasing.fields.promised_at'))
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('confirmedBy.name')
                    ->label(__('admin.purchasing.fields.confirmed_by'))
                    ->placeholder('—'),
                TextColumn::make('confirmed_at')
                    ->label(__('admin.purchasing.fields.confirmed_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('confirmation_status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->options(static fn (): array => self::statusOptions()),
                SelectFilter::make('supplier_id')
                    ->label(__('admin.purchasing.fields.supplier'))
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                SupplierConfirmationActions::confirm(),
                SupplierConfirmationActions::reject(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageSupplierConfirmations::route('/')];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (SupplierConfirmationStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
