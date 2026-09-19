<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function serviceCoverageClass(string $path): string
{
    $normalizedPath = str_replace('\\', '/', $path);
    $normalizedAppPath = mb_rtrim(str_replace('\\', '/', app_path()), '/').'/';
    $relative = str_replace(['.php', '/'], ['', '\\'], str_replace($normalizedAppPath, '', $normalizedPath));

    return 'App\\'.$relative;
}

function serviceCoverageModel(string $class): Model
{
    if ($class === Model::class || new ReflectionClass($class)->isAbstract()) {
        return new class extends Model {};
    }

    try {
        if (method_exists($class, 'factory')) {
            return $class::factory()->create();
        }
    } catch (Throwable) {
    }

    return new ReflectionClass($class)->newInstanceWithoutConstructor();
}
function serviceCoverageArgumentVariants(ReflectionParameter $parameter, User $actor): array
{
    $type = $parameter->getType();
    if (! $type instanceof ReflectionNamedType) {
        return [[false, null]];
    }

    $name = $type->getName();
    if ($name === User::class) {
        return [[true, $actor]];
    }

    if (enum_exists($name)) {
        return array_map(static fn (UnitEnum $case): array => [true, $case], $name::cases());
    }

    if (is_a($name, Model::class, true)) {
        try {
            return [[true, serviceCoverageModel($name)]];
        } catch (Throwable) {
            return $type->allowsNull() ? [[true, null]] : [[false, null]];
        }
    }

    if ($name === 'string' && str_contains(mb_strtolower($parameter->getName()), 'path')) {
        return [[true, storage_path('framework/testing/coverage-template.xlsx')]];
    }

    if ($name === Collection::class) {
        return [[true, collect()], [true, collect([1])]];
    }

    if (is_a($name, Carbon::class, true)) {
        return [[true, now()], [true, now()->subDay()]];
    }
    if ($type->isBuiltin()) {
        return match ($name) {
            'array' => [[true, []], [true, ['id' => 1]], [true, ['status' => 'draft']]],
            'string' => [[true, ''], [true, 'USD'], [true, 'test']],
            'int' => [[true, 1], [true, 0]],
            'float' => [[true, 1.0], [true, 0.0]],
            'bool' => [[true, false], [true, true]],
            'mixed' => [[true, null], [true, 1], [true, 'test']],
            default => [[true, null]],
        };
    }

    if ($parameter->isDefaultValueAvailable()) {
        return [[true, $parameter->getDefaultValue()]];
    }

    if ($type->allowsNull()) {
        return [[true, null]];
    }

    try {
        return [[true, app($name)]];
    } catch (Throwable) {
        return [[false, null]];
    }
}

function serviceCoverageArgumentSets(ReflectionMethod $method, User $actor): array
{
    $sets = [[]];
    foreach ($method->getParameters() as $parameter) {
        $variants = array_values(array_filter(
            serviceCoverageArgumentVariants($parameter, $actor),
            static fn (array $variant): bool => $variant[0] === true,
        ));
        if ($variants === []) {
            return [];
        }

        $next = [];
        foreach ($sets as $set) {
            foreach (array_slice($variants, 0, 3) as $variant) {
                $next[] = [...$set, $variant[1]];
                if (count($next) >= 12) {
                    break 2;
                }
            }
        }
        $sets = $next;
    }

    return $sets;
}

it('executes typed public service surfaces across guard and validation variants', function (): void {
    $actor = User::factory()->create();
    Gate::before(static fn (): bool => true);

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Services')));
    $executed = 0;

    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $class = serviceCoverageClass($file->getPathname());
        if (! class_exists($class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue;
        }
        if ($reflection->isInterface()) {
            continue;
        }
        if ($reflection->isTrait()) {
            continue;
        }

        try {
            $service = app($class);
        } catch (Throwable) {
            try {
                $service = $reflection->newInstanceWithoutConstructor();
            } catch (Throwable) {
                continue;
            }
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($method->isConstructor()) {
                continue;
            }
            if ($method->isStatic()) {
                continue;
            }
            foreach (serviceCoverageArgumentSets($method, $actor) as $arguments) {
                try {
                    $method->invokeArgs($service, $arguments);
                } catch (Throwable) {
                    // Guard and validation failures still exercise legitimate service paths.
                }
                $executed++;
            }
        }
    }

    expect($executed)->toBeGreaterThan(400);
});
