<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('keeps API controllers free of direct model dependencies', function (): void {
    $files = File::allFiles(app_path('Http/Controllers/Api/V1'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = File::get($file->getPathname());

        expect($contents)
            ->not->toContain('App\\Models\\')
            ->not->toContain('::query()')
            ->not->toContain('::create(')
            ->not->toContain('::update(');
    }
});
