<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\PurchaseAgreementStatus;
use App\Enums\PurchasePermission;
use App\Models\PurchaseAgreement;
use App\Models\User;
use App\Services\Settings\CurrencyCatalogService;
use DomainException;
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
            $agreement = new PurchaseAgreement([
                'supplier_id' => $attributes['supplier_id'],
                'currency_code' => $this->currencies->normalizeActive($attributes['currency_code'], 'currency_code'),
                'starts_on' => $attributes['starts_on'],
                'ends_on' => $attributes['ends_on'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);
            $agreement->forceFill([
                'agreement_number' => $this->numbers->next(),
                'status' => PurchaseAgreementStatus::Draft,
                'created_by' => $actor->getKey(),
            ])->save();

            foreach ($lines as $line) {
                if ((float) $line['unit_price'] < 0) {
                    throw new DomainException('Agreement unit price cannot be negative.');
                }
                $agreement->lines()->create($line);
            }

            return $agreement->refresh()->load('lines');
        });
    }

    public function activate(User $actor, PurchaseAgreement $agreement): PurchaseAgreement
    {
        Gate::forUser($actor)->authorize(PurchasePermission::AgreementManage->value);

        if ($agreement->lines()->doesntExist()) {
            throw new DomainException('An empty purchase agreement cannot be activated.');
        }

        $agreement->forceFill(['status' => PurchaseAgreementStatus::Active])->save();

        return $agreement->refresh();
    }

    public function cancel(User $actor, PurchaseAgreement $agreement): PurchaseAgreement
    {
        Gate::forUser($actor)->authorize(PurchasePermission::AgreementManage->value);
        $agreement->forceFill(['status' => PurchaseAgreementStatus::Cancelled])->save();

        return $agreement->refresh();
    }
}
