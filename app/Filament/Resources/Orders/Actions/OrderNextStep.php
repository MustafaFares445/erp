<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Actions;

use Filament\Support\Icons\Heroicon;

final readonly class OrderNextStep
{
    public function __construct(
        public string $label,
        public Heroicon $icon,
        public string $url,
    ) {}
}
