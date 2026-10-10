<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\InventoryPermission;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\SalesPermission;
use App\Filament\Resources\Adjustments\Pages\ListAdjustments;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\PaymentTransactions\Pages\ListPaymentTransactions;
use App\Filament\Resources\SalesOpportunities\Pages\ListSalesOpportunities;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\SupplierPayments\Pages\ManageSupplierPayments;
use App\Models\Campaign;
use App\Models\CustomerProfile;
use App\Models\InventoryAdjustment;
use App\Models\InventoryStock;
use App\Models\Lead;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\SalesOpportunity;
use App\Models\SupplierPayment;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * List tables keep one primary next-step action inline and move secondary
 * actions into a single overflow ActionGroup so the sticky trailing actions
 * cell stays narrow at laptop widths.
 *
 * @return array{top: list<string>, group: list<string>}
 */
function rowActionGroupingStructure(Testable $list): array
{
    $actions = array_values($list->instance()->getTable()->getRecordActions());

    return [
        'top' => array_map(
            static fn (object $action): string => $action instanceof ActionGroup ? 'group' : (string) $action->getName(),
            $actions,
        ),
        'group' => collect($actions)
            ->filter(static fn (object $action): bool => $action instanceof ActionGroup)
            ->flatMap(static fn (ActionGroup $group): array => array_keys($group->getFlatActions()))
            ->values()
            ->all(),
    ];
}

function rowActionGroupingUser(array $permissions, bool $admin = false): User
{
    $user = $admin ? User::factory()->admin()->create() : User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('keeps confirm, release and the next step inline and groups view and edit on the sales orders list', function (): void {
    (new SalesPermissionSeeder)->run();
    $user = rowActionGroupingUser([
        SalesPermission::OrderView->value,
        SalesPermission::OrderManage->value,
        SalesPermission::OrderConfirm->value,
        SalesPermission::OrderRelease->value,
    ]);
    $draft = Order::factory()->create(['status' => OrderStatus::Draft]);

    $list = Livewire::actingAs($user)
        ->test(ListOrders::class)
        ->assertTableActionVisible('confirm_order', $draft)
        ->assertActionVisible(TestAction::make('view')->table($draft))
        ->assertActionVisible(TestAction::make('edit')->table($draft));

    $structure = rowActionGroupingStructure($list);

    expect($structure['top'])->toBe(['confirm_order', 'release_order', 'next_step', 'group'])
        ->and($structure['group'])->toBe(['view', 'edit']);
});

it('hides confirm and edit on a closed order while view stays reachable from the group', function (): void {
    (new SalesPermissionSeeder)->run();
    $user = rowActionGroupingUser([
        SalesPermission::OrderView->value,
        SalesPermission::OrderManage->value,
        SalesPermission::OrderConfirm->value,
    ]);
    $closed = Order::factory()->create(['status' => OrderStatus::Closed]);

    Livewire::actingAs($user)
        ->test(ListOrders::class)
        ->assertTableActionHidden('confirm_order', $closed)
        ->assertActionHidden(TestAction::make('edit')->table($closed))
        ->assertActionVisible(TestAction::make('view')->table($closed));
});

it('keeps complete settlement inline and groups view and refresh on the payment transactions list', function (): void {
    $succeeded = PaymentTransaction::factory()->succeeded()->create();

    $list = Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListPaymentTransactions::class)
        ->assertActionVisible(TestAction::make('retry_settlement')->table($succeeded))
        ->assertActionVisible(TestAction::make('refresh_status')->table($succeeded))
        ->assertActionVisible(TestAction::make('view')->table($succeeded));

    $structure = rowActionGroupingStructure($list);

    expect($structure['top'])->toBe(['retry_settlement', 'group'])
        ->and($structure['group'])->toBe(['view', 'refresh_status']);
});

it('hides complete settlement for a pending transaction while refresh and view stay available', function (): void {
    $pending = PaymentTransaction::factory()->create(['status' => PaymentTransactionStatus::Pending]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListPaymentTransactions::class)
        ->assertActionHidden(TestAction::make('retry_settlement')->table($pending))
        ->assertActionVisible(TestAction::make('refresh_status')->table($pending))
        ->assertActionVisible(TestAction::make('view')->table($pending));
});

