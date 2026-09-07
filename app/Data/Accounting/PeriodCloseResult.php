<?php

declare(strict_types=1);

namespace App\Data\Accounting;

use App\Enums\PeriodCloseCheck;
use App\Services\Accounting\PeriodCloseChecklistService;
use Carbon\CarbonImmutable;

/**
 * One check's verdict from a {@see PeriodCloseChecklistService}
 * run, before it is persisted to `fiscal_period_close_checks`.
 */
final readonly class PeriodCloseResult
{
    /** @param array<string, mixed> $detail */
    public function __construct(
        public PeriodCloseCheck $check,
        public bool $passed,
        public array $detail,
        public CarbonImmutable $measuredAt,
        public ?int $reconciliationRunId = null,
    ) {}

    /**
     * Whether this result is a failure serious enough to block a close —
     * an advisory check never blocks, however it comes out.
     */
    public function isMandatoryFailure(): bool
    {
        return ! $this->passed && $this->check->isMandatory();
    }
}
