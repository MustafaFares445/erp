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

final class ConfirmationsRelationManager extends RelationManager
{
    use InteractsWithPurchasingServices;

    protected static string $relationship = 'confirmations';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('notes')->label(__('admin.purchasing.fields.notes'))->rows(3)->maxLength(1000),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'asc')
            ->columns([
                TextColumn::make('confirmation_status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (SupplierConfirmationStatus $state): string => $state->label())
                    ->color(static fn (SupplierConfirmationStatus $state): string => match ($state) {
                        SupplierConfirmationStatus::Pending, SupplierConfirmationStatus::Partial => 'warning',
                        SupplierConfirmationStatus::Confirmed => 'success',
                        SupplierConfirmationStatus::Rejected => 'danger',
                    }),
                TextColumn::make('items_count')->label(__('admin.purchasing.fields.lines'))->counts('items')->badge(),
                TextColumn::make('promised_at')->label(__('admin.purchasing.fields.promised_at'))->date()->placeholder('—'),
                TextColumn::make('confirmedBy.name')->label(__('admin.purchasing.fields.confirmed_by'))->placeholder('—'),
                TextColumn::make('notes')->label(__('admin.purchasing.fields.notes'))->wrap()->limit(80)->placeholder('—'),
                TextColumn::make('created_at')->label(__('admin.common.created_at'))->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.purchasing.actions.request_supplier_confirmation'))
                    ->visible(fn (): bool => self::purchasingActor()?->can('request', SupplierConfirmation::class) ?? false)
                    ->using(function (array $data): SupplierConfirmation {
                        $actor = self::purchasingActor();
                        if (! $actor instanceof User) {
                            throw new LogicException('A supplier confirmation requires an authenticated actor.');
                        }

                        return self::runPurchasingOperation(
                            fn (): SupplierConfirmation => app(SupplierConfirmationService::class)->recordPurchaseOrder(
                                $actor,
                                $this->order(),
                                self::nullableStringFrom($data['notes'] ?? null),
                            ),
                            'admin.purchasing.notifications.confirmation_recorded',
                        );
                    }),
            ])
            ->recordActions([
                SupplierConfirmationActions::response(),
            ])
            ->toolbarActions([]);
    }

    private function order(): PurchaseOrder
    {
        $record = $this->getOwnerRecord();
        if (! $record instanceof PurchaseOrder) {
            throw new LogicException('Expected a PurchaseOrder owner record.');
        }

        return $record;
    }
}
