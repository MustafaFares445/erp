<?php

declare(strict_types=1);

namespace App\Filament\Search;

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Str;
use Throwable;

final class IerpGlobalSearchProvider extends DefaultGlobalSearchProvider
{
    #[\Override]
    public function getResults(string $query): GlobalSearchResults
    {
        $results = GlobalSearchResults::make();

        $navigation = $this->navigationResults($query);
        if ($navigation !== []) {
            $results->category(__('Quick navigation'), $navigation);
        }

        $commands = $this->commandResults($query);
        if ($commands !== []) {
            $results->category(__('Quick actions'), $commands);
        }

        /** @var list<class-string<resource>> $resources */
        $resources = Filament::getResources();
        usort(
            $resources,
            static fn (string $left, string $right): int => ($left::getGlobalSearchSort() ?? 0) <=> ($right::getGlobalSearchSort() ?? 0),
        );

        foreach ($resources as $resource) {
            if (! $resource::canGloballySearch()) {
                continue;
            }

            $resourceResults = $resource::getGlobalSearchResults($query);
            if ($resourceResults->isEmpty()) {
                continue;
            }

            $results->category(
                $this->categoryFor($resource).' → '.$resource::getPluralModelLabel(),
                $resourceResults,
            );
        }

        return $results;
    }

    /** @return list<GlobalSearchResult> */
    private function navigationResults(string $query): array
    {
        $needle = Str::of($query)->lower()->squish()->toString();
        if ($needle === '') {
            return [];
        }

        $results = [];

        foreach (AdminModuleRegistry::accessibleGroups() as $group) {
            $groupLabel = __($group['label']);

            foreach ($group['items'] as $item) {
                $label = __($item['label']);
                $haystack = Str::of($groupLabel.' '.$label)->lower()->squish()->toString();

                if (! str_contains($haystack, $needle)) {
                    continue;
                }

                $url = $this->urlForRegistryItem($item);
                if ($url === null) {
                    continue;
                }

                $results[] = new GlobalSearchResult(
                    title: $label,
                    url: $url,
                    details: [__('Module') => $groupLabel],
                );

                if (count($results) >= 8) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /** @return list<GlobalSearchResult> */
    private function commandResults(string $query): array
    {
        $needle = Str::of($query)->lower()->squish()->toString();
        $commands = [];

        $definitions = [
            [
                'terms' => ['create quotation', 'new quotation', 'new quote'],
                'visible' => QuotationResource::canCreate(),
                'title' => __('Create quotation'),
                'url' => QuotationResource::getUrl('create'),
            ],
            [
                'terms' => ['create purchase order', 'new purchase order', 'new po'],
                'visible' => PurchaseOrderResource::canCreate(),
                'title' => __('Create purchase order'),
                'url' => PurchaseOrderResource::getUrl('create'),
            ],
            [
                'terms' => ['purchase needs', 'procurement needs', 'shortage'],
                'visible' => PurchaseNeeds::canAccess(),
                'title' => __('Open purchase needs'),
                'url' => PurchaseNeeds::getUrl(),
            ],
            [
                'terms' => ['overdue invoices', 'late invoices'],
                'visible' => InvoiceResource::canAccess(),
                'title' => __('Open overdue invoices'),
                'url' => InvoiceResource::getUrl('index').'?tab=overdue',
            ],
            [
                'terms' => ['open tickets', 'support tickets'],
                'visible' => TicketResource::canAccess(),
                'title' => __('Open support tickets'),
                'url' => TicketResource::getUrl('index').'?tab=open',
            ],
        ];

        foreach ($definitions as $definition) {
            if (! $definition['visible']) {
                continue;
            }

            if (! collect($definition['terms'])->contains(
                static fn (string $term): bool => str_contains($term, $needle) || str_contains($needle, $term),
            )) {
                continue;
            }

            $commands[] = new GlobalSearchResult(
                title: $definition['title'],
                url: $definition['url'],
                details: [__('Type') => __('Command')],
            );
        }

        return $commands;
    }

    /** @param class-string $resource */
    private function categoryFor(string $resource): string
    {
        foreach (AdminModuleRegistry::groups() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['link'] === $resource) {
                    return __($group['label']);
                }
            }
        }

        return __('Other');
    }

    /** @param array{label: string, link: string, page?: string, section?: string} $item */
    private function urlForRegistryItem(array $item): ?string
    {
        $link = $item['link'];

        try {
            if (is_subclass_of($link, Resource::class)) {
                /** @var class-string<resource> $link */
                return $link::getUrl(is_string($item['page'] ?? null) ? $item['page'] : 'index');
            }

            if (is_subclass_of($link, Page::class)) {
                /** @var class-string<Page> $link */
                return $link::getUrl();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
