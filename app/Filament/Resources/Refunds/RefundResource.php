<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds;

use App\Filament\Resources\Refunds\Pages\ManageRefunds;
use App\Models\Refund;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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

final class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 208;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.refunds');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('refund_number')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('customer_id')->relationship('customer', 'company_name')->searchable()->preload()->required(),
            Select::make('payment_method_id')
                ->relationship('paymentMethod', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true))
                ->searchable()
                ->preload()
                ->required(),
            DatePicker::make('refund_date')->required(),
            TextInput::make('amount')->numeric()->minValue(0.01)->step(0.01)->required(),
            Textarea::make('reason')->required()->columnSpanFull(),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('refund_date', 'desc')
            ->columns([
                TextColumn::make('refund_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('paymentMethod.name')->label('Payment method'),
                TextColumn::make('refund_date')->date()->sortable(),
                TextColumn::make('amount')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->recordActions([
                self::approveAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageRefunds::route('/')];
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->visible(fn (Refund $record): bool => $record->isDraft())
            ->authorize('approve')
            ->requiresConfirmation()
            ->action(function (Refund $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                app(AccountingDocumentService::class)->approveRefund($actor, $record);
            });
    }
}
