<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->string('template_key', 80);
            $table->string('channel', 20);
            $table->string('locale', 5);
            $table->string('route', 255)->nullable();
            $table->string('subject_document_type')->nullable();
            $table->unsignedBigInteger('subject_document_id')->nullable();
            $table->string('status', 20);
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->json('variables')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notification_deliveries_notifiable_index');
            $table->index(['status', 'created_at']);
            $table->index(['template_key', 'created_at']);
            $table->index(['subject_document_type', 'subject_document_id'], 'notification_deliveries_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
