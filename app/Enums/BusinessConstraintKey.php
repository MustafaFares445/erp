<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Settings\BusinessConstraints;
use App\Services\Settings\ConstraintGuard;

/**
 * The catalogue of business constraints.
 *
 * A constraint's **definition** lives here, in code; only an owner's
 * **override of its value** lives in the `business_constraints` table. That
 * split is deliberate:
 *
 * - a missing row means "use the default", so `migrate:fresh` needs no seed
 *   and a test needs no fixture to exercise stock behaviour;
 * - adding a constraint is one case plus one set of translation keys, with no
 *   migration and no schema churn;
 * - every consumer reaches a constraint through a typed case, so a typo is a
 *   compile-time problem rather than a silently null limit.
 *
 * Read through {@see BusinessConstraints}; enforce
 * through {@see ConstraintGuard}. Nothing else should
 * read the model directly.
 */
enum BusinessConstraintKey: string
{
    /**
     * How close two readings of a constraint have to be to count as the same
     * value.
     *
     * Percentages reach a comparison after division and rounding, so exact
     * equality would make a limit unreachable in practice and would refuse an
     * approval for the very amount it was granted for. Defined once here
     * because the guard comparing an attempt to a limit and the override
     * matching an attempt to its approval are asking the same question.
     */
    public const float ValueTolerance = 0.0001;

    case MaxDiscountPercent = 'pricing.max_discount_percent';
    case MaxMarkupPercent = 'pricing.max_markup_percent';
    case MinGrossMarginPercent = 'pricing.min_gross_margin_percent';
    case ReceivableAgeingBoundaries = 'accounting.receivable_ageing_boundaries';
    case PayableAgeingBoundaries = 'accounting.payable_ageing_boundaries';
    case OverdueReminderDays = 'sales.overdue_reminder_days';

    public function kind(): BusinessConstraintKind
    {
        return match ($this) {
            self::MaxDiscountPercent,
            self::MaxMarkupPercent,
            self::MinGrossMarginPercent => BusinessConstraintKind::Limit,
            self::ReceivableAgeingBoundaries,
            self::PayableAgeingBoundaries,
            self::OverdueReminderDays => BusinessConstraintKind::Policy,
        };
    }

    public function unit(): BusinessConstraintUnit
    {
        return match ($this) {
            self::MaxDiscountPercent,
            self::MaxMarkupPercent,
            self::MinGrossMarginPercent => BusinessConstraintUnit::Percent,
            self::ReceivableAgeingBoundaries,
            self::PayableAgeingBoundaries,
            self::OverdueReminderDays => BusinessConstraintUnit::Days,
        };
    }

    /**
     * Whether this constraint holds an ordered list of numbers rather than a
     * single value. A list constraint stores into `value_json`; a scalar one
     * stores into `value`.
     */
    public function isList(): bool
    {
        return $this->kind() === BusinessConstraintKind::Policy;
    }

    /**
     * Whether the configured number is an upper bound rather than a lower one.
     *
     * Only meaningful for {@see BusinessConstraintKind::Limit}; a policy value
     * bounds nothing, and {@see ConstraintGuard} refuses
     * a policy key before it asks.
     */
    public function isCeiling(): bool
    {
        return in_array($this, [self::MaxDiscountPercent, self::MaxMarkupPercent], true);
    }

    /**
     * Whether "no value" is a meaningful configured state, distinct from the
     * default.
     *
     * Only the minimum gross margin needs this. A margin floor of zero is a
     * real rule — it refuses selling below cost — so it cannot double as the
     * "not configured" marker the way a zero ceiling could.
     */
    public function isNullable(): bool
    {
        return $this === self::MinGrossMarginPercent;
    }

    /**
     * The value used when the owner has set none.
     *
     * Every default here reproduces the behaviour the code had before this
     * registry existed, except {@see self::MaxDiscountPercent}, which is the
     * one genuinely new rule — there was no discount ceiling at all.
     */
    public function defaultValue(): ?float
    {
        return match ($this) {
            self::MaxDiscountPercent => 25.0,
            // Reproduces the hardcoded 0-100 bound in
            // ProductPricingService::assertPercentage().
            self::MaxMarkupPercent => 100.0,
            // Null, not zero: unset until an owner opts in, so no variant
            // already priced below cost starts refusing to save.
            self::MinGrossMarginPercent => null,
            self::ReceivableAgeingBoundaries,
            self::PayableAgeingBoundaries,
            self::OverdueReminderDays => null,
        };
    }

