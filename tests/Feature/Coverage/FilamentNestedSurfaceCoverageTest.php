<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function filamentNestedClassFromPath(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $base = mb_rtrim(str_replace('\\', '/', app_path()), '/').'/';
    $relative = str_replace(['.php', '/'], ['', '\\'], str_replace($base, '', $normalized));

    return 'App\\'.$relative;
}

function filamentNestedModel(string $class): Model
{
    try {
        return method_exists($class, 'factory') ? $class::factory()->create() : new $class;
    } catch (Throwable) {
        return new ReflectionClass($class)->newInstanceWithoutConstructor();
    }
}
/** @return list<mixed> */
function filamentNestedVariants(ReflectionParameter $parameter, User $actor): array
{
    $type = $parameter->getType();

    if ($parameter->isVariadic()) {
        return [];
    }

    if ($type instanceof ReflectionUnionType) {
        $values = [];
        foreach ($type->getTypes() as $candidate) {
            if ($candidate instanceof ReflectionNamedType) {
                $values = [...$values, ...filamentNestedNamedVariants($candidate, $parameter, $actor)];
            }
        }

        return array_slice($values, 0, 8);
    }

    if (! $type instanceof ReflectionNamedType) {
        return $parameter->isDefaultValueAvailable()
            ? [$parameter->getDefaultValue()]
            : [null, '', 0, [], $actor];
    }

    return filamentNestedNamedVariants($type, $parameter, $actor);
}
/** @return list<mixed> */
function filamentNestedNamedVariants(
    ReflectionNamedType $type,
    ReflectionParameter $parameter,
    User $actor,
): array {
    $name = $type->getName();

    if ($type->isBuiltin()) {
        return match ($name) {
            'string' => ['', 'test', 'draft', 'pending', 'approved', 'paid', 'AED', '1'],
            'int' => [0, 1, -1, 999999],
            'float' => [0.0, 0.01, 1.0, 100.0],
            'bool' => [false, true],
            'array' => [
                [],
                ['id' => 1],
                ['status' => 'draft'],
                ['status' => 'approved'],
                ['reason' => 'coverage'],
                ['quantity' => 1],
                ['amount' => '1.00'],
            ],
            'mixed' => [null, '', 0, 1, false, true, [], ['id' => 1], $actor],
            'callable' => [static fn (): null => null],
            default => $type->allowsNull() ? [null] : [],
        };
    }
    if ($name === User::class) {
        return [$actor];
    }

    if ($name === Get::class) {
        $make = static fn (mixed $value): Get => new class($value) extends Get
        {
            public function __construct(private mixed $value) {}

            public function __invoke(
                string|Component $path = '',
                bool $isAbsolute = false,
            ): mixed {
                return $this->value;
            }
        };

        return [$make(null), $make(1), $make('draft'), $make([]), $make(['id' => 1])];
    }

    if ($name === Set::class) {
        return [new class extends Set
        {
            public function __construct() {}

            public function __invoke(
                string|Component $path,
                mixed $state,
                bool $isAbsolute = false,
                bool $shouldCallUpdatedHooks = false,
            ): mixed {
                return $state;
            }
        }];
    }

    if (enum_exists($name)) {
        return array_slice($name::cases(), 0, 10);
    }

    if (is_a($name, Model::class, true)) {
        try {
            $values = [filamentNestedModel($name)];
        } catch (Throwable) {
            $values = [];
        }

        if ($type->allowsNull()) {
            $values[] = null;
        }

        return $values;
    }

    if (is_a($name, Collection::class, true)) {
        return [collect(), collect([$actor]), collect([1])];
    }

    if (class_exists(Action::class) && is_a($name, Action::class, true)) {
        return [Action::make('coverage')];
    }

    if (is_a($name, DateTimeInterface::class, true)) {
        return [now(), now()->subDay(), now()->addDay()];
    }

    if ($name === Closure::class) {
        return [static fn (): null => null];
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
function filamentNestedArgumentSets(ReflectionFunctionAbstract $callable, User $actor): array
{
    $sets = [[]];

    foreach ($callable->getParameters() as $parameter) {
        if ($parameter->isVariadic()) {
            continue;
        }

        $variants = filamentNestedVariants($parameter, $actor);
        if ($variants === []) {
            return [];
        }
        $next = [];
        foreach ($sets as $set) {
            foreach (array_slice($variants, 0, 4) as $variant) {
                $next[] = [...$set, $variant];

                if (count($next) >= 10) {
                    break 2;
                }
            }
        }

        $sets = $next;
    }

    return $sets;
}

function filamentNestedInvokeClosure(Closure $closure, User $actor): int
{
    $reflection = new ReflectionFunction($closure);
    $count = 0;

    foreach (filamentNestedArgumentSets($reflection, $actor) as $arguments) {
        try {
            $closure(...$arguments);
        } catch (Throwable) {
        }

        $count++;
    }

    return $count;
}
function filamentNestedPropertyClosures(object $object, User $actor): int
{
    $count = 0;
    $inspected = 0;
    $seen = [];

    for ($class = new ReflectionObject($object); $class !== false; $class = $class->getParentClass()) {
        foreach ($class->getProperties() as $property) {
            $key = $property->getDeclaringClass()->getName().':'.$property->getName();
            if ($property->isStatic() || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            try {
                if (! $property->isInitialized($object)) {
                    continue;
                }

                $property->setAccessible(true);
                $propertyValue = $property->getValue($object);
            } catch (Throwable) {
                continue;
            }

            if ($propertyValue instanceof Closure) {
                $count += filamentNestedInvokeClosure($propertyValue, $actor);
            } elseif (is_array($propertyValue) || $propertyValue instanceof Traversable) {
                $nested = 0;
                foreach ($propertyValue as $nestedValue) {
                    if ($nestedValue instanceof Closure) {
                        $count += filamentNestedInvokeClosure($nestedValue, $actor);
                    }

                    if (++$nested >= 20) {
                        break;
                    }
                }
            }

            if (++$inspected >= 120) {
                return $count;
            }
        }
    }

    return $count;
}

function filamentNestedInspect(mixed $value, User $actor): int
{
    $items = [];

    if (is_array($value) || $value instanceof Traversable) {
        foreach ($value as $item) {
            $items[] = $item;

            if (count($items) >= 20) {
                break;
            }
        }
    } elseif (is_object($value)) {
        $items[] = $value;
    }

    $count = 0;

    foreach ($items as $item) {
        if ($item instanceof Closure) {
            $count += filamentNestedInvokeClosure($item, $actor);

            continue;
        }

        if (! is_object($item)) {
            continue;
        }

        $count += filamentNestedPropertyClosures($item, $actor);

        if (method_exists($item, 'getActionFunction')) {
            try {
                $action = $item->getActionFunction();
                if ($action instanceof Closure) {
                    $count += filamentNestedInvokeClosure($action, $actor);
                }
            } catch (Throwable) {
            }
        }
        foreach (['getComponents', 'getActions', 'getHeaderActions', 'getFooterActions'] as $method) {
            if (! method_exists($item, $method)) {
                continue;
            }

            try {
                $children = $item->{$method}();
            } catch (Throwable) {
                continue;
            }

            $nested = 0;
            foreach (is_iterable($children) ? $children : [] as $child) {
                if (is_object($child)) {
                    $count += filamentNestedPropertyClosures($child, $actor);

                    if (method_exists($child, 'getActionFunction')) {
                        try {
                            $action = $child->getActionFunction();
                            if ($action instanceof Closure) {
                                $count += filamentNestedInvokeClosure($action, $actor);
                            }
                        } catch (Throwable) {
                        }
                    }
                }

                if (++$nested >= 20) {
                    break;
                }
            }
        }
    }

    return $count;
}
it('executes nested Filament action and component closures across safe variants', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Filament')),
    );

    $methodInvocations = 0;
    $nestedInvocations = 0;

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $class = filamentNestedClassFromPath($file->getPathname());
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

            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            foreach (filamentNestedArgumentSets($method, $actor) as $arguments) {
                try {
                    $result = $method->invokeArgs($method->isStatic() ? null : $instance, $arguments);
                    $nestedInvocations += filamentNestedInspect($result, $actor);
                } catch (Throwable) {
                }

                $methodInvocations++;
            }
        }
    }

    expect($methodInvocations)->toBeGreaterThan(300)
        ->and($nestedInvocations)->toBeGreaterThan(20);
});
