<?php

declare(strict_types=1);

namespace App\Data\Support;

use App\Enums\CustomerSupportStage;

final readonly class CustomerSupportState
{
    public function __construct(
        public CustomerSupportStage $stage,
        public bool $actionRequired,
        public ?string $actionType,
        public ?string $message,
        public string $nextExpectedEvent,
    ) {}
}
