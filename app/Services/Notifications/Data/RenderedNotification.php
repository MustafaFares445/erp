<?php

declare(strict_types=1);

namespace App\Services\Notifications\Data;

final readonly class RenderedNotification
{
    public function __construct(
        public string $templateKey,
        public string $locale,
        public ?string $subject,
        public string $body,
    ) {}
}
