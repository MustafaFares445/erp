<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\MaintenanceStatus;
use App\Enums\MovementType;
use App\Enums\NotificationChannel;
use App\Enums\QuotationStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyRecoveryStatus;
use App\Enums\WarrantyStatus;
use App\Events\PurchaseOrderReceived;
use App\Events\SupplierCommitmentRecorded;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\MaintenanceRequests\Actions\WarrantyRecoveryActions;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants;
use App\Filament\Resources\SerializedInventoryUnits\Pages\ViewSerializedInventoryUnit;
use App\Listeners\SendBusinessNotification;
use App\Models\InventoryMovement;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceRecord;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SerializedInventoryUnit;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Models\WarrantyPolicy;
use App\Models\WarrantyRecoveryClaim;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function batch62Action(array $actions, string $name): Action
{
    foreach ($actions as $candidate) {
        if ($candidate instanceof Action && $candidate->getName() === $name) {
            return $candidate;
        }

        if ($candidate instanceof ActionGroup) {
            foreach ($candidate->getFlatActions() as $action) {
                if ($action->getName() === $name) {
                    return $action;
                }
            }
        }
    }

    throw new LogicException("Action {$name} was not found.");
}

it('covers campaign content-template options for invalid email sms and whatsapp channels', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $mail = NotificationTemplate::query()->create([
        'key' => 'campaign.mail.coverage',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Mail',
        'body' => 'Mail body',
        'variables' => [],
        'is_active' => true,
    ]);
    $sms = NotificationTemplate::query()->create([
        'key' => 'campaign.sms.coverage',
        'locale' => 'en',
        'channel' => NotificationChannel::Sms,
        'subject' => null,
        'body' => 'SMS body',
        'variables' => [],
        'is_active' => true,
    ]);
    $whatsapp = NotificationTemplate::query()->create([
        'key' => 'campaign.whatsapp.coverage',
        'locale' => 'en',
        'channel' => NotificationChannel::Whatsapp,
        'subject' => null,
        'body' => 'WhatsApp body',
        'variables' => [],
        'is_active' => true,
    ]);
    NotificationTemplate::query()->create([
        'key' => 'campaign.mail.inactive',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Inactive',
        'body' => 'Inactive',
        'variables' => [],
        'is_active' => false,
    ]);

    $test = Livewire::actingAs($actor)->test(CreateCampaign::class);
    $templateSelect = static function (Testable $test): Select {
        $schema = $test->instance()->getSchema('form');
        $component = collect($schema?->getFlatComponents(withHidden: true) ?? [])
            ->first(static fn (mixed $candidate): bool => $candidate instanceof Select && $candidate->getName() === 'content_template_id');

        if (! $component instanceof Select) {
            throw new LogicException('Campaign content template field was not found.');
        }

        return $component;
    };

    expect($templateSelect($test)->getOptions())->toBe([]);

    $test->set('data.channel', 'invalid-channel');
    expect($templateSelect($test)->getOptions())->toBe([]);

    $test->set('data.channel', CampaignChannel::Email->value);
    expect($templateSelect($test)->getOptions())->toHaveKey($mail->getKey())
        ->not->toHaveKey(NotificationTemplate::query()->where('key', 'campaign.mail.inactive')->value('id'));

    $test->set('data.content_template_id', $mail->getKey())
        ->set('data.channel', CampaignChannel::Sms->value)
        ->assertSet('data.content_template_id', null);
    expect($templateSelect($test)->getOptions())->toHaveKey($sms->getKey());

    $test->set('data.channel', CampaignChannel::Whatsapp->value);
    expect($templateSelect($test)->getOptions())->toHaveKey($whatsapp->getKey());
});

it('dispatches supplier commitment notifications for allocation and rejected or backordered exceptions', function (): void {
    (new NotificationTemplateSeeder)->run();
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $warehouse = User::factory()->admin()->create();
    $warehouse->assignRole('Warehouse Manager');

    $buyer = User::factory()->admin()->create();
    $buyer->assignRole('Purchasing Manager');

    $order = PurchaseOrder::factory()->accepted()->create();
    $confirmation = SupplierConfirmation::factory()->confirmed()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
    ]);
    SupplierConfirmationItem::factory()->for($confirmation, 'confirmation')->create([
        'confirmed_base_quantity' => 2,
        'backordered_base_quantity' => 1,
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
    ]);

    app(SendBusinessNotification::class)->handle(new SupplierCommitmentRecorded($order, $confirmation));

    expect(NotificationDelivery::query()->where('notifiable_id', $warehouse->getKey())->count())->toBeGreaterThanOrEqual(1)
        ->and(NotificationDelivery::query()->where('notifiable_id', $buyer->getKey())->count())->toBeGreaterThanOrEqual(1);

    $rejected = SupplierConfirmation::factory()->rejected()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
        'confirmation_status' => SupplierConfirmationStatus::Rejected,
    ]);
    SupplierConfirmationItem::factory()->for($rejected, 'confirmation')->create([
        'confirmed_base_quantity' => 0,
        'backordered_base_quantity' => 0,
        'confirmation_status' => SupplierConfirmationStatus::Rejected,
    ]);

    $before = NotificationDelivery::query()->where('notifiable_id', $buyer->getKey())->count();
    app(SendBusinessNotification::class)->handle(new SupplierCommitmentRecorded($order, $rejected));

    expect(NotificationDelivery::query()->where('notifiable_id', $buyer->getKey())->count())->toBeGreaterThan($before);
});

