<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('code', 50)->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('currency_code', 3)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('minimum_quantity', 20, 6)->nullable();
            $table->decimal('price', 15, 2);
            $table->date('valid_from')->nullable()->index();
            $table->date('valid_to')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(
                ['price_list_id', 'product_id', 'product_variant_id', 'is_active'],
                'price_list_items_resolution_index',
            );
        });

        Schema::create('price_list_customer', function (Blueprint $table): void {
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['price_list_id', 'customer_profile_id']);
        });

        Schema::create('price_list_customer_group', function (Blueprint $table): void {
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['price_list_id', 'customer_group_id']);
        });

        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->string('customer_type', 40)->default('other')->after('company_name')->index();
            $table->foreignId('customer_group_id')->nullable()->after('customer_type')->constrained('customer_groups')->nullOnDelete();
            $table->string('default_currency_code', 3)->nullable()->after('customer_group_id')->index();
            $table->foreignId('default_price_list_id')->nullable()->after('default_currency_code')->constrained('price_lists')->nullOnDelete();
            $table->foreignId('assigned_sales_employee_id')->nullable()->after('default_price_list_id')->constrained('users')->nullOnDelete();
            $table->text('billing_address')->nullable()->after('address');
            $table->string('tax_registration_number', 100)->nullable()->after('billing_address')->index();
        });

        foreach (['quotation_lines', 'order_lines', 'invoice_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('resolved_price_list_id')
                    ->nullable()
                    ->after('resolved_price_tier_id')
                    ->constrained('price_lists')
                    ->nullOnDelete();
                $table->foreignId('resolved_price_list_item_id')
                    ->nullable()
                    ->after('resolved_price_list_id')
                    ->constrained('price_list_items')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['invoice_lines', 'order_lines', 'quotation_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('resolved_price_list_item_id');
                $table->dropConstrainedForeignId('resolved_price_list_id');
            });
        }

        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->dropIndex(['customer_type']);
            $table->dropIndex(['default_currency_code']);
            $table->dropIndex(['tax_registration_number']);
            $table->dropConstrainedForeignId('assigned_sales_employee_id');
            $table->dropConstrainedForeignId('default_price_list_id');
            $table->dropConstrainedForeignId('customer_group_id');
            $table->dropColumn([
                'customer_type',
                'default_currency_code',
                'billing_address',
                'tax_registration_number',
            ]);
        });

        Schema::dropIfExists('price_list_customer_group');
        Schema::dropIfExists('price_list_customer');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('customer_groups');
    }
};
