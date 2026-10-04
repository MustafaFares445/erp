<?php

declare(strict_types=1);

use App\Enums\KnowledgeArticleStatus;
use App\Enums\KnowledgeArticleVisibility;
use App\Enums\TicketKnowledgeLinkType;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\KnowledgeArticle;
use App\Models\Ticket;
use App\Models\TicketKnowledgeArticle;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Support\KnowledgeArticleService;
use App\Services\Support\KnowledgeSuggestionService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('support.knowledge_base_enabled', true);
    config()->set('support.customer_support_api_enabled', true);
    config()->set('support.support_automation_enabled', false);
    (new SupportPermissionSeeder)->run();
});

function publishedKnowledge(array $attributes = []): KnowledgeArticle
{
    $author = User::factory()->admin()->create();

    return KnowledgeArticle::query()->create([
        'title' => 'Printer paper jam recovery',
        'slug' => 'printer-paper-jam-recovery-'.fake()->unique()->numberBetween(1, 999999),
        'summary' => 'Steps for recovering a printer after repeated paper jams.',
        'body' => '<p>Power off the printer, inspect the feed path, and remove trapped paper.</p>',
        'visibility' => KnowledgeArticleVisibility::Both,
        'status' => KnowledgeArticleStatus::Published,
        'locale' => 'en',
        'published_at' => now(),
        'author_id' => $author->getKey(),
        'updated_by' => $author->getKey(),
        ...$attributes,
    ]);
}

it('suggests published knowledge by ticket type and keywords', function (): void {
    $ticket = Ticket::factory()->create([
        'type' => TicketType::HardwareIssue,
        'title' => 'Printer keeps jamming',
        'description' => 'The paper feed jams every few pages.',
    ]);

    $matching = publishedKnowledge();
    DB::table('knowledge_article_ticket_types')->insert([
        'knowledge_article_id' => $matching->getKey(),
        'ticket_type' => TicketType::HardwareIssue->value,
    ]);

    $other = publishedKnowledge([
        'title' => 'General account setup',
        'slug' => 'general-account-setup',
        'summary' => 'How to configure a new account.',
    ]);

    $suggestions = app(KnowledgeSuggestionService::class)->suggestForTicket($ticket, 5);

    expect($suggestions->first()?->getKey())->toBe($matching->getKey())
        ->and($suggestions->pluck('id')->all())->toContain($other->getKey());
});

it('shares only published customer-visible articles into the ticket conversation', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => TicketStatus::Live,
    ]);
    $article = publishedKnowledge();

    $link = app(KnowledgeArticleService::class)->shareWithCustomer($ticket, $article, $manager);

    expect($link->link_type)->toBe(TicketKnowledgeLinkType::SharedWithCustomer)
        ->and(TicketKnowledgeArticle::query()->whereKey($link->getKey())->exists())->toBeTrue()
        ->and(TicketMessage::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('is_internal_note', false)
            ->where('message', 'like', '%'.$article->title.'%')
            ->exists())->toBeTrue();

    $internal = publishedKnowledge([
        'title' => 'Internal runbook',
        'slug' => 'internal-runbook',
        'visibility' => KnowledgeArticleVisibility::Internal,
    ]);

    expect(fn () => app(KnowledgeArticleService::class)->shareWithCustomer($ticket, $internal, $manager))
        ->toThrow(DomainException::class);
});

it('exposes only published customer-visible articles through the customer API', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $visible = publishedKnowledge();
    publishedKnowledge([
        'title' => 'Internal only article',
        'slug' => 'internal-only-article',
        'visibility' => KnowledgeArticleVisibility::Internal,
    ]);
    publishedKnowledge([
        'title' => 'Draft customer article',
        'slug' => 'draft-customer-article',
        'status' => KnowledgeArticleStatus::Draft,
    ]);

    $this->getJson('/api/customer/support/knowledge')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->getKey());

    $this->getJson('/api/customer/support/knowledge/'.$visible->getKey())
        ->assertOk()
        ->assertJsonPath('data.title', $visible->title);
});

it('returns customer-facing knowledge suggestions before a ticket is created', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $article = publishedKnowledge();
    DB::table('knowledge_article_ticket_types')->insert([
        'knowledge_article_id' => $article->getKey(),
        'ticket_type' => TicketType::HardwareIssue->value,
    ]);

    $this->getJson('/api/customer/support/knowledge/suggestions?type=hardware_issue&title=printer%20jam&description=paper%20feed%20jam')
        ->assertOk()
        ->assertJsonPath('data.0.id', $article->getKey());
});

it('hides the knowledge API when the knowledge rollout switch is disabled', function (): void {
    config()->set('support.knowledge_base_enabled', false);

    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $this->getJson('/api/customer/support/knowledge')->assertNotFound();
});
