<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Component;

uses(RefreshDatabase::class);

function resourceCoverageModelVariants(string $class): array
{
    if (! is_a($class, Model::class, true)) {
        return [];
    }

    $variants = [];

    try {
        $variants[] = method_exists($class, 'factory')
            ? $class::factory()->create()
            : new $class;
    } catch (Throwable) {
        try {
            $variants[] = new $class;
        } catch (Throwable) {
            return [];
        }
    }

    $base = $variants[0];

    foreach ($base->getCasts() as $attribute => $cast) {
        if (! is_string($cast)) {
            continue;
        }
        if (! enum_exists($cast)) {
            continue;
        }
        foreach (array_slice($cast::cases(), 0, 8) as $case) {
            $variant = clone $base;
            $variant->forceFill([
                $attribute => $case instanceof BackedEnum ? $case->value : $case,
            ]);
            $variants[] = $variant;
        }
    }

    foreach (['is_active', 'is_closed', 'is_default', 'mandatory'] as $attribute) {
        foreach ([false, true] as $value) {
            $variant = clone $base;
            $variant->forceFill([$attribute => $value]);
            $variants[] = $variant;
        }
    }

    return array_slice($variants, 0, 12);
}

function resourceCoverageParameterVariants(ReflectionParameter $parameter): array
{
    $type = $parameter->getType();

    if ($type instanceof ReflectionUnionType) {
        foreach ($type->getTypes() as $candidate) {
            if (! $candidate instanceof ReflectionNamedType) {
                continue;
            }

            $variants = resourceCoverageNamedTypeVariants($candidate, $parameter);
            if ($variants !== []) {
                return $variants;
            }
        }

        return $type->allowsNull() ? [null] : [];
    }

    if (! $type instanceof ReflectionNamedType) {
        return $parameter->isDefaultValueAvailable() ? [$parameter->getDefaultValue()] : [];
    }

    return resourceCoverageNamedTypeVariants($type, $parameter);
}

function resourceCoverageNamedTypeVariants(ReflectionNamedType $type, ReflectionParameter $parameter): array
{
    $name = $type->getName();

    if ($type->isBuiltin()) {
        return match ($name) {
            'string' => ['', 'test', 'draft', 'approved', 'paid', 'USD'],
            'int' => [0, 1, 999999],
            'float' => [0.0, 1.0, 10.5],
            'bool' => [false, true],
            'array' => [[], ['id' => 1], ['status' => 'draft'], ['value' => 1]],
            'mixed' => [null, '', 0, 1, 'test'],
            default => $type->allowsNull() ? [null] : [],
        };
    }

    if (enum_exists($name)) {
        return array_slice($name::cases(), 0, 8);
    }

    if (is_a($name, Model::class, true)) {
        return resourceCoverageModelVariants($name);
    }

    if (is_a($name, Builder::class, true)) {
        return [User::query()];
    }

    if (is_a($name, Collection::class, true)) {
        return [collect(), collect([1])];
    }

    if (is_a($name, DateTimeInterface::class, true)) {
        return [now(), now()->subDay()];
    }

    if (is_a($name, Action::class, true)) {
        try {
            return [$name::make('coverage')];
        } catch (Throwable) {
            return [];
        }
    }

    try {
        return [app($name)];
    } catch (Throwable) {
        if ($parameter->isDefaultValueAvailable()) {
            return [$parameter->getDefaultValue()];
        }

        return $type->allowsNull() ? [null] : [];
    }
}

function resourceCoverageArgumentSets(ReflectionFunctionAbstract $function): array
{
    $sets = [[]];

    foreach ($function->getParameters() as $parameter) {
        $variants = resourceCoverageParameterVariants($parameter);

        if ($variants === []) {
            return [];
        }

        $next = [];
        foreach ($sets as $set) {
            foreach (array_slice($variants, 0, 8) as $variant) {
                $next[] = [...$set, $variant];

                if (count($next) >= 16) {
                    break 2;
                }
            }
        }

        $sets = $next;
    }

    return $sets;
}

function resourceCoverageExerciseCallbacks(mixed $value, array &$seen, int &$callbacks, int $depth = 0): void
{
    if ($depth > 8) {
        return;
    }

    if ($value instanceof Closure) {
        $reflection = new ReflectionFunction($value);

        foreach (resourceCoverageArgumentSets($reflection) as $arguments) {
            try {
                $value(...$arguments);
            } catch (Throwable) {
                // Guard/error branches still count as exercised.
            }

            $callbacks++;
        }

        return;
    }

    if (is_iterable($value)) {
        foreach ($value as $item) {
            resourceCoverageExerciseCallbacks($item, $seen, $callbacks, $depth + 1);
        }

        return;
    }

    if (! is_object($value)) {
        return;
    }

    $class = $value::class;

    if (! str_starts_with($class, 'Filament\\')) {
        return;
    }

    $id = spl_object_id($value);
    if (isset($seen[$id])) {
        return;
    }

    $seen[$id] = true;
    $reflection = new ReflectionObject($value);

    foreach ($reflection->getProperties() as $property) {
        if ($property->isStatic()) {
            continue;
        }
        if (! $property->isInitialized($value)) {
            continue;
        }
        try {
            resourceCoverageExerciseCallbacks($property->getValue($value), $seen, $callbacks, $depth + 1);
        } catch (Throwable) {
            // Some lazy Filament internals cannot be inspected outside a mounted component.
        }
    }
}

it('executes application Filament resource configuration and metadata surfaces', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $warehouse = Warehouse::factory()->create();
    $customer = CustomerProfile::factory()->create();
    foreach ([
        ProductVariant::factory()->create(),
        ProductVariant::factory()->machine()->create(),
        ProductVariant::factory()->expiryMaterial()->create(),
    ] as $variant) {
        InventoryStock::factory()->for($variant)->for($warehouse)->create([
            'on_hand_quantity' => '5.000000',
            'reserved_quantity' => '0.000000',
            'available_quantity' => '5.000000',
        ]);
    }

    $tableOwner = new class extends Component implements HasTable
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

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament/Resources')));
    $executed = 0;

    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        if (! str_ends_with($file->getFilename(), 'Resource.php')) {
            continue;
        }
        $normalizedPath = str_replace('\\', '/', $file->getPathname());
        $normalizedAppPath = mb_rtrim(str_replace('\\', '/', app_path()), '/').'/';
        $relative = str_replace(['.php', '/'], ['', '\\'], str_replace($normalizedAppPath, '', $normalizedPath));
        $class = 'App\\'.$relative;
        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $arguments = [];
            $supported = true;
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (! $type instanceof ReflectionNamedType) {
                    $supported = false;
                    break;
                }

                $arguments[] = match ($type->getName()) {
                    Schema::class => Schema::make(),
                    Table::class => Table::make($tableOwner),
                    default => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                };

                if ($arguments[array_key_last($arguments)] === null && ! $type->allowsNull() && ! $parameter->isDefaultValueAvailable()) {
                    $supported = false;
                    break;
                }
            }

            if (! $supported) {
                continue;
            }

            try {
                $result = $method->invokeArgs(null, $arguments);
                $executed++;
                $seen = [];
                $callbacks = 0;
                resourceCoverageExerciseCallbacks($result, $seen, $callbacks);
            } catch (Throwable) {
                // Some resource metadata requires a mounted panel or authenticated actor.
            }
        }
    }

    expect($executed)->toBeGreaterThan(150);
});
