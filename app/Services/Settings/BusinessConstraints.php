<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Data\Settings\BusinessConstraintData;
use App\Data\Settings\PurchaseApprovalThresholdData;
use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Models\BusinessConstraint;
use App\Models\PurchaseSetting;

/**
 * The one place the rest of the application asks what a business limit is.
 *
 * Every consumer — a pricing service, a Filament form deriving its own
 * `maxValue()`, a console command building a reminder schedule — reads from
 * here, so a rule and the form that displays it can never drift apart. That
 * also means the constraints hold for callers that have no form at all: the
 * customer and employee applications will reach the same domain services.
 *
 * Reads are memoised for the lifetime of the request, not cached in a store.
 * `Docs/CONFIGURATION.md` only sanctions caching data whose invalidation is
 * implemented, and a per-request array needs none — it cannot go stale across
 * processes because it does not outlive one.
 */
final class BusinessConstraints
{
    /** @var array<string, BusinessConstraintData>|null */
    private ?array $memo = null;

    public function get(BusinessConstraintKey $key): BusinessConstraintData
    {
        return $this->load()[$key->value];
    }

    /**
     * Every constraint in catalogue order.
     *
     * @return list<BusinessConstraintData>
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    /**
     * @return list<BusinessConstraintData>
     */
    public function inGroup(string $group): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (BusinessConstraintData $data): bool => $data->key->group() === $group,
        ));
    }

    /** Drop the memo after a write, so the same request sees its own change. */
    public function forget(): void
    {
        $this->memo = null;
    }

    public function enforcementFor(BusinessConstraintKey $key): BusinessConstraintEnforcement
    {
        return $this->get($key)->requireEnforcement();
    }

    public function maxDiscountPercent(): float
    {
        return $this->get(BusinessConstraintKey::MaxDiscountPercent)->requireValue();
    }

    public function maxMarkupPercent(): float
    {
        return $this->get(BusinessConstraintKey::MaxMarkupPercent)->requireValue();
    }

    /** Null while no owner has opted into a margin floor. */
    public function minGrossMarginPercent(): ?float
    {
        return $this->get(BusinessConstraintKey::MinGrossMarginPercent)->value;
    }

    /** @return list<int> */
    public function receivableAgeingBoundaries(): array
    {
        return $this->get(BusinessConstraintKey::ReceivableAgeingBoundaries)->requireList();
    }

    /** @return list<int> */
    public function payableAgeingBoundaries(): array
    {
        return $this->get(BusinessConstraintKey::PayableAgeingBoundaries)->requireList();
    }

    /** @return list<int> */
    public function overdueReminderDays(): array
    {
        return $this->get(BusinessConstraintKey::OverdueReminderDays)->requireList();
    }

    /**
     * The purchasing auto-approval threshold, read through to its own settings
     * row.
     *
     * Deliberately not migrated into `business_constraints`: it works, it is
     * snapshotted onto each submission, and moving the data would be churn
     * with no behavioural payoff. Unifying the read path gets the benefit
     * without the risk.
     */
    public function purchaseApprovalThreshold(): PurchaseApprovalThresholdData
    {
        $setting = PurchaseSetting::current();

        return new PurchaseApprovalThresholdData(
            (float) $setting->approval_threshold_amount,
            mb_strtoupper($setting->approval_threshold_currency),
        );
    }

    /**
     * Every catalogue entry, overridden where a row exists.
     *
     * @return array<string, BusinessConstraintData>
     */
    private function load(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $stored = BusinessConstraint::query()->get()->keyBy(
            static fn (BusinessConstraint $constraint): string => $constraint->key->value,
        );

        $constraints = [];

        foreach (BusinessConstraintKey::cases() as $key) {
            $row = $stored->get($key->value);

            $constraints[$key->value] = $row instanceof BusinessConstraint
                ? $this->fromRow($key, $row)
                : $this->fromDefaults($key);
        }

        return $this->memo = $constraints;
    }

    private function fromRow(BusinessConstraintKey $key, BusinessConstraint $row): BusinessConstraintData
    {
        return new BusinessConstraintData(
            $key,
            $row->value === null ? null : (float) $row->value,
            $row->value_json,
            $row->enforcement,
            isDefault: false,
        );
    }

    private function fromDefaults(BusinessConstraintKey $key): BusinessConstraintData
    {
        return new BusinessConstraintData(
            $key,
            $key->defaultValue(),
            $key->defaultList(),
            $key->defaultEnforcement(),
            isDefault: true,
        );
    }
}
