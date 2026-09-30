<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->foreignId('campaign_id')
                ->nullable()
                ->after('lead_id')
                ->constrained('campaigns')
                ->nullOnDelete();
            $table->index('campaign_id');
        });

        Schema::table('campaign_responses', function (Blueprint $table): void {
            $table->foreignId('created_opportunity_id')
                ->nullable()
                ->after('created_lead_id')
                ->constrained('sales_opportunities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_responses', function (Blueprint $table): void {
            $table->dropForeign(['created_opportunity_id']);
            $table->dropColumn('created_opportunity_id');
        });

        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropForeign(['campaign_id']);
            $table->dropIndex(['campaign_id']);
            $table->dropColumn('campaign_id');
        });
    }
};
