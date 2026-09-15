<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations\Actions;

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Models\ProductVariant;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Services\Purchasing\SupplierConfirmationService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use LogicException;

final class SupplierConfirmationActions
{
    use InteractsWithPurchasingServices;

    public static function response(): Action
    {
        return Action::make('supplierResponse')
            ->label(__('admin.purchasing.actions.supplier_response'))
            ->icon(Heroicon::ChatBubbleLeftRight)
            ->color('primary')
            ->schema([
                Select::make('response')
                    ->label(__('admin.purchasing.fields.supplier_response'))
                    ->options([
                        SupplierConfirmationStatus::Confirmed->value => SupplierConfirmationStatus::Confirmed->label(),
                        SupplierConfirmationStatus::Partial->value => SupplierConfirmationStatus::Partial->label(),
                        SupplierConfirmationStatus::Rejected->value => SupplierConfirmationStatus::Rejected->label(),
                    ])
                    ->required()
                    ->live(),
                DatePicker::make('promised_at')
                    ->label(__('admin.purchasing.fields.promised_at'))
                    ->required(fn (Get $get): bool => self::needsCommitment($get('response')))
                    ->visible(fn (Get $get): bool => self::needsCommitment($get('response'))),
                Repeater::make('items')
                    ->label(__('admin.purchasing.fields.lines'))
                    ->visible(fn (Get $get): bool => self::needsCommitment($get('response')))
                    ->default(fn (SupplierConfirmation $record): array => self::commitmentDefaults($record))
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('product')->label(__('admin.purchasing.fields.product'))->disabled()->dehydrated(false),
                        TextInput::make('supplier_reference')->label(__('admin.purchasing.fields.supplier_reference'))->disabled()->dehydrated(false),
                        TextInput::make('requested_base_quantity')
                            ->label(__('admin.purchasing.fields.requested_quantity'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('confirmed_base_quantity')
                            ->label(__('admin.purchasing.fields.confirmed_quantity'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.000001)
                            ->required(),
                        TextInput::make('backordered_base_quantity')
                            ->label(__('admin.purchasing.fields.backordered_quantity'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.000001)
                            ->required(),
                    ])
                    ->columns(5)
                    ->reorderable(false)
                    ->addable(false)
                    ->deletable(false),
                Textarea::make('notes')
                    ->label(__('admin.purchasing.fields.notes'))
                    ->rows(3)
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(fn (SupplierConfirmation $record): bool => self::canAnswer($record))
            ->authorize(fn (SupplierConfirmation $record): bool => self::canAnswer($record))
            ->action(function (SupplierConfirmation $record, array $data): void {
                $actor = self::purchasingActor();
                if (! $actor instanceof User) {
                    return;
                }

                $response = self::stringFrom($data['response'] ?? null);
                $status = SupplierConfirmationStatus::from($response);
                $promised = self::nullableStringFrom($data['promised_at'] ?? null);
                $items = is_array($data['items'] ?? null) ? $data['items'] : [];

                self::runPurchasingOperation(
                    fn (): SupplierConfirmation => app(SupplierConfirmationService::class)->respond(
                        $actor,
                        $record,
                        $status,
                        $promised === null ? null : CarbonImmutable::parse($promised),
                        self::stringFrom($data['notes'] ?? null),
                        self::quantityPayload($items),
                    ),
                    'admin.purchasing.notifications.confirmation_recorded',
                );
            });
    }

    /** @return list<array<string, mixed>> */
    private static function commitmentDefaults(SupplierConfirmation $record): array
    {
        return array_values($record->items()
            ->with(['productVariant.product.brand', 'purchaseOrderLine.supplierProductReference'])
            ->where('confirmation_status', SupplierConfirmationStatus::Pending->value)
            ->orderBy('id')
            ->get()
            ->map(static function (SupplierConfirmationItem $item): array {
                $variant = $item->productVariant;
                $product = $variant instanceof ProductVariant ? $variant->product : null;
                $reference = $item->purchaseOrderLine?->supplierProductReference;

                $productLabel = $variant instanceof ProductVariant
                    ? mb_trim(($product->name ?? 'Product').' → '.$variant->name.' ('.$variant->sku.')')
                    : (string) $item->product_variant_id;
                $supplierLabel = $reference instanceof SupplierProductReference
                    ? mb_trim(($reference->supplier_name ?? '').' / '.$reference->supplier_item_number, ' /')
                    : '—';

                return [
                    'id' => self::itemId($item),
                    'product' => $productLabel,
                    'supplier_reference' => $supplierLabel,
                    'requested_base_quantity' => $item->requested_base_quantity,
                    'confirmed_base_quantity' => $item->requested_base_quantity,
                    'backordered_base_quantity' => '0.000000',
                ];
            })
            ->values()
            ->all());
    }

    /** @param array<array-key, mixed> $items
     * @return list<array{id:int,confirmed_base_quantity:mixed,backordered_base_quantity:mixed}>
     */
    private static function quantityPayload(array $items): array
    {
        $payload = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $payload[] = [
                'id' => self::integerFrom($item['id'] ?? null),
                'confirmed_base_quantity' => $item['confirmed_base_quantity'] ?? null,
                'backordered_base_quantity' => $item['backordered_base_quantity'] ?? null,
            ];
        }

        return $payload;
    }

    private static function needsCommitment(mixed $response): bool
    {
        return is_string($response) && in_array($response, [
            SupplierConfirmationStatus::Confirmed->value,
            SupplierConfirmationStatus::Partial->value,
        ], true);
    }

    private static function canAnswer(SupplierConfirmation $confirmation): bool
    {
        return self::purchasingActor()?->can('answer', $confirmation) ?? false;
    }

    private static function itemId(SupplierConfirmationItem $item): int
    {
        $id = $item->getKey();
        if (! is_numeric($id)) {
            throw new LogicException('A supplier confirmation item must have a numeric identifier.');
        }

        return (int) $id;
    }
}
