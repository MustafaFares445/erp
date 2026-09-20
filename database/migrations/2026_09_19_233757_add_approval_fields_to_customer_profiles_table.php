<?php

declare(strict_types=1);

use App\Enums\CustomerApprovalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->string('approval_status', 30)->default(CustomerApprovalStatus::Pending->value)->after('is_active');
            $table->foreignId('reviewed_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_note')->nullable()->after('reviewed_at');
            $table->boolean('allow_direct_orders')->default(false)->after('review_note');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['approval_status', 'reviewed_at', 'review_note', 'allow_direct_orders']);
        });
    }
};
