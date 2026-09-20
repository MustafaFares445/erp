<?php

declare(strict_types=1);

use App\Enums\CustomerApprovalStatus;
use App\Models\CustomerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds the approval columns without dropping anything', function (): void {
    expect(Schema::hasColumns('customer_profiles', [
        'approval_status', 'reviewed_by', 'reviewed_at', 'review_note', 'allow_direct_orders',
    ]))->toBeTrue();
});

it('backfills active legacy customers as Approved and inactive ones as Pending', function (): void {
    $active = CustomerProfile::factory()->create(['is_active' => true]);
    $inactive = CustomerProfile::factory()->create(['is_active' => false]);

    // Reproduce pre-backfill rows by writing straight to the database, bypassing
    // the model default the factory otherwise applies.
    DB::table('customer_profiles')->whereIn('id', [$active->id, $inactive->id])->update([
        'approval_status' => CustomerApprovalStatus::Pending->value,
    ]);

    runCustomerApprovalBackfill();

    expect($active->refresh()->approval_status)->toBe(CustomerApprovalStatus::Approved)
        ->and($inactive->refresh()->approval_status)->toBe(CustomerApprovalStatus::Pending);
});

it('is idempotent, so a re-run cannot drift a classification', function (): void {
    $active = CustomerProfile::factory()->create(['is_active' => true]);

    runCustomerApprovalBackfill();
    $first = $active->refresh()->approval_status;

    runCustomerApprovalBackfill();
    $second = $active->refresh()->approval_status;

    expect($first)->toBe(CustomerApprovalStatus::Approved)->and($second)->toBe($first);
});

function runCustomerApprovalBackfill(): void
{
    $migration = require database_path('migrations/2026_09_19_234743_backfill_customer_approval_status_from_is_active.php');
    $migration->up();
}
