<?php

declare(strict_types=1);

use App\Enums\CustomerSupportStage;
use App\Enums\KnowledgeArticleStatus;
use App\Enums\KnowledgeArticleVisibility;
use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\TicketKnowledgeLinkType;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Filament\Resources\KnowledgeArticles\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticles\Pages\EditKnowledgeArticle;
use App\Http\Resources\Api\Customer\SupportTicketResource;
use App\Models\CustomerProfile;
use App\Models\KnowledgeArticle;
use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\TicketKnowledgeArticle;
use App\Models\TicketMessage;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Payments\Providers\StripeCheckoutSessionData;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Support\CustomerSupportStateResolver;
use App\Services\Support\KnowledgeArticleService;
use App\Services\Support\KnowledgeSuggestionService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('support.knowledge_base_enabled', true);
    config()->set('support.customer_support_api_enabled', true);
    config()->set('support.support_automation_enabled', false);
    config()->set('support.sla_v2_enabled', true);
    config()->set('support.customer_api_redirect_hosts', ['customer.example.test']);
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

/** @param array<string, mixed> $attributes */
function coverageArticle(array $attributes = []): KnowledgeArticle
{
    $author = User::factory()->admin()->create();

    return KnowledgeArticle::query()->create([
        'title' => 'Printer paper jam recovery',
        'slug' => 'printer-paper-jam-recovery-'.fake()->unique()->numberBetween(1, 999999),
        'summary' => 'Steps for recovering a printer after repeated paper jams.',
        'body' => '<p>Power off the printer and inspect the feed path.</p>',
        'visibility' => KnowledgeArticleVisibility::Both,
        'status' => KnowledgeArticleStatus::Published,
        'locale' => 'en',
        'published_at' => now(),
        'author_id' => $author->getKey(),
        'updated_by' => $author->getKey(),
        ...$attributes,
    ]);
}

function coverageSupportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

/** Invoke a non-public method on a Livewire component instance. */
function invokeHidden(object $target, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($target, $method);

    return $reflection->invoke($target, ...$arguments);
}

// ---------------------------------------------------------------- Filament pages

