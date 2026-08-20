<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FiscalPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalPeriod>
 */
final class FiscalPeriodFactory extends Factory
{
    /**
     * Defaults to the current calendar month, so a factory-made period contains
     * `now()` and an entry dated today posts without further setup. Tests
     * needing a specific range use {@see self::forMonth()} or
     * {@see self::between()}, both of which produce non-overlapping ranges by
     * construction — overlap is refused by FR-015.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfMonth();

        return [
            'name' => $start->format('F Y'),
            'starts_at' => $start->toDateString(),
            'ends_at' => $start->endOfMonth()->toDateString(),
            'is_closed' => false,
        ];
    }

    public function closed(): self
    {
        return $this->state(fn (): array => ['is_closed' => true]);
    }

    public function forMonth(CarbonImmutable $month): self
    {
        $start = $month->startOfMonth();

        return $this->state(fn (): array => [
            'name' => $start->format('F Y'),
            'starts_at' => $start->toDateString(),
            'ends_at' => $start->endOfMonth()->toDateString(),
        ]);
    }

    public function between(CarbonImmutable $startsAt, CarbonImmutable $endsAt): self
    {
        return $this->state(fn (): array => [
            'name' => $startsAt->toDateString().' - '.$endsAt->toDateString(),
            'starts_at' => $startsAt->toDateString(),
            'ends_at' => $endsAt->toDateString(),
        ]);
    }
}
