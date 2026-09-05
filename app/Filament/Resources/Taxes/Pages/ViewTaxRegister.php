<?php

declare(strict_types=1);

namespace App\Filament\Resources\Taxes\Pages;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\FinancialReports\Pages\ViewFinancialReports;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Taxes\TaxResource;
use App\Models\Bill;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\TaxRegisterService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The period report AC-06 demands: the deferred-versus-payable split, a
 * reconciliation of both tax accounts against the ledger, and the raw
 * entries beneath — proof, not just a number (WP-2.7).
 *
 * {@see TaxResource}'s {@see ListTaxes} remains the flat, unfiltered register
 * (AC-11's trace); this page is the report built from it.
 *
 * The permission re-check inside every loader and export mirrors
 * {@see ViewFinancialReports}:
 * a direct URL or action call must never bypass the resource gate.
 */
final class ViewTaxRegister extends Page
{
    protected static string $resource = TaxResource::class;

    protected string $view = 'filament.resources.taxes.pages.view-tax-register';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /** @var array<string, string> */
    public array $summary = [];

    /** @var array<string, array{register: string, journal: string, difference: string}> */
    public array $reconciliation = [];

    /** @var array<int, array{id: int, tax_date: string, direction: string, tax_type: string, tax_amount: string, document_label: string, document_url: string|null, invoice_url: string|null}> */
    public array $entries = [];

    public int $entriesShown = 0;

    public int $entriesTotal = 0;

    /**
     * The on-screen list is capped: it exists for at-a-glance tracing
     * (AC-11), while {@see TaxRegisterService::toCsv()} — backed by a cursor,
     * not this array — is the export for a period with more rows than a page
     * should render.
     */
    private const MAX_ENTRIES_SHOWN = 200;

    public function mount(): void
    {
        $this->authorizeTaxRegisterAccess();

        if ($this->from === null || $this->to === null) {
            $period = app(FiscalPeriodService::class)->forDate(CarbonImmutable::now());
            $today = CarbonImmutable::today();

            $this->from ??= $period?->starts_at->toDateString() ?? $today->startOfMonth()->toDateString();
            $this->to ??= $period?->ends_at->toDateString() ?? $today->endOfMonth()->toDateString();
        }

        $this->loadReport();
    }

    public function updatedFrom(): void
    {
        $this->loadReport();
    }

    public function updatedTo(): void
    {
        $this->loadReport();
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.taxes').' — Register';
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_raw_entries')
                ->label('View raw register')
                ->url(fn (): string => TaxResource::getUrl('index')),
            Action::make('export_summary')
                ->label('Export summary CSV')
                ->visible(fn (): bool => $this->canViewTaxRegister())
                ->authorize(fn (): bool => $this->canViewTaxRegister())
                ->action(fn (): StreamedResponse => $this->streamSummaryCsv()),
            Action::make('export_entries')
                ->label('Export entries CSV')
                ->visible(fn (): bool => $this->canViewTaxRegister())
                ->authorize(fn (): bool => $this->canViewTaxRegister())
                ->action(fn (): StreamedResponse => $this->streamEntriesCsv()),
        ];
    }

    private function loadReport(): void
    {
        $this->authorizeTaxRegisterAccess();

        [$from, $to] = $this->range();
        $service = app(TaxRegisterService::class);

        $this->summary = $service->period($from, $to);
        $this->reconciliation = $service->reconciliation($from, $to);

        $query = TaxRecognitionEntry::query()
            ->whereDate('tax_date', '>=', $from->toDateString())
            ->whereDate('tax_date', '<=', $to->toDateString());

        $this->entriesTotal = $query->count();

        $this->entries = $query
            ->orderBy('tax_date')
            ->orderBy('id')
            ->limit(self::MAX_ENTRIES_SHOWN)
            ->get()
            ->map(fn (TaxRecognitionEntry $entry): array => [
                'id' => (int) $entry->getKey(),
                'tax_date' => $entry->tax_date->toDateString(),
                'direction' => $entry->direction,
                'tax_type' => $entry->tax_type,
                'tax_amount' => (string) $entry->tax_amount,
                'document_label' => $entry->source_type !== null
                    ? class_basename($entry->source_type).' #'.$entry->source_id
                    : '—',
                'document_url' => $this->documentUrl($entry->source_type, $entry->source_id),
                'invoice_url' => $entry->invoice_id !== null
                    ? AdminModuleRegistry::resolveResourceRecordLink(InvoiceResource::class, (int) $entry->invoice_id)
                    : null,
            ])
            ->values()
            ->all();

        $this->entriesShown = count($this->entries);
    }

    private function documentUrl(?string $sourceType, ?int $sourceId): ?string
    {
        if ($sourceType === null || $sourceId === null) {
            return null;
        }

        $resource = match ($sourceType) {
            Invoice::class => InvoiceResource::class,
            Payment::class => PaymentResource::class,
            Bill::class => BillResource::class,
            CreditNote::class => CreditNoteResource::class,
            default => null,
        };

        return $resource === null ? null : AdminModuleRegistry::resolveResourceRecordLink($resource, $sourceId);
    }

    private function streamSummaryCsv(): StreamedResponse
    {
        $this->authorizeTaxRegisterAccess();

        [$from, $to] = $this->range();
        $service = app(TaxRegisterService::class);
        $summary = $service->period($from, $to);
        $reconciliation = $service->reconciliation($from, $to);

        return $this->streamCsv('tax-register-summary.csv', function (mixed $handle) use ($summary, $reconciliation, $from, $to): void {
            fputcsv($handle, [sprintf('Tax Register — %s to %s', $from->toDateString(), $to->toDateString())], escape: '\\');
            fputcsv($handle, ['figure', 'amount'], escape: '\\');

            foreach ($summary as $figure => $amount) {
                fputcsv($handle, [$figure, $amount], escape: '\\');
            }

            fputcsv($handle, [], escape: '\\');
            fputcsv($handle, ['account', 'register', 'journal', 'difference'], escape: '\\');

            foreach ($reconciliation as $account => $figures) {
                fputcsv($handle, [$account, $figures['register'], $figures['journal'], $figures['difference']], escape: '\\');
            }
        });
    }

    private function streamEntriesCsv(): StreamedResponse
    {
        $this->authorizeTaxRegisterAccess();

        [$from, $to] = $this->range();
        $csv = app(TaxRegisterService::class)->toCsv($from, $to);

        return response()->streamDownload(
            static function () use ($csv): void {
                echo $csv;
            },
            'tax-register-entries.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * @param  callable(resource): void  $writer
     */
    private function streamCsv(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            $writer($handle);

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(): array
    {
        $today = CarbonImmutable::today();

        return [
            CarbonImmutable::parse($this->from ?? $today->startOfMonth()->toDateString()),
            CarbonImmutable::parse($this->to ?? $today->toDateString()),
        ];
    }

    private function authorizeTaxRegisterAccess(): void
    {
        abort_unless($this->canViewTaxRegister(), 403);
    }

    private function canViewTaxRegister(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can(AccountingPermission::TaxView->value);
    }
}
