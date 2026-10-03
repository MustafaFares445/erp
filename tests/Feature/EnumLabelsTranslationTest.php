<?php

declare(strict_types=1);

use App\Enums\Concerns\HasTranslatedLabel;
use Illuminate\Support\Facades\App;

/**
 * @return array<string, class-string<BackedEnum>>
 */
function translatedLabelEnums(): array
{
    $enums = [];

    foreach (glob(app_path('Enums/*.php')) ?: [] as $file) {
        $class = 'App\\Enums\\'.basename($file, '.php');

        if (enum_exists($class) && in_array(HasTranslatedLabel::class, class_uses($class) ?: [], true)) {
            $enums[class_basename($class)] = $class;
        }
    }

    return $enums;
}

it('has at least one enum using translated labels', function (): void {
    expect(translatedLabelEnums())->not->toBeEmpty();
});

it('translates every enum case label in both locales', function (string $locale): void {
    $original = App::getLocale();
    App::setLocale($locale);

    try {
        foreach (translatedLabelEnums() as $name => $class) {
            foreach ($class::cases() as $case) {
                $label = $case->label();

                expect($label)->not->toBeEmpty("{$name}::{$case->name} has an empty {$locale} label")
                    ->and(str_starts_with($label, 'enums.'))->toBeFalse("{$name}::{$case->name} is missing a {$locale} translation");
            }
        }
    } finally {
        App::setLocale($original);
    }
})->with(['en', 'ar']);
