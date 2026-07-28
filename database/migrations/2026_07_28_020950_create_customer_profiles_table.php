<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('customer_code', 50);
            $table->string('company_name')->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('default_payment_term_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();

            $table->unique('user_id');
            $table->unique('customer_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
