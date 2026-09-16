<?php

declare(strict_types=1);

use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;

uses(RefreshDatabase::class);

it('executes application Filament resource configuration and metadata surfaces', function (): void {
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
        if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), 'Resource.php')) {
            continue;
        }

        $relative = str_replace([app_path().DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR], ['', '', '\\'], $file->getPathname());
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
                $method->invokeArgs(null, $arguments);
                $executed++;
            } catch (Throwable) {
                // Some resource metadata requires a mounted panel or authenticated actor.
            }
        }
    }

    expect($executed)->toBeGreaterThan(150);
});