it('keeps pay inline and groups cancel, edit and delete on the supplier payments list', function (): void {
    (new AccountingPermissionSeeder)->run();
    $user = rowActionGroupingUser(AccountingPermission::values(), admin: true);
    $draft = SupplierPayment::factory()->create(['status' => 'draft']);

    $list = Livewire::actingAs($user)
        ->test(ManageSupplierPayments::class)
        ->assertActionVisible(TestAction::make('pay')->table($draft))
        ->assertActionVisible(TestAction::make('cancel')->table($draft))
        ->assertActionVisible(TestAction::make('edit')->table($draft))
        ->assertActionVisible(TestAction::make('delete')->table($draft));

    $structure = rowActionGroupingStructure($list);

    expect($structure['top'])->toBe(['pay', 'group'])
        ->and($structure['group'])->toBe(['cancel', 'edit', 'delete']);
});

it('hides every lifecycle and maintenance action on a paid supplier payment', function (): void {
    (new AccountingPermissionSeeder)->run();
    $user = rowActionGroupingUser(AccountingPermission::values(), admin: true);
    $paid = SupplierPayment::factory()->create(['status' => 'paid']);

    Livewire::actingAs($user)
        ->test(ManageSupplierPayments::class)
        ->assertActionHidden(TestAction::make('pay')->table($paid))
        ->assertActionHidden(TestAction::make('cancel')->table($paid))
        ->assertActionHidden(TestAction::make('edit')->table($paid))
        ->assertActionHidden(TestAction::make('delete')->table($paid));
});

it('keeps only view inline and groups the stock utilities on the stock levels list', function (): void {
    (new InventoryPermissionSeeder)->run();
    $user = rowActionGroupingUser([
        InventoryPermission::StockView->value,
        InventoryPermission::ConditionChangeCreate->value,
    ]);
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 5,
        'reserved_quantity' => 0,
        'damaged_quantity' => 2,
        'available_quantity' => 3,
    ]);

    $list = Livewire::actingAs($user)
        ->test(ListStockLevels::class)
        ->assertActionVisible(TestAction::make('view')->table($stock))
        ->assertActionVisible(TestAction::make('availability_breakdown')->table($stock))
        ->assertActionVisible(TestAction::make('damage')->table($stock));

    $structure = rowActionGroupingStructure($list);

    expect($structure['top'])->toBe(['view', 'group'])
        ->and($structure['group'])->toBe([
            'availability_breakdown',
            'package_movements',
            'disposition_quarantine',
            'damage',
            'recover_damage',
            'dispose_damage',
        ]);
});

it('hides the condition-change utilities for a viewer without that permission', function (): void {
    (new InventoryPermissionSeeder)->run();
    $viewer = rowActionGroupingUser([InventoryPermission::StockView->value]);
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 5,
        'damaged_quantity' => 2,
        'available_quantity' => 3,
    ]);

    Livewire::actingAs($viewer)
        ->test(ListStockLevels::class)
        ->assertActionHidden(TestAction::make('damage')->table($stock))
        ->assertActionHidden(TestAction::make('recover_damage')->table($stock))
        ->assertActionHidden(TestAction::make('dispose_damage')->table($stock))
        ->assertActionVisible(TestAction::make('view')->table($stock))
        ->assertActionVisible(TestAction::make('availability_breakdown')->table($stock));
});

