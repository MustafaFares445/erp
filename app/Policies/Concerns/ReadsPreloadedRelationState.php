<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use LogicException;

/**
 * Lets a policy answer "does this record have related rows?" from state a list query already
 * loaded (`withCount()`, `withExists()` or an eager-loaded relation) instead of issuing one query
 * per table row. Whenever nothing was preloaded the original relation query still runs, so the
 * answer never changes — only where it comes from.
 */
trait ReadsPreloadedRelationState
{
    /**
     * @param  string|null  $flag  Attribute holding a preloaded `withExists()`/`withCount()` result when it
     *                             does not follow the default `{relation}_exists` / `{relation}_count` naming.
     */
    protected function hasRelated(Model $model, string $relation, ?string $flag = null): bool
    {
        $attributes = $model->getAttributes();
        $snake = Str::snake($relation);

        foreach ([$flag, $snake.'_exists', $snake.'_count'] as $key) {
            if ($key !== null && array_key_exists($key, $attributes)) {
                $value = $attributes[$key];

                return is_bool($value) ? $value : is_numeric($value) && (float) $value > 0;
            }
        }

        if ($model->relationLoaded($relation)) {
            $loaded = $model->getRelation($relation);

            if ($loaded instanceof Collection) {
                return $loaded->isNotEmpty();
            }

            return $loaded instanceof Model;
        }

        $query = $model->{$relation}();

        if (! $query instanceof Relation) {
            throw new LogicException(sprintf('[%s] is not a relation on %s.', $relation, $model::class));
        }

        return $query->exists();
    }
}