it('creates a draft knowledge article, stamps the author and syncs ticket types', function (): void {
    $manager = coverageSupportManager();

    Livewire::actingAs($manager)
        ->test(CreateKnowledgeArticle::class)
        ->fillForm([
            'title' => 'Resetting a stuck printer',
            'slug' => 'resetting-a-stuck-printer',
            'visibility' => KnowledgeArticleVisibility::Customer->value,
            'locale' => 'en',
            'body' => '<p>Hold the power button for ten seconds.</p>',
            'ticket_types' => [TicketType::HardwareIssue->value, TicketType::GeneralSupport->value],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $article = KnowledgeArticle::query()->where('slug', 'resetting-a-stuck-printer')->firstOrFail();

    expect($article->status)->toBe(KnowledgeArticleStatus::Draft)
        ->and($article->author_id)->toBe($manager->getKey())
        ->and($article->updated_by)->toBe($manager->getKey())
        ->and($article->published_at)->toBeNull()
        ->and(DB::table('knowledge_article_ticket_types')
            ->where('knowledge_article_id', $article->getKey())
            ->pluck('ticket_type')
            ->sort()
            ->values()
            ->all())->toBe([TicketType::GeneralSupport->value, TicketType::HardwareIssue->value]);
});

it('refuses to create an article when no authenticated user is available', function (): void {
    $component = Livewire::actingAs(coverageSupportManager())->test(CreateKnowledgeArticle::class);
    $page = $component->instance();

    auth()->forgetGuards();

    expect(fn (): mixed => invokeHidden($page, 'mutateFormDataBeforeCreate', ['title' => 'x']))
        ->toThrow(LogicException::class, 'An authenticated User is required.');
});

it('refuses to sync ticket types when the created record is not a knowledge article', function (): void {
    $page = Livewire::actingAs(coverageSupportManager())->test(CreateKnowledgeArticle::class)->instance();
    $page->record = User::factory()->admin()->create();

    expect(fn (): mixed => invokeHidden($page, 'syncTicketTypes'))
        ->toThrow(LogicException::class, 'Expected a KnowledgeArticle record.');
});

it('loads, replaces and persists ticket types when editing an article', function (): void {
    $manager = coverageSupportManager();
    $article = coverageArticle(['status' => KnowledgeArticleStatus::Draft]);
    DB::table('knowledge_article_ticket_types')->insert([
        'knowledge_article_id' => $article->getKey(),
        'ticket_type' => TicketType::HardwareIssue->value,
    ]);

    Livewire::actingAs($manager)
        ->test(EditKnowledgeArticle::class, ['record' => $article->getKey()])
        ->assertFormSet(['ticket_types' => [TicketType::HardwareIssue->value]])
        ->fillForm([
            'title' => 'Printer paper jam recovery (updated)',
            'ticket_types' => [TicketType::GeneralSupport->value],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($article->refresh()->title)->toBe('Printer paper jam recovery (updated)')
        ->and($article->updated_by)->toBe($manager->getKey())
        ->and($article->ticketTypes())->toBe([TicketType::GeneralSupport])
        ->and(DB::table('knowledge_article_ticket_types')->where('knowledge_article_id', $article->getKey())->count())->toBe(1);
});

it('publishes and archives an article from the edit page header actions', function (): void {
    $manager = coverageSupportManager();
    $article = coverageArticle(['status' => KnowledgeArticleStatus::Draft, 'published_at' => null]);

    $component = Livewire::actingAs($manager)
        ->test(EditKnowledgeArticle::class, ['record' => $article->getKey()])
        ->assertActionVisible('publish')
        ->assertActionVisible('archive')
        ->callAction('publish')
        ->assertNotified(__('Article published'));

    $article->refresh();

    expect($article->status)->toBe(KnowledgeArticleStatus::Published)
        ->and($article->published_at)->not->toBeNull()
        ->and($article->updated_by)->toBe($manager->getKey());

    $component
        ->assertActionHidden('publish')
        ->callAction('archive')
        ->assertNotified(__('Article archived'))
        ->assertActionHidden('archive');

    expect($article->refresh()->status)->toBe(KnowledgeArticleStatus::Archived);
});

it('guards the edit page helpers against a foreign record or a missing user', function (): void {
    $page = Livewire::actingAs(coverageSupportManager())
        ->test(EditKnowledgeArticle::class, ['record' => coverageArticle()->getKey()])
        ->instance();

    expect(invokeHidden($page, 'article'))->toBeInstanceOf(KnowledgeArticle::class)
        ->and(invokeHidden($page, 'actor'))->toBeInstanceOf(User::class);

    auth()->forgetGuards();

    expect(fn (): mixed => invokeHidden($page, 'actor'))
        ->toThrow(LogicException::class, 'An authenticated User is required.');

    $page->record = User::factory()->admin()->create();

    expect(fn (): mixed => invokeHidden($page, 'article'))
        ->toThrow(LogicException::class, 'Expected a KnowledgeArticle record.');
});

// ---------------------------------------------------------------- Model

it('lists only valid ticket types linked to an article', function (): void {
    $article = coverageArticle();

    expect($article->ticketTypes())->toBe([]);

    DB::table('knowledge_article_ticket_types')->insert([
        ['knowledge_article_id' => $article->getKey(), 'ticket_type' => TicketType::HardwareIssue->value],
        ['knowledge_article_id' => $article->getKey(), 'ticket_type' => 'retired_type'],
    ]);

    expect($article->ticketTypes())->toBe([TicketType::HardwareIssue]);
});

// ---------------------------------------------------------------- KnowledgeArticleService

it('publishes an article, keeps the first publish date and records activity', function (): void {
    $manager = coverageSupportManager();
    $firstPublished = now()->subDays(3)->startOfSecond();
    $article = coverageArticle(['status' => KnowledgeArticleStatus::Draft, 'published_at' => $firstPublished]);

    $published = app(KnowledgeArticleService::class)->publish($article, $manager);

    expect($published->status)->toBe(KnowledgeArticleStatus::Published)
        ->and($published->published_at?->equalTo($firstPublished))->toBeTrue()
        ->and($published->updated_by)->toBe($manager->getKey())
        ->and(DB::table('activity_log')
            ->where('description', 'support.knowledge.published')
            ->where('subject_id', $article->getKey())
            ->where('causer_id', $manager->getKey())
            ->exists())->toBeTrue();
});

it('refuses to publish or archive without the matching knowledge permission', function (): void {
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $article = coverageArticle(['status' => KnowledgeArticleStatus::Draft]);

    expect(fn () => app(KnowledgeArticleService::class)->publish($article, $agent))
        ->toThrow(HttpException::class)
        ->and(fn () => app(KnowledgeArticleService::class)->archive($article, $agent))
        ->toThrow(HttpException::class);

    expect($article->refresh()->status)->toBe(KnowledgeArticleStatus::Draft);
});

it('archives an article and returns the refreshed model', function (): void {
    $manager = coverageSupportManager();
    $article = coverageArticle();

    $archived = app(KnowledgeArticleService::class)->archive($article, $manager);

    expect($archived->status)->toBe(KnowledgeArticleStatus::Archived)
        ->and($archived->updated_by)->toBe($manager->getKey());
});

it('does not let a support agent share knowledge on a ticket they are not assigned to', function (): void {
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);
    $article = coverageArticle();

    expect(fn () => app(KnowledgeArticleService::class)->shareWithCustomer($ticket, $article, $agent))
        ->toThrow(HttpException::class);

    expect(TicketKnowledgeArticle::query()->count())->toBe(0)
        ->and(TicketMessage::query()->where('ticket_id', $ticket->getKey())->count())->toBe(0);
});

it('marks an article as used in resolution once per ticket', function (): void {
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $ticket = Ticket::factory()->create();
    $article = coverageArticle();

    $first = app(KnowledgeArticleService::class)->markUsedInResolution($ticket, $article, $agent);
    $second = app(KnowledgeArticleService::class)->markUsedInResolution($ticket, $article, $agent);

    expect($first->link_type)->toBe(TicketKnowledgeLinkType::UsedInResolution)
        ->and($first->linked_by)->toBe($agent->getKey())
        ->and($second->getKey())->toBe($first->getKey());
});

it('keeps an existing slug and generates unique slugs for blank ones', function (): void {
    $service = app(KnowledgeArticleService::class);

    $named = new KnowledgeArticle(['title' => 'Anything', 'slug' => 'keep-me']);
    $service->ensureSlug($named);

    expect($named->slug)->toBe('keep-me');

    coverageArticle(['slug' => 'warranty-basics']);
    coverageArticle(['slug' => 'warranty-basics-2']);

    $fresh = new KnowledgeArticle(['title' => 'Warranty Basics']);
    $service->ensureSlug($fresh);

    expect($fresh->slug)->toBe('warranty-basics-3');

    $symbols = new KnowledgeArticle(['title' => '!!!']);
    $service->ensureSlug($symbols);

    expect($symbols->slug)->toBe('article');

    $existing = coverageArticle(['slug' => 'self-slug']);
    $existing->slug = '';

    $service->ensureSlug($existing);

    expect($existing->slug)->toBe('printer-paper-jam-recovery');
});

// ---------------------------------------------------------------- KnowledgeSuggestionService

it('ranks an article linked to the ticket equipment variant above keyword matches', function (): void {
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create(['product_variant_id' => $variant->getKey()]);

    $generic = coverageArticle(['title' => 'Printer maintenance guide', 'summary' => 'General printer care.']);
    $linked = coverageArticle(['title' => 'Unrelated title', 'summary' => 'Nothing to match.']);
    $linked->productVariants()->attach($variant->getKey());

    $ticket = Ticket::factory()->create([
        'title' => 'Printer maintenance needed',
        'description' => 'Printer care requested.',
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    $suggestions = app(KnowledgeSuggestionService::class)->suggestForTicket($ticket, 5);

    expect($suggestions->pluck('id')->all())->toBe([$linked->getKey(), $generic->getKey()]);
});

it('degrades to no keywords instead of failing when the regex engine gives up', function (): void {
    $service = app(KnowledgeSuggestionService::class);
    $text = str_repeat('printer jams ', 100);

    expect(invokeHidden($service, 'keywords', 'Printer jams repeatedly'))->toBe(['printer', 'jams', 'repeatedly']);

    // Evict the already-JIT-compiled pattern from PHP's regex cache so the limits below apply to it.
    foreach (range(1, 5000) as $i) {
        preg_match('/flood'.$i.'/', 'x');
    }

    $backtrack = (string) ini_get('pcre.backtrack_limit');
    $jit = (string) ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $keywords = invokeHidden($service, 'keywords', $text);
    } finally {
        ini_set('pcre.backtrack_limit', $backtrack);
        ini_set('pcre.jit', $jit);
    }

    expect($keywords)->toBe([])
        ->and(invokeHidden($service, 'keywords', $text))->toBe(['printer', 'jams']);
});

// ---------------------------------------------------------------- Customer knowledge API

it('searches customer knowledge by title or summary', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $byTitle = coverageArticle(['title' => 'Toner replacement', 'summary' => 'Swap the cartridge.']);
    $bySummary = coverageArticle(['title' => 'Cartridge basics', 'summary' => 'Everything about toner levels.']);
    coverageArticle(['title' => 'Network setup', 'summary' => 'Connect to wifi.']);

    $ids = collect($this->getJson('/api/customer/support/knowledge?q=toner')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$byTitle->getKey(), $bySummary->getKey()])->sort()->values()->all());

    $this->getJson('/api/customer/support/knowledge?q=zzz-no-match')->assertOk()->assertJsonCount(0, 'data');
});

it('suggests knowledge for the customers own equipment and rejects equipment they do not own', function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $variant = ProductVariant::factory()->create();
    $own = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->getKey(),
    ]);
    $foreign = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $other->getKey(),
    ]);

    coverageArticle(['title' => 'Generic article', 'summary' => 'Generic.']);
    $targeted = coverageArticle(['title' => 'Targeted article', 'summary' => 'Targeted.']);
    $targeted->productVariants()->attach($variant->getKey());

    $this->getJson('/api/customer/support/knowledge/suggestions?equipment_id='.$own->getKey())
        ->assertOk()
        ->assertJsonPath('data.0.id', $targeted->getKey());

    $this->getJson('/api/customer/support/knowledge/suggestions?equipment_id='.$foreign->getKey())
        ->assertNotFound();

    $this->getJson('/api/customer/support/knowledge/suggestions?equipment_id=999999')
        ->assertNotFound();
});

