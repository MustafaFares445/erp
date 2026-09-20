<?php

declare(strict_types=1);

namespace App\Services\Crm\Timeline;

use App\Data\Crm\TimelineEvent;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\CustomerTimelineService;
use App\Services\Crm\Timeline\Sources\ActivitySource;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * One document (or event) family feeding the Customer 360 timeline
 * (CR-05) — its own SQL branch of the union and its own presentation, so
 * `CustomerTimelineService` stays a thin union/pagination/permission
 * pipeline instead of a nine-arm match statement.
 */
interface TimelineSource
{
    /**
     * The type discriminator this source contributes, matching
     * {@see CustomerTimelineService::TYPES}.
     */
    public function key(): string;

    /**
     * Whether the acting user may see this source at all, checked before
     * the union query runs (XC-05 — hiding a control is not authorisation).
     */
    public function permission(User $actor): bool;

    /**
     * The `id, type, occurred_at` projection unioned by the timeline query.
     *
     * `$actor` is unused by most sources — {@see ActivitySource}
     * is the exception, since its branches inherit their subject's own
     * permission rather than a single module permission.
     */
    public function subQuery(CustomerProfile $customer, User $actor, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder;

    /**
     * @param  list<int>  $ids
     * @return array<int, TimelineEvent>
     */
    public function hydrate(array $ids): array;
}
