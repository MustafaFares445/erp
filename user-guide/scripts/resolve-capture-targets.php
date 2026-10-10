<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Resources\Resource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dbConnection = config()->string('database.default');
$dbDatabase = (string) config("database.connections.{$dbConnection}.database");

if ($dbConnection !== 'sqlite' || ! str_ends_with(str_replace('\\', '/', $dbDatabase), '/tmp/user-guide-demo.sqlite')) {
    fwrite(STDERR, "Refusing to resolve capture targets outside the isolated tmp/user-guide-demo.sqlite database.\n");
    exit(1);
}

$actor = User::query()->where('email', 'demo.admin@ierp.test')->first();

if (! $actor instanceof User) {
    fwrite(STDERR, "Demo admin is missing. Run the screenshot demo preparation script first.\n");
    exit(1);
}

Auth::login($actor);

$source = __DIR__.'/../content/screenshots.manifest.json';
$target = __DIR__.'/../../tmp/user-guide-capture-targets.json';

if (! is_file($source)) {
    fwrite(STDERR, "Screenshot manifest is missing. Run npm --prefix user-guide run docs:generate first.\n");
    exit(1);
}

/** @var list<array<string, mixed>> $screens */
$screens = json_decode((string) file_get_contents($source), true, flags: JSON_THROW_ON_ERROR);

$resolved = [];
$missingRoute = 0;
$missingRecord = 0;

foreach ($screens as $screen) {
    $routeUri = $screen['route_uri'] ?? null;
    $routeParameters = array_values($screen['route_parameters'] ?? []);
    $capture = [
        ...$screen,
        'capture_url' => null,
        'capture_ready' => false,
        'capture_reason' => null,
        'record_route_key' => null,
    ];

    if (! is_string($routeUri) || $routeUri === '') {
        $capture['capture_reason'] = 'No registered Filament GET route is available for this surface.';
        $missingRoute++;
        $resolved[] = $capture;

        continue;
    }

    if ($routeParameters === []) {
        $capture['capture_url'] = '/'.mb_ltrim($routeUri, '/');
        $captureQuery = $screen['capture_query'] ?? [];

        if (is_array($captureQuery) && $captureQuery !== []) {
            $capture['capture_url'] .= '?'.http_build_query($captureQuery, encoding_type: PHP_QUERY_RFC3986);
        }

        $capture['capture_ready'] = true;
        $resolved[] = $capture;

        continue;
    }

    if ($routeParameters !== ['record']) {
        $capture['capture_reason'] = 'Unsupported route parameters: '.implode(', ', $routeParameters);
        $missingRoute++;
        $resolved[] = $capture;

        continue;
    }

    $modelClass = $screen['model'] ?? null;

    if (! is_string($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
        $capture['capture_reason'] = 'Record route has no resolvable Eloquent model.';
        $missingRecord++;
        $resolved[] = $capture;

        continue;
    }

    $resourceClass = $screen['resource'] ?? null;
    $pageKey = (string) ($screen['page'] ?? 'view');
    $model = new $modelClass;

    /** @var Model|null $record */
    $record = null;

    $query = $modelClass::query();

    if (is_string($resourceClass) && is_subclass_of($resourceClass, Resource::class)) {
        try {
            // Respect the Resource query itself (for example Delivery Notes are a filtered
            // InventoryOperation surface). A model-level query can resolve a route key that
            // Filament correctly rejects as not belonging to that Resource.
            $query = $resourceClass::getEloquentQuery();
        } catch (Throwable) {
            $query = $modelClass::query();
        }
    }

    foreach ($query->orderBy($model->getKeyName())->limit(250)->cursor() as $candidate) {
        if (! $candidate instanceof Model) {
            continue;
        }

        if (is_string($resourceClass) && is_subclass_of($resourceClass, Resource::class)) {
            try {
                $allowed = $pageKey === 'edit'
                    ? $resourceClass::canEdit($candidate)
                    : $resourceClass::canView($candidate);
            } catch (Throwable) {
                $allowed = false;
            }

            if (! $allowed) {
                continue;
            }
        }

        $record = $candidate;
        break;
    }

    if (! $record instanceof Model) {
        $capture['capture_reason'] = 'The isolated demo database has no record that is accessible for this screen/state.';
        $missingRecord++;
        $resolved[] = $capture;

        continue;
    }

    $routeKey = (string) $record->getRouteKey();
    $capture['record_route_key'] = $routeKey;
    $capture['capture_url'] = '/'.mb_ltrim(str_replace('{record}', rawurlencode($routeKey), $routeUri), '/');
    $capture['capture_ready'] = true;
    $resolved[] = $capture;
}

$json = json_encode(
    $resolved,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
)."\n";

if (($argv[1] ?? null) === '--write') {
    file_put_contents($target, $json);
}

$ready = count(array_filter($resolved, static fn (array $item): bool => $item['capture_ready'] === true));

fwrite(STDOUT, sprintf(
    'Screenshot capture targets: %d total, %d ready, %d without route, %d without demo record.%s',
    count($resolved),
    $ready,
    $missingRoute,
    $missingRecord,
    PHP_EOL,
));

if (($argv[1] ?? null) !== '--write') {
    fwrite(STDOUT, $json);
}
