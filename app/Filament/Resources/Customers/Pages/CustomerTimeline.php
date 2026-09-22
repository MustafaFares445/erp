<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Data\Crm\InteractionData;
use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\Crm\CustomerTimelineService;
use App\Services\Crm\InteractionService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Customer 360 timeline (WP-3.1, GAP-UI-03, CR-05) — every source
 * reachable in one reverse-chronological, permission-filtered, paginated
 * stream instead of an account manager assembling it from nine screens.
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

    #[Url]
    public ?string $search = null;

    #[Url]
    public bool $showActivity = true;

    #[Url]
    public string $range = 'all';

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
            Action::make('log_interaction')
                ->label('Log interaction')
                ->schema([
                    Select::make('type')->options(collect(InteractionType::cases())->mapWithKeys(fn (InteractionType $v): array => [$v->value => $v->label()])->all())->required(),
                    Select::make('direction')->options(collect(InteractionDirection::cases())->mapWithKeys(fn (InteractionDirection $v): array => [$v->value => str($v->value)->headline()->toString()])->all())->default('outbound')->required(),
                    Select::make('outcome')->options(collect(InteractionOutcome::cases())->mapWithKeys(fn (InteractionOutcome $v): array => [$v->value => str($v->value)->replace('_', ' ')->headline()->toString()])->all()),
                    DateTimePicker::make('occurred_at')->default(now())->required(),
                    TextInput::make('summary')->required()->maxLength(255),
                    Textarea::make('notes')->rows(3),
                ])
                ->action(function (array $data): void {
                    app(InteractionService::class)->log(new InteractionData(
                        subject: $this->customer(),
                        type: InteractionType::from(self::stringValue($data['type'] ?? null, 'type')),
                        direction: InteractionDirection::from(self::stringValue($data['direction'] ?? null, 'direction')),
                        occurredAt: Carbon::parse(self::stringValue($data['occurred_at'] ?? null, 'occurred_at')),
                        summary: self::stringValue($data['summary'] ?? null, 'summary'),
                        outcome: filled($data['outcome'] ?? null) ? InteractionOutcome::from(self::stringValue($data['outcome'], 'outcome')) : null,
                        notes: is_string($data['notes'] ?? null) ? $data['notes'] : null,
                    ), $this->actor());
                    Notification::make()->success()->title('Customer interaction recorded')->send();
                }),
            Action::make('export_csv')
                ->label('Export CSV')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
            Action::make('back_to_customer')
                ->label('Back to customer')
                ->color('gray')
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
        [$from, $until] = $this->resolveRange();

        return [
            'customer' => $customer,
            'summary' => $service->summary($customer),
            'timeline' => $service->timeline(
                $customer,
                $this->actor(),
                $from,
                $until,
                $this->types,
                20,
                $this->search,
                $this->showActivity,
            ),
            'availableTypes' => array_values(array_diff(CustomerTimelineService::TYPES, ['activity'])),
        ];
    }

    public function clearFilters(): void
    {
        $this->types = [];
        $this->search = null;
        $this->range = 'all';
        $this->showActivity = true;
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveRange(): array
    {
        return match ($this->range) {
            '30d' => [now()->subDays(30), null],
            '90d' => [now()->subDays(90), null],
            'ytd' => [now()->startOfYear(), null],
            'custom' => [$this->parseDate($this->from), $this->parseDate($this->until)],
            default => [null, null],
        };
    }

    private function exportCsv(): StreamedResponse
    {
        $customer = $this->customer();
        [$from, $until] = $this->resolveRange();
        $events = app(CustomerTimelineService::class)->timeline(
            $customer,
            $this->actor(),
            $from,
            $until,
            $this->types,
            10000,
            $this->search,
            $this->showActivity,
        );

        return response()->streamDownload(function () use ($events): void {
            $handle = fopen('php://output', 'wb');

            // @codeCoverageIgnoreStart
            // php://output is guaranteed by PHP in supported runtime environments; keep this defensive guard.
            if ($handle === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }
            // @codeCoverageIgnoreEnd

            fputcsv($handle, ['Date', 'Type', 'Title', 'Status', 'Amount', 'Actor'], escape: '\\');

            foreach ($events as $event) {
                fputcsv($handle, [
                    $event->occurredAt->toDateTimeString(),
                    $event->type,
                    $event->title,
                    $event->statusLabel,
                    $event->amountMinor !== null ? MoneyFormatter::format($event->amountMinor, $event->currency) : null,
                    $event->actorName,
                ],
                    escape: '\\');
            }

            fclose($handle);
        }, "{$customer->customer_code}-timeline.csv");
    }

    private static function stringValue(mixed $value, string $field): string
    {
        if (! is_scalar($value) || (string) $value === '') {
            throw new LogicException("Expected {$field}.");
        }

        return (string) $value;
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
