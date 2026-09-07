<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);
            $table->string('locale', 5);
            $table->string('channel', 20);
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->json('variables');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['key', 'locale', 'channel'], 'notification_templates_key_locale_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
