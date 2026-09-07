<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentTerm;
use App\Models\Quotation;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class PaymentTermService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PaymentTerm
    {
        return DB::transaction(function () use ($attributes): PaymentTerm {
            if ($attributes['is_default'] ?? false) {
                $this->clearExistingDefault();
            }

            return PaymentTerm::query()->create($attributes);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PaymentTerm $term, array $attributes): PaymentTerm
    {
        return DB::transaction(function () use ($term, $attributes): PaymentTerm {
            if (($attributes['is_default'] ?? false) && ! $term->is_default) {
                $this->clearExistingDefault();
            }

            $term->update($attributes);

            return $term->refresh();
        });
    }

    public function delete(PaymentTerm $term): void
    {
        DB::transaction(function () use ($term): void {
            /** @var PaymentTerm $locked */
            $locked = PaymentTerm::query()->whereKey($term->getKey())->lockForUpdate()->sole();

            $references = [
                'quotation' => Quotation::query()->where('payment_term_id', $locked->getKey())->exists(),
                'sales order' => Order::query()->where('payment_term_id', $locked->getKey())->exists(),
                'invoice' => Invoice::query()->where('payment_term_id', $locked->getKey())->exists(),
            ];

            foreach ($references as $document => $exists) {
                if ($exists) {
                    throw new DomainException("Payment term {$locked->name} cannot be deleted because a {$document} references it.");
                }
            }

            $locked->delete();
        });
    }

    private function clearExistingDefault(): void
    {
        PaymentTerm::query()->where('is_default', true)->update(['is_default' => false]);
    }
}
