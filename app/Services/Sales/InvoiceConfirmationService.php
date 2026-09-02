<?php

declare(strict_types=1);

namespace App\Services\Sales;

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
        string $type,
        ?string $notes = null,
        ?string $signaturePath = null,
    ): InvoiceConfirmation {
        Gate::forUser($actor)->authorize('confirmReceipt', $invoice);

        return DB::transaction(function () use ($actor, $invoice, $type, $notes, $signaturePath): InvoiceConfirmation {
            if (! in_array($type, ['customer_received', 'employee_confirmed_received'], true)) {
                throw new DomainException('Unsupported invoice receipt confirmation type.');
            }

            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->sole();

            if (! $locked->isIssued()) {
                throw new DomainException('Only an issued invoice can record receipt evidence.');
            }

            $confirmation = $locked->confirmations()->create([
                'confirmed_by_user_id' => $actor->getKey(),
                'confirmation_type' => $type,
                'confirmed_at' => now(),
                'notes' => $notes,
            ]);

            if (is_string($signaturePath) && $signaturePath !== '') {
                $confirmation->addMedia($signaturePath)
                    ->toMediaCollection('invoice-confirmation-signature');
            }

            if (in_array($locked->status, ['issued', 'sent'], true)) {
                $locked->forceFill(['status' => $type, 'updated_by' => $actor->getKey()])->save();
            }

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'confirmation_type' => $type])
                ->log('sales.invoice.receipt_confirmed');

            return $confirmation->refresh();
        });
    }
}
