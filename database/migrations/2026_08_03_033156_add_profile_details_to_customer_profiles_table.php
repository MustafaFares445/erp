<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('company_name');
            $table->string('phone')->nullable()->after('email');
            $table->string('country')->nullable()->after('address');
            $table->string('city')->nullable()->after('country');
            $table->decimal('latitude', 10, 7)->nullable()->after('city');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('accountant_name')->nullable()->after('longitude');
            $table->string('accountant_phone')->nullable()->after('accountant_name');
            $table->string('accountant_email')->nullable()->after('accountant_phone');
            $table->boolean('contact_is_self')->default(true)->after('accountant_email');
            $table->string('contact_name')->nullable()->after('contact_is_self');
            $table->string('contact_phone')->nullable()->after('contact_name');
            $table->string('contact_email')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'email',
                'phone',
                'country',
                'city',
                'latitude',
                'longitude',
                'accountant_name',
                'accountant_phone',
                'accountant_email',
                'contact_is_self',
                'contact_name',
                'contact_phone',
                'contact_email',
            ]);
        });
    }
};
