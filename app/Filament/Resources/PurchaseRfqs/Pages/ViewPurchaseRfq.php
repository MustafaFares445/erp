<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseRfqs\Pages;

use App\Enums\PurchasePermission;
use App\Enums\PurchaseRfqStatus;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRfqs\PurchaseRfqResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRfq;
use App\Models\PurchaseRfqSupplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseRfqService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewPurchaseRfq extends ViewRecord
{
    use InteractsWithPurchasingServices;

    protected static string $resource = PurchaseRfqResource::class;

    #[\Override]
    public function getTitle(): string
    {
        return 'RFQ '.$this->rfq()->rfq_number;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label(__('Send RFQ'))
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $this->rfq()->status === PurchaseRfqStatus::Draft
                    && ($this->actor()?->can(PurchasePermission::RfqManage->value) ?? false))
                ->requiresConfirmation()
                ->action(function (): void {
                    $actor = $this->actor();
                    if (! $actor instanceof User) {
                        return;
                    }

                    self::runPurchasingOperation(fn (): PurchaseRfq => app(PurchaseRfqService::class)->send($actor, $this->rfq()));
                    $this->refreshFormData(['status', 'sent_at']);
                    Notification::make()->success()->title(__('RFQ sent'))->send();
                }),
            $this->recordResponseAction(),
            $this->awardAction(),
            $this->closeAction(),
            $this->expireAction(),
            $this->cancelAction(),
        ];
    }

    private function recordResponseAction(): Action
    {
        return Action::make('recordResponse')
            ->label(__('Record supplier response'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->visible(fn (): bool => $this->rfq()->status->acceptsResponses()
                && ($this->actor()?->can(PurchasePermission::RfqManage->value) ?? false))
            ->schema([
                Select::make('rfq_supplier_id')
                    ->label(__('Supplier'))
                    ->required()
                    ->options(fn (): array => $this->supplierOptions()),
                Repeater::make('responses')
                    ->label(__('Quoted lines'))
                    ->minItems(1)
                    ->schema([
                        Select::make('rfq_line_id')
                            ->label(__('RFQ line'))
                            ->required()
                            ->options(fn (): array => $this->rfqLineOptions()),
                        TextInput::make('unit_price')->numeric()->minValue(0)->required(),
                        TextInput::make('offered_quantity')->numeric()->minValue(0.000001)->required(),
                        TextInput::make('lead_time_days')->numeric()->minValue(0),
                        TextInput::make('minimum_order_quantity')->numeric()->minValue(0),
                        Textarea::make('notes')->rows(2),
                    ])->columns(2),
            ])
            ->action(function (array $data): void {
                $actor = $this->actor();
                if (! $actor instanceof User || ! is_numeric($data['rfq_supplier_id'] ?? null)) {
                    return;
                }

                $supplier = $this->rfq()->suppliers()->whereKey((int) $data['rfq_supplier_id'])->firstOrFail();
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

                $this->refreshFormData(['status']);
                Notification::make()->success()->title(__('Supplier response recorded'))->send();
            });
    }

    private function awardAction(): Action
    {
        return Action::make('award')
            ->label(__('Award supplier'))
            ->icon('heroicon-o-trophy')
            ->color('success')
            ->visible(fn (): bool => in_array($this->rfq()->status, [PurchaseRfqStatus::PartiallyResponded, PurchaseRfqStatus::Evaluating], true)
                && ($this->actor()?->can(PurchasePermission::RfqAward->value) ?? false))
            ->schema([
                Select::make('rfq_supplier_id')
                    ->label(__('Supplier'))
                    ->required()
                    ->options(fn (): array => $this->supplierOptions(respondedOnly: true)),
            ])
            ->requiresConfirmation()
            ->action(function (array $data): void {
                $actor = $this->actor();
                if (! $actor instanceof User || ! is_numeric($data['rfq_supplier_id'] ?? null)) {
                    return;
                }

                $candidate = $this->rfq()->suppliers()->whereKey((int) $data['rfq_supplier_id'])->firstOrFail();
                $order = self::runPurchasingOperation(
                    fn (): PurchaseOrder => app(PurchaseRfqService::class)->award($actor, $candidate),
                );

                Notification::make()->success()->title(__('RFQ awarded and Purchase Order created'))->send();
                $this->redirect(PurchaseOrderResource::getUrl('view', ['record' => $order]));
            });
    }

    private function closeAction(): Action
    {
        return Action::make('close')
            ->label(__('Close RFQ'))
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->visible(fn (): bool => $this->rfq()->status->canClose()
                && ($this->actor()?->can(PurchasePermission::RfqManage->value) ?? false))
            ->requiresConfirmation()
            ->action(function (): void {
                $actor = $this->actor();
                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(fn (): PurchaseRfq => app(PurchaseRfqService::class)->close($actor, $this->rfq()));
                $this->refreshFormData(['status', 'closed_at']);
                Notification::make()->success()->title(__('RFQ closed'))->send();
            });
    }

    private function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel RFQ'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->rfq()->status->canCancel()
                && ($this->actor()?->can(PurchasePermission::RfqManage->value) ?? false))
            ->requiresConfirmation()
            ->action(function (): void {
                $actor = $this->actor();
                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(fn (): PurchaseRfq => app(PurchaseRfqService::class)->cancel($actor, $this->rfq()));
                $this->refreshFormData(['status', 'cancelled_at']);
                Notification::make()->success()->title(__('RFQ cancelled'))->send();
            });
    }

    private function expireAction(): Action
    {
        return Action::make('expire')
            ->label(__('Mark expired'))
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->visible(fn (): bool => $this->rfq()->closes_at !== null
                && $this->rfq()->closes_at->isPast()
                && ! $this->rfq()->status->isTerminal()
                && $this->rfq()->status !== PurchaseRfqStatus::Awarded
                && ($this->actor()?->can(PurchasePermission::RfqManage->value) ?? false))
            ->requiresConfirmation()
            ->action(function (): void {
                $actor = $this->actor();
                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(fn (): PurchaseRfq => app(PurchaseRfqService::class)->expire($actor, $this->rfq()));
                $this->refreshFormData(['status', 'expired_at']);
                Notification::make()->success()->title(__('RFQ expired'))->send();
            });
    }

    /** @return array<int, string> */
    private function supplierOptions(bool $respondedOnly = false): array
    {
        $query = $this->rfq()->suppliers()->with('supplier:id,name');

        if ($respondedOnly) {
            $query->whereNotNull('responded_at');
        }

        return $query->get()->mapWithKeys(function (PurchaseRfqSupplier $candidate): array {
            $name = data_get($candidate, 'supplier.name');

            return [(int) $candidate->id => is_string($name) ? $name : '#'.$candidate->supplier_id];
        })->all();
    }

    /** @return array<int, string> */
    private function rfqLineOptions(): array
    {
        return $this->rfq()->lines()
            ->with('productVariant:id,sku,name')
            ->get()
            ->mapWithKeys(function ($line): array {
                $sku = data_get($line, 'productVariant.sku');

                return [(int) $line->id => ((is_string($sku) ? $sku : '#'.$line->product_variant_id).' · '.$line->quantity)];
            })
            ->all();
    }

    private function rfq(): PurchaseRfq
    {
        $record = $this->getRecord();

        if (! $record instanceof PurchaseRfq) {
            throw new \LogicException('Expected a PurchaseRfq record.');
        }

        return $record;
    }

    private function actor(): ?User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : null;
    }
}