it('covers maintenance approval action guard success and invalid warranty override data', function (): void {
    (new SupportPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::AwaitingApproval,
    ]);
    MaintenanceCoverageLine::factory()->for($record)->create([
        'customer_amount_minor' => 10000,
        'amount_minor' => 10000,
        'covered_amount_minor' => 0,
        'coverage_percent' => 0,
        'coverage_source' => WarrantyCoverageSource::CustomerPaid,
    ]);

    $test = Livewire::actingAs($actor)->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()]);
    $page = $test->instance();

    $approvalMethod = new ReflectionMethod(ViewMaintenanceRequest::class, 'customerApprovalAction');
    /** @var Action $approval */
    $approval = $approvalMethod->invoke($page);
    ($approval->getActionFunction())();

    expect($record->refresh()->status)->toBe(MaintenanceStatus::AwaitingApproval);

    $quote = Quotation::factory()->accepted()->create([
        'customer_id' => $record->customer_id,
        'status' => QuotationStatus::Accepted,
    ]);
    $record->forceFill(['quotation_id' => $quote->getKey()])->save();

    $freshTest = Livewire::actingAs($actor)->test(ViewMaintenanceRequest::class, ['record' => $record->fresh()->getRouteKey()]);
    $freshPage = $freshTest->instance();
    /** @var Action $freshApproval */
    $freshApproval = $approvalMethod->invoke($freshPage);
    ($freshApproval->getActionFunction())();
    expect($record->refresh()->status)->toBe(MaintenanceStatus::ReadyForRepair);

    $headerMethod = new ReflectionMethod(ViewMaintenanceRequest::class, 'getHeaderActions');
    $override = batch62Action($headerMethod->invoke($page), 'overrideWarranty');

    expect(fn () => ($override->getActionFunction())([
        'warranty_status' => null,
        'reason' => [],
    ]))->toThrow(LogicException::class, 'Warranty override data is invalid');

    auth()->logout();
    $actorMethod = new ReflectionMethod(ViewMaintenanceRequest::class, 'currentActor');
    expect(fn (): mixed => $actorMethod->invoke(null))->toThrow(LogicException::class, 'authenticated User');
});

it('covers serialized-unit warranty action guards cancellation and receipt-source helpers', function (): void {
    (new SupportPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $unit = SerializedInventoryUnit::factory()->create();
    $test = Livewire::actingAs($actor)->test(ViewSerializedInventoryUnit::class, ['record' => $unit->getRouteKey()]);
    $page = $test->instance();
    $header = new ReflectionMethod(ViewSerializedInventoryUnit::class, 'getHeaderActions');
    $actions = $header->invoke($page);

    $activate = batch62Action($actions, 'activateWarranty');
    expect(fn () => ($activate->getActionFunction())($unit, []))
        ->toThrow(LogicException::class, 'Warranty activation data is invalid');

    $cancel = batch62Action($actions, 'cancelWarrantyEntitlement');
    expect(fn () => ($cancel->getActionFunction())($unit, []))
        ->toThrow(LogicException::class, 'Warranty cancellation data is invalid');

    $entitlement = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'state' => WarrantyEntitlementState::Active,
    ]);

    ($cancel->getActionFunction())($unit->refresh(), ['reason' => 'Coverage cancellation test.']);
    expect($entitlement->refresh()->state)->not->toBe(WarrantyEntitlementState::Active);

    $receipt = new ReflectionMethod(ViewSerializedInventoryUnit::class, 'receiptOperationId');
    expect($receipt->invoke(null, $unit->refresh()))->toBeNull();

    $movement = InventoryMovement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'product_variant_id' => $unit->product_variant_id,
        'movement_type' => MovementType::Receipt,
        'source_type' => 'not_inventory_operation',
        'source_id' => 91,
    ]);
    expect($receipt->invoke(null, $unit->refresh()))->toBeNull();

    $validUnit = SerializedInventoryUnit::factory()->create();
    InventoryMovement::factory()->create([
        'serialized_inventory_unit_id' => $validUnit->getKey(),
        'product_variant_id' => $validUnit->product_variant_id,
        'movement_type' => MovementType::Receipt,
        'source_type' => 'inventory_operation',
        'source_id' => 92,
    ]);
    expect($receipt->invoke(null, $validUnit->refresh()))->toBe(92);

    auth()->logout();
    $actorMethod = new ReflectionMethod(ViewSerializedInventoryUnit::class, 'currentActor');
    expect(fn (): mixed => $actorMethod->invoke(null))->toThrow(LogicException::class, 'authenticated User');
});

