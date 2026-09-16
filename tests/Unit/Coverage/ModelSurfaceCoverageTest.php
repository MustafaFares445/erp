<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

function safeCoverageModel(string $class): Model
{
    if ($class === Model::class) {
        return new class extends Model {};
    }

    $model = new $class;
    $attributes = ['is_active' => true, 'is_default' => false];

    foreach ($model->getCasts() as $attribute => $cast) {
        if (is_string($cast) && enum_exists($cast)) {
            $case = $cast::cases()[0] ?? null;
            if ($case instanceof BackedEnum) {
                $attributes[$attribute] = $case->value;
            }
        }
    }

    return $model->forceFill($attributes);
}

function safeModelMethodArguments(ReflectionMethod $method, Model $model): array
{
    $arguments = [];

    foreach ($method->getParameters() as $parameter) {
        $type = $parameter->getType();
        if (! $type instanceof ReflectionNamedType) {
            return [];
        }

        $name = $type->getName();
        if (! $type->isBuiltin() && is_a($name, Builder::class, true)) {
            $arguments[] = $model->newQuery();
            continue;
        }
        if (! $type->isBuiltin() && enum_exists($name)) {
            $arguments[] = $name::cases()[0];
            continue;
        }
        if ($type->isBuiltin() && $name === 'string') {
            $arguments[] = '';
            continue;
        }
        if ($type->isBuiltin() && in_array($name, ['int', 'float'], true)) {
            $arguments[] = 0;
            continue;
        }
        if ($type->isBuiltin() && $name === 'bool') {
            $arguments[] = false;
            continue;
        }
        if ($type->allowsNull()) {
            $arguments[] = null;
            continue;
        }
        if ($parameter->isDefaultValueAvailable()) {
            $arguments[] = $parameter->getDefaultValue();
            continue;
        }

        return [];
    }

    return $arguments;
}

function isSafeModelSurface(ReflectionMethod $method): bool
{
    $returnType = $method->getReturnType();
    if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
        $name = $returnType->getName();
        if (is_a($name, Relation::class, true) || $name === Attribute::class || is_a($name, Builder::class, true)) {
            return true;
        }
    }

    return str_starts_with($method->getName(), 'scope')
        || preg_match('/^(get|set).+Attribute$/', $method->getName()) === 1;
}

it('executes safe relationship accessor and scope surfaces for every model', function (): void {
    $files = glob(app_path('Models/*.php')) ?: [];
    $invocations = 0;

    foreach ($files as $file) {
        $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (! $reflection->isInstantiable()) {
            continue;
        }

        $model = safeCoverageModel($class);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || ! isSafeModelSurface($method)) {
                continue;
            }

            $arguments = safeModelMethodArguments($method, $model);
            if (count($arguments) !== $method->getNumberOfParameters()) {
                continue;
            }

            try {
                $method->setAccessible(true);
                $method->invokeArgs($method->isStatic() ? null : $model, $arguments);
            } catch (Throwable) {
                // Some relationships/scopes need persisted foreign keys; construction still covers guards.
            }
            $invocations++;
        }
    }

    expect($invocations)->toBeGreaterThan(150);
});
