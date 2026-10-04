<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_installations', function (Blueprint $table): void {
            $table->text('commissioning_failure_reason')->nullable()->after('commissioned_by_employee_id');
            $table->text('customer_rejection_reason')->nullable()->after('customer_accepted_at');
            $table->timestamp('customer_rejected_at')->nullable()->after('customer_rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_installations', function (Blueprint $table): void {
            $table->dropColumn(['commissioning_failure_reason', 'customer_rejection_reason', 'customer_rejected_at']);
        });
    }
};