it('keeps confirm inline and merges view, edit, delete and correction into one group on the adjustments list', function (): void {
    (new InventoryPermissionSeeder)->run();
    $approver = rowActionGroupingUser([
        InventoryPermission::AdjustmentView->value,
        InventoryPermission::AdjustmentCreate->value,
        InventoryPermission::AdjustmentConfirm->value,
    ]);
    $maker = rowActionGroupingUser([InventoryPermission::AdjustmentView->value, InventoryPermission::AdjustmentCreate->value]);
    $draft = InventoryAdjustment::factory()->create(['created_by' => $maker->getKey()]);

    $list = Livewire::actingAs($approver)
        ->test(ListAdjustments::class)
        ->assertTableActionVisible('confirm', $draft)
        ->assertActionVisible(TestAction::make('view')->table($draft));

    $structure = rowActionGroupingStructure($list);

    expect($structure['top'])->toBe(['confirm', 'group'])
        ->and($structure['group'])->toBe(['view', 'edit', 'delete', 'createCorrection']);
});

it('hides confirm, edit and delete on a confirmed adjustment while the correction action is offered from the group', function (): void {
    (new InventoryPermissionSeeder)->run();
    $approver = rowActionGroupingUser([
        InventoryPermission::AdjustmentView->value,
        InventoryPermission::AdjustmentCreate->value,
        InventoryPermission::AdjustmentConfirm->value,
    ]);
    $confirmed = InventoryAdjustment::factory()->confirmed()->create();

    Livewire::actingAs($approver)
        ->test(ListAdjustments::class)
        ->assertTableActionHidden('confirm', $confirmed)
        ->assertActionHidden(TestAction::make('edit')->table($confirmed))
        ->assertActionHidden(TestAction::make('delete')->table($confirmed))
        ->assertActionVisible(TestAction::make('view')->table($confirmed))
        ->assertActionVisible(TestAction::make('createCorrection')->table($confirmed));
});

it('keeps the approval step inline and groups the review utilities on the customer profiles list', function (): void {
    $pending = CustomerProfile::factory()->pending()->create();

    $list = Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListCustomers::class)
        ->assertActionVisible(TestAction::make('approve')->table($pending))
        ->assertActionVisible(TestAction::make('requestChanges')->table($pending))
        ->assertActionVisible(TestAction::make('rejectApproval')->table($pending))
        ->assertActionVisible(TestAction::make('edit')->table($pending))
        ->assertActionVisible(TestAction::make('delete')->table($pending));

    $structure = rowActionGroupingStructure($list);

    expect($structure['top'])->toBe(['approve', 'reactivate', 'restore', 'group'])
        ->and($structure['group'])->toBe(['view', 'edit', 'requestChanges', 'rejectApproval', 'delete']);
});

it('hides approve and reactivate on an approved customer while the review utilities stay in the group', function (): void {
    $approved = CustomerProfile::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListCustomers::class)
        ->assertActionHidden(TestAction::make('approve')->table($approved))
        ->assertActionHidden(TestAction::make('reactivate')->table($approved))
        ->assertActionVisible(TestAction::make('view')->table($approved))
        ->assertActionVisible(TestAction::make('requestChanges')->table($approved))
        ->assertActionVisible(TestAction::make('rejectApproval')->table($approved))
        ->assertActionVisible(TestAction::make('delete')->table($approved));
});

function rowActionGroupingLead(LeadStatus $status): Lead
{
    $lead = new Lead;
    $lead->forceFill([
        'lead_number' => 'LEAD-GROUP-'.$status->value,
        'status' => $status,
        'source' => LeadSource::Website,
        'first_name' => 'Grouped',
        'last_name' => 'Lead',
        'email' => 'grouped-lead-'.$status->value.'@example.test',
        'created_by' => User::factory()->create()->getKey(),
    ])->save();

    return $lead;
}

it('keeps convert and log interaction inline for a qualified lead and groups the other lead actions', function (): void {
    $qualified = rowActionGroupingLead(LeadStatus::Qualified);

    $list = Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListLeads::class)
        ->assertActionVisible(TestAction::make('convert')->table($qualified))
        ->assertActionVisible(TestAction::make('log_interaction')->table($qualified))
        ->assertActionVisible(TestAction::make('assign')->table($qualified))
        ->assertActionVisible(TestAction::make('disqualify')->table($qualified))
        ->assertActionVisible(TestAction::make('edit')->table($qualified));

    $structure = rowActionGroupingStructure($list);

    expect($structure['top'])->toBe(['convert', 'log_interaction', 'group'])
        ->and($structure['group'])->toBe(['view', 'assign', 'disqualify', 'edit']);
});

