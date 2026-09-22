<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Enums\SalesPermission;
use App\Enums\SupportPermission;
use App\Enums\UserType;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Support\TicketProviderSettlementService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Resolves the single trusted actor automated provider/settlement flows use
 * instead of `auth()` or a real admin (Customer App V1 plan §16).
 *
 * The {@see DashboardRole::SystemIntegration} role holds exactly three
 * abilities: `SalesPermission::PaymentRecord` (so it can create/post an ERP
 * {@see Payment} through {@see PaymentService}),
 * `AccountingPermission::JournalEntryPostFromSource` (so
 * {@see JournalPostingService} lets it post only
 * source-backed entries — it can never open the free-form "New Journal
 * Entry" screen), and `SupportPermission::TicketSettlePayment` (so it can
 * settle a chargeable ticket's payment link exactly the way a System Admin
 * dashboard action does, but only ever from a Stripe-verified transaction —
 * see {@see TicketProviderSettlementService}, never
 * from an unverified manual reference). Being one of
 * {@see DashboardRole::fixedRoleNames()} is what actually confines it to
 * those three abilities: without that, the `isAdmin() && no fixed role`
 * bypass every module's policy shares would grant this actor blanket access
 * instead. Both the user and the role are provisioned lazily and
 * idempotently, so no separate seeder run is required in any environment.
 */
final readonly class SystemActorResolver
{
    private const string Email = 'system-integration@ierp.internal';

    public function resolve(): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => self::Email],
            [
                'name' => 'System Integration',
                'username' => 'system-integration',
                'password' => Hash::make(Str::random(64)),
                'user_type' => UserType::Admin,
            ],
        );

        $role = Role::findOrCreate(DashboardRole::SystemIntegration->value, 'web');
        $role->givePermissionTo([
            Permission::findOrCreate(SalesPermission::PaymentRecord->value, 'web'),
            Permission::findOrCreate(AccountingPermission::JournalEntryPostFromSource->value, 'web'),
            Permission::findOrCreate(SupportPermission::TicketSettlePayment->value, 'web'),
        ]);

        if (! $user->hasRole(DashboardRole::SystemIntegration->value)) {
            $user->assignRole(DashboardRole::SystemIntegration->value);
        }

        return $user;
    }
}
