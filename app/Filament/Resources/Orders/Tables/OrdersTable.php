<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Services\Sales\OrderWorkflowService;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Search by order number or customer name')
            ->columns([
                TextColumn::make('order_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(static fn (OrderStatus $state): string => $state->label()),
                TextColumn::make('workflow_milestone')
                    ->label('Milestone')
                    ->state(fn (Order $record): string => app(OrderWorkflowService::class)->project($record)->businessMilestone)
                    ->badge(),
                TextColumn::make('workflow_blocker')
                    ->label('Blocker')
                    ->state(fn (Order $record): ?string => app(OrderWorkflowService::class)->project($record)->blockerMessage)
                    ->limit(45)
                    ->placeholder('—'),
                TextColumn::make('next_action')
                    ->label('Next action')
                    ->state(fn (Order $record): string => app(OrderWorkflowService::class)->project($record)->nextActionOwner.': '.app(OrderWorkflowService::class)->project($record)->nextActionLabel),
                TextColumn::make('grand_total')
                    ->label(__('admin.sales.fields.grand_total'))
                    ->money()
                    ->placeholder('—')
                    ->sortable()
                    ->summarize(Sum::make()->money()->label('Total')),
                TextColumn::make('payment_status')
                    ->label(__('admin.sales.fields.payment_status'))
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(static fn (?OrderPaymentStatus $state): ?string => $state?->label()),
                TextColumn::make('scheduled_at')->label('Requested')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('payment_status')
                    ->label(__('admin.sales.fields.payment_status'))
                    ->options(collect(OrderPaymentStatus::cases())->mapWithKeys(fn (OrderPaymentStatus $status): array => [$status->value => $status->label()])->all()),
                SelectFilter::make('customer_id')
                    ->label(__('admin.sales.fields.customer'))
                    ->searchable()
                    ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
                Filter::make('scheduled_between')
                    ->schema([
                        DatePicker::make('from')->label('Requested from'),
                        DatePicker::make('until')->label('Requested until'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('scheduled_at', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('scheduled_at', '<=', $date))),
                Filter::make('confirmed_between')
                    ->schema([
                        DatePicker::make('from')->label('Confirmed from'),
                        DatePicker::make('until')->label('Confirmed until'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('confirmed_at', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('confirmed_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Order $record): bool => $record->status === OrderStatus::Draft),
            ])
            ->toolbarActions([]);
    }

    private static function dateFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
