<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bills;

use App\Filament\Resources\Bills\Pages\EditBill;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Filament\Resources\Bills\Pages\ViewBill;
use App\Filament\Resources\Bills\Schemas\BillInfolist;
use App\Models\Bill;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use LogicException;
use UnitEnum;

final class BillResource extends Resource
{
    protected static ?string $model = Bill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 206;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.bills');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('bill_number')->label('Bill number')->disabled()->dehydrated(false),
            Select::make('supplier_id')
                ->relationship('supplier', 'name')
                ->searchable()
                ->preload()
                ->live()
                ->required(),
            TextInput::make('supplier_reference')
                ->label('Supplier invoice reference')
                ->required()
                ->maxLength(100)
                ->unique(
                    table: Bill::class,
                    column: 'supplier_reference',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where(
                        'supplier_id',
                        is_numeric($get('supplier_id')) ? (int) $get('supplier_id') : 0,
                    ),
                )
                ->helperText('Required duplicate-payment control: this reference cannot be reused for the same supplier.'),
            Select::make('purchase_order_id')->relationship('purchaseOrder', 'purchase_order_number')->searchable()->preload()->live(),
            Select::make('payment_term_id')->relationship('paymentTerm', 'name')->searchable()->preload(),
            DatePicker::make('bill_date')->required(),
            DatePicker::make('due_date'),
            TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('subtotal')->numeric()->minValue(0)->step(0.01)->required(),
            TextInput::make('tax_total')->numeric()->minValue(0)->step(0.01)->default(0)->required(),
            TextInput::make('total_amount')->numeric()->minValue(0.01)->step(0.01)->required(),
            Repeater::make('lines')
                ->relationship()
                ->schema([
                    Select::make('purchase_order_line_id')
                        ->label('Purchase order line')
                        ->options(fn (Get $get): array => self::purchaseOrderLineOptions($get('../../purchase_order_id')))
                        ->searchable()
                        ->live(),
                    Select::make('chart_account_id')
                        ->relationship('chartAccount', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->where('is_postable', true)
                            ->where('is_active', true)
                            ->where('code', '!=', '1300'))
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('description')->required(),
                    TextInput::make('quantity')->numeric()->minValue(0.001)->step(0.001)->required()->default(1),
                    TextInput::make('unit_price')->numeric()->minValue(0.01)->step(0.01)->required(),
                    TextInput::make('tax_amount')->numeric()->minValue(0)->step(0.01)->required()->default(0),
                    TextInput::make('line_total')->numeric()->minValue(0.01)->step(0.01)->required(),
                ])
                ->columns(3)
                ->minItems(1)
                ->columnSpanFull(),
            Textarea::make('notes')->columnSpanFull(),
        ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return BillInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('bill_date', 'desc')
            ->columns([
                TextColumn::make('bill_number')->searchable()->sortable(),
                TextColumn::make('supplier.name')->searchable()->sortable(),
                TextColumn::make('supplier_reference')
                    ->label('Supplier reference')
                    ->searchable(),
                TextColumn::make('supplier_reference_source')
                    ->label('Reference evidence')
                    ->state(fn (Bill $record): string => $record->supplier_reference_backfilled_at === null
                        ? 'Supplier provided'
                        : 'Backfilled reference')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Backfilled reference' ? 'warning' : 'success'),
                TextColumn::make('purchaseOrder.purchase_order_number')->label('Purchase order')->searchable(),
                TextColumn::make('description')->searchable()->limit(40),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('total_amount')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('amount_paid')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                self::approveAction(),
                self::cancelAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageBills::route('/'),
            'view' => ViewBill::route('/{record}'),
            'edit' => EditBill::route('/{record}/edit'),
        ];
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->visible(fn (Bill $record): bool => $record->isDraft())
            ->authorize('approve')
            ->requiresConfirmation()
            ->action(function (Bill $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                app(AccountingDocumentService::class)->approveBill($actor, $record);
            });
    }

    /** @return array<int, string> */
    private static function purchaseOrderLineOptions(mixed $purchaseOrderId): array
    {
        if (! is_numeric($purchaseOrderId)) {
            return [];
        }

        return PurchaseOrderLine::query()
            ->with(['productVariant', 'unit'])
            ->where('purchase_order_id', (int) $purchaseOrderId)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(static fn (PurchaseOrderLine $line): array => [
                $line->id => sprintf(
                    '%s — ordered %s %s',
                    $line->productVariant->sku,
                    $line->quantity_ordered,
                    $line->unit->name,
                ),
            ])
            ->all();
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel draft bill')
            ->visible(fn (Bill $record): bool => $record->isDraft())
            ->authorize('update')
            ->requiresConfirmation()
            ->action(function (Bill $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                app(AccountingDocumentService::class)->cancelBill($actor, $record);
            });
    }
}
