<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/** @return list<string> */
function dashboardTranslationKeys(string $locale): array
{
    /** @var array<string, mixed> $translations */
    $translations = require lang_path($locale.'/dashboards.php');

    return array_keys(Arr::dot($translations));
}

it('ships the same dashboard translation keys in English and Arabic', function (): void {
    $english = dashboardTranslationKeys('en');
    $arabic = dashboardTranslationKeys('ar');

    expect(array_values(array_diff($english, $arabic)))->toBe([])
        ->and(array_values(array_diff($arabic, $english)))->toBe([]);
});

it('defines every dashboard translation key the code uses', function (): void {
    $defined = dashboardTranslationKeys('en');
    $used = [];

    foreach ([app_path(), resource_path('views')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            preg_match_all("/__\\('dashboards\\.([a-z0-9_.]+?)'/", $file->getContents(), $matches);
            array_push($used, ...$matches[1]);
        }
    }

    // Keys built from a prefix plus a runtime suffix, e.g. a status value.
    $used = array_filter(array_unique($used), static fn (string $key): bool => ! str_ends_with($key, '.'));

    expect(array_values(array_diff($used, $defined)))->toBe([]);
});