    /** @return list<int>|null */
    public function defaultList(): ?array
    {
        return match ($this) {
            // Reproduces the 30/60/90 ladder duplicated in
            // AccountsReceivableService and AccountsPayableService.
            self::ReceivableAgeingBoundaries,
            self::PayableAgeingBoundaries => [30, 60, 90],
            // Reproduces SendOverdueInvoiceRemindersCommand::thresholds().
            self::OverdueReminderDays => [7, 30, 60],
            self::MaxDiscountPercent,
            self::MaxMarkupPercent,
            self::MinGrossMarginPercent => null,
        };
    }

    public function defaultEnforcement(): ?BusinessConstraintEnforcement
    {
        return match ($this) {
            // A discount past the ceiling is a commercial decision someone
            // should own by name, not an error, so it escalates by default.
            self::MaxDiscountPercent => BusinessConstraintEnforcement::RequireApproval,
            // Refusing outright preserves the behaviour of the hardcoded bound
            // this replaces.
            self::MaxMarkupPercent => BusinessConstraintEnforcement::Block,
            self::MinGrossMarginPercent => BusinessConstraintEnforcement::Warn,
            self::ReceivableAgeingBoundaries,
            self::PayableAgeingBoundaries,
            self::OverdueReminderDays => null,
        };
    }

    /**
     * The enforcement modes an owner may choose for this constraint.
     *
     * Not every limit can offer every mode. A markup ceiling governs how a
     * base price is derived from cost across the catalogue, in bulk; there is
     * no single deal for an approval to hang off and nobody to name as its
     * approver, so it is either enforced or merely observed. A discount, by
     * contrast, is always agreed on one deal with one customer, which is
     * exactly what an approval records.
     *
     * @return list<BusinessConstraintEnforcement>
     */
    public function allowedEnforcements(): array
    {
        return match ($this) {
            self::MaxDiscountPercent,
            self::MinGrossMarginPercent => BusinessConstraintEnforcement::cases(),
            self::MaxMarkupPercent => [
                BusinessConstraintEnforcement::Block,
                BusinessConstraintEnforcement::Warn,
            ],
            self::ReceivableAgeingBoundaries,
            self::PayableAgeingBoundaries,
            self::OverdueReminderDays => [],
        };
    }

    /**
     * The settings-page section this constraint is presented under.
     */
    public function group(): string
    {
        return match ($this) {
            self::MaxDiscountPercent,
            self::MaxMarkupPercent,
            self::MinGrossMarginPercent => 'pricing',
            self::ReceivableAgeingBoundaries,
            self::PayableAgeingBoundaries => 'receivables',
            self::OverdueReminderDays => 'reminders',
        };
    }

    /**
     * The admin module groups whose behaviour changes when this constraint
     * changes, as `AdminModuleRegistry` group keys.
     *
     * Surfaced beside the field so an owner sees the blast radius before
     * moving a number rather than after.
     *
     * @return list<string>
     */
    public function affectedModules(): array
    {
        return match ($this) {
            self::MaxDiscountPercent => ['crm', 'sales', 'inventory'],
            self::MaxMarkupPercent, self::MinGrossMarginPercent => ['inventory', 'crm'],
            self::ReceivableAgeingBoundaries => ['accounting', 'reports'],
            self::PayableAgeingBoundaries => ['accounting', 'vendors', 'reports'],
            self::OverdueReminderDays => ['sales', 'accounting'],
        };
    }

    public function label(): string
    {
        return __('admin.constraints.keys.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('admin.constraints.keys.'.$this->value.'.description');
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $key): string => $key->value, self::cases());
    }

    /** @return list<self> */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $key): bool => $key->group() === $group,
        ));
    }

    /** @return list<string> */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::cases() as $key) {
            if (! in_array($key->group(), $groups, true)) {
                $groups[] = $key->group();
            }
        }

        return $groups;
    }
}
