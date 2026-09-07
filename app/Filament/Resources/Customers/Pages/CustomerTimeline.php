<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\CustomerTimelineService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * The Customer 360 timeline (WP-3.1, GAP-UI-03, CR-05) — every source
 * reachable in one reverse-chronological, permission-filtered, paginated
 * stream instead of an account manager assembling it from eight screens.
 */
final class CustomerTimeline extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.customers.customer-timeline';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    /** @var list<string> */
    #[Url]
    public array $types = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(CustomerResource::canView($this->getRecord()), 403);
    }

    #[\Override]
    public function getTitle(): string
    {
        return $this->customer()->company_name.' — Timeline';
    }

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('back_to_customer')
                ->label('Back to customer')
                ->url(fn (): string => CustomerResource::getUrl('view', ['record' => $this->customer()])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getViewData(): array
    {
        $service = app(CustomerTimelineService::class);
        $customer = $this->customer();

        return [
            'customer' => $customer,
            'summary' => $service->summary($customer),
            'timeline' => $service->timeline(
                $customer,
                $this->actor(),
                $this->parseDate($this->from),
                $this->parseDate($this->until),
                $this->types,
            ),
            'availableTypes' => CustomerTimelineService::TYPES,
        ];
    }

    private function customer(): CustomerProfile
    {
        $record = $this->getRecord();

        abort_unless($record instanceof CustomerProfile, 404);

        return $record;
    }

    private function actor(): User
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function parseDate(?string $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
