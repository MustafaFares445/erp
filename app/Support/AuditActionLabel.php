<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns machine audit action keys (`sales.invoice.issued`) into readable,
 * translated labels (`lang/{locale}/audit.php`). Keys without a translation
 * fall back to a humanized form of the key, so new actions never render raw.
 */
final class AuditActionLabel
{
    public static function for(?string $action): string
    {
        if ($action === null || $action === '') {
            return '—';
        }

        $label = self::translations()[$action] ?? null;

        return is_string($label) ? $label : Str::of($action)->replace(['.', '_'], ' ')->squish()->ucfirst()->toString();
    }

    /**
     * Action options for filters: every action present in the log, keyed by its stored key.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::loggedActions()
            ->mapWithKeys(static fn (string $action): array => [$action => self::for($action)])
            ->sort()
            ->all();
    }

    /**
     * Constrain a query to rows whose action key or readable label contains the search term.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public static function search(Builder $query, string $search): Builder
    {
        $term = mb_strtolower(mb_trim($search));

        $matches = self::loggedActions()
            ->filter(static fn (string $action): bool => str_contains(mb_strtolower($action), $term)
                || str_contains(mb_strtolower(self::for($action)), $term))
            ->values()
            ->all();

        return $query->whereIn('description', $matches);
    }

    /**
     * @return Collection<int, string>
     */
    private static function loggedActions(): Collection
    {
        /** @var Collection<int, string> $actions */
        $actions = AuditLog::query()->whereNotNull('description')->distinct()->pluck('description');

        return $actions;
    }

    /**
     * @return array<mixed>
     */
    private static function translations(): array
    {
        $translations = trans('audit.actions');

        return is_array($translations) ? $translations : [];
    }
}
