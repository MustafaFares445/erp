<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collaboration_entries', function (Blueprint $table): void {
            $table->id();
            $table->morphs('subject');
            $table->string('type', 32)->index();
            $table->foreignId('author_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('collaboration_entries')->cascadeOnUpdate()->nullOnDelete();
            $table->text('body');
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->string('visibility', 24)->default('internal')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'created_at'], 'collab_subject_created_idx');
        });
        Schema::create('collaboration_followers', function (Blueprint $table): void {
            $table->id();
            $table->morphs('subject');
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'user_id'], 'collab_followers_subject_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_followers');
        Schema::dropIfExists('collaboration_entries');
    }
};
