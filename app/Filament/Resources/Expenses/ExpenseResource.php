<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses;

use App\Enums\ExpenseStatus;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\Schemas\ExpenseInfolist;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
            TextInput::make('expense_number')->label(__('Expense number'))->disabled()->dehydrated(false),
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
                ->label(__('Receipt'))
                ->disk('local')
                ->directory('expense-receipts')
                ->visibility('private')
                ->maxSize(10240)
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                ->preventFilePathTampering(
                    allowFilePathUsing: static fn (?Expense $record, string $file): bool => $record instanceof Expense
                        && $record->getFirstMedia('receipt')?->getPathRelativeToRoot() === $file,
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
    public static function infolist(Schema $schema): Schema
    {
        return ExpenseInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageExpenses::route('/'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }

    public static function approveAction(): Action
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

    public static function payAction(): Action
    {
        return Action::make('pay')
            ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::Approved)
            ->authorize('pay')
            ->schema([
                DatePicker::make('payment_date')
                    ->label(__('Payment date'))
                    ->default(now()->toDateString())
                    ->required(),
            ])
            ->requiresConfirmation()
            ->action(function (Expense $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                $paymentDateValue = $data['payment_date'] ?? null;
                $paymentDate = CarbonImmutable::parse(
                    is_string($paymentDateValue) ? $paymentDateValue : now()->toDateString(),
                );
                app(AccountingDocumentService::class)->payExpense($actor, $record, $paymentDate);
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel draft expense'))
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
