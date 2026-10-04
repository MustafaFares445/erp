<?php

declare(strict_types=1);

use App\Enums\CustomerApprovalStatus;
use App\Enums\InvoiceStatus;
use App\Enums\KnowledgeArticleStatus;
use App\Enums\KnowledgeArticleVisibility;
use App\Enums\QuotationStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SupportEntitlementStatus;
use App\Enums\TicketKnowledgeLinkType;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\MaintenanceRecord;
use App\Models\Quotation;
use App\Models\SerializedInventoryUnit;
use App\Models\SupportEntitlement;
use App\Models\SupportServiceLevel;
use App\Models\Ticket;
use App\Models\TicketKnowledgeArticle;
use App\Models\TicketPaymentLink;
use App\Models\User;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('support.customer_support_api_enabled', true);
    config()->set('support.knowledge_base_enabled', true);
    config()->set('support.csat_enabled', true);
    config()->set('support.support_automation_enabled', false);
    config()->set('support.sla_v2_enabled', true);
    config()->set('support.customer_api_redirect_hosts', ['example.test']);
    (new SlaPolicySeeder)->run();
});

/** @param array<string, mixed> $attributes */
function securityArticle(array $attributes): KnowledgeArticle
{
    $author = User::factory()->admin()->create();

    return KnowledgeArticle::query()->create([
        'title' => 'Article '.fake()->unique()->numberBetween(1, 99999),
        'slug' => 'article-'.fake()->unique()->numberBetween(1, 99999),
        'summary' => 'Summary',
        'body' => '<p>Body</p>',
        'locale' => 'en',
        'published_at' => now(),
        'author_id' => $author->getKey(),
        'updated_by' => $author->getKey(),
        ...$attributes,
    ]);
}

/** Concrete URL for every named customer route (the login route is intentionally public). */
function customerApiRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
        if (! str_starts_with($name, 'api.customer.')) {
            continue;
        }
        if ($name === 'api.customer.login') {
            continue;
        }
        $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
        $routes[$name] = [$route->methods()[0], '/'.$uri];
    }

    return $routes;
}

it('requires a bearer token on every customer route', function (): void {
    foreach (customerApiRoutes() as $name => [$method, $uri]) {
        $this->json($method, $uri)->assertUnauthorized($name);
    }
});

it('refuses staff accounts on every customer route', function (): void {
    Sanctum::actingAs(User::factory()->admin()->create(), ['customer:*']);

    $this->getJson('/api/customer/support/tickets')->assertForbidden();

    foreach (customerApiRoutes() as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBeIn([403, 404]);
    }
});

it('refuses customer users that have no customer profile', function (): void {
    Sanctum::actingAs(User::factory()->customer()->create(), ['customer:*']);

    foreach (customerApiRoutes() as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBeIn([403, 404]);
    }
});

it('refuses tokens that lack the customer ability', function (): void {
    Sanctum::actingAs(CustomerProfile::factory()->create()->user, ['something:else']);

    $this->getJson('/api/customer/support/tickets')->assertForbidden();

    foreach (customerApiRoutes() as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBeIn([403, 404]);
    }
});

it('refuses suspended and unapproved customers even with a valid token', function (): void {
    $suspended = CustomerProfile::factory()->create(['is_active' => false]);
    $pending = CustomerProfile::factory()->create(['approval_status' => CustomerApprovalStatus::Pending, 'is_active' => false]);

    foreach ([$suspended, $pending] as $customer) {
        Sanctum::actingAs($customer->user, ['customer:*']);

        $this->getJson('/api/customer/support/tickets')->assertForbidden();
        $this->postJson('/api/customer/support/tickets', ['title' => 'x'])->assertForbidden();
    }
});

it('issues, expires and revokes customer tokens', function (): void {
    $customer = CustomerProfile::factory()->create();
    $user = $customer->user;

    $login = $this->postJson('/api/customer/login', ['login' => $user->username, 'password' => 'password', 'device_name' => 'pixel'])
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('customer.id', $customer->getKey())
        ->assertJsonMissingPath('user.password');

    $token = (string) $login->json('token');

    expect($login->getContent())->not->toContain('password');

    $this->withToken($token)->getJson('/api/customer/support/tickets')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/customer/logout')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/customer/support/tickets')->assertUnauthorized();

    $second = (string) $this->postJson('/api/customer/login', ['login' => $user->email, 'password' => 'password'])->json('token');

    app('auth')->forgetGuards();
    $this->travel(31)->days();
    $this->withToken($second)->getJson('/api/customer/support/tickets')->assertUnauthorized();
});

