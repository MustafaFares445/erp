<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportReports\Pages;

use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Models\User;
use App\Services\Support\SupportReportService;
use Carbon\Carbon;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Workload, SLA, and maintenance report sections (FR-090–094), each
 * self-checked by {@see SupportReportService}. `$from`/`$until` drive the
 * SLA and maintenance sections' period filter (FR-092/093); the workload
 * section is always "right now" (FR-091 has no period of its own).
 */
final class ViewSupportReports extends Page
{
    protected static string $resource = SupportReportResource::class;

    protected string $view = 'filament.support-reports.view-support-reports';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.support_reports');
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getViewData(): array
    {
        $service = app(SupportReportService::class);
        $from = $this->parseDate($this->from);
        $until = $this->parseDate($this->until);

        return [
            'workload' => $service->workload($this->actor()),
            'sla' => $service->sla($this->actor(), $from, $until),
            'maintenance' => $service->maintenance($this->actor(), $from, $until),
        ];
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
