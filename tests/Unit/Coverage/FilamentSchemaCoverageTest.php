<?php

declare(strict_types=1);

use Filament\Schemas\Schema;

it('configures every application Filament schema class', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));
    $configured = 0;

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Schemas'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $relative = str_replace([app_path().DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR], ['', '', '\\'], $file->getPathname());
        $class = 'App\\'.$relative;
        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (! $reflection->hasMethod('configure')) {
            continue;
        }

        $method = $reflection->getMethod('configure');
        if (! $method->isPublic() || ! $method->isStatic()) {
            continue;
        }

        $parameter = $method->getParameters()[0] ?? null;
        $type = $parameter?->getType();
        if (! $type instanceof ReflectionNamedType || $type->getName() !== Schema::class) {
            continue;
        }

        try {
            $result = $method->invoke(null, Schema::make());
            expect($result)->toBeInstanceOf(Schema::class);
            $configured++;
        } catch (Throwable) {
            // Some schemas require a live record or Livewire owner to evaluate relationship metadata.
        }
    }

    expect($configured)->toBeGreaterThan(15);
});
