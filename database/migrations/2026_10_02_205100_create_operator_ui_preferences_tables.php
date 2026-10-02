<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_table_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page_key', 120);
            $table->string('name', 120);
            $table->string('icon')->nullable();
            $table->string('color', 40)->nullable();
            $table->boolean('is_public')->default(false);
            $table->json('state');
            $table->unsignedSmallInteger('state_version')->default(1);
            $table->timestamps();

            $table->index(['page_key', 'is_public']);
            $table->index(['user_id', 'page_key']);
        });

        Schema::create('table_view_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page_key', 120);
            $table->string('view_type', 20);
            $table->string('view_key', 120);
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'page_key', 'view_type', 'view_key'], 'table_view_preference_unique');
        });

        Schema::create('user_ui_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 60);
            $table->string('key', 160);
            $table->json('value');
            $table->timestamps();

            $table->unique(['user_id', 'scope', 'key'], 'user_ui_preference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ui_preferences');
        Schema::dropIfExists('table_view_preferences');
        Schema::dropIfExists('saved_table_views');
    }
};
