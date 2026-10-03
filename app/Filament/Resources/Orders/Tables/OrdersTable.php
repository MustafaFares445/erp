<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\Order;
use App\Services\Sales\OrderWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder(__('Search by order number or customer name'))
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('order_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('Customer'))->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (OrderStatus $state): string => $state->label())
                    ->color(static fn (OrderStatus $state): string => $state->color()),
                TextColumn::make('workflow_milestone')
                    ->label(__('Milestone'))
                    ->state(fn (Order $record): string => app(OrderWorkflowService::class)->project($record)->businessMilestone)
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'Cancelled' => 'danger',
                        'Closed' => 'success',
                        'Draft' => 'gray',
                        'Awaiting Release', 'Supply Blocked', 'Awaiting Logistics Allocation' => 'warning',
                        'Partially Allocated', 'Ready to Dispatch', 'In Transit' => 'info',
                        'Invoice Pending', 'Payment Pending' => 'warning',
                        'Delivered' => 'success',
                        default => 'primary',
                    }),
                TextColumn::make('workflow_blocker')
                    ->label(__('Blocker'))
                    ->state(fn (Order $record): ?string => app(OrderWorkflowService::class)->project($record)->blockerMessage)
                    ->limit(45)
                    ->tooltip(fn (Order $record): ?string => app(OrderWorkflowService::class)->project($record)->blockerMessage)
                    ->placeholder(__('—')),
                TextColumn::make('grand_total')
                    ->label(__('admin.sales.fields.grand_total'))
                    ->money()
                    ->placeholder(__('—'))
                    ->sortable()
                    ->summarize(Sum::make()->money()->label(__('Total'))),
                TextColumn::make('payment_status')
                    ->label(__('admin.sales.fields.payment_status'))
                    ->badge()
                    ->placeholder(__('—'))
                    ->formatStateUsing(static fn (?OrderPaymentStatus $state): ?string => $state?->label())
                    ->color(static fn (?OrderPaymentStatus $state): ?string => $state?->color()),
                TextColumn::make('scheduled_at')->label(__('Requested'))->date()->sortable(),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Status'))
                    ->getTitleFromRecordUsing(static fn (Order $record): string => $record->status->label()),
                Group::make('payment_status')
                    ->label(__('admin.sales.fields.payment_status'))
                    ->getTitleFromRecordUsing(static fn (Order $record): ?string => $record->payment_status?->label()),
                Group::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                Group::make('scheduled_at')->label(__('Requested'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Status'))
                        ->options(static fn (): array => collect(OrderStatus::cases())
                            ->mapWithKeys(static fn (OrderStatus $status): array => [$status->value => $status->label()])
                            ->all())
                        ->multiple(),
                    SelectConstraint::make('payment_status')
                        ->label(__('admin.sales.fields.payment_status'))
                        ->options(static fn (): array => collect(OrderPaymentStatus::cases())
                            ->mapWithKeys(static fn (OrderPaymentStatus $status): array => [$status->value => $status->label()])
                            ->all())
                        ->multiple(),
                    TextConstraint::make('order_number')->label(__('Reference')),
                    RelationshipConstraint::make('customer')
                        ->label(__('admin.sales.fields.customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    NumberConstraint::make('grand_total')->label(__('admin.sales.fields.grand_total')),
                    DateConstraint::make('scheduled_at')->label(__('Requested')),
                    DateConstraint::make('confirmed_at')->label(__('Confirmed at')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Order $record): bool => $record->status === OrderStatus::Draft),
                OrderActions::confirm(),
                OrderActions::release(),
                self::nextActionButton(),
            ])
            ->toolbarActions([]);
    }

    private static function nextActionButton(): Action
    {
        return Action::make('next_action')
            ->label(fn (Order $record): string => self::nextAction($record)['owner'].': '.self::nextAction($record)['label'])
            ->color(fn (Order $record): string => self::nextAction($record)['owner'] === 'None' ? 'gray' : 'primary')
            ->disabled()
            ->visible(fn (Order $record): bool => ! in_array(
                self::nextAction($record)['label'],
                ['Confirm order', 'Release to Logistics'],
                true,
            ));
    }

    /** @return array{owner: string, label: string} */
    private static function nextAction(Order $record): array
    {
        $projection = app(OrderWorkflowService::class)->project($record);

        return ['owner' => $projection->nextActionOwner, 'label' => $projection->nextActionLabel];
    }
}
