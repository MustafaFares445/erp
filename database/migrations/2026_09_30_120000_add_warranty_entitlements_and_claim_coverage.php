<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable()->change();
        });

        Schema::create('warranty_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->unsignedInteger('duration_value');
            $table->string('duration_unit', 20);
            $table->string('start_trigger', 30)->default('confirmed_delivery');
            $table->boolean('covers_parts')->default(true);
            $table->boolean('covers_labour')->default(true);
            $table->boolean('covers_travel')->default(false);
            $table->boolean('covers_consumables')->default(false);
            $table->boolean('covers_third_party')->default(false);
            $table->boolean('transferable')->default(false);
            $table->string('replacement_rule', 40)->default('remaining_original_term');
            $table->text('exclusions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->foreignId('warranty_policy_id')
                ->nullable()
                ->after('warranty_duration_unit')
                ->constrained('warranty_policies')
                ->nullOnDelete();
        });

        Schema::create('warranty_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('serialized_inventory_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customer_profiles')->restrictOnDelete();
            $table->foreignId('warranty_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->foreignId('replacement_of_entitlement_id')->nullable()->constrained('warranty_entitlements')->nullOnDelete();
            $table->string('state', 30)->default('pending_activation')->index();
            $table->string('policy_name');
            $table->unsignedInteger('duration_value');
            $table->string('duration_unit', 20);
            $table->string('start_trigger', 30);
            $table->boolean('covers_parts')->default(true);
            $table->boolean('covers_labour')->default(true);
            $table->boolean('covers_travel')->default(false);
            $table->boolean('covers_consumables')->default(false);
            $table->boolean('covers_third_party')->default(false);
            $table->boolean('transferable')->default(false);
            $table->string('replacement_rule', 40)->default('remaining_original_term');
            $table->date('starts_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->timestamps();
            $table->index(['serialized_inventory_unit_id', 'customer_id', 'state'], 'warranty_entitlement_lookup');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->timestamp('response_sla_started_at')->nullable()->after('sla_resolution_target_minutes');
            $table->boolean('diagnostic_fee_required')->default(false)->after('charge_waived_reason');
            $table->decimal('diagnostic_fee_amount', 12, 2)->nullable()->after('diagnostic_fee_required');
            $table->string('diagnostic_fee_currency', 3)->nullable()->after('diagnostic_fee_amount');
        });

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->text('diagnosis_summary')->nullable()->after('description');
            $table->text('root_cause')->nullable()->after('diagnosis_summary');
            $table->string('failure_category', 40)->nullable()->after('root_cause')->index();
            $table->timestamp('diagnosed_at')->nullable()->after('failure_category');
            $table->foreignId('diagnosed_by')->nullable()->after('diagnosed_at')->constrained('users')->nullOnDelete();
            $table->string('coverage_decision', 40)->default('pending_diagnosis')->after('diagnosed_by')->index();
            $table->string('coverage_source', 40)->nullable()->after('coverage_decision')->index();
            $table->text('coverage_reason')->nullable()->after('coverage_source');
            $table->text('customer_coverage_explanation')->nullable()->after('coverage_reason');
            $table->timestamp('coverage_decided_at')->nullable()->after('customer_coverage_explanation');
            $table->foreignId('coverage_decided_by')->nullable()->after('coverage_decided_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('maintenance_coverage_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_record_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30)->index();
            $table->string('description');
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->decimal('coverage_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('covered_amount_minor')->default(0);
            $table->unsignedBigInteger('customer_amount_minor')->default(0);
            $table->string('coverage_source', 40)->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('warranty_recovery_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_record_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('coverage_source', 40)->index();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('counterparty_name')->nullable();
            $table->string('external_reference')->nullable()->index();
            $table->string('status', 30)->default('draft')->index();
            $table->string('currency', 3);
            $table->unsignedBigInteger('claimed_amount_minor');
            $table->unsignedBigInteger('approved_amount_minor')->nullable();
            $table->unsignedBigInteger('received_amount_minor')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_recovery_claims');
        Schema::dropIfExists('maintenance_coverage_lines');

        Schema::table('maintenance_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coverage_decided_by');
            $table->dropConstrainedForeignId('diagnosed_by');
            $table->dropColumn([
                'diagnosis_summary',
                'root_cause',
                'failure_category',
                'diagnosed_at',
                'coverage_decision',
                'coverage_source',
                'coverage_reason',
                'customer_coverage_explanation',
                'coverage_decided_at',
            ]);
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropColumn([
                'response_sla_started_at',
                'diagnostic_fee_required',
                'diagnostic_fee_amount',
                'diagnostic_fee_currency',
            ]);
        });

        Schema::dropIfExists('warranty_entitlements');

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warranty_policy_id');
        });

        Schema::dropIfExists('warranty_policies');

        Schema::table('quotation_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_variant_id')->nullable(false)->change();
        });
    }
};
