<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SupportPermission;
use App\Models\Ticket;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

final class SupportTicketTrend extends ChartWidget
{
    protected ?string $heading = 'Ticket trend';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::TicketView->value) ?? false;
    }

    #[\Override]
    protected function getData(): array
    {
        /** @var Collection<int, Carbon> $months */
        $months = collect(range(5, 0))
            ->map(fn (int $offset): Carbon => now()->startOfMonth()->subMonths($offset));

        $openedCounts = $this->countByMonth(Ticket::query()->pluck('created_at'));
        $resolvedCounts = $this->countByMonth(Ticket::query()->whereNotNull('resolved_at')->pluck('resolved_at'));

        return [
            'datasets' => [
                [
                    'label' => 'Opened',
                    'data' => $months->map(fn (Carbon $month): int => $openedCounts->get($month->format('Y-m'), 0))->all(),
                ],
                [
                    'label' => 'Resolved',
                    'data' => $months->map(fn (Carbon $month): int => $resolvedCounts->get($month->format('Y-m'), 0))->all(),
                ],
            ],
            'labels' => $months->map(fn (Carbon $month): string => $month->format('M Y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @param  Collection<int, \DateTimeInterface|string|null>  $dates
     * @return Collection<string, int>
     */
    private function countByMonth(Collection $dates): Collection
    {
        return $dates
            ->filter()
            ->map(fn (\DateTimeInterface|string $date): string => Carbon::parse($date)->format('Y-m'))
            ->countBy();
    }
}
