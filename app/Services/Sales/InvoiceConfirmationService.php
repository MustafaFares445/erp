<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceConfirmation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class InvoiceConfirmationService
{
    public function confirm(
        User $actor,
        Invoice $invoice,
        InvoiceConfirmationType|string $type,
        ?string $notes = null,
        ?string $signaturePath = null,
    ): InvoiceConfirmation {
        Gate::forUser($actor)->authorize('confirmReceipt', $invoice);

        $confirmationType = $type instanceof InvoiceConfirmationType
            ? $type
            : InvoiceConfirmationType::tryFrom($type);

        if (! $confirmationType instanceof InvoiceConfirmationType) {
            throw new DomainException('Unsupported invoice receipt confirmation type.');
        }

        return DB::transaction(function () use ($actor, $invoice, $confirmationType, $notes, $signaturePath): InvoiceConfirmation {

            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->sole();

            if (! $locked->isIssued() || $locked->status !== InvoiceStatus::Sent) {
                throw new DomainException('Only a sent invoice can record receipt evidence.');
            }

            $confirmation = $locked->confirmations()->create([
                'confirmed_by_user_id' => $actor->getKey(),
                'confirmation_type' => $confirmationType,
                'confirmed_at' => now(),
                'notes' => $notes,
            ]);

            if (is_string($signaturePath) && $signaturePath !== '') {
                $confirmation->addMedia($signaturePath)
                    ->toMediaCollection('invoice-confirmation-signature');
            }

            $locked->forceFill([
                'received_confirmation_type' => $confirmationType,
                'received_confirmed_at' => $confirmation->confirmed_at,
                'received_confirmed_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'confirmation_type' => $confirmationType->value,
                ])
                ->log('sales.invoice.receipt_confirmed');

            return $confirmation->refresh();
        });
    }
}
