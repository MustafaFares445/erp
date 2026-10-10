<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\AdminModuleRegistry;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Str;
use Throwable;

final class UserGuideArticleResolver
{
    public static function homeUrl(?string $locale = null): string
    {
        $base = mb_rtrim(config()->string('app.user_guide_url', '/guide/'), '/');

        return $base.($locale === 'ar' ? '/ar/' : '/');
    }

    public static function forClass(string $groupKey, string $class, ?string $locale = null): string
    {
        return self::articleUrl($groupKey, $class, $locale);
    }

    /**
     * Bind contextual help to the concrete Filament page component instead of the current HTTP
     * route. Livewire updates run on /livewire/update, but the component scope remains the same.
     *
     * @return array<class-string<Page>, array{group: string, article_class: class-string<resource>|class-string<Page>}>
     */
    public static function pageScopes(): array
    {
        $scopes = [];

        foreach (AdminModuleRegistry::groups() as $group) {
            foreach (AdminModuleRegistry::memberClassesOf($group) as $class) {
                if (! class_exists($class)) {
                    continue;
                }

                if (is_subclass_of($class, Resource::class)) {
                    try {
                        foreach ($class::getPages() as $registration) {
                            $page = $registration->getPage();
                            if (! is_subclass_of($page, Page::class)) {
                                continue;
                            }

                            $scopes[$page] ??= [
                                'group' => $group['key'],
                                'article_class' => $class,
                            ];
                        }
                    } catch (Throwable) {
                        continue;
                    }

                    continue;
                }

                if (is_subclass_of($class, Page::class)) {
                    $scopes[$class] ??= [
                        'group' => $group['key'],
                        'article_class' => $class,
                    ];
                }
            }
        }

        return $scopes;
    }

    public static function forRouteName(string $routeName, ?string $groupKey = null, ?string $locale = null): ?string
    {
        $panelId = Filament::getCurrentOrDefaultPanel()?->getId() ?? 'admin';
        $groups = AdminModuleRegistry::groups();

        if ($groupKey !== null) {
            $groups = array_values(array_filter(
                $groups,
                static fn (array $group): bool => $group['key'] === $groupKey,
            ));
        }

        foreach ($groups as $group) {
            foreach (AdminModuleRegistry::memberClassesOf($group) as $class) {
                if (! class_exists($class)) {
                    continue;
                }

                if (is_subclass_of($class, Resource::class)) {
                    $prefix = sprintf('filament.%s.resources.%s.', $panelId, $class::getSlug());

                    if (! Str::startsWith($routeName, $prefix)) {
                        continue;
                    }

                    return self::articleUrl($group['key'], $class, $locale);
                }

                if (is_subclass_of($class, Page::class)) {
                    try {
                        if ($routeName !== $class::getRouteName()) {
                            continue;
                        }
                    } catch (Throwable) {
                        continue;
                    }

                    return self::articleUrl($group['key'], $class, $locale);
                }
            }
        }

        return null;
    }

    private static function articleUrl(string $groupKey, string $class, ?string $locale): string
    {
        $base = mb_rtrim(config()->string('app.user_guide_url', '/guide/'), '/');
        $prefix = $locale === 'ar' ? '/ar' : '';
        $slug = self::classSlug($class);

        return $base.$prefix.'/screens/'.$groupKey.'/'.$slug;
    }

    private static function classSlug(string $class): string
    {
        $base = class_basename($class);

        foreach (['Resource', 'Page'] as $suffix) {
            if (str_ends_with($base, $suffix)) {
                $base = mb_substr($base, 0, -mb_strlen($suffix));
            }
        }

        if (str_ends_with(mb_strtolower($base), 'dashboard')) {
            return 'dashboard';
        }

        return Str::kebab($base);
    }
}
