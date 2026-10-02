<?php

declare(strict_types=1);

namespace App\Filament\Resources\BankStatements;

use App\Enums\AccountingPermission;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\BankStatements\Pages\CreateBankStatement;
use App\Filament\Resources\BankStatements\Pages\ListBankStatements;
use App\Filament\Resources\BankStatements\Pages\ViewBankStatement;
use App\Filament\Resources\BankStatements\RelationManagers\LinesRelationManager;
use App\Filament\Support\CurrencySelect;
use App\Models\BankStatement;
use App\Models\PaymentMethod;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class BankStatementResource extends Resource
{
    protected static ?string $model = BankStatement::class;

    protected static ?string $recordTitleAttribute = 'statement_number';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 150;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('Bank reconciliation');
    }

    #[\Override]
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(AccountingPermission::BankReconciliationView->value) ?? false;
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return auth()->user()?->can(AccountingPermission::BankReconciliationManage->value) ?? false;
    }

    #[\Override]
    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Statement details'))->schema([
                Select::make('payment_method_id')
                    ->label(__('Payment method / bank account'))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => PaymentMethod::query()
                        ->where('is_active', true)
                        ->whereHas('chartAccount', fn (Builder $query): Builder => $query
                            ->where('is_active', true)
                            ->where('is_postable', true))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                CurrencySelect::make('currency_code')->required(),
                DatePicker::make('period_start')->required(),
                DatePicker::make('period_end')->required()->afterOrEqual('period_start'),
                TextInput::make('opening_balance')->numeric()->required()->default(0),
                TextInput::make('closing_balance')->numeric()->required(),
            ])->columns(2),
            Section::make(__('Statement transactions'))->schema([
                Repeater::make('rows')
                    ->label('')
                    ->minItems(1)
                    ->defaultItems(1)
                    ->reorderable(false)
                    ->schema([
                        DatePicker::make('transaction_date')->required(),
                        TextInput::make('amount')
                            ->label(__('Amount (+ deposit / - withdrawal)'))
                            ->numeric()
                            ->required()
                            ->notIn([0, '0', '0.00']),
                        TextInput::make('reference')->maxLength(255),
                        TextInput::make('counterparty')->maxLength(255),
                        Textarea::make('description')->rows(2)->columnSpanFull(),
                    ])->columns(2),
            ]),
        ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Statement summary'))->columns(3)->schema([
                TextEntry::make('statement_number')->label(__('Statement')),
                TextEntry::make('status')->badge(),
                TextEntry::make('paymentMethod.name')->label(__('Payment method')),
                TextEntry::make('currency_code')->label(__('Currency')),
                TextEntry::make('period_start')->date(),
                TextEntry::make('period_end')->date(),
                TextEntry::make('opening_balance')->money(fn (BankStatement $record): string => $record->currency_code),
                TextEntry::make('closing_balance')->money(fn (BankStatement $record): string => $record->currency_code),
                TextEntry::make('reconciliation_progress')
                    ->label(__('Reconciliation progress'))
                    ->state(fn (BankStatement $record): string => self::progressLabel($record)),
                TextEntry::make('imported_at')->dateTime()->placeholder('—'),
                TextEntry::make('reconciled_at')->dateTime()->placeholder('—'),
            ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('statement_number')->label(__('Statement'))->searchable()->sortable(),
                TextColumn::make('paymentMethod.name')->label(__('Bank / method'))->searchable()->sortable(),
                TextColumn::make('period_start')->date()->sortable(),
                TextColumn::make('period_end')->date()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('reconciliation_progress')
                    ->label(__('Progress'))
                    ->state(fn (BankStatement $record): string => self::progressLabel($record)),
                TextColumn::make('closing_balance')->money(fn (BankStatement $record): string => $record->currency_code)->sortable(),
                TextColumn::make('imported_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'open' => __('Open'),
                    'reconciled' => __('Reconciled'),
                ]),
                SelectFilter::make('payment_method_id')->relationship('paymentMethod', 'name')->searchable()->preload(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['statement_number', 'paymentMethod.name', 'lines.reference', 'lines.counterparty'];
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListBankStatements::route('/'),
            'create' => CreateBankStatement::route('/create'),
            'view' => ViewBankStatement::route('/{record}'),
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('paymentMethod:id,name')
            ->withCount([
                'lines',
                'lines as matched_lines_count' => fn (Builder $query): Builder => $query->where('status', 'matched'),
            ]);
    }

    private static function progressLabel(BankStatement $record): string
    {
        $totalState = $record->getAttribute('lines_count');
        $matchedState = $record->getAttribute('matched_lines_count');
        $total = is_numeric($totalState) ? (int) $totalState : $record->lines()->count();
        $matched = is_numeric($matchedState) ? (int) $matchedState : $record->lines()->where('status', 'matched')->count();

        return $total === 0 ? '0 / 0' : "{$matched} / {$total}";
    }
}