it('rejects bad credentials identically for unknown and known accounts, and non-customer accounts', function (): void {
    $customer = CustomerProfile::factory()->create();
    $staff = User::factory()->admin()->create();

    $wrong = $this->postJson('/api/customer/login', ['login' => $customer->user->username, 'password' => 'nope'])
        ->assertUnprocessable();
    $unknown = $this->postJson('/api/customer/login', ['login' => 'nobody-here', 'password' => 'nope'])
        ->assertUnprocessable();
    $this->postJson('/api/customer/login', ['login' => $staff->username, 'password' => 'password'])
        ->assertUnprocessable();

    expect($wrong->json('message'))->toBe($unknown->json('message'));

    $this->postJson('/api/customer/login', [])->assertUnprocessable()->assertJsonValidationErrors(['login', 'password']);
});

it('refuses login for suspended customers and throttles repeated guesses', function (): void {
    $suspended = CustomerProfile::factory()->create(['is_active' => false]);

    $this->postJson('/api/customer/login', ['login' => $suspended->user->username, 'password' => 'password'])
        ->assertForbidden();

    $customer = CustomerProfile::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/customer/login', ['login' => $customer->user->username, 'password' => 'wrong'])->assertUnprocessable();
    }

    $this->postJson('/api/customer/login', ['login' => $customer->user->username, 'password' => 'password'])->assertTooManyRequests();
});

it('ignores authoritative fields supplied by the customer when opening a ticket', function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $this->postJson('/api/customer/support/tickets', [
        'type' => TicketType::HardwareIssue->value,
        'title' => 'Mass assignment probe',
        'description' => 'Trying to set staff-owned fields.',
        'external_equipment_name' => 'Probe',
        'customer_id' => $other->getKey(),
        'status' => TicketStatus::Resolved->value,
        'priority' => 'urgent',
        'is_chargeable' => true,
        'warranty_status' => 'in_warranty',
        'created_by' => User::factory()->admin()->create()->getKey(),
        'assigned_employee_id' => 1,
        'diagnostic_fee_amount' => 0,
    ])->assertCreated();

    $ticket = Ticket::query()->latest('id')->firstOrFail();

    expect($ticket->customer_id)->toBe($customer->getKey())
        ->and($ticket->created_by)->toBe($customer->user_id)
        ->and($ticket->status)->toBe(TicketStatus::Pending)
        ->and($ticket->is_chargeable)->toBeFalse()
        ->and($ticket->assigned_employee_id)->toBeNull();
});

it("keeps staff-attached files off the customer API while serving the customer's own uploads privately", function (): void {
    Storage::fake('local');

    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $this->postJson('/api/customer/support/tickets', [
        'type' => TicketType::HardwareIssue->value,
        'title' => 'With photo',
        'description' => 'Photo attached by the customer.',
        'external_equipment_name' => 'Printer',
        'attachments' => [UploadedFile::fake()->image('photo.png')],
    ])->assertCreated();

    $ticket = Ticket::query()->latest('id')->firstOrFail();
    $staffMedia = $ticket->addMediaFromString('internal cost sheet')->usingFileName('costs.txt')->toMediaCollection('ticket-attachments', 'local');
    $ownMedia = $ticket->getMedia('ticket-attachments')->first(static fn ($media): bool => $media->getCustomProperty('visibility') === 'customer');

    $listed = $this->getJson('/api/customer/support/tickets/'.$ticket->getKey())
        ->assertOk()
        ->assertJsonCount(1, 'data.attachments')
        ->json('data.attachments.0.file_name');

    expect($listed)->toBe('photo.png');

    $this->get('/api/customer/support/tickets/'.$ticket->getKey().'/attachments/'.$ownMedia->getKey())->assertOk();
    $this->get('/api/customer/support/tickets/'.$ticket->getKey().'/attachments/'.$staffMedia->getKey())->assertNotFound();

    $otherTicket = Ticket::factory()->create(['customer_id' => $customer->getKey()]);
    $this->get('/api/customer/support/tickets/'.$otherTicket->getKey().'/attachments/'.$ownMedia->getKey())->assertNotFound();

    Sanctum::actingAs($other->user, ['customer:*']);
    $this->get('/api/customer/support/tickets/'.$ticket->getKey().'/attachments/'.$ownMedia->getKey())->assertNotFound();
});

