<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds;

use App\Enums\RefundStatus;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\Refunds\Pages\ManageRefunds;
use App\Models\Refund;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Accounting\RefundService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            Select::make('credit_note_id')
                ->label(__('Credit note (optional)'))
                ->relationship(
                    'creditNote',
                    'credit_note_number',
                    modifyQueryUsing: fn (Builder $query): Builder => $query
                        ->where('status', 'confirmed')
                        ->whereNull('reversed_at'),
                )
                ->searchable()
                ->preload()
                ->helperText(__('Leave empty to refund an unapplied customer deposit.')),
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
                TextColumn::make('customer.company_name')->label(__('Customer'))->searchable(),
                TextColumn::make('paymentMethod.name')->label(__('Payment method')),
                TextColumn::make('refund_date')->date()->sortable(),
                TextColumn::make('amount')->money()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RefundStatus $state): string => $state->label())
                    ->color(fn (RefundStatus $state): string => $state->color())
                    ->sortable(),
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
        return ['index' => ManageRefunds::route('/')];
    }

    private static function payAction(): Action
    {
        return Action::make('pay')
            ->label(__('Mark refund paid'))
            ->visible(fn (Refund $record): bool => $record->isApproved()
                && ! ($record->paymentMethod?->isStripe() ?? false))
            ->authorize('pay')
            ->requiresConfirmation()
            ->action(function (Refund $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                app(RefundService::class)->pay($actor, $record);
            });
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel draft refund'))
            ->visible(fn (Refund $record): bool => $record->isDraft())
            ->authorize('update')
            ->requiresConfirmation()
            ->action(function (Refund $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated accounting user is required.');
                }

                app(RefundService::class)->cancel($actor, $record);
            });
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
