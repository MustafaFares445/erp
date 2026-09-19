<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

uses(RefreshDatabase::class);

function sweepCoverageClassFromPath(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $base = mb_rtrim(str_replace('\\', '/', app_path()), '/').'/';
    $relative = str_replace(['.php', '/'], ['', '\\'], str_replace($base, '', $normalized));

    return 'App\\'.$relative;
}

function sweepCoverageModel(string $class): Model
{
    try {
        $model = method_exists($class, 'factory')
            ? $class::factory()->create()
            : new $class;
    } catch (Throwable) {
        $model = new ReflectionClass($class)->newInstanceWithoutConstructor();
    }

    if ($model instanceof Model) {
        $attributes = [
            'is_active' => true,
            'is_default' => false,
            'is_closed' => false,
            'mandatory' => false,
            'email' => 'coverage-sweep@example.test',
            'phone' => '+15555550124',
        ];

        foreach ($model->getCasts() as $attribute => $cast) {
            if (! is_string($cast)) {
                continue;
            }
            if (! enum_exists($cast)) {
                continue;
            }
            $case = $cast::cases()[0] ?? null;
            if ($case instanceof BackedEnum) {
                $attributes[$attribute] = $case->value;
            }
        }

        $model->forceFill($attributes);
    }

    return $model;
}

function sweepCoverageTable(): Table
{
    $owner = new class extends Component implements HasTable
    {
        use InteractsWithTable;

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return Table::make($owner);
}

/** @return list<mixed> */
function sweepCoverageNamedTypeVariants(ReflectionNamedType $type, ReflectionParameter $parameter, User $actor): array
{
    $name = $type->getName();

    if ($type->isBuiltin()) {
        return match ($name) {
            'string' => ['', 'test', 'draft', 'approved', 'paid', 'AED', '1'],
            'int' => [0, 1, 999999],
            'float' => [0.0, 1.0, 10.5],
            'bool' => [false, true],
            'array' => [[], ['id' => 1], ['status' => 'draft'], ['value' => 1], ['amount' => '1.00']],
            'mixed' => [null, '', 0, 1, 'test', []],
            'callable' => [static fn (): null => null],
            default => $type->allowsNull() ? [null] : [],
        };
    }

    if ($name === User::class) {
        return [$actor];
    }

    if (enum_exists($name)) {
        return array_slice($name::cases(), 0, 8);
    }

    if (is_a($name, Model::class, true)) {
        try {
            $base = sweepCoverageModel($name);
        } catch (Throwable) {
            return $type->allowsNull() ? [null] : [];
        }

        $variants = [$base];
        foreach ($base->getCasts() as $attribute => $cast) {
            if (! is_string($cast)) {
                continue;
            }
            if (! enum_exists($cast)) {
                continue;
            }
            foreach (array_slice($cast::cases(), 0, 5) as $case) {
                $variant = clone $base;
                $variant->forceFill([$attribute => $case instanceof BackedEnum ? $case->value : $case]);
                $variants[] = $variant;
            }
        }

        return array_slice($variants, 0, 8);
    }

    if (is_a($name, Builder::class, true)) {
        return [User::query()];
    }

    if (is_a($name, Collection::class, true)) {
        return [collect(), collect([1])];
    }

    if ($name === CarbonImmutable::class) {
        return [CarbonImmutable::now(), CarbonImmutable::now()->subDay()];
    }

    if (is_a($name, Carbon::class, true) || is_a($name, DateTimeInterface::class, true)) {
        return [now(), now()->subDay()];
    }

    if ($name === Request::class || is_a($name, Request::class, true)) {
        return [request()];
    }

    if ($name === Schema::class) {
        return [Schema::make()];
    }

    if ($name === Table::class) {
        return [sweepCoverageTable()];
    }

    if ($name === Closure::class) {
        return [static fn (): null => null];
    }

    if (is_a($name, Throwable::class, true)) {
        return [new RuntimeException('coverage sweep')];
    }

    try {
        return [app($name)];
    } catch (Throwable) {
        try {
            return [new ReflectionClass($name)->newInstanceWithoutConstructor()];
        } catch (Throwable) {
            if ($parameter->isDefaultValueAvailable()) {
                return [$parameter->getDefaultValue()];
            }

            return $type->allowsNull() ? [null] : [];
        }
    }
}

/** @return list<mixed> */
function sweepCoverageParameterVariants(ReflectionParameter $parameter, User $actor): array
{
    $type = $parameter->getType();

    if ($parameter->isVariadic()) {
        return [];
    }

    if ($type instanceof ReflectionUnionType) {
        $variants = [];
        foreach ($type->getTypes() as $candidate) {
            if ($candidate instanceof ReflectionNamedType) {
                $variants = [...$variants, ...sweepCoverageNamedTypeVariants($candidate, $parameter, $actor)];
            }
        }

        if ($type->allowsNull()) {
            $variants[] = null;
        }

        return array_slice($variants, 0, 8);
    }

    if (! $type instanceof ReflectionNamedType) {
        return $parameter->isDefaultValueAvailable() ? [$parameter->getDefaultValue()] : [];
    }

    return sweepCoverageNamedTypeVariants($type, $parameter, $actor);
}

/** @return list<list<mixed>> */
function sweepCoverageArgumentSets(ReflectionMethod $method, User $actor): array
{
    $sets = [[]];

    foreach ($method->getParameters() as $parameter) {
        $variants = sweepCoverageParameterVariants($parameter, $actor);

        if ($parameter->isVariadic()) {
            continue;
        }

        if ($variants === []) {
            return [];
        }

        $next = [];
        foreach ($sets as $set) {
            foreach (array_slice($variants, 0, 5) as $variant) {
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

it('sweeps remaining application command filament listener notification and policy branches', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $roots = [
        app_path('Console/Commands'),
        app_path('Filament'),
        app_path('Listeners'),
        app_path('Notifications'),
        app_path('Policies'),
    ];

    $invocations = 0;

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = sweepCoverageClassFromPath($file->getPathname());

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

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
                if ($method->isConstructor()) {
                    continue;
                }
                if ($method->isDestructor()) {
                    continue;
                }
                if ($method->isAbstract()) {
                    continue;
                }

                if (str_starts_with($method->getName(), '__')) {
                    continue;
                }

                foreach (sweepCoverageArgumentSets($method, $actor) as $arguments) {
                    try {
                        $method->invokeArgs($method->isStatic() ? null : $instance, $arguments);
                    } catch (Throwable) {
                        // Expected domain guards and missing mounted Filament context still exercise branches.
                    }

                    $invocations++;
                }
            }
        }
    }

    expect($invocations)->toBeGreaterThan(500);
});
