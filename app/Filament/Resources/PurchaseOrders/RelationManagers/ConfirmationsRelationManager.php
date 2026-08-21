<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\SupplierConfirmations\Actions\SupplierConfirmationActions;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Services\Purchasing\SupplierConfirmationService;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

/**
 * The chronological record of what this supplier said about this order.
 *
 * Append-only. There is no edit or delete action anywhere on this surface,
 * because an answered confirmation is evidence and the receiving-performance
 * report is built from it (FR-031). A supplier who changes their mind produces
 * a new row, which is why the history reads oldest-first: it is a conversation,
 * not a current value.
 */
final class ConfirmationsRelationManager extends RelationManager
{
    use InteractsWithPurchasingServices;

    protected static string $relationship = 'confirmations';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('notes')
                ->label(__('admin.purchasing.fields.notes'))
                ->rows(3)
                ->maxLength(1000),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            // Oldest first: this is a history, and reading it backwards loses the
            // sequence of what was asked and answered.
            ->defaultSort('id', 'asc')
            ->columns([
                TextColumn::make('confirmation_status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (SupplierConfirmationStatus $state): string => $state->label())
                    ->color(static fn (SupplierConfirmationStatus $state): string => match ($state) {
                        SupplierConfirmationStatus::Pending => 'warning',
                        SupplierConfirmationStatus::Confirmed => 'success',
                        SupplierConfirmationStatus::Rejected => 'danger',
                    }),
                TextColumn::make('promised_at')
                    ->label(__('admin.purchasing.fields.promised_at'))
                    ->date()
                    ->placeholder('—'),
                TextColumn::make('confirmedBy.name')
                    ->label(__('admin.purchasing.fields.confirmed_by'))
                    ->placeholder('—'),
                TextColumn::make('confirmed_at')
                    ->label(__('admin.purchasing.fields.confirmed_at'))
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('notes')
                    ->label(__('admin.purchasing.fields.notes'))
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.purchasing.actions.submit'))
                    ->visible(fn (): bool => self::purchasingActor()?->can('create', SupplierConfirmation::class) ?? false)
                    ->using(function (array $data): SupplierConfirmation {
                        $actor = self::purchasingActor();

                        if (! $actor instanceof User) {
                            throw new LogicException('A supplier confirmation cannot be recorded without an authenticated actor.');
                        }

                        $order = $this->order();

                        return self::runPurchasingOperation(
                            fn (): SupplierConfirmation => app(SupplierConfirmationService::class)->record(
                                $actor,
                                $order,
                                $order->supplier_id,
                                self::nullableStringFrom($data['notes'] ?? null),
                            ),
                            'admin.purchasing.notifications.confirmation_recorded',
                        );
                    }),
            ])
            ->recordActions([
                SupplierConfirmationActions::confirm(),
                SupplierConfirmationActions::reject(),
            ])
            // No edit, no delete, no bulk actions. Append-only means append-only.
            ->toolbarActions([]);
    }

    private function order(): PurchaseOrder
    {
        $record = $this->getOwnerRecord();

        // @codeCoverageIgnoreStart
        // Unreachable in practice; the guard exists only to satisfy static
        // analysis, which sees getOwnerRecord() as returning the base Model.
        if (! $record instanceof PurchaseOrder) {
            throw new LogicException('Expected the owner record of ConfirmationsRelationManager to be a PurchaseOrder.');
        }

        // @codeCoverageIgnoreEnd

        return $record;
    }
}
