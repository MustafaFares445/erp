<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations\Actions;

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Models\ProductVariant;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Models\User;
use App\Services\Purchasing\SupplierConfirmationService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use LogicException;

/**
 * The two ways a pending confirmation can be answered, defined once and mounted
 * on both the standalone resource and the purchase order's relation manager.
 *
 * Both disappear the moment the record is answered — not because the permission
 * changes, but because an answered confirmation accepts no further writes at
 * all (FR-031).
 */
final class SupplierConfirmationActions
{
    use InteractsWithPurchasingServices;

    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label(__('admin.purchasing.confirmation_status.confirmed'))
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->schema([
                DatePicker::make('promised_at')
                    ->label(__('admin.purchasing.fields.promised_at'))
                    ->required(),
                Repeater::make('items')
                    ->label('Supplier commitment by PO line')
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('product')
                            ->label('Product')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('requested_base_quantity')
                            ->label('Ordered')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('confirmed_base_quantity')
                            ->label('Confirmed')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.000001)
                            ->required(),
                        TextInput::make('backordered_base_quantity')
                            ->label('Backordered')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.000001)
                            ->required(),
                    ])
                    ->columns(4)
                    ->reorderable(false)
                    ->addable(false)
                    ->deletable(false)
                    ->default(fn (SupplierConfirmation $record): array => self::commitmentDefaults($record))
                    ->visible(fn (SupplierConfirmation $record): bool => self::hasPurchaseOrderItems($record)),
                Textarea::make('notes')
                    ->label(__('admin.purchasing.fields.notes'))
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->visible(fn (SupplierConfirmation $record): bool => self::canAnswer($record))
            ->authorize(fn (SupplierConfirmation $record): bool => self::canAnswer($record))
            ->action(function (SupplierConfirmation $record, array $data): void {
                /** @var array<string, mixed> $data */
                self::answer($record, SupplierConfirmationStatus::Confirmed, $data);
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectConfirmation')
            ->label(__('admin.purchasing.confirmation_status.rejected'))
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->schema([
                Textarea::make('notes')
                    ->label(__('admin.purchasing.fields.notes'))
                    ->rows(2)
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(fn (SupplierConfirmation $record): bool => self::canAnswer($record))
            ->authorize(fn (SupplierConfirmation $record): bool => self::canAnswer($record))
            ->action(function (SupplierConfirmation $record, array $data): void {
                /** @var array<string, mixed> $data */
                self::answer($record, SupplierConfirmationStatus::Rejected, $data);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function answer(SupplierConfirmation $record, SupplierConfirmationStatus $outcome, array $data): void
    {
        $actor = self::purchasingActor();

        if (! $actor instanceof User) {
            return;
        }

        $promised = self::nullableStringFrom($data['promised_at'] ?? null);

        self::runPurchasingOperation(
            function () use ($actor, $record, $outcome, $promised, $data): SupplierConfirmation {
                if (! $record->items()->exists()) {
                    return app(SupplierConfirmationService::class)->answer(
                        $actor,
                        $record,
                        $outcome,
                        $promised === null ? null : CarbonImmutable::parse($promised),
                        self::nullableStringFrom($data['notes'] ?? null),
                    );
                }

                return app(SupplierConfirmationService::class)->answerItems(
                    $actor,
                    $record,
                    self::itemAnswers($record, $outcome, $promised, $data),
                );
            },
            'admin.purchasing.notifications.confirmation_recorded',
        );
    }

    /** @return list<array<string, mixed>> */
    private static function commitmentDefaults(SupplierConfirmation $record): array
    {
        return array_values($record->items()
            ->with('productVariant')
            ->whereNotNull('purchase_order_line_id')
            ->where('confirmation_status', SupplierConfirmationStatus::Pending->value)
            ->orderBy('id')
            ->get()
            ->map(static function (SupplierConfirmationItem $item): array {
                $product = $item->productVariant;

                return [
                    'id' => self::itemId($item),
                    'product' => $product instanceof ProductVariant
                        ? $product->sku
                        : self::stringInput($item->product_variant_id),
                    'requested_base_quantity' => $item->requested_base_quantity,
                    'confirmed_base_quantity' => $item->requested_base_quantity,
                    'backordered_base_quantity' => '0.000000',
                ];
            })
            ->values()
            ->all());
    }

    private static function hasPurchaseOrderItems(SupplierConfirmation $record): bool
    {
        return $record->items()->whereNotNull('purchase_order_line_id')->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{
     *     id: int,
     *     confirmation_status: SupplierConfirmationStatus,
     *     promised_at: CarbonImmutable|null,
     *     confirmed_base_quantity: mixed,
     *     backordered_base_quantity: mixed,
     *     notes: string|null
     * }>
     */
    private static function itemAnswers(
        SupplierConfirmation $record,
        SupplierConfirmationStatus $outcome,
        ?string $promised,
        array $data,
    ): array {
        $provided = collect(is_array($data['items'] ?? null) ? $data['items'] : [])
            ->keyBy('id');

        return array_values($record->items()
            ->where('confirmation_status', SupplierConfirmationStatus::Pending->value)
            ->orderBy('id')
            ->get()
            ->map(function (SupplierConfirmationItem $item) use ($provided, $outcome, $promised, $data): array {
                $input = $provided->get(self::itemId($item), []);
                $input = is_array($input) ? $input : [];

                return [
                    'id' => self::itemId($item),
                    'confirmation_status' => $outcome,
                    'promised_at' => $promised === null ? null : CarbonImmutable::parse($promised),
                    'confirmed_base_quantity' => $input['confirmed_base_quantity'] ?? null,
                    'backordered_base_quantity' => $input['backordered_base_quantity'] ?? null,
                    'notes' => self::nullableStringFrom($data['notes'] ?? null),
                ];
            })
            ->values()
            ->all());
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

    private static function stringInput(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new LogicException('A supplier confirmation product identifier is required.');
        }

        return (string) $value;
    }
}
