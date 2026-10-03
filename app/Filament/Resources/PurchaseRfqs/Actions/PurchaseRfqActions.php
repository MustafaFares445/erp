<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseRfqs\Actions;

use App\Enums\PurchasePermission;
use App\Enums\PurchaseRfqStatus;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRfq;
use App\Models\PurchaseRfqLine;
use App\Models\PurchaseRfqSupplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseRfqService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * RFQ workflow actions shared by the RFQ View page and the list table so both
 * surfaces call {@see PurchaseRfqService} with identical visibility rules.
 */
final class PurchaseRfqActions
{
    use InteractsWithPurchasingServices;

    public static function send(): Action
    {
        return Action::make('send')
            ->label(__('Send RFQ'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (PurchaseRfq $record): bool => $record->status === PurchaseRfqStatus::Draft
                && self::can(PurchasePermission::RfqManage))
            ->requiresConfirmation()
            ->action(function (PurchaseRfq $record): void {
                $actor = self::purchasingActor();
                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(fn (): PurchaseRfq => app(PurchaseRfqService::class)->send($actor, $record));
                $record->refresh();
                Notification::make()->success()->title(__('RFQ sent'))->send();
            });
    }

    public static function recordResponse(string $name = 'recordResponse'): Action
    {
        return Action::make($name)
            ->label(__('Record supplier response'))
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->visible(fn (PurchaseRfq $record): bool => $record->status->acceptsResponses()
                && self::can(PurchasePermission::RfqManage))
            ->schema([
                Select::make('rfq_supplier_id')
                    ->label(__('Supplier'))
                    ->required()
                    ->options(fn (PurchaseRfq $record): array => self::supplierOptions($record)),
                Repeater::make('responses')
                    ->label(__('Quoted lines'))
                    ->minItems(1)
                    ->schema([
                        Select::make('rfq_line_id')
                            ->label(__('RFQ line'))
                            ->required()
                            ->options(fn (PurchaseRfq $record): array => self::rfqLineOptions($record)),
                        TextInput::make('unit_price')->numeric()->minValue(0)->required(),
                        TextInput::make('offered_quantity')->numeric()->minValue(0.000001)->required(),
                        TextInput::make('lead_time_days')->numeric()->minValue(0),
                        TextInput::make('minimum_order_quantity')->numeric()->minValue(0),
                        Textarea::make('notes')->rows(2),
                    ])->columns(2),
            ])
            ->action(function (PurchaseRfq $record, array $data): void {
                $actor = self::purchasingActor();
                if (! $actor instanceof User || ! is_numeric($data['rfq_supplier_id'] ?? null)) {
                    return;
                }

                $supplier = $record->suppliers()->whereKey((int) $data['rfq_supplier_id'])->firstOrFail();
                $rawResponses = is_array($data['responses'] ?? null) ? $data['responses'] : [];
                $responses = [];

                foreach ($rawResponses as $response) {
                    if (! is_array($response)) {
                        continue;
                    }

                    $responses[] = [
                        'rfq_line_id' => self::integerFrom($response['rfq_line_id'] ?? null),
                        'unit_price' => self::stringFrom($response['unit_price'] ?? null),
                        'offered_quantity' => self::stringFrom($response['offered_quantity'] ?? null),
                        'lead_time_days' => is_numeric($response['lead_time_days'] ?? null) ? (int) $response['lead_time_days'] : null,
                        'minimum_order_quantity' => self::nullableStringFrom($response['minimum_order_quantity'] ?? null),
                        'notes' => self::nullableStringFrom($response['notes'] ?? null),
                    ];
                }
                self::runPurchasingOperation(
                    fn (): PurchaseRfqSupplier => app(PurchaseRfqService::class)->recordResponse($actor, $supplier, $responses),
                );

                $record->refresh();
                Notification::make()->success()->title(__('Supplier response recorded'))->send();
            });
    }

    public static function award(): Action
    {
        $order = null;

        return Action::make('award')
            ->label(__('Award supplier'))
            ->icon(Heroicon::OutlinedTrophy)
            ->color('success')
            ->visible(fn (PurchaseRfq $record): bool => $record->status->isAwardable()
                && self::can(PurchasePermission::RfqAward))
            ->schema([
                Select::make('rfq_supplier_id')
                    ->label(__('Supplier'))
                    ->required()
                    ->options(fn (PurchaseRfq $record): array => self::supplierOptions($record, respondedOnly: true)),
            ])
            ->requiresConfirmation()
            ->action(function (PurchaseRfq $record, array $data) use (&$order): void {
                $actor = self::purchasingActor();
                if (! $actor instanceof User || ! is_numeric($data['rfq_supplier_id'] ?? null)) {
                    return;
                }

                $candidate = $record->suppliers()->whereKey((int) $data['rfq_supplier_id'])->firstOrFail();
                $order = self::runPurchasingOperation(
                    fn (): PurchaseOrder => app(PurchaseRfqService::class)->award($actor, $candidate),
                );
                $record->refresh();

                Notification::make()->success()->title(__('RFQ awarded and Purchase Order created'))->send();
            })
            ->successRedirectUrl(static function () use (&$order): ?string {
                return $order instanceof PurchaseOrder
                    ? PurchaseOrderResource::getUrl('view', ['record' => $order])
                    : null;
            });
    }

    /** Link to the Purchase Order created by the award, when the user may open it. */
    public static function openPurchaseOrder(): Action
    {
        return Action::make('openPurchaseOrder')
            ->label(__('Open Purchase Order'))
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('primary')
            ->visible(fn (PurchaseRfq $record): bool => $record->status === PurchaseRfqStatus::Awarded
                && $record->awardedPurchaseOrder !== null
                && PurchaseOrderResource::canView($record->awardedPurchaseOrder))
            ->url(fn (PurchaseRfq $record): ?string => $record->awardedPurchaseOrder instanceof PurchaseOrder
                ? PurchaseOrderResource::getUrl('view', ['record' => $record->awardedPurchaseOrder])
                : null);
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label(__('Close RFQ'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('gray')
            ->visible(fn (PurchaseRfq $record): bool => $record->status->canClose()
                && self::can(PurchasePermission::RfqManage))
            ->requiresConfirmation()
            ->action(function (PurchaseRfq $record): void {
                $actor = self::purchasingActor();
                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(fn (): PurchaseRfq => app(PurchaseRfqService::class)->close($actor, $record));
                $record->refresh();
                Notification::make()->success()->title(__('RFQ closed'))->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel RFQ'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (PurchaseRfq $record): bool => $record->status->canCancel()
                && self::can(PurchasePermission::RfqManage))
            ->requiresConfirmation()
            ->action(function (PurchaseRfq $record): void {
                $actor = self::purchasingActor();
                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(fn (): PurchaseRfq => app(PurchaseRfqService::class)->cancel($actor, $record));
                $record->refresh();
                Notification::make()->success()->title(__('RFQ cancelled'))->send();
            });
    }

    public static function expire(): Action
    {
        return Action::make('expire')
            ->label(__('Mark expired'))
            ->icon(Heroicon::OutlinedClock)
            ->color('warning')
            ->visible(fn (PurchaseRfq $record): bool => $record->closes_at !== null
                && $record->closes_at->isPast()
                && ! $record->status->isTerminal()
                && $record->status !== PurchaseRfqStatus::Awarded
                && self::can(PurchasePermission::RfqManage))
            ->requiresConfirmation()
            ->action(function (PurchaseRfq $record): void {
                $actor = self::purchasingActor();
                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(fn (): PurchaseRfq => app(PurchaseRfqService::class)->expire($actor, $record));
                $record->refresh();
                Notification::make()->success()->title(__('RFQ expired'))->send();
            });
    }

    private static function can(PurchasePermission $permission): bool
    {
        return self::purchasingActor()?->can($permission->value) ?? false;
    }

    /** @return array<int, string> */
    private static function supplierOptions(PurchaseRfq $rfq, bool $respondedOnly = false): array
    {
        $query = $rfq->suppliers()->with('supplier:id,name');

        if ($respondedOnly) {
            $query->whereNotNull('responded_at');
        }

        return $query->get()->mapWithKeys(function (PurchaseRfqSupplier $candidate): array {
            $name = data_get($candidate, 'supplier.name');

            return [(int) $candidate->id => is_string($name) ? $name : '#'.$candidate->supplier_id];
        })->all();
    }

    /** @return array<int, string> */
    private static function rfqLineOptions(PurchaseRfq $rfq): array
    {
        return $rfq->lines()
            ->with('productVariant:id,sku,name')
            ->get()
            ->mapWithKeys(function (PurchaseRfqLine $line): array {
                $sku = data_get($line, 'productVariant.sku');

                return [(int) $line->id => ((is_string($sku) ? $sku : '#'.$line->product_variant_id).' · '.$line->quantity)];
            })
            ->all();
    }
}
