<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-set overrides of the constraint catalogue in
 * `App\Enums\BusinessConstraintKey`.
 *
 * Only overrides are stored. A key with no row here uses the default the enum
 * declares, so a fresh database already behaves correctly and no seeder has to
 * stay in step with the catalogue.
 *
 * `value` and `value_json` are a deliberate pair rather than one JSON column:
 * a scalar limit stays queryable and comparable in SQL, while a list policy
 * (an ageing ladder, a reminder schedule) needs ordered structure. The model
 * refuses any row that populates the wrong one for its key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_constraints', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->decimal('value', 15, 4)->nullable();
            $table->json('value_json')->nullable();
            // Null for a policy constraint, which has nothing to enforce.
            $table->string('enforcement', 20)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_constraints');
    }
};
