<?php

declare(strict_types=1);

namespace App\Enums;

enum ServiceAppointmentStatus: string
{
    case Planned = 'planned';
    case Dispatched = 'dispatched';
    case EnRoute = 'en_route';
    case OnSite = 'on_site';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => __('Planned'),
            self::Dispatched => __('Dispatched'),
            self::EnRoute => __('En route'),
            self::OnSite => __('On site'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Dispatched => 'info',
            self::EnRoute => 'warning',
            self::OnSite => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planned => [self::Dispatched, self::Cancelled],
            self::Dispatched => [self::EnRoute, self::OnSite, self::Cancelled],
            self::EnRoute => [self::OnSite, self::Cancelled],
            self::OnSite => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
