<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function domainPrivateClassFromPath(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $base = mb_rtrim(str_replace('\\', '/', app_path()), '/').'/';
    $relative = str_replace(['.php', '/'], ['', '\\'], str_replace($base, '', $normalized));

    return 'App\\'.$relative;
}

/** @return list<mixed> */
function domainPrivateVariants(ReflectionParameter $parameter, User $actor): array
{
    $type = $parameter->getType();
    if (! $type instanceof ReflectionNamedType) {
        return $parameter->isDefaultValueAvailable() ? [$parameter->getDefaultValue()] : [null, '', 0, []];
    }

    $name = $type->getName();
    if ($type->isBuiltin()) {
        return match ($name) {
            'string' => ['', 'test', 'draft', 'pending', 'approved', 'paid', 'customer', 'supplier', 'manual', 'AED', 'USD', '1'],
            'int' => [0, 1, -1, 999999],
            'float' => [0.0, 0.01, 1.0, -1.0, 100.0],
            'bool' => [false, true],
            'array' => [[], ['id' => 1], ['status' => 'draft'], ['status' => 'approved'], ['value' => 1], ['amount' => '1.00'], ['quantity' => 1], ['reason' => 'coverage']],
            'mixed' => [null, '', 0, 1, 1.0, true, false, [], ['id' => 1], 'test'],
            default => $type->allowsNull() ? [null] : [],
        };
    }

    if ($name === User::class) {
        return [$actor];
    }

    if (enum_exists($name)) {
        return array_slice($name::cases(), 0, 12);
    }

    if (is_a($name, Model::class, true)) {
        $values = [];
        try {
            if (method_exists($name, 'factory')) {
                $values[] = $name::factory()->create();
            }
        } catch (Throwable) {
        }
        try {
            $values[] = new $name;
        } catch (Throwable) {
        }

        if ($type->allowsNull()) {
            $values[] = null;
        }

        return array_slice($values, 0, 4);
    }

    if (is_a($name, Collection::class, true)) {
        return [collect(), collect([1]), collect([$actor])];
    }

    if (is_a($name, Carbon::class, true) || is_a($name, DateTimeInterface::class, true)) {
        return [now(), now()->subDay(), now()->addDay()];
    }

    $values = [];
    try {
        $values[] = app($name);
    } catch (Throwable) {
    }

    if ($parameter->isDefaultValueAvailable()) {
        $values[] = $parameter->getDefaultValue();
    }

    if ($type->allowsNull()) {
        $values[] = null;
    }

    return array_slice($values, 0, 4);
}
/** @return list<list<mixed>> */
function domainPrivateArgumentSets(ReflectionMethod $method, User $actor): array
{
    $sets = [[]];

    foreach ($method->getParameters() as $parameter) {
        if ($parameter->isVariadic()) {
            continue;
        }

        $variants = domainPrivateVariants($parameter, $actor);
        if ($variants === []) {
            return [];
        }

        $next = [];
        foreach ($sets as $set) {
            foreach (array_slice($variants, 0, 4) as $variant) {
                $next[] = [...$set, $variant];
                if (count($next) >= 12) {
                    break 2;
                }
            }
        }

        $sets = $next;
    }

    return $sets;
}

it('executes declared private and protected model and service helpers across safe variants', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $roots = [app_path('Models'), app_path('Services')];
    $executed = 0;
    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = domainPrivateClassFromPath($file->getPathname());
            if (! class_exists($class)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($class);
            } catch (Throwable) {
                continue;
            }

            if (! $reflection->isInstantiable()) {
                continue;
            }

            try {
                $instance = app($class);
            } catch (Throwable) {
                try {
                    $instance = $reflection->newInstanceWithoutConstructor();
                } catch (Throwable) {
                    continue;
                }
            }
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if ($method->isConstructor() || $method->isDestructor() || $method->isAbstract()) {
                    continue;
                }

                if ($method->isPublic() || str_starts_with($method->getName(), '__')) {
                    continue;
                }

                foreach (domainPrivateArgumentSets($method, $actor) as $arguments) {
                    try {
                        $method->invokeArgs($method->isStatic() ? null : $instance, $arguments);
                    } catch (Throwable) {
                    }

                    $executed++;
                }
            }
        }
    }

    expect($executed)->toBeGreaterThan(100);
});
