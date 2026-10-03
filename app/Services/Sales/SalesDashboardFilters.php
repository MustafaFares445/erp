<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Support\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;

/**
 * Resolved state of the Sales Dashboard's global filter bar: the shared
 * {@see DashboardPeriod} date window plus the optional salesperson/customer
 * scoping. Every Sales widget builds its queries from one of these rather
 * than reading `$pageFilters` directly.
 */
final readonly class SalesDashboardFilters
{
    public const string PERIOD_TODAY = DashboardPeriod::PERIOD_TODAY;

    public const string PERIOD_LAST_7_DAYS = DashboardPeriod::PERIOD_LAST_7_DAYS;

    public const string PERIOD_LAST_30_DAYS = DashboardPeriod::PERIOD_LAST_30_DAYS;

    public const string PERIOD_THIS_MONTH = DashboardPeriod::PERIOD_THIS_MONTH;

    public const string PERIOD_LAST_MONTH = DashboardPeriod::PERIOD_LAST_MONTH;

    public const string PERIOD_THIS_QUARTER = DashboardPeriod::PERIOD_THIS_QUARTER;

    public const string PERIOD_THIS_YEAR = DashboardPeriod::PERIOD_THIS_YEAR;

    public const string PERIOD_CUSTOM = DashboardPeriod::PERIOD_CUSTOM;

    public const string GRANULARITY_HOURLY = DashboardPeriod::GRANULARITY_HOURLY;

    public const string GRANULARITY_DAILY = DashboardPeriod::GRANULARITY_DAILY;

    public const string GRANULARITY_WEEKLY = DashboardPeriod::GRANULARITY_WEEKLY;

    public const string GRANULARITY_MONTHLY = DashboardPeriod::GRANULARITY_MONTHLY;

    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public CarbonImmutable $previousFrom;

    public CarbonImmutable $previousTo;

    public string $granularity;

    public function __construct(
        public DashboardPeriod $period,
        public ?int $employeeId = null,
        public ?int $customerId = null,
    ) {
        $this->from = $period->from;
        $this->to = $period->to;
        $this->previousFrom = $period->previousFrom;
        $this->previousTo = $period->previousTo;
        $this->granularity = $period->granularity;
    }

    /** @param  array<string, mixed>  $pageFilters */
    public static function fromPageFilters(array $pageFilters): self
    {
        return new self(
            period: DashboardPeriod::fromPageFilters($pageFilters),
            employeeId: self::nullableInt($pageFilters['employeeId'] ?? null),
            customerId: self::nullableInt($pageFilters['customerId'] ?? null),
        );
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
