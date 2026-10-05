<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * WP-3.4 (GAP-UI-08) — every directory under `app/Filament/Resources/` must
 * contain exactly one `*Resource.php`. This is what prevents the next rename
 * from leaving the next orphan, which is the only durable value in this
 * package (it fixed two dormant orphans: `TaxRecognitionEntries/`, already
 * gone by the time this test was written, and `InventoryExports/Schemas/`,
 * whose schema was moved into `InventoryReports/Schemas/` since it is
 * genuinely shared, not report-specific — reachable from seven different
 * resource pages via `App\Filament\Concerns\RequestsInventoryExports`).
 */
it('keeps exactly one *Resource.php in every Filament resource directory', function (): void {
    $resourcesPath = app_path('Filament/Resources');
    $violations = [];

    foreach (File::directories($resourcesPath) as $directory) {
        $resourceFiles = File::glob($directory.'/*Resource.php');

        if (count($resourceFiles) !== 1) {
            $violations[] = basename($directory).' ('.count($resourceFiles).' resource files)';
        }
    }

    expect($violations)->toBe([]);
});
