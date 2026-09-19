<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Sales\OrderWorkflowService;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
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
                    ->sortable(),
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
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Order $record): bool => $record->status === OrderStatus::Draft),
            ])
            ->toolbarActions([]);
    }
}
