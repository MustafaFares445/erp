<?php

declare(strict_types=1);

namespace App\Filament;

use App\Filament\Pages\CatalogSetup;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ModulePlaceholder;
use Illuminate\Support\Str;

final class PageUsageGuide
{
    /** @param array<mixed> $scopes */
    public static function for(array $scopes): string
    {
        $page = $scopes[0] ?? null;

        if ($page === Dashboard::class) {
            return 'Review the inventory work that needs attention, including pending documents, low stock, recent movements, and stock value.';
        }

        if ($page === CatalogSetup::class) {
            return 'Maintain the shared categories, brands, attributes, and units that are used when creating products and recording inventory.';
        }

        if ($page === ModulePlaceholder::class) {
            return 'This area is listed for navigation but is not available in the current module.';
        }

        if (! is_string($page)) {
            return 'Use this page to review the available information and complete the actions you are authorized to perform.';
        }

        $label = self::labelFor($page);

        if (str_contains(class_basename($page), 'Create')) {
            return sprintf('Add a new %s record. Complete the required fields, then save it to make it available for the next workflow step.', $label);
        }

        if (str_contains(class_basename($page), 'Edit')) {
            return sprintf('Update this %s record while it is still editable. Saving applies the changes to future inventory activity.', $label);
        }

        if (str_contains(class_basename($page), 'View')) {
            return sprintf('Review this %s record and its related activity. Use the available tabs to inspect linked stock, movements, or history.', $label);
        }

        return sprintf('Review and manage %s. Use search and filters to find records, then open a record or use the available actions to continue.', $label);
    }

    private static function labelFor(string $page): string
    {
        return Str::of(class_basename($page))
            ->replace(['Create', 'Edit', 'List', 'Manage', 'View'], '')
            ->kebab()
            ->replace('-', ' ')
            ->lower()
            ->toString();
    }
}
