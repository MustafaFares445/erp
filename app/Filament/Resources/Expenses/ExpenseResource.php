<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Models\Expense;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use UnitEnum;

final class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 207;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.expenses');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('expense_number')->label('Expense number')->disabled()->dehydrated(false),
            Select::make('supplier_id')->relationship('supplier', 'name')->searchable()->preload(),
            Select::make('requested_by')->relationship('requestedBy', 'employee_code')->searchable()->preload(),
            TextInput::make('merchant_name')->maxLength(255),
            Select::make('expense_account_id')
                ->relationship('expenseAccount', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query
                    ->where('is_postable', true)
                    ->where('is_active', true))
                ->searchable()
                ->preload()
                ->required(),
            Select::make('payment_method_id')
                ->relationship('paymentMethod', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true))
                ->searchable()
                ->preload()
                ->required(),
            DatePicker::make('expense_date')->required(),
            DatePicker::make('due_date'),
            TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('subtotal')->numeric()->minValue(0)->step(0.01)->required(),
            TextInput::make('tax_total')->numeric()->minValue(0)->step(0.01)->default(0)->required(),
            TextInput::make('total_amount')->numeric()->minValue(0.01)->step(0.01)->required(),
            FileUpload::make('receipt')
                ->label('Receipt')
                ->disk('local')
                ->directory('expense-receipts')
                ->visibility('private')
                ->maxSize(10240)
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                ->preventFilePathTampering(
                    allowFilePathUsing: static function (?Expense $record, string $file): bool {
                        return $record instanceof Expense
                            && $record->getFirstMedia('receipt')?->getPathRelativeToRoot() === $file;
                    },
                )
                ->afterStateHydrated(static function (FileUpload $component, ?Expense $record): void {
                    if (! $record instanceof Expense) {
                        return;
                    }

                    $path = $record->getFirstMedia('receipt')?->getPathRelativeToRoot();
                    $component->state($path);
                }),
            Textarea::make('notes')->columnSpanFull(),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('expense_date', 'desc')
            ->columns([
                TextColumn::make('expense_number')->searchable()->sortable(),
                TextColumn::make('merchant_name')->label('Merchant')->searchable(),
                TextColumn::make('supplier.name')->searchable(),
                TextColumn::make('description')->searchable()->limit(40),
                TextColumn::make('total_amount')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('amount_paid')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->recordActions([
                self::approveAction(),
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
            'index' => ManageExpenses::route('/'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->visible(fn (Expense $record): bool => $record->isDraft())
            ->authorize('approve')
            ->requiresConfirmation()
            ->action(function (Expense $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                app(AccountingDocumentService::class)->approveExpense($actor, $record);
            });
    }

    private static function payAction(): Action
    {
        return Action::make('pay')
            ->visible(fn (Expense $record): bool => $record->status === 'approved')
            ->authorize('pay')
            ->requiresConfirmation()
            ->action(function (Expense $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                app(AccountingDocumentService::class)->payExpense($actor, $record);
            });
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel draft expense')
            ->visible(fn (Expense $record): bool => $record->isDraft())
            ->authorize('update')
            ->requiresConfirmation()
            ->action(function (Expense $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                app(AccountingDocumentService::class)->cancelExpense($actor, $record);
            });
    }
}
