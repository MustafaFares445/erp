<?php

declare(strict_types=1);

use App\Enums\CrmReportType;
use App\Enums\InventoryReturnStatus;
use App\Enums\QualityResolutionType;
use App\Enums\SupportPermission;
use App\Enums\TicketType;
use App\Filament\Resources\CrmReports\Pages\ViewCrmReports;
use App\Filament\Resources\SupportReports\Pages\ViewSupportReports;
use App\Models\CustomerReturnRequest;
use App\Models\InventoryLot;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\ProductVariant;
use App\Models\Ticket;
use App\Models\TicketProductContext;
use App\Models\TicketQualityResolution;
use App\Models\User;
use App\Services\Settings\CurrencyCatalogService;
use App\Services\Support\SupportLifecycleReportService;
use App\Services\Support\SupportReportService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers every CRM report summary branch and nonnumeric sum fallback', function (): void {
    $page = app(ViewCrmReports::class);

    $page->reportType = CrmReportType::LeadsBySource->value;

    expect($page->summary(collect([
        [__('Leads') => 3, __('Converted') => 1],
        [__('Leads') => 'not-numeric', __('Converted') => 2],
    ])))->toBe([
        ['label' => __('Leads'), 'value' => 3],
        ['label' => __('Converted'), 'value' => 3],
        ['label' => __('Sources'), 'value' => 2],
    ]);

    $page->reportType = CrmReportType::StageConversion->value;
    expect($page->summary(collect([
        [__('Leads') => 4],
        [__('Leads') => 6],
    ])))->toBe([
        ['label' => __('Leads'), 'value' => 10],
        ['label' => __('Stages'), 'value' => 2],
    ]);

    $page->reportType = CrmReportType::CampaignPerformance->value;
    $campaignSummary = $page->summary(collect([
        [__('Recipients') => 10, __('Interested') => 3],
        [__('Recipients') => 5, __('Interested') => 2],
    ]));
    expect($campaignSummary[0]['value'])->toBe(2)
        ->and($campaignSummary[1]['value'])->toBe(15)
        ->and($campaignSummary[2]['value'])->toBe(5);

    $page->reportType = CrmReportType::PipelineValueAndAge->value;
    $pipeline = $page->summary(collect([
        [__('Open opportunities') => 2],
        [__('Open opportunities') => 4],
    ]));
    expect($pipeline[0]['value'])->toBe(6)
        ->and($pipeline[1]['value'])->toBe(2);

    $page->reportType = CrmReportType::AttributedRevenue->value;
    expect($page->summary(collect([['x' => 1], ['x' => 2]])))->toBe([
        ['label' => __('Campaigns with collected revenue'), 'value' => 2],
    ]);

    $page->reportType = 'invalid-report';
    expect(new ReflectionMethod(ViewCrmReports::class, 'type')->invoke($page))
        ->toBe(CrmReportType::LeadsBySource);
});

it('covers support lifecycle and product-quality view/export branches including populated rows', function (): void {
    (new SupportPermissionSeeder)->run();
    (new CurrencySeeder)->run();

    $actor = User::factory()->create();
    $actor->givePermissionTo(SupportPermission::ReportView->value);
    $this->actingAs($actor);

    $ticket = Ticket::factory()->create(['type' => TicketType::ProductQualityIssue]);
    $variant = ProductVariant::factory()->create(['name' => 'Coverage 95 Product']);
    $lot = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-COV-95',
    ]);

    TicketProductContext::factory()->create([
        'ticket_id' => $ticket->id,
        'product_variant_id' => $variant->id,
        'inventory_lot_id' => $lot->id,
        'quantity' => '2.000000',
    ]);

    $inventoryReturn = InventoryReturn::factory()->customer()->create([
        'customer_id' => $ticket->customer_id,
    ]);
    InventoryReturnLine::factory()->create([
        'inventory_return_id' => $inventoryReturn->id,
        'product_variant_id' => $variant->id,
        'transaction_quantity' => '1.000000',
    ]);
    $inventoryReturn->forceFill([
        'status' => InventoryReturnStatus::Posted,
        'ready_at' => now()->subMinute(),
        'posted_at' => now(),
    ])->save();

    $returnRequest = CustomerReturnRequest::factory()->create([
        'customer_id' => $ticket->customer_id,
        'resulting_inventory_return_id' => $inventoryReturn->id,
    ]);
    TicketQualityResolution::factory()->create([
        'ticket_id' => $ticket->id,
        'resolution_type' => QualityResolutionType::Replacement,
        'customer_return_request_id' => $returnRequest->id,
    ]);

    $page = app(ViewSupportReports::class);
    $page->from = now()->subDay()->toDateString();
    $page->until = now()->addDay()->toDateString();

    $page->section = 'equipment_lifecycle';

    $equipmentData = $page->getViewData();
    expect($equipmentData)->toHaveKeys([
        'installationReport',
        'calibrationReport',
        'loanerReport',
        'rmaReport',
    ]);

    $response = $page->exportCurrentReport();
    ob_start();
    $response->sendContent();
    $equipmentCsv = (string) ob_get_clean();
    expect($equipmentCsv)->toContain('Area,Metric,Value');

    $page->section = 'service_desk';
    $response = $page->exportCurrentReport();
    ob_start();
    $response->sendContent();
    $serviceDeskCsv = (string) ob_get_clean();
    expect($serviceDeskCsv)->toContain('Metric,Value', 'SLA compliance');

    $page->section = 'product_quality';
    $qualityData = $page->getViewData();
    expect($qualityData['qualityReport']['complaints'])->toBe(1);
    $productLabel = $qualityData['qualityReport']['by_product'][0]['product'];
    $lotLabel = $qualityData['qualityReport']['by_lot'][0]['lot_number'];

    $exportRows = new ReflectionMethod(ViewSupportReports::class, 'exportRows');
    $service = app(SupportReportService::class);
    $from = now()->subDay();
    $until = now()->addDay();
    $currency = app(CurrencyCatalogService::class)->defaultCode();

    $equipmentRows = $exportRows->invoke(
        $page,
        'equipment_lifecycle',
        $service,
        $actor,
        $from,
        $until,
        $currency,
    );
    expect($equipmentRows)->not->toBeEmpty();

    $qualityRows = $exportRows->invoke(
        $page,
        'product_quality',
        $service,
        $actor,
        $from,
        $until,
        $currency,
    );

    expect(collect($qualityRows)->contains(
        fn (array $row): bool => count($row) === 3 && is_numeric($row[1] ?? null) && is_numeric($row[2] ?? null),
    ))->toBeTrue()
        ->and(collect($qualityRows)->contains(
            fn (array $row): bool => count($row) === 4 && is_numeric($row[1] ?? null) && is_numeric($row[2] ?? null) && is_numeric($row[3] ?? null),
        ))->toBeTrue();

    $lifecycle = app(SupportLifecycleReportService::class);
    expect($lifecycle->quality($actor, $from, $until)['complaints'])->toBe(1);
});
