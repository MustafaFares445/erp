<?php

declare(strict_types=1);

use App\Enums\KnowledgeArticleStatus;
use App\Enums\KnowledgeArticleVisibility;
use App\Enums\SerializedCustodyType;
use App\Enums\SupportAssignmentStrategy;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketKnowledgeLinkType;
use App\Enums\TicketStatus;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\Tickets\Actions\TriageTicketAction;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\CustomerProfile;
use App\Models\KnowledgeArticle;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\SupportRoutingRule;
use App\Models\SupportTeam;
use App\Models\Ticket;
use App\Models\TicketKnowledgeArticle;
use App\Models\TicketMessage;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Support\TicketMessageService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function workspaceCoverageManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

/** @param array<string, mixed> $attributes */
function workspaceCoverageArticle(array $attributes = []): KnowledgeArticle
{
    $author = User::factory()->admin()->create();

    return KnowledgeArticle::query()->create([
        'title' => 'Printer paper jam recovery',
        'slug' => 'printer-paper-jam-recovery-'.fake()->unique()->numberBetween(1, 999999),
        'summary' => 'Steps for recovering a printer after repeated paper jams.',
        'body' => '<p>Power off the printer and remove trapped paper.</p>',
        'visibility' => KnowledgeArticleVisibility::Both,
        'status' => KnowledgeArticleStatus::Published,
        'locale' => 'en',
        'published_at' => now(),
        'author_id' => $author->getKey(),
        'updated_by' => $author->getKey(),
        ...$attributes,
    ]);
}

/** @return array<string, object> */
function workspaceCoverageTriageComponents(): array
{
    $action = TriageTicketAction::make();
    $schemaProperty = new ReflectionProperty($action, 'schema');
    $components = [];

    $collect = static function (iterable $children) use (&$collect, &$components): void {
        foreach ($children as $component) {
            if (! method_exists($component, 'getName')) {
                $childrenProperty = new ReflectionProperty($component, 'childComponents');
                $childSets = $childrenProperty->getValue($component);
                $collect($childSets['default'] ?? []);

                continue;
            }

            $components[$component->getName()] = $component;
        }
    };

    $collect($schemaProperty->getValue($action));

    return $components;
}

it('re-runs routing from the ticket page and reports when no rule matches', function (): void {
    $manager = workspaceCoverageManager();
    $ticket = Ticket::factory()->triagedForMaintenance()->create();

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->callAction('routeTicket')
        ->assertNotified('No routing rule matched this ticket');

    expect($ticket->refresh()->support_team_id)->toBeNull();
});

it('re-runs routing from the ticket page and reports the matched rule', function (): void {
    $manager = workspaceCoverageManager();
    $team = SupportTeam::query()->create([
        'code' => 'RR',
        'name' => 'Re-route team',
        'assignment_strategy' => SupportAssignmentStrategy::LeastLoaded,
        'is_active' => true,
    ]);
    $rule = SupportRoutingRule::query()->create([
        'name' => 'Catch all',
        'precedence' => 1,
        'is_active' => true,
        'support_team_id' => $team->getKey(),
        'auto_assign' => false,
    ]);
    $ticket = Ticket::factory()->triagedForMaintenance()->create();

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->callAction('routeTicket')
        ->assertNotified('Ticket routed');

    expect($ticket->refresh()->support_team_id)->toBe($team->getKey())
        ->and($ticket->routed_by_rule_id)->toBe($rule->getKey());
});

it('surfaces a domain failure while posting a conversation message instead of crashing', function (): void {
    $manager = workspaceCoverageManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

    app()->instance(TicketMessageService::class, new class
    {
        public function post(): never
        {
            throw new DomainException('Messaging is locked for this ticket.');
        }
    });

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->set('replyMessage', 'This will be rejected.')
        ->call('postMessage')
        ->assertNotified('Unable to post message')
        ->assertSet('replyMessage', 'This will be rejected.');

    expect(TicketMessage::query()->where('ticket_id', $ticket->getKey())->count())->toBe(0);
});

it('suggests knowledge in the workspace and shares or links an article from it', function (): void {
    config()->set('support.knowledge_base_enabled', true);

    $manager = workspaceCoverageManager();
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'title' => 'Printer keeps jamming',
        'description' => 'The paper feed jams every few pages.',
    ]);
    $article = workspaceCoverageArticle();

    $component = Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertSee('Suggested knowledge')
        ->assertSee('Printer paper jam recovery');

    $component
        ->call('shareKnowledgeArticle', $article->getKey())
        ->assertNotified('Knowledge article shared with the customer');

    expect(TicketKnowledgeArticle::query()
        ->where('ticket_id', $ticket->getKey())
        ->where('link_type', TicketKnowledgeLinkType::SharedWithCustomer->value)
        ->exists())->toBeTrue()
        ->and(TicketMessage::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('message', 'like', '%Printer paper jam recovery%')
            ->exists())->toBeTrue();

    $component
        ->call('markKnowledgeUsed', $article->getKey())
        ->assertNotified('Knowledge article linked to the resolution');

    expect(TicketKnowledgeArticle::query()
        ->where('ticket_id', $ticket->getKey())
        ->where('knowledge_article_id', $article->getKey())
        ->where('link_type', TicketKnowledgeLinkType::UsedInResolution->value)
        ->exists())->toBeTrue();
});

