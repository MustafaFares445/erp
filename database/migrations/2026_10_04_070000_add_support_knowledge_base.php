<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_article_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()
                ->constrained('knowledge_article_categories')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('body');
            $table->string('visibility', 20)->default('internal');
            $table->string('status', 20)->default('draft');
            $table->string('locale', 10)->default('en');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'visibility', 'locale']);
            $table->index(['category_id', 'status']);
        });

        Schema::create('knowledge_article_product_variants', function (Blueprint $table): void {
            $table->foreignId('knowledge_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            $table->unique(['knowledge_article_id', 'product_variant_id'], 'knowledge_article_variant_unique');
        });

        Schema::create('knowledge_article_ticket_types', function (Blueprint $table): void {
            $table->foreignId('knowledge_article_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_type', 50);

            $table->unique(['knowledge_article_id', 'ticket_type'], 'knowledge_article_ticket_type_unique');
        });

        Schema::create('ticket_knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('link_type', 30);
            $table->timestamps();

            $table->unique(
                ['ticket_id', 'knowledge_article_id', 'link_type'],
                'ticket_knowledge_link_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_knowledge_articles');
        Schema::dropIfExists('knowledge_article_ticket_types');
        Schema::dropIfExists('knowledge_article_product_variants');
        Schema::dropIfExists('knowledge_articles');
        Schema::dropIfExists('knowledge_article_categories');
    }
};
