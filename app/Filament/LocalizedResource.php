<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

use function Filament\Support\get_model_label;

/**
 * @template TModel of Model = Model
 *
 * @extends resource<TModel>
 */
abstract class LocalizedResource extends Resource
{
    public static function getModelLabel(): string
    {
        $label = static::$modelLabel
            ?? static::getLabel()
            ?? get_model_label(static::getModel());

        return __($label);
    }

    public static function getPluralModelLabel(): string
    {
        $label = static::$pluralModelLabel ?? static::getPluralLabel();

        if (filled($label)) {
            return __($label);
        }
        $singularLabel = static::$modelLabel
            ?? static::getLabel()
            ?? get_model_label(static::getModel());

        return __(Str::plural($singularLabel));
    }

    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }
}
