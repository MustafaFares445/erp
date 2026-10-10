<?php

declare(strict_types=1);

use App\Filament\AdminModuleRegistry;
use Filament\Resources\Resource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$target = __DIR__.'/../user-guide/content/admin-manifest.json';
$mode = $argv[1] ?? '--check';

$translate = static function (string $key): array {
    app()->setLocale('en');
    $en = __($key);
    app()->setLocale('ar');
    $ar = __($key);
    app()->setLocale('en');

    return [
        'label_key' => $key,
        'label_en' => $en,
        'label_ar' => $ar,
    ];
};

/** @var array<string, array{name: ?string, uri: string, parameters: list<string>}> $routesByAction */
$routesByAction = [];

foreach (Route::getRoutes() as $route) {
    if (! $route instanceof LaravelRoute) {
        continue;
    }

    $action = $route->getActionName();

    if (! is_string($action) || ! str_starts_with($action, 'App\\Filament\\')) {
        continue;
    }

    $routesByAction[$action] = [
        'name' => $route->getName(),
        'uri' => $route->uri(),
        'parameters' => array_values($route->parameterNames()),
    ];
}

$routeFor = static function (string $class) use ($routesByAction): array {
    $route = $routesByAction[$class] ?? null;

    if ($route === null) {
        return [
            'route_name' => null,
            'route_uri' => null,
            'route_parameters' => [],
        ];
    }

    return [
        'route_name' => $route['name'],
        'route_uri' => $route['uri'],
        'route_parameters' => $route['parameters'],
    ];
};

$modelFor = static function (string $class): ?string {
    if (! is_subclass_of($class, Resource::class)) {
        return null;
    }

    try {
        return $class::getModel();
    } catch (Throwable) {
        return null;
    }
};

$pagesFor = static function (string $class) use ($routeFor): array {
    if (! is_subclass_of($class, Resource::class)) {
        return [];
    }

    try {
        $pages = [];

        foreach ($class::getPages() as $key => $registration) {
            $pageClass = $registration->getPage();
            $pages[] = [
                'key' => (string) $key,
                'page_class' => $pageClass,
                ...$routeFor($pageClass),
            ];
        }

        return $pages;
    } catch (Throwable) {
        return [];
    }
};

$entryMetadata = static function (string $class) use ($routeFor, $modelFor): array {
    return [
        'model' => $modelFor($class),
        ...$routeFor($class),
    ];
};

$out = [];

foreach (AdminModuleRegistry::groups() as $group) {
    $groupData = [
        'key' => $group['key'],
        ...$translate($group['label']),
        'sort' => $group['sort'],
        'sections' => [],
        'items' => [],
        'contextual' => [],
    ];

    foreach ($group['sections'] ?? [] as $section) {
        $groupData['sections'][] = [
            'key' => $section['key'],
            ...$translate($section['label']),
        ];
    }

    foreach ($group['items'] as $item) {
        $entry = [
            ...$translate($item['label']),
            'class' => $item['link'],
            ...$entryMetadata($item['link']),
            'section' => $item['section'] ?? null,
            'page' => $item['page'] ?? null,
            'tabs' => [],
            'tools' => [],
            'pages' => $pagesFor($item['link']),
        ];

        foreach ($item['tabs'] ?? [] as $tab) {
            $entry['tabs'][] = [
                ...$translate($tab['label']),
                'class' => $tab['link'],
                ...$entryMetadata($tab['link']),
                'page' => $tab['page'] ?? null,
                'pages' => $pagesFor($tab['link']),
            ];
        }

        foreach ($item['tools'] ?? [] as $tool) {
            $entry['tools'][] = [
                ...$translate($tool['label']),
                'class' => $tool['link'],
                ...$entryMetadata($tool['link']),
            ];
        }

        $groupData['items'][] = $entry;
    }

    foreach ($group['contextual'] ?? [] as $class) {
        $groupData['contextual'][] = [
            'class' => $class,
            ...$entryMetadata($class),
            'pages' => $pagesFor($class),
        ];
    }

    $out[] = $groupData;
}

$json = json_encode(
    $out,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
)."\n";

if ($mode === '--write') {
    file_put_contents($target, $json);
    fwrite(STDOUT, "Updated user-guide/content/admin-manifest.json\n");
    exit(0);
}

if (! is_file($target)) {
    fwrite(STDERR, "User guide admin manifest is missing. Run: php scripts/user-guide-manifest.php --write\n");
    exit(1);
}

$current = file_get_contents($target);

if ($current !== $json) {
    fwrite(STDERR, "User guide admin manifest is stale. Run: npm --prefix user-guide run docs:generate\n");
    exit(1);
}

fwrite(STDOUT, "User guide admin manifest matches the current Filament registry.\n");
