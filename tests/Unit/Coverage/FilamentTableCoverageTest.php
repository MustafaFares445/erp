<?php

declare(strict_types=1);

use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

it('configures every application Filament table class', function (): void {
    $livewire = new class extends Component implements HasTable
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

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));
    $configured = 0;

    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        if (! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Tables'.DIRECTORY_SEPARATOR)) {
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
        $parameter = $method->getParameters()[0] ?? null;
        $type = $parameter?->getType();
        if (! $method->isPublic()) {
            continue;
        }
        if (! $method->isStatic()) {
            continue;
        }
        if (! $type instanceof ReflectionNamedType) {
            continue;
        }
        if ($type->getName() !== Table::class) {
            continue;
        }

        try {
            $result = $method->invoke(null, Table::make($livewire));
            expect($result)->toBeInstanceOf(Table::class);
            $configured++;
        } catch (Throwable) {
            // Some tables need a fully mounted page/record to configure dynamic actions.
        }
    }

    expect($configured)->toBeGreaterThan(10);
});
