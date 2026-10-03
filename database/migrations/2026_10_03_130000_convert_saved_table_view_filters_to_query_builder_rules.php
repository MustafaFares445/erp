<?php

declare(strict_types=1);

use App\Services\UiPreferences\SavedTableViewFilterMigrator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        resolve(SavedTableViewFilterMigrator::class)->migrate();
    }

    public function down(): void
    {
        // The removed filters no longer exist; the converted rules stay valid.
    }
};
