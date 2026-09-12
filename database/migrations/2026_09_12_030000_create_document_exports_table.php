<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_exports', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 30)->index();
            $table->string('type', 80)->index();
            $table->string('format', 10);
            $table->json('parameters')->nullable();
            $table->unsignedBigInteger('row_count')->default(0);
            $table->string('file_path')->nullable();
            $table->string('status', 30)->default('queued')->index();
            $table->text('failure_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_exports');
    }
};