it('covers warranty recovery no-claim early returns rejected decision and actor guard', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $plain = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty,
        'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty,
    ]);

    $actions = WarrantyRecoveryActions::make();
    foreach (['submitWarrantyRecovery', 'decideWarrantyRecovery', 'recordWarrantyRecoveryReceipt'] as $name) {
        $action = batch62Action($actions, $name);
        ($action->getActionFunction())($plain, []);
    }

    $claim = WarrantyRecoveryClaim::factory()->create([
        'maintenance_record_id' => $plain->getKey(),
        'status' => WarrantyRecoveryStatus::Submitted,
    ]);

    $decision = batch62Action(WarrantyRecoveryActions::make(), 'decideWarrantyRecovery');
    ($decision->getActionFunction())($plain->refresh(), [
        'decision' => 'rejected',
        'rejection_reason' => 'Provider rejected the external warranty.',
    ]);

    expect($claim->refresh()->status)->toBe(WarrantyRecoveryStatus::Rejected);

    $normalized = new ReflectionMethod(WarrantyRecoveryActions::class, 'stringKeyedData');
    expect($normalized->invoke(null, [0 => 'skip', 'keep' => 1]))->toBe(['keep' => 1]);

    auth()->logout();
    $actorMethod = new ReflectionMethod(WarrantyRecoveryActions::class, 'actor');
    expect(fn (): mixed => $actorMethod->invoke(null))->toThrow(LogicException::class, 'authenticated User');
});

it('covers product-variant warranty policy summary for missing unavailable and configured policies', function (): void {
    (new InventoryPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo([
        InventoryPermission::CatalogView->value,
        InventoryPermission::CatalogManage->value,
    ]);

    $test = Livewire::actingAs($manager)
        ->test(ManageProductVariants::class)
        ->mountAction(TestAction::make('create'));

    $schemaMethod = new ReflectionMethod($test->instance(), 'getMountedActionSchema');
    $schema = $schemaMethod->invoke($test->instance());

    $placeholder = static function ($schema): Placeholder {
        $component = collect($schema?->getFlatComponents(withHidden: true) ?? [])
            ->first(static fn (mixed $candidate): bool => $candidate instanceof Placeholder && $candidate->getName() === 'warranty_policy_summary');

        if (! $component instanceof Placeholder) {
            throw new LogicException('Warranty policy summary placeholder was not found.');
        }

        return $component;
    };

    expect($placeholder($schema)->getContent())->toContain('No policy selected');

    $test->set('mountedActions.0.data.warranty_policy_id', 999999);
    $schema = $schemaMethod->invoke($test->instance());
    expect($placeholder($schema)->getContent())->toBe('Warranty policy unavailable.');

    $policy = WarrantyPolicy::factory()->create([
        'duration_value' => 18,
        'covers_parts' => true,
        'covers_labour' => true,
        'covers_travel' => false,
        'covers_consumables' => false,
        'covers_third_party' => true,
    ]);

    $test->set('mountedActions.0.data.warranty_policy_id', $policy->getKey());
    $schema = $schemaMethod->invoke($test->instance());

    expect($placeholder($schema)->getContent())
        ->toContain('18', 'Parts', 'Labour', 'Third-party services');
});

it('covers valid warranty override without expiry and caught invalid maintenance transition', function (): void {
    (new SupportPermissionSeeder)->run();

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Open,
    ]);

    $test = Livewire::actingAs($actor)->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()]);
    $page = $test->instance();

    $headerMethod = new ReflectionMethod(ViewMaintenanceRequest::class, 'getHeaderActions');
    $override = batch62Action($headerMethod->invoke($page), 'overrideWarranty');

    ($override->getActionFunction())([
        'warranty_status' => WarrantyStatus::Expired->value,
        'reason' => 'Warranty expired before intake.',
    ]);

    expect($record->refresh()->warranty_status)->toBe(WarrantyStatus::Expired);

    $badRecord = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Open,
    ]);
    $badTest = Livewire::actingAs($actor)->test(ViewMaintenanceRequest::class, ['record' => $badRecord->getRouteKey()]);
    $transitionMethod = new ReflectionMethod(ViewMaintenanceRequest::class, 'transitionAction');
    /** @var Action $invalidTransition */
    $invalidTransition = $transitionMethod->invoke(
        $badTest->instance(),
        'invalidClose',
        'Invalid Close',
        MaintenanceStatus::Closed,
    );

    ($invalidTransition->getActionFunction())();

    expect($badRecord->refresh()->status)->toBe(MaintenanceStatus::Open);
});

