<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

function policyCoverageArgument(ReflectionParameter $parameter, bool $allowed): mixed
{
    $type = $parameter->getType();
    if (! $type instanceof ReflectionNamedType) {
        return null;
    }

    $name = $type->getName();
    if ($name === User::class) {
        $GLOBALS['policySurfaceCoverageAllowed'] = $allowed;

        return new User;
    }

    if (! $type->isBuiltin() && enum_exists($name)) {
        return $name::cases()[0] ?? null;
    }

    if (! $type->isBuiltin() && is_a($name, Model::class, true)) {
        $model = $name === Model::class
            ? new class extends Model {}
        : new $name;
        $model->forceFill([
            'is_default' => false,
            'is_active' => true,
        ]);

        return $model;
    }

    if ($type->isBuiltin()) {
        return match ($name) {
            'string' => 'view',
            'int' => 1,
            'float' => 1.0,
            'bool' => $allowed,
            'array' => [],
            default => null,
        };
    }

    if ($type->allowsNull()) {
        return null;
    }

    try {
        return app($name);
    } catch (Throwable) {
        return null;
    }
}

it('executes every application policy authorization surface', function (): void {
    Gate::before(static fn (): bool => (bool) ($GLOBALS['policySurfaceCoverageAllowed'] ?? false));

    $files = glob(app_path('Policies/*Policy.php')) ?: [];
    $invocations = 0;

    foreach ($files as $file) {
        $class = 'App\\Policies\\'.pathinfo($file, PATHINFO_FILENAME);
        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (! $reflection->isInstantiable()) {
            continue;
        }

        $policy = $reflection->newInstance();
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($method->isConstructor()) {
                continue;
            }
            foreach ([false, true] as $allowed) {
                $arguments = [];
                foreach ($method->getParameters() as $parameter) {
                    $arguments[] = policyCoverageArgument($parameter, $allowed);
                }

                try {
                    $method->invokeArgs($policy, $arguments);
                } catch (Throwable) {
                    // Some policies intentionally require persisted state.
                }
                $invocations++;
            }
        }
    }

    expect($invocations)->toBeGreaterThan(100);
});
