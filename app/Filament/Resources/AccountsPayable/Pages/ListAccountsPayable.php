<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountsPayable\Pages;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Filament\Resources\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountsPayableService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ListAccountsPayable extends Page
{
    protected static string $resource = AccountsPayableResource::class;

    protected string $view = 'filament.resources.accounts-payable.pages.list-accounts-payable';

    #[Url]
    public ?string $asOf = null;

    #[Url]
    public ?int $supplierId = null;

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<string, mixed> */
    public array $detail = [];

    public ?string $selectedSupplierName = null;

    public function mount(): void
    {
        $this->authorizePayableAccess();
        $this->asOf ??= CarbonImmutable::today()->toDateString();
        $this->loadReport();
    }

    public function updatedAsOf(): void
    {
        $this->loadReport();
    }

    public function showSupplier(int $supplierId): void
    {
        $this->supplierId = $supplierId;
        $this->loadReport();
    }

    public function clearSupplier(): void
    {
        $this->supplierId = null;
        $this->loadReport();
    }

    public function documentUrl(string $type, int $documentId): ?string
    {
        return match ($type) {
            'bill' => BillResource::getUrl('view', ['record' => $documentId]),
            'expense' => ExpenseResource::getUrl('view', ['record' => $documentId]),
            default => null,
        };
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.accounts_payable');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('Export CSV'))
                ->visible(fn (): bool => $this->canViewPayables())
                ->authorize(fn (): bool => $this->canViewPayables())
                ->action(function (): StreamedResponse {
                    $user = auth()->user();
                    if (! $user instanceof User) {
                        throw new LogicException('An authenticated accounting user is required.');
                    }

                    $this->authorizePayableAccess();
                    $csv = app(AccountsPayableService::class)->toCsv(CarbonImmutable::parse($this->asOf));

                    return response()->streamDownload(
                        static function () use ($csv): void {
                            echo $csv;
                        },
                        'accounts-payable.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),
        ];
    }

    public function downloadStatement(): StreamedResponse
    {
        $this->authorizePayableAccess();
        if ($this->supplierId === null) {
            throw new LogicException('Choose a supplier before downloading a statement.');
        }

        $supplier = Supplier::withTrashed()->find($this->supplierId);
        if (! $supplier instanceof Supplier) {
            throw new LogicException('The selected supplier no longer exists.');
        }

        $to = CarbonImmutable::parse($this->asOf ?? CarbonImmutable::today()->toDateString());
        $from = $to->subYear()->addDay();
        $statement = app(AccountsPayableService::class)->statement($supplier, $from, $to);
        /** @var resource $stream */
        $stream = fopen('php://temp', 'w+');

        fputcsv($stream, ['Supplier', $statement['supplier_name']], escape: '\\');
        fputcsv($stream, ['Period', $statement['from'].' to '.$statement['to']], escape: '\\');
        fputcsv($stream, ['Brought forward', number_format(((int) $statement['brought_forward_minor']) / 100, 2, '.', '')], escape: '\\');
        fputcsv($stream, ['Date', 'Type', 'Reference', 'Charge', 'Payment'], escape: '\\');
        foreach ($statement['entries'] as $entry) {
            fputcsv($stream, [
                $entry['date'],
                $entry['type'],
                $entry['reference'],
                number_format(((int) $entry['charge_minor']) / 100, 2, '.', ''),
                number_format(((int) $entry['payment_minor']) / 100, 2, '.', ''),
            ], escape: '\\');
        }
        fputcsv($stream, ['Carried forward', number_format(((int) $statement['carried_forward_minor']) / 100, 2, '.', '')], escape: '\\');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response()->streamDownload(
            static function () use ($csv): void {
                echo is_string($csv) ? $csv : '';
            },
            'supplier-statement-'.$supplier->id.'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function loadReport(): void
    {
        $this->authorizePayableAccess();
        $date = CarbonImmutable::parse($this->asOf ?? CarbonImmutable::today()->toDateString());
        $service = app(AccountsPayableService::class);
        $this->summary = $service->aging($date);
        $this->detail = [];
        $this->selectedSupplierName = null;

        if ($this->supplierId === null) {
            return;
        }

        $supplier = Supplier::withTrashed()->find($this->supplierId);
        if (! $supplier instanceof Supplier) {
            $this->supplierId = null;

            return;
        }

        $this->selectedSupplierName = $supplier->name;
        $this->detail = $service->supplierDetail($supplier, $date);
    }

    private function authorizePayableAccess(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $this->canViewPayables()) {
            abort(403);
        }
    }

    private function canViewPayables(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can(AccountingPermission::PayableView->value);
    }
}
