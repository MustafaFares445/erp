<?php

declare(strict_types=1);

use App\Enums\InventoryAlertType;
use App\Enums\WarrantyEntitlementState;
use Illuminate\Support\Facades\Lang;

it('translates every literal Inventory diagnostic rule, fact, action, check and limitation in both languages', function (): void {
    $sourceFiles = array_merge(
        glob(app_path('Services/Inventory/Diagnostics/*.php')) ?: [],
        glob(app_path('Filament/Components/*Inventory*.php')) ?: [],
        glob(resource_path('views/filament/inventory/*.blade.php')) ?: [],
        glob(app_path('Data/Inventory/*Diagnostic*.php')) ?: [],
    );

    $used = [];
    foreach ($sourceFiles as $file) {
        $source = (string) file_get_contents($file);

        preg_match_all('/\\$this->finding\\(\\s*[\\x27\\x22]([a-z][a-z0-9_]*(?:\\.[a-z][a-z0-9_]*)+)[\\x27\\x22]/', $source, $rules);
        foreach ($rules[1] as $code) {
            if (str_ends_with($code, '_')) {
                continue; // Dynamically constructed expiry/stage suffix, checked in runtime tests.
            }
            $used['rules.'.$code.'.title'] = true;
            $used['rules.'.$code.'.explanation'] = true;
        }

        foreach (['fact' => 'facts', 'actionLabel' => 'actions', 'check' => 'checks', 'limit' => 'limits'] as $method => $prefix) {
            preg_match_all('/\\$this->'.preg_quote($method, '/').'\\(\\s*[\\x27\\x22]([a-z][a-z0-9_]*)[\\x27\\x22]/', $source, $matches);
            foreach ($matches[1] as $code) {
                $used[$prefix.'.'.$code] = true;
            }
        }

        preg_match_all('/\\$this->(?:viewLink|indexLink|createLink)\\(\\s*[^,\\r\\n]+,\\s*[\\x27\\x22]([a-z][a-z0-9_]*)[\\x27\\x22]/', $source, $links);
        foreach ($links[1] as $code) {
            $used['actions.'.$code] = true;
        }

        preg_match_all('/__\\(\\s*[\\x27\\x22]inventory_view_ux\\.([a-z][a-z0-9_.]*)[\\x27\\x22]/', $source, $direct);
        foreach ($direct[1] as $code) {
            if (! str_ends_with($code, '.')) {
                $used[$code] = true;
            }
        }
    }

    expect(count($used))->toBeGreaterThan(300);

    foreach (['en', 'ar'] as $locale) {
        foreach (array_keys($used) as $key) {
            $name = 'inventory_view_ux.'.$key;
            expect(Lang::has($name, $locale, false))->toBeTrue(
                "Missing $locale Inventory diagnostic translation: $name",
            );

            $value = Lang::get($name, [], $locale, false);
            expect(is_string($value) && mb_trim($value) !== '' && $value !== $name)->toBeTrue(
                "Invalid $locale Inventory diagnostic translation: $name",
            );
        }
    }
});

