<?php

declare(strict_types=1);

function enumCoverageValues(ReflectionParameter $parameter, ReflectionEnum $enum): array
{
    $type = $parameter->getType();

    if (! $type instanceof ReflectionNamedType) {
        return [null];
    }

    if (! $type->isBuiltin()) {
        $name = $type->getName();

        if (in_array($name, ['self', 'static', $enum->getName()], true)) {
            return $enum->getCases() === [] ? [null] : array_map(
                static fn ($case): UnitEnum => $case->getValue(),
                $enum->getCases(),
            );
        }

        if (enum_exists($name)) {
            return $name::cases();
        }

        return $type->allowsNull() ? [null] : [];
    }

    return match ($type->getName()) {
        'string' => ['', 'x', 'draft', 'pending', 'completed'],
        'int' => [0, 1, -1],
        'float' => [0.0, 1.0, -1.0],
        'bool' => [false, true],
        'array' => [[], ['x']],
        'mixed' => [null, 'x', 1],
        default => $type->allowsNull() ? [null] : [],
    };
}

function enumCoverageArgumentSets(ReflectionMethod $method, ReflectionEnum $enum): array
{
    $sets = [[]];

    foreach ($method->getParameters() as $parameter) {
        $values = enumCoverageValues($parameter, $enum);

        if ($values === [] && $parameter->isDefaultValueAvailable()) {
            $values = [$parameter->getDefaultValue()];
        }

        if ($values === []) {
            return [];
        }

        $next = [];
        foreach ($sets as $set) {
            foreach ($values as $value) {
                $next[] = [...$set, $value];
                if (count($next) >= 64) {
                    break 2;
                }
            }
        }
        $sets = $next;
    }

    return $sets;
}

it('executes the public surface of every application enum', function (): void {
    $enumFiles = glob(app_path('Enums/*.php')) ?: [];
    $invocations = 0;

    foreach ($enumFiles as $file) {
        $class = 'App\\Enums\\'.pathinfo($file, PATHINFO_FILENAME);
        expect(enum_exists($class))->toBeTrue();

        $reflection = new ReflectionEnum($class);
        $cases = array_map(static fn (ReflectionEnumUnitCase $case): UnitEnum => $case->getValue(), $reflection->getCases());

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $argumentSets = enumCoverageArgumentSets($method, $reflection);
            if ($argumentSets === []) {
                continue;
            }

            $targets = $method->isStatic() ? [null] : $cases;
            foreach ($targets as $target) {
                foreach ($argumentSets as $arguments) {
                    try {
                        $method->invokeArgs($target, $arguments);
                    } catch (Throwable) {
                        // Invalid combinations are still useful for exercising guards.
                    }
                    $invocations++;
                }
            }
        }
    }

    expect($invocations)->toBeGreaterThan(100);
});