it('rejects oversized, wrongly typed and too many attachments', function (): void {
    Storage::fake('local');
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $base = ['type' => TicketType::HardwareIssue->value, 'title' => 'Bad files', 'description' => 'Invalid attachments.', 'external_equipment_name' => 'X'];

    $this->postJson('/api/customer/support/tickets', $base + ['attachments' => [UploadedFile::fake()->create('big.pdf', 20_000, 'application/pdf')]])
        ->assertUnprocessable();
    $this->postJson('/api/customer/support/tickets', $base + ['attachments' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')]])
        ->assertUnprocessable();
    $this->postJson('/api/customer/support/tickets', $base + ['attachments' => array_map(
        static fn (int $i): UploadedFile => UploadedFile::fake()->image("p{$i}.png"),
        range(1, 6),
    )])->assertUnprocessable()->assertJsonValidationErrors('attachments');

    expect(Ticket::query()->count())->toBe(0);
});

it("returns 404 for every other customer's ticket, message, payment, feedback and maintenance resource", function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $ticket = Ticket::factory()->create(['customer_id' => $other->getKey(), 'status' => TicketStatus::Closed]);
    TicketPaymentLink::factory()->for($ticket)->create();
    $record = MaintenanceRecord::factory()->create(['customer_id' => $other->getKey()]);

    $this->getJson("/api/customer/support/tickets/{$ticket->getKey()}/messages")->assertNotFound();
    $this->postJson("/api/customer/support/tickets/{$ticket->getKey()}/messages", ['message' => 'hi'])->assertNotFound();
    $this->getJson("/api/customer/support/tickets/{$ticket->getKey()}/payment-status")->assertNotFound();
    $this->postJson("/api/customer/support/tickets/{$ticket->getKey()}/payment-session", [
        'success_url' => 'https://example.test/a', 'cancel_url' => 'https://example.test/b',
    ])->assertNotFound();
    $this->postJson("/api/customer/support/tickets/{$ticket->getKey()}/satisfaction", ['rating' => 5])->assertNotFound();
    $this->getJson("/api/customer/maintenance/{$record->getKey()}")->assertNotFound();
    $this->getJson('/api/customer/maintenance')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/customer/equipment')->assertOk()->assertJsonCount(0, 'data');
});

it('answers 422 instead of failing when the customer replies to a closed ticket', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    foreach ([TicketStatus::Closed, TicketStatus::Cancelled] as $status) {
        $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey(), 'status' => $status]);

        $this->postJson("/api/customer/support/tickets/{$ticket->getKey()}/messages", ['message' => 'Anyone there?'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
    }
});

it('never lets a customer pick the internal flag or exceed the message size', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);
    $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey(), 'status' => TicketStatus::Live]);

    $this->postJson("/api/customer/support/tickets/{$ticket->getKey()}/messages", ['message' => 'sneaky', 'is_internal_note' => true])
        ->assertCreated()
        ->assertJsonMissingPath('data.is_internal_note');

    expect($ticket->messages()->where('message', 'sneaky')->value('is_internal_note'))->toBeFalsy();

    $this->postJson("/api/customer/support/tickets/{$ticket->getKey()}/messages", ['message' => str_repeat('a', 10_001)])
        ->assertUnprocessable();
});

it('derives the payment amount on the server and limits redirect hosts', function (): void {
    config()->set('support.customer_api_redirect_hosts', ['customer.example.test']);

    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);
    $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey(), 'status' => TicketStatus::PendingPayment]);

    $this->getJson("/api/customer/support/tickets/{$ticket->getKey()}/payment-status")
        ->assertOk()->assertJsonPath('required', false);
    $this->postJson("/api/customer/support/tickets/{$ticket->getKey()}/payment-session", [
        'success_url' => 'https://customer.example.test/ok', 'cancel_url' => 'https://customer.example.test/no',
    ])->assertUnprocessable();

    TicketPaymentLink::factory()->for($ticket)->create(['amount' => 40, 'currency' => 'AED']);

    $this->postJson("/api/customer/support/tickets/{$ticket->getKey()}/payment-session", [
        'success_url' => 'https://evil.test/ok', 'cancel_url' => 'https://customer.example.test/no',
    ])->assertUnprocessable()->assertJsonValidationErrors('success_url');

    $this->postJson("/api/customer/support/tickets/{$ticket->getKey()}/payment-session", [
        'success_url' => 'https://app.customer.example.test/ok', 'cancel_url' => 'https://customer.example.test/no',
        'amount' => 1, 'currency' => 'USD',
    ])->assertCreated()->assertJsonPath('amount_minor', 4000)->assertJsonPath('currency', 'AED');
});

it('validates satisfaction ratings and only accepts one response for a closed ticket', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);
    $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey(), 'status' => TicketStatus::Closed]);
    $url = "/api/customer/support/tickets/{$ticket->getKey()}/satisfaction";

    foreach ([0, 6, 'five', null] as $rating) {
        $this->postJson($url, ['rating' => $rating])->assertUnprocessable();
    }

    $this->postJson($url, ['rating' => 4, 'comment' => 'Good'])->assertSuccessful();
    $this->postJson($url, ['rating' => 5])->assertStatus(422);
});

