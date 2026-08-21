<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_type_id')->constrained('account_types')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_accounts');
    }
};