it('hides convert on a new lead while logging an interaction stays inline', function (): void {
    $new = rowActionGroupingLead(LeadStatus::New);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListLeads::class)
        ->assertActionHidden(TestAction::make('convert')->table($new))
        ->assertActionVisible(TestAction::make('log_interaction')->table($new))
        ->assertActionVisible(TestAction::make('assign')->table($new))
        ->assertActionVisible(TestAction::make('view')->table($new));
});

it('keeps approve review and create quotation inline and groups the other opportunity actions', function (): void {
    $approved = SalesOpportunity::factory()->manual()->create();

    $list = Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListSalesOpportunities::class)
        ->assertActionVisible(TestAction::make('create_quotation')->table($approved))
        ->assertActionVisible(TestAction::make('change_stage')->table($approved))
        ->assertActionVisible(TestAction::make('edit')->table($approved));

    $structure = rowActionGroupingStructure($list);

    expect($structure['top'])->toBe(['approve', 'create_quotation', 'group'])
        ->and($structure['group'])->toBe(['view', 'edit', 'reject', 'change_stage']);
});

it('hides create quotation on a draft opportunity while review rejection and view stay reachable from the group', function (): void {
    $draft = SalesOpportunity::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListSalesOpportunities::class)
        ->assertActionVisible(TestAction::make('approve')->table($draft))
        ->assertActionHidden(TestAction::make('create_quotation')->table($draft))
        ->assertActionHidden(TestAction::make('change_stage')->table($draft))
        ->assertActionVisible(TestAction::make('reject')->table($draft))
        ->assertActionVisible(TestAction::make('view')->table($draft));
});

function rowActionGroupingCampaign(string $status, ?string $scheduledAt = null): Campaign
{
    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-GROUP-'.$status.'-'.($scheduledAt === null ? 'none' : 'later'),
        'name' => 'Row grouping campaign',
        'channel' => 'email',
        'status' => $status,
        'scheduled_at' => $scheduledAt,
        'segment_criteria' => [],
        'created_by' => User::factory()->create()->getKey(),
    ])->save();

    return $campaign;
}

it('keeps send inline and groups the campaign preparation and utility actions', function (): void {
    $draft = rowActionGroupingCampaign('draft');

    $list = Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListCampaigns::class)
        ->assertActionVisible(TestAction::make('send_campaign')->table($draft))
        ->assertActionVisible(TestAction::make('build_recipients')->table($draft))
        ->assertActionVisible(TestAction::make('schedule')->table($draft))
        ->assertActionVisible(TestAction::make('cancel')->table($draft))
        ->assertActionVisible(TestAction::make('download_send_log')->table($draft));

    $structure = rowActionGroupingStructure($list);

    expect($structure['top'])->toBe(['send_campaign', 'group'])
        ->and($structure['group'])->toBe(['view', 'build_recipients', 'schedule', 'cancel', 'download_send_log']);
});

it('hides send and schedule on a future-scheduled campaign while build recipients and cancel stay in the group', function (): void {
    $scheduled = rowActionGroupingCampaign('scheduled', now()->addDay()->toDateTimeString());

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListCampaigns::class)
        ->assertActionHidden(TestAction::make('send_campaign')->table($scheduled))
        ->assertActionHidden(TestAction::make('schedule')->table($scheduled))
        ->assertActionVisible(TestAction::make('build_recipients')->table($scheduled))
        ->assertActionVisible(TestAction::make('cancel')->table($scheduled))
        ->assertActionVisible(TestAction::make('download_send_log')->table($scheduled));
});

it('labels the build recipients campaign action in Arabic', function (): void {
    app()->setLocale('ar');
    $draft = rowActionGroupingCampaign('draft');

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListCampaigns::class)
        ->assertActionHasLabel(TestAction::make('build_recipients')->table($draft), 'تجهيز المستلمين');
});
