<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountsReceivable\Pages;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Filament\Resources\AccountsReceivable\AccountsReceivableResource;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Accounting\AccountsReceivableService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ListAccountsReceivable extends Page
{
    protected static string $resource = AccountsReceivableResource::class;

    protected string $view = 'filament.resources.accounts-receivable.pages.list-accounts-receivable';

    #[Url]
    public ?string $asOf = null;

    #[Url]
    public ?int $customerId = null;

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<string, mixed> */
    public array $detail = [];

    /** @var array<string, mixed> */
    public array $reconciliation = [];

    public ?string $selectedCustomerName = null;

    public function mount(): void
    {
        $this->authorizeReceivableAccess();
        $this->asOf ??= CarbonImmutable::today()->toDateString();
        $this->loadReport();
    }

    public function updatedAsOf(): void
    {
        $this->loadReport();
    }

    public function showCustomer(int $customerId): void
    {
        $this->customerId = $customerId;
        $this->loadReport();
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
        $this->loadReport();
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.accounts_receivable');
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export aging CSV')
                ->visible(fn (): bool => $this->canViewReceivables())
                ->authorize(fn (): bool => $this->canViewReceivables())
                ->action(function (): StreamedResponse {
                    $this->authorizeReceivableAccess();
                    $csv = app(AccountsReceivableService::class)->toCsv(CarbonImmutable::parse($this->asOf));

                    return response()->streamDownload(
                        static function () use ($csv): void {
                            echo $csv;
                        },
                        'accounts-receivable-aging.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),
        ];
    }

    public function downloadStatement(): StreamedResponse
    {
        $this->authorizeReceivableAccess();
        if ($this->customerId === null) {
            throw new LogicException('Choose a customer before downloading a statement.');
        }

        $customer = CustomerProfile::withTrashed()->find($this->customerId);
        if (! $customer instanceof CustomerProfile) {
            throw new LogicException('The selected customer no longer exists.');
        }

        $to = CarbonImmutable::parse($this->asOf ?? CarbonImmutable::today()->toDateString());
        $from = $to->subYear()->addDay();
        $statement = app(AccountsReceivableService::class)->statement($customer, $from, $to);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new LogicException('The customer statement export stream could not be opened.');
        }

        fputcsv($stream, ['Customer', $statement['customer_name']], escape: '\\');
        fputcsv($stream, ['Period', $statement['from'].' to '.$statement['to']], escape: '\\');
        fputcsv($stream, ['Brought forward', number_format(((int) $statement['brought_forward_minor']) / 100, 2, '.', '')], escape: '\\');
        fputcsv($stream, ['Date', 'Type', 'Reference', 'Debit', 'Credit'], escape: '\\');
        foreach ($statement['entries'] as $entry) {
            fputcsv($stream, [
                $entry['date'],
                $entry['type'],
                $entry['reference'],
                number_format(((int) $entry['debit_minor']) / 100, 2, '.', ''),
                number_format(((int) $entry['credit_minor']) / 100, 2, '.', ''),
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
            'customer-statement-'.$customer->id.'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function loadReport(): void
    {
        $this->authorizeReceivableAccess();
        $date = CarbonImmutable::parse($this->asOf ?? CarbonImmutable::today()->toDateString());
        $service = app(AccountsReceivableService::class);
        $this->summary = $service->aging($date);
        $this->reconciliation = $service->reconciliation($date);
        $this->detail = [];
        $this->selectedCustomerName = null;

        if ($this->customerId === null) {
            return;
        }

        $customer = CustomerProfile::withTrashed()->find($this->customerId);
        if (! $customer instanceof CustomerProfile) {
            $this->customerId = null;

            return;
        }

        $this->selectedCustomerName = $customer->company_name ?: ($customer->customer_code ?: "Customer #{$customer->id}");
        $this->detail = $service->customerDetail($customer, $date);
    }

    private function authorizeReceivableAccess(): void
    {
        if (! $this->canViewReceivables()) {
            abort(403);
        }
    }

    private function canViewReceivables(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can(AccountingPermission::ReceivableView->value);
    }
}