<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Keeps staged Support capabilities inaccessible through both navigation
 * and direct Filament URLs until their rollout switch is enabled.
 *
 * Policy checks are still delegated to Filament's Resource parent methods,
 * so enabling a feature never bypasses the support permission catalogue.
 */
trait RequiresSupportFeature
{
    abstract protected static function supportFeatureFlag(): string;

    protected static function supportFeatureEnabled(): bool
    {
        return (bool) config(static::supportFeatureFlag(), false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::supportFeatureEnabled()
            && parent::shouldRegisterNavigation();
    }

    public static function canViewAny(): bool
    {
        return static::supportFeatureEnabled()
            && parent::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::supportFeatureEnabled()
            && parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return static::supportFeatureEnabled()
            && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::supportFeatureEnabled()
            && parent::canDelete($record);
    }
}
