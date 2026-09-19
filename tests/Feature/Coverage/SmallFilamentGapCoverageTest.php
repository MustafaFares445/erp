<?php

declare(strict_types=1);

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\NotificationChannel;
use App\Filament\Resources\Campaigns\Schemas\CampaignForm;
use App\Filament\Resources\PurchasingReports\Pages\ListPurchasingReports;
use App\Filament\Resources\SalesOpportunities\Pages\EditSalesOpportunity;
use App\Filament\Widgets\CrmDormantLeads;
use App\Models\Lead;
use App\Models\NotificationTemplate;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOpportunity;
use App\Models\User;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('covers sales opportunity edit update and wrong-record guards', function (): void {
    $page = app(EditSalesOpportunity::class);
    $method = new ReflectionMethod(EditSalesOpportunity::class, 'handleRecordUpdate');
    $opportunity = SalesOpportunity::factory()->create();

    $updated = $method->invoke($page, $opportunity, ['title' => 'Coverage updated opportunity']);
    expect($updated)->toBeInstanceOf(SalesOpportunity::class)
        ->and($updated->title)->toBe('Coverage updated opportunity');

    expect(fn (): mixed => $method->invoke($page, new class extends Model {}, []))
        ->toThrow(LogicException::class, 'Expected a sales opportunity');
});

it('covers purchasing report access, view data and CSV streaming', function (): void {
    $page = app(ListPurchasingReports::class);
    expect($page->getTitle())->not->toBe('');

    expect(fn () => $page->getViewData())->toThrow(HttpException::class);

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    Gate::before(static fn (): bool => true);

    $data = $page->getViewData();
    expect($data)->toHaveKeys([
        'openCommitments',
        'receivingPerformance',
        'costVariance',
        'duplicateReferenceAttempts',
    ]);

    $stream = new ReflectionMethod(ListPurchasingReports::class, 'streamOpenCommitments');
    $response = $stream->invoke($page);
    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();
    expect($csv)->toContain('supplier,orders,ordered_value,received_value,outstanding_value');
});

it('covers campaign template labels and dormant lead widget branches', function (): void {
    $template = NotificationTemplate::query()->create([
        'key' => 'coverage.template',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Coverage',
        'body' => 'Coverage body',
        'variables' => [],
        'is_active' => true,
    ]);
    $label = new ReflectionMethod(CampaignForm::class, 'templateLabel');
    expect($label->invoke(null, $template))->toContain('coverage.template', 'en', 'mail');

    CampaignForm::configure(Schema::make());
    expect(CrmDormantLeads::canView())->toBeFalse();

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    Gate::before(static fn (): bool => true);
    expect(CrmDormantLeads::canView())->toBeTrue();

    $lead = new Lead;
    $lead->forceFill([
        'lead_number' => 'LEAD-DORMANT-COVERAGE',
        'status' => LeadStatus::New,
        'source' => LeadSource::Website,
        'first_name' => 'Dormant',
        'last_interaction_at' => now()->subDays(30),
        'created_by' => $actor->getKey(),
    ])->save();

    $widget = app(CrmDormantLeads::class);
    $getStats = new ReflectionMethod(CrmDormantLeads::class, 'getStats');
    $stats = $getStats->invoke($widget);
    expect($stats)->toHaveCount(1);
});

it('streams populated purchasing open commitments rows', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    Gate::before(static fn (): bool => true);

    $order = PurchaseOrder::factory()->accepted()->create();
    PurchaseOrderLine::factory()->for($order)->create([
        'quantity_ordered' => 4,
        'quantity_received' => 1,
        'unit_cost' => 25,
        'line_total' => 100,
    ]);

    $page = app(ListPurchasingReports::class);
    $stream = new ReflectionMethod(ListPurchasingReports::class, 'streamOpenCommitments');
    $response = $stream->invoke($page);

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain($order->supplier->name)
        ->and($csv)->toContain('100')
        ->and($csv)->toContain('25')
        ->and($csv)->toContain('75');
});
