<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Actions;

use App\Enums\QuotationDecision;
use App\Enums\QuotationStatus;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Sales\QuotationConversionService;
use App\Services\Sales\QuotationService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * The Send and Record Decision actions, defined once and mounted on the
 * table, view page, and edit page.
 *
 * Each is visible only when the acting user holds the matching ability *and*
 * the quotation is in a status the transition allows — mirroring
 * {@see PurchaseOrderActions}.
 * Neither does any work itself; each is a thin adapter over
 * {@see QuotationService}.
 */
final class QuotationActions
{
    use InteractsWithSalesServices;

    public static function send(): Action
    {
        return Action::make('send')
            ->label(__('admin.sales.actions.send'))
            ->icon(Heroicon::PaperAirplane)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('admin.sales.actions.send_confirm'))
            ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Draft && self::canSend($record))
            ->authorize(fn (Quotation $record): bool => self::canSend($record))
            ->action(function (Quotation $record): void {
                self::runSalesOperation(
                    fn (): Quotation => app(QuotationService::class)->send($record),
                    'admin.sales.notifications.sent',
                    ['number' => (string) $record->quotation_number],
                );
            });
    }

    public static function recordDecision(): Action
    {
        return Action::make('record_decision')
            ->label(__('admin.sales.actions.record_decision'))
            ->icon(Heroicon::ChatBubbleLeftRight)
            ->color('gray')
            ->schema([
                Radio::make('decision')
                    ->label(__('admin.sales.fields.status'))
                    ->options([
                        QuotationDecision::Accepted->value => QuotationDecision::Accepted->label(),
                        QuotationDecision::Rejected->value => QuotationDecision::Rejected->label(),
                    ])
                    ->required(),
                DatePicker::make('decided_at')
                    ->label(__('admin.sales.fields.decided_at'))
                    ->required()
                    ->default(now()),
                Textarea::make('decision_note')
                    ->label(__('admin.sales.fields.decision_note'))
                    ->rows(2),
            ])
            ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Sent && self::canDecide())
            ->authorize(fn (): bool => self::canDecide())
            ->action(function (Quotation $record, array $data): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runSalesOperation(
                    fn (): Quotation => app(QuotationService::class)->recordDecision(
                        $record,
                        QuotationDecision::from(self::stringFrom($data['decision'] ?? null)),
                        CarbonImmutable::parse(self::stringFrom($data['decided_at'] ?? null)),
                        self::nullableStringFrom($data['decision_note'] ?? null),
                        $actor,
                    ),
                    'admin.sales.notifications.decision_recorded',
                    ['number' => (string) $record->quotation_number],
                );
            });
    }

    public static function convert(): Action
    {
        return Action::make('convert')
            ->label(__('admin.sales.actions.convert'))
            ->icon(Heroicon::ArrowRightCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('admin.sales.actions.convert_confirm'))
            ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Accepted
                && $record->converted_order_id === null
                && self::canConvert())
            ->authorize(fn (): bool => self::canConvert())
            ->action(function (Quotation $record): void {
                $order = self::runSalesOperation(
                    fn (): Order => app(QuotationConversionService::class)->convert($record),
                );

                Notification::make()
                    ->success()
                    ->title(__('admin.sales.notifications.converted', [
                        'number' => (string) $record->quotation_number,
                        'order' => (string) $order->order_number,
                    ]))
                    ->send();
            });
    }

    public static function requote(): Action
    {
        return Action::make('requote')
            ->label(__('admin.sales.actions.requote'))
            ->icon(Heroicon::ArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('admin.sales.actions.requote_confirm'))
            ->visible(fn (Quotation $record): bool => $record->isExpired() && self::canRequote())
            ->authorize(fn (): bool => self::canRequote())
            ->action(function (Quotation $record): void {
                $requoted = self::runSalesOperation(
                    fn (): Quotation => app(QuotationService::class)->requote($record),
                );

                Notification::make()
                    ->success()
                    ->title(__('admin.sales.notifications.requoted', [
                        'number' => (string) $record->quotation_number,
                        'new_number' => (string) $requoted->quotation_number,
                    ]))
                    ->send();
            });
    }

    private static function canConvert(): bool
    {
        $actor = self::salesActor();

        return $actor instanceof User && $actor->can('convert', Quotation::class);
    }

    private static function canRequote(): bool
    {
        $actor = self::salesActor();

        return $actor instanceof User && $actor->can('create', Quotation::class);
    }

    private static function canSend(Quotation $record): bool
    {
        $actor = self::salesActor();

        return $actor instanceof User && $actor->can('send', $record);
    }

    private static function canDecide(): bool
    {
        $actor = self::salesActor();

        return $actor instanceof User && $actor->can('decide', Quotation::class);
    }
}