it('keeps English and Arabic replacement placeholders aligned in extended Inventory diagnostics', function (): void {
    $english = require lang_path('en/inventory_view_ux_extended.php');
    $arabic = require lang_path('ar/inventory_view_ux_extended.php');

    $flat = static function (array $values) use (&$flat): array {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                foreach ($flat($value) as $path => $line) {
                    $result[$key.'.'.$path] = $line;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    };
    $en = $flat($english);
    $ar = $flat($arabic);

    expect(array_keys($en))->toEqual(array_keys($ar));

    foreach ($en as $key => $englishLine) {
        $arabicLine = $ar[$key];
        preg_match_all('/(?<!:):([a-z][a-z0-9_]*)/i', $englishLine, $enParameters);
        preg_match_all('/(?<!:):([a-z][a-z0-9_]*)/i', $arabicLine, $arParameters);
        $enPlaceholders = array_values(array_unique($enParameters[1]));
        $arPlaceholders = array_values(array_unique($arParameters[1]));
        sort($enPlaceholders);
        sort($arPlaceholders);
        expect($arPlaceholders)->toEqual($enPlaceholders);
    }
});

it('shows reviewed operator explanations instead of generic diagnostic boilerplate in both languages', function (): void {
    $reviewedEnglish = require lang_path('en/inventory_view_ux_reviewed.php');
    $reviewedArabic = require lang_path('ar/inventory_view_ux_reviewed.php');
    $total = 0;

    foreach ($reviewedEnglish as $group => $rules) {
        foreach ($rules as $rule => $messages) {
            $total++;
            expect(array_keys($reviewedArabic[$group][$rule]))->toEqual(array_keys($messages));

            foreach (['en', 'ar'] as $locale) {
                $title = __('inventory_view_ux.rules.'.$group.'.'.$rule.'.title', [], $locale);
                $explanation = __('inventory_view_ux.rules.'.$group.'.'.$rule.'.explanation', [], $locale);

                expect($title)->not->toContain('inventory_view_ux')
                    ->and($explanation)->not->toContain('inventory_view_ux');

                if ($locale === 'en') {
                    expect($explanation)->not->toContain('The recorded checks indicate');
                } else {
                    expect($explanation)->not->toContain('تشير البيانات المسجلة إلى:')
                        ->and(preg_match('/[\\x{0600}-\\x{06FF}]/u', $title))->toBe(1)
                        ->and(preg_match('/[\\x{0600}-\\x{06FF}]/u', $explanation))->toBe(1);
                }
            }
        }
    }

    expect($total)->toBe(102);
});

it('covers Inventory View labels referenced by actual Filament resources in English and Arabic', function (): void {
    $resources = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Filament/Resources'), FilesystemIterator::SKIP_DOTS),
    );
    $keys = [];
    foreach ($resources as $resource) {
        if (! $resource->isFile() || $resource->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($resource->getPathname());
        preg_match_all('/__\(\s*[\x27\x22]inventory_view_ux\.(ui\.[a-z0-9_]+)[\x27\x22]/', $code, $matches);
        foreach ($matches[1] as $key) {
            $keys[$key] = true;
        }
    }

    expect(count($keys))->toBeGreaterThan(60);

    foreach (['en', 'ar'] as $locale) {
        foreach (array_keys($keys) as $key) {
            $path = 'inventory_view_ux.'.$key;
            expect(Lang::has($path, $locale, false))->toBeTrue("Missing $locale Filament UI label: $path")
                ->and(Lang::get($path, [], $locale, false))->toBeString()->not->toBeEmpty();
        }
    }
});

it('provides translated nested serial custody, warranty and coverage labels', function (): void {
    $groups = [
        'serial_reference_types' => ['customer', 'warehouse', 'inventory_operation'],
        'serial_warranty_states' => array_map(static fn (WarrantyEntitlementState $state): string => $state->value, WarrantyEntitlementState::cases()),
        'serial_coverage_categories' => ['parts', 'labour', 'travel', 'consumables', 'third_party'],
    ];

    foreach (['en', 'ar'] as $locale) {
        foreach ($groups as $group => $cases) {
            foreach ($cases as $name) {
                $key = "inventory_view_ux.ui.$group.$name";
                expect(Lang::has($key, $locale, false))->toBeTrue("Missing $locale serial translation: $key")
                    ->and(Lang::get($key, [], $locale, false))->toBeString()->not->toBeEmpty();
            }
        }
    }
});

it('keeps all Inventory View UI placeholders aligned between English and Arabic', function (): void {
    $english = require lang_path('en/inventory_view_ui.php');
    $arabic = require lang_path('ar/inventory_view_ui.php');

    $flatten = static function (array $data, string $prefix = '') use (&$flatten): array {
        $result = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $result += $flatten($value, $name);
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    };

    $en = $flatten($english);
    $ar = $flatten($arabic);
    expect(array_keys($en))->toEqual(array_keys($ar));

    foreach ($en as $key => $translation) {
        preg_match_all('/:([a-z][a-z0-9_]*)/i', $translation, $englishMatches);
        preg_match_all('/:([a-z][a-z0-9_]*)/i', $ar[$key], $arabicMatches);
        $englishArgs = array_values(array_unique($englishMatches[1]));
        $arabicArgs = array_values(array_unique($arabicMatches[1]));
        sort($englishArgs);
        sort($arabicArgs);
        expect($englishArgs)->toEqual($arabicArgs, "Placeholder mismatch for $key");
    }
});

it('translates all dynamic alert types and outbound planning choices for both languages', function (): void {
    foreach (['en', 'ar'] as $locale) {
        foreach (InventoryAlertType::cases() as $type) {
            foreach (['title', 'explanation'] as $part) {
                $key = 'inventory_view_ux.rules.alert.'.$type->value.'.'.$part;
                expect(Lang::has($key, $locale, false))
                    ->toBeTrue("Missing $locale dynamic alert translation: $key");
            }
        }

        foreach (['no_plan', 'partially_planned', 'unlinked_delivery'] as $rule) {
            foreach (['title', 'explanation'] as $part) {
                $key = 'inventory_view_ux.rules.outbound.'.$rule.'.'.$part;
                expect(Lang::has($key, $locale, false))
                    ->toBeTrue("Missing $locale outbound planning translation: $key");
            }
        }

        foreach (['alert_open_stock', 'alert_open_operation', 'alert_open_unit', 'open_lot'] as $action) {
            $key = 'inventory_view_ux.actions.'.$action;
            expect(Lang::has($key, $locale, false))
                ->toBeTrue("Missing $locale alert action: $key");
        }

        $message = trans_choice('inventory_view_explanations.workflow_summary', 1, ['count' => 1], $locale);
        expect($message)->not->toContain(':count')->not->toContain('inventory_view_explanations.');
    }
});
