<?php

declare(strict_types=1);

use App\Data\Crm\InteractionData;
use App\Data\Crm\LeadData;
use App\Data\Sales\OpportunityData;
use App\Enums\AccountingPermission;
use App\Enums\CrmPermission;
use App\Enums\InteractionDirection;
use App\Enums\InteractionType;
use App\Enums\InvoiceStatus;
use App\Enums\LeadSource;
use App\Enums\OpportunityOrigin;
use App\Enums\ProductStatus;
use App\Enums\SalesPermission;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\EmployeeVoiceNote;
use App\Models\FiscalPeriod;
use App\Models\Interaction;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SalesOpportunity;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\InteractionService;
use App\Services\Crm\LeadService;
use App\Services\Employees\VoiceNoteIntakeService;
use App\Services\Sales\OpportunityService;
use App\Services\Sales\PriceResolver;
use App\Services\Support\TicketIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Companion to tests/Feature/Api/ChannelApiTest.php (ADR 0012, WP-4.5). ADR
 * 0012 names this file explicitly as out of scope: 21 routes were exercised
 * by only 4 tests, with no contract proving the API and a dashboard action
 * reach the same business outcome. Each test below drives a write-capable
 * endpoint through the API and compares the resulting record against the
 * same domain service invoked directly (the path a Filament action takes),
 * asserting the business-relevant fields -- status, numbering scheme,
 * pricing, and derived state -- agree, not just that both calls succeed.
 */
function crmEmployee(array $permissions = []): User
{
    $user = User::factory()->employee()->create();
    EmployeeProfile::factory()->for($user, 'user')->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user;
}

it('creates a customer ticket through the API with the same numbering scheme and default status the dashboard intake service produces', function (): void {
    $referenceCustomer = CustomerProfile::factory()->create();
    $reference = app(TicketIntakeService::class)->create([
        'customer_id' => $referenceCustomer->getKey(),
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Reference ticket',
        'description' => 'Created directly through the intake service.',
    ], User::factory()->admin()->create());

    $user = User::factory()->customer()->create();
    $customer = CustomerProfile::factory()->for($user, 'user')->create();
    $token = $user->createToken('customer-api', ['customer'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/customer/tickets', [
            'type' => TicketType::GeneralSupport->value,
            'priority' => TicketPriority::Normal->value,
            'title' => 'API ticket',
            'description' => 'Created through the customer API.',
        ])->assertOk()
        ->assertJsonPath('data.status', $reference->status->value);

    $apiTicket = Ticket::query()->where('customer_id', $customer->getKey())->sole();

    expect($apiTicket->status)->toBe($reference->status)
        ->and($apiTicket->is_chargeable)->toBe($reference->is_chargeable)
        ->and($apiTicket->pending_reason)->toBe($reference->pending_reason)
        ->and($apiTicket->ticket_number)->toMatch('/^TCK-\d{6}$/');

    $this->withToken($token)
        ->getJson('/api/v1/customer/tickets')
        ->assertOk()
        ->assertJsonPath('data.0.id', $apiTicket->getKey());

    $this->withToken($token)
        ->getJson('/api/v1/customer/tickets/'.$apiTicket->getKey())
        ->assertOk()
        ->assertJsonPath('data.title', 'API ticket');
});

it('captures a lead through the employee API with the same numbering scheme, status, and stage transition the dashboard LeadService produces', function (): void {
    $reference = app(LeadService::class)->create(new LeadData(
        source: LeadSource::FieldObservation,
        email: 'reference-lead@example.test',
    ), User::factory()->admin()->create());

    $employee = crmEmployee([CrmPermission::LeadCreate->value]);
    $token = $employee->createToken('employee-api', ['employee'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/employee/leads', [
            'source' => LeadSource::FieldObservation->value,
            'email' => 'api-lead@example.test',
            'first_name' => 'Field',
            'last_name' => 'Lead',
        ])->assertOk()
        ->assertJsonPath('data.status', $reference->status->value)
        ->assertJsonPath('data.source', LeadSource::FieldObservation->value);

    $apiLead = Lead::query()->where('email', 'api-lead@example.test')->sole();

    expect($apiLead->status)->toBe($reference->status)
        ->and($apiLead->lead_number)->toMatch('/^LEAD-\d+$/')
        ->and($apiLead->assigned_to)->toBe($employee->getKey())
        ->and($apiLead->stageTransitions()->count())->toBe(1)
        ->and($apiLead->stageTransitions()->sole()->to_status)->toBe($reference->status);
});

it('logs a CRM interaction through the employee API using the same subject linkage and last-interaction bookkeeping as the dashboard InteractionService', function (): void {
    $employee = crmEmployee([CrmPermission::LeadCreate->value, CrmPermission::InteractionCreate->value]);
    $token = $employee->createToken('employee-api', ['employee'])->plainTextToken;

    $leadId = $this->withToken($token)
        ->postJson('/api/v1/employee/leads', [
            'source' => LeadSource::FieldObservation->value,
            'email' => 'interaction-lead@example.test',
        ])->assertOk()->json('data.id');

    $reference = app(InteractionService::class)->log(new InteractionData(
        subject: Lead::query()->findOrFail($leadId),
        type: InteractionType::Call,
        direction: InteractionDirection::Outbound,
        occurredAt: now(),
        summary: 'Reference interaction logged directly.',
    ), $employee);

    $this->withToken($token)
        ->postJson('/api/v1/employee/interactions', [
            'subject_type' => 'lead',
            'subject_id' => $leadId,
            'type' => InteractionType::Call->value,
            'direction' => InteractionDirection::Outbound->value,
            'summary' => 'API interaction logged.',
        ])->assertOk()
        ->assertJsonPath('data.type', InteractionType::Call->value)
        ->assertJsonPath('data.direction', InteractionDirection::Outbound->value);

    $apiInteraction = Interaction::query()->where('summary', 'API interaction logged.')->sole();

    expect($apiInteraction->subject_type)->toBe($reference->subject_type)
        ->and((int) $apiInteraction->subject_id)->toBe((int) $leadId)
        ->and($apiInteraction->employee_id)->toBe($reference->employee_id)
        ->and(Lead::query()->findOrFail($leadId)->last_interaction_at)->not->toBeNull();
});

