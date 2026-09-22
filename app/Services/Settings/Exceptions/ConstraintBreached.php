<?php

declare(strict_types=1);

namespace App\Services\Settings\Exceptions;

use App\Enums\BusinessConstraintKey;
use DomainException;

/**
 * A value crossed a configured business limit and was not allowed through.
 *
 * Extends {@see DomainException} on purpose: the four
 * `App\Filament\Concerns\InteractsWith*Services` traits already catch that
 * type, raise a red notification carrying the message, and halt the action —
 * so constraints surface correctly in every Filament form without a single
 * change to the error plumbing.
 *
 * The key, attempted value and limit are exposed because the UI needs them to
 * offer the approval affordance, not merely to print a sentence.
 */
final class ConstraintBreached extends DomainException
{
    private function __construct(
        string $message,
        public readonly BusinessConstraintKey $key,
        public readonly float $attempted,
        public readonly float $limit,
        public readonly bool $approvable,
    ) {
        parent::__construct($message);
    }

    public static function blocked(BusinessConstraintKey $key, float $attempted, float $limit): self
    {
        return new self(
            __('admin.constraints.errors.blocked', [
                'constraint' => $key->label(),
                'attempted' => self::render($key, $attempted),
                'limit' => self::render($key, $limit),
            ]),
            $key,
            $attempted,
            $limit,
            approvable: false,
        );
    }

    public static function approvalRequired(BusinessConstraintKey $key, float $attempted, float $limit): self
    {
        return new self(
            __('admin.constraints.errors.approval_required', [
                'constraint' => $key->label(),
                'attempted' => self::render($key, $attempted),
                'limit' => self::render($key, $limit),
            ]),
            $key,
            $attempted,
            $limit,
            approvable: true,
        );
    }

    /**
     * The supplied override does not authorise this exact value.
     *
     * Presenting an approval for a different amount is the failure mode that
     * matters here: it is how an approved 30% discount would otherwise be
     * reused to push through 45%.
     */
    public static function overrideDoesNotApply(BusinessConstraintKey $key, float $attempted, float $limit): self
    {
        return new self(
            __('admin.constraints.errors.override_does_not_apply', ['constraint' => $key->label()]),
            $key,
            $attempted,
            $limit,
            approvable: true,
        );
    }

    private static function render(BusinessConstraintKey $key, float $amount): string
    {
        $suffix = $key->unit()->suffix();
        $formatted = mb_rtrim(mb_rtrim(number_format($amount, 2, '.', ''), '0'), '.');

        return $suffix === null ? $formatted : $formatted.$suffix;
    }
}
