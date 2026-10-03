<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\PurchaseAgreementStatus;
use App\Enums\PurchasePermission;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseAgreementLine;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Services\Settings\CurrencyCatalogService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class PurchaseAgreementService
{
    public function __construct(
        private PurchaseAgreementNumberGenerator $numbers,
        private CurrencyCatalogService $currencies,
    ) {}

    /** @param array{supplier_id:int,currency_code:string,starts_on:string,ends_on?:string|null,notes?:string|null} $attributes
     *  @param list<array{product_variant_id:int,unit_id:int,unit_price:float|string,minimum_order_quantity?:float|string|null,lead_time_days?:int|null}> $lines */
    public function create(User $actor, array $attributes, array $lines): PurchaseAgreement
    {
        Gate::forUser($actor)->authorize(PurchasePermission::AgreementManage->value);
        if ($lines === []) {
            throw new DomainException('A purchase agreement requires at least one line.');
        }

        return DB::transaction(function () use ($actor, $attributes, $lines): PurchaseAgreement {
            /** @var Supplier $supplier */
            $supplier = Supplier::query()->findOrFail($attributes['supplier_id']);
            if (! $supplier->is_active) {
                throw new DomainException('An inactive supplier cannot be used for a new purchase agreement.');
            }
            $supplierKey = $supplier->getKey();
            if (! is_int($supplierKey) && ! is_string($supplierKey)) {
                throw new DomainException('Supplier has an invalid identifier.');
            }
            $supplierId = (int) $supplierKey;

            $startsOn = Carbon::parse($attributes['starts_on'])->startOfDay();
            $endsOn = isset($attributes['ends_on'])
                ? Carbon::parse($attributes['ends_on'])->startOfDay()
                : null;
            if ($endsOn instanceof Carbon && $endsOn->lt($startsOn)) {
                throw new DomainException('Agreement end date cannot be before its start date.');
            }

            $agreement = new PurchaseAgreement([
                'supplier_id' => $supplierId,
                'currency_code' => $this->currencies->normalizeActive($attributes['currency_code'], 'currency_code'),
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'notes' => $attributes['notes'] ?? null,
            ]);
            $agreement->forceFill([
                'agreement_number' => $this->numbers->next(),
                'status' => PurchaseAgreementStatus::Draft,
                'created_by' => $actor->getKey(),
            ])->save();

            $seen = [];
            foreach ($lines as $line) {
                $unitPrice = (float) $line['unit_price'];
                $minimumOrderQuantity = $line['minimum_order_quantity'] ?? null;
                $leadTimeDays = $line['lead_time_days'] ?? null;

                if ($unitPrice < 0) {
                    throw new DomainException('Agreement unit price cannot be negative.');
                }
                if ($minimumOrderQuantity !== null && (float) $minimumOrderQuantity <= 0) {
                    throw new DomainException('Agreement minimum order quantity must be positive when provided.');
                }
                if ($leadTimeDays !== null && $leadTimeDays < 0) {
                    throw new DomainException('Agreement lead time cannot be negative.');
                }

                $key = $line['product_variant_id'].':'.$line['unit_id'];
                if (isset($seen[$key])) {
                    throw new DomainException('A product variant and unit may appear only once in a purchase agreement.');
                }
                $seen[$key] = true;

                if (! SupplierProductReference::query()->activeFor($supplierId, $line['product_variant_id'])->exists()) {
                    throw new DomainException('Agreement lines require an active supplier product reference.');
                }
                if (! ProductVariantUnit::query()
                    ->where('product_variant_id', $line['product_variant_id'])
                    ->where('unit_id', $line['unit_id'])
                    ->where('is_active', true)
                    ->where('is_purchase', true)
                    ->exists()) {
                    throw new DomainException('Agreement line unit must be an active purchase unit for the product variant.');
                }

                $agreement->lines()->create($line);
            }

            return $agreement->refresh()->load('lines');
        });
    }

    public function activate(User $actor, PurchaseAgreement $agreement): PurchaseAgreement
    {
        Gate::forUser($actor)->authorize(PurchasePermission::AgreementManage->value);

        return DB::transaction(function () use ($agreement): PurchaseAgreement {
            /** @var PurchaseAgreement $locked */
            $locked = PurchaseAgreement::query()->with('lines')->lockForUpdate()->findOrFail($agreement->getKey());
            if ($locked->status !== PurchaseAgreementStatus::Draft) {
                throw new DomainException('Only a draft purchase agreement can be activated.');
            }
            if ($locked->lines->isEmpty()) {
                throw new DomainException('An empty purchase agreement cannot be activated.');
            }
            if ($locked->ends_on !== null && $locked->ends_on->lt(today())) {
                throw new DomainException('An agreement that has already ended cannot be activated.');
            }

            $this->assertNoOverlappingActiveAgreement($locked);
            $locked->forceFill(['status' => PurchaseAgreementStatus::Active])->save();

            return $locked->refresh();
        });
    }

    public function cancel(User $actor, PurchaseAgreement $agreement): PurchaseAgreement
    {
        Gate::forUser($actor)->authorize(PurchasePermission::AgreementManage->value);

        return DB::transaction(function () use ($agreement): PurchaseAgreement {
            /** @var PurchaseAgreement $locked */
            $locked = PurchaseAgreement::query()->lockForUpdate()->findOrFail($agreement->getKey());
            if ($locked->status === PurchaseAgreementStatus::Expired) {
                throw new DomainException('An expired purchase agreement cannot be cancelled.');
            }
            if ($locked->status !== PurchaseAgreementStatus::Cancelled) {
                $locked->forceFill(['status' => PurchaseAgreementStatus::Cancelled])->save();
            }

            return $locked->refresh();
        });
    }

    public function expire(User $actor, PurchaseAgreement $agreement): PurchaseAgreement
    {
        Gate::forUser($actor)->authorize(PurchasePermission::AgreementManage->value);

        return DB::transaction(function () use ($agreement): PurchaseAgreement {
            /** @var PurchaseAgreement $locked */
            $locked = PurchaseAgreement::query()->lockForUpdate()->findOrFail($agreement->getKey());
            if ($locked->status !== PurchaseAgreementStatus::Active) {
                throw new DomainException('Only an active purchase agreement can expire.');
            }
            if ($locked->ends_on === null || ! $locked->ends_on->lt(today())) {
                throw new DomainException('Purchase agreement has not reached its end date.');
            }

            $locked->forceFill(['status' => PurchaseAgreementStatus::Expired])->save();

            return $locked->refresh();
        });
    }

    private function assertNoOverlappingActiveAgreement(PurchaseAgreement $agreement): void
    {
        foreach ($agreement->lines as $line) {
            $overlap = PurchaseAgreementLine::query()
                ->where('product_variant_id', $line->product_variant_id)
                ->where('unit_id', $line->unit_id)
                ->whereHas('agreement', fn (Builder $query): Builder => $query
                    ->whereKeyNot($agreement->getKey())
                    ->where('supplier_id', $agreement->supplier_id)
                    ->where('currency_code', $agreement->currency_code)
                    ->where('status', PurchaseAgreementStatus::Active->value)
                    ->when($agreement->ends_on !== null, fn (Builder $dates): Builder => $dates->whereDate('starts_on', '<=', $agreement->ends_on?->toDateString()))
                    ->where(fn (Builder $dates): Builder => $dates
                        ->whereNull('ends_on')
                        ->orWhereDate('ends_on', '>=', $agreement->starts_on->toDateString())))
                ->exists();

            if ($overlap) {
                throw new DomainException('An overlapping active purchase agreement already exists for this supplier, product variant, unit, and currency.');
            }
        }
    }
}
