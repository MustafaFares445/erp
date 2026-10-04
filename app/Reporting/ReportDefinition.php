<?php

declare(strict_types=1);

namespace App\Reporting;

use Filament\Resources\Resource;
use Throwable;

/**
 * Read-only metadata for one report entry.
 *
 * The definition never owns reporting business logic. It only describes how a
 * user discovers a domain-owned report and how to open its canonical page.
 */
final readonly class ReportDefinition
{
    /**
     * @param  class-string<resource>  $resource
     * @param  array<string, scalar|null>  $parameters
     */
    public function __construct(
        public string $key,
        public string $domain,
        public string $category,
        public string $label,
        public string $description,
        public string $resource,
        public array $parameters = [],
    ) {}

    public function url(): ?string
    {
        try {
            if (! $this->resource::canAccess()) {
                return null;
            }

            return $this->resource::getUrl('index', $this->parameters);
        } catch (Throwable) {
            return null;
        }
    }
}
