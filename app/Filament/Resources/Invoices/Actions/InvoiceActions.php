<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Actions;

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\ReceivableWriteOffs\ReceivableWriteOffResource;
use App\Jobs\GenerateInvoiceDocument;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReceivableWriteOff;
use App\Models\User;
use App\Services\Sales\InvoiceConfirmationService;
use App\Services\Sales\InvoiceService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class InvoiceActions
{
    use InteractsWithSalesServices;

    public static function issue(): Action
    {
        return Action::make('issue')
            ->label('Issue invoice')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Issuing freezes the commercial content and posts Accounts Receivable, Revenue, and Deferred Sales Tax.')
            ->visible(fn (Invoice $record): bool => self::can('issue', $record))
            ->authorize(fn (Invoice $record): bool => self::can('issue', $record))
            ->action(function (Invoice $record): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runSalesOperation(
                    fn (): Invoice => app(InvoiceService::class)->issue($actor, $record),
                );

                Notification::make()->success()->title('Invoice issued and posted.')->send();
            });
    }

    public static function generatePdf(): Action
    {
        return Action::make('generate_pdf')
            ->label(fn (Invoice $record): string => $record->getFirstMedia('invoice-pdf') instanceof Media ? 'Regenerate PDF' : 'Generate PDF')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->visible(fn (Invoice $record): bool => $record->isIssued() && self::can('send', $record))
            ->authorize(fn (Invoice $record): bool => self::can('send', $record))
            ->action(function (Invoice $record): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                GenerateInvoiceDocument::dispatch((int) $record->getKey(), (int) $actor->getKey());

                Notification::make()->success()->title('Invoice PDF generation queued.')->send();
            });
    }

    public static function send(): Action
    {
        return Action::make('send_invoice')
            ->label('Send invoice')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription('The stored invoice PDF will be emailed to the customer. Accounting is not changed by sending.')
            ->visible(fn (Invoice $record): bool => self::can('send', $record) && $record->getFirstMedia('invoice-pdf') instanceof Media)
            ->authorize(fn (Invoice $record): bool => self::can('send', $record))
            ->action(function (Invoice $record): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runSalesOperation(
                    fn (): Invoice => app(InvoiceService::class)->send($actor, $record),
                );

                Notification::make()->success()->title('Invoice email queued.')->send();
            });
    }

    public static function confirmReceipt(): Action
    {
        return Action::make('confirm_receipt')
            ->label('Confirm receipt')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('gray')
            ->schema([
                Select::make('confirmation_type')
                    ->label('Confirmation type')
                    ->options(
                        collect(InvoiceConfirmationType::cases())
                            ->mapWithKeys(fn (InvoiceConfirmationType $type): array => [$type->value => $type->label()])
                            ->all(),
                    )
                    ->required(),
                Textarea::make('notes')->rows(2)->maxLength(2000),
                FileUpload::make('signature')
                    ->label('Signature evidence')
                    ->disk('local')
                    ->directory('invoice-confirmation-signatures')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp']),
            ])
            ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Sent
                && self::can('confirmReceipt', $record))
            ->authorize(fn (Invoice $record): bool => self::can('confirmReceipt', $record))
            ->action(function (Invoice $record, array $data): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                $signature = self::nullableStringFrom($data['signature'] ?? null);

                self::runSalesOperation(
                    fn () => app(InvoiceConfirmationService::class)->confirm(
                        $actor,
                        $record,
                        self::stringFrom($data['confirmation_type'] ?? null),
                        self::nullableStringFrom($data['notes'] ?? null),
                        $signature === null ? null : Storage::disk('local')->path($signature),
                    ),
                );

                Notification::make()->success()->title('Invoice receipt evidence recorded.')->send();
            });
    }

    public static function recordPayment(): Action
    {
        return Action::make('record_payment')
            ->label('Record payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('primary')
            ->visible(fn (Invoice $record): bool => $record->isIssued()
                && $record->outstandingAmount() > 0.00001
                && (self::salesActor()?->can('create', Payment::class) ?? false))
            ->url(fn (Invoice $record): string => PaymentResource::getUrl('create', [
                'customer_id' => $record->customer_id,
                'invoice_id' => $record->getKey(),
            ]));
    }

    public static function writeOff(): Action
    {
        return Action::make('write_off')
            ->label('Write off receivable')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('danger')
            ->visible(fn (Invoice $record): bool => $record->isIssued()
                && ! in_array($record->status, [InvoiceStatus::Cancelled, InvoiceStatus::WrittenOff], true)
                && $record->outstandingMinor() > 0
                && (self::salesActor()?->can('create', ReceivableWriteOff::class) ?? false))
            ->url(fn (Invoice $record): string => ReceivableWriteOffResource::getUrl('create', [
                'customer_id' => $record->customer_id,
                'invoice_id' => $record->getKey(),
            ]));
    }

    public static function createCreditNote(): Action
    {
        return Action::make('create_credit_note')
            ->label('Create credit note')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Invoice $record): bool => $record->isIssued()
                && (float) $record->credited_amount + 0.00001 < (float) $record->total_amount
                && (self::salesActor()?->can('create', CreditNote::class) ?? false))
            ->url(fn (Invoice $record): string => CreditNoteResource::getUrl('create', [
                'invoice_id' => $record->getKey(),
            ]));
    }

    private static function can(string $ability, Invoice $invoice): bool
    {
        return self::salesActor()?->can($ability, $invoice) ?? false;
    }
}
