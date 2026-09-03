<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierPayments;

use App\Enums\BillStatus;
use App\Enums\SupplierPaymentStatus;
use App\Filament\Resources\SupplierPayments\Pages\EditSupplierPayment;
use App\Filament\Resources\SupplierPayments\Pages\ManageSupplierPayments;
use App\Models\Bill;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use UnitEnum;

final class SupplierPaymentResource extends Resource
{
    protected static ?string $model = SupplierPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 209;

    #[\Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('supplier_payment_number')->label('Supplier payment number')->disabled()->dehydrated(false),
            Select::make('supplier_id')->relationship('supplier', 'name')->searchable()->preload()->required(),
            Select::make('payment_method_id')
                ->relationship('paymentMethod', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true))
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('amount')->numeric()->minValue(0.01)->step(0.01)->required(),
            DatePicker::make('payment_date')->required(),
            TextInput::make('reference')->maxLength(150),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('payment_date', 'desc')
            ->columns([
                TextColumn::make('supplier_payment_number')->searchable()->sortable(),
                TextColumn::make('supplier.name')->searchable()->sortable(),
                TextColumn::make('paymentMethod.name')->label('Payment method'),
                TextColumn::make('payment_date')->date()->sortable(),
                TextColumn::make('amount')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SupplierPaymentStatus $state): string => $state->label())
                    ->color(fn (SupplierPaymentStatus $state): string => $state->color())
                    ->sortable(),
            ])
            ->recordActions([
                self::payAction(),
                self::cancelAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageSupplierPayments::route('/'),
            'edit' => EditSupplierPayment::route('/{record}/edit'),
        ];
    }

    private static function payAction(): Action
    {
        return Action::make('pay')
            ->visible(fn (SupplierPayment $record): bool => $record->isDraft())
            ->authorize('pay')
            ->requiresConfirmation()
            ->form([
                Repeater::make('allocations')
                    ->schema([
                        Select::make('bill_id')
                            ->options(fn (): array => Bill::query()
                                ->whereIn('status', [BillStatus::Approved->value, BillStatus::PartiallyPaid->value])
                                ->orderBy('bill_number')
                                ->pluck('bill_number', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('amount')->numeric()->minValue(0.01)->step(0.01)->required(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->required(),
            ])
            ->action(function (SupplierPayment $record, array $data): void {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                $allocations = [];
                $rawAllocations = $data['allocations'] ?? [];
                if (is_array($rawAllocations)) {
                    foreach ($rawAllocations as $allocation) {
                        if (! is_array($allocation)) {
                            continue;
                        }

                        $allocations[] = [
                            'bill_id' => $allocation['bill_id'] ?? null,
                            'amount' => $allocation['amount'] ?? null,
                        ];
                    }
                }

                app(AccountingDocumentService::class)->paySupplierPayment($actor, $record, $allocations);
            });
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel draft payment')
            ->visible(fn (SupplierPayment $record): bool => $record->isDraft())
            ->authorize('update')
            ->requiresConfirmation()
            ->action(function (SupplierPayment $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                app(AccountingDocumentService::class)->cancelSupplierPayment($actor, $record);
            });
    }
}
