<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

final class CustomerLocationPicker extends Field
{
    protected string $view = 'filament.forms.components.customer-location-picker';

    public function getLongitudeStatePath(): string
    {
        return (string) str($this->getStatePath())->replaceEnd('latitude', 'longitude');
    }
}
