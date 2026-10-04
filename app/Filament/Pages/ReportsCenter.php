<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Reporting\ReportDefinition;
use App\Reporting\ReportRegistry;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

final class ReportsCenter extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected string $view = 'filament.pages.reports-center';

    #[Url]
    public string $search = '';

    #[\Override]
    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && ReportRegistry::accessibleDomains($actor) !== [];
    }

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('reporting.center.navigation');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('reporting.center.title');
    }

    /**
     * @return list<array{
     *     key:string,
     *     label:string,
     *     description:string,
     *     icon:string,
     *     reports:list<ReportDefinition>
     * }>
     */
    public function domains(): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return [];
        }

        $domains = ReportRegistry::accessibleDomains($actor);
        $needle = mb_strtolower(mb_trim($this->search));

        if ($needle === '') {
            return $domains;
        }

        foreach ($domains as &$domain) {
            $domain['reports'] = array_values(array_filter(
                $domain['reports'],
                static function (ReportDefinition $report) use ($needle): bool {
                    $haystack = mb_strtolower($report->label.' '.$report->description.' '.$report->category);

                    return str_contains($haystack, $needle);
                },
            ));
        }
        unset($domain);

        return array_values(array_filter(
            $domains,
            static fn (array $domain): bool => $domain['reports'] !== [],
        ));
    }
}