// ---------------------------------------------------------------- Customer auth / payment

it('refuses login for a customer account without a customer profile', function (): void {
    $user = User::factory()->customer()->create();

    $this->postJson('/api/customer/login', ['login' => $user->username, 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This customer account has no customer profile.')
        ->assertJsonMissingPath('token');

    expect($user->tokens()->count())->toBe(0);
});

it('reports a provider-side payment failure as 422 without exposing a checkout session', function (): void {
    app()->instance(StripeClientInterface::class, new class implements StripeClientInterface
    {
        public function createCheckoutSession(array $params): StripeCheckoutSessionData
        {
            throw new DomainException('Stripe is temporarily unavailable.');
        }

        public function retrievePaymentIntent(string $paymentIntentId): never
        {
            throw new LogicException('Not used.');
        }

        public function createRefund(string $paymentIntentId, ?int $amountMinor = null, ?string $idempotencyKey = null): never
        {
            throw new LogicException('Not used.');
        }
    });

    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $ticket = Ticket::factory()->create(['customer_id' => $customer->getKey(), 'status' => TicketStatus::PendingPayment]);
    $link = TicketPaymentLink::factory()->for($ticket)->create(['amount' => 40, 'currency' => 'AED']);

    $this->postJson("/api/customer/support/tickets/{$ticket->getKey()}/payment-session", [
        'success_url' => 'https://customer.example.test/ok',
        'cancel_url' => 'https://customer.example.test/no',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Stripe is temporarily unavailable.');

    expect($link->refresh()->payment_url)->toBeNull();
});

// ---------------------------------------------------------------- Resource + state resolver

it('refuses to serialise something that is not a ticket', function (): void {
    $resource = new SupportTicketResource(new stdClass);

    expect(fn (): array => $resource->toArray(request()))
        ->toThrow(LogicException::class, 'Expected a Ticket resource.');
});

it('maps terminal and review ticket statuses to customer stages', function (TicketStatus $status, CustomerSupportStage $stage, bool $actionRequired): void {
    $ticket = Ticket::factory()->create(['status' => $status]);

    $state = app(CustomerSupportStateResolver::class)->resolve($ticket);

    expect($state->stage)->toBe($stage)
        ->and($state->actionRequired)->toBe($actionRequired)
        ->and($state->actionType)->toBeNull()
        ->and($state->nextExpectedEvent)->not->toBe('');
})->with([
    'cancelled' => [TicketStatus::Cancelled, CustomerSupportStage::Cancelled, false],
    'closed' => [TicketStatus::Closed, CustomerSupportStage::Completed, false],
    'resolved' => [TicketStatus::Resolved, CustomerSupportStage::QualityCheck, false],
    'pending' => [TicketStatus::Pending, CustomerSupportStage::Received, false],
    'live' => [TicketStatus::Live, CustomerSupportStage::UnderReview, false],
    'assigned' => [TicketStatus::Assigned, CustomerSupportStage::UnderReview, false],
    'in progress' => [TicketStatus::InProgress, CustomerSupportStage::InProgress, false],
]);

it('asks the customer to pay a diagnostic fee or reply when the ticket is blocked on them', function (): void {
    $resolver = app(CustomerSupportStateResolver::class);

    $payment = $resolver->resolve(Ticket::factory()->create(['status' => TicketStatus::PendingPayment]));
    $reply = $resolver->resolve(Ticket::factory()->create(['status' => TicketStatus::WaitingCustomer]));

    expect($payment->stage)->toBe(CustomerSupportStage::ActionRequired)
        ->and($payment->actionRequired)->toBeTrue()
        ->and($payment->actionType)->toBe('diagnostic_payment')
        ->and($payment->message)->toContain('diagnostic payment')
        ->and($reply->stage)->toBe(CustomerSupportStage::ActionRequired)
        ->and($reply->actionRequired)->toBeTrue()
        ->and($reply->actionType)->toBe('reply_required')
        ->and($reply->message)->toContain('waiting for your reply');
});

it('asks for quotation approval for a sent quotation or an awaiting-approval maintenance record', function (): void {
    $resolver = app(CustomerSupportStateResolver::class);

    $quoted = Ticket::factory()->create(['status' => TicketStatus::InProgress]);
    MaintenanceRecord::factory()->create([
        'customer_id' => $quoted->customer_id,
        'ticket_id' => $quoted->getKey(),
        'status' => MaintenanceStatus::AwaitingApproval,
        'quotation_id' => Quotation::factory()->sent()->create()->getKey(),
    ]);

    $awaiting = Ticket::factory()->create(['status' => TicketStatus::Assigned, 'assigned_employee_id' => null]);
    MaintenanceRecord::factory()->create([
        'customer_id' => $awaiting->customer_id,
        'ticket_id' => $awaiting->getKey(),
        'status' => MaintenanceStatus::AwaitingApproval,
    ]);

    foreach ([$quoted, $awaiting] as $ticket) {
        $state = $resolver->resolve($ticket);

        expect($state->stage)->toBe(CustomerSupportStage::ActionRequired)
            ->and($state->actionRequired)->toBeTrue()
            ->and($state->actionType)->toBe('quotation_approval')
            ->and($state->message)->toContain('quotation');
    }
});

it('reports quality check while the linked maintenance record is in quality assurance', function (): void {
    $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);
    MaintenanceRecord::factory()->create([
        'customer_id' => $ticket->customer_id,
        'ticket_id' => $ticket->getKey(),
        'status' => MaintenanceStatus::QualityAssurance,
    ]);

    $state = app(CustomerSupportStateResolver::class)->resolve($ticket);

    expect($state->stage)->toBe(CustomerSupportStage::QualityCheck)
        ->and($state->actionRequired)->toBeFalse()
        ->and($state->actionType)->toBeNull()
        ->and($state->message)->toContain('quality assurance');
});

it('exposes the resolved stage and required action through the customer ticket API', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $cancelled = Ticket::factory()->create(['customer_id' => $customer->getKey(), 'status' => TicketStatus::Cancelled]);
    $waiting = Ticket::factory()->create(['customer_id' => $customer->getKey(), 'status' => TicketStatus::WaitingCustomer]);

    $this->getJson('/api/customer/support/tickets/'.$cancelled->getKey())
        ->assertOk()
        ->assertJsonPath('data.stage', 'cancelled')
        ->assertJsonPath('data.action_required', false);

    $this->getJson('/api/customer/support/tickets/'.$waiting->getKey())
        ->assertOk()
        ->assertJsonPath('data.stage', 'action_required')
        ->assertJsonPath('data.action_required_type', 'reply_required');
});
