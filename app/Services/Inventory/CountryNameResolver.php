<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use Illuminate\Support\Str;
use ResourceBundle;

final readonly class CountryNameResolver
{
    /** @return list<string> */
    public function matchingCodes(string $search): array
    {
        $needle = $this->normalize($search);

        if ($needle === '') {
            return [];
        }

        $matches = [];

        foreach (array_unique(['en', 'ar', app()->getLocale()]) as $locale) {
            foreach ($this->countryNames($locale) as $code => $name) {
                if ($this->matches($code, $name, $needle)) {
                    $matches[$code] = true;
                }
            }
        }

        return array_keys($matches);
    }

    /** @return array<string, string> */
    private function countryNames(string $locale): array
    {
        $bundle = ResourceBundle::create($locale, 'ICUDATA-region', true);
        $countries = $bundle?->get('Countries');

        $names = [];

        foreach (is_iterable($countries) ? $countries : [] as $code => $name) {
            if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1 && is_string($name)) {
                $names[$code] = $name;
            }
        }

        return $names;
    }

    private function matches(string $code, string $name, string $needle): bool
    {
        if (mb_strtolower($code) === $needle) {
            return true;
        }

        $normalizedName = $this->normalize($name);

        return str_contains($normalizedName, $needle)
            || str_contains(Str::ascii($normalizedName), Str::ascii($needle));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(Str::squish($value));
    }
}
