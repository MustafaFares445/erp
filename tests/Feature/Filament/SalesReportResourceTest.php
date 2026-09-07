<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\SalesPermission;
use App\Enums\SalesReportType;
use App\Filament\Resources\SalesReports\Pages\ViewSalesReports;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Quotation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function salesReportViewer(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(SalesPermission::ReportView->value);

    return $user;
}

function salesReportExporter(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(SalesPermission::ReportView->value);
    $user->givePermissionTo(SalesPermission::Export->value);

    return $user;
}

it('renders every one of the nine sales report types', function (): void {
    $viewer = salesReportViewer();

    foreach (SalesReportType::cases() as $type) {
        Livewire::actingAs($viewer)
            ->test(ViewSalesReports::class)
            ->set('reportType', $type->value)
            ->assertOk();
    }
});

it('blocks a user without the report-view permission', function (): void {
    $unauthorized = User::factory()->create();

    Livewire::actingAs($unauthorized)
        ->test(ViewSalesReports::class)
        ->assertForbidden();
});

it('shows an explicit empty state for a report with no data in the period', function (): void {
    $viewer = salesReportViewer();

    $component = Livewire::actingAs($viewer)
        ->test(ViewSalesReports::class)
        ->set('reportType', SalesReportType::QuotationFunnel->value)
        ->set('from', '2020-01-01')
        ->set('to', '2020-01-31')
        ->assertOk();

    expect($component->instance()->hasNoDetailRows())->toBeTrue()
        ->and($component->instance()->summaryFields()['total'] ?? null)->toBe(0);

    $component->assertSee('No data for this report in the selected period.');
});

it('excludes a delivery completed one day after the as-of date boundary', function (): void {
    $viewer = salesReportViewer();
    $asOf = CarbonImmutable::parse('2026-09-05');

    InventoryOperation::factory()->delivery()->done()->create([
        'completed_at' => $asOf->addDay(),
    ]);

    $component = Livewire::actingAs($viewer)
        ->test(ViewSalesReports::class)
        ->set('reportType', SalesReportType::DeliveredNotInvoiced->value)
        ->set('to', $asOf->toDateString())
        ->assertOk();

    expect($component->instance()->summaryFields()['count'] ?? null)->toBe(0);
});

it('gates the export action behind the export permission, separate from report-view', function (): void {
    $viewerOnly = salesReportViewer();

    $page = new ViewSalesReports;
    $page->reportType = SalesReportType::QuotationFunnel->value;

    auth()->login($viewerOnly);

    expect(fn (): StreamedResponse => $page->exportCsv())->toThrow(HttpException::class);
});

it('allows export for an actor holding both report-view and export permissions', function (): void {
    $exporter = salesReportExporter();

    $response = Livewire::actingAs($exporter)
        ->test(ViewSalesReports::class)
        ->set('reportType', SalesReportType::QuotationFunnel->value)
        ->assertOk()
        ->instance()
        ->exportCsv();

    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

it('lets the read-only Reviewer role view every report but never export one', function (): void {
    $reviewer = User::factory()->create();
    $reviewer->assignRole(DashboardRole::Reviewer->value);

    Livewire::actingAs($reviewer)
        ->test(ViewSalesReports::class)
        ->assertOk();

    $page = new ViewSalesReports;
    $page->reportType = SalesReportType::QuotationFunnel->value;

    auth()->login($reviewer);

    expect(fn (): StreamedResponse => $page->exportCsv())->toThrow(HttpException::class);
});

it('never posts a journal entry or mutates any domain record as a side effect of viewing or exporting a report', function (): void {
    $exporter = salesReportExporter();
    $customer = CustomerProfile::factory()->create();
    Invoice::factory()->create(['customer_id' => $customer->getKey(), 'issued_at' => now()->subDays(3)]);
    Quotation::factory()->create(['customer_id' => $customer->getKey()]);

    $journalCountBefore = JournalEntry::query()->count();
    $invoiceCountBefore = Invoice::query()->count();
    $quotationCountBefore = Quotation::query()->count();

    foreach (SalesReportType::cases() as $type) {
        Livewire::actingAs($exporter)
            ->test(ViewSalesReports::class)
            ->set('reportType', $type->value)
            ->assertOk()
            ->instance()
            ->exportCsv();
    }

    expect(JournalEntry::query()->count())->toBe($journalCountBefore)
        ->and(Invoice::query()->count())->toBe($invoiceCountBefore)
        ->and(Quotation::query()->count())->toBe($quotationCountBefore);
});