it('covers serialized-unit warranty activation success and caught repeated activation and cancellation', function (): void {
    (new SupportPermissionSeeder)->run();

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $unit = SerializedInventoryUnit::factory()->create();
    $entitlement = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'state' => WarrantyEntitlementState::PendingActivation,
        'starts_on' => null,
        'expires_on' => null,
    ]);

    $page = Livewire::actingAs($actor)
        ->test(ViewSerializedInventoryUnit::class, ['record' => $unit->getRouteKey()])
        ->instance();

    $header = new ReflectionMethod(ViewSerializedInventoryUnit::class, 'getHeaderActions');
    $actions = $header->invoke($page);
    $activate = batch62Action($actions, 'activateWarranty');
    $cancel = batch62Action($actions, 'cancelWarrantyEntitlement');

    ($activate->getActionFunction())($unit->refresh(), [
        'starts_on' => today()->toDateString(),
        'reason' => 'Installation completed.',
    ]);

    expect($entitlement->refresh()->state)->toBe(WarrantyEntitlementState::Active);

    ($activate->getActionFunction())($unit->refresh(), [
        'starts_on' => today()->toDateString(),
        'reason' => 'Repeated activation is handled in the action.',
    ]);

    expect($entitlement->refresh()->state)->toBe(WarrantyEntitlementState::Active);

    ($cancel->getActionFunction())($unit->refresh(), [
        'reason' => 'Customer warranty cancelled for coverage test.',
    ]);

    expect($entitlement->refresh()->state)->toBe(WarrantyEntitlementState::Cancelled);

    ($cancel->getActionFunction())($unit->refresh(), [
        'reason' => 'Repeated cancellation is handled in the action.',
    ]);

    expect($entitlement->refresh()->state)->toBe(WarrantyEntitlementState::Cancelled);
});

it('covers warranty recovery submit decision and receipt exception notifications', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $submitRecord = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty,
        'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty,
    ]);
    $submitClaim = WarrantyRecoveryClaim::factory()->create([
        'maintenance_record_id' => $submitRecord->getKey(),
        'status' => WarrantyRecoveryStatus::Rejected,
    ]);
    $submit = batch62Action(WarrantyRecoveryActions::make(), 'submitWarrantyRecovery');
    ($submit->getActionFunction())($submitRecord->refresh(), ['external_reference' => 'RETRY-INVALID']);
    expect($submitClaim->refresh()->status)->toBe(WarrantyRecoveryStatus::Rejected);

    $decisionRecord = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty,
        'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty,
    ]);
    $decisionClaim = WarrantyRecoveryClaim::factory()->create([
        'maintenance_record_id' => $decisionRecord->getKey(),
        'status' => WarrantyRecoveryStatus::Submitted,
        'claimed_amount_minor' => 1000,
    ]);
    $decision = batch62Action(WarrantyRecoveryActions::make(), 'decideWarrantyRecovery');
    ($decision->getActionFunction())($decisionRecord->refresh(), [
        'decision' => 'approved',
        'approved_amount' => '0',
    ]);
    expect($decisionClaim->refresh()->status)->toBe(WarrantyRecoveryStatus::Submitted);

    $receiptRecord = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::ThirdPartyWarranty,
        'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty,
    ]);
    $receiptClaim = WarrantyRecoveryClaim::factory()->create([
        'maintenance_record_id' => $receiptRecord->getKey(),
        'status' => WarrantyRecoveryStatus::Approved,
        'claimed_amount_minor' => 1000,
        'approved_amount_minor' => 1000,
        'received_amount_minor' => 0,
    ]);
    $receipt = batch62Action(WarrantyRecoveryActions::make(), 'recordWarrantyRecoveryReceipt');
    ($receipt->getActionFunction())($receiptRecord->refresh(), [
        'received_amount' => '20',
    ]);
    expect($receiptClaim->refresh()->status)->toBe(WarrantyRecoveryStatus::Approved);
});

it('dispatches purchase-order received accounting notification through the accounting role', function (): void {
    (new NotificationTemplateSeeder)->run();
    (new AccountingPermissionSeeder)->run();

    $accountant = User::factory()->admin()->create();
    $accountant->assignRole(DashboardRole::Accountant->value);

    $order = PurchaseOrder::factory()->accepted()->create();
    $before = NotificationDelivery::query()->where('notifiable_id', $accountant->getKey())->count();

    app(SendBusinessNotification::class)->handle(new PurchaseOrderReceived($order));

    expect(NotificationDelivery::query()->where('notifiable_id', $accountant->getKey())->count())
        ->toBeGreaterThan($before);
});
