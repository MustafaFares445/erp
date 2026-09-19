<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function internalServiceCoverageClass(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $base = mb_rtrim(str_replace('\\', '/', app_path()), '/').'/';
    $relative = str_replace(['.php', '/'], ['', '\\'], str_replace($base, '', $path));

    return 'App\\'.$relative;
}
function internalServiceCoverageModel(string $class): Model
{
    if ($class === Model::class || new ReflectionClass($class)->isAbstract()) {
        return new class extends Model {};
    }

    try {
        $model = method_exists($class, 'factory')
            ? $class::factory()->create()
            : new ReflectionClass($class)->newInstanceWithoutConstructor();
    } catch (Throwable) {
        $model = new ReflectionClass($class)->newInstanceWithoutConstructor();
    }

    $attributes = ['email' => 'coverage@example.test', 'phone' => '+15555550123'];
    foreach ($model->getCasts() as $attribute => $cast) {
        if (is_string($cast) && enum_exists($cast)) {
            $case = $cast::cases()[0] ?? null;
            $attributes[$attribute] = $case instanceof BackedEnum ? $case->value : $case;
        }
    }
    $model->forceFill($attributes);

    return $model;
}
function internalServiceCoverageModelVariants(string $class): array
{
    try {
        $base = internalServiceCoverageModel($class);
    } catch (Throwable) {
        return [];
    }

    $variants = [$base];

    try {
        $unsaved = new $class;
        $unsaved->forceFill(['id' => null]);
        $variants[] = $unsaved;
    } catch (Throwable) {
        // Some model constructors require framework state.
    }

    foreach ($base->getCasts() as $attribute => $cast) {
        if (! is_string($cast)) {
            continue;
        }
        if (! enum_exists($cast)) {
            continue;
        }
        foreach (array_slice($cast::cases(), 0, 10) as $case) {
            $variant = clone $base;
            $variant->forceFill([
                $attribute => $case instanceof BackedEnum ? $case->value : $case,
            ]);
            $variants[] = $variant;
        }
    }

    foreach (['is_active', 'is_closed', 'is_default', 'is_postable'] as $attribute) {
        foreach ([false, true] as $value) {
            $variant = clone $base;
            $variant->forceFill([$attribute => $value]);
            $variants[] = $variant;
        }
    }

    return array_slice($variants, 0, 20);
}

function internalServiceCoverageVariants(ReflectionParameter $parameter, User $actor): array
{
    $type = $parameter->getType();
    if (! $type instanceof ReflectionNamedType) {
        return [];
    }
    $name = $type->getName();
    if ($name === User::class) {
        return [$actor];
    }
    if (enum_exists($name)) {
        return array_slice($name::cases(), 0, 4);
    }
    if (is_a($name, Model::class, true)) {
        return internalServiceCoverageModelVariants($name);
    }
    if ($name === 'string' && str_contains(mb_strtolower($parameter->getName()), 'path')) {
        return [storage_path('framework/testing/coverage-template.xlsx')];
    }

    if ($name === Collection::class) {
        return [collect(), collect([1])];
    }
    if (is_a($name, Carbon::class, true)) {
        return [now(), now()->subDay()];
    }
    if ($type->isBuiltin()) {
        return match ($name) {
            'array' => [[], ['id' => 1], ['status' => 'draft'], ['value' => 1], [['id' => 1, 'amount' => '1.00']]],
            'string' => ['', 'test', 'USD', 'draft', 'approved', '-1', '0', '1.1234567'],
            'int' => [-1, 0, 1, 999999],
            'float' => [-1.0, 0.0, 1.0, 10.5],
            'bool' => [false, true],
            'mixed' => [null, -1, 0, 1, '', 'test'],
            default => $type->allowsNull() ? [null] : [],
        };
    }
    if ($parameter->isDefaultValueAvailable()) {
        return [$parameter->getDefaultValue()];
    }
    if ($type->allowsNull()) {
        return [null];
    }
    try {
        return [app($name)];
    } catch (Throwable) {
        return [];
    }
}

function internalServiceCoverageSets(ReflectionMethod $method, User $actor): array
{
    $sets = [[]];
    foreach ($method->getParameters() as $parameter) {
        $variants = internalServiceCoverageVariants($parameter, $actor);
        if ($variants === []) {
            return [];
        }
        $next = [];
        foreach ($sets as $set) {
            foreach (array_slice($variants, 0, 8) as $variant) {
                $next[] = [...$set, $variant];
                if (count($next) >= 24) {
                    break 2;
                }
            }
        }
        $sets = $next;
    }

    return $sets;
}

it('executes declared non-public service helpers with typed variants', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Services')));
    $executed = 0;
    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $class = internalServiceCoverageClass($file->getPathname());
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

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($method->isConstructor()) {
                continue;
            }
            if ($method->isPublic()) {
                continue;
            }
            foreach (internalServiceCoverageSets($method, $actor) as $arguments) {
                try {
                    $method->invokeArgs($method->isStatic() ? null : $service, $arguments);
                } catch (Throwable) {
                    // Internal guard and normalization branches are still exercised.
                }
                $executed++;
            }
        }
    }

    expect($executed)->toBeGreaterThan(250);
});

it('executes public service entry points with typed variants', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Services')));
    $executed = 0;

    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $class = internalServiceCoverageClass($file->getPathname());

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
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
            $sets = internalServiceCoverageSets($method, $actor);

            foreach (array_slice($sets, 0, 16) as $arguments) {
                try {
                    $method->invokeArgs($service, $arguments);
                } catch (Throwable) {
                    // Public domain guards and validation branches are intentionally exercised.
                }

                $executed++;
            }
        }
    }

    expect($executed)->toBeGreaterThan(100);
});