it('creates a sales opportunity through the employee API with the same lead-origin inference the dashboard OpportunityService applies', function (): void {
    $employee = crmEmployee([CrmPermission::LeadCreate->value]);
    $token = $employee->createToken('employee-api', ['employee'])->plainTextToken;

    $leadId = $this->withToken($token)
        ->postJson('/api/v1/employee/leads', [
            'source' => LeadSource::FieldObservation->value,
            'email' => 'opportunity-lead@example.test',
        ])->assertOk()->json('data.id');

    $reference = app(OpportunityService::class)->create(new OpportunityData(
        summary: 'Reference opportunity created directly.',
        leadId: $leadId,
        ownerId: $employee->getKey(),
    ), $employee);

    $this->withToken($token)
        ->postJson('/api/v1/employee/opportunities', [
            'summary' => 'API opportunity created.',
            'lead_id' => $leadId,
        ])->assertOk()
        ->assertJsonPath('data.origin', $reference->origin->value)
        ->assertJsonPath('data.stage', $reference->stage->value)
        ->assertJsonPath('data.status', $reference->status->value);

    $apiOpportunity = SalesOpportunity::query()->where('summary', 'API opportunity created.')->sole();

    expect($apiOpportunity->origin)->toBe(OpportunityOrigin::Lead)
        ->and($apiOpportunity->origin)->toBe($reference->origin)
        ->and($apiOpportunity->stage)->toBe($reference->stage)
        ->and($apiOpportunity->owner_id)->toBe($employee->getKey());
});

it('stores an employee voice note through the API with the same pending transcription state the intake service produces directly', function (): void {
    Bus::fake();
    Storage::fake('local');

    $employee = crmEmployee();
    $visit = CustomerVisit::factory()->for($employee->employeeProfile, 'employee')->create();
    $token = $employee->createToken('employee-api', ['employee'])->plainTextToken;

    $referenceVisit = CustomerVisit::factory()->for($employee->employeeProfile, 'employee')->create();
    Storage::disk('local')->put('tmp/reference.mp3', str_repeat('a', 20));
    $reference = app(VoiceNoteIntakeService::class)->intake($referenceVisit, 'tmp/reference.mp3', 'reference.mp3', 'en', 30);

    $this->withToken($token)
        ->post('/api/v1/employee/visits/'.$visit->getKey().'/voice-notes', [
            'audio' => UploadedFile::fake()->create('note.mp3', 100),
            'language' => 'en',
            'duration_seconds' => 30,
        ])->assertOk()
        ->assertJsonPath('data.language', 'en')
        ->assertJsonPath('data.duration_seconds', 30)
        ->assertJsonPath('data.transcription_status', $reference->transcription?->status?->value);

    $apiNote = EmployeeVoiceNote::query()->where('customer_visit_id', $visit->getKey())->sole();

    expect($apiNote->status)->toBe($reference->status)
        ->and($apiNote->transcription->status)->toBe($reference->transcription->status);
});

it('completes an employee van sale through the API using the same pricing, reservation, and invoicing services a dashboard-created order relies on', function (): void {
    seedPostingAccounts();
    FiscalPeriod::factory()->create();

    $employee = User::factory()->employee()->create();
    $employee->givePermissionTo(Permission::findOrCreate(SalesPermission::InvoiceManage->value, 'web'));
    $employee->givePermissionTo(Permission::findOrCreate(SalesPermission::InvoiceIssue->value, 'web'));
    $employee->givePermissionTo(Permission::findOrCreate(AccountingPermission::JournalEntryPostFromSource->value, 'web'));

    $warehouse = Warehouse::factory()->create();
    EmployeeProfile::factory()->for($employee, 'user')->create(['van_warehouse_id' => $warehouse->getKey()]);

    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->grain()->create([
        'base_price' => '50.00',
        'min_price' => '40.00',
        'status' => ProductStatus::Active,
        'is_active' => true,
    ]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '10.000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'expires_at' => null,
    ]);

    $resolved = app(PriceResolver::class)->resolve($variant);
    $token = $employee->createToken('employee-api', ['employee'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/employee/van-sales', [
            'customer_id' => $customer->getKey(),
            'products' => [[
                'product_variant_id' => $variant->getKey(),
                'quantity' => 2,
                'inventory_lot_id' => $lot->getKey(),
            ]],
        ])->assertOk();

    $order = Order::query()->findOrFail($response->json('data.order.id'));
    $invoice = Invoice::query()->findOrFail($response->json('data.invoice.id'));
    $line = $order->lines()->sole();

    expect((float) $line->unit_price)->toBe($resolved->amount)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->customer_id)->toBe($customer->getKey())
        ->and((float) $invoice->total_amount)->toBe((float) $order->grand_total)
        ->and(InventoryStock::query()->whereBelongsTo($warehouse)->whereBelongsTo($variant)->value('on_hand_quantity'))->toBe('8.000000');
});