it('refuses to share an internal-only knowledge article with the customer', function (): void {
    config()->set('support.knowledge_base_enabled', true);

    $manager = workspaceCoverageManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);
    $internal = workspaceCoverageArticle([
        'title' => 'Internal runbook',
        'visibility' => KnowledgeArticleVisibility::Internal,
    ]);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->call('shareKnowledgeArticle', $internal->getKey())
        ->assertNotified('Unable to share the knowledge article');

    expect(TicketKnowledgeArticle::query()->where('ticket_id', $ticket->getKey())->exists())->toBeFalse();
});

it('fails loudly when the ticket page is bound to a record that is not a ticket', function (): void {
    $page = new ViewTicket;
    $page->record = new User;

    $getTicket = new ReflectionMethod(ViewTicket::class, 'getTicket');

    expect(fn (): mixed => $getTicket->invoke($page))
        ->toThrow(LogicException::class, 'Expected a Ticket record.');
});

it('renders the legacy infolist for a ticket waiting on the customer with its payment and warranty details', function (): void {
    config()->set('support.workspace_v2_enabled', false);

    $manager = workspaceCoverageManager();
    $ticket = Ticket::factory()->triagedForMaintenance()->create([
        'status' => TicketStatus::WaitingCustomer,
        'warranty_status' => WarrantyStatus::Covered,
        'warranty_expiry_date' => '2031-05-17',
        'waiting_customer_since' => now()->subHour(),
        'diagnostic_fee_required' => true,
    ]);
    $link = TicketPaymentLink::factory()->settled()->for($ticket)->create();
    PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $ticket->customer_id,
        'purpose_type' => TicketPaymentLink::class,
        'purpose_id' => $link->getKey(),
    ]);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Waiting for customer response')
        ->assertSee('Paused — waiting for customer')
        ->assertSee('Warranty is active until 2031-05-17.')
        ->assertSee('Settled')
        ->assertDontSee('No provider transaction');
});

it('renders the legacy infolist next action and expired warranty wording for resolved and closed tickets', function (): void {
    config()->set('support.workspace_v2_enabled', false);

    $manager = workspaceCoverageManager();

    $resolved = Ticket::factory()->triagedForMaintenance()->create([
        'status' => TicketStatus::Resolved,
        'warranty_status' => WarrantyStatus::Expired,
        'warranty_expiry_date' => '2020-01-31',
        'live_at' => now()->subDays(2),
    ]);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $resolved->getRouteKey()])
        ->assertSee('Review and close')
        ->assertSee('Seller warranty expired on 2020-01-31.')
        ->assertSee('Running');

    $closed = Ticket::factory()->triagedForMaintenance()->create([
        'status' => TicketStatus::Closed,
        'warranty_status' => WarrantyStatus::Expired,
        'warranty_expiry_date' => null,
    ]);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $closed->getRouteKey()])
        ->assertSee('Complete')
        ->assertSee('Seller warranty expired. Other coverage sources may still apply after diagnosis.');
});

it('limits the SLA risk tab to tickets whose first response or resolution is due soon', function (): void {
    $manager = workspaceCoverageManager();

    $responseSoon = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'response_due_at' => now()->addMinutes(30),
        'resolution_due_at' => now()->addDays(3),
    ]);
    $resolutionSoon = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'first_response_at' => now()->subHour(),
        'response_due_at' => now()->addDays(2),
        'resolution_due_at' => now()->addHours(2),
    ]);
    $comfortable = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'response_due_at' => now()->addHours(6),
        'resolution_due_at' => now()->addDays(3),
    ]);

    Livewire::actingAs($manager)
        ->test(ListTickets::class)
        ->set('activeTab', 'sla_risk')
        ->assertCanSeeTableRecords([$responseSoon, $resolutionSoon])
        ->assertCanNotSeeTableRecords([$comfortable]);
});

it('previews covered and expired seller warranty wording with the expiry date in the triage form', function (): void {
    $components = workspaceCoverageTriageComponents();

    $preview = new ReflectionProperty($components['warranty_preview'], 'getConstantStateUsing')
        ->getValue($components['warranty_preview']);

    expect($preview)->toBeInstanceOf(Closure::class);

    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();
    $ticket = Ticket::factory()->for($customer, 'customer')->create(['status' => TicketStatus::Pending]);

    $makeGet = static function (SerializedInventoryUnit $unit): Get {
        $get = Mockery::mock(Get::class);
        $get->shouldReceive('__invoke')->andReturnUsing(
            static fn (string $path): mixed => match ($path) {
                'equipment_source' => TicketEquipmentSource::SoldByUs->value,
                'serialized_inventory_unit_id' => $unit->getKey(),
                default => null,
            },
        );

        return $get;
    };

    $active = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
        'warranty_started_on' => today()->subMonth(),
        'warranty_expires_on' => today()->addYear(),
    ]);
    $lapsed = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
        'warranty_started_on' => today()->subYears(2),
        'warranty_expires_on' => today()->subYear(),
    ]);

    expect($preview($ticket, $makeGet($active)))
        ->toContain('Warranty active until '.today()->addYear()->toDateString())
        ->and($preview($ticket, $makeGet($lapsed)))
        ->toContain('Warranty expired on '.today()->subYear()->toDateString());
});