it('hides draft quotations and invoices from the maintenance endpoint', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $record = MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
        'quotation_id' => Quotation::factory()->create(['status' => QuotationStatus::Draft])->getKey(),
        'invoice_id' => Invoice::factory()->create(['status' => InvoiceStatus::Draft])->getKey(),
    ]);

    $this->getJson("/api/customer/maintenance/{$record->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.quotation', null)
        ->assertJsonPath('data.invoice', null);

    $record->quotation->update(['status' => QuotationStatus::Sent]);
    $record->invoice->update(['status' => InvoiceStatus::Issued]);

    $this->getJson("/api/customer/maintenance/{$record->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.quotation.status', 'sent')
        ->assertJsonPath('data.invoice.status', 'issued')
        ->assertJsonMissingPath('data.internal_cost_minor');
});

it('exposes only published customer-visible knowledge and drops shared links that were later withdrawn', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $published = securityArticle(['status' => KnowledgeArticleStatus::Published, 'visibility' => KnowledgeArticleVisibility::Customer, 'locale' => 'en']);
    $internal = securityArticle(['status' => KnowledgeArticleStatus::Published, 'visibility' => KnowledgeArticleVisibility::Internal, 'locale' => 'en']);
    $draft = securityArticle(['status' => KnowledgeArticleStatus::Draft, 'visibility' => KnowledgeArticleVisibility::Customer, 'locale' => 'en']);
    $archived = securityArticle(['status' => KnowledgeArticleStatus::Archived, 'visibility' => KnowledgeArticleVisibility::Both, 'locale' => 'en']);

    $ids = collect($this->getJson('/api/customer/support/knowledge')->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toBe([$published->getKey()]);

    foreach ([$internal, $draft, $archived] as $article) {
        $this->getJson('/api/customer/support/knowledge/'.$article->getKey())->assertNotFound();
    }

    $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey()]);
    foreach ([$published, $internal, $draft] as $article) {
        TicketKnowledgeArticle::query()->create([
            'ticket_id' => $ticket->getKey(),
            'knowledge_article_id' => $article->getKey(),
            'link_type' => TicketKnowledgeLinkType::SharedWithCustomer,
        ]);
    }

    $shared = collect($this->getJson('/api/customer/support/tickets/'.$ticket->getKey())->assertOk()->json('data.shared_knowledge'))->pluck('id');

    expect($shared->all())->toBe([$published->getKey()]);
});

it('keeps internal fields out of the ticket and maintenance resources', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);
    $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey()]);
    $record = MaintenanceRecord::factory()->create(['customer_id' => $customer->getKey(), 'ticket_id' => $ticket->getKey()]);

    $payload = json_encode([
        $this->getJson('/api/customer/support/tickets/'.$ticket->getKey())->assertOk()->json(),
        $this->getJson('/api/customer/maintenance/'.$record->getKey())->assertOk()->json(),
    ], JSON_THROW_ON_ERROR);

    foreach (['is_internal_note', 'internal', 'cost', 'margin', 'created_by', 'updated_by', 'assigned_employee', 'journal', 'ledger'] as $forbidden) {
        expect($payload)->not->toContain($forbidden);
    }
});

it("shows the active support service level and next preventive date on the customer's own equipment only", function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->getKey(),
    ]);
    SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $other->getKey(),
    ]);

    $level = SupportServiceLevel::query()->create(['code' => 'GOLD', 'name' => 'Gold']);
    SupportEntitlement::query()->create([
        'customer_id' => $customer->getKey(),
        'support_service_level_id' => $level->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'starts_on' => today()->subMonth()->toDateString(),
        'ends_on' => today()->addMonth()->toDateString(),
        'status' => SupportEntitlementStatus::Active,
    ]);
    SupportEntitlement::query()->create([
        'customer_id' => $customer->getKey(),
        'support_service_level_id' => SupportServiceLevel::query()->create(['code' => 'OLD', 'name' => 'Expired tier'])->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'starts_on' => today()->subYear()->toDateString(),
        'ends_on' => today()->subMonths(6)->toDateString(),
        'status' => SupportEntitlementStatus::Active,
    ]);

    $this->getJson('/api/customer/equipment')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.service_level', 'Gold');
    $this->getJson('/api/customer/equipment/'.$unit->getKey())->assertOk()->assertJsonPath('data.service_level', 'Gold');
});

it('reports the issued invoice total on the maintenance endpoint', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $record = MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_id' => Invoice::factory()->create(['status' => InvoiceStatus::Issued, 'total_amount' => 321.5])->getKey(),
    ]);

    $this->getJson('/api/customer/maintenance/'.$record->getKey())
        ->assertOk()
        ->assertJsonPath('data.invoice.total', 321.5);
});
